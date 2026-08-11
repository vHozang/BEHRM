<?php

namespace App\Services;

use App\Support\TenantContext;
use App\Support\TimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * E1.4 — Monthly attendance summary feeding payroll.
 *
 * Aggregates raw attendance / overtime / leave data for one salary period into
 * one `salary_attendance_summary` row per ACTIVE employee. The output is the
 * attendance basis a payroll run consumes (standard vs. actual working days,
 * late minutes, overtime hours, leave days).
 *
 * Column mapping (the real `salary_attendance_summary` columns):
 *   - standard_days        ← LeavePolicyService::workingDays for the period
 *   - actual_working_days  ← present days (ON_TIME + LATE — the employee showed up)
 *   - paid_leave_days      ← approved leave_requests total_days overlapping period
 *   - unpaid_leave_days    ← 0 (no PAID/UNPAID split on leave_requests here; reserved)
 *   - holiday_days         ← weekend/holiday days in [start,end] (calendar - working)
 *   - overtime_hours       ← approved overtime_requests total_hours in [start,end]
 *   - late_minutes         ← sum of attendances.meta->late_minutes for LATE days
 *   - early_leave_minutes  ← 0 (no source signal; reserved)
 *   - meta (jsonb)         ← metrics with no dedicated column:
 *                            present_days, on_time_days, late_count, absent_days,
 *                            leave_days, calendar_days + a build marker.
 *
 * Idempotent: re-running for the same period updates the same rows
 * (updateOrInsert keyed on tenant + legal_entity + employee + period).
 */
class AttendanceSummaryService
{
    /** Attendance statuses that count as "present" (employee showed up). */
    private const PRESENT_STATUSES = ['ON_TIME', 'LATE', 'EARLY_LEAVE'];

    /** Approved statuses (English + the legacy VN label seen in the data). */
    private const APPROVED_STATUSES = ['APPROVED', 'ĐÃ_DUYỆT'];

    public function __construct(
        private readonly LeavePolicyService $leavePolicy,
        private readonly OvertimeReconciliationService $overtimeReconciliation,
    ) {}

    /**
     * Build (upsert) the attendance summary for a salary period.
     *
     * @return array{period_id:int, employees:int, rows_upserted:int}
     */
    public function build(int $salaryPeriodId): array
    {
        $period = DB::table('salary_periods')
            ->where('id', $salaryPeriodId)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->first();

        if (! $period) {
            throw new RuntimeException("Salary period {$salaryPeriodId} not found", 404);
        }

        $tenantId = (int) $period->tenant_id;
        $legalEntityId = (int) $period->legal_entity_id;
        $start = (string) $period->start_date;
        $end = (string) $period->end_date;

        // Working-day denominator for the period. VN "công chuẩn" = calendar days
        // minus the weekly rest day (attendance.weekly_rest_weekday, default Sun)
        // minus holidays — Saturday is NOT excluded (6-day week, Đ.105).
        $standardDays = TimePolicy::standardWorkingDays($start, $end);

        // Calendar days inclusive — non-working days bucket (weekends + holidays).
        $calendarDays = (int) (CarbonImmutable::parse($start)
            ->startOfDay()
            ->diffInDays(CarbonImmutable::parse($end)->startOfDay()) + 1);
        $holidayDays = max(0, $calendarDays - $standardDays);

        // Nhân viên đang làm (chính thức + thử việc), loại tài khoản hệ thống.
        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->where(fn ($query) => $query->whereNull('hire_date')->orWhere('hire_date', '<=', $end))
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->pluck('id')
            ->all();

        $rowsUpserted = 0;
        $now = now();

        foreach ($employees as $employeeId) {
            $att = $this->attendanceAggregate($employeeId, $start, $end, $tenantId, $legalEntityId);
            $overtime = $this->overtimeReconciliation->reconcileRange($tenantId, (int) $employeeId, $start, $end);
            $overtimeHours = $overtime['payable_overtime_hours'];
            $leave = $this->leaveDays($employeeId, $start, $end, $tenantId);
            $leaveDays = $leave['paid'];

            // Ngày công = chấm công thật + WFH/công tác ĐÃ DUYỆT (căn cứ đơn, không
            // ai chấm công khi làm từ xa/đi công tác). ponytail: giả định ngày WFH
            // không đồng thời có bản ghi chấm công (tránh đếm đôi).
            $presentDays = $att['on_time_days'] + $att['late_days'] + $att['early_leave_days'] + (int) round($leave['work']);

            $meta = [
                'present_days' => $presentDays,
                'on_time_days' => $att['on_time_days'],
                'late_count' => $att['late_days'],
                'early_leave_count' => $att['early_leave_days'],
                'absent_days' => $att['absent_days'],
                'leave_days' => $leaveDays,
                'calendar_days' => $calendarDays,
                'source' => 'attendance-summary',
                'built_at' => $now->toIso8601String(),
                'payable_overtime_minutes' => $overtime['payable_overtime_minutes'],
                'verified_overtime_minutes' => $overtime['verified_overtime_minutes'],
                'overtime_warnings' => $overtime['warnings'],
                'overtime_blocking_issues' => $overtime['blocking_issues'],
            ];

            $payload = TenantContext::stamp([
                'standard_days' => $standardDays,
                'actual_working_days' => $presentDays,
                'paid_leave_days' => $leave['paid'],
                'unpaid_leave_days' => $leave['unpaid'],
                'holiday_days' => $holidayDays,
                'overtime_hours' => $overtimeHours,
                'late_minutes' => $att['late_minutes'],
                'early_leave_minutes' => $att['early_leave_minutes'],
                'meta' => json_encode($meta),
                'tenant_id' => $tenantId,
                'legal_entity_id' => $legalEntityId,
                'updated_at' => $now,
            ], true);

            // Idempotent upsert keyed on the natural identity of the summary row.
            DB::table('salary_attendance_summary')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $legalEntityId,
                    'employee_id' => $employeeId,
                    'period_id' => $salaryPeriodId,
                ],
                array_merge($payload, [
                    'employee_id' => $employeeId,
                    'period_id' => $salaryPeriodId,
                    'created_at' => $now,
                ])
            );

            $rowsUpserted++;
        }

        return [
            'period_id' => $salaryPeriodId,
            'employees' => count($employees),
            'rows_upserted' => $rowsUpserted,
        ];
    }

    /**
     * Aggregate attendance day counts and late minutes for an employee in range.
     *
     * @return array{on_time_days:int, late_days:int, absent_days:int, late_minutes:float}
     */
    private function attendanceAggregate(int $employeeId, string $start, string $end, int $tenantId, int $legalEntityId): array
    {
        $rows = DB::table('attendances')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$start, $end])
            ->get(['status', 'meta']);

        $totals = [
            'on_time_days' => 0,
            'late_days' => 0,
            'early_leave_days' => 0,
            'absent_days' => 0,
            'late_minutes' => 0.0,
            'early_leave_minutes' => 0.0,
        ];
        foreach ($rows as $row) {
            $status = strtoupper((string) $row->status);
            if (in_array($status, ['ON_TIME', 'PRESENT', 'CHECKED_IN', 'CHECKED_OUT', 'ĐÃ_DUYỆT', 'ĐÃ DUYỆT'], true)) {
                $totals['on_time_days']++;
            } elseif ($status === 'LATE') {
                $totals['late_days']++;
            } elseif ($status === 'EARLY_LEAVE') {
                $totals['early_leave_days']++;
            } elseif ($status === 'ABSENT') {
                $totals['absent_days']++;
            }
            $meta = is_string($row->meta) ? (json_decode($row->meta, true) ?: []) : (array) ($row->meta ?? []);
            $totals['late_minutes'] += (float) ($meta['late_minutes'] ?? 0);
            $totals['early_leave_minutes'] += (float) ($meta['early_leave_minutes'] ?? 0);
        }

        return $totals;
    }

    /**
     * Approved leave days overlapping the period, SPLIT theo có lương / không lương
     * (leave_types.category = 'UNPAID' → không lương → prorate trừ lương).
     *
     * Đơn nghỉ VẮT qua 2 tháng: chỉ tính phần ngày RƠI TRONG kỳ (giao của
     * [start_date,end_date] với kỳ), tránh cộng full total_days vào cả hai kỳ.
     *
     * @return array{paid: float, unpaid: float}
     *                                           ponytail: UNPAID theo category; nghỉ thai sản (BHXH chi trả) vẫn tính 'paid'
     *                                           ở đây — tách riêng khi cần trừ lương công ty cho ngày thai sản.
     */
    private function leaveDays(int $employeeId, string $start, string $end, int $tenantId): array
    {
        $rows = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->where('lr.tenant_id', $tenantId)
            ->where('lr.employee_id', $employeeId)
            ->whereIn('lr.status', self::APPROVED_STATUSES)
            ->where('lr.start_date', '<=', $end)
            ->where('lr.end_date', '>=', $start)
            ->get(['lr.start_date', 'lr.end_date', 'lr.total_days', 'lt.category']);

        $totals = ['paid' => 0.0, 'unpaid' => 0.0, 'work' => 0.0];
        $periodStart = CarbonImmutable::parse($start);
        $periodEnd = CarbonImmutable::parse($end);
        foreach ($rows as $row) {
            $leaveStart = CarbonImmutable::parse($row->start_date);
            $leaveEnd = CarbonImmutable::parse($row->end_date);
            $overlapStart = $leaveStart->gt($periodStart) ? $leaveStart : $periodStart;
            $overlapEnd = $leaveEnd->lt($periodEnd) ? $leaveEnd : $periodEnd;
            $days = min((float) $row->total_days, (float) ($overlapStart->diffInDays($overlapEnd) + 1));
            $category = strtoupper((string) $row->category);
            $totals[$category === 'UNPAID' ? 'unpaid' : ($category === 'WORK' ? 'work' : 'paid')] += $days;
        }

        return [
            'paid' => $totals['paid'],
            'unpaid' => $totals['unpaid'],
            // WFH / công tác đã duyệt → tính là NGÀY LÀM VIỆC (không phải nghỉ).
            'work' => $totals['work'],
        ];
    }
}
