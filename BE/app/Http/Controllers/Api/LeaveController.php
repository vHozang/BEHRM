<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\AttendanceAccess;
use App\Services\LeavePolicyService;
use App\Services\ShiftResolver;
use App\Support\AccessControl;
use App\Support\ApprovalFlow;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class LeaveController extends Controller
{
    public function __construct(
        private LeavePolicyService $leavePolicy,
        private ShiftResolver $shiftResolver,
        private readonly AttendanceAccess $access,
    ) {}
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = LeaveRequest::with(['employee:id,full_name,employee_code'])
            ->orderByDesc('id');
        $this->access->scopeEmployeeResource($query, $request, 'leave_requests');

        foreach (['employee_id', 'leave_type_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        $page = $query->paginate($perPage);

        // can_approve theo CẤP duyệt hiện tại của từng đơn (đa cấp: Quản lý→HR…). FE chỉ
        // hiện nút Duyệt/Từ chối cho đúng người duyệt cấp này (backend vẫn chặn nếu sai).
        // ponytail: cannotApprove() có truy vấn role — N+1 nhỏ (list ≤100, không hot path).
        $approverId = $request->attributes->get('auth_employee_id');
        $items = $page->items();
        foreach ($items as $item) {
            $pending = in_array($item->status, ['PENDING', 'CHỜ_DUYỆT'], true);
            $notSelf = $approverId !== null && (int) $item->employee_id !== (int) $approverId;
            if ($pending && $notSelf) {
                $meta = is_string($item->meta) ? (json_decode($item->meta, true) ?: []) : (array) ($item->meta ?? []);
                $meta = ApprovalFlow::ensure($meta, 'leave');
                $item->can_approve = ApprovalFlow::cannotApprove($meta, $approverId) === null;
            } else {
                $item->can_approve = false;
            }
            $this->appendLeaveMeta($item);
            $item->can_edit = $pending
                && (int) $item->employee_id === $this->access->actorId($request)
                && ApprovalFlow::progress((array) ($item->decoded_meta ?? []))['done'] === 0;
        }

        return $this->ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Leave requests list');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'employee_id.exists' => 'Nhân viên không tồn tại',
            'leave_type_id.required' => 'Loại nghỉ phép là bắt buộc',
            'start_date.required' => 'Ngày bắt đầu nghỉ là bắt buộc',
            'start_date.after_or_equal' => 'Ngày bắt đầu nghỉ không được nằm trong quá khứ',
            'end_date.required' => 'Ngày kết thúc nghỉ là bắt buộc',
            'end_date.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = $request->input('employee_id');
        $leaveTypeId = $request->input('leave_type_id');

        $actorId = $this->access->actorId($request);
        $canManage = AccessControl::accessHasCapability(
            (array) $request->attributes->get('access', []),
            'leave.manage',
        );
        if ((int) $employeeId !== $actorId
            && (! $canManage || ! $this->access->canAccessEmployee($request, (int) $employeeId, true))) {
            return response()->json([
                'status' => 403,
                'message' => 'Bạn chỉ được tạo đơn nghỉ cho chính mình hoặc nhân viên trong phạm vi được quản lý',
                'data' => null,
            ], 403);
        }

        // Reject cross-tenant employee/leave-type (bare `exists` is unscoped).
        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }
        if (! TenantContext::ownsRow('leave_types', $leaveTypeId)) {
            return $this->validationError(['leave_type_id' => ['Loại nghỉ phép không thuộc công ty hiện tại']]);
        }

        // Check for overlapping leave requests
        $overlap = LeaveRequest::where('employee_id', $employeeId)
            ->whereNotIn('status', ['REJECTED', 'CANCELLED', 'TỪ_CHỐI', 'ĐÃ_HỦY'])
            ->where(function ($q) use ($request) {
                $q->whereBetween('start_date', [$request->start_date, $request->end_date])
                    ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                    ->orWhere(function ($q2) use ($request) {
                        $q2->where('start_date', '<=', $request->start_date)
                            ->where('end_date', '>=', $request->end_date);
                    });
            })->exists();

        if ($overlap) {
            return $this->validationError([
                'start_date' => ['Đã có đơn nghỉ phép trùng thời gian'],
            ]);
        }

        // Check leave balance — resolve the balance row for the REQUEST'S year
        // ONLY (no cross-year fallback); avoids picking a stale prior-year row.
        $leaveYear = (string) date('Y', strtotime((string) $request->start_date));
        $balance = LeaveBalance::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $leaveYear)
            ->first();

        // Resolve the leave type's effective rule config (VN labour law): paid,
        // accrual model (annual quota vs per-event statutory vs none), whether a
        // balance is required, and per-event day cap.
        $leaveType = DB::table('leave_types')->where('id', $leaveTypeId)->first();
        $cfg = $this->leavePolicy->typeConfig($leaveType);

        // Thời lượng nghỉ: cả ngày | nửa ngày | theo giờ (Đ. cho phép nghỉ <1 ngày).
        $durationType = in_array($request->input('duration_type'), ['half_day', 'hourly'], true)
            ? $request->input('duration_type') : 'full_day';

        if ($durationType === 'half_day') {
            // Nửa ngày: chỉ trong 1 ngày làm việc → 0.5 công.
            if ((string) $request->start_date !== (string) $request->end_date) {
                return $this->validationError(['end_date' => ['Nghỉ nửa ngày chỉ áp dụng trong cùng một ngày']]);
            }
            $totalDays = 0.5;
        } elseif ($durationType === 'hourly') {
            // Theo giờ: quy đổi ra ngày theo giờ chuẩn/ngày.
            $hours = (float) $request->input('hours', 0);
            $std = (float) \App\Support\HrmConfig::get('attendance.standard_hours_per_day', 8);
            if ($hours <= 0 || $std <= 0) {
                return $this->validationError(['hours' => ['Số giờ nghỉ phải lớn hơn 0']]);
            }
            if ((string) $request->start_date !== (string) $request->end_date) {
                return $this->validationError(['end_date' => ['Nghỉ theo giờ chỉ áp dụng trong cùng một ngày']]);
            }
            $totalDays = round($hours / $std, 4);
        } else {
            // Cả ngày: đếm ngày làm việc (trừ cuối tuần + lễ).
            $totalDays = $this->leavePolicy->workingDays(
                $request->start_date,
                $request->end_date,
                TenantContext::id(),
                TenantContext::legalEntityId()
            );

            if ($totalDays < 1) {
                return $this->validationError([
                    'start_date' => ['Khoảng thời gian không có ngày làm việc nào (toàn ngày nghỉ/lễ)'],
                ]);
            }
        }

        // Quota types (e.g. ANNUAL) need an entitlement balance for the request year.
        if ($cfg['requires_balance']) {
            if (! $balance) {
                return $this->validationError([
                    'total_days' => ["Nhân viên chưa có số dư phép loại này cho năm {$leaveYear}"],
                ]);
            }
            if ($balance->remaining_days < $totalDays) {
                return $this->validationError([
                    'total_days' => ["Số dư phép không đủ. Còn lại: {$balance->remaining_days} ngày, yêu cầu: {$totalDays} ngày"],
                ]);
            }
        } elseif ($cfg['accrual'] === 'per_event' && $cfg['max_days_per_event'] !== null) {
            // Statutory per-event leave (cưới, tang, thai sản…) — cap days/lần.
            if ($totalDays > $cfg['max_days_per_event']) {
                $ref = $cfg['statutory_ref'] ? " ({$cfg['statutory_ref']})" : '';
                return $this->validationError([
                    'total_days' => ["Loại nghỉ này tối đa {$cfg['max_days_per_event']} ngày/lần theo luật{$ref}; yêu cầu {$totalDays} ngày"],
                ]);
            }
        }

        $data = [
            'employee_id' => (int) $employeeId,
            'leave_type_id' => (int) $leaveTypeId,
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
        ];
        $data['total_days'] = $totalDays;
        // Đơn tạo mới LUÔN ở PENDING — không cho client tự set APPROVED để bỏ qua
        // duyệt + né trừ số dư phép (chỉ approve() mới chuyển trạng thái + trừ quota).
        $data['status'] = 'PENDING';

        // Persist classification + reason in meta (payroll reads `paid`; no `reason` column).
        $meta = [];
        if (! empty($data['meta'])) {
            $meta = is_string($data['meta']) ? (json_decode($data['meta'], true) ?: []) : (array) $data['meta'];
        }
        $meta['paid'] = $cfg['paid'];
        $meta['accrual'] = $cfg['accrual'];
        $meta['leave_type_code'] = $leaveType->leave_type_code ?? null;
        $meta['statutory_ref'] = $cfg['statutory_ref'];
        $meta['duration_type'] = $durationType;
        if ($durationType === 'half_day') {
            $meta['half_session'] = in_array($request->input('half_session'), ['morning', 'afternoon'], true)
                ? $request->input('half_session') : 'morning';
        } elseif ($durationType === 'hourly') {
            $meta['hours'] = (float) $request->input('hours', 0);
        }
        if ($request->filled('reason')) {
            $meta['reason'] = $request->input('reason');
        }
        $data['meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);

        $data['created_at'] = now();
        $data['updated_at'] = now();

        $leave = LeaveRequest::create($data);

        return response()->json([
            'status' => 201,
            'message' => 'Đơn nghỉ phép đã được tạo',
            'data' => $leave->fresh()->load('employee:id,full_name'),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::with(['employee:id,full_name,employee_code'])->find($id);

        if (! $leave) {
            return $this->notFound();
        }
        if (! $this->access->canAccessEmployee($request, (int) $leave->employee_id)) {
            return $this->notFound();
        }

        $this->appendLeaveMeta($leave);

        return $this->ok($leave, 'Leave request detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::find($id);

        if (! $leave) {
            return $this->notFound();
        }

        if ((int) $leave->employee_id !== $this->access->actorId($request)) {
            return response()->json([
                'status' => 403,
                'message' => 'Chỉ nhân viên tạo đơn mới được sửa đơn nghỉ phép',
                'data' => null,
            ], 403);
        }

        if (! in_array($leave->status, ['PENDING', 'CHỜ_DUYỆT'])) {
            return $this->validationError([
                'status' => ['Chỉ có thể sửa đơn đang chờ duyệt'],
            ]);
        }

        $existingMeta = $this->decodeMeta($leave->meta);
        if (ApprovalFlow::progress($existingMeta)['done'] > 0) {
            return $this->validationError([
                'status' => ['Đơn đã có cấp duyệt hoàn tất; hãy hủy và tạo đơn mới để thay đổi nội dung'],
            ]);
        }

        $input = [
            'leave_type_id' => $request->input('leave_type_id', $leave->leave_type_id),
            'start_date' => $request->input('start_date', optional($leave->start_date)->format('Y-m-d')),
            'end_date' => $request->input('end_date', optional($leave->end_date)->format('Y-m-d')),
            'duration_type' => $request->input('duration_type', $existingMeta['duration_type'] ?? 'full_day'),
            'half_session' => $request->input('half_session', $existingMeta['half_session'] ?? 'morning'),
            'hours' => $request->input('hours', $existingMeta['hours'] ?? null),
            'reason' => $request->input('reason', $existingMeta['reason'] ?? null),
        ];
        $validator = Validator::make($input, [
            'leave_type_id' => ['required', 'integer'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'duration_type' => ['required', 'in:full_day,half_day,hourly'],
            'half_session' => ['nullable', 'in:morning,afternoon'],
            'hours' => ['nullable', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        if (! TenantContext::ownsRow('leave_types', (int) $input['leave_type_id'])) {
            return $this->validationError(['leave_type_id' => ['Loại nghỉ phép không thuộc công ty hiện tại']]);
        }

        $overlap = LeaveRequest::where('employee_id', $leave->employee_id)
            ->where('id', '<>', $leave->id)
            ->whereNotIn('status', ['REJECTED', 'CANCELLED', 'TỪ_CHỐI', 'ĐÃ_HỦY'])
            ->where(function ($query) use ($input): void {
                $query->whereBetween('start_date', [$input['start_date'], $input['end_date']])
                    ->orWhereBetween('end_date', [$input['start_date'], $input['end_date']])
                    ->orWhere(function ($covered) use ($input): void {
                        $covered->where('start_date', '<=', $input['start_date'])
                            ->where('end_date', '>=', $input['end_date']);
                    });
            })->exists();
        if ($overlap) {
            return $this->validationError(['start_date' => ['Đã có đơn nghỉ phép trùng thời gian']]);
        }

        $leaveType = DB::table('leave_types')
            ->where('tenant_id', TenantContext::id())
            ->where('id', $input['leave_type_id'])
            ->first();
        $cfg = $this->leavePolicy->typeConfig($leaveType);
        $durationType = (string) $input['duration_type'];
        if ($durationType === 'half_day') {
            if ($input['start_date'] !== $input['end_date']) {
                return $this->validationError(['end_date' => ['Nghỉ nửa ngày chỉ áp dụng trong cùng một ngày']]);
            }
            $totalDays = 0.5;
        } elseif ($durationType === 'hourly') {
            if ($input['start_date'] !== $input['end_date']) {
                return $this->validationError(['end_date' => ['Nghỉ theo giờ chỉ áp dụng trong cùng một ngày']]);
            }
            $standardHours = (float) \App\Support\HrmConfig::get('attendance.standard_hours_per_day', 8);
            $totalDays = round((float) $input['hours'] / max($standardHours, 0.01), 4);
        } else {
            $totalDays = $this->leavePolicy->workingDays(
                $input['start_date'],
                $input['end_date'],
                TenantContext::id(),
                TenantContext::legalEntityId(),
            );
            if ($totalDays < 1) {
                return $this->validationError(['start_date' => ['Khoảng thời gian không có ngày làm việc nào']]);
            }
        }

        $year = (string) date('Y', strtotime((string) $input['start_date']));
        if ($cfg['requires_balance']) {
            $balance = LeaveBalance::where('employee_id', $leave->employee_id)
                ->where('leave_type_id', $input['leave_type_id'])
                ->where('year', $year)
                ->first();
            if (! $balance || (float) $balance->remaining_days < $totalDays) {
                return $this->validationError(['total_days' => ['Số dư phép không đủ cho nội dung đã sửa']]);
            }
        } elseif ($cfg['accrual'] === 'per_event'
            && $cfg['max_days_per_event'] !== null
            && $totalDays > $cfg['max_days_per_event']) {
            return $this->validationError([
                'total_days' => ["Loại nghỉ này tối đa {$cfg['max_days_per_event']} ngày/lần"],
            ]);
        }

        $meta = array_merge($existingMeta, [
            'paid' => $cfg['paid'],
            'accrual' => $cfg['accrual'],
            'leave_type_code' => $leaveType->leave_type_code ?? null,
            'statutory_ref' => $cfg['statutory_ref'],
            'duration_type' => $durationType,
            'reason' => $input['reason'],
        ]);
        if ($durationType === 'half_day') {
            $meta['half_session'] = $input['half_session'] ?: 'morning';
            unset($meta['hours']);
        } elseif ($durationType === 'hourly') {
            $meta['hours'] = (float) $input['hours'];
            unset($meta['half_session']);
        } else {
            unset($meta['half_session'], $meta['hours']);
        }

        $leave->update([
            'leave_type_id' => (int) $input['leave_type_id'],
            'start_date' => $input['start_date'],
            'end_date' => $input['end_date'],
            'total_days' => $totalDays,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);

        $updated = $leave->fresh()->load('employee:id,full_name,employee_code');
        $this->appendLeaveMeta($updated);

        return $this->ok($updated, 'Đơn nghỉ phép đã được cập nhật');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::find($id);

        if (! $leave) {
            return $this->notFound();
        }
        if (! $this->access->canAccessEmployee($request, (int) $leave->employee_id)) {
            return $this->notFound();
        }

        return $this->conflict(
            ['Đơn nghỉ phép đã vào workflow không được xóa cứng; hãy dùng thao tác Hủy đơn'],
            'Đơn nghỉ phép'
        );
    }

    /**
     * POST /leave-requests/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::find($id);

        if (! $leave) {
            return $this->notFound();
        }
        if (! $this->access->canAccessEmployee($request, (int) $leave->employee_id, true)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền duyệt đơn này', 'data' => null], 403);
        }

        if (! in_array($leave->status, ['PENDING', 'CHỜ_DUYỆT'])) {
            return $this->validationError(['status' => ['Đơn không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');

        // Người tạo đơn không tự duyệt.
        if ($approverId !== null && (int) $leave->employee_id === (int) $approverId) {
            return $this->validationError(['approver_id' => ['Người tạo đơn không thể tự duyệt đơn của mình']]);
        }

        // Duyệt nhiều cấp: chỉ khi qua HẾT các cấp mới chốt APPROVED + trừ số dư.
        $meta = is_string($leave->meta) ? (json_decode($leave->meta, true) ?: []) : (array) ($leave->meta ?? []);
        $meta = ApprovalFlow::ensure($meta, 'leave');
        if ($err = ApprovalFlow::cannotApprove($meta, $approverId)) {
            return $this->validationError(['approver_id' => [$err]]);
        }
        [$meta, $final] = ApprovalFlow::approveStep($meta, $approverId, $request->input('comment'));
        $progress = ApprovalFlow::progress($meta);

        if (! $final) {
            // Còn cấp duyệt phía sau → giữ PENDING, lưu tiến trình.
            $leave->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
            $next = ApprovalFlow::currentRole($meta);

            return $this->ok($leave->fresh(), "Đã duyệt cấp {$progress['done']}/{$progress['total']}. Chờ cấp tiếp theo" . ($next ? " ({$next})" : '') . '.');
        }

        DB::transaction(function () use ($leave, $approverId, $meta) {
            // Chống double-spend (TOCTOU): khoá hàng đơn + xác nhận lại PENDING BÊN
            // TRONG transaction. Hai request duyệt đồng thời sẽ nối tiếp nhau qua
            // lockForUpdate; request thứ 2 thấy đã APPROVED → thoát, không trừ quota
            // lần nữa. (Đã kiểm chứng: trước fix, 3 duyệt song song trừ 3× số ngày.)
            $locked = DB::table('leave_requests')->where('id', $leave->id)->lockForUpdate()->first();
            if (! $locked || ! in_array((string) $locked->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
                return;
            }

            $meta['approved_by'] = $approverId;
            $meta['approved_at'] = now()->toIso8601String();

            $leave->update([
                'status' => 'APPROVED',
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);

            // Only quota-backed types (e.g. ANNUAL) draw down a balance. Statutory
            // per-event paid leave (cưới, tang, thai sản) and unpaid leave do not.
            if ($this->requiresBalance($leave)) {
                $this->adjustBalanceAndLedger($leave, 'DEDUCT', 'Duyệt đơn nghỉ phép');
            }
        });

        // Khép chuỗi nghỉ → phủ ca: tự sinh yêu cầu phủ ca cho ngày nghỉ (nếu bật).
        $this->autoCreateCoverage($leave, $approverId);

        \App\Support\Notifier::notify(
            (int) $leave->employee_id,
            'Đơn nghỉ được duyệt',
            'Đơn nghỉ ' . \Carbon\Carbon::parse($leave->start_date)->format('d/m/Y')
                . ' → ' . \Carbon\Carbon::parse($leave->end_date)->format('d/m/Y') . ' đã được duyệt.',
            'leave_request', $leave->id, ['priority' => 'normal'], $approverId
        );

        return $this->ok($leave->fresh(), 'Đơn nghỉ phép đã được duyệt (hoàn tất các cấp)');
    }

    /**
     * Khi đơn nghỉ được duyệt (full-day) và DN bật `attendance.auto_coverage_on_leave`,
     * tạo "yêu cầu phủ ca" (OPEN) cho mỗi ngày nghỉ mà NV có ca làm → quản lý
     * điều người tăng ca phủ. Idempotent, bỏ qua ngày off/không có ca. Lỗi phụ
     * trợ không làm hỏng việc duyệt nghỉ.
     */
    private function autoCreateCoverage(LeaveRequest $leave, $approverId): void
    {
        try {
            if (! \App\Support\HrmConfig::get('attendance.auto_coverage_on_leave', false)) {
                return;
            }
            if (! Schema::hasTable('shift_coverage_requests')) {
                return;
            }
            // Nghỉ nửa buổi/theo giờ: NV vẫn có mặt một phần → không tạo phủ ca.
            $meta = is_string($leave->meta) ? (json_decode($leave->meta, true) ?: []) : (array) ($leave->meta ?? []);
            if (($meta['duration_type'] ?? 'full_day') !== 'full_day') {
                return;
            }

            $start = \Carbon\Carbon::parse($leave->start_date)->startOfDay();
            $end = \Carbon\Carbon::parse($leave->end_date)->startOfDay();
            if ($end->lt($start) || $start->diffInDays($end) > 60) {
                return;
            }

            $tenantId = TenantContext::id();
            $entityId = TenantContext::legalEntityId();

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dateStr = $d->toDateString();
                $shift = $this->resolveShiftForCoverage((int) $leave->employee_id, $dateStr, $tenantId);
                if (! $shift) {
                    continue; // ngày không có ca
                }

                $shiftMeta = is_string($shift->meta ?? null) ? (json_decode($shift->meta, true) ?: []) : (array) ($shift->meta ?? []);
                $workWeekdays = $shiftMeta['work_weekdays'] ?? null;
                $iso = $d->dayOfWeekIso; // 1=T2 .. 7=CN
                if (is_array($workWeekdays)
                    && ! in_array($iso, $workWeekdays, false) && ! in_array((string) $iso, $workWeekdays, true)) {
                    continue; // ngày NV không làm ca này → không cần phủ
                }

                $exists = DB::table('shift_coverage_requests')
                    ->where('tenant_id', $tenantId)
                    ->whereDate('work_date', $dateStr)
                    ->where('shift_type_id', $shift->id)
                    ->where('absent_employee_id', $leave->employee_id)
                    ->whereIn('status', ['OPEN', 'PARTIALLY_COVERED', 'COVERED'])
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('shift_coverage_requests')->insert([
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $entityId,
                    'work_date' => $dateStr,
                    'shift_type_id' => $shift->id,
                    'absent_employee_id' => $leave->employee_id,
                    'reason' => 'leave',
                    'hours_needed' => (float) ($shiftMeta['working_hours'] ?? 8),
                    'hours_covered' => 0,
                    'status' => 'OPEN',
                    'created_by' => $approverId,
                    'notes' => 'Tự sinh từ đơn nghỉ #' . $leave->id,
                    'meta' => json_encode(['source' => 'auto-leave', 'leave_id' => $leave->id, 'shift_code' => $shift->shift_code], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('autoCreateCoverage failed: ' . $e->getMessage());
        }
    }

    /** Phân giải ca có hiệu lực của NV trong 1 ngày (override có expiry ưu tiên). */
    private function resolveShiftForCoverage(int $employeeId, string $date, $tenantId): ?object
    {
        $assign = $this->shiftResolver->resolve($employeeId, $date, $tenantId ? (int) $tenantId : null);
        if (! $assign || ! $assign->shift_type_id || ! $this->shiftResolver->isAssignmentWorkday($assign, $date)) {
            return null;
        }

        return DB::table('shift_types')->where('id', $assign->shift_type_id)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->first();
    }

    /**
     * POST /leave-requests/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::find($id);

        if (! $leave) {
            return $this->notFound();
        }
        if (! $this->access->canAccessEmployee($request, (int) $leave->employee_id, true)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền từ chối đơn này', 'data' => null], 403);
        }

        if (! in_array($leave->status, ['PENDING', 'CHỜ_DUYỆT'])) {
            return $this->validationError(['status' => ['Đơn không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');

        // Người tạo đơn không tự từ chối (muốn bỏ thì dùng Hủy đơn).
        if ($approverId !== null && (int) $leave->employee_id === (int) $approverId) {
            return $this->validationError(['approver_id' => ['Người tạo đơn không thể tự từ chối đơn của mình']]);
        }

        // Từ chối cũng là quyết định của CẤP duyệt hiện tại → phải giữ đúng vai trò cấp này,
        // giống approve(). Trước đây reject KHÔNG kiểm tra gì → bất kỳ ai chạm endpoint cũng
        // từ chối được đơn của người khác, bỏ qua toàn bộ duyệt đa cấp.
        $rmeta = is_string($leave->meta) ? (json_decode($leave->meta, true) ?: []) : (array) ($leave->meta ?? []);
        $rmeta = ApprovalFlow::ensure($rmeta, 'leave');
        if ($err = ApprovalFlow::cannotApprove($rmeta, $approverId)) {
            return $this->validationError(['approver_id' => [$err]]);
        }

        // rejection_reason has no column — preserve it (and the approver) in meta.
        $leave->update([
            'status' => 'REJECTED',
            'meta' => $this->mergeMeta($leave, [
                'rejected_by' => $request->attributes->get('auth_employee_id'),
                'rejected_at' => now()->toIso8601String(),
                'rejection_reason' => $request->input('reason'),
            ]),
        ]);

        return $this->ok($leave->fresh(), 'Đơn nghỉ phép đã bị từ chối');
    }

    /**
     * POST /leave-requests/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::find($id);

        if (! $leave) {
            return $this->notFound();
        }

        // Authorization: only the requesting employee may cancel their own leave.
        // (HR/admin override would go through a dedicated admin path.)
        $currentUserId = $this->access->actorId($request);
        if ((int) $leave->employee_id !== $currentUserId) {
            return response()->json([
                'status' => 403,
                'message' => 'Chỉ nhân viên tạo đơn mới có thể hủy đơn nghỉ phép',
                'data' => null,
            ], 403);
        }

        // Status guard — only un-finalized leave can be cancelled.
        if (! in_array($leave->status, ['PENDING', 'APPROVED', 'IN_PROGRESS', 'CHỜ_DUYỆT', 'ĐÃ_DUYỆT', 'ĐANG_XỬ_LÝ'], true)) {
            return $this->validationError(['status' => ['Không thể hủy đơn ở trạng thái hiện tại']]);
        }

        // Cannot cancel (and refund) leave that has already started/been consumed.
        if ($leave->start_date && \Carbon\Carbon::parse($leave->start_date)->startOfDay()->lte(now()->startOfDay())) {
            return $this->validationError([
                'start_date' => ['Không thể hủy đơn nghỉ phép đã bắt đầu hoặc đã sử dụng'],
            ]);
        }

        DB::transaction(function () use ($leave) {
            $wasApproved = in_array($leave->status, ['APPROVED', 'ĐÃ_DUYỆT']);

            $leave->update(['status' => 'CANCELLED']);

            // Refund balance only if it was approved AND drew down a balance.
            if ($wasApproved && $this->requiresBalance($leave)) {
                $this->adjustBalanceAndLedger($leave, 'REFUND', 'Hủy đơn nghỉ phép — hoàn lại số dư');
            }
        });

        return $this->ok($leave->fresh(), 'Đơn nghỉ phép đã được hủy');
    }

    /**
     * GET /employees/{employeeId}/leave-balances
     */
    public function balance(Request $request, int $employeeId): JsonResponse
    {
        if (! $this->access->canAccessEmployee($request, $employeeId)) {
            return $this->notFound();
        }
        $query = LeaveBalance::where('employee_id', $employeeId);

        if ($request->filled('year')) {
            $query->where('year', (string) $request->query('year'));
        }

        $balances = $query
            ->join('leave_types', 'leave_balances.leave_type_id', '=', 'leave_types.id')
            ->orderByDesc('leave_balances.year')
            ->get([
                'leave_balances.leave_type_id',
                'leave_types.leave_type_code',
                'leave_types.leave_type_name',
                'leave_balances.year',
                'leave_balances.total_days',
                'leave_balances.used_days',
                'leave_balances.remaining_days',
            ])
            ->map(fn ($b) => [
                'leave_type_id' => (int) $b->leave_type_id,
                'leave_type_code' => $b->leave_type_code,
                'leave_type_name' => $b->leave_type_name,
                'year' => (int) $b->year,
                'entitlement' => (float) $b->total_days,
                'used' => (float) $b->used_days,
                'remaining' => (float) $b->remaining_days,
            ]);

        return $this->ok($balances, 'Leave balances');
    }

    /**
     * POST /leave/accrual/run — recompute annual leave balances for the tenant.
     */
    public function accrualRun(Request $request): JsonResponse
    {
        if (! AccessControl::accessHasCapability(
            (array) $request->attributes->get('access', []),
            'leave.manage',
        )) {
            return response()->json([
                'status' => 403,
                'message' => 'Chỉ HR hoặc Admin được đối soát phép năm',
                'data' => null,
            ], 403);
        }

        $year = (int) ($request->input('year') ?: now()->year);
        if ($year < 2000 || $year > 2100) {
            return $this->validationError(['year' => ['Năm không hợp lệ (2000–2100)']]);
        }

        $summary = $this->leavePolicy->recomputeBalances(TenantContext::id(), $year);

        return $this->ok(array_merge(['year' => $year], $summary), 'Cấp/đối soát phép năm hoàn tất');
    }

    // ── Internal Helpers ─────────────────────────────────

    private function decodeMeta(mixed $value): array
    {
        $meta = is_string($value) ? json_decode($value, true) : (array) ($value ?? []);

        return is_array($meta) ? $meta : [];
    }

    private function appendLeaveMeta(LeaveRequest $leave): void
    {
        $meta = $this->decodeMeta($leave->meta);
        $leave->setAttribute('decoded_meta', $meta);
        foreach (['reason', 'paid', 'accrual', 'leave_type_code', 'statutory_ref', 'duration_type', 'half_session', 'hours'] as $key) {
            $leave->setAttribute($key, $meta[$key] ?? null);
        }
    }

    /**
     * Does this leave request's type draw down a quota balance (e.g. ANNUAL)?
     * Statutory per-event paid leave and unpaid leave do not.
     */
    private function requiresBalance(LeaveRequest $leave): bool
    {
        $type = DB::table('leave_types')->where('id', $leave->leave_type_id)->first();

        return $this->leavePolicy->typeConfig($type)['requires_balance'];
    }

    /**
     * Merge keys into a leave_requests.meta jsonb payload and return JSON.
     */
    private function mergeMeta(LeaveRequest $leave, array $extra): string
    {
        $existing = [];
        if ($leave->meta) {
            $existing = is_string($leave->meta) ? json_decode($leave->meta, true) : (array) $leave->meta;
            if (! is_array($existing)) {
                $existing = [];
            }
        }

        return json_encode(array_merge($existing, $extra), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Apply a DEDUCT (used+, remaining-) or REFUND (used-, remaining+) to the
     * matching leave_balances row and write a leave_transactions ledger entry.
     */
    private function adjustBalanceAndLedger(LeaveRequest $leave, string $type, string $reason): void
    {
        $days = (float) $leave->total_days;
        $year = (string) optional($leave->start_date)->year ?: (string) now()->year;

        // Resolve the EXACT request-year balance row — no cross-year fallback, so
        // a deduction/refund can never land on a different year's balance.
        // lockForUpdate: khoá hàng số dư trong suốt transaction để read-modify-write
        // không bị mất cập nhật khi 2 đơn KHÁC nhau của cùng NV được duyệt song song.
        $balance = LeaveBalance::where('employee_id', $leave->employee_id)
            ->where('leave_type_id', $leave->leave_type_id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        $before = $balance ? (float) $balance->remaining_days : 0.0;

        if ($balance) {
            if ($type === 'DEDUCT') {
                $balance->update([
                    'used_days' => (float) $balance->used_days + $days,
                    'remaining_days' => (float) $balance->remaining_days - $days,
                ]);
            } else { // REFUND
                $balance->update([
                    'used_days' => max(0, (float) $balance->used_days - $days),
                    'remaining_days' => (float) $balance->remaining_days + $days,
                ]);
            }
        }

        $after = $type === 'DEDUCT' ? $before - $days : $before + $days;

        DB::table('leave_transactions')->insert(TenantContext::stamp([
            'employee_id' => $leave->employee_id,
            'leave_type_id' => $leave->leave_type_id,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => $type,
            'quantity' => $type === 'DEDUCT' ? -$days : $days,
            'before_balance' => $before,
            'after_balance' => $after,
            'reference_id' => $leave->id,
            'reference_type' => 'LEAVE_REQUEST',
            'reason' => $reason,
            'tenant_id' => TenantContext::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
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

    private function conflict(array $violations, string $resourceName): JsonResponse
    {
        return response()->json([
            'status' => 409,
            'message' => "Không thể xóa {$resourceName} do vi phạm ràng buộc nghiệp vụ",
            'data' => ['violations' => $violations],
        ], 409);
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
