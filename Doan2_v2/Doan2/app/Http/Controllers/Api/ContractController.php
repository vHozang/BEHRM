<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Support\ContractRenderer;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = Contract::with([
            'employee:id,full_name,employee_code',
            'contractType:id,contract_type_name',
            'department:id,department_name',
        ])->orderByDesc('id');

        foreach (['employee_id', 'status', 'contract_type_id', 'department_id'] as $field) {
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
        ], 'Contracts list');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'start_date' => 'required|date',
            'contract_type_id' => 'nullable|exists:contract_types,id',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'employee_id.exists' => 'Nhân viên không tồn tại',
            'start_date.required' => 'Ngày bắt đầu hợp đồng là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        // Entity targeting: a supplied legal_entity_id must belong to the
        // current tenant. If absent, the BelongsToTenant trait defaults it.
        if ($request->filled('legal_entity_id')) {
            $entityId = (int) $request->input('legal_entity_id');
            if (! LegalEntity::find($entityId)) {
                return $this->validationError([
                    'legal_entity_id' => ['legal_entity_id không thuộc công ty hiện tại'],
                ]);
            }
        }

        // department_id/position_id must belong to the current tenant (bare
        // `exists`/no-validation would otherwise accept foreign-tenant ids).
        if ($request->filled('department_id') && ! TenantContext::ownsRow('departments', $request->input('department_id'))) {
            return $this->validationError(['department_id' => ['Phòng ban không thuộc công ty hiện tại']]);
        }
        if ($request->filled('position_id') && ! TenantContext::ownsRow('positions', $request->input('position_id'))) {
            return $this->validationError(['position_id' => ['Chức vụ không thuộc công ty hiện tại']]);
        }

        // Check no active contract for this employee
        $employee = Employee::find($request->input('employee_id'));
        if ($employee && $employee->hasActiveContract()) {
            return $this->validationError([
                'employee_id' => ['Nhân viên đã có hợp đồng đang hiệu lực'],
            ]);
        }

        $columns = Schema::getColumnListing('contracts');
        $data = collect($request->all())->only($columns)->toArray();
        $data['status'] = $data['status'] ?? 'ACTIVE';
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $contract = Contract::create($data);

        // Sync employee's department/position from contract only if contract status is 'ACTIVE'
        if ($data['status'] === 'ACTIVE') {
            $syncData = [];
            if (! empty($data['department_id'])) {
                $syncData['department_id'] = $data['department_id'];
            }
            if (! empty($data['position_id'])) {
                $syncData['position_id'] = $data['position_id'];
            }
            if (! empty($syncData)) {
                $employee->update($syncData);
            }
        }

        return response()->json([
            'status' => 201,
            'message' => 'Hợp đồng đã được tạo',
            'data' => $contract->fresh()->load([
                'employee:id,full_name,employee_code',
                'contractType:id,contract_type_name',
                'department:id,department_name',
            ]),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $contract = Contract::with([
            'employee:id,full_name,employee_code',
            'contractType:id,contract_type_name',
            'department:id,department_name',
        ])->find($id);

        if (! $contract) {
            return $this->notFound();
        }

        return $this->ok($contract, 'Contract detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contract = Contract::find($id);

        if (! $contract) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'sometimes|required|exists:employees,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'contract_type_id' => 'nullable|exists:contract_types,id',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:positions,id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        if ($request->filled('department_id') && ! TenantContext::ownsRow('departments', $request->input('department_id'))) {
            return $this->validationError(['department_id' => ['Phòng ban không thuộc công ty hiện tại']]);
        }
        if ($request->filled('position_id') && ! TenantContext::ownsRow('positions', $request->input('position_id'))) {
            return $this->validationError(['position_id' => ['Chức vụ không thuộc công ty hiện tại']]);
        }

        $oldValues = $contract->toArray();

        $columns = Schema::getColumnListing('contracts');
        $data = collect($request->except(['id', 'created_at', 'updated_at']))->only($columns)->toArray();

        $contract->update($data);

        // Log changes
        $newValues = $contract->fresh()->toArray();
        $changes = [];
        foreach ($data as $key => $value) {
            if (($oldValues[$key] ?? null) !== ($newValues[$key] ?? null)) {
                $changes[$key] = [
                    'old' => $oldValues[$key] ?? null,
                    'new' => $newValues[$key] ?? null,
                ];
            }
        }

        if (! empty($changes) && Schema::hasTable('contract_change_logs')) {
            foreach ($changes as $field => $change) {
                DB::table('contract_change_logs')->insert(TenantContext::stamp([
                    'contract_id' => $id,
                    'employee_id' => $contract->employee_id,
                    'change_type' => 'UPDATE_'.strtoupper($field),
                    'old_value' => is_array($change['old']) ? json_encode($change['old']) : (string) $change['old'],
                    'new_value' => is_array($change['new']) ? json_encode($change['new']) : (string) $change['new'],
                    'reason' => 'Contract updated',
                    'meta' => json_encode(['changed_by' => $request->attributes->get('auth_employee_id')]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        // Check if contract status transitioned to 'ACTIVE'
        $statusActivated = false;
        if (array_key_exists('status', $changes) && $changes['status']['new'] === 'ACTIVE' && ($changes['status']['old'] ?? null) !== 'ACTIVE') {
            $statusActivated = true;
        }

        // Sync department/position to the employee ONLY if contract status is 'ACTIVE'
        if ($contract->status === 'ACTIVE' && $contract->employee) {
            $syncData = [];

            // If it transitioned to ACTIVE (e.g. from INACTIVE), sync unconditionally
            if ($statusActivated) {
                if ($contract->department_id) {
                    $syncData['department_id'] = $contract->department_id;
                }
                if ($contract->position_id) {
                    $syncData['position_id'] = $contract->position_id;
                }
            } else {
                // Otherwise, only sync if the fields themselves changed
                if (array_key_exists('department_id', $changes)) {
                    $syncData['department_id'] = $newValues['department_id'];
                }
                if (array_key_exists('position_id', $changes)) {
                    $syncData['position_id'] = $newValues['position_id'];
                }
            }

            if (! empty($syncData)) {
                $contract->employee->update($syncData);
            }
        }

        return $this->ok(
            $contract->fresh()->load([
                'employee:id,full_name,employee_code',
                'contractType:id,contract_type_name',
                'department:id,department_name',
            ]),
            'Hợp đồng đã được cập nhật'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $contract = Contract::find($id);

        if (! $contract) {
            return $this->notFound();
        }

        $violations = $contract->deletionViolations();

        if (! empty($violations)) {
            return $this->conflict($violations, 'Hợp đồng');
        }

        $contract->delete();

        return $this->ok(['id' => $id], 'Hợp đồng đã được xóa');
    }

    /**
     * POST /contracts/{id}/activate — Kích hoạt hợp đồng.
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        $contract = Contract::find($id);

        if (! $contract) {
            return $this->notFound();
        }

        if ($contract->isActive()) {
            return $this->validationError(['status' => ['Hợp đồng đã đang hiệu lực']]);
        }

        // Check if employee already has another active contract
        $employee = $contract->employee;
        if ($employee && $employee->hasActiveContract()) {
            return $this->validationError([
                'employee_id' => ['Nhân viên đã có hợp đồng khác đang hiệu lực'],
            ]);
        }

        $oldStatus = $contract->status;
        $contract->update(['status' => 'ACTIVE']);

        // Audit log
        if (Schema::hasTable('contract_change_logs')) {
            DB::table('contract_change_logs')->insert(TenantContext::stamp([
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id,
                'change_type' => 'ACTIVATE',
                'old_value' => (string) $oldStatus,
                'new_value' => 'ACTIVE',
                'reason' => 'Contract activated',
                'meta' => json_encode(['changed_by' => $request->attributes->get('auth_employee_id')]),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        // Sync org info
        $syncData = [];
        if ($contract->department_id) {
            $syncData['department_id'] = $contract->department_id;
        }
        if ($contract->position_id) {
            $syncData['position_id'] = $contract->position_id;
        }
        if (! empty($syncData) && $employee) {
            $employee->update($syncData);
        }

        return $this->ok(
            $contract->fresh()->load([
                'employee:id,full_name,employee_code',
                'contractType:id,contract_type_name',
                'department:id,department_name',
            ]),
            'Hợp đồng đã được kích hoạt'
        );
    }

    /**
     * POST /contracts/{id}/terminate — Kết thúc hợp đồng.
     */
    public function terminate(Request $request, int $id): JsonResponse
    {
        $contract = Contract::find($id);

        if (! $contract) {
            return $this->notFound();
        }

        // Guard: only an active contract can be terminated (idempotency / no
        // overwriting a historical termination date with an arbitrary one).
        if (! $contract->isActive()) {
            return $this->validationError(['status' => ['Chỉ có thể kết thúc hợp đồng đang hiệu lực']]);
        }

        $oldStatus = $contract->status;
        $employeeId = $contract->employee_id;

        DB::transaction(function () use ($contract, $request, $oldStatus, $employeeId) {
            $contract->update([
                'status' => 'TERMINATED',
                'end_date' => $request->input('end_date', now()->toDateString()),
            ]);

            // Audit log
            if (Schema::hasTable('contract_change_logs')) {
                DB::table('contract_change_logs')->insert(TenantContext::stamp([
                    'contract_id' => $contract->id,
                    'employee_id' => $contract->employee_id,
                    'change_type' => 'TERMINATE',
                    'old_value' => (string) $oldStatus,
                    'new_value' => 'TERMINATED',
                    'reason' => 'Contract terminated',
                    'meta' => json_encode(['changed_by' => $request->attributes->get('auth_employee_id')]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $employee = $contract->employee;

            // Drive the employee out of active state when no other active
            // contract remains, and revoke their access + pending leave.
            if ($employee && ! $employee->hasActiveContract()) {
                $employee->update(['status' => 'TERMINATED']);

                // Revoke outstanding sessions so the ex-employee loses API access.
                if (Schema::hasTable('api_tokens')) {
                    DB::table('api_tokens')->where('employee_id', $employeeId)->delete();
                }

                // Cancel pending/in-progress leave (no balance was deducted yet,
                // so no refund needed — only APPROVED leave holds balance).
                DB::table('leave_requests')
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', ['PENDING', 'IN_PROGRESS', 'CHỜ_DUYỆT', 'ĐANG_XỬ_LÝ'])
                    ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            }
        });

        return $this->ok(
            $contract->fresh()->load([
                'employee:id,full_name,employee_code',
                'contractType:id,contract_type_name',
                'department:id,department_name',
            ]),
            'Hợp đồng đã được kết thúc'
        );
    }

    // ═══════════════════════════════════════════════════════
    // DOCUMENT & SIGNING (mẫu hợp đồng + ký trên cổng)
    // ═══════════════════════════════════════════════════════

    /**
     * GET /contracts/{id}/render?template_id= — trộn dữ liệu vào mẫu, trả HTML
     * để xem/in. Dùng cho cả admin và nhân viên (xem HĐ của chính mình).
     */
    public function render(Request $request, int $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return $this->notFound();
        }

        [$content, $templateId] = $this->resolveTemplate($request);
        $data = ContractRenderer::buildData($contract);
        $html = ContractRenderer::render($content, $data);

        return $this->ok([
            'html' => $html,
            'template_id' => $templateId,
            'fields' => $data,
            'sign_status' => $contract->meta['sign_status'] ?? null,
            'signature' => $contract->meta['signature'] ?? null,
        ], 'Rendered contract');
    }

    /**
     * POST /contracts/{id}/send-sign — gửi hợp đồng cho nhân viên ký. Chụp lại
     * bản render hiện tại + đổi trạng thái ký sang PENDING_SIGN. (Admin/HR)
     */
    public function sendForSign(Request $request, int $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return $this->notFound();
        }

        [$content, $templateId] = $this->resolveTemplate($request);
        $data = ContractRenderer::buildData($contract);
        $html = ContractRenderer::render($content, $data);

        $meta = is_array($contract->meta) ? $contract->meta : [];
        $meta['sign_status'] = 'PENDING_SIGN';
        $meta['sign_template_id'] = $templateId;
        $meta['rendered_html'] = $html;
        $meta['sent_for_sign_at'] = now()->toIso8601String();
        unset($meta['sign_otp']); // reset OTP cũ nếu có
        $contract->update(['meta' => $meta]);

        return $this->ok($contract->fresh()->load('employee:id,full_name'), 'Đã gửi hợp đồng cho nhân viên ký');
    }

    /**
     * POST /contracts/{id}/request-otp — nhân viên yêu cầu mã OTP để ký HĐ của
     * mình. Lưu hash + hạn 10 phút. (Self-service — owner only.)
     *
     * Demo: trả mã trong response (thực tế sẽ gửi qua email/SMS của nhân viên).
     */
    public function requestOtp(Request $request, int $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return $this->notFound();
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $meta = is_array($contract->meta) ? $contract->meta : [];
        $meta['sign_otp'] = [
            'hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ];
        $contract->update(['meta' => $meta]);

        return $this->ok([
            'sent' => true,
            'expires_in' => 600,
            'dev_otp' => $otp, // DEMO: production sẽ gửi qua email/SMS, không trả về đây
        ], 'Đã gửi mã OTP xác nhận');
    }

    /**
     * POST /contracts/{id}/sign — nhân viên ký HĐ bằng OTP + chữ ký vẽ tay.
     * (Self-service — owner only.) Lưu ảnh chữ ký + nhật ký (thời điểm, IP).
     */
    public function sign(Request $request, int $id): JsonResponse
    {
        $contract = Contract::find($id);
        if (! $contract) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'otp' => 'required|string',
            'signature' => 'required|string', // data URL ảnh chữ ký
        ], [
            'otp.required' => 'Vui lòng nhập mã OTP',
            'signature.required' => 'Vui lòng ký tên',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $meta = is_array($contract->meta) ? $contract->meta : [];
        $otpRec = $meta['sign_otp'] ?? null;

        if (! is_array($otpRec) || empty($otpRec['hash'])) {
            return $this->validationError(['otp' => ['Chưa yêu cầu mã OTP hoặc mã đã hết hiệu lực']]);
        }
        if (now()->greaterThan(\Illuminate\Support\Carbon::parse($otpRec['expires_at']))) {
            return $this->validationError(['otp' => ['Mã OTP đã hết hạn, vui lòng yêu cầu lại']]);
        }
        if (! Hash::check($request->input('otp'), $otpRec['hash'])) {
            return $this->validationError(['otp' => ['Mã OTP không đúng']]);
        }

        $meta['signature'] = [
            'image' => $request->input('signature'),
            'signed_at' => now()->toIso8601String(),
            'ip' => $request->ip(),
            'method' => 'OTP+DRAW',
            'by_employee_id' => $request->attributes->get('auth_employee_id'),
        ];
        $meta['sign_status'] = 'SIGNED';
        unset($meta['sign_otp']);
        $contract->update(['meta' => $meta]);

        if (Schema::hasTable('contract_change_logs')) {
            DB::table('contract_change_logs')->insert(TenantContext::stamp([
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id,
                'change_type' => 'SIGN',
                'old_value' => 'PENDING_SIGN',
                'new_value' => 'SIGNED',
                'reason' => 'Nhân viên ký hợp đồng (OTP + chữ ký điện tử)',
                'meta' => json_encode(['ip' => $request->ip()]),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        return $this->ok($contract->fresh(), 'Đã ký hợp đồng thành công');
    }

    /** Resolve nội dung mẫu: template_id chỉ định → mẫu mặc định → mẫu code. */
    private function resolveTemplate(Request $request): array
    {
        $tpl = null;
        if ($request->filled('template_id')) {
            $tpl = ContractTemplate::find((int) $request->input('template_id'));
        }
        if (! $tpl) {
            $tpl = ContractTemplate::whereRaw('is_default = true')->first();
        }

        return [$tpl?->content ?? ContractRenderer::defaultTemplate(), $tpl?->id];
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
