<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Services\AttendanceAccess;
use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class RequestApprovalController extends Controller
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = ApprovalRequest::with(['requester:id,full_name,employee_code'])
            ->orderByDesc('id');
        $this->access->scopeEmployeeResource($query, $request, 'requests', 'requester_id');

        foreach (['requester_id', 'request_type_id', 'status', 'priority'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        $page = $query->paginate($perPage);

        // can_approve cho từng yêu cầu: FE chỉ hiện nút Duyệt/Từ chối cho ĐÚNG người
        // duyệt bước hiện tại (backend vẫn chặn 403 nếu sai). ponytail: N+1 nhỏ (list ≤100,
        // không phải hot path); gom query nếu về sau danh sách duyệt phình to.
        $approverId = $request->attributes->get('auth_employee_id');
        $items = $page->items();
        $typeMap = DB::table('request_types')
            ->where('tenant_id', TenantContext::id())
            ->whereIn('id', collect($items)->pluck('request_type_id')->filter()->unique())
            ->get(['id', 'request_type_code', 'request_type_name', 'category'])
            ->keyBy('id');
        $stepMap = DB::table('approval_steps')
            ->where('tenant_id', TenantContext::id())
            ->whereIn('id', collect($items)->pluck('current_step_id')->filter()->unique())
            ->get(['id', 'step_order', 'approver_role_id', 'approver_user_id'])
            ->keyBy('id');
        foreach ($items as $item) {
            $type = $typeMap->get((int) $item->request_type_id);
            $item->request_type = $type;
            $item->request_type_code = $type->request_type_code ?? null;
            $item->request_type_name = $type->request_type_name ?? null;
            $step = $stepMap->get((int) $item->current_step_id);
            $item->current_step = $step ? (int) $step->step_order : null;
            $pending = in_array($item->status, ['PENDING', 'IN_PROGRESS', 'CHỜ_DUYỆT', 'ĐANG_XỬ_LÝ', 'pending'], true);
            $notSelf = $approverId !== null && (int) $item->requester_id !== (int) $approverId;
            $item->can_approve = $pending && $notSelf
                && AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'requests.approve')
                && $this->access->canAccessEmployee($request, (int) $item->requester_id, true)
                && $this->approverHoldsStepRole($item->current_step_id, $approverId);
        }

        return $this->ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Requests list');
    }

    /**
     * POST /requests/{id}/attachments — đính kèm chứng từ cho đơn (giấy bác sĩ khi
     * nghỉ ốm, giấy ĐKKH khi nghỉ cưới, vé xe khi công tác…). Bảng request_attachments
     * đã có sẵn nhưng chưa có API nên trước giờ không ai đính kèm được gì.
     * Dùng lại đúng pattern upload CV ứng viên (Storage local + lưu đường dẫn).
     */
    public function uploadAttachment(Request $request, int $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $req = ApprovalRequest::find($id);
        if (! $req) {
            return $this->notFound();
        }
        // Người TẠO đơn, hoặc HR/Admin (người xử lý hồ sơ) mới được đính kèm.
        // Không dùng approverHoldsStepRole ở đây: nó chỉ đúng cho người duyệt ĐÚNG BƯỚC
        // hiện tại — đơn đã duyệt xong thì HR vẫn cần bổ sung chứng từ vào hồ sơ.
        $callerId = (int) $request->attributes->get('auth_employee_id');
        if (! $this->access->canAccessEmployee($request, (int) $req->requester_id)
            || ((int) $req->requester_id !== $callerId && ! $this->canManageRequests($request))) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền đính kèm cho đơn này', 'data' => null], 403);
        }

        $file = $request->file('file');
        $path = $file->store("request-attachments/{$id}");
        $attId = DB::table('request_attachments')->insertGetId(TenantContext::stamp([
            'request_id' => $id,
            'uploaded_by' => $callerId,
            'file_name' => $file->getClientOriginalName(),
            'file_url' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => (string) $file->getSize(),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return $this->ok(DB::table('request_attachments')->find($attId), 'Đã đính kèm chứng từ');
    }

    /** Người xử lý hồ sơ đơn từ: ADMIN / HR (hoặc super-admin). */
    private function canManageRequests(Request $request): bool
    {
        return AccessControl::accessHasCapability(
            (array) $request->attributes->get('access', []),
            'requests.approve',
        );
    }

    /** GET /requests/{id}/attachments — danh sách chứng từ của đơn. */
    public function attachments(Request $request, int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::find($id);
        if (! $approvalRequest || ! $this->access->canAccessEmployee($request, (int) $approvalRequest->requester_id)) {
            return $this->notFound();
        }
        $rows = DB::table('request_attachments')
            ->where('request_id', $id)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->orderByDesc('id')->get();

        return $this->ok($rows, 'Danh sách chứng từ đính kèm');
    }

    /** GET /requests/{id}/attachments/{attachmentId} — tải chứng từ. */
    public function downloadAttachment(Request $request, int $id, int $attachmentId)
    {
        $approvalRequest = ApprovalRequest::find($id);
        if (! $approvalRequest || ! $this->access->canAccessEmployee($request, (int) $approvalRequest->requester_id)) {
            return $this->notFound();
        }
        $att = DB::table('request_attachments')->where('id', $attachmentId)->where('request_id', $id)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->first();
        if (! $att || ! \Illuminate\Support\Facades\Storage::exists($att->file_url)) {
            return $this->notFound('Không tìm thấy tệp đính kèm');
        }

        return \Illuminate\Support\Facades\Storage::download($att->file_url, $att->file_name);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'request_type_id' => 'required|exists:request_types,id',
            'title' => 'required|string|max:255',
        ], [
            'request_type_id.required' => 'Loại yêu cầu là bắt buộc',
            'title.required' => 'Tiêu đề yêu cầu là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        // Resolve approval flow from request type
        $requestType = DB::table('request_types')
            ->where('id', $request->input('request_type_id'))
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('request_types.tenant_id', TenantContext::id()))
            ->whereIn('status', ['ACTIVE', 'active', '1'])
            ->first();

        if (! $requestType) {
            return $this->validationError([
                'request_type_id' => ['Loại yêu cầu không tồn tại'],
            ]);
        }

        $columns = Schema::getColumnListing('requests');
        $data = collect($request->all())->only($columns)->toArray();
        $data['requester_id'] = (int) $request->attributes->get('auth_employee_id');
        $data['status'] = $request->boolean('save_as_draft') ? 'DRAFT' : 'PENDING';
        // Point current_step_id at the first approval step of the resolved flow
        // (the `requests` table has current_step_id, NOT current_step / approval_flow_id).
        $flowId = $requestType->approval_flow_id ?: DB::table('approval_flows')
            ->where('tenant_id', TenantContext::id())
            ->where('request_type_id', $requestType->id)
            ->orderBy('id')
            ->value('id');
        $data['current_step_id'] = $data['status'] === 'DRAFT'
            ? null
            : $this->firstStepId($flowId);
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $approvalRequest = ApprovalRequest::create($data);

        return response()->json([
            'status' => 201,
            'message' => 'Yêu cầu đã được tạo',
            'data' => $approvalRequest->fresh()->load('requester:id,full_name'),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::with([
            'requester:id,full_name,employee_code',
            'approvalHistories',
        ])->find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }
        if (! $this->access->canAccessEmployee($request, (int) $approvalRequest->requester_id)) {
            return $this->notFound();
        }

        $this->appendRequestType($approvalRequest);

        return $this->ok($approvalRequest, 'Request detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }

        $actorId = (int) $request->attributes->get('auth_employee_id');
        if ((int) $approvalRequest->requester_id !== $actorId) {
            return response()->json(['status' => 403, 'message' => 'Chỉ người tạo được sửa yêu cầu', 'data' => null], 403);
        }

        if (! in_array($approvalRequest->status, ['PENDING', 'CHỜ_DUYỆT'])) {
            return $this->validationError([
                'status' => ['Chỉ có thể sửa yêu cầu đang chờ duyệt'],
            ]);
        }

        if (DB::table('approval_histories')->where('request_id', $id)->exists()) {
            return $this->validationError(['status' => ['Yêu cầu đã có lịch sử duyệt nên không thể sửa']]);
        }

        $data = $request->validate([
            'request_type_id' => ['sometimes', 'integer'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'max:50'],
        ]);
        if (array_key_exists('request_type_id', $data)) {
            $type = DB::table('request_types')
                ->where('tenant_id', TenantContext::id())
                ->where('id', $data['request_type_id'])
                ->first();
            if (! $type) {
                return $this->validationError(['request_type_id' => ['Loại yêu cầu không tồn tại']]);
            }
            $flowId = $type->approval_flow_id ?: DB::table('approval_flows')
                ->where('tenant_id', TenantContext::id())
                ->where('request_type_id', $type->id)
                ->orderBy('id')
                ->value('id');
            $data['current_step_id'] = $this->firstStepId($flowId);
        }

        $approvalRequest->update($data);

        return $this->ok($approvalRequest->fresh(), 'Yêu cầu đã được cập nhật');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }

        if ((int) $approvalRequest->requester_id !== (int) $request->attributes->get('auth_employee_id')) {
            return response()->json(['status' => 403, 'message' => 'Chỉ người tạo được xóa bản nháp', 'data' => null], 403);
        }

        if ((string) $approvalRequest->status !== 'DRAFT'
            || DB::table('approval_histories')->where('request_id', $id)->exists()) {
            return $this->conflict(
                ['Chỉ có thể xóa bản nháp chưa có lịch sử duyệt'],
                'Yêu cầu'
            );
        }

        $approvalRequest->delete();

        return $this->ok(['id' => $id], 'Yêu cầu đã được xóa');
    }

    /**
     * POST /requests/{id}/approve — Duyệt yêu cầu.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }

        if (! in_array($approvalRequest->status, ['PENDING', 'IN_PROGRESS', 'CHỜ_DUYỆT', 'ĐANG_XỬ_LÝ'])) {
            return $this->validationError(['status' => ['Yêu cầu không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');

        if (! AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'requests.approve')
            || ! $this->access->canAccessEmployee($request, (int) $approvalRequest->requester_id, true)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền duyệt yêu cầu này', 'data' => null], 403);
        }

        // Self-approval guard — a requester cannot approve their own request.
        if ($approverId !== null && (int) $approvalRequest->requester_id === (int) $approverId) {
            return $this->validationError([
                'approver_id' => ['Người tạo yêu cầu không thể tự duyệt yêu cầu của mình'],
            ]);
        }

        // Approver-role authorization — if the current step pins an approver_role_id,
        // the approving employee must hold that role (active). When the step has no
        // approver_role_id (NULL / no flow configured), the self-approval guard above
        // is the only gate (preserves prior behavior).
        if (! $this->approverHoldsStepRole($approvalRequest->current_step_id, $approverId)) {
            return response()->json([
                'status' => 403,
                'message' => 'Bạn không có quyền duyệt bước này',
                'data' => null,
            ], 403);
        }

        DB::transaction(function () use ($approvalRequest, $request, $approverId) {
            // Record approval history (real columns: step_id, action_date).
            DB::table('approval_histories')->insert(TenantContext::stamp([
                'request_id' => $approvalRequest->id,
                'step_id' => $approvalRequest->current_step_id,
                'approver_id' => $approverId,
                'action' => 'APPROVED',
                'comment' => $request->input('comment'),
                'action_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            // Advance to the next step in the flow (by step_order), or finalize.
            $nextStepId = $this->nextStepId($approvalRequest->current_step_id);

            if ($nextStepId === null) {
                $approvalRequest->update(['status' => 'APPROVED']);
            } else {
                $approvalRequest->update([
                    'status' => 'IN_PROGRESS',
                    'current_step_id' => $nextStepId,
                ]);
            }
        });

        return $this->ok($approvalRequest->fresh()->load('approvalHistories'), 'Yêu cầu đã được duyệt');
    }

    /**
     * POST /requests/{id}/reject — Từ chối yêu cầu.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }

        if (! in_array($approvalRequest->status, ['PENDING', 'IN_PROGRESS', 'CHỜ_DUYỆT', 'ĐANG_XỬ_LÝ'], true)) {
            return $this->validationError(['status' => ['Yêu cầu không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');

        if (! AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'requests.approve')
            || ! $this->access->canAccessEmployee($request, (int) $approvalRequest->requester_id, true)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền từ chối yêu cầu này', 'data' => null], 403);
        }

        if ($approverId !== null && (int) $approvalRequest->requester_id === (int) $approverId) {
            return $this->validationError([
                'approver_id' => ['Người tạo yêu cầu không thể tự từ chối yêu cầu của mình'],
            ]);
        }

        // Từ chối là hành động đặc quyền lên bước duyệt — phải giữ đúng role của bước,
        // giống approve() (trước đây thiếu → bất kỳ ai không phải người tạo cũng từ chối được).
        if (! $this->approverHoldsStepRole($approvalRequest->current_step_id, $approverId)) {
            return response()->json([
                'status' => 403,
                'message' => 'Bạn không có quyền từ chối bước này',
                'data' => null,
            ], 403);
        }

        DB::transaction(function () use ($approvalRequest, $request, $approverId) {
            DB::table('approval_histories')->insert(TenantContext::stamp([
                'request_id' => $approvalRequest->id,
                'step_id' => $approvalRequest->current_step_id,
                'approver_id' => $approverId,
                'action' => 'REJECTED',
                'comment' => $request->input('comment'),
                'action_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            $approvalRequest->update(['status' => 'REJECTED']);
        });

        return $this->ok($approvalRequest->fresh(), 'Yêu cầu đã bị từ chối');
    }

    /**
     * POST /requests/{id}/cancel — Hủy yêu cầu (chỉ người tạo).
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }

        $currentUserId = $request->attributes->get('auth_employee_id');

        if ($approvalRequest->requester_id != $currentUserId) {
            return $this->validationError([
                'requester_id' => ['Chỉ người tạo yêu cầu mới có thể hủy'],
            ]);
        }

        if (! in_array($approvalRequest->status, ['PENDING', 'IN_PROGRESS', 'CHỜ_DUYỆT'])) {
            return $this->validationError([
                'status' => ['Không thể hủy yêu cầu đã được xử lý'],
            ]);
        }

        $approvalRequest->update(['status' => 'CANCELLED']);

        return $this->ok($approvalRequest->fresh(), 'Yêu cầu đã được hủy');
    }

    // ── Authorization Helpers ────────────────────────────

    /**
     * True if the approving employee may act on the given approval step.
     *
     * When the step pins an approver_role_id, the employee must hold that role
     * (an active employee_roles row for the same tenant whose effective/expiry
     * window covers today). When the step has no approver_role_id (or no step /
     * no flow configured), authorization is delegated to the caller's other
     * guards and this returns true.
     */
    private function approverHoldsStepRole($currentStepId, $approverId): bool
    {
        if (! $currentStepId || ! Schema::hasTable('approval_steps')) {
            return true;
        }

        $step = DB::table('approval_steps')
            ->where('id', $currentStepId)
            ->where('tenant_id', TenantContext::id())
            ->first(['approver_role_id', 'approver_user_id']);
        if (! $step) {
            return false;
        }
        if ($step->approver_user_id !== null) {
            return (int) $step->approver_user_id === (int) $approverId;
        }
        $roleId = $step->approver_role_id;

        // No role pinned on this step → preserve existing behavior.
        if (empty($roleId)) {
            return true;
        }

        // A role is required but we cannot identify the approver → deny.
        if ($approverId === null) {
            return false;
        }

        $today = now()->toDateString();

        return DB::table('employee_roles')
            ->where('employee_id', $approverId)
            ->where('role_id', $roleId)
            ->whereRaw('is_active = true')
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->where(fn ($q) => $q->whereNull('effective_date')->orWhere('effective_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today))
            ->exists();
    }

    // ── Flow Helpers ─────────────────────────────────────

    /**
     * First approval_steps.id for a flow (lowest step_order), tenant-scoped.
     * Returns null when the flow has no steps (single-approval request).
     */
    private function firstStepId($flowId): ?int
    {
        if (! $flowId || ! Schema::hasTable('approval_steps')) {
            return null;
        }

        $step = DB::table('approval_steps')
            ->where('approval_flow_id', $flowId)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('approval_steps.tenant_id', TenantContext::id()))
            ->orderBy('step_order')
            ->first(['id']);

        return $step ? (int) $step->id : null;
    }

    /**
     * The next step (by step_order) after the given current step in the same
     * flow, or null when the current step is the last (final approval).
     */
    private function nextStepId($currentStepId): ?int
    {
        if (! $currentStepId || ! Schema::hasTable('approval_steps')) {
            return null;
        }

        $current = DB::table('approval_steps')->where('id', $currentStepId)->first(['approval_flow_id', 'step_order']);

        if (! $current) {
            return null;
        }

        $next = DB::table('approval_steps')
            ->where('approval_flow_id', $current->approval_flow_id)
            ->where('step_order', '>', (int) $current->step_order)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('approval_steps.tenant_id', TenantContext::id()))
            ->orderBy('step_order')
            ->first(['id']);

        return $next ? (int) $next->id : null;
    }

    private function appendRequestType(ApprovalRequest $approvalRequest): void
    {
        $type = DB::table('request_types')
            ->where('tenant_id', TenantContext::id())
            ->where('id', $approvalRequest->request_type_id)
            ->first(['id', 'request_type_code', 'request_type_name', 'category']);
        $approvalRequest->setAttribute('request_type', $type);
        $approvalRequest->setAttribute('request_type_code', $type->request_type_code ?? null);
        $approvalRequest->setAttribute('request_type_name', $type->request_type_name ?? null);
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
