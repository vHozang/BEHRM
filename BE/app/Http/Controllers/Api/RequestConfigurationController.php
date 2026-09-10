<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class RequestConfigurationController extends Controller
{
    public function requestTypes(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $query = DB::table('request_types')
            ->where('tenant_id', TenantContext::id())
            ->orderBy('request_type_name');
        if ($request->filled('status')) {
            $query->whereRaw('UPPER(COALESCE(status, ?)) = ?', ['ACTIVE', strtoupper((string) $request->query('status'))]);
        }
        $page = $query->paginate($perPage);
        $items = $page->items();
        $flowIds = collect($items)->pluck('approval_flow_id')->filter()->unique();
        $flowsById = DB::table('approval_flows')
            ->where('tenant_id', TenantContext::id())
            ->whereIn('id', $flowIds)
            ->get(['id', 'flow_name', 'status'])
            ->keyBy('id');
        $legacyFlows = DB::table('approval_flows')
            ->where('tenant_id', TenantContext::id())
            ->whereIn('request_type_id', collect($items)->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'request_type_id', 'flow_name', 'status'])
            ->keyBy('request_type_id');
        foreach ($items as $item) {
            $item->approval_flow = $flowsById->get((int) $item->approval_flow_id)
                ?? $legacyFlows->get((int) $item->id);
            if (! $item->approval_flow_id && $item->approval_flow) {
                $item->approval_flow_id = $item->approval_flow->id;
            }
        }

        return $this->page($items, $page, 'Request types list');
    }

    public function storeRequestType(Request $request): JsonResponse
    {
        $this->requireCapability($request, 'requests.types.manage');
        $data = $this->validateRequestType($request);
        $now = now();
        $id = DB::transaction(function () use ($data, $now): int {
            $id = DB::table('request_types')->insertGetId([
                ...$data,
                'tenant_id' => TenantContext::id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->mirrorRequestTypeFlow($id, $data['approval_flow_id'] ?? null);

            return $id;
        });

        return response()->json(['status' => 201, 'message' => 'Đã tạo loại yêu cầu', 'data' => DB::table('request_types')->find($id)], 201);
    }

    public function updateRequestType(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'requests.types.manage');
        $current = DB::table('request_types')->where('tenant_id', TenantContext::id())->where('id', $id)->first();
        if (! $current) {
            return $this->notFound();
        }
        $data = $this->validateRequestType($request, $id, true);
        DB::transaction(function () use ($id, $data, $current): void {
            DB::table('request_types')->where('tenant_id', TenantContext::id())->where('id', $id)
                ->update([...$data, 'updated_at' => now()]);
            if (array_key_exists('approval_flow_id', $data)) {
                if ($current->approval_flow_id && (int) $current->approval_flow_id !== (int) ($data['approval_flow_id'] ?? 0)) {
                    DB::table('approval_flows')->where('tenant_id', TenantContext::id())
                        ->where('id', $current->approval_flow_id)
                        ->where('request_type_id', $id)
                        ->update(['request_type_id' => null, 'updated_at' => now()]);
                }
                $this->mirrorRequestTypeFlow($id, $data['approval_flow_id']);
            }
        });

        return $this->ok(DB::table('request_types')->where('tenant_id', TenantContext::id())->find($id), 'Đã cập nhật loại yêu cầu');
    }

    public function destroyRequestType(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'requests.types.manage');
        $row = DB::table('request_types')->where('tenant_id', TenantContext::id())->where('id', $id)->first();
        if (! $row) {
            return $this->notFound();
        }
        if (DB::table('requests')->where('tenant_id', TenantContext::id())->where('request_type_id', $id)->exists()) {
            return $this->conflict('Loại yêu cầu đã có đơn phát sinh; hãy chuyển sang INACTIVE');
        }
        DB::transaction(function () use ($id): void {
            DB::table('approval_flows')->where('tenant_id', TenantContext::id())
                ->where('request_type_id', $id)
                ->update(['request_type_id' => null, 'updated_at' => now()]);
            DB::table('request_types')->where('tenant_id', TenantContext::id())->where('id', $id)->delete();
        });

        return $this->ok(['id' => $id], 'Đã xóa loại yêu cầu');
    }

    public function approvalFlows(Request $request): JsonResponse
    {
        $this->requireCapability($request, 'requests.flows.manage');
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $page = DB::table('approval_flows')
            ->where('tenant_id', TenantContext::id())
            ->orderByDesc('id')
            ->paginate($perPage);
        $items = $page->items();
        $flowIds = collect($items)->pluck('id');
        $steps = DB::table('approval_steps as s')
            ->leftJoin('roles as r', function ($join): void {
                $join->on('r.id', '=', 's.approver_role_id')->on('r.tenant_id', '=', 's.tenant_id');
            })
            ->leftJoin('employees as e', function ($join): void {
                $join->on('e.id', '=', 's.approver_user_id')->on('e.tenant_id', '=', 's.tenant_id');
            })
            ->where('s.tenant_id', TenantContext::id())
            ->whereIn('s.approval_flow_id', $flowIds)
            ->orderBy('s.step_order')
            ->get(['s.*', 'r.role_name', 'r.role_code', 'e.full_name as approver_name'])
            ->groupBy('approval_flow_id');
        $types = DB::table('request_types')
            ->where('tenant_id', TenantContext::id())
            ->whereIn('approval_flow_id', $flowIds)
            ->get(['id', 'request_type_name', 'approval_flow_id'])
            ->keyBy('approval_flow_id');
        foreach ($items as $item) {
            $item->steps = array_values(($steps->get($item->id) ?? collect())->all());
            $item->request_type = $types->get($item->id);
        }

        return $this->page($items, $page, 'Approval flows list');
    }

    public function storeApprovalFlow(Request $request): JsonResponse
    {
        $this->requireCapability($request, 'requests.flows.manage');
        $data = $this->validateFlow($request);
        $id = DB::transaction(function () use ($data): int {
            $id = DB::table('approval_flows')->insertGetId([
                'request_type_id' => $data['request_type_id'] ?? null,
                'flow_name' => $data['flow_name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE',
                'tenant_id' => TenantContext::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->syncFlowSteps($id, $data['steps']);
            if (! empty($data['request_type_id'])) {
                $this->mirrorRequestTypeFlow((int) $data['request_type_id'], $id);
            }

            return $id;
        });

        return response()->json(['status' => 201, 'message' => 'Đã tạo luồng phê duyệt', 'data' => ['id' => $id]], 201);
    }

    public function updateApprovalFlow(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'requests.flows.manage');
        $flow = DB::table('approval_flows')->where('tenant_id', TenantContext::id())->where('id', $id)->first();
        if (! $flow) {
            return $this->notFound();
        }
        $data = $this->validateFlow($request, true, $id);
        if (array_key_exists('steps', $data) && DB::table('approval_histories as h')
            ->join('approval_steps as s', 's.id', '=', 'h.step_id')
            ->where('s.tenant_id', TenantContext::id())
            ->where('s.approval_flow_id', $id)
            ->exists()) {
            return $this->conflict('Luồng đã có lịch sử duyệt; không thể thay đổi các bước');
        }

        DB::transaction(function () use ($id, $data, $flow): void {
            $fields = collect($data)->only(['request_type_id', 'flow_name', 'description', 'status'])->toArray();
            if ($fields !== []) {
                DB::table('approval_flows')->where('tenant_id', TenantContext::id())->where('id', $id)
                    ->update([...$fields, 'updated_at' => now()]);
            }
            if (array_key_exists('steps', $data)) {
                $this->syncFlowSteps($id, $data['steps']);
            }
            if (array_key_exists('request_type_id', $data)) {
                if ($flow->request_type_id && (int) $flow->request_type_id !== (int) ($data['request_type_id'] ?? 0)) {
                    DB::table('request_types')->where('tenant_id', TenantContext::id())
                        ->where('id', $flow->request_type_id)
                        ->where('approval_flow_id', $id)
                        ->update(['approval_flow_id' => null, 'updated_at' => now()]);
                }
                if ($data['request_type_id']) {
                    $this->mirrorRequestTypeFlow((int) $data['request_type_id'], $id);
                }
            }
        });

        return $this->ok(['id' => $id], 'Đã cập nhật luồng phê duyệt');
    }

    public function destroyApprovalFlow(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'requests.flows.manage');
        if (DB::table('request_types')->where('tenant_id', TenantContext::id())->where('approval_flow_id', $id)->exists()
            || DB::table('approval_flows')->where('tenant_id', TenantContext::id())->where('id', $id)->whereNotNull('request_type_id')->exists()) {
            return $this->conflict('Luồng đang được một loại yêu cầu sử dụng');
        }
        $stepIds = DB::table('approval_steps')->where('tenant_id', TenantContext::id())->where('approval_flow_id', $id)->pluck('id');
        if (DB::table('approval_histories')->whereIn('step_id', $stepIds)->exists()) {
            return $this->conflict('Luồng đã có lịch sử duyệt');
        }
        DB::transaction(function () use ($id): void {
            DB::table('approval_steps')->where('tenant_id', TenantContext::id())->where('approval_flow_id', $id)->delete();
            DB::table('approval_flows')->where('tenant_id', TenantContext::id())->where('id', $id)->delete();
        });

        return $this->ok(['id' => $id], 'Đã xóa luồng phê duyệt');
    }

    private function validateRequestType(Request $request, ?int $id = null, bool $partial = false): array
    {
        $rules = [
            'request_type_code' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'request_type_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'approval_flow_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:30'],
        ];
        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $id): void {
            if ($request->filled('request_type_code')) {
                $exists = DB::table('request_types')->where('tenant_id', TenantContext::id())
                    ->whereRaw('UPPER(request_type_code) = ?', [strtoupper((string) $request->input('request_type_code'))])
                    ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
                if ($exists) {
                    $validator->errors()->add('request_type_code', 'Mã loại yêu cầu đã tồn tại');
                }
            }
            if ($request->filled('approval_flow_id') && ! DB::table('approval_flows')
                ->where('tenant_id', TenantContext::id())->where('id', $request->input('approval_flow_id'))->exists()) {
                $validator->errors()->add('approval_flow_id', 'Luồng phê duyệt không thuộc công ty hiện tại');
            }
            if ($request->filled('approval_flow_id')) {
                $flowId = (int) $request->input('approval_flow_id');
                $usedByCanonical = DB::table('request_types')->where('tenant_id', TenantContext::id())
                    ->where('approval_flow_id', $flowId)
                    ->when($id, fn ($query) => $query->where('id', '<>', $id))
                    ->exists();
                $usedByLegacy = DB::table('approval_flows')->where('tenant_id', TenantContext::id())
                    ->where('id', $flowId)
                    ->whereNotNull('request_type_id')
                    ->when($id, fn ($query) => $query->where('request_type_id', '<>', $id))
                    ->exists();
                if ($usedByCanonical || $usedByLegacy) {
                    $validator->errors()->add('approval_flow_id', 'Luồng phê duyệt đã được gán cho loại yêu cầu khác');
                }
            }
        });
        $data = $validator->validate();
        if (isset($data['request_type_code'])) {
            $data['request_type_code'] = Str::upper(trim($data['request_type_code']));
        }

        return $data;
    }

    private function validateFlow(Request $request, bool $partial = false, ?int $flowId = null): array
    {
        $rules = [
            'flow_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:30'],
            'request_type_id' => ['nullable', 'integer'],
            'steps' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'steps.*.approver_role_id' => ['nullable', 'integer'],
            'steps.*.approver_user_id' => ['nullable', 'integer'],
        ];
        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $flowId): void {
            if ($request->filled('request_type_id') && ! DB::table('request_types')
                ->where('tenant_id', TenantContext::id())->where('id', $request->input('request_type_id'))->exists()) {
                $validator->errors()->add('request_type_id', 'Loại yêu cầu không thuộc công ty hiện tại');
            }
            if ($request->filled('request_type_id')) {
                $typeId = (int) $request->input('request_type_id');
                $canonicalFlow = DB::table('request_types')->where('tenant_id', TenantContext::id())
                    ->where('id', $typeId)->value('approval_flow_id');
                $legacyFlow = DB::table('approval_flows')->where('tenant_id', TenantContext::id())
                    ->where('request_type_id', $typeId)
                    ->when($flowId, fn ($query) => $query->where('id', '<>', $flowId))
                    ->exists();
                if (($canonicalFlow && (int) $canonicalFlow !== (int) $flowId) || $legacyFlow) {
                    $validator->errors()->add('request_type_id', 'Loại yêu cầu đã có luồng phê duyệt khác');
                }
            }
            foreach ((array) $request->input('steps', []) as $index => $step) {
                $roleId = $step['approver_role_id'] ?? null;
                $userId = $step['approver_user_id'] ?? null;
                if (! $roleId && ! $userId) {
                    $validator->errors()->add("steps.{$index}", 'Mỗi bước phải chọn vai trò hoặc người duyệt');
                }
                if ($roleId && ! DB::table('roles')->where('tenant_id', TenantContext::id())->where('id', $roleId)->exists()) {
                    $validator->errors()->add("steps.{$index}.approver_role_id", 'Vai trò không thuộc công ty hiện tại');
                }
                if ($userId && ! DB::table('employees')->where('tenant_id', TenantContext::id())->where('id', $userId)->exists()) {
                    $validator->errors()->add("steps.{$index}.approver_user_id", 'Người duyệt không thuộc công ty hiện tại');
                }
            }
        });

        return $validator->validate();
    }

    private function syncFlowSteps(int $flowId, array $steps): void
    {
        DB::table('approval_steps')->where('tenant_id', TenantContext::id())->where('approval_flow_id', $flowId)->delete();
        foreach (array_values($steps) as $index => $step) {
            DB::table('approval_steps')->insert([
                'approval_flow_id' => $flowId,
                'step_order' => $index + 1,
                'approver_role_id' => $step['approver_role_id'] ?? null,
                'approver_user_id' => $step['approver_user_id'] ?? null,
                'status' => 'ACTIVE',
                'tenant_id' => TenantContext::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function mirrorRequestTypeFlow(int $requestTypeId, ?int $flowId): void
    {
        DB::table('request_types')->where('tenant_id', TenantContext::id())->where('id', $requestTypeId)
            ->update(['approval_flow_id' => $flowId, 'updated_at' => now()]);
        DB::table('approval_flows')->where('tenant_id', TenantContext::id())->where('request_type_id', $requestTypeId)
            ->where('id', '!=', $flowId ?? 0)->update(['request_type_id' => null, 'updated_at' => now()]);
        if ($flowId) {
            DB::table('approval_flows')->where('tenant_id', TenantContext::id())->where('id', $flowId)
                ->update(['request_type_id' => $requestTypeId, 'updated_at' => now()]);
        }
    }

    private function requireCapability(Request $request, string $capability): void
    {
        abort_unless(AccessControl::accessHasCapability((array) $request->attributes->get('access', []), $capability), 403);
    }

    private function page(array $items, object $page, string $message): JsonResponse
    {
        return $this->ok(['items' => $items, 'pagination' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]], $message);
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => 'Record not found', 'data' => null], 404);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['status' => 409, 'message' => $message, 'data' => null], 409);
    }
}
