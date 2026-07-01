<?php

namespace App\Services;

use App\Support\TenantContext;
use App\Support\TimePolicy;
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
        $calendarDays = (int) (\Carbon\CarbonImmutable::parse($start)
            ->startOfDay()
            ->diffInDays(\Carbon\CarbonImmutable::parse($end)->startOfDay()) + 1);
        $holidayDays = max(0, $calendarDays - $standardDays);

        // Nhân viên đang làm (chính thức + thử việc), loại tài khoản hệ thống.
        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->whereRaw("COALESCE((profile->>'system_account')::boolean, false) = false")
            ->pluck('id')
            ->all();

        $rowsUpserted = 0;
        $now = now();

        foreach ($employees as $employeeId) {
            $att = $this->attendanceAggregate($employeeId, $start, $end, $tenantId, $legalEntityId);
            $overtimeHours = $this->overtimeHours($employeeId, $start, $end, $tenantId);
            $leaveDays = $this->leaveDays($employeeId, $start, $end, $tenantId);

            $presentDays = $att['on_time_days'] + $att['late_days'] + $att['early_leave_days'];

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
            ];

            $payload = TenantContext::stamp([
                'standard_days' => $standardDays,
                'actual_working_days' => $presentDays,
                'paid_leave_days' => $leaveDays,
                'unpaid_leave_days' => 0,
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
        $row = DB::table('attendances')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$start, $end])
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'ON_TIME') AS on_time_days,
                COUNT(*) FILTER (WHERE status = 'LATE') AS late_days,
                COUNT(*) FILTER (WHERE status = 'EARLY_LEAVE') AS early_leave_days,
                COUNT(*) FILTER (WHERE status = 'ABSENT') AS absent_days,
                COALESCE(SUM((meta->>'late_minutes')::numeric) FILTER (WHERE status = 'LATE'), 0) AS late_minutes,
                COALESCE(SUM((meta->>'early_leave_minutes')::numeric), 0) AS early_leave_minutes
            ")
            ->first();

        return [
            'on_time_days' => (int) ($row->on_time_days ?? 0),
            'late_days' => (int) ($row->late_days ?? 0),
            'early_leave_days' => (int) ($row->early_leave_days ?? 0),
            'absent_days' => (int) ($row->absent_days ?? 0),
            'late_minutes' => (float) ($row->late_minutes ?? 0),
            'early_leave_minutes' => (float) ($row->early_leave_minutes ?? 0),
        ];
    }

    /**
     * Sum approved overtime hours overlapping the period (work_date in range).
     */
    private function overtimeHours(int $employeeId, string $start, string $end, int $tenantId): float
    {
        return (float) DB::table('overtime_requests')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('work_date', [$start, $end])
            // OT đã quy đổi NGHỈ BÙ thì không trả tiền (tránh hưởng kép).
            ->whereRaw("COALESCE((meta->>'converted_to_comp_off')::boolean, false) = false")
            ->sum('total_hours');
    }

    /**
     * Sum approved leave days overlapping the period.
     *
     * "Overlapping" = the leave window intersects [start, end]. We credit the
     * full request total_days when it overlaps (good enough for this summary;
     * day-accurate proration can be layered on later).
     */
    private function leaveDays(int $employeeId, string $start, string $end, int $tenantId): float
    {
        return (float) DB::table('leave_requests')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::APPROVED_STATUSES)
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->sum('total_days');
    }
}
