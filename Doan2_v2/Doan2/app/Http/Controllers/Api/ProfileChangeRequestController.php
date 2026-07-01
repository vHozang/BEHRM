<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Support\AuditLogger;
use App\Support\HrmConfig;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Đơn đề nghị thay đổi thông tin cá nhân (profile change requests).
 *
 * Các trường nhạy cảm (CCCD, MST, tài khoản ngân hàng, BHXH...) bị khóa với
 * nhân viên sau khi HR nhập từ hồ sơ lúc onboarding. Muốn đổi, nhân viên tạo
 * đơn; HR/quản trị duyệt thì giá trị mới mới được ghi vào hồ sơ. Tránh nhân
 * viên tự ý sửa gây sai lệch dữ liệu vận hành.
 */
class ProfileChangeRequestController extends Controller
{
    /**
     * Danh mục các trường được phép đề nghị thay đổi (tenant-configurable).
     * Mặc định = nhóm thông tin nhạy cảm; tenant có thể override qua HrmConfig
     * key `employee.request_change_fields` (mảng [{key,label}]).
     */
    private function fieldCatalog(): array
    {
        $default = [
            ['key' => 'id_number', 'label' => 'Số CCCD/CMND'],
            ['key' => 'id_issue_date', 'label' => 'Ngày cấp CCCD/CMND'],
            ['key' => 'id_issue_place', 'label' => 'Nơi cấp CCCD/CMND'],
            ['key' => 'tax_number', 'label' => 'Mã số thuế cá nhân'],
            ['key' => 'insurance_number', 'label' => 'Số sổ BHXH'],
            ['key' => 'bank_name', 'label' => 'Ngân hàng'],
            ['key' => 'bank_account', 'label' => 'Số tài khoản ngân hàng'],
            ['key' => 'permanent_address', 'label' => 'Địa chỉ thường trú'],
            ['key' => 'personal_phone', 'label' => 'Số điện thoại cá nhân'],
            ['key' => 'personal_email', 'label' => 'Email cá nhân'],
        ];

        $configured = HrmConfig::get('employee.request_change_fields', null);
        if (is_array($configured) && ! empty($configured)) {
            // Chỉ giữ các mục hợp lệ {key,label}.
            $clean = [];
            foreach ($configured as $f) {
                if (is_array($f) && ! empty($f['key'])) {
                    $clean[] = ['key' => (string) $f['key'], 'label' => (string) ($f['label'] ?? $f['key'])];
                }
            }
            if (! empty($clean)) {
                return $clean;
            }
        }

        return $default;
    }

    private function labelFor(string $key): ?string
    {
        foreach ($this->fieldCatalog() as $f) {
            if ($f['key'] === $key) {
                return $f['label'];
            }
        }

        return null;
    }

    /**
     * GET /profile-change-requests/fields — danh mục trường có thể đề nghị đổi.
     */
    public function fields(): JsonResponse
    {
        return $this->ok($this->fieldCatalog(), 'Requestable profile fields');
    }

    /**
     * True nếu người gọi là quản lý nhân sự (full access hoặc có module 'hr').
     */
    private function isManager(Request $request): bool
    {
        $access = $request->attributes->get('access');
        if (! is_array($access)) {
            return false;
        }
        if (! empty($access['full'])) {
            return true;
        }

        return in_array('hr', $access['modules'] ?? [], true);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = ProfileChangeRequest::with(['employee:id,full_name,employee_code', 'approver:id,full_name'])
            ->orderByDesc('id');

        // Non-managers chỉ thấy đơn của chính mình (phòng rò rỉ dữ liệu).
        if (! $this->isManager($request)) {
            $authId = (int) $request->attributes->get('auth_employee_id');
            $query->where('employee_id', $authId);
        } elseif ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
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
        ], 'Profile change requests list');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'changes' => 'required|array|min:1',
            'reason' => 'nullable|string',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'employee_id.exists' => 'Nhân viên không tồn tại',
            'changes.required' => 'Phải có ít nhất một trường cần thay đổi',
            'changes.min' => 'Phải có ít nhất một trường cần thay đổi',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = (int) $request->input('employee_id');

        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $employee = Employee::find($employeeId);
        if (! $employee) {
            return $this->notFound();
        }

        $catalogKeys = array_column($this->fieldCatalog(), 'key');
        $currentProfile = $this->decodeProfile($employee->profile);

        // Build the authoritative changes record server-side: only whitelisted
        // fields, with old value resolved from the current record and a trusted
        // label from the catalog. FE only supplies {field_key: new_value}.
        $changes = [];
        foreach ((array) $request->input('changes') as $key => $newValue) {
            $key = (string) $key;
            if (! in_array($key, $catalogKeys, true)) {
                return $this->validationError([
                    'changes' => ["Trường '{$key}' không được phép đề nghị thay đổi"],
                ]);
            }
            $old = $this->currentValue($employee, $currentProfile, $key);
            $new = is_string($newValue) ? trim($newValue) : $newValue;
            if ((string) $old === (string) $new) {
                continue; // bỏ qua trường không thực sự đổi
            }
            $changes[$key] = [
                'label' => $this->labelFor($key),
                'old' => $old,
                'new' => $new,
            ];
        }

        if (empty($changes)) {
            return $this->validationError([
                'changes' => ['Không có thay đổi nào so với dữ liệu hiện tại'],
            ]);
        }

        $pcr = ProfileChangeRequest::create([
            'employee_id' => $employeeId,
            'changes' => $changes,
            'reason' => $request->input('reason'),
            'status' => 'PENDING',
        ]);

        return response()->json([
            'status' => 201,
            'message' => 'Đơn đề nghị thay đổi thông tin đã được tạo',
            'data' => $pcr->fresh()->load('employee:id,full_name'),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $pcr = ProfileChangeRequest::with(['employee:id,full_name,employee_code', 'approver:id,full_name'])->find($id);

        if (! $pcr) {
            return $this->notFound();
        }

        // Non-managers chỉ xem được đơn của mình.
        if (! $this->isManager($request)) {
            $authId = (int) $request->attributes->get('auth_employee_id');
            if ((int) $pcr->employee_id !== $authId) {
                return $this->notFound();
            }
        }

        return $this->ok($pcr, 'Profile change request detail');
    }

    /**
     * POST /profile-change-requests/{id}/approve — duyệt + ghi thay đổi vào hồ sơ.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $pcr = ProfileChangeRequest::find($id);

        if (! $pcr) {
            return $this->notFound();
        }

        if (! in_array($pcr->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
            return $this->validationError(['status' => ['Đơn không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');

        if ($approverId !== null && (int) $pcr->employee_id === (int) $approverId) {
            return $this->validationError([
                'approver_id' => ['Người tạo đơn không thể tự duyệt đơn của mình'],
            ]);
        }

        $employee = Employee::find($pcr->employee_id);
        if (! $employee) {
            return $this->validationError(['employee_id' => ['Nhân viên không còn tồn tại']]);
        }

        DB::transaction(function () use ($pcr, $employee, $approverId) {
            $before = $employee->getAttributes();

            $columns = Schema::getColumnListing('employees');
            $profile = $this->decodeProfile($employee->profile);

            foreach ((array) $pcr->changes as $key => $change) {
                $new = is_array($change) ? ($change['new'] ?? null) : $change;
                // Field nhạy cảm hầu hết nằm trong profile JSONB; nếu trùng tên
                // cột thật trên employees thì ghi vào cột.
                if (in_array($key, $columns, true)) {
                    $employee->{$key} = $new;
                } else {
                    $profile[$key] = $new;
                }
            }

            $employee->profile = $profile;
            $employee->save();

            AuditLogger::log('update', 'employees', (int) $employee->id, $before, $employee->getAttributes());

            $pcr->update([
                'status' => 'APPROVED',
                'approver_id' => $approverId,
                'decided_at' => now(),
            ]);
        });

        return $this->ok($pcr->fresh()->load('employee:id,full_name'), 'Đơn đã được duyệt và hồ sơ đã cập nhật');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $pcr = ProfileChangeRequest::find($id);

        if (! $pcr) {
            return $this->notFound();
        }

        if (! in_array($pcr->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
            return $this->validationError(['status' => ['Đơn không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');

        if ($approverId !== null && (int) $pcr->employee_id === (int) $approverId) {
            return $this->validationError([
                'approver_id' => ['Người tạo đơn không thể tự từ chối đơn của mình'],
            ]);
        }

        $pcr->update([
            'status' => 'REJECTED',
            'approver_id' => $approverId,
            'decision_comment' => $request->input('decision_comment') ?? $request->input('comment'),
            'decided_at' => now(),
        ]);

        return $this->ok($pcr->fresh(), 'Đơn đã bị từ chối');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $pcr = ProfileChangeRequest::find($id);

        if (! $pcr) {
            return $this->notFound();
        }

        $currentUserId = $request->attributes->get('auth_employee_id');

        if ($currentUserId !== null && (int) $pcr->employee_id !== (int) $currentUserId) {
            return $this->validationError([
                'employee_id' => ['Chỉ người tạo đơn mới có thể hủy'],
            ]);
        }

        if (! in_array($pcr->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
            return $this->validationError([
                'status' => ['Không thể hủy đơn đã được xử lý'],
            ]);
        }

        $pcr->update(['status' => 'CANCELLED']);

        return $this->ok($pcr->fresh(), 'Đơn đã được hủy');
    }

    // ── Helpers ──────────────────────────────────────────

    private function decodeProfile($profile): array
    {
        if (! $profile) {
            return [];
        }
        $decoded = is_string($profile) ? json_decode($profile, true) : (array) $profile;

        return is_array($decoded) ? $decoded : [];
    }

    private function currentValue(Employee $employee, array $profile, string $key)
    {
        $columns = Schema::getColumnListing('employees');
        if (in_array($key, $columns, true)) {
            return $employee->{$key};
        }

        return $profile[$key] ?? null;
    }

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
}
