<?php

namespace App\Services;

use App\Models\Attendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceReadService
{
    public function __construct(
        private readonly AttendanceChangePublisher $changes,
        private readonly AttendanceAccess $access,
    ) {}

    public function filteredQuery(Request $request, ?int $legalEntityId, bool $withRelations = false): Builder
    {
        $query = $this->access->scopeAttendances(Attendance::query(), $request);
        if ($legalEntityId !== null && $legalEntityId > 0) {
            $query->where('attendances.legal_entity_id', $legalEntityId);
        }
        if ($withRelations) {
            $query->with([
                'employee:id,full_name,employee_code,department_id,legal_entity_id',
                'shiftType:id,shift_code,shift_name,start_time,end_time',
                'payrollReview:id,attendance_id,default_percent,approved_percent,status',
            ]);
        }

        foreach (['employee_id', 'work_date', 'status', 'shift_type_id'] as $field) {
            if ($request->filled($field)) {
                $query->where("attendances.{$field}", $request->query($field));
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('attendances.work_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('attendances.work_date', '<=', $request->query('to'));
        }

        $year = (int) $request->query('year', 0);
        $month = (int) $request->query('month', 0);
        if ($year >= 2000 && $year <= 2100) {
            if ($month >= 1 && $month <= 12) {
                $start = sprintf('%04d-%02d-01', $year, $month);
                $query->whereBetween('attendances.work_date', [$start, date('Y-m-t', strtotime($start))]);
            } else {
                $query->whereBetween('attendances.work_date', ["{$year}-01-01", "{$year}-12-31"]);
            }
        }

        if ($request->filled('department_id')) {
            $query->whereHas('employee', function (Builder $employee) use ($request, $legalEntityId): void {
                $employee->where('department_id', (int) $request->query('department_id'));
                if ($legalEntityId !== null && $legalEntityId > 0) {
                    $employee->where('legal_entity_id', $legalEntityId);
                }
            });
        }
        if ($request->query('review') === 'needs_review') {
            $this->whereMetaValue($query, 'review_status', 'needs_review');
        }
        if ($request->filled('verification_status')) {
            $this->whereMetaValue($query, 'review_status', (string) $request->query('verification_status'));
        }
        if ($request->query('payroll_review') === 'unresolved') {
            $query->whereHas('payrollReview', fn (Builder $review) => $review->whereIn('status', ['PENDING', 'STALE']));
        } elseif ($request->filled('payroll_review')) {
            $query->whereHas('payrollReview', fn (Builder $review) => $review->where('status', strtoupper((string) $request->query('payroll_review'))));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function cursorPage(Request $request, ?int $legalEntityId): array
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $cursor = Cursor::fromEncoded($request->query('cursor'));
        $query = $this->filteredQuery($request, $legalEntityId)
            ->leftJoin('employees as attendance_employee', 'attendance_employee.id', '=', 'attendances.employee_id')
            ->leftJoin('shift_types as attendance_shift', 'attendance_shift.id', '=', 'attendances.shift_type_id')
            ->leftJoin('attendance_payroll_reviews as attendance_review', 'attendance_review.attendance_id', '=', 'attendances.id')
            ->select([
                'attendances.id as attendance_cursor_id', 'attendances.employee_id', 'attendances.legal_entity_id',
                'attendances.shift_type_id', 'attendances.work_date', 'attendances.check_in_time',
                'attendances.check_out_time', 'attendances.check_in_time_2', 'attendances.check_out_time_2',
                'attendances.status', 'attendances.updated_at',
                'attendance_employee.employee_code', 'attendance_employee.full_name',
                'attendance_employee.department_id',
                'attendance_shift.shift_code', 'attendance_shift.shift_name',
                'attendance_shift.start_time as shift_start_time', 'attendance_shift.end_time as shift_end_time',
                'attendance_review.id as payroll_review_id',
                'attendance_review.status as payroll_review_status',
                'attendance_review.default_percent as payroll_review_default_percent',
                'attendance_review.approved_percent as payroll_review_percent',
            ]);

        if (DB::getDriverName() === 'pgsql') {
            foreach (['regular_worked_minutes', 'early_arrival_minutes', 'late_minutes', 'early_leave_minutes', 'after_shift_minutes'] as $field) {
                $query->selectRaw("COALESCE((attendances.meta->>'{$field}')::int, 0) AS {$field}");
            }
            $query->selectRaw("attendances.meta->>'review_status' AS verification_status");
        } else {
            foreach (['regular_worked_minutes', 'early_arrival_minutes', 'late_minutes', 'early_leave_minutes', 'after_shift_minutes'] as $field) {
                $query->selectRaw("COALESCE(CAST(json_extract(attendances.meta, '$.{$field}') AS INTEGER), 0) AS {$field}");
            }
            $query->selectRaw("json_extract(attendances.meta, '$.review_status') AS verification_status");
        }

        if ($cursor) {
            $workDate = $cursor->parameter('work_date');
            $attendanceId = (int) $cursor->parameter('attendance_cursor_id');
            $pointsNext = $cursor->pointsToNextItems();
            $operator = $pointsNext ? '<' : '>';
            $query->where(function (Builder $boundary) use ($workDate, $attendanceId, $operator): void {
                $boundary->where('attendances.work_date', $operator, $workDate)
                    ->orWhere(function (Builder $sameDate) use ($workDate, $attendanceId, $operator): void {
                        $sameDate->where('attendances.work_date', $workDate)
                            ->where('attendances.id', $operator, $attendanceId);
                    });
            });
        }

        $descending = ! $cursor || $cursor->pointsToNextItems();
        $rows = $query
            ->orderBy('attendances.work_date', $descending ? 'desc' : 'asc')
            ->orderBy('attendances.id', $descending ? 'desc' : 'asc')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit);
        if (! $descending) {
            $items = $items->reverse()->values();
        }

        $first = $items->first();
        $last = $items->last();
        $hasPrevious = $cursor !== null && ($cursor->pointsToNextItems() || $hasMore);
        $hasNext = $cursor === null ? $hasMore : ($cursor->pointsToNextItems() ? $hasMore : true);

        return [
            'items' => $items->map(fn ($row) => $this->mapLightRow($row))->all(),
            'next_cursor' => $hasNext && $last ? $this->attendanceCursor($last, true) : null,
            'prev_cursor' => $hasPrevious && $first ? $this->attendanceCursor($first, false) : null,
            'has_more' => $hasNext,
            'limit' => $limit,
        ];
    }

    /** @return array<string, int|string|null> */
    public function overview(Request $request, int $tenantId, ?int $legalEntityId): array
    {
        $filters = collect($request->only([
            'from', 'to', 'year', 'month', 'employee_id', 'status', 'shift_type_id',
            'department_id', 'review', 'verification_status', 'payroll_review',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->sortKeys()->all();
        $version = $this->changes->versionToken($tenantId, $legalEntityId);
        $key = 'attendance:overview:t'.$tenantId.':e'.($legalEntityId ?: 'all').':'.$version.':'.hash('sha256', json_encode($filters));

        $cache = null;
        try {
            $cache = $this->changes->cache();
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $exception) {
            Log::debug('Attendance overview cache read skipped', ['error' => $exception->getMessage()]);
        }

        $overview = $this->buildOverview($request, $legalEntityId);
        if ($cache) {
            try {
                $cache->put($key, $overview, now()->addSeconds(30));
            } catch (\Throwable $exception) {
                Log::debug('Attendance overview cache write skipped', ['error' => $exception->getMessage()]);
            }
        }

        return $overview;
    }

    /** @return array<string, int|string|null> */
    private function buildOverview(Request $request, ?int $legalEntityId): array
    {
        $query = $this->filteredQuery($request, $legalEntityId);
        $lateMetric = $this->integerMetaExpression('late_minutes');
        $earlyLeaveMetric = $this->integerMetaExpression('early_leave_minutes');
        $reviewMetric = $this->textMetaExpression('review_status');
        $row = (clone $query)->withoutEagerLoads()->reorder()
            ->leftJoin('attendance_payroll_reviews as overview_review', function ($join): void {
                $join->on('overview_review.attendance_id', '=', 'attendances.id')
                    ->on('overview_review.tenant_id', '=', 'attendances.tenant_id');
            })
            ->selectRaw(<<<SQL
            COUNT(*) AS total,
            SUM(CASE WHEN attendances.status IN ('ON_TIME', 'PRESENT', 'CHECKED_IN', 'CHECKED_OUT', 'ĐÃ_DUYỆT', 'ĐÃ DUYỆT') THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN attendances.status IN ('ABSENT', 'VẮNG', 'NGHỈ') THEN 1 ELSE 0 END) AS absent,
            SUM(CASE WHEN attendances.status IN ('LATE', 'ĐI_MUỘN', 'ĐI MUỘN', 'MUỘN') OR {$lateMetric} > 0 THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN attendances.status IN ('EARLY_LEAVE', 'VỀ_SỚM', 'VỀ SỚM') OR {$earlyLeaveMetric} > 0 THEN 1 ELSE 0 END) AS early_leave,
            SUM(CASE WHEN attendances.status = 'HALF_DAY' THEN 1 ELSE 0 END) AS half_day,
            SUM(CASE WHEN {$reviewMetric} = 'needs_review' THEN 1 ELSE 0 END) AS needs_review,
            SUM(CASE WHEN overview_review.status IN ('PENDING', 'STALE') THEN 1 ELSE 0 END) AS payroll_review_pending
        SQL)->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'present' => (int) ($row?->present ?? 0),
            'absent' => (int) ($row?->absent ?? 0),
            'late' => (int) ($row?->late ?? 0),
            'early_leave' => (int) ($row?->early_leave ?? 0),
            'half_day' => (int) ($row?->half_day ?? 0),
            'needs_review' => (int) ($row?->needs_review ?? 0),
            'payroll_review_pending' => (int) ($row?->payroll_review_pending ?? 0),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function mapLightRow(object $row): array
    {
        return [
            'id' => (int) $row->attendance_cursor_id,
            'employee_id' => (int) $row->employee_id,
            'employee_code' => $row->employee_code,
            'full_name' => $row->full_name,
            'department_id' => $row->department_id ? (int) $row->department_id : null,
            'legal_entity_id' => $row->legal_entity_id ? (int) $row->legal_entity_id : null,
            'work_date' => $row->work_date instanceof \DateTimeInterface ? $row->work_date->format('Y-m-d') : substr((string) $row->work_date, 0, 10),
            'check_in_time' => $row->check_in_time,
            'check_out_time' => $row->check_out_time,
            'check_in_time_2' => $row->check_in_time_2,
            'check_out_time_2' => $row->check_out_time_2,
            'status' => $row->status,
            'regular_worked_minutes' => (int) $row->regular_worked_minutes,
            'early_arrival_minutes' => (int) $row->early_arrival_minutes,
            'late_minutes' => (int) $row->late_minutes,
            'early_leave_minutes' => (int) $row->early_leave_minutes,
            'after_shift_minutes' => (int) $row->after_shift_minutes,
            'shift_type_id' => $row->shift_type_id ? (int) $row->shift_type_id : null,
            'shift_code' => $row->shift_code,
            'shift_name' => $row->shift_name,
            'shift_start' => $row->shift_start_time,
            'shift_end' => $row->shift_end_time,
            'verification_status' => $row->verification_status,
            'review_status' => $row->verification_status,
            'payroll_review_status' => $row->payroll_review_status,
            'payroll_review' => $row->payroll_review_id ? [
                'id' => (int) $row->payroll_review_id,
                'status' => $row->payroll_review_status,
                'default_percent' => $row->payroll_review_default_percent !== null
                    ? (int) $row->payroll_review_default_percent
                    : null,
                'approved_percent' => $row->payroll_review_percent !== null
                    ? (int) $row->payroll_review_percent
                    : null,
            ] : null,
            'payroll_review_percent' => $row->payroll_review_percent !== null ? (int) $row->payroll_review_percent : null,
            'updated_at' => $row->updated_at instanceof \DateTimeInterface ? $row->updated_at->format(DATE_ATOM) : (string) $row->updated_at,
        ];
    }

    private function whereMetaValue(Builder $query, string $key, string $value): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            return $query->whereRaw("attendances.meta->>'{$key}' = ?", [$value]);
        }

        return $query->whereRaw('json_extract(attendances.meta, ?) = ?', ['$.'.$key, $value]);
    }

    private function integerMetaExpression(string $key): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "COALESCE((attendances.meta->>'{$key}')::int, 0)";
        }

        return "COALESCE(CAST(json_extract(attendances.meta, '$.{$key}') AS INTEGER), 0)";
    }

    private function textMetaExpression(string $key): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "attendances.meta->>'{$key}'";
        }

        return "json_extract(attendances.meta, '$.{$key}')";
    }

    private function attendanceCursor(object $row, bool $next): string
    {
        $workDate = $row->work_date instanceof \DateTimeInterface
            ? $row->work_date->format('Y-m-d')
            : substr((string) $row->work_date, 0, 10);

        return (new Cursor([
            'work_date' => $workDate,
            'attendance_cursor_id' => (int) $row->attendance_cursor_id,
        ], $next))->encode();
    }
}
