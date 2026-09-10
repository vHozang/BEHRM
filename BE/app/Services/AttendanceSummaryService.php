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

        $end = (string) $period->end_date;

        // Nhân viên đang làm (chính thức + thử việc), loại tài khoản hệ thống.
        $employees = DB::table('employees')
            ->where('tenant_id', (int) $period->tenant_id)
            ->where('legal_entity_id', (int) $period->legal_entity_id)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->where(fn ($query) => $query->whereNull('hire_date')->orWhere('hire_date', '<=', $end))
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->buildForEmployees($salaryPeriodId, $employees);
    }

    /** @param array<int, int> $employeeIds */
    public function buildForEmployees(int $salaryPeriodId, array $employeeIds): array
    {
        $period = DB::table('salary_periods')
            ->where('id', $salaryPeriodId)
            ->when(TenantContext::hasTenant(), fn ($query) => $query->where('tenant_id', TenantContext::id()))
            ->first();
        if (! $period) {
            throw new RuntimeException("Salary period {$salaryPeriodId} not found", 404);
        }
        $tenantId = (int) $period->tenant_id;
        $legalEntityId = (int) $period->legal_entity_id;
        $start = (string) $period->start_date;
        $end = (string) $period->end_date;
        $standardDays = TimePolicy::standardWorkingDays($start, $end);
        $calendarDays = (int) (CarbonImmutable::parse($start)->startOfDay()
            ->diffInDays(CarbonImmutable::parse($end)->startOfDay()) + 1);
        $holidayDays = max(0, $calendarDays - $standardDays);

        $employeeIds = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('id', array_values(array_unique(array_map('intval', $employeeIds))))
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($employeeIds === []) {
            return ['period_id' => $salaryPeriodId, 'employees' => 0, 'rows_upserted' => 0];
        }

        $now = now();
        $attendanceByEmployee = $this->attendanceAggregates($employeeIds, $start, $end, $tenantId, $legalEntityId);
        $overtimeByEmployee = $this->overtimeAggregates($tenantId, $employeeIds, $start, $end);
        $leaveByEmployee = $this->leaveDaysByEmployee($employeeIds, $start, $end, $tenantId);
        $rows = [];

        foreach ($employeeIds as $employeeId) {
            $att = $attendanceByEmployee[$employeeId] ?? $this->emptyAttendanceAggregate();
            $overtime = $overtimeByEmployee[$employeeId] ?? $this->emptyOvertimeAggregate();
            $overtimeHours = $overtime['payable_overtime_hours'];
            $leave = $leaveByEmployee[$employeeId] ?? ['paid' => 0.0, 'unpaid' => 0.0, 'work' => 0.0];
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

            $rows[] = [
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
                'employee_id' => $employeeId,
                'period_id' => $salaryPeriodId,
                'updated_at' => $now,
            ];
        }

        $rowsUpserted = $this->bulkPersist($salaryPeriodId, $tenantId, $legalEntityId, $rows, $now);

        return [
            'period_id' => $salaryPeriodId,
            'employees' => count($employeeIds),
            'rows_upserted' => $rowsUpserted,
        ];
    }

    /**
     * Aggregate attendance day counts and late minutes for an employee in range.
     *
     * @return array{on_time_days:int, late_days:int, absent_days:int, late_minutes:float}
     */
    private function attendanceAggregates(array $employeeIds, string $start, string $end, int $tenantId, int $legalEntityId): array
    {
        $rows = DB::table('attendances')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start, $end])
            ->get(['employee_id', 'status', 'meta']);

        $totals = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;
            $totals[$employeeId] ??= $this->emptyAttendanceAggregate();
            $status = strtoupper((string) $row->status);
            if (in_array($status, ['ON_TIME', 'PRESENT', 'CHECKED_IN', 'CHECKED_OUT', 'ĐÃ_DUYỆT', 'ĐÃ DUYỆT'], true)) {
                $totals[$employeeId]['on_time_days']++;
            } elseif ($status === 'LATE') {
                $totals[$employeeId]['late_days']++;
            } elseif ($status === 'EARLY_LEAVE') {
                $totals[$employeeId]['early_leave_days']++;
            } elseif ($status === 'ABSENT') {
                $totals[$employeeId]['absent_days']++;
            }
            $meta = is_string($row->meta) ? (json_decode($row->meta, true) ?: []) : (array) ($row->meta ?? []);
            $totals[$employeeId]['late_minutes'] += (float) ($meta['late_minutes'] ?? 0);
            $totals[$employeeId]['early_leave_minutes'] += (float) ($meta['early_leave_minutes'] ?? 0);
        }

        return $totals;
    }

    /** @return array<string, int|float> */
    private function emptyAttendanceAggregate(): array
    {
        return [
            'on_time_days' => 0, 'late_days' => 0, 'early_leave_days' => 0,
            'absent_days' => 0, 'late_minutes' => 0.0, 'early_leave_minutes' => 0.0,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function bulkPersist(
        int $periodId,
        int $tenantId,
        int $legalEntityId,
        array $rows,
        mixed $now,
    ): int {
        return DB::transaction(function () use ($periodId, $tenantId, $legalEntityId, $rows, $now): int {
            // A period lock serializes retries while the batch is persisted.
            DB::table('salary_periods')
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->where('id', $periodId)
                ->lockForUpdate()
                ->first();

            $employeeIds = array_column($rows, 'employee_id');
            $existing = DB::table('salary_attendance_summary')
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->where('period_id', $periodId)
                ->whereIn('employee_id', $employeeIds)
                ->pluck('id', 'employee_id');

            $updates = [];
            $inserts = [];
            foreach ($rows as $row) {
                $id = $existing->get($row['employee_id']);
                if ($id) {
                    $updates[] = ['id' => (int) $id] + $row;
                } else {
                    $inserts[] = $row + ['created_at' => $now];
                }
            }

            if ($updates !== []) {
                DB::table('salary_attendance_summary')->upsert(
                    $updates,
                    ['id'],
                    array_values(array_diff(array_keys($updates[0]), ['id', 'created_at'])),
                );
            }
            if ($inserts !== []) {
                DB::table('salary_attendance_summary')->insert($inserts);
            }

            return count($rows);
        });
    }

    /** @return array{verified_overtime_minutes:int,payable_overtime_minutes:int,payable_overtime_hours:float,warnings:array,blocking_issues:array} */
    private function overtimeAggregates(int $tenantId, array $employeeIds, string $start, string $end): array
    {
        $rows = DB::table('overtime_requests')
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('work_date', [$start, $end])
            ->get(['id', 'employee_id', 'work_date', 'meta']);
        $totals = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;
            $totals[$employeeId] ??= $this->emptyOvertimeAggregate();
            $meta = is_string($row->meta) ? (json_decode($row->meta, true) ?: []) : (array) ($row->meta ?? []);
            $reconciliation = (array) ($meta['overtime_reconciliation'] ?? []);
            $payable = max(0, (int) ($meta['payable_overtime_minutes'] ?? $reconciliation['payable_minutes'] ?? 0));
            $totals[$employeeId]['verified_overtime_minutes'] += $payable;
            if (empty($meta['converted_to_comp_off'])) {
                $totals[$employeeId]['payable_overtime_minutes'] += $payable;
            }
            array_push($totals[$employeeId]['warnings'], ...(array) ($reconciliation['warnings'] ?? []));
            if (($reconciliation['status'] ?? null) === 'NO_ATTENDANCE') {
                $totals[$employeeId]['blocking_issues'][] = [
                    'employee_id' => $employeeId,
                    'overtime_request_id' => (int) $row->id,
                    'work_date' => (string) $row->work_date,
                    'issue_code' => 'OT_NO_ATTENDANCE',
                    'message' => 'Đơn/ticket tăng ca đã duyệt nhưng không có phiên chấm công hoàn chỉnh.',
                ];
            }
        }

        foreach ($totals as &$total) {
            $total['payable_overtime_hours'] = round($total['payable_overtime_minutes'] / 60, 2);
            $total['warnings'] = array_values(array_unique($total['warnings']));
        }
        unset($total);

        return $totals;
    }

    /** @return array<string, int|float|array> */
    private function emptyOvertimeAggregate(): array
    {
        return [
            'verified_overtime_minutes' => 0,
            'payable_overtime_minutes' => 0,
            'payable_overtime_hours' => 0.0,
            'warnings' => [],
            'blocking_issues' => [],
        ];
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
    private function leaveDaysByEmployee(array $employeeIds, string $start, string $end, int $tenantId): array
    {
        $rows = DB::table('leave_requests as lr')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->where('lr.tenant_id', $tenantId)
            ->whereIn('lr.employee_id', $employeeIds)
            ->whereIn('lr.status', self::APPROVED_STATUSES)
            ->where('lr.start_date', '<=', $end)
            ->where('lr.end_date', '>=', $start)
            ->get(['lr.employee_id', 'lr.start_date', 'lr.end_date', 'lr.total_days', 'lt.category']);

        $totals = [];
        $periodStart = CarbonImmutable::parse($start);
        $periodEnd = CarbonImmutable::parse($end);
        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;
            $totals[$employeeId] ??= ['paid' => 0.0, 'unpaid' => 0.0, 'work' => 0.0];
            $leaveStart = CarbonImmutable::parse($row->start_date);
            $leaveEnd = CarbonImmutable::parse($row->end_date);
            $overlapStart = $leaveStart->gt($periodStart) ? $leaveStart : $periodStart;
            $overlapEnd = $leaveEnd->lt($periodEnd) ? $leaveEnd : $periodEnd;
            $days = min((float) $row->total_days, (float) ($overlapStart->diffInDays($overlapEnd) + 1));
            $category = strtoupper((string) $row->category);
            $bucket = $category === 'UNPAID' ? 'unpaid' : ($category === 'WORK' ? 'work' : 'paid');
            $totals[$employeeId][$bucket] += $days;
        }

        return $totals;
    }
}
