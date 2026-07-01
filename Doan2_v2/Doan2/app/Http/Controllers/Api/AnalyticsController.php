<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->select('status', DB::raw('COUNT(*) AS count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $employeesByDepartment = DB::table('employees AS e')
            ->leftJoin('departments AS d', 'd.id', '=', 'e.department_id')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
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
                    DB::raw($attendanceDateColumn . '::date AS d'),
                    'status',
                    DB::raw('COUNT(*) AS count')
                )
                ->whereBetween($attendanceDateColumn, [$from->toDateString(), today()->toDateString()])
                ->groupBy(DB::raw($attendanceDateColumn . '::date'), 'status')
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
                ->select(DB::raw('work_date::date AS d'), DB::raw('SUM(total_hours) AS hours'))
                ->groupBy(DB::raw('work_date::date'))
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
                    ->select($stageColumn . ' AS stage', DB::raw('COUNT(*) AS count'))
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

        return $this->ok([
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
        ], 'Dashboard statistics');
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
            ->where('e.status', 'ACTIVE');

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
    private function nextOccurrence(\Illuminate\Support\Carbon $today, int $month, int $day): ?\Illuminate\Support\Carbon
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
            $candidate = \Illuminate\Support\Carbon::create($year, $month, $d)->startOfDay();
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

        $supported = ['headcount', 'leave-summary', 'payroll-summary', 'attendance-summary', 'bhxh-declaration', 'pit-finalization'];
        if (! is_string($type) || ! in_array($type, $supported, true)) {
            return $this->validationError([
                'type' => ['Unknown report type. Supported: ' . implode(', ', $supported)],
            ]);
        }

        // Period-scoped VN compliance reports require a period_id filter.
        if (in_array($type, ['bhxh-declaration', 'pit-finalization'], true)) {
            $periodId = $filters['period_id'] ?? null;
            if ($periodId === null || $periodId === '' || ! is_numeric($periodId)) {
                return $this->validationError([
                    'filters.period_id' => ['period_id is required for ' . $type . '.'],
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

    private function headcountRows(): \Illuminate\Support\Collection
    {
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        return DB::table('employees AS e')
            ->leftJoin('departments AS d', 'd.id', '=', 'e.department_id')
            ->when($tenantId !== null, fn ($q) => $q->where('e.tenant_id', $tenantId))
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

    private function leaveSummaryRows(): \Illuminate\Support\Collection
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

    private function payrollSummaryRows(): \Illuminate\Support\Collection
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

    private function attendanceSummaryRows(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('attendances')) {
            return collect();
        }

        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        return DB::table('attendances')
            ->when($tenantId !== null, fn ($q) => $q->where('attendances.tenant_id', $tenantId))
            ->select('status', DB::raw('COUNT(*) AS count'))
            ->groupBy('status')
            ->get()
            ->map(static fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
            ]);
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
