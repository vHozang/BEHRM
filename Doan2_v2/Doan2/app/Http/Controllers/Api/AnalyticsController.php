<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsController extends Controller
{
    /**
     * GET /dashboard/stats
     * Real aggregated dashboard metrics computed with a small set of grouped queries.
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        // ── Employees ────────────────────────────────────────
        $employeesByStatus = DB::table('employees')
            ->when($tenantId !== null, fn ($q) => $q->where('employees.tenant_id', $tenantId))
            ->where(fn ($q) => $q->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->select('status', DB::raw('COUNT(*) AS count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $employeesByDepartment = DB::table('employees AS e')
            ->leftJoin('departments AS d', 'd.id', '=', 'e.department_id')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
            ->where(fn ($q) => $q->whereNull('e.profile->system_account')->orWhere('e.profile->system_account', false))
            ->select(
                'e.department_id',
                DB::raw("COALESCE(d.department_name, 'Chưa phân bổ') AS department_name"),
                DB::raw('COUNT(*) AS count')
            )
            ->groupBy('e.department_id', 'd.department_name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->map(static fn ($row) => [
                'department_id' => $row->department_id !== null ? (int) $row->department_id : null,
                'department_name' => $row->department_name ?: 'Chưa phân bổ',
                'count' => (int) $row->count,
            ]);

        $employees = [
            'total' => (int) array_sum($employeesByStatus->map(static fn ($v) => (int) $v)->all()),
            'by_status' => $employeesByStatus->map(static fn ($v) => (int) $v),
            'by_department' => $employeesByDepartment,
        ];

        // ── Leave requests (focus pending) ───────────────────
        $leaveByStatus = DB::table('leave_requests')
            ->when($tenantId !== null, fn ($q) => $q->where('leave_requests.tenant_id', $tenantId))
            ->select('status', DB::raw('COUNT(*) AS count'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(static fn ($row) => [(string) ($row->status ?? 'UNKNOWN') => (int) $row->count]);

        $leaveRequests = [
            'by_status' => $leaveByStatus,
            'pending' => (int) (
                ($leaveByStatus['PENDING'] ?? 0)
                + ($leaveByStatus['pending'] ?? 0)
                + ($leaveByStatus['CHỜ_DUYỆT'] ?? 0)
            ),
            'unknown' => (int) ($leaveByStatus['UNKNOWN'] ?? 0),
        ];

        // ── On leave today (APPROVED, CURRENT_DATE within range) ──
        $approvedStatuses = ['APPROVED', 'ĐÃ_DUYỆT'];

        $onLeaveToday = DB::table('leave_requests AS lr')
            ->leftJoin('employees AS e', 'e.id', '=', 'lr.employee_id')
            ->leftJoin('leave_types AS lt', 'lt.id', '=', 'lr.leave_type_id')
            ->when($tenantId !== null, fn ($q) => $q->where('lr.tenant_id', $tenantId))
            ->whereIn('lr.status', $approvedStatuses)
            ->whereDate('lr.start_date', '<=', today())
            ->whereDate('lr.end_date', '>=', today())
            ->orderBy('lr.start_date')
            ->limit(8)
            ->get([
                'lr.employee_id',
                'e.full_name',
                'lt.leave_type_name',
                'lr.start_date',
                'lr.end_date',
            ])
            ->map(static fn ($row) => [
                'employee_id' => $row->employee_id !== null ? (int) $row->employee_id : null,
                'full_name' => $row->full_name,
                'leave_type_name' => $row->leave_type_name,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => substr((string) $row->end_date, 0, 10),
            ])
            ->values();

        // ── Leave quota (current-year leave_balances rollup) ──
        $currentYear = (string) today()->year;
        $quotaRow = DB::table('leave_balances')
            ->when($tenantId !== null, fn ($q) => $q->where('leave_balances.tenant_id', $tenantId))
            ->where('year', $currentYear)
            ->selectRaw('COALESCE(SUM(used_days), 0) AS used_days, COALESCE(SUM(total_days), 0) AS total_days')
            ->first();

        $quotaUsed = (float) ($quotaRow->used_days ?? 0);
        $quotaTotal = (float) ($quotaRow->total_days ?? 0);

        // ── Recent pending leave requests (newest first) ──────
        $recentPending = DB::table('leave_requests AS lr')
            ->leftJoin('employees AS e', 'e.id', '=', 'lr.employee_id')
            ->leftJoin('leave_types AS lt', 'lt.id', '=', 'lr.leave_type_id')
            ->when($tenantId !== null, fn ($q) => $q->where('lr.tenant_id', $tenantId))
            ->whereIn('lr.status', ['PENDING', 'CHỜ_DUYỆT'])
            ->orderByDesc('lr.id')
            ->limit(5)
            ->get([
                'lr.id',
                'lr.employee_id',
                'e.full_name',
                'lt.leave_type_name',
                'lr.start_date',
                'lr.end_date',
                'lr.total_days',
            ])
            ->map(static fn ($row) => [
                'id' => (int) $row->id,
                'employee_id' => $row->employee_id !== null ? (int) $row->employee_id : null,
                'full_name' => $row->full_name,
                'leave_type_name' => $row->leave_type_name,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => substr((string) $row->end_date, 0, 10),
                'total_days' => (float) ($row->total_days ?? 0),
            ])
            ->values();

        $leaveRequests['on_leave_today'] = $onLeaveToday;
        $leaveRequests['recent_pending'] = $recentPending;
        $leaveRequests['quota'] = [
            'used_days' => $quotaUsed,
            'total_days' => $quotaTotal,
            'percent' => $quotaTotal > 0 ? (int) round($quotaUsed / $quotaTotal * 100) : 0,
        ];

        // ── Attendances today ────────────────────────────────
        $attendanceDateColumn = Schema::hasColumn('attendances', 'work_date')
            ? 'work_date'
            : (Schema::hasColumn('attendances', 'date') ? 'date' : null);

        $attendanceStatusRows = $attendanceDateColumn !== null
            ? DB::table('attendances')
                ->when($tenantId !== null, fn ($q) => $q->where('attendances.tenant_id', $tenantId))
                ->select('status', DB::raw('COUNT(*) AS count'))
                ->whereDate($attendanceDateColumn, today())
                ->groupBy('status')
                ->get()
            : collect();

        $attendancesToday = [
            'present' => 0,
            'late' => 0,
            'early' => 0,
            'absent' => 0,
            'total' => 0,
        ];

        foreach ($attendanceStatusRows as $row) {
            $count = (int) $row->count;
            $status = mb_strtoupper((string) ($row->status ?? ''), 'UTF-8');
            $attendancesToday['total'] += $count;

            if (in_array($status, ['LATE', 'ĐI_MUỘN', 'MUỘN'], true)) {
                $attendancesToday['late'] += $count;
            } elseif (in_array($status, ['EARLY_LEAVE', 'VỀ_SỚM'], true)) {
                $attendancesToday['early'] += $count;
            } elseif (in_array($status, ['ABSENT', 'VẮNG', 'NGHỈ'], true)) {
                $attendancesToday['absent'] += $count;
            } else {
                $attendancesToday['present'] += $count;
            }
        }

        // ── Attendance trend (last 30 days) ──────────────────
        $attendanceTrend = [];
        if ($attendanceDateColumn !== null) {
            $from = today()->subDays(29);
            $trendRows = DB::table('attendances')
                ->when($tenantId !== null, fn ($q) => $q->where('attendances.tenant_id', $tenantId))
                ->select(
                    $attendanceDateColumn.' AS d',
                    'status',
                    DB::raw('COUNT(*) AS count')
                )
                ->whereBetween($attendanceDateColumn, [$from->toDateString(), today()->toDateString()])
                ->groupBy($attendanceDateColumn, 'status')
                ->get();

            // Pre-fill 30 day buckets so the chart always has a continuous axis.
            $buckets = [];
            for ($i = 0; $i < 30; $i++) {
                $key = $from->copy()->addDays($i)->toDateString();
                $buckets[$key] = ['date' => $key, 'present' => 0, 'late' => 0, 'early' => 0, 'absent' => 0];
            }

            foreach ($trendRows as $row) {
                $key = (string) $row->d;
                if (! isset($buckets[$key])) {
                    continue;
                }
                $count = (int) $row->count;
                $status = mb_strtoupper((string) ($row->status ?? ''), 'UTF-8');
                if (in_array($status, ['LATE', 'ĐI_MUỘN', 'MUỘN'], true)) {
                    $buckets[$key]['late'] += $count;
                } elseif (in_array($status, ['EARLY_LEAVE', 'VỀ_SỚM'], true)) {
                    $buckets[$key]['early'] += $count;
                } elseif (in_array($status, ['ABSENT', 'VẮNG', 'NGHỈ'], true)) {
                    $buckets[$key]['absent'] += $count;
                } else {
                    $buckets[$key]['present'] += $count;
                }
            }

            $attendanceTrend = array_values($buckets);
        }

        // ── Overtime (approved OT this month + 30-day daily hours trend) ──
        $overtime = ['month_hours' => 0.0, 'month_requests' => 0, 'pending' => 0, 'trend' => []];
        if (Schema::hasTable('overtime_requests')) {
            $approved = ['APPROVED', 'ĐÃ_DUYỆT'];
            $monthStart = today()->startOfMonth()->toDateString();
            $monthEnd = today()->endOfMonth()->toDateString();

            $monthOt = DB::table('overtime_requests')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereIn('status', $approved)
                ->whereBetween('work_date', [$monthStart, $monthEnd]);
            $overtime['month_hours'] = round((float) (clone $monthOt)->sum('total_hours'), 1);
            $overtime['month_requests'] = (clone $monthOt)->count();

            $overtime['pending'] = (int) DB::table('overtime_requests')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereIn('status', ['PENDING', 'CHỜ_DUYỆT'])
                ->count();

            $otFrom = today()->subDays(29);
            $otRows = DB::table('overtime_requests')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereIn('status', $approved)
                ->whereBetween('work_date', [$otFrom->toDateString(), today()->toDateString()])
                ->select('work_date AS d', DB::raw('SUM(total_hours) AS hours'))
                ->groupBy('work_date')
                ->get()
                ->keyBy(fn ($r) => (string) $r->d);

            $otBuckets = [];
            for ($i = 0; $i < 30; $i++) {
                $key = $otFrom->copy()->addDays($i)->toDateString();
                $otBuckets[] = ['date' => $key, 'hours' => round((float) ($otRows[$key]->hours ?? 0), 1)];
            }
            $overtime['trend'] = $otBuckets;
        }

        // ── Recruitment (candidates by status/stage) ─────────
        $recruitment = ['by_status' => collect(), 'total' => 0];
        if (Schema::hasTable('recruitment_candidates')) {
            $stageColumn = Schema::hasColumn('recruitment_candidates', 'application_status')
                ? 'application_status'
                : (Schema::hasColumn('recruitment_candidates', 'status') ? 'status' : null);

            if ($stageColumn !== null) {
                $byStage = DB::table('recruitment_candidates')
                    ->when($tenantId !== null, fn ($q) => $q->where('recruitment_candidates.tenant_id', $tenantId))
                    ->select($stageColumn.' AS stage', DB::raw('COUNT(*) AS count'))
                    ->groupBy($stageColumn)
                    ->get()
                    ->mapWithKeys(static fn ($row) => [(string) ($row->stage ?? 'UNKNOWN') => (int) $row->count]);

                $recruitment = [
                    'by_status' => $byStage,
                    'total' => (int) $byStage->sum(),
                ];
            }
        }

        // ── Contracts expiring within 30 days ────────────────
        $contractsExpiringSoon = 0;
        if (Schema::hasTable('contracts') && Schema::hasColumn('contracts', 'end_date')) {
            $contractsExpiringSoon = (int) DB::table('contracts')
                ->when($tenantId !== null, fn ($q) => $q->where('contracts.tenant_id', $tenantId))
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [today(), today()->addDays(30)])
                ->count();
        }

        // ── Upcoming (birthdays / anniversaries / new hires) ─
        $upcoming = $this->upcoming($tenantId);

        $data = [
            'employees' => $employees,
            'leave_requests' => $leaveRequests,
            'attendances_today' => $attendancesToday,
            'attendance_trend' => $attendanceTrend,
            'overtime' => $overtime,
            'recruitment' => $recruitment,
            'contracts' => [
                'expiring_within_30_days' => $contractsExpiringSoon,
            ],
            'upcoming' => $upcoming,
        ];

        // Do not merely hide unrelated cards in Vue: trim tenant-wide aggregates
        // at the API boundary too, so a partial role cannot inspect the raw JSON.
        $access = (array) $request->attributes->get('access', []);
        if (empty($access['full'])) {
            $modules = $access['modules'] ?? [];
            if (! in_array('recruitment', $modules, true)) {
                unset($data['recruitment']);
            }
            if (! in_array('hr', $modules, true)) {
                unset($data['contracts'], $data['upcoming']);
            }
            if (! in_array('time', $modules, true)) {
                unset($data['leave_requests'], $data['attendances_today'], $data['attendance_trend'], $data['overtime']);
            }
            if (! array_intersect(['hr', 'time'], $modules)) {
                unset($data['employees']);
            }
        }

        return $this->ok($data, 'Dashboard statistics');
    }

    /**
     * Upcoming people events for the dashboard, tenant-scoped to ACTIVE employees.
     *
     * - birthdays / work_anniversaries: month-day falls within the next 14 days
     *   (inclusive of today, year-agnostic; handles year wrap). days_until 0..14.
     *   Anniversaries additionally require >= 1 completed year.
     * - new_hires: hire_date within the last 30 days.
     *
     * Uses Postgres EXTRACT(MONTH/DAY) so leap-day and year-wrap are computed in PHP
     * for correctness; the window is small so the candidate set stays tiny.
     *
     * @return array{birthdays: array<int, array>, work_anniversaries: array<int, array>, new_hires: array<int, array>}
     */
    private function upcoming(?int $tenantId): array
    {
        $today = today();
        $windowDays = 14;

        $base = static fn () => DB::table('employees AS e')
            ->where('e.status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('e.profile->system_account')->orWhere('e.profile->system_account', false));

        // ── Birthdays ────────────────────────────────────────
        $birthdays = [];
        if (Schema::hasColumn('employees', 'date_of_birth')) {
            $rows = $base()
                ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
                ->whereNotNull('e.date_of_birth')
                ->get(['e.id', 'e.full_name', 'e.date_of_birth']);

            foreach ($rows as $row) {
                $next = $this->nextOccurrence($today, (int) date('n', strtotime($row->date_of_birth)), (int) date('j', strtotime($row->date_of_birth)));
                if ($next === null) {
                    continue;
                }
                $daysUntil = $today->diffInDays($next);
                if ($daysUntil <= $windowDays) {
                    $birthdays[] = [
                        'employee_id' => (int) $row->id,
                        'full_name' => $row->full_name,
                        'date' => $next->toDateString(),
                        'days_until' => $daysUntil,
                    ];
                }
            }

            usort($birthdays, static fn ($a, $b) => $a['days_until'] <=> $b['days_until']);
            $birthdays = array_slice($birthdays, 0, 8);
        }

        // ── Work anniversaries ───────────────────────────────
        $workAnniversaries = [];
        if (Schema::hasColumn('employees', 'hire_date')) {
            $rows = $base()
                ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
                ->whereNotNull('e.hire_date')
                ->get(['e.id', 'e.full_name', 'e.hire_date']);

            foreach ($rows as $row) {
                $hire = strtotime($row->hire_date);
                $next = $this->nextOccurrence($today, (int) date('n', $hire), (int) date('j', $hire));
                if ($next === null) {
                    continue;
                }
                $daysUntil = $today->diffInDays($next);
                if ($daysUntil > $windowDays) {
                    continue;
                }
                $years = (int) date('Y', $next->getTimestamp()) - (int) date('Y', $hire);
                if ($years < 1) {
                    continue;
                }
                $workAnniversaries[] = [
                    'employee_id' => (int) $row->id,
                    'full_name' => $row->full_name,
                    'years' => $years,
                    'date' => $next->toDateString(),
                    'days_until' => $daysUntil,
                ];
            }

            usort($workAnniversaries, static fn ($a, $b) => $a['days_until'] <=> $b['days_until']);
            $workAnniversaries = array_slice($workAnniversaries, 0, 8);
        }

        // ── New hires (hire_date within last 30 days) ────────
        $newHires = [];
        if (Schema::hasColumn('employees', 'hire_date')) {
            $newHires = $base()
                ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
                ->whereNotNull('e.hire_date')
                ->whereBetween('e.hire_date', [$today->copy()->subDays(30)->toDateString(), $today->toDateString()])
                ->orderByDesc('e.hire_date')
                ->limit(8)
                ->get(['e.id', 'e.full_name', 'e.hire_date'])
                ->map(static fn ($row) => [
                    'employee_id' => (int) $row->id,
                    'full_name' => $row->full_name,
                    'hire_date' => substr((string) $row->hire_date, 0, 10),
                ])
                ->all();
        }

        return [
            'birthdays' => $birthdays,
            'work_anniversaries' => $workAnniversaries,
            'new_hires' => $newHires,
        ];
    }

    /**
     * Next calendar occurrence (>= today) of a given month/day, handling year wrap.
     * Feb 29 in a non-leap year falls back to Feb 28. Returns null on invalid input.
     */
    private function nextOccurrence(Carbon $today, int $month, int $day): ?Carbon
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        for ($year = $today->year; $year <= $today->year + 1; $year++) {
            $d = $day;
            if (! checkdate($month, $d, $year)) {
                // e.g. Feb 29 on a non-leap year -> clamp to last valid day of month.
                $d = (int) $today->copy()->setDate($year, $month, 1)->endOfMonth()->day;
            }
            $candidate = Carbon::create($year, $month, $d)->startOfDay();
            if ($candidate->greaterThanOrEqualTo($today->copy()->startOfDay())) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * POST /reports/generate
     * Body: { "type": <string>, "filters": <object optional> }
     */
    public function generateReport(Request $request): JsonResponse
    {
        $type = $request->input('type');
        $filters = $request->input('filters', []);
        if (! is_array($filters)) {
            $filters = [];
        }

        $supported = ['headcount', 'leave-summary', 'payroll-summary', 'attendance-summary',
            'bhxh-declaration', 'pit-finalization',
            'workforce-structure', 'leave-liability', 'labor-cost', 'hr-metrics'];
        if (! is_string($type) || ! in_array($type, $supported, true)) {
            return $this->validationError([
                'type' => ['Unknown report type. Supported: '.implode(', ', $supported)],
            ]);
        }

        // Period-scoped VN compliance reports require a period_id filter.
        if (in_array($type, ['bhxh-declaration', 'pit-finalization', 'labor-cost'], true)) {
            $periodId = $filters['period_id'] ?? null;
            if ($periodId === null || $periodId === '' || ! is_numeric($periodId)) {
                return $this->validationError([
                    'filters.period_id' => ['period_id is required for '.$type.'.'],
                ]);
            }
        }

        $rows = match ($type) {
            'headcount' => $this->headcountRows(),
            'leave-summary' => $this->leaveSummaryRows(),
            'payroll-summary' => $this->payrollSummaryRows(),
            'attendance-summary' => $this->attendanceSummaryRows(),
            'bhxh-declaration' => $this->bhxhDeclarationRows($filters),
            'pit-finalization' => $this->pitFinalizationRows($filters),
            'workforce-structure' => $this->workforceStructureRows(),
            'leave-liability' => $this->leaveLiabilityRows(),
            'labor-cost' => $this->laborCostRows($filters),
            'hr-metrics' => $this->hrMetricsRows($filters),
        };

        $authEmployeeId = $request->attributes->get('auth_employee_id');
        $now = now();

        $historyId = DB::table('report_histories')->insertGetId(TenantContext::stamp([
            'template_id' => null,
            'executed_by' => $authEmployeeId,
            'executed_at' => $now,
            'parameters' => json_encode(['type' => $type, 'filters' => $filters], JSON_UNESCAPED_UNICODE),
            'status' => 'DONE',
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return $this->ok([
            'type' => $type,
            'rows' => $rows,
            'history_id' => (int) $historyId,
        ], 'Report generated');
    }

    // ── Report builders ──────────────────────────────────────

    private function headcountRows(): Collection
    {
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        return DB::table('employees AS e')
            ->leftJoin('departments AS d', 'd.id', '=', 'e.department_id')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
            ->whereIn('e.status', ['ACTIVE', 'PROBATION'])
            ->where(fn ($q) => $q->whereNull('e.profile->system_account')->orWhere('e.profile->system_account', false))
            ->select(
                'e.department_id',
                DB::raw("COALESCE(d.department_name, 'Chưa phân bổ') AS department_name"),
                DB::raw('COUNT(*) AS headcount')
            )
            ->groupBy('e.department_id', 'd.department_name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->map(static fn ($row) => [
                'department_id' => $row->department_id !== null ? (int) $row->department_id : null,
                'department_name' => $row->department_name ?: 'Chưa phân bổ',
                'headcount' => (int) $row->headcount,
            ]);
    }

    private function leaveSummaryRows(): Collection
    {
        $hasLeaveTypes = Schema::hasTable('leave_types');
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        $query = DB::table('leave_requests AS lr')
            ->when($tenantId !== null, fn ($q) => $q->where('lr.tenant_id', $tenantId))
            ->select(
                'lr.status',
                'lr.leave_type_id',
                DB::raw('COUNT(*) AS count'),
                DB::raw('COALESCE(SUM(lr.total_days), 0) AS total_days')
            )
            ->groupBy('lr.status', 'lr.leave_type_id');

        if ($hasLeaveTypes) {
            $query->leftJoin('leave_types AS lt', 'lt.id', '=', 'lr.leave_type_id')
                ->addSelect(DB::raw('lt.leave_type_name AS leave_type_name'))
                ->groupBy('lt.leave_type_name');
        }

        return $query->get()->map(static function ($row) use ($hasLeaveTypes) {
            return [
                'status' => $row->status,
                'leave_type_id' => $row->leave_type_id !== null ? (int) $row->leave_type_id : null,
                'leave_type_name' => $hasLeaveTypes ? ($row->leave_type_name ?? null) : null,
                'count' => (int) $row->count,
                'total_days' => (float) $row->total_days,
            ];
        });
    }

    private function payrollSummaryRows(): Collection
    {
        if (! Schema::hasTable('salary_details')) {
            return collect();
        }

        $hasPeriods = Schema::hasTable('salary_periods');
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        $query = DB::table('salary_details AS sd')
            ->when($tenantId !== null, fn ($q) => $q->where('sd.tenant_id', $tenantId))
            ->select(
                'sd.period_id',
                DB::raw('COUNT(*) AS employees'),
                DB::raw('COALESCE(SUM(sd.gross_salary), 0) AS total_gross'),
                DB::raw('COALESCE(SUM(sd.net_salary), 0) AS total_net')
            )
            ->groupBy('sd.period_id');

        if ($hasPeriods) {
            $query->leftJoin('salary_periods AS sp', 'sp.id', '=', 'sd.period_id')
                ->addSelect(
                    DB::raw('sp.period_code AS period_code'),
                    DB::raw('sp.period_name AS period_name')
                )
                ->groupBy('sp.period_code', 'sp.period_name', 'sp.start_date')
                ->orderBy('sp.start_date');
        }

        return $query->get()->map(static function ($row) use ($hasPeriods) {
            return [
                'period_id' => $row->period_id !== null ? (int) $row->period_id : null,
                'period_code' => $hasPeriods ? ($row->period_code ?? null) : null,
                'period_name' => $hasPeriods ? ($row->period_name ?? null) : null,
                'employees' => (int) $row->employees,
                'total_gross' => (float) $row->total_gross,
                'total_net' => (float) $row->total_net,
            ];
        });
    }

    private function attendanceSummaryRows(): Collection
    {
        if (! Schema::hasTable('attendances')) {
            return collect();
        }

        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        $status = "CASE WHEN status IN ('PRESENT', 'CHECKED_IN', 'CHECKED_OUT', 'ĐÃ_DUYỆT', 'ĐÃ DUYỆT') THEN 'ON_TIME' ELSE status END";

        return DB::table('attendances')
            ->when($tenantId !== null, fn ($q) => $q->where('attendances.tenant_id', $tenantId))
            ->selectRaw("{$status} AS status, COUNT(*) AS count")
            ->groupByRaw($status)
            ->get()
            ->map(static fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
            ]);
    }

    /**
     * Cơ cấu lao động — nền cho "Báo cáo tình hình sử dụng lao động" nộp Sở LĐ-TB&XH
     * (Mẫu 01/PLI, Nghị định 145/2020): chia theo giới tính, nhóm tuổi, thâm niên,
     * trình độ và loại hợp đồng. Mỗi dòng = 1 tiêu chí + 1 giá trị + số người + tỷ lệ.
     */
    private function workforceStructureRows(): Collection
    {
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;
        $base = fn () => DB::table('employees AS e')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
            ->whereIn('e.status', ['ACTIVE', 'PROBATION']);

        $total = (int) $base()->count();
        if ($total === 0) {
            return collect();
        }

        $rows = collect();
        $push = function (string $dim, string $value, int $count) use (&$rows, $total) {
            $rows->push([
                'tieu_chi' => $dim,
                'phan_loai' => $value,
                'so_nguoi' => $count,
                'ty_le_phan_tram' => round($count * 100 / max(1, $total), 1),
            ]);
        };

        $push('Tổng số lao động', 'Đang làm việc', $total);

        foreach ($base()->selectRaw("COALESCE(NULLIF(e.gender,''),'Chưa khai báo') g, COUNT(*) c")
            ->groupBy('g')->orderByDesc('c')->get() as $r) {
            $push('Giới tính', ['MALE' => 'Nam', 'FEMALE' => 'Nữ'][$r->g] ?? $r->g, (int) $r->c);
        }

        $ageBands = "CASE WHEN e.date_of_birth IS NULL THEN 'Chưa khai báo'
            WHEN EXTRACT(YEAR FROM AGE(e.date_of_birth)) < 25 THEN 'Dưới 25'
            WHEN EXTRACT(YEAR FROM AGE(e.date_of_birth)) < 35 THEN 'Từ 25 đến 34'
            WHEN EXTRACT(YEAR FROM AGE(e.date_of_birth)) < 45 THEN 'Từ 35 đến 44'
            WHEN EXTRACT(YEAR FROM AGE(e.date_of_birth)) < 55 THEN 'Từ 45 đến 54'
            ELSE 'Từ 55 trở lên' END";
        foreach ($base()->selectRaw("{$ageBands} b, COUNT(*) c")->groupByRaw($ageBands)->orderBy('b')->get() as $r) {
            $push('Nhóm tuổi', $r->b, (int) $r->c);
        }

        $seniority = "CASE WHEN e.hire_date IS NULL THEN 'Chưa khai báo'
            WHEN EXTRACT(YEAR FROM AGE(e.hire_date)) < 1 THEN 'Dưới 1 năm'
            WHEN EXTRACT(YEAR FROM AGE(e.hire_date)) < 3 THEN 'Từ 1 đến 3 năm'
            WHEN EXTRACT(YEAR FROM AGE(e.hire_date)) < 5 THEN 'Từ 3 đến 5 năm'
            WHEN EXTRACT(YEAR FROM AGE(e.hire_date)) < 10 THEN 'Từ 5 đến 10 năm'
            ELSE 'Trên 10 năm' END";
        foreach ($base()->selectRaw("{$seniority} b, COUNT(*) c")->groupByRaw($seniority)->orderBy('b')->get() as $r) {
            $push('Thâm niên', $r->b, (int) $r->c);
        }

        foreach ($base()->selectRaw("COALESCE(NULLIF(e.profile->>'education_level',''),'Chưa khai báo') b, COUNT(*) c")
            ->groupByRaw("COALESCE(NULLIF(e.profile->>'education_level',''),'Chưa khai báo')")
            ->orderByDesc('c')->get() as $r) {
            $push('Trình độ', $r->b, (int) $r->c);
        }

        if (Schema::hasTable('contracts') && Schema::hasTable('contract_types')) {
            foreach (DB::table('contracts AS c')
                ->join('employees AS e', 'e.id', '=', 'c.employee_id')
                ->leftJoin('contract_types AS ct', 'ct.id', '=', 'c.contract_type_id')
                ->when($tenantId !== null, fn ($q) => $q->where('c.tenant_id', $tenantId))
                ->whereIn('c.status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
                ->whereIn('e.status', ['ACTIVE', 'PROBATION'])
                ->selectRaw("COALESCE(ct.contract_type_name, 'Chưa phân loại') b, COUNT(*) c")
                ->groupByRaw('COALESCE(ct.contract_type_name, \'Chưa phân loại\')')
                ->orderByDesc('c')->get() as $r) {
                $push('Loại hợp đồng', $r->b, (int) $r->c);
            }
        }

        return $rows;
    }

    /**
     * BẢNG CHỈ SỐ NHÂN SỰ (HR scorecard) — các TỶ LỆ để ban giám đốc đọc, khác với
     * dashboard chỉ ĐẾM số lượng. Mỗi dòng: nhóm · chỉ số · giá trị · đơn vị · diễn giải.
     * Kỳ mặc định = tháng hiện tại; truyền filters.from / filters.to để đổi.
     *
     * ponytail: mỗi chỉ số 1 truy vấn nhỏ, dễ đọc/sửa từng cái; gộp thành 1 query
     * khổng lồ chỉ đáng làm nếu bảng chỉ số này bị gọi liên tục.
     */
    private function hrMetricsRows(array $filters): Collection
    {
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from']) : Carbon::now()->startOfMonth();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to']) : Carbon::now()->endOfMonth();
        $fromS = $from->toDateString();
        $toS = $to->toDateString();
        $year = (int) $to->format('Y');

        $rows = collect();
        $add = function (string $nhom, string $chiSo, $giaTri, string $donVi, string $dienGiai = '') use (&$rows) {
            $rows->push([
                'nhom' => $nhom, 'chi_so' => $chiSo,
                'gia_tri' => is_float($giaTri) ? round($giaTri, 1) : $giaTri,
                'don_vi' => $donVi, 'dien_giai' => $dienGiai,
            ]);
        };
        $pct = fn ($a, $b) => $b > 0 ? round($a * 100 / $b, 1) : 0.0;

        $emp = fn () => DB::table('employees AS e')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
            ->whereIn('e.status', ['ACTIVE', 'PROBATION']);

        // ── 1. Nhân lực ──
        $total = (int) $emp()->count();
        $add('Nhân lực', 'Tổng lao động đang làm việc', $total, 'người', "Kỳ {$fromS} → {$toS}");
        if ($total === 0) {
            return $rows;
        }
        $avgSeniority = (float) ($emp()->whereNotNull('e.hire_date')
            ->selectRaw('AVG(EXTRACT(YEAR FROM AGE(e.hire_date))) v')->value('v') ?? 0);
        $add('Nhân lực', 'Thâm niên bình quân', $avgSeniority, 'năm', 'Càng cao càng ổn định');
        $female = (int) $emp()->where('e.gender', 'FEMALE')->count();
        $add('Nhân lực', 'Tỷ lệ lao động nữ', $pct($female, $total), '%', "{$female}/{$total} — số liệu cho báo cáo Sở LĐ-TB&XH");
        $probation = (int) $emp()->where('e.status', 'PROBATION')->count();
        $add('Nhân lực', 'Tỷ lệ đang thử việc', $pct($probation, $total), '%', "{$probation} người");
        $newHire = (int) $emp()->whereBetween('e.hire_date', [$fromS, $toS])->count();
        $add('Nhân lực', 'Tuyển mới trong kỳ', $newHire, 'người', '');

        // Biến động & tỷ lệ nghỉ việc — mở khoá nhờ quyết định thôi việc ghi
        // profile->termination_date (PersonnelDecisionController).
        $leavers = (int) DB::table('employees')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereRaw("profile->>'termination_date' BETWEEN ? AND ?", [$fromS, $toS])
            ->count();
        $avgHeadcount = $total + $leavers / 2;   // xấp xỉ headcount bình quân kỳ
        $add('Nhân lực', 'Nghỉ việc trong kỳ', $leavers, 'người', 'Theo quyết định chấm dứt HĐ đã duyệt');
        $add('Nhân lực', 'Tỷ lệ nghỉ việc (turnover)', $pct($leavers, max(1, (int) round($avgHeadcount))), '%',
            'Tham chiếu ngành sản xuất VN: 2–4%/tháng là bình thường');
        $earlyLeavers = (int) DB::table('employees')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereRaw("profile->>'termination_date' BETWEEN ? AND ?", [$fromS, $toS])
            ->whereRaw("(profile->>'termination_date')::date - hire_date < 90")
            ->count();
        if ($leavers > 0) {
            $add('Nhân lực', 'Nghỉ trong 90 ngày đầu', $pct($earlyLeavers, $leavers), '%',
                'Cao → chất lượng tuyển dụng hoặc hội nhập có vấn đề');
        }

        // ── 2. Chấm công (chỉ số kỷ luật lao động) ──
        if (Schema::hasTable('attendances')) {
            $att = fn () => DB::table('attendances AS a')
                ->when($tenantId !== null, fn ($q) => $q->where('a.tenant_id', $tenantId))
                ->whereBetween('a.work_date', [$fromS, $toS]);
            $totalAtt = (int) $att()->count();
            if ($totalAtt > 0) {
                $late = (int) $att()->where('a.status', 'LATE')->count();
                $absent = (int) $att()->where('a.status', 'ABSENT')->count();
                $early = (int) $att()->where('a.status', 'EARLY_LEAVE')->count();
                $add('Chấm công', 'Tỷ lệ đi muộn', $pct($late, $totalAtt), '%', "{$late}/{$totalAtt} lượt công");
                $add('Chấm công', 'Tỷ lệ vắng không phép', $pct($absent, $totalAtt), '%', 'Chuẩn quản trị: nên dưới 3%');
                $add('Chấm công', 'Tỷ lệ về sớm', $pct($early, $totalAtt), '%', "{$early} lượt");
                $add('Chấm công', 'Ngày công bình quân', $totalAtt / max(1, $total), 'ngày/người', 'Trong kỳ báo cáo');
            }
        }

        // ── 3. Tăng ca (rủi ro pháp lý Đ.107) ──
        if (Schema::hasTable('overtime_requests')) {
            $otBase = fn () => DB::table('overtime_requests AS o')
                ->when($tenantId !== null, fn ($q) => $q->where('o.tenant_id', $tenantId))
                ->whereIn('o.status', ['APPROVED', 'ĐÃ_DUYỆT']);
            $otHours = (float) $otBase()->whereBetween('o.work_date', [$fromS, $toS])->sum('o.total_hours');
            $otPeople = (int) $otBase()->whereBetween('o.work_date', [$fromS, $toS])->distinct()->count('o.employee_id');
            $add('Tăng ca', 'Tổng giờ tăng ca trong kỳ', $otHours, 'giờ', "{$otPeople} người có tăng ca");
            $add('Tăng ca', 'Giờ tăng ca bình quân', $otPeople > 0 ? $otHours / $otPeople : 0.0, 'giờ/người', 'Tính trên người CÓ tăng ca');

            // Cảnh báo trần OT năm (Đ.107 BLLĐ, config overtime.yearly_max_hours).
            $yMax = (float) \App\Support\HrmConfig::get('overtime.yearly_max_hours', 200);
            // select tường minh: group theo employee_id mà để SELECT * sẽ lỗi 42803 (Postgres).
            $nearCap = (int) $otBase()->whereRaw('EXTRACT(YEAR FROM o.work_date) = ?', [$year])
                ->select('o.employee_id')
                ->groupBy('o.employee_id')
                ->havingRaw('SUM(o.total_hours) >= ?', [$yMax * 0.8])
                ->get()->count();
            $add('Tăng ca', 'Số người chạm ngưỡng 80% trần OT năm', $nearCap, 'người',
                "Trần {$yMax}h/năm — vượt là vi phạm Điều 107 BLLĐ");
        }

        // ── 4. Phép năm ──
        if (Schema::hasTable('leave_balances')) {
            $lb = DB::table('leave_balances AS lb')
                ->join('employees AS e', 'e.id', '=', 'lb.employee_id')
                ->when($tenantId !== null, fn ($q) => $q->where('lb.tenant_id', $tenantId))
                ->where('lb.year', (string) $year)
                ->whereIn('e.status', ['ACTIVE', 'PROBATION'])
                ->selectRaw('COALESCE(SUM(lb.total_days),0) t, COALESCE(SUM(lb.used_days),0) u, COALESCE(SUM(lb.remaining_days),0) r')
                ->first();
            if ($lb && (float) $lb->t > 0) {
                $add('Phép năm', 'Tỷ lệ sử dụng phép', $pct((float) $lb->u, (float) $lb->t), '%',
                    'Thấp quá → dồn phép cuối năm, tăng chi phí phải trả');
                $add('Phép năm', 'Ngày phép còn lại bình quân', (float) $lb->r / max(1, $total), 'ngày/người', '');
            }
        }

        // ── 5. Tuân thủ ──
        if (Schema::hasTable('contracts')) {
            $withContract = (int) DB::table('contracts AS c')
                ->join('employees AS e', 'e.id', '=', 'c.employee_id')
                ->when($tenantId !== null, fn ($q) => $q->where('c.tenant_id', $tenantId))
                ->whereIn('c.status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
                ->whereIn('e.status', ['ACTIVE', 'PROBATION'])
                ->distinct()->count('e.id');
            $add('Tuân thủ', 'Tỷ lệ có hợp đồng hiệu lực', $pct($withContract, $total), '%',
                $withContract < $total ? 'CẢNH BÁO: '.($total - $withContract).' người chưa có HĐ hiệu lực' : 'Đạt 100%');

            $expiring = (int) DB::table('contracts')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
                ->whereBetween('end_date', [Carbon::today()->toDateString(), Carbon::today()->addDays(30)->toDateString()])
                ->count();
            $add('Tuân thủ', 'Hợp đồng hết hạn trong 30 ngày', $expiring, 'hợp đồng', 'Cần chuẩn bị gia hạn/ký mới');
        }
        if (Schema::hasTable('certificates')) {
            $certTotal = (int) DB::table('certificates')->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->count();
            $certValid = (int) DB::table('certificates')->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', Carbon::today()->toDateString()))
                ->count();
            if ($certTotal > 0) {
                $add('Tuân thủ', 'Tỷ lệ chứng chỉ còn hiệu lực', $pct($certValid, $certTotal), '%',
                    'Gồm chứng chỉ an toàn lao động (NĐ 44/2016)');
            }
        }

        return $rows;
    }

    /**
     * Quỹ phép phải trả (leave liability) — số tiền doanh nghiệp đang "nợ" người lao
     * động nếu họ nghỉ hết phép còn lại. Kế toán dùng để trích trước chi phí.
     * Đơn giá ngày = lương cơ bản / số ngày công chuẩn (config payroll.standard_days).
     */
    private function leaveLiabilityRows(): Collection
    {
        if (! Schema::hasTable('leave_balances')) {
            return collect();
        }
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;
        $stdDays = max(1.0, (float) \App\Support\HrmConfig::get('payroll.standard_days', 26));

        return DB::table('leave_balances AS lb')
            ->join('employees AS e', 'e.id', '=', 'lb.employee_id')
            ->leftJoin('departments AS d', 'd.id', '=', 'e.department_id')
            ->when($tenantId !== null, fn ($q) => $q->where('lb.tenant_id', $tenantId))
            ->where('lb.year', (string) now()->year)
            ->whereIn('e.status', ['ACTIVE', 'PROBATION'])
            ->selectRaw("COALESCE(d.department_name, 'Chưa phân bổ') AS phong_ban,
                COUNT(DISTINCT e.id) AS so_nhan_vien,
                COALESCE(SUM(lb.remaining_days), 0) AS tong_ngay_phep_con,
                COALESCE(SUM(lb.remaining_days * COALESCE(e.base_salary, 0) / {$stdDays}), 0) AS tien_phai_tra")
            ->groupByRaw("COALESCE(d.department_name, 'Chưa phân bổ')")
            ->orderByDesc('tien_phai_tra')
            ->get()
            ->map(static fn ($r) => [
                'phong_ban' => $r->phong_ban,
                'so_nhan_vien' => (int) $r->so_nhan_vien,
                'tong_ngay_phep_con' => round((float) $r->tong_ngay_phep_con, 1),
                'tien_phai_tra' => round((float) $r->tien_phai_tra),
            ]);
    }

    /**
     * Chi phí lao động theo phòng ban cho 1 kỳ lương: quỹ lương (gross) + phần bảo
     * hiểm DOANH NGHIỆP đóng (21,5%) = tổng chi phí thực của doanh nghiệp cho nhân sự.
     * Nguồn: salary_details.gross_salary + meta->insurance_employer->total.
     */
    private function laborCostRows(array $filters): Collection
    {
        if (! Schema::hasTable('salary_details')) {
            return collect();
        }
        $periodId = (int) ($filters['period_id'] ?? 0);
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        return DB::table('salary_details AS sd')
            ->join('employees AS e', 'e.id', '=', 'sd.employee_id')
            ->leftJoin('departments AS d', 'd.id', '=', 'e.department_id')
            ->when($tenantId !== null, fn ($q) => $q->where('sd.tenant_id', $tenantId))
            ->where('sd.period_id', $periodId)
            ->selectRaw("COALESCE(d.department_name, 'Chưa phân bổ') AS phong_ban,
                COUNT(*) AS so_nhan_vien,
                COALESCE(SUM(sd.gross_salary), 0) AS quy_luong_gross,
                COALESCE(SUM(sd.net_salary), 0) AS thuc_chi_cho_nv,
                COALESCE(SUM((sd.meta->'insurance_employer'->>'total')::numeric), 0) AS bao_hiem_dn_dong")
            ->groupByRaw("COALESCE(d.department_name, 'Chưa phân bổ')")
            ->orderByDesc('quy_luong_gross')
            ->get()
            ->map(static function ($r) {
                $gross = (float) $r->quy_luong_gross;
                $er = (float) $r->bao_hiem_dn_dong;
                $n = max(1, (int) $r->so_nhan_vien);

                return [
                    'phong_ban' => $r->phong_ban,
                    'so_nhan_vien' => (int) $r->so_nhan_vien,
                    'quy_luong_gross' => round($gross),
                    'bao_hiem_dn_dong' => round($er),
                    'tong_chi_phi' => round($gross + $er),
                    'chi_phi_binh_quan' => round(($gross + $er) / $n),
                ];
            });
    }

    /**
     * BHXH declaration (tờ khai bảo hiểm) for one salary period.
     *
     * Source: salary_details.meta (jsonb written by PayrollRunService / vn-payroll-v1):
     *   meta->insurance_employee = {bhxh, bhyt, bhtn, total, base:{bhxh_bhyt, bhtn}}
     *   meta->insurance_employer = {bhxh, bhyt, bhtn, total, base:{...}}
     * Insurance base = meta->insurance_employee->base->bhxh_bhyt.
     * Joined to employees for employee_code + full_name. Tenant-scoped on sd.tenant_id.
     * Returns { rows: [...per employee...], totals: {...} }.
     */
    private function bhxhDeclarationRows(array $filters): array
    {
        $periodId = (int) ($filters['period_id'] ?? 0);

        if (! Schema::hasTable('salary_details')) {
            return ['rows' => [], 'totals' => $this->emptyBhxhTotals()];
        }

        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        $records = DB::table('salary_details AS sd')
            ->leftJoin('employees AS e', 'e.id', '=', 'sd.employee_id')
            ->where('sd.period_id', $periodId)
            ->when($tenantId !== null, fn ($q) => $q->where('sd.tenant_id', $tenantId))
            ->orderBy('e.employee_code')
            ->get(['sd.employee_id', 'e.employee_code', 'e.full_name', 'sd.meta']);

        $rows = [];
        $totals = $this->emptyBhxhTotals();

        foreach ($records as $rec) {
            $meta = $this->decodeMeta($rec->meta);
            $emp = (array) ($meta['insurance_employee'] ?? []);
            $er = (array) ($meta['insurance_employer'] ?? []);
            $base = (float) (($emp['base']['bhxh_bhyt'] ?? null) ?? ($meta['base_salary'] ?? 0));

            $empBhxh = (float) ($emp['bhxh'] ?? 0);
            $empBhyt = (float) ($emp['bhyt'] ?? 0);
            $empBhtn = (float) ($emp['bhtn'] ?? 0);
            $empTotal = (float) (($emp['total'] ?? null) ?? ($empBhxh + $empBhyt + $empBhtn));

            $erBhxh = (float) ($er['bhxh'] ?? 0);
            $erBhyt = (float) ($er['bhyt'] ?? 0);
            $erBhtn = (float) ($er['bhtn'] ?? 0);
            $erTotal = (float) (($er['total'] ?? null) ?? ($erBhxh + $erBhyt + $erBhtn));

            $rows[] = [
                'employee_id' => $rec->employee_id !== null ? (int) $rec->employee_id : null,
                'employee_code' => $rec->employee_code,
                'full_name' => $rec->full_name,
                'insurance_base' => $base,
                'employee_bhxh' => $empBhxh,
                'employee_bhyt' => $empBhyt,
                'employee_bhtn' => $empBhtn,
                'employee_total' => $empTotal,
                'employer_bhxh' => $erBhxh,
                'employer_bhyt' => $erBhyt,
                'employer_bhtn' => $erBhtn,
                'employer_total' => $erTotal,
                'grand_total' => round($empTotal + $erTotal, 4),
            ];

            $totals['insurance_base'] += $base;
            $totals['employee_bhxh'] += $empBhxh;
            $totals['employee_bhyt'] += $empBhyt;
            $totals['employee_bhtn'] += $empBhtn;
            $totals['employee_total'] += $empTotal;
            $totals['employer_bhxh'] += $erBhxh;
            $totals['employer_bhyt'] += $erBhyt;
            $totals['employer_bhtn'] += $erBhtn;
            $totals['employer_total'] += $erTotal;
            $totals['grand_total'] += $empTotal + $erTotal;
            $totals['employees']++;
        }

        foreach ($totals as $k => $v) {
            $totals[$k] = $k === 'employees' ? (int) $v : round((float) $v, 4);
        }

        return ['period_id' => $periodId, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * PIT finalization (quyết toán thuế TNCN) for one monthly salary period.
     *
     * Source: salary_details.meta (vn-payroll-v1):
     *   meta->>'taxable_income', meta->pit->>'tax', meta->>'gross', meta->>'net'.
     * Falls back to sd.gross_salary / sd.net_salary columns when meta lacks them.
     * Joined to employees for code + name. Tenant-scoped on sd.tenant_id.
     * Returns { rows: [...per employee...], totals: {...} }.
     */
    private function pitFinalizationRows(array $filters): array
    {
        $periodId = (int) ($filters['period_id'] ?? 0);

        if (! Schema::hasTable('salary_details')) {
            return ['rows' => [], 'totals' => $this->emptyPitTotals()];
        }

        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        $records = DB::table('salary_details AS sd')
            ->leftJoin('employees AS e', 'e.id', '=', 'sd.employee_id')
            ->where('sd.period_id', $periodId)
            ->when($tenantId !== null, fn ($q) => $q->where('sd.tenant_id', $tenantId))
            ->orderBy('e.employee_code')
            ->get(['sd.employee_id', 'e.employee_code', 'e.full_name', 'sd.gross_salary', 'sd.net_salary', 'sd.meta']);

        $rows = [];
        $totals = $this->emptyPitTotals();

        foreach ($records as $rec) {
            $meta = $this->decodeMeta($rec->meta);

            $taxable = (float) ($meta['taxable_income'] ?? 0);
            $pit = (float) (($meta['pit']['tax'] ?? null) ?? 0);
            $gross = (float) (($meta['gross'] ?? null) ?? ($rec->gross_salary ?? 0));
            $net = (float) (($meta['net'] ?? null) ?? ($rec->net_salary ?? 0));
            $relief = (float) ($meta['personal_relief'] ?? 0);
            $dependents = (int) ($meta['dependents'] ?? 0);

            $rows[] = [
                'employee_id' => $rec->employee_id !== null ? (int) $rec->employee_id : null,
                'employee_code' => $rec->employee_code,
                'full_name' => $rec->full_name,
                'gross' => $gross,
                'personal_relief' => $relief,
                'dependents' => $dependents,
                'taxable_income' => $taxable,
                'pit' => $pit,
                'net' => $net,
            ];

            $totals['gross'] += $gross;
            $totals['taxable_income'] += $taxable;
            $totals['pit'] += $pit;
            $totals['net'] += $net;
            $totals['employees']++;
        }

        foreach ($totals as $k => $v) {
            $totals[$k] = $k === 'employees' ? (int) $v : round((float) $v, 4);
        }

        return ['period_id' => $periodId, 'rows' => $rows, 'totals' => $totals];
    }

    /** @return array<string, float|int> */
    private function emptyBhxhTotals(): array
    {
        return [
            'employees' => 0,
            'insurance_base' => 0.0,
            'employee_bhxh' => 0.0,
            'employee_bhyt' => 0.0,
            'employee_bhtn' => 0.0,
            'employee_total' => 0.0,
            'employer_bhxh' => 0.0,
            'employer_bhyt' => 0.0,
            'employer_bhtn' => 0.0,
            'employer_total' => 0.0,
            'grand_total' => 0.0,
        ];
    }

    /** @return array<string, float|int> */
    private function emptyPitTotals(): array
    {
        return [
            'employees' => 0,
            'gross' => 0.0,
            'taxable_income' => 0.0,
            'pit' => 0.0,
            'net' => 0.0,
        ];
    }

    private function decodeMeta(mixed $meta): array
    {
        if (empty($meta)) {
            return [];
        }
        $decoded = is_string($meta) ? json_decode($meta, true) : (array) $meta;

        return is_array($decoded) ? $decoded : [];
    }

    // ── Response Helpers ─────────────────────────────────────

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => ['errors' => $errors],
        ], 422);
    }
}
