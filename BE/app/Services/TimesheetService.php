<?php

namespace App\Services;

use App\Jobs\ReconcileOvertimeDay;
use App\Models\Attendance;
use App\Models\AttendancePayrollReview;
use App\Support\HrmConfig;
use App\Support\TimePolicy;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P3 — Chấm công: dựng "Bảng công tháng" (timesheet grid nhân viên × ngày) và
 * tái phân loại trạng thái chấm công (ON_TIME/LATE/EARLY_LEAVE/ABSENT) theo
 * ca làm việc (shift_types) + dung sai đi trễ (attendance.late_grace_minutes).
 *
 * Mọi tham số luật đọc qua TimePolicy/HrmConfig (tenant-configurable). Công chuẩn
 * tháng = ngày dương trừ ngày nghỉ hằng tuần (mặc định CN) trừ ngày lễ — KHÔNG
 * trừ thứ Bảy (DN 6 ngày/tuần). Đây là mẫu số feed cho payroll.
 */
class TimesheetService
{
    public function __construct(
        private readonly ShiftResolver $shiftResolver,
        private readonly AttendanceReconciliationService $attendanceReconciliation,
        private readonly AttendanceChangePublisher $attendanceChanges,
    ) {}

    /** Trạng thái coi là "có mặt" (NV đã đến làm). */
    private const PRESENT_STATUSES = ['ON_TIME', 'LATE', 'EARLY_LEAVE'];

    private const APPROVED_STATUSES = ['APPROVED', 'ĐÃ_DUYỆT'];

    private const PENDING_STATUSES = ['PENDING', 'CHỜ_DUYỆT'];

    /**
     * Cache each employee page independently. The attendance version makes a
     * new punch visible immediately, while the short TTL bounds staleness for
     * leave, shift and employee changes that do not publish attendance events.
     */
    public function cachedMonthlyGrid(
        int $tenantId,
        int $legalEntityId,
        string $month,
        ?array $employeeIds = null,
        ?int $departmentId = null,
        ?int $page = null,
        int $perPage = 25,
        bool $refresh = false,
    ): array {
        $employeeIds = $employeeIds === null
            ? null
            : array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds !== null) {
            sort($employeeIds);
        }
        $version = $this->attendanceChanges->versionToken($tenantId, $legalEntityId);
        $scope = [
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'month' => $month,
            'employee_ids' => $employeeIds,
            'department_id' => $departmentId,
            'page' => $page,
            'per_page' => $perPage,
        ];
        $key = 'attendance:timesheet:'.$version.':'.hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
        $cache = null;

        try {
            $cache = $this->attendanceChanges->cache();
            if (! $refresh) {
                $cached = $cache->get($key);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        } catch (\Throwable $exception) {
            Log::debug('Attendance timesheet cache read skipped', ['error' => $exception->getMessage()]);
        }

        $grid = $this->monthlyGrid(
            $tenantId,
            $legalEntityId,
            $month,
            $employeeIds,
            $departmentId,
            $page,
            $perPage,
        );
        if ($cache) {
            try {
                $cache->put(
                    $key,
                    $grid,
                    now()->addSeconds(max((int) config('hrm.attendance.timesheet_cache_seconds', 60), 1)),
                );
            } catch (\Throwable $exception) {
                Log::debug('Attendance timesheet cache write skipped', ['error' => $exception->getMessage()]);
            }
        }

        return $grid;
    }

    /**
     * Tái phân loại các bản ghi attendances trong [start,end] theo engine.
     * Idempotent — chạy lại cập nhật cùng các dòng. Dùng shift của chính bản ghi
     * (shift_type_id), fallback ca đang gán cho nhân viên.
     *
     * @return array{scanned:int, updated:int}
     */
    public function recompute(
        int $tenantId,
        int $legalEntityId,
        string $start,
        string $end,
        ?array $employeeIds = null,
        ?Closure $onProgress = null,
        ?string $operationId = null,
    ): array {
        $shifts = $this->shiftMap($tenantId);
        $query = Attendance::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereBetween('work_date', [$start, $end])
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds));
        $closedRanges = DB::table('salary_periods')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->whereIn('status', ['CLOSED', 'LOCKED', 'PAID', 'ĐÃ_ĐÓNG', 'DA_DONG', 'ĐÃ_TRẢ', 'DA_TRA'])
            ->get(['start_date', 'end_date']);

        $scanned = 0;
        $updated = 0;
        $skippedLocked = 0;
        $createdReviews = 0;
        $staleReviews = 0;
        $query->orderBy('id')->chunkById(500, function ($rows) use (
            $tenantId, $start, $end, $shifts, $closedRanges, $onProgress,
            &$scanned, &$updated, &$skippedLocked, &$createdReviews, &$staleReviews
        ): void {
            $assignmentRows = $this->shiftResolver->rowsForRange(
                $rows->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
                $start,
                $end,
                $tenantId,
            );
            $reviewMap = AttendancePayrollReview::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('attendance_id', $rows->pluck('id'))
                ->get()
                ->keyBy('attendance_id');
            Attendance::withoutEvents(function () use (
                $rows, $assignmentRows, $shifts, $closedRanges, $reviewMap,
                &$updated, &$skippedLocked, &$createdReviews, &$staleReviews
            ): void {
                $preparedRows = [];
                $reviewRows = [];
                foreach ($rows as $row) {
                    $resolved = $this->shiftResolver->resolveFromRows(
                        $assignmentRows[(int) $row->employee_id] ?? [],
                        $row->work_date->toDateString(),
                    );
                    $shiftId = $row->shift_type_id ?: ($resolved->shift_type_id ?? null);
                    $isWorkday = ! $resolved || $this->shiftResolver->isAssignmentWorkday($resolved, $row->work_date->toDateString());
                    $shift = $isWorkday && $shiftId ? ($shifts[$shiftId] ?? null) : null;
                    if (in_array($row->status, self::APPROVED_STATUSES, true)) {
                        continue;
                    }
                    $workDate = $row->work_date->toDateString();
                    if ($closedRanges->contains(fn (object $range): bool => $workDate >= (string) $range->start_date && $workDate <= (string) $range->end_date
                    )) {
                        $skippedLocked++;

                        continue;
                    }
                    try {
                        $prepared = $this->attendanceReconciliation->prepareWithShift($row, $shift, false);
                        if ($prepared['changes'] !== []) {
                            $attributes = $row->getAttributes();
                            foreach ($prepared['changes'] as $key => $value) {
                                $attributes[$key] = $key === 'meta' && is_array($value)
                                    ? json_encode($value, JSON_THROW_ON_ERROR)
                                    : $value;
                            }
                            $attributes['updated_at'] = now();
                            $preparedRows[] = $attributes;
                        }
                        $row->forceFill($prepared['changes']);
                        $reviewRows[] = [
                            $row,
                            $prepared['calculation'],
                            $prepared['changes'] !== [],
                            $reviewMap->get($row->id),
                        ];
                    } catch (\RuntimeException $e) {
                        if ($e->getCode() === 409) {
                            $skippedLocked++;

                            continue;
                        }
                        throw $e;
                    }
                }
                DB::transaction(function () use (
                    $preparedRows, $reviewRows, &$updated, &$createdReviews, &$staleReviews
                ): void {
                    if ($preparedRows !== []) {
                        DB::table('attendances')->upsert(
                            $preparedRows,
                            ['id'],
                            ['shift_type_id', 'status', 'meta', 'updated_at'],
                        );
                    }
                    foreach ($reviewRows as [$row, $calculation, $attendanceChanged, $review]) {
                        $outcome = $this->attendanceReconciliation
                            ->syncPreparedKnownReviewWithOutcome($row, $calculation, $review, null, false);
                        $createdReviews += $outcome['notification'] === 'created' ? 1 : 0;
                        $staleReviews += $outcome['notification'] === 'stale' ? 1 : 0;
                        $updated += ($attendanceChanged || $outcome['changed']) ? 1 : 0;
                    }
                });
            });
            $scanned += $rows->count();
            if ($onProgress) {
                $onProgress($scanned, $updated, $skippedLocked);
            }
        });

        DB::afterCommit(function () use ($tenantId, $start, $end, $employeeIds): void {
            DB::table('overtime_requests')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', self::APPROVED_STATUSES)
                ->whereBetween('work_date', [$start, $end])
                ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
                ->select(['employee_id', 'work_date'])
                ->distinct()
                ->orderBy('employee_id')
                ->orderBy('work_date')
                ->chunk(500, function ($dates) use ($tenantId): void {
                    foreach ($dates as $row) {
                        ReconcileOvertimeDay::dispatch(
                            $tenantId,
                            (int) $row->employee_id,
                            CarbonImmutable::parse($row->work_date)->toDateString(),
                        );
                    }
                });
        });

        if ($updated > 0) {
            $audience = DB::table('attendances as a')
                ->join('employees as e', function ($join): void {
                    $join->on('e.id', '=', 'a.employee_id')->on('e.tenant_id', '=', 'a.tenant_id');
                })
                ->where('a.tenant_id', $tenantId)
                ->where('a.legal_entity_id', $legalEntityId)
                ->whereBetween('a.work_date', [$start, $end])
                ->when($employeeIds !== null, fn ($query) => $query->whereIn('a.employee_id', $employeeIds))
                ->select(['a.employee_id', 'e.department_id'])
                ->distinct()
                ->get();
            $this->attendanceChanges->publishScope(
                $tenantId,
                $legalEntityId,
                'recompute_refresh',
                $audience->pluck('department_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all(),
                $audience->pluck('employee_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all(),
            );
        }

        app(AttendancePayrollReviewService::class)->notifyHrBatch(
            $tenantId,
            $legalEntityId,
            $createdReviews,
            $staleReviews,
            $start,
            $end,
            $operationId,
        );

        return [
            'scanned' => $scanned,
            'updated' => $updated,
            'skipped_locked' => $skippedLocked,
            'created_reviews' => $createdReviews,
            'stale_reviews' => $staleReviews,
        ];
    }

    /** @return array<string, int|string> */
    public function monthlyOverview(
        int $tenantId,
        int $legalEntityId,
        string $month,
        ?array $employeeIds = null,
        ?int $departmentId = null,
    ): array {
        $employeeIds = $employeeIds === null
            ? null
            : array_values(array_unique(array_map('intval', $employeeIds)));
        $version = $this->attendanceChanges->versionToken($tenantId, $legalEntityId);
        $scope = compact('tenantId', 'legalEntityId', 'month', 'employeeIds', 'departmentId');
        $key = 'attendance:timesheet-overview:'.$version.':'.hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
        $cache = null;
        try {
            $cache = $this->attendanceChanges->cache();
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
        }

        $totals = [
            'employees' => 0, 'on_time_days' => 0, 'late_days' => 0,
            'early_leave_days' => 0, 'absent_days' => 0, 'payable_days' => 0.0,
            'overtime_hours' => 0.0,
        ];
        $employeeQuery = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('id');
        $employeeQuery->chunkById(100, function ($employees) use (
            $tenantId, $legalEntityId, $month, $departmentId, &$totals
        ): void {
            $ids = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
            $grid = $this->monthlyGrid($tenantId, $legalEntityId, $month, $ids, $departmentId);
            foreach ($grid['rows'] as $row) {
                $totals['employees']++;
                foreach (['on_time_days', 'late_days', 'early_leave_days', 'absent_days'] as $key) {
                    $totals[$key] += (int) ($row['totals'][$key] ?? 0);
                }
                $totals['payable_days'] += (float) ($row['totals']['payable_days'] ?? 0);
                $totals['overtime_hours'] += (float) ($row['totals']['overtime_hours'] ?? 0);
            }
        }, 'id');
        $totals['payable_days'] = round($totals['payable_days'], 1);
        $totals['overtime_hours'] = round($totals['overtime_hours'], 2);
        $totals['generated_at'] = now()->toIso8601String();
        if ($cache) {
            try {
                $cache->put($key, $totals, now()->addSeconds(30));
            } catch (\Throwable) {
            }
        }

        return $totals;
    }

    /**
     * Bảng công tháng: lưới nhân viên × ngày + tổng hợp mỗi nhân viên.
     *
     * @return array{
     *   month:string, start:string, end:string, standard_days:int,
     *   days:array<int,array{date:string,dow:int,day_type:string,is_rest:bool,is_holiday:bool}>,
     *   rows:array<int,array>
     * }
     */
    public function monthlyGrid(
        int $tenantId,
        int $legalEntityId,
        string $month,
        ?array $employeeIds = null,
        ?int $departmentId = null,
        ?int $page = null,
        int $perPage = 25,
    ): array {
        $startDate = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $endDate = $startDate->endOfMonth();
        $start = $startDate->toDateString();
        $end = $endDate->toDateString();
        $today = CarbonImmutable::parse(now()->toDateString());

        $holidays = TimePolicy::holidaySet($start, $end);
        $standardDays = TimePolicy::standardWorkingDays($start, $end);
        $halfDayHours = (float) HrmConfig::get('attendance.half_day_hours', 4);

        // Header ngày trong tháng.
        $days = [];
        for ($d = $startDate; $d->lte($endDate); $d = $d->addDay()) {
            $ds = $d->toDateString();
            $isHoliday = in_array($ds, $holidays, true);
            $isRest = TimePolicy::isRestDay($d);
            $days[] = [
                'date' => $ds,
                'day' => (int) $d->day,
                'dow' => (int) $d->dayOfWeek,
                'is_rest' => $isRest,
                'is_holiday' => $isHoliday,
                'day_type' => $isHoliday ? 'holiday' : ($isRest ? 'weekend' : 'weekday'),
            ];
        }

        // Nhân viên đang làm (chính thức + thử việc), loại tài khoản hệ thống.
        $employeeQuery = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->where(function ($query): void {
                if (DB::getDriverName() === 'pgsql') {
                    $query->whereNull('profile->system_account')->orWhere('profile->system_account', false);
                } else {
                    $query->whereNull('profile')->orWhereRaw("COALESCE(json_extract(profile, '$.system_account'), 0) = 0");
                }
            })
            ->orderBy('employee_code');

        $totalEmployees = (clone $employeeQuery)->count();
        if ($page !== null) {
            $perPage = min(max($perPage, 1), 50);
            $employeeQuery->forPage(max($page, 1), $perPage);
        }

        $employees = $employeeQuery
            ->get(['id', 'full_name', 'employee_code', 'hire_date', 'profile'])
            ->filter(function ($employee): bool {
                $profile = $this->decodeMeta($employee->profile ?? null);

                return empty($profile['system_account']);
            })
            ->values();

        $empIds = $employees->pluck('id')->all();
        $assignmentRows = $this->shiftResolver->rowsForRange($empIds, $start, $end, $tenantId);

        // Prefetch attendance / leave / OT cho cả tháng.
        $attByEmpDate = $this->attendanceIndex($tenantId, $legalEntityId, $start, $end, $empIds);
        $leaveByEmp = $this->leaveIndex($tenantId, $start, $end, $empIds);
        $otByEmpDate = $this->overtimeIndex($tenantId, $start, $end, $empIds);

        $rows = [];
        foreach ($employees as $emp) {
            $hireDate = $emp->hire_date ? CarbonImmutable::parse($emp->hire_date)->toDateString() : null;
            $cells = [];
            $totals = [
                'present_days' => 0, 'on_time_days' => 0, 'late_days' => 0,
                'early_leave_days' => 0, 'half_days' => 0, 'absent_days' => 0, 'leave_days' => 0,
                'holiday_days' => 0, 'rest_days' => 0, 'ot_days' => 0,
                'late_minutes' => 0, 'early_leave_minutes' => 0,
                'early_arrival_minutes' => 0, 'after_shift_minutes' => 0,
                'worked_hours' => 0.0, 'overtime_hours' => 0.0,
            ];

            foreach ($days as $day) {
                $ds = $day['date'];
                $att = $attByEmpDate[$emp->id][$ds] ?? null;
                $leave = $this->leaveOnDate($leaveByEmp[$emp->id] ?? [], $ds);
                $leaveCode = $leave['code'] ?? null;
                $ot = (float) ($otByEmpDate[$emp->id][$ds] ?? 0);
                $assignment = $this->shiftResolver->resolveFromRows(
                    $assignmentRows[(int) $emp->id] ?? [],
                    $ds,
                );
                // Ngày nghỉ của riêng nhân viên, gồm cả OFF nhập từ file.
                $isRest = $assignment
                    ? ! $this->shiftResolver->isAssignmentWorkday($assignment, $ds)
                    : $day['is_rest'];

                $cell = [
                    'date' => $ds,
                    'late_minutes' => 0,
                    'early_leave_minutes' => 0,
                    'early_arrival_minutes' => 0,
                    'after_shift_minutes' => 0,
                    'worked_hours' => 0.0,
                    'overtime_hours' => $ot,
                    'leave_code' => $leaveCode,
                    'is_ot_day' => false,
                    'payroll_review_status' => $att->payroll_review_status ?? null,
                    'payroll_review_percent' => isset($att->payroll_review_percent) ? (int) $att->payroll_review_percent : null,
                ];
                $totals['overtime_hours'] += $ot;

                if ($att && in_array($att->status, ['ON_TIME', 'LATE', 'EARLY_LEAVE'], true) || ($att && in_array($att->status, self::APPROVED_STATUSES, true))) {
                    $meta = $this->decodeMeta($att->meta);
                    $cell['check_in'] = $att->check_in_time;
                    $cell['check_out'] = $att->check_out_time;
                    $cell['late_minutes'] = (int) ($meta['late_minutes'] ?? 0);
                    $cell['early_leave_minutes'] = (int) ($meta['early_leave_minutes'] ?? 0);
                    $cell['early_arrival_minutes'] = (int) ($meta['early_arrival_minutes'] ?? 0);
                    $cell['after_shift_minutes'] = (int) ($meta['after_shift_minutes'] ?? 0);
                    $cell['worked_hours'] = (float) ($meta['worked_hours'] ?? 0);

                    $totals['late_minutes'] += $cell['late_minutes'];
                    $totals['early_leave_minutes'] += $cell['early_leave_minutes'];
                    $totals['early_arrival_minutes'] += $cell['early_arrival_minutes'];
                    $totals['after_shift_minutes'] += $cell['after_shift_minutes'];
                    $totals['worked_hours'] += $cell['worked_hours'];

                    // Nửa công: có đi làm nhưng giờ làm < ngưỡng nửa công.
                    $isHalf = $cell['worked_hours'] > 0 && $cell['worked_hours'] < $halfDayHours;
                    // Đi làm vào ngày nghỉ tuần / lễ → đánh dấu OT-eligible.
                    $cell['is_ot_day'] = $isRest || $day['is_holiday'];
                    if ($cell['is_ot_day']) {
                        $totals['ot_days']++;
                    }

                    $totals['present_days']++;
                    if ($isHalf) {
                        $cell['status'] = 'HALF_DAY';
                        $totals['half_days']++;
                    } else {
                        $cell['status'] = $att->status;
                        if ($att->status === 'LATE') {
                            $totals['late_days']++;
                        } elseif ($att->status === 'EARLY_LEAVE') {
                            $totals['early_leave_days']++;
                        } else {
                            $totals['on_time_days']++;
                        }
                    }
                } elseif ($att && $att->status === 'ABSENT') {
                    // Bản ghi đã đánh dấu vắng (vd bị từ chối xác minh).
                    $cell['status'] = 'ABSENT';
                    $totals['absent_days']++;
                } elseif ($day['is_holiday']) {
                    // Ngày lễ là ngày nghỉ hưởng lương — KHÔNG trừ phép dù đơn nghỉ
                    // có phủ qua. (Nếu có chấm công ở trên = đi làm ngày lễ → OT.)
                    $cell['status'] = 'HOLIDAY';
                    $totals['holiday_days']++;
                } elseif ($isRest) {
                    // Ngày nghỉ hằng tuần (theo ca / cấu hình). Phép KHÔNG áp vào ngày
                    // nghỉ. Đi làm ngày này = OT (đã xử lý ở nhánh có chấm công trên).
                    $cell['status'] = 'REST';
                    $totals['rest_days']++;
                } elseif ($leave && ! $leave['pending']) {
                    if (! empty($leave['half'])) {
                        $cell['status'] = 'LEAVE_HALF';
                        $totals['leave_days'] += 0.5;
                    } else {
                        $cell['status'] = 'LEAVE';
                        $totals['leave_days']++;
                    }
                } elseif ($leave && $leave['pending']) {
                    // Đơn nghỉ phép CHỜ DUYỆT phủ ngày làm việc → không tính vắng.
                    $cell['status'] = 'LEAVE_PENDING';
                } elseif ($hireDate && $ds < $hireDate) {
                    // Trước ngày vào làm → không tính (chưa là nhân viên).
                    $cell['status'] = '';
                } elseif (CarbonImmutable::parse($ds)->lt($today)) {
                    // Ngày làm việc ĐÃ QUA, không chấm công, không nghỉ → vắng.
                    // (Ngày hôm nay chưa kết thúc nên chưa tính vắng.)
                    $cell['status'] = 'ABSENT';
                    $totals['absent_days']++;
                } else {
                    $cell['status'] = ''; // tương lai
                }

                $cells[$ds] = $cell;
            }

            $totals['worked_hours'] = round($totals['worked_hours'], 2);
            $totals['overtime_hours'] = round($totals['overtime_hours'], 2);
            $totals['standard_days'] = $standardDays;
            // Công thực tế (cho payroll): ngày công đủ (present trừ nửa công) +
            // nửa công × 0.5 + nghỉ có lương. present_days đã gồm cả half_days.
            $fullPresent = $totals['present_days'] - $totals['half_days'];
            $totals['payable_days'] = round($fullPresent + $totals['half_days'] * 0.5 + $totals['leave_days'], 1);

            $rows[] = [
                'employee_id' => $emp->id,
                'employee_code' => $emp->employee_code,
                'full_name' => $emp->full_name,
                'cells' => $cells,
                'totals' => $totals,
            ];
        }

        return [
            'month' => $startDate->format('Y-m'),
            'start' => $start,
            'end' => $end,
            'standard_days' => $standardDays,
            'days' => $days,
            'rows' => $rows,
            'pagination' => $page === null ? null : [
                'current_page' => max($page, 1),
                'per_page' => $perPage,
                'total' => $totalEmployees,
                'last_page' => max(1, (int) ceil($totalEmployees / $perPage)),
            ],
        ];
    }

    // ── Prefetch helpers ─────────────────────────────────

    private function shiftMap(int $tenantId): array
    {
        return DB::table('shift_types')
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function attendanceIndex(int $tenantId, int $legalEntityId, string $start, string $end, array $empIds): array
    {
        if (empty($empIds)) {
            return [];
        }
        $map = [];
        DB::table('attendances')
            ->leftJoin('attendance_payroll_reviews as apr', 'apr.attendance_id', '=', 'attendances.id')
            ->where('attendances.tenant_id', $tenantId)
            ->where('attendances.legal_entity_id', $legalEntityId)
            ->whereIn('attendances.employee_id', $empIds)
            ->whereBetween('attendances.work_date', [$start, $end])
            ->get(['attendances.*', 'apr.status as payroll_review_status', 'apr.approved_percent as payroll_review_percent'])
            ->each(function ($r) use (&$map) {
                $date = CarbonImmutable::parse($r->work_date)->toDateString();
                $map[$r->employee_id][$date] = $r;
            });

        return $map;
    }

    private function leaveIndex(int $tenantId, string $start, string $end, array $empIds): array
    {
        if (empty($empIds)) {
            return [];
        }
        $map = [];
        DB::table('leave_requests')
            ->leftJoin('leave_types', 'leave_requests.leave_type_id', '=', 'leave_types.id')
            ->where('leave_requests.tenant_id', $tenantId)
            ->whereIn('leave_requests.employee_id', $empIds)
            ->whereIn('leave_requests.status', array_merge(self::APPROVED_STATUSES, self::PENDING_STATUSES))
            ->where('leave_requests.start_date', '<=', $end)
            ->where('leave_requests.end_date', '>=', $start)
            ->get([
                'leave_requests.employee_id',
                'leave_requests.start_date',
                'leave_requests.end_date',
                'leave_requests.status',
                'leave_requests.total_days',
                'leave_types.leave_type_code as code',
            ])
            ->each(function ($r) use (&$map) {
                $start = CarbonImmutable::parse($r->start_date)->toDateString();
                $end = CarbonImmutable::parse($r->end_date)->toDateString();
                // Nghỉ nửa ngày/theo giờ: cùng ngày + tổng < 1 công.
                $half = $start === $end && (float) $r->total_days > 0 && (float) $r->total_days < 1;
                $map[$r->employee_id][] = [
                    'start' => $start,
                    'end' => $end,
                    'code' => $r->code ?: 'LEAVE',
                    'pending' => ! in_array($r->status, self::APPROVED_STATUSES, true),
                    'half' => $half,
                ];
            });

        return $map;
    }

    private function overtimeIndex(int $tenantId, string $start, string $end, array $empIds): array
    {
        if (empty($empIds)) {
            return [];
        }
        $map = [];
        DB::table('overtime_requests')
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $empIds)
            ->whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('work_date', [$start, $end])
            ->get(['employee_id', 'work_date', 'meta'])
            ->each(function ($r) use (&$map) {
                $date = CarbonImmutable::parse($r->work_date)->toDateString();
                $meta = $this->decodeMeta($r->meta);
                $minutes = max(0, (int) ($meta['payable_overtime_minutes'] ?? 0));
                $map[$r->employee_id][$date] = round((($map[$r->employee_id][$date] ?? 0) * 60 + $minutes) / 60, 2);
            });

        return $map;
    }

    /** @return array{code:string,pending:bool}|null */
    private function leaveOnDate(array $intervals, string $date): ?array
    {
        // Prefer an approved leave over a pending one if both cover the day.
        $found = null;
        foreach ($intervals as $iv) {
            if ($date >= $iv['start'] && $date <= $iv['end']) {
                if (empty($iv['pending'])) {
                    return ['code' => $iv['code'], 'pending' => false, 'half' => ! empty($iv['half'])];
                }
                $found = ['code' => $iv['code'], 'pending' => true, 'half' => ! empty($iv['half'])];
            }
        }

        return $found;
    }

    private function decodeMeta($meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
