<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileOvertimeDay;
use App\Jobs\RunAttendanceRecomputeOperation;
use App\Jobs\RunAttendanceSummaryOperation;
use App\Models\Attendance;
use App\Models\AttendanceOperation;
use App\Models\OvertimeRequest;
use App\Services\AttendanceAccess;
use App\Services\AttendanceChangePublisher;
use App\Services\AttendanceDayLock;
use App\Services\AttendanceReadService;
use App\Services\AttendanceReconciliationService;
use App\Services\ShiftResolver;
use App\Services\TimesheetService;
use App\Support\ApprovalFlow;
use App\Support\AttendanceVerification;
use App\Support\HrmConfig;
use App\Support\Notifier;
use App\Support\OvertimeSuggester;
use App\Support\TenantContext;
use App\Support\TimePolicy;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly ShiftResolver $shiftResolver,
        private readonly AttendanceReconciliationService $attendanceReconciliation,
        private readonly AttendanceReadService $attendanceRead,
        private readonly AttendanceAccess $attendanceAccess,
        private readonly AttendanceDayLock $attendanceDayLock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $legalEntityId = $this->attendanceAccess->requestedLegalEntity($request);
        if ($request->query('pagination') === 'cursor') {
            return $this->ok($this->attendanceRead->cursorPage($request, $legalEntityId), 'Attendances list');
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $query = $this->attendanceRead->filteredQuery($request, $legalEntityId, true)
            ->orderByDesc('work_date')
            ->orderByDesc('id');

        $page = $query->paginate($perPage);

        return $this->ok([
            'items' => collect($page->items())->map(fn (Attendance $attendance) => $this->decorateAttendance($attendance))->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Attendances list');
    }

    public function overview(Request $request): JsonResponse
    {
        return $this->ok(
            $this->attendanceRead->overview($request, (int) TenantContext::id(), $this->attendanceAccess->requestedLegalEntity($request)),
            'Attendance overview'
        );
    }

    public function changes(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 250);
        $since = AttendanceChangePublisher::decodeCursor($request->query('since'));
        $scope = fn () => $this->attendanceAccess->scopeChangeEvents(
            DB::table('attendance_change_events'),
            $request,
        );
        $changeRange = $scope()
            ->selectRaw('MIN(id) AS min_id, MAX(id) AS max_id')
            ->first();
        $oldestAvailableId = (int) ($changeRange?->min_id ?? 0);
        $latestAvailableId = (int) ($changeRange?->max_id ?? 0);

        if (! $request->filled('since')) {
            return $this->ok([
                'items' => [],
                'next_cursor' => AttendanceChangePublisher::encodeCursor($latestAvailableId),
                'has_more' => false,
                'reset_required' => false,
            ], 'Attendance changes baseline');
        }

        // The change journal is retained for seven days. A cursor older than
        // the first remaining event cannot be replayed safely, so callers must
        // refresh their current view and continue from the latest watermark.
        if ($oldestAvailableId > 0 && $since < ($oldestAvailableId - 1)) {
            return $this->ok([
                'items' => [],
                'next_cursor' => AttendanceChangePublisher::encodeCursor($latestAvailableId),
                'has_more' => false,
                'reset_required' => true,
            ], 'Attendance changes cursor expired');
        }

        $query = $scope()
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit + 1);

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $changeVersion = app(AttendanceChangePublisher::class)->versionToken(
            (int) TenantContext::id(),
            $this->attendanceAccess->isAdmin($request)
                ? null
                : (int) TenantContext::legalEntityId(),
        );
        $items = $rows->take($limit)->map(fn ($row) => [
            'cursor' => AttendanceChangePublisher::encodeCursor((int) $row->id),
            'attendance_id' => $row->attendance_id ? (int) $row->attendance_id : null,
            'employee_id' => $row->employee_id ? (int) $row->employee_id : null,
            'legal_entity_id' => $row->legal_entity_id ? (int) $row->legal_entity_id : null,
            'department_id' => $row->department_id ? (int) $row->department_id : null,
            'work_date' => $row->work_date,
            'change_type' => $row->change_type,
            'version' => $changeVersion,
            'updated_at' => $row->created_at,
        ])->values();
        $lastItemId = $items->isNotEmpty()
            ? AttendanceChangePublisher::decodeCursor((string) $items->last()['cursor'])
            : $since;
        $nextId = $hasMore ? $lastItemId : max($lastItemId, $latestAvailableId);

        return $this->ok([
            'items' => $items,
            'next_cursor' => AttendanceChangePublisher::encodeCursor($nextId),
            'has_more' => $hasMore,
            'reset_required' => false,
        ], 'Attendance changes');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $detailQuery = $this->attendanceAccess->scopeAttendances(Attendance::query(), $request)->with([
            'employee:id,full_name,employee_code',
            'shiftType:id,shift_code,shift_name,start_time,end_time,meta',
            'payrollReview',
        ]);
        $attendance = $detailQuery->find($id);

        if (! $attendance) {
            return $this->notFound();
        }

        return $this->ok($this->decorateAttendance($attendance), 'Attendance detail');
    }

    /**
     * POST /attendances/check-in
     */
    public function checkIn(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'nullable|integer|exists:employees,id',
        ], [
            'employee_id.exists' => 'Nhân viên không tồn tại',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = $this->attendanceAccess->actorId($request);
        if ($request->filled('employee_id') && (int) $request->input('employee_id') !== $employeeId) {
            return response()->json([
                'status' => 403,
                'message' => 'Bạn chỉ được check-in cho chính mình.',
                'data' => null,
            ], 403);
        }

        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return response()->json(['status' => 403, 'message' => 'Tài khoản không thuộc công ty hiện tại.', 'data' => null], 403);
        }

        // Tài khoản hệ thống (vd System Administrator) không phải nhân sự thật → không chấm công.
        if ($this->isSystemAccount($employeeId)) {
            return $this->validationError(['employee_id' => ['Tài khoản hệ thống không thể chấm công. Vui lòng dùng tài khoản nhân viên.']]);
        }

        $today = now()->toDateString();

        // Determine current shift and calculate late minutes
        $shiftAssignment = $this->shiftResolver->resolve((int) $employeeId, $today, TenantContext::id());

        $shiftTypeId = $shiftAssignment->shift_type_id ?? null;
        $shift = null;
        if ($shiftTypeId) {
            $shift = DB::table('shift_types')->where('id', $shiftTypeId)
                ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_types.tenant_id', TenantContext::id()))
                ->first();
        }

        // Phân loại theo ca + dung sai đi trễ (attendance.late_grace_minutes).
        // Khi check-in chưa có giờ ra → chỉ ra ON_TIME / LATE.
        $cls = TimePolicy::classifyAttendance($shift, now()->toTimeString(), null);

        // Xác minh chống gian lận (IP / vị trí / thiết bị / WFH).
        $verify = AttendanceVerification::evaluate(
            $request->ip(),
            $request->input('latitude'),
            $request->input('longitude'),
            $shift,
            $request->userAgent(),
            $request->input('source'),
            $request->input('work_mode') // office | wfh | on_site
        );
        $verify['accuracy'] = is_numeric($request->input('accuracy')) ? (float) $request->input('accuracy') : null;
        $verify['at'] = now()->toIso8601String();
        if ($request->filled('site_name')) {
            $verify['site_name'] = (string) $request->input('site_name'); // tên công trình (on-site)
        }

        // Chế độ 'block': từ chối nếu ngoài phạm vi cho phép.
        if ($verify['mode'] === 'block' && ! $verify['within_policy']) {
            return $this->validationError([
                'location' => ['Chấm công ngoài phạm vi cho phép (IP/vị trí không hợp lệ). Liên hệ quản trị nếu bạn làm từ xa.'],
            ]);
        }

        $reviewStatus = $verify['within_policy'] ? 'ok' : 'needs_review';

        // `attendances` has no late_minutes column — persist it in meta, which is
        // where the timesheet engine / payroll summary read it from.
        $result = $this->attendanceDayLock->run(
            (int) TenantContext::id(),
            $employeeId,
            $today,
            function () use ($employeeId, $today, $shiftTypeId, $cls, $verify, $reviewStatus): array {
                $attendance = Attendance::where('employee_id', $employeeId)
                    ->where('work_date', $today)
                    ->lockForUpdate()
                    ->first();
                $message = 'Check-in thành công';
                $status = 201;

                if ($attendance) {
                    if ($this->attendanceReconciliation->isClosedDate($attendance)) {
                        return ['error' => response()->json([
                            'status' => 409,
                            'message' => 'Kỳ lương chứa ngày chấm công này đã chốt, không thể sửa trực tiếp.',
                            'data' => null,
                        ], 409)];
                    }
                    if ($attendance->check_in_time && ! $attendance->check_out_time) {
                        return ['error' => $this->validationError([
                            'employee_id' => ['Bạn phải checkout phiên hiện tại trước khi check-in lần tiếp theo.'],
                        ])];
                    }
                    if ($attendance->check_in_time_2 && ! $attendance->check_out_time_2) {
                        return ['error' => $this->validationError([
                            'employee_id' => ['Phiên làm việc thứ hai đang mở, không thể check-in thêm.'],
                        ])];
                    }
                    if ($attendance->check_in_time_2 && $attendance->check_out_time_2) {
                        return ['error' => $this->validationError([
                            'employee_id' => ['Bạn đã hoàn tất đủ hai phiên làm việc hôm nay.'],
                        ])];
                    }
                    if (! $attendance->check_in_time) {
                        $meta = is_string($attendance->meta)
                            ? (json_decode($attendance->meta, true) ?: [])
                            : (array) ($attendance->meta ?? []);
                        $meta['verification'] = $verify;
                        $meta['review_status'] = $reviewStatus;
                        $attendance->update([
                            'check_in_time' => now()->toTimeString(),
                            'shift_type_id' => $attendance->shift_type_id ?: $shiftTypeId,
                            'meta' => $meta,
                        ]);
                    } else {
                        $attendance->update(['check_in_time_2' => now()->toTimeString()]);
                        $message = 'Check-in phiên 2 thành công';
                    }
                    $status = 200;
                } else {
                    $attendance = Attendance::create([
                        'employee_id' => $employeeId,
                        'legal_entity_id' => DB::table('employees')->where('id', $employeeId)->value('legal_entity_id'),
                        'work_date' => $today,
                        'check_in_time' => now()->toTimeString(),
                        'shift_type_id' => $shiftTypeId,
                        'status' => $cls['status'],
                        'meta' => [
                            'late_minutes' => $cls['late_minutes'],
                            'verification' => $verify,
                            'review_status' => $reviewStatus,
                        ],
                    ]);
                }

                $this->attendanceReconciliation->reconcile($attendance->fresh(), $employeeId);

                return [
                    'attendance' => $attendance->fresh(['employee:id,full_name,employee_code', 'shiftType', 'payrollReview']),
                    'message' => $message,
                    'status' => $status,
                ];
            },
        );
        if (isset($result['error'])) {
            return $result['error'];
        }
        $fresh = $result['attendance'];

        return response()->json([
            'status' => $result['status'],
            'message' => $reviewStatus === 'needs_review' && $result['status'] === 201
                ? 'Đã ghi nhận chấm công — cần quản trị xem xét (ngoài phạm vi).'
                : $result['message'],
            'data' => $this->decorateAttendance($fresh),
        ], $result['status']);
    }

    /**
     * POST /attendances/check-out
     */
    public function checkOut(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'nullable|integer|exists:employees,id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = $this->attendanceAccess->actorId($request);
        if ($request->filled('employee_id') && (int) $request->input('employee_id') !== $employeeId) {
            return response()->json([
                'status' => 403,
                'message' => 'Bạn chỉ được check-out cho chính mình.',
                'data' => null,
            ], 403);
        }

        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $today = now()->toDateString();

        $attendance = $this->attendanceForCheckout($employeeId, $today);

        if (! $attendance) {
            return $this->validationError([
                'employee_id' => ['Nhân viên chưa check-in hôm nay'],
            ]);
        }

        $workDate = $attendance->work_date->toDateString();
        $result = $this->attendanceDayLock->run(
            (int) TenantContext::id(),
            $employeeId,
            $workDate,
            function () use ($request, $employeeId, $workDate): array {
                $attendance = Attendance::where('employee_id', $employeeId)
                    ->where('work_date', $workDate)
                    ->lockForUpdate()
                    ->first();
                if (! $attendance) {
                    return ['error' => $this->validationError(['employee_id' => ['Không còn phiên check-in đang mở.']])];
                }
                if ($this->attendanceReconciliation->isClosedDate($attendance)) {
                    return ['error' => response()->json([
                        'status' => 409,
                        'message' => 'Kỳ lương chứa ngày chấm công này đã chốt, không thể sửa trực tiếp.',
                        'data' => null,
                    ], 409)];
                }

                $shift = $attendance->shift_type_id
                    ? DB::table('shift_types')->where('tenant_id', TenantContext::id())->where('id', $attendance->shift_type_id)->first()
                    : null;
                $meta = is_string($attendance->meta)
                    ? (json_decode($attendance->meta, true) ?: [])
                    : (array) ($attendance->meta ?? []);
                if ($request->filled('latitude') || $request->filled('longitude') || $request->ip()) {
                    $verification = AttendanceVerification::evaluate(
                        $request->ip(),
                        $request->input('latitude'),
                        $request->input('longitude'),
                        $shift,
                        $request->userAgent(),
                        $request->input('source'),
                    );
                    $verification['at'] = now()->toIso8601String();
                    $meta['verification_out'] = $verification;
                }

                $checkOutTime = now()->toTimeString();
                if ($attendance->check_in_time_2 && ! $attendance->check_out_time_2) {
                    $attendance->update([
                        'check_out_time_2' => $checkOutTime,
                        'meta' => $meta,
                    ]);
                } elseif ($attendance->check_in_time && ! $attendance->check_out_time) {
                    $attendance->update([
                        'check_out_time' => $checkOutTime,
                        'meta' => $meta,
                    ]);
                } else {
                    return ['error' => $this->validationError(['employee_id' => ['Không có phiên check-in nào đang mở để checkout.']])];
                }

                $this->attendanceReconciliation->reconcile($attendance->fresh(), $employeeId);

                return [
                    'attendance' => $attendance->fresh(['employee:id,full_name,employee_code', 'shiftType', 'payrollReview']),
                    'shift' => $shift,
                    'check_out_time' => $checkOutTime,
                ];
            },
        );
        if (isset($result['error'])) {
            return $result['error'];
        }
        $attendance = $result['attendance'];
        $shift = $result['shift'];
        $checkOutTime = $result['check_out_time'];

        // Đi làm ngày nghỉ/lễ → tự đề xuất đơn tăng ca chờ duyệt.
        $otSuggested = OvertimeSuggester::suggest([
            'employee_id' => (int) $employeeId,
            'tenant_id' => TenantContext::id(),
            'work_date' => $today,
            'check_in' => (string) $attendance->check_in_time,
            'check_out' => $checkOutTime,
            'shift' => $shift,
        ]);

        return $this->ok($this->decorateAttendance($attendance), $otSuggested
            ? 'Check-out thành công. Đã tạo đơn tăng ca (làm ngày nghỉ/lễ) chờ duyệt.'
            : 'Check-out thành công');
    }

    /**
     * PATCH /attendances/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->attendanceAccess->canModifyAttendance($request)) {
            return response()->json([
                'status' => 403,
                'message' => 'Chỉ HR hoặc Admin được sửa bản ghi chấm công.',
                'data' => null,
            ], 403);
        }

        $attendance = $this->attendanceAccess
            ->scopeAttendances(Attendance::query(), $request)
            ->find($id);

        if (! $attendance) {
            return $this->notFound();
        }

        if ($this->attendanceReconciliation->isClosedDate($attendance)) {
            return response()->json([
                'status' => 409,
                'message' => 'Kỳ lương chứa ngày chấm công này đã chốt, không thể sửa trực tiếp.',
                'data' => null,
            ], 409);
        }

        $allowed = [
            'work_date', 'check_in_time', 'check_out_time',
            'check_in_time_2', 'check_out_time_2', 'shift_type_id', 'notes',
        ];
        $unexpected = array_values(array_diff(array_keys($request->all()), $allowed));
        if ($unexpected !== []) {
            return $this->validationError([
                'fields' => ['Không được sửa các trường: '.implode(', ', $unexpected).'.'],
            ]);
        }

        $timeRule = ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/'];
        $validator = Validator::make($request->all(), [
            'work_date' => 'sometimes|date',
            'check_in_time' => $timeRule,
            'check_out_time' => $timeRule,
            'check_in_time_2' => $timeRule,
            'check_out_time_2' => $timeRule,
            'shift_type_id' => 'nullable|integer|exists:shift_types,id',
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        if ($request->filled('shift_type_id') && ! DB::table('shift_types')
            ->where('tenant_id', TenantContext::id())
            ->where('id', (int) $request->input('shift_type_id'))
            ->exists()) {
            return $this->validationError(['shift_type_id' => ['Ca làm không thuộc công ty hiện tại.']]);
        }

        $targetDate = $request->filled('work_date')
            ? Carbon::parse($request->input('work_date'))->toDateString()
            : $attendance->work_date->toDateString();
        if ($targetDate !== $attendance->work_date->toDateString()
            && $this->attendanceReconciliation->isClosedWorkDate(
                (int) $attendance->tenant_id,
                (int) $attendance->legal_entity_id,
                $targetDate,
            )) {
            return response()->json([
                'status' => 409,
                'message' => 'Ngày công mới thuộc kỳ lương đã chốt, không thể chuyển bản ghi.',
                'data' => null,
            ], 409);
        }

        try {
            DB::transaction(function () use ($request, $attendance): void {
                $locked = Attendance::whereKey($attendance->id)->lockForUpdate()->firstOrFail();
                $payload = $request->only([
                    'work_date', 'check_in_time', 'check_out_time',
                    'check_in_time_2', 'check_out_time_2', 'shift_type_id',
                ]);
                if ($request->has('notes')) {
                    $meta = is_string($locked->meta)
                        ? (json_decode($locked->meta, true) ?: [])
                        : (array) ($locked->meta ?? []);
                    $meta['notes'] = $request->input('notes');
                    $payload['meta'] = $meta;
                }
                $locked->update($payload);
                $this->attendanceReconciliation->reconcile(
                    $locked->fresh(),
                    $this->attendanceAccess->actorId($request),
                );
            });
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                return response()->json(['status' => 409, 'message' => $e->getMessage(), 'data' => null], 409);
            }
            throw $e;
        }

        $fresh = $attendance->fresh(['employee:id,full_name,employee_code', 'shiftType', 'payrollReview']);

        return $this->ok($this->decorateAttendance($fresh), 'Cập nhật thành công');
    }

    // ═══════════════════════════════════════════════════════
    // OVERTIME REQUESTS
    // ═══════════════════════════════════════════════════════

    public function overtimeIndex(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = $this->attendanceAccess->scopeEmployeeResource(
            OvertimeRequest::query(),
            $request,
            'overtime_requests',
        )->with(['employee:id,full_name,employee_code,department_id,legal_entity_id'])
            ->orderByDesc('id');

        foreach (['employee_id', 'status', 'work_date'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        if ($request->filled('kind')) {
            $kind = strtoupper((string) $request->query('kind'));
            if (DB::getDriverName() === 'pgsql') {
                $kind === 'EMPLOYEE_REQUEST'
                    ? $query->whereRaw("(overtime_requests.meta->>'kind' = ? OR overtime_requests.meta->>'kind' IS NULL)", [$kind])
                    : $query->whereRaw("overtime_requests.meta->>'kind' = ?", [$kind]);
            } else {
                $kind === 'EMPLOYEE_REQUEST'
                    ? $query->whereRaw("(json_extract(overtime_requests.meta, '$.kind') = ? OR json_extract(overtime_requests.meta, '$.kind') IS NULL)", [$kind])
                    : $query->whereRaw("json_extract(overtime_requests.meta, '$.kind') = ?", [$kind]);
            }
        }

        $summaryQuery = clone $query;
        $summary = [
            'total' => (clone $summaryQuery)->withoutEagerLoads()->reorder()->count(),
            'pending' => (clone $summaryQuery)->withoutEagerLoads()->reorder()
                ->whereIn('status', ['PENDING', 'CHỜ_DUYỆT', 'OFFERED'])->count(),
            'approved' => (clone $summaryQuery)->withoutEagerLoads()->reorder()
                ->whereIn('status', ['APPROVED', 'ĐÃ_DUYỆT'])->count(),
            'rejected' => (clone $summaryQuery)->withoutEagerLoads()->reorder()
                ->whereIn('status', ['REJECTED', 'DECLINED', 'CANCELLED'])->count(),
            'payable_minutes' => $this->sumOvertimeMeta($summaryQuery, 'payable_overtime_minutes'),
        ];
        $page = $query->paginate($perPage);

        return $this->ok([
            'items' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'summary' => $summary,
        ], 'Overtime requests list');
    }

    public function storeOvertime(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'work_date' => 'required|date',
            'total_hours' => 'nullable|numeric|min:0.25',
            'start_time' => ['required', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/'],
            'end_time' => ['required', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/'],
            'reason' => 'nullable|string',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'work_date.required' => 'Ngày tăng ca là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = (int) $request->input('employee_id');
        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }
        $actorId = (int) $request->attributes->get('auth_employee_id');
        if ($employeeId !== $actorId) {
            return response()->json([
                'status' => 403,
                'message' => 'Nhân viên chỉ được gửi đơn tăng ca của chính mình. Quản lý hãy dùng ticket tăng ca.',
                'data' => null,
            ], 403);
        }

        $start = $request->input('start_time');
        $end = $request->input('end_time');
        // Phân loại theo luật (loại ngày, giờ đêm, hệ số) + tính tổng giờ.
        $cls = TimePolicy::classifyOvertime($request->input('work_date'), $start, $end, $request->input('total_hours'));
        if ($cls['total_hours'] * 60 < 15) {
            return $this->validationError(['total_hours' => ['Khung tăng ca phải tối thiểu 15 phút.']]);
        }

        // Chặn vượt giới hạn OT theo luật (ngày/tháng/năm).
        $caps = TimePolicy::overtimeCaps($employeeId, $request->input('work_date'), $cls['total_hours'], null, false, (int) TenantContext::id());
        if (! empty($caps['violations'])) {
            return $this->validationError(['total_hours' => $caps['violations']]);
        }

        $ot = OvertimeRequest::create([
            'employee_id' => $employeeId,
            'work_date' => $request->input('work_date'),
            'start_time' => $start,
            'end_time' => $end,
            'total_hours' => $cls['total_hours'],
            'status' => 'PENDING',
            'meta' => [
                'day_type' => $cls['day_type'],
                'multiplier' => $cls['multiplier'],
                'night_hours' => $cls['night_hours'],
                'pay_factor' => $cls['pay_factor'],
                'label' => $cls['label'],
                'reason' => $request->input('reason'),
                'kind' => 'EMPLOYEE_REQUEST',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 201,
            'message' => 'Đơn tăng ca đã được tạo ('.$cls['label'].')',
            'data' => $ot->fresh()->load('employee:id,full_name'),
        ], 201);
    }

    public function approveOvertime(
        Request $request,
        int $id,
    ): JsonResponse {
        if (! $this->attendanceAccess->canReadOrganization($request)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền duyệt đơn tăng ca.', 'data' => null], 403);
        }
        $ot = $this->attendanceAccess->scopeEmployeeResource(
            OvertimeRequest::query(),
            $request,
            'overtime_requests',
        )->find($id);

        if (! $ot) {
            return $this->notFound();
        }

        if (! in_array($ot->status, ['PENDING', 'CHỜ_DUYỆT'])) {
            return $this->validationError(['status' => ['Đơn không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');
        if ($approverId !== null && (int) $ot->employee_id === (int) $approverId) {
            return $this->validationError(['approver_id' => ['Người tạo đơn không thể tự duyệt']]);
        }

        // Khi duyệt: bảo đảm tổng OT đã DUYỆT không vượt giới hạn luật.
        $caps = TimePolicy::overtimeCaps((int) $ot->employee_id, $ot->work_date, (float) $ot->total_hours, $ot->id, true, (int) $ot->tenant_id);
        if (! empty($caps['violations'])) {
            return $this->validationError(['status' => array_map(fn ($v) => 'Không thể duyệt: '.$v, $caps['violations'])]);
        }

        // Duyệt nhiều cấp (approval.overtime_chain). Chỉ chốt khi qua hết các cấp.
        $meta = is_string($ot->meta) ? (json_decode($ot->meta, true) ?: []) : (array) ($ot->meta ?? []);
        $meta = ApprovalFlow::ensure($meta, 'overtime');
        if ($err = ApprovalFlow::cannotApprove($meta, $approverId)) {
            return $this->validationError(['approver_id' => [$err]]);
        }
        [$meta, $final] = ApprovalFlow::approveStep($meta, $approverId, $request->input('comment'));
        $progress = ApprovalFlow::progress($meta);

        if (! $final) {
            $ot->update(['meta' => $meta]);
            $next = ApprovalFlow::currentRole($meta);

            return $this->ok($ot->fresh(), "Đã duyệt cấp {$progress['done']}/{$progress['total']}. Chờ cấp tiếp theo".($next ? " ({$next})" : '').'.');
        }

        // Tuỳ chọn: quy đổi OT này thành NGHỈ BÙ thay vì trả tiền (comp-off).
        $compOff = filter_var($request->input('comp_off'), FILTER_VALIDATE_BOOLEAN);
        $message = 'Đơn tăng ca đã được duyệt';

        DB::transaction(function () use ($ot, $compOff, $meta, &$message) {
            // Chống double-credit: khoá hàng + xác nhận lại PENDING trong transaction.
            // 2 duyệt song song với comp_off=true nếu không khoá sẽ cộng nghỉ bù 2 lần.
            $locked = DB::table('overtime_requests')->where('id', $ot->id)->lockForUpdate()->first();
            if (! $locked || ! in_array((string) $locked->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
                return;
            }
            if ($compOff && (bool) HrmConfig::get('overtime.comp_off_enabled', true)) {
                $meta['converted_to_comp_off'] = true;
                $meta['comp_off_pending_reconciliation'] = true;
                $ot->update(['status' => 'APPROVED', 'meta' => $meta]);
                $message = 'Đã duyệt OT; nghỉ bù sẽ được cấp theo phút chấm công thực tế đã đối soát.';
            } else {
                $ot->update(['status' => 'APPROVED', 'meta' => $meta]);
            }
        });

        ReconcileOvertimeDay::dispatch(
            (int) $ot->tenant_id,
            (int) $ot->employee_id,
            $ot->work_date->toDateString(),
        )->afterCommit();

        Notifier::notify(
            (int) $ot->employee_id,
            'Tăng ca được duyệt',
            'Đơn tăng ca ngày '.Carbon::parse($ot->work_date)->format('d/m/Y')
                .' ('.(float) $ot->total_hours.'h) đã được duyệt.',
            'overtime_request', $ot->id, ['priority' => 'normal'], $approverId
        );

        return $this->ok($ot->fresh(), $message);
    }

    /**
     * GET /overtime-requests/usage?employee_id=&date= — mức dùng OT ngày/tháng/năm
     * + các giới hạn theo luật (cho FE hiển thị thanh tiến độ & cảnh báo).
     */
    public function overtimeUsage(Request $request): JsonResponse
    {
        $employeeId = (int) $request->query('employee_id');
        if (! $employeeId) {
            return $this->validationError(['employee_id' => ['Thiếu employee_id']]);
        }
        $date = $request->query('date') ?: now()->toDateString();

        if (! $this->attendanceAccess->canAccessEmployee($request, $employeeId)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền xem mức sử dụng OT của nhân viên này.', 'data' => null], 403);
        }

        return $this->ok(TimePolicy::overtimeCaps($employeeId, $date, 0, null, false, (int) TenantContext::id()), 'Overtime usage');
    }

    // ═══════════════════════════════════════════════════════
    // ATTENDANCE SUMMARY (E1.4 — payroll feed)
    // ═══════════════════════════════════════════════════════

    /**
     * POST /attendance/summary/run {salary_period_id}
     *
     * Build (upsert) one salary_attendance_summary row per ACTIVE employee for
     * the given salary period. Idempotent. Result is read back via the generic
     * GET /salary-attendance-summary endpoint.
     */
    public function summaryRun(Request $request): JsonResponse
    {
        if (! $this->attendanceAccess->canRunSummary($request)) {
            return response()->json([
                'status' => 403,
                'message' => 'Chỉ Kế toán hoặc Admin được tổng hợp công cho lương.',
                'data' => null,
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'salary_period_id' => 'required|integer|exists:salary_periods,id',
        ], [
            'salary_period_id.required' => 'Mã kỳ lương là bắt buộc',
            'salary_period_id.exists' => 'Kỳ lương không tồn tại',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $period = DB::table('salary_periods')
            ->where('tenant_id', TenantContext::id())
            ->where('id', (int) $request->input('salary_period_id'))
            ->first();
        if (! $period || (! $this->attendanceAccess->isAdmin($request)
            && (int) $period->legal_entity_id !== (int) TenantContext::legalEntityId())) {
            return $this->notFound('Kỳ lương không thuộc phạm vi được phép.');
        }

        $operation = $this->createOperation($request, 'SUMMARY', (int) $period->legal_entity_id, [
            'salary_period_id' => (int) $period->id,
        ]);
        RunAttendanceSummaryOperation::dispatch($operation->id);

        return response()->json([
            'status' => 202,
            'message' => 'Đã xếp hàng tổng hợp chấm công kỳ lương.',
            'data' => $this->operationResource($operation),
        ], 202);
    }

    /**
     * GET /attendance/timesheet?month=YYYY-MM[&employee_id=]
     * Bảng công tháng (lưới nhân viên × ngày) + tổng hợp — feed payroll.
     */
    public function timesheet(Request $request, TimesheetService $service): JsonResponse
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $this->validationError(['month' => ['Định dạng tháng phải là YYYY-MM']]);
        }

        $tenantId = (int) TenantContext::id();
        $legalEntityId = (int) $this->attendanceAccess->requestedLegalEntity($request, false, true);
        if (! $this->prepareTimesheetScope($request, $legalEntityId, 'query')) {
            return $this->validationError([
                'filters' => ['Phòng ban hoặc nhân viên không thuộc pháp nhân được phép truy cập.'],
            ]);
        }

        $employeeIds = $request->filled('employee_id')
            ? [(int) $request->query('employee_id')]
            : $this->attendanceAccess->timesheetEmployeeIds($request, $legalEntityId);
        if ($this->attendanceAccess->isDepartmentManager($request) && $request->filled('department_id')) {
            $departmentId = (int) $request->query('department_id');
            $employeeIds = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->where('department_id', $departmentId)
                ->whereIn('id', $employeeIds ?? [])
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        $grid = $service->cachedMonthlyGrid(
            $tenantId,
            $legalEntityId,
            $month,
            $employeeIds,
            $request->filled('department_id') ? (int) $request->query('department_id') : null,
            max((int) $request->query('page', 1), 1),
            min(max((int) $request->query('per_page', 25), 1), 50),
            $request->boolean('refresh'),
        );

        // Kỳ lương trùng tháng (để nút "Tổng hợp công → lương" biết period nào).
        $period = DB::table('salary_periods')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('start_date', '<=', $grid['end'])
            ->where('end_date', '>=', $grid['start'])
            ->orderBy('start_date')
            ->first();
        $grid['salary_period'] = $period
            ? ['id' => $period->id, 'period_code' => $period->period_code ?? null, 'status' => $period->status ?? null]
            : null;

        return $this->ok($grid, 'Bảng công tháng '.$month);
    }

    public function timesheetOverview(Request $request, TimesheetService $service): JsonResponse
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $this->validationError(['month' => ['Định dạng tháng phải là YYYY-MM']]);
        }
        $tenantId = (int) TenantContext::id();
        $legalEntityId = (int) $this->attendanceAccess->requestedLegalEntity($request, false, true);
        if (! $this->prepareTimesheetScope($request, $legalEntityId, 'query')) {
            return $this->validationError(['filters' => ['Bộ lọc nằm ngoài phạm vi được phép.']]);
        }
        $employeeIds = $request->filled('employee_id')
            ? [(int) $request->query('employee_id')]
            : $this->attendanceAccess->timesheetEmployeeIds($request, $legalEntityId);
        if ($this->attendanceAccess->isDepartmentManager($request) && $request->filled('department_id')) {
            $departmentId = (int) $request->query('department_id');
            $employeeIds = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $legalEntityId)
                ->where('department_id', $departmentId)
                ->whereIn('id', $employeeIds ?? [])
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        return $this->ok($service->monthlyOverview(
            $tenantId,
            $legalEntityId,
            $month,
            $employeeIds,
            $request->filled('department_id') ? (int) $request->query('department_id') : null,
        ), 'Tổng quan bảng công tháng '.$month);
    }

    /**
     * POST /attendance/recompute {month|start,end[,employee_id]}
     * Tái phân loại trạng thái chấm công theo ca + dung sai (engine). Idempotent.
     */
    public function recompute(Request $request): JsonResponse
    {
        if (! $this->attendanceAccess->canRunRecompute($request)) {
            return response()->json(['status' => 403, 'message' => 'Chỉ HR hoặc Admin được tái tính chấm công.', 'data' => null], 403);
        }
        $start = $request->input('start');
        $end = $request->input('end');
        if ($request->filled('month')) {
            $month = (string) $request->input('month');
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                return $this->validationError(['month' => ['Định dạng tháng phải là YYYY-MM']]);
            }
            $start = Carbon::parse($month.'-01')->startOfMonth()->toDateString();
            $end = Carbon::parse($month.'-01')->endOfMonth()->toDateString();
        }

        if (! $start || ! $end) {
            return $this->validationError(['month' => ['Cần truyền month=YYYY-MM hoặc start+end']]);
        }

        $tenantId = (int) TenantContext::id();
        $legalEntityId = (int) $this->attendanceAccess->requestedLegalEntity($request, true, true);
        $employeeIds = $request->filled('employee_id') ? [(int) $request->input('employee_id')] : null;
        if (! $this->prepareTimesheetScope($request, $legalEntityId, 'input')) {
            return $this->validationError([
                'filters' => ['Nhân viên không thuộc pháp nhân được phép truy cập.'],
            ]);
        }

        $operation = $this->createOperation($request, 'RECOMPUTE', $legalEntityId, [
            'start' => Carbon::parse($start)->toDateString(),
            'end' => Carbon::parse($end)->toDateString(),
            'employee_ids' => $employeeIds,
        ]);
        RunAttendanceRecomputeOperation::dispatch($operation->id);

        return response()->json([
            'status' => 202,
            'message' => 'Đã xếp hàng tái phân loại chấm công.',
            'data' => $this->operationResource($operation),
        ], 202);
    }

    public function operation(Request $request, string $runId): JsonResponse
    {
        $operation = AttendanceOperation::withoutTenantScope()->find($runId);
        if (! $operation || ! $this->attendanceAccess->canViewOperation($request, $operation)) {
            return $this->notFound('Không tìm thấy tác vụ Attendance.');
        }

        return $this->ok($this->operationResource($operation), 'Trạng thái tác vụ Attendance.');
    }

    // ═══════════════════════════════════════════════════════
    // SHIFT SWAPS
    // ═══════════════════════════════════════════════════════

    public function requestShiftSwap(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'requester_id' => 'required|exists:employees,id',
            'target_employee_id' => 'required|exists:employees,id|different:requester_id',
            'swap_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        // Both employees must belong to the caller's tenant.
        foreach (['requester_id', 'target_employee_id'] as $f) {
            if (! TenantContext::ownsRow('employees', $request->input($f))) {
                return $this->validationError([$f => ['Nhân viên không thuộc công ty hiện tại']]);
            }
        }

        $columns = Schema::getColumnListing('shift_swaps');
        $data = collect($request->all())->only($columns)->toArray();
        // shift_swaps uses `approval_status`, not `status`.
        $data['approval_status'] = 'PENDING';
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $swapId = DB::table('shift_swaps')->insertGetId(TenantContext::stamp($data));

        $reqName = DB::table('employees')->where('id', $request->input('requester_id'))->value('full_name') ?: 'Đồng nghiệp';
        Notifier::notify(
            (int) $request->input('target_employee_id'),
            'Yêu cầu đổi ca',
            $reqName.' muốn đổi ca ngày '.Carbon::parse($request->input('swap_date'))->format('d/m/Y').' với bạn.',
            'shift_swap', $swapId, ['priority' => 'normal'], (int) $request->input('requester_id')
        );

        return response()->json([
            'status' => 201,
            'message' => 'Yêu cầu đổi ca đã được tạo',
            'data' => DB::table('shift_swaps')->where('id', $swapId)->first(),
        ], 201);
    }

    public function approveShiftSwap(int $id): JsonResponse
    {
        $swap = DB::table('shift_swaps')->where('id', $id)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_swaps.tenant_id', TenantContext::id()))
            ->first();

        if (! $swap) {
            return $this->notFound();
        }

        if (! in_array((string) ($swap->approval_status ?? ''), ['PENDING', 'CHỜ_DUYỆT'], true)) {
            return $this->validationError(['approval_status' => ['Yêu cầu đổi ca không ở trạng thái chờ duyệt']]);
        }

        DB::transaction(function () use ($swap, $id) {
            DB::table('shift_swaps')->where('id', $id)->update([
                'approval_status' => 'APPROVED',
                'approver_id' => request()->attributes->get('auth_employee_id'),
                'updated_at' => now(),
            ]);

            // Đổi ca theo ĐÚNG ngày swap_date (không đụng ca cố định): phân giải
            // ca mỗi NV trong ngày đó rồi ghi-đè 1 ngày chéo nhau.
            $swapDate = (string) $swap->swap_date;
            $rShift = $this->resolveShiftOnDate((int) $swap->requester_id, $swapDate);
            $tShift = $this->resolveShiftOnDate((int) $swap->target_employee_id, $swapDate);

            if ($rShift && $tShift && $rShift->shift_type_id != $tShift->shift_type_id) {
                $pairs = [
                    [(int) $swap->requester_id, $tShift->shift_type_id, $rShift->legal_entity_id ?? null],
                    [(int) $swap->target_employee_id, $rShift->shift_type_id, $tShift->legal_entity_id ?? null],
                ];
                foreach ($pairs as [$emp, $shiftType, $le]) {
                    $existing = DB::table('shift_assignments')
                        ->where('employee_id', $emp)
                        ->whereDate('effective_date', $swapDate)
                        ->whereDate('expiry_date', $swapDate)
                        ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_assignments.tenant_id', TenantContext::id()))
                        ->first();
                    if ($existing) {
                        DB::table('shift_assignments')->where('id', $existing->id)
                            ->update(['shift_type_id' => $shiftType, 'updated_at' => now()]);
                    } else {
                        DB::table('shift_assignments')->insert([
                            'employee_id' => $emp,
                            'shift_type_id' => $shiftType,
                            'effective_date' => $swapDate,
                            'expiry_date' => $swapDate,
                            'status' => 'ACTIVE',
                            'notes' => 'Đổi ca (swap #'.$id.')',
                            'meta' => json_encode(['source' => 'shift-swap', 'swap_id' => $id], JSON_UNESCAPED_UNICODE),
                            'tenant_id' => TenantContext::id(),
                            'legal_entity_id' => $le ?? TenantContext::legalEntityId(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        Notifier::notifyMany(
            [(int) $swap->requester_id, (int) $swap->target_employee_id],
            'Đổi ca đã được duyệt',
            'Yêu cầu đổi ca ngày '.Carbon::parse($swap->swap_date)->format('d/m/Y').' đã được duyệt.',
            'shift_swap', $id, ['priority' => 'normal']
        );

        return $this->ok(
            DB::table('shift_swaps')->where('id', $id)
                ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_swaps.tenant_id', TenantContext::id()))
                ->first(),
            'Yêu cầu đổi ca đã được duyệt'
        );
    }

    /**
     * POST /attendances/{id}/verify {decision: approve|reject, note?}
     * Admin xác minh một lượt chấm công bị đánh dấu "cần xem xét".
     */
    public function verifyAttendance(Request $request, int $id): JsonResponse
    {
        if (! $this->attendanceAccess->canModifyAttendance($request)) {
            return response()->json([
                'status' => 403,
                'message' => 'Chỉ HR hoặc Admin được xác minh chấm công.',
                'data' => null,
            ], 403);
        }
        $attendance = $this->attendanceAccess
            ->scopeAttendances(Attendance::query(), $request)
            ->find($id);
        if (! $attendance) {
            return $this->notFound();
        }
        if ($this->attendanceReconciliation->isClosedDate($attendance)) {
            return response()->json([
                'status' => 409,
                'message' => 'Kỳ lương chứa ngày chấm công này đã chốt, không thể xác minh lại.',
                'data' => null,
            ], 409);
        }

        $decision = strtolower((string) $request->input('decision'));
        if (! in_array($decision, ['approve', 'reject'], true)) {
            return $this->validationError(['decision' => ['decision phải là approve hoặc reject']]);
        }

        $meta = is_string($attendance->meta) ? (json_decode($attendance->meta, true) ?: []) : (array) ($attendance->meta ?? []);
        $meta['review_status'] = $decision === 'approve' ? 'approved' : 'rejected';
        $meta['reviewed_by'] = $request->attributes->get('auth_employee_id');
        $meta['reviewed_at'] = now()->toIso8601String();
        if ($request->filled('note')) {
            $meta['review_note'] = $request->input('note');
        }

        $update = ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)];
        // Từ chối lượt chấm công gian lận → đánh dấu vắng.
        if ($decision === 'reject') {
            $update['status'] = 'ABSENT';
        }
        $attendance->update($update);

        return $this->ok($attendance->fresh(), $decision === 'approve' ? 'Đã xác nhận hợp lệ' : 'Đã từ chối lượt chấm công');
    }

    /**
     * Phân giải phân ca có hiệu lực cho 1 NV trong 1 ngày (range-resolve):
     * ưu tiên bản ghi-đè 1 ngày (có expiry_date) hơn ca cố định/standing; cùng
     * loại lấy effective_date mới nhất. Trả null nếu ngày đó không có ca.
     */
    private function resolveShiftOnDate(int $employeeId, string $date): ?object
    {
        return $this->shiftResolver->resolve($employeeId, $date, TenantContext::id());
    }

    /** Tài khoản kỹ thuật (profile.system_account) không phải nhân sự thật. */
    private function isSystemAccount($employeeId): bool
    {
        $profile = DB::table('employees')->where('id', $employeeId)->value('profile');
        $p = is_string($profile) ? json_decode($profile, true) : (array) $profile;

        return is_array($p) && ! empty($p['system_account']);
    }

    private function decorateAttendance(Attendance $attendance): Attendance
    {
        $meta = is_string($attendance->meta)
            ? (json_decode($attendance->meta, true) ?: [])
            : (array) ($attendance->meta ?? []);
        foreach ([
            'regular_worked_minutes',
            'raw_presence_minutes',
            'early_arrival_minutes',
            'late_minutes',
            'early_leave_minutes',
            'after_shift_minutes',
            'scheduled_minutes',
            'worked_hours',
            'shift_start',
            'shift_end',
        ] as $key) {
            $attendance->setAttribute($key, $meta[$key] ?? 0);
        }

        return $attendance;
    }

    private function attendanceForCheckout(int $employeeId, string $today): ?Attendance
    {
        $openSession = static function ($query): void {
            $query->where(function ($first): void {
                $first->whereNotNull('check_in_time')->whereNull('check_out_time');
            })->orWhere(function ($second): void {
                $second->whereNotNull('check_in_time_2')->whereNull('check_out_time_2');
            });
        };

        $todayAttendance = Attendance::where('employee_id', $employeeId)
            ->where('work_date', $today)
            ->where($openSession)
            ->first();
        if ($todayAttendance) {
            return $todayAttendance;
        }

        // CA3 belongs to the date on which the shift starts. A checkout after
        // midnight must therefore close yesterday's still-open attendance row.
        return Attendance::where('employee_id', $employeeId)
            ->where('work_date', Carbon::parse($today)->subDay()->toDateString())
            ->where($openSession)
            ->first();
    }

    /**
     * POST /shift-roster/generate — sinh lịch CA XOAY theo tuần.
     * Body: { shift_type_ids:[], start_date, weeks, employee_ids?:[], rotate?:bool }
     * Mỗi tuần gán mỗi NV một ca; nếu rotate → ca dịch 1 bậc mỗi tuần (ca xoay).
     * Idempotent: xoá lịch sinh tự động cũ (meta.source='roster-gen') trong khoảng.
     */
    public function generateRoster(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_type_ids' => 'required|array|min:1',
            'start_date' => 'required|date',
            'weeks' => 'required|integer|min:1|max:26',
        ], [
            'shift_type_ids.required' => 'Cần chọn ít nhất một ca để xoay',
            'start_date.required' => 'Ngày bắt đầu là bắt buộc',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $tenantId = TenantContext::id();
        $entityId = TenantContext::legalEntityId();
        $shiftIds = array_values($request->input('shift_type_ids'));
        $weeks = (int) $request->input('weeks');
        $rotate = $request->boolean('rotate', true);
        // Snap về thứ Hai của tuần để các tuần xoay khớp nhau kể cả khi caller
        // truyền ngày giữa tuần (không tin mỗi FE).
        $startMonday = Carbon::parse($request->input('start_date'))->startOfWeek(Carbon::MONDAY);

        // Nhân viên: theo danh sách hoặc tất cả NV đang làm.
        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->whereRaw("COALESCE((profile->>'system_account')::boolean, false) = false")
            ->when($request->filled('employee_ids'), fn ($q) => $q->whereIn('id', $request->input('employee_ids')))
            ->orderBy('employee_code')
            ->get(['id', 'legal_entity_id']);
        if ($employees->isEmpty()) {
            return $this->validationError(['employee_ids' => ['Không có nhân viên để xếp ca']]);
        }

        $endDate = $startMonday->copy()->addDays($weeks * 7 - 1);
        $empIds = $employees->pluck('id')->all();

        // Xoá lịch sinh tự động cũ trong khoảng để tạo lại sạch.
        DB::table('shift_assignments')
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $empIds)
            ->whereRaw("meta->>'source' = 'roster-gen'")
            ->whereBetween('effective_date', [$startMonday->toDateString(), $endDate->toDateString()])
            ->delete();

        $now = now();
        $rows = [];
        $count = count($shiftIds);
        foreach ($employees->values() as $i => $emp) {
            for ($w = 0; $w < $weeks; $w++) {
                $weekStart = $startMonday->copy()->addDays($w * 7);
                $weekEnd = $weekStart->copy()->addDays(6);
                $idx = $rotate ? (($i + $w) % $count) : ($i % $count);
                $rows[] = [
                    'employee_id' => $emp->id,
                    'shift_type_id' => $shiftIds[$idx],
                    'effective_date' => $weekStart->toDateString(),
                    'expiry_date' => $weekEnd->toDateString(),
                    'status' => 'ACTIVE',
                    'notes' => 'Lịch ca xoay (tự sinh)',
                    'meta' => json_encode(['source' => 'roster-gen', 'rotate' => $rotate], JSON_UNESCAPED_UNICODE),
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $emp->legal_entity_id ?? $entityId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        // is_permanent để DB tự đặt default (false) — tránh lỗi bind boolean PG.
        DB::table('shift_assignments')->insert($rows);

        return $this->ok([
            'employees' => $employees->count(),
            'weeks' => $weeks,
            'assignments_created' => count($rows),
            'range' => [$startMonday->toDateString(), $endDate->toDateString()],
        ], "Đã tạo lịch ca xoay {$weeks} tuần cho {$employees->count()} nhân viên");
    }

    // ── Response Helpers ─────────────────────────────────

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $message = 'Record not found'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $message, 'data' => null], 404);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => ['errors' => $errors],
        ], 422);
    }

    private function sumOvertimeMeta(Builder $query, string $key): int
    {
        $expression = DB::getDriverName() === 'pgsql'
            ? "COALESCE((overtime_requests.meta->>'{$key}')::int, 0)"
            : "COALESCE(CAST(json_extract(overtime_requests.meta, '$.{$key}') AS INTEGER), 0)";
        $row = (clone $query)->withoutEagerLoads()->reorder()
            ->selectRaw("COALESCE(SUM({$expression}), 0) AS total")
            ->first();

        return (int) ($row?->total ?? 0);
    }

    private function createOperation(Request $request, string $type, int $legalEntityId, array $filters): AttendanceOperation
    {
        return AttendanceOperation::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => (int) TenantContext::id(),
            'legal_entity_id' => $legalEntityId,
            'requested_by' => $this->attendanceAccess->actorId($request),
            'type' => $type,
            'status' => 'PENDING',
            'filters' => $filters,
        ]);
    }

    /** @return array<string, mixed> */
    private function operationResource(AttendanceOperation $operation): array
    {
        $total = max(0, (int) $operation->total_items);
        $processed = max(0, (int) $operation->processed_items);

        return [
            'run_id' => $operation->id,
            'type' => $operation->type,
            'status' => $operation->status,
            'progress_percent' => $total > 0 ? min(100, (int) floor($processed * 100 / $total)) : ($operation->status === 'COMPLETED' ? 100 : 0),
            'total_items' => $total,
            'processed_items' => $processed,
            'succeeded_items' => (int) $operation->succeeded_items,
            'failed_items' => (int) $operation->failed_items,
            'result' => $operation->result,
            'error' => $operation->error,
            'started_at' => $operation->started_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }

    private function prepareTimesheetScope(Request $request, int $legalEntityId, string $source): bool
    {
        $read = fn (string $key) => $source === 'input' ? $request->input($key) : $request->query($key);
        $write = function (string $key, mixed $value) use ($request, $source): void {
            if ($source === 'input') {
                $request->merge([$key => $value]);
            } else {
                $request->query->set($key, $value);
            }
        };

        if (! $this->attendanceAccess->canReadOrganization($request) && ! $this->attendanceAccess->isAccountant($request)) {
            $write('employee_id', $this->attendanceAccess->actorId($request));
        }
        $departmentId = $read('department_id');
        if ($departmentId !== null && $departmentId !== ''
            && ! $this->attendanceAccess->assertDepartmentFilter($request, (int) $departmentId)) {
            return false;
        }
        $employeeId = $read('employee_id');
        if ($employeeId !== null && $employeeId !== ''
            && ! $this->attendanceAccess->canFilterTimesheetEmployee($request, (int) $employeeId, $legalEntityId)) {
            return false;
        }

        return $this->validTimesheetFilterScope($request, $legalEntityId, $source);
    }

    private function validTimesheetFilterScope(Request $request, int $legalEntityId, string $source): bool
    {
        $read = fn (string $key) => $source === 'input' ? $request->input($key) : $request->query($key);

        foreach (['department_id' => 'departments', 'employee_id' => 'employees'] as $key => $table) {
            $id = $read($key);
            if ($id === null || $id === '') {
                continue;
            }
            if (! DB::table($table)
                ->where('id', (int) $id)
                ->where('tenant_id', TenantContext::id())
                ->where('legal_entity_id', $legalEntityId)
                ->exists()) {
                return false;
            }
        }

        return true;
    }

    private function scopeSelfServiceRequest(Request $request): void
    {
        $access = (array) $request->attributes->get('access', []);
        if (empty($access['full']) && ! in_array('time', $access['modules'] ?? [], true)) {
            $request->query->set('employee_id', (int) $request->attributes->get('auth_employee_id'));
        }
    }
}
