<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class RequestApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = ApprovalRequest::with(['requester:id,full_name,employee_code'])
            ->orderByDesc('id');

        foreach (['requester_id', 'request_type_id', 'status', 'priority'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        $page = $query->paginate($perPage);

        return $this->ok([
            'items' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Requests list');
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
            ->first();

        if (! $requestType) {
            return $this->validationError([
                'request_type_id' => ['Loại yêu cầu không tồn tại'],
            ]);
        }

        $columns = Schema::getColumnListing('requests');
        $data = collect($request->all())->only($columns)->toArray();
        $data['requester_id'] = $data['requester_id'] ?? $request->attributes->get('auth_employee_id');
        $data['status'] = 'PENDING';
        // Point current_step_id at the first approval step of the resolved flow
        // (the `requests` table has current_step_id, NOT current_step / approval_flow_id).
        $data['current_step_id'] = $this->firstStepId($requestType->approval_flow_id ?? null);
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $approvalRequest = ApprovalRequest::create($data);

        return response()->json([
            'status' => 201,
            'message' => 'Yêu cầu đã được tạo',
            'data' => $approvalRequest->fresh()->load('requester:id,full_name'),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::with([
            'requester:id,full_name,employee_code',
            'approvalHistories',
        ])->find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }

        return $this->ok($approvalRequest, 'Request detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }

        if (! in_array($approvalRequest->status, ['PENDING', 'CHỜ_DUYỆT'])) {
            return $this->validationError([
                'status' => ['Chỉ có thể sửa yêu cầu đang chờ duyệt'],
            ]);
        }

        $columns = Schema::getColumnListing('requests');
        $data = collect($request->except(['id', 'created_at', 'updated_at', 'status', 'current_step', 'current_step_id']))
            ->only($columns)
            ->toArray();

        $approvalRequest->update($data);

        return $this->ok($approvalRequest->fresh(), 'Yêu cầu đã được cập nhật');
    }

    public function destroy(int $id): JsonResponse
    {
        $approvalRequest = ApprovalRequest::find($id);

        if (! $approvalRequest) {
            return $this->notFound();
        }

        if (! in_array($approvalRequest->status, ['PENDING', 'CHỜ_DUYỆT'])) {
            return $this->conflict(
                ['Chỉ có thể xóa yêu cầu đang chờ duyệt'],
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

        if ($approverId !== null && (int) $approvalRequest->requester_id === (int) $approverId) {
            return $this->validationError([
                'approver_id' => ['Người tạo yêu cầu không thể tự từ chối yêu cầu của mình'],
            ]);
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

        $roleId = DB::table('approval_steps')->where('id', $currentStepId)->value('approver_role_id');

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
            ->orderByRaw('(step_order)::int ASC')
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
            ->whereRaw('(step_order)::int > ?', [(int) $current->step_order])
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('approval_steps.tenant_id', TenantContext::id()))
            ->orderByRaw('(step_order)::int ASC')
            ->first(['id']);

        return $next ? (int) $next->id : null;
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
