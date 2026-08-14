<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business rules cho các bảng NHÓM A (GenericResourceController).
 *
 * Các bảng NHÓM B (employees, contracts, leave_requests, attendances,
 * salary_periods, recruitment_candidates, requests) đã có validation
 * riêng trong controller/model tương ứng.
 */
class ResourceBusinessRules
{
    /**
     * Check if a resource record can be safely deleted.
     *
     * @return array{can_delete: bool, violations: list<string>}
     */
    public static function canDelete(string $resource, int $id): array
    {
        $guards = self::deleteGuards()[$resource] ?? [];
        $violations = [];

        if ($resource === 'salary_components') {
            $code = DB::table('salary_components')->where('id', $id)
                ->when(TenantContext::hasTenant(), fn ($query) => $query->where('tenant_id', TenantContext::id()))
                ->value('code');
            if ($code && DB::table('salary_breakdowns')->where('tenant_id', TenantContext::id())
                ->whereRaw('upper(item_code) = ?', [strtoupper((string) $code)])->exists()) {
                $violations[] = 'Không thể xóa thành phần đã xuất hiện trong phiếu lương';
            }
        }

        foreach ($guards as $guard) {
            if (self::evaluateDeleteGuard($guard, $resource, $id)) {
                $violations[] = $guard['message'];
            }
        }

        return [
            'can_delete' => empty($violations),
            'violations' => $violations,
        ];
    }

    /**
     * Validate payload for store (create) operation.
     *
     * @return array{valid: bool, errors: array<string, list<string>>}
     */
    public static function validateStore(string $resource, array $payload): array
    {
        $rules = self::storeRules()[$resource] ?? [];

        $result = self::runValidation($rules, $payload, $resource, null);

        if ($resource === 'shift_assignments') {
            $isDayOff = self::booleanValue($payload['is_day_off'] ?? false);
            if (! $isDayOff && empty($payload['shift_type_id'])) {
                $result['errors']['shift_type_id'][] = 'Ca làm là bắt buộc khi không chọn OFF';
                $result['valid'] = false;
            }
            if (! empty($payload['expiry_date']) && ! empty($payload['effective_date'])
                && (string) $payload['expiry_date'] < (string) $payload['effective_date']) {
                $result['errors']['expiry_date'][] = 'Ngày kết thúc không được trước ngày hiệu lực';
                $result['valid'] = false;
            }
        }

        if (in_array($resource, ['employee_allowances', 'employee_deductions'], true)) {
            $result = self::validatePayrollAssignment($result, $resource, $payload, null);
        }

        return self::mergeForeignKeyValidation($result, $resource, $payload);
    }

    /**
     * Reject any *_id payload value that points at a row in another tenant.
     *
     * The DB has no application-level FK on most of these columns, and the
     * generic insert stamps the CALLER's tenant_id — so without this guard a
     * tenant can bind its rows to another tenant's employees/shift-types/etc.
     *
     * @param  array{valid: bool, errors: array<string, list<string>>}  $result
     * @return array{valid: bool, errors: array<string, list<string>>}
     */
    private static function mergeForeignKeyValidation(array $result, string $resource, array $payload): array
    {
        foreach (self::tenantForeignKeys()[$resource] ?? [] as $column => $targetTable) {
            if (! array_key_exists($column, $payload)) {
                continue;
            }

            $value = $payload[$column];
            if ($value === null || $value === '') {
                continue; // nullable FK
            }

            if (! \App\Support\TenantContext::ownsRow($targetTable, $value)) {
                $result['errors'][$column][] = "Giá trị {$column} không thuộc công ty hiện tại";
                $result['valid'] = false;
            }
        }

        return $result;
    }

    private static function booleanValue(mixed $value): bool
    {
        if ($value instanceof \Illuminate\Database\Query\Expression) {
            $value = $value->getValue(DB::connection()->getQueryGrammar());
        }

        return in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true);
    }

    /**
     * Per-table map of foreign-key columns that must resolve to a row owned by
     * the current tenant. Covers the generic-routed tables that carry FKs to
     * tenant-scoped entities (employees, shift_types, allowances, etc.).
     *
     * @return array<string, array<string, string>>  resource => [column => target_table]
     */
    private static function tenantForeignKeys(): array
    {
        return [
            'employee_allowances' => ['employee_id' => 'employees', 'allowance_id' => 'allowances'],
            'employee_deductions' => ['employee_id' => 'employees', 'deduction_id' => 'deductions'],
            'assets' => ['category_id' => 'asset_categories', 'supplier_id' => 'suppliers', 'location_id' => 'asset_locations'],
            'asset_incidents' => ['asset_id' => 'assets', 'assignment_id' => 'asset_assignments', 'reported_by' => 'employees', 'resolved_by' => 'employees'],
            'asset_maintenance' => ['asset_id' => 'assets'],
            'asset_locations' => ['department_id' => 'departments'],
            'service_tickets' => ['requester_id' => 'employees', 'category_id' => 'service_categories'],
            'news' => ['category_id' => 'news_categories'],
            'shift_assignments' => ['employee_id' => 'employees', 'shift_type_id' => 'shift_types', 'assigned_by' => 'employees'],
            'employee_roles' => ['employee_id' => 'employees', 'role_id' => 'roles'],
            'role_permissions' => ['role_id' => 'roles', 'permission_id' => 'permissions'],
            'dependents' => ['employee_id' => 'employees'],
            'leave_balances' => ['employee_id' => 'employees', 'leave_type_id' => 'leave_types'],
            'asset_assignments' => ['employee_id' => 'employees', 'asset_id' => 'assets'],
            'qualifications' => ['employee_id' => 'employees', 'qualification_type_id' => 'qualification_types'],
            'certificates' => ['employee_id' => 'employees'],
            'identity_documents' => ['employee_id' => 'employees', 'document_type_id' => 'document_types'],
            'social_insurance_info' => ['employee_id' => 'employees'],
            'insurance_claims' => ['employee_id' => 'employees', 'insurance_type_id' => 'insurance_types', 'bank_id' => 'banks'],
            'employment_histories' => ['employee_id' => 'employees', 'department_id' => 'departments', 'position_id' => 'positions'],
            'leave_advancement_requests' => ['employee_id' => 'employees'],
            'leave_advancement_config' => ['department_id' => 'departments', 'position_id' => 'positions', 'approval_flow_id' => 'approval_flows'],
        ];
    }

    /**
     * Validate payload for update operation.
     *
     * @return array{valid: bool, errors: array<string, list<string>>}
     */
    public static function validateUpdate(string $resource, int $id, array $payload): array
    {
        $rules = self::updateRules()[$resource] ?? [];

        $result = self::runValidation($rules, $payload, $resource, $id);

        if ($resource === 'shift_assignments') {
            $current = DB::table($resource)->where('id', $id)->first();
            $isDayOff = array_key_exists('is_day_off', $payload)
                ? self::booleanValue($payload['is_day_off'])
                : self::booleanValue($current->is_day_off ?? false);
            $shiftTypeId = array_key_exists('shift_type_id', $payload)
                ? $payload['shift_type_id']
                : ($current->shift_type_id ?? null);
            if (! $isDayOff && empty($shiftTypeId)) {
                $result['errors']['shift_type_id'][] = 'Ca làm là bắt buộc khi không chọn OFF';
                $result['valid'] = false;
            }

            $effectiveDate = $payload['effective_date'] ?? ($current->effective_date ?? null);
            $expiryDate = $payload['expiry_date'] ?? ($current->expiry_date ?? null);
            if ($expiryDate && $effectiveDate && (string) $expiryDate < (string) $effectiveDate) {
                $result['errors']['expiry_date'][] = 'Ngày kết thúc không được trước ngày hiệu lực';
                $result['valid'] = false;
            }
        }

        if (in_array($resource, ['employee_allowances', 'employee_deductions'], true)) {
            $result = self::validatePayrollAssignment($result, $resource, $payload, $id);
        }

        return self::mergeForeignKeyValidation($result, $resource, $payload);
    }

    // =========================================================================
    // DELETE GUARDS — Chỉ cho nhóm A (GenericResourceController)
    // =========================================================================

    private static function deleteGuards(): array
    {
        return [
            // ─── Departments ─────────────────────────────────────────
            'departments' => [
                [
                    'type' => 'has_related',
                    'table' => 'employees',
                    'foreign_key' => 'department_id',
                    'conditions' => [['column' => 'status', 'operator' => '!=', 'value' => 'DELETED']],
                    'message' => 'Không thể xóa phòng ban đang có nhân viên',
                ],
                [
                    'type' => 'has_related',
                    'table' => 'contracts',
                    'foreign_key' => 'department_id',
                    'conditions' => [['column' => 'status', 'operator' => 'IN', 'value' => ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC']]],
                    'message' => 'Không thể xóa phòng ban đang có hợp đồng hiệu lực',
                ],
                [
                    'type' => 'has_related',
                    'table' => 'recruitment_positions',
                    'foreign_key' => 'department_id',
                    'conditions' => [['column' => 'status', 'operator' => 'IN', 'value' => ['OPEN', 'ĐANG_TUYỂN']]],
                    'message' => 'Không thể xóa phòng ban đang có vị trí tuyển dụng mở',
                ],
            ],

            // ─── Positions ───────────────────────────────────────────
            'positions' => [
                [
                    'type' => 'has_related',
                    'table' => 'employees',
                    'foreign_key' => 'position_id',
                    'conditions' => [['column' => 'status', 'operator' => '!=', 'value' => 'DELETED']],
                    'message' => 'Không thể xóa chức vụ đang có nhân viên',
                ],
                [
                    'type' => 'has_related',
                    'table' => 'contracts',
                    'foreign_key' => 'position_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa chức vụ đang được tham chiếu bởi hợp đồng',
                ],
            ],

            'job_families' => [
                [
                    'type' => 'has_related',
                    'table' => 'positions',
                    'foreign_key' => 'job_family_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa nhóm chức danh đang có chức danh liên quan',
                ],
            ],

            // ─── Assets ──────────────────────────────────────────────
            'assets' => [
                [
                    'type' => 'has_related',
                    'table' => 'asset_assignments',
                    'foreign_key' => 'asset_id',
                    'conditions' => [['column' => 'status', 'operator' => 'NOT_IN', 'value' => ['RETURNED', 'ĐÃ_TRẢ', 'DA_TRA']]],
                    'message' => 'Không thể xóa tài sản đang được cấp phát cho nhân viên',
                ],
                [
                    'type' => 'has_related',
                    'table' => 'asset_incidents',
                    'foreign_key' => 'asset_id',
                    'conditions' => [['column' => 'status', 'operator' => 'IN', 'value' => ['OPEN', 'IN_PROGRESS', 'ĐANG_XỬ_LÝ']]],
                    'message' => 'Không thể xóa tài sản đang có sự cố chưa giải quyết',
                ],
            ],

            // ─── Roles ───────────────────────────────────────────────
            'roles' => [
                [
                    'type' => 'self_field',
                    'field' => 'is_system_role',
                    'operator' => '=',
                    'value' => true,
                    'message' => 'Không thể xóa vai trò hệ thống (system role)',
                ],
                [
                    'type' => 'has_related',
                    'table' => 'employee_roles',
                    'foreign_key' => 'role_id',
                    'conditions' => [['column' => 'is_active', 'operator' => '=', 'value' => true]],
                    'message' => 'Không thể xóa vai trò đang được gán cho nhân viên',
                ],
            ],

            // ─── Leave Types ─────────────────────────────────────────
            'leave_types' => [
                [
                    'type' => 'has_related',
                    'table' => 'leave_requests',
                    'foreign_key' => 'leave_type_id',
                    'conditions' => [['column' => 'status', 'operator' => 'IN', 'value' => ['PENDING', 'APPROVED', 'CHỜ_DUYỆT', 'ĐÃ_DUYỆT']]],
                    'message' => 'Không thể xóa loại phép đang có đơn nghỉ phép liên quan',
                ],
                [
                    'type' => 'has_related',
                    'table' => 'leave_balances',
                    'foreign_key' => 'leave_type_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa loại phép đang có số dư phép của nhân viên',
                ],
                [
                    'type' => 'has_related',
                    'table' => 'leave_transactions',
                    'foreign_key' => 'leave_type_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa loại phép đã có giao dịch trong sổ cái',
                ],
            ],

            // ─── Shift Types ─────────────────────────────────────────
            'shift_types' => [
                [
                    'type' => 'has_related',
                    'table' => 'attendances',
                    'foreign_key' => 'shift_type_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa ca làm đã có dữ liệu chấm công',
                ],
                [
                    'type' => 'has_related',
                    'table' => 'shift_assignments',
                    'foreign_key' => 'shift_type_id',
                    'conditions' => [['column' => 'status', 'operator' => '!=', 'value' => 'INACTIVE']],
                    'message' => 'Không thể xóa ca làm đang được phân cho nhân viên',
                ],
            ],

            // ─── Approval Flows ──────────────────────────────────────
            'approval_flows' => [
                [
                    'type' => 'has_related',
                    'table' => 'request_types',
                    'foreign_key' => 'approval_flow_id',
                    'conditions' => [['column' => 'status', 'operator' => '!=', 'value' => 'INACTIVE']],
                    'message' => 'Không thể xóa luồng phê duyệt đang được sử dụng bởi loại yêu cầu',
                ],
            ],

            // ─── News ────────────────────────────────────────────────
            'news' => [
                [
                    'type' => 'self_status',
                    'status_column' => 'status',
                    'blocked_statuses' => ['PUBLISHED', 'ĐÃ_ĐĂNG'],
                    'message' => 'Không thể xóa tin tức đã được đăng. Hãy chuyển sang trạng thái nháp hoặc ẩn trước',
                ],
            ],

            'policies' => [
                [
                    'type' => 'self_status',
                    'status_column' => 'status',
                    'blocked_statuses' => ['ACTIVE', 'PUBLISHED', 'ISSUED', 'ĐÃ_BAN_HÀNH'],
                    'message' => 'Không thể xóa chính sách đã ban hành; hãy tạo phiên bản thay thế',
                ],
            ],

            // ─── Contract Types ──────────────────────────────────────
            'contract_types' => [
                [
                    'type' => 'has_related',
                    'table' => 'contracts',
                    'foreign_key' => 'contract_type_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa loại hợp đồng đã có hợp đồng sử dụng',
                ],
            ],

            // ─── Asset Categories ────────────────────────────────────
            'asset_categories' => [
                [
                    'type' => 'has_related',
                    'table' => 'assets',
                    'foreign_key' => 'category_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa danh mục tài sản đã có tài sản',
                ],
            ],

            'allowances' => [[
                'type' => 'has_related', 'table' => 'employee_allowances', 'foreign_key' => 'allowance_id',
                'conditions' => [], 'message' => 'Không thể xóa phụ cấp đã được gán cho nhân viên',
            ]],
            'deductions' => [[
                'type' => 'has_related', 'table' => 'employee_deductions', 'foreign_key' => 'deduction_id',
                'conditions' => [], 'message' => 'Không thể xóa khấu trừ đã được gán cho nhân viên',
            ]],

            // ─── Service Categories ──────────────────────────────────
            'service_categories' => [
                [
                    'type' => 'has_related',
                    'table' => 'service_tickets',
                    'foreign_key' => 'category_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa danh mục dịch vụ đã có phiếu yêu cầu',
                ],
            ],

            // ─── News Categories ─────────────────────────────────────
            'news_categories' => [
                [
                    'type' => 'has_related',
                    'table' => 'news',
                    'foreign_key' => 'category_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa danh mục tin tức đã có bài viết',
                ],
            ],

            // ─── Recruitment Positions (CRUD qua generic, delete guard) ─
            'recruitment_positions' => [
                [
                    'type' => 'has_related',
                    'table' => 'recruitment_candidates',
                    'foreign_key' => 'recruitment_position_id',
                    'conditions' => [],
                    'message' => 'Không thể xóa vị trí tuyển dụng đã có ứng viên',
                ],
            ],
        ];
    }

    // =========================================================================
    // STORE VALIDATION — Chỉ cho nhóm A
    // =========================================================================

    private static function storeRules(): array
    {
        return [
            'departments' => [
                ['field' => 'department_name', 'rule' => 'required', 'message' => 'Tên phòng ban là bắt buộc'],
                ['field' => 'department_code', 'rule' => 'unique_if_present', 'table' => 'departments', 'message' => 'Mã phòng ban đã tồn tại'],
            ],
            'positions' => [
                ['field' => 'position_name', 'rule' => 'required', 'message' => 'Tên chức vụ là bắt buộc'],
                ['field' => 'position_code', 'rule' => 'unique_if_present', 'table' => 'positions', 'message' => 'Mã chức vụ đã tồn tại'],
            ],
            'job_families' => [
                ['field' => 'code', 'rule' => 'required', 'message' => 'Mã nhóm công việc là bắt buộc'],
                ['field' => 'code', 'rule' => 'unique_if_present', 'table' => 'job_families', 'message' => 'Mã nhóm công việc đã tồn tại'],
                ['field' => 'name', 'rule' => 'required', 'message' => 'Tên nhóm công việc là bắt buộc'],
            ],
            'roles' => [
                ['field' => 'role_code', 'rule' => 'required', 'message' => 'Mã vai trò là bắt buộc'],
                ['field' => 'role_code', 'rule' => 'unique_if_present', 'table' => 'roles', 'message' => 'Mã vai trò đã tồn tại'],
                ['field' => 'role_name', 'rule' => 'required', 'message' => 'Tên vai trò là bắt buộc'],
            ],
            'dependents' => [
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'full_name', 'rule' => 'required', 'message' => 'Họ tên người phụ thuộc là bắt buộc'],
                ['field' => 'relationship', 'rule' => 'required', 'message' => 'Quan hệ với nhân viên là bắt buộc'],
            ],
            'employment_histories' => [
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'start_date', 'rule' => 'required', 'message' => 'Ngày bắt đầu là bắt buộc'],
            ],
            'leave_types' => [
                ['field' => 'leave_type_code', 'rule' => 'required', 'message' => 'Mã loại nghỉ là bắt buộc'],
                ['field' => 'leave_type_code', 'rule' => 'unique_if_present', 'table' => 'leave_types', 'message' => 'Mã loại nghỉ đã tồn tại'],
                ['field' => 'leave_type_name', 'rule' => 'required', 'message' => 'Tên loại nghỉ là bắt buộc'],
            ],
            'shift_types' => [
                ['field' => 'shift_code', 'rule' => 'required', 'message' => 'Mã ca làm là bắt buộc'],
                ['field' => 'shift_code', 'rule' => 'unique_if_present', 'table' => 'shift_types', 'message' => 'Mã ca làm đã tồn tại'],
                ['field' => 'shift_name', 'rule' => 'required', 'message' => 'Tên ca làm là bắt buộc'],
                ['field' => 'start_time', 'rule' => 'required', 'message' => 'Giờ bắt đầu là bắt buộc'],
                ['field' => 'end_time', 'rule' => 'required', 'message' => 'Giờ kết thúc là bắt buộc'],
            ],
            'shift_assignments' => [
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'effective_date', 'rule' => 'required', 'message' => 'Ngày hiệu lực là bắt buộc'],
            ],
            'assets' => [
                ['field' => 'asset_code', 'rule' => 'required', 'message' => 'Mã tài sản là bắt buộc'],
                ['field' => 'asset_code', 'rule' => 'unique_if_present', 'table' => 'assets', 'message' => 'Mã tài sản đã tồn tại'],
                ['field' => 'asset_name', 'rule' => 'required', 'message' => 'Tên tài sản là bắt buộc'],
                ['field' => 'category_id', 'rule' => 'required', 'message' => 'Danh mục tài sản là bắt buộc'],
            ],
            'asset_assignments' => [
                ['field' => 'asset_id', 'rule' => 'required', 'message' => 'Tài sản là bắt buộc'],
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'assigned_date', 'rule' => 'required', 'message' => 'Ngày bàn giao là bắt buộc'],
            ],
            'news' => [
                ['field' => 'title', 'rule' => 'required', 'message' => 'Tiêu đề tin tức là bắt buộc'],
                ['field' => 'content', 'rule' => 'required', 'message' => 'Nội dung tin tức là bắt buộc'],
                ['field' => 'category_id', 'rule' => 'required', 'message' => 'Danh mục tin tức là bắt buộc'],
            ],
            'service_tickets' => [
                ['field' => 'title', 'rule' => 'required', 'message' => 'Tiêu đề ticket là bắt buộc'],
                ['field' => 'description', 'rule' => 'required', 'message' => 'Nội dung ticket là bắt buộc'],
                ['field' => 'category_id', 'rule' => 'required', 'message' => 'Danh mục dịch vụ là bắt buộc'],
            ],
            'allowances' => [
                ['field' => 'allowance_code', 'rule' => 'required', 'message' => 'Mã phụ cấp là bắt buộc'],
                ['field' => 'allowance_code', 'rule' => 'unique_if_present', 'table' => 'allowances', 'message' => 'Mã phụ cấp đã tồn tại'],
                ['field' => 'allowance_name', 'rule' => 'required', 'message' => 'Tên phụ cấp là bắt buộc'],
            ],
            'deductions' => [
                ['field' => 'deduction_code', 'rule' => 'required', 'message' => 'Mã khấu trừ là bắt buộc'],
                ['field' => 'deduction_code', 'rule' => 'unique_if_present', 'table' => 'deductions', 'message' => 'Mã khấu trừ đã tồn tại'],
                ['field' => 'deduction_name', 'rule' => 'required', 'message' => 'Tên khấu trừ là bắt buộc'],
            ],
            'employee_allowances' => [
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'allowance_id', 'rule' => 'required', 'message' => 'Loại phụ cấp là bắt buộc'],
                ['field' => 'effective_date', 'rule' => 'required', 'message' => 'Ngày hiệu lực là bắt buộc'],
            ],
            'employee_deductions' => [
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'deduction_id', 'rule' => 'required', 'message' => 'Loại khấu trừ là bắt buộc'],
                ['field' => 'effective_date', 'rule' => 'required', 'message' => 'Ngày hiệu lực là bắt buộc'],
            ],
            'asset_categories' => [
                ['field' => 'category_code', 'rule' => 'required', 'message' => 'Mã danh mục là bắt buộc'],
                ['field' => 'category_code', 'rule' => 'unique_if_present', 'table' => 'asset_categories', 'message' => 'Mã danh mục đã tồn tại'],
                ['field' => 'category_name', 'rule' => 'required', 'message' => 'Tên danh mục là bắt buộc'],
            ],
            'asset_locations' => [
                ['field' => 'location_code', 'rule' => 'required', 'message' => 'Mã vị trí là bắt buộc'],
                ['field' => 'location_code', 'rule' => 'unique_if_present', 'table' => 'asset_locations', 'message' => 'Mã vị trí đã tồn tại'],
                ['field' => 'location_name', 'rule' => 'required', 'message' => 'Tên vị trí là bắt buộc'],
            ],
            'suppliers' => [
                ['field' => 'supplier_code', 'rule' => 'required', 'message' => 'Mã nhà cung cấp là bắt buộc'],
                ['field' => 'supplier_code', 'rule' => 'unique_if_present', 'table' => 'suppliers', 'message' => 'Mã nhà cung cấp đã tồn tại'],
                ['field' => 'supplier_name', 'rule' => 'required', 'message' => 'Tên nhà cung cấp là bắt buộc'],
            ],
            'service_categories' => [
                ['field' => 'category_code', 'rule' => 'required', 'message' => 'Mã danh mục là bắt buộc'],
                ['field' => 'category_code', 'rule' => 'unique_if_present', 'table' => 'service_categories', 'message' => 'Mã danh mục đã tồn tại'],
                ['field' => 'category_name', 'rule' => 'required', 'message' => 'Tên danh mục là bắt buộc'],
            ],
            'news_categories' => [
                ['field' => 'category_code', 'rule' => 'required', 'message' => 'Mã danh mục là bắt buộc'],
                ['field' => 'category_code', 'rule' => 'unique_if_present', 'table' => 'news_categories', 'message' => 'Mã danh mục đã tồn tại'],
                ['field' => 'category_name', 'rule' => 'required', 'message' => 'Tên danh mục là bắt buộc'],
            ],
            'document_types' => [
                ['field' => 'document_type_code', 'rule' => 'required', 'message' => 'Mã loại giấy tờ là bắt buộc'],
                ['field' => 'document_type_code', 'rule' => 'unique_if_present', 'table' => 'document_types', 'message' => 'Mã loại giấy tờ đã tồn tại'],
                ['field' => 'document_type_name', 'rule' => 'required', 'message' => 'Tên loại giấy tờ là bắt buộc'],
            ],
            'qualification_types' => [
                ['field' => 'qualification_type_code', 'rule' => 'required', 'message' => 'Mã loại trình độ là bắt buộc'],
                ['field' => 'qualification_type_code', 'rule' => 'unique_if_present', 'table' => 'qualification_types', 'message' => 'Mã loại trình độ đã tồn tại'],
                ['field' => 'qualification_type_name', 'rule' => 'required', 'message' => 'Tên loại trình độ là bắt buộc'],
            ],
            'insurance_types' => [
                ['field' => 'insurance_type_code', 'rule' => 'required', 'message' => 'Mã loại bảo hiểm là bắt buộc'],
                ['field' => 'insurance_type_code', 'rule' => 'unique_if_present', 'table' => 'insurance_types', 'message' => 'Mã loại bảo hiểm đã tồn tại'],
                ['field' => 'insurance_type_name', 'rule' => 'required', 'message' => 'Tên loại bảo hiểm là bắt buộc'],
            ],
            'banks' => [
                ['field' => 'bank_code', 'rule' => 'required', 'message' => 'Mã ngân hàng là bắt buộc'],
                ['field' => 'bank_code', 'rule' => 'unique_if_present', 'table' => 'banks', 'message' => 'Mã ngân hàng đã tồn tại'],
                ['field' => 'bank_name', 'rule' => 'required', 'message' => 'Tên ngân hàng là bắt buộc'],
            ],
            'nationalities' => [
                ['field' => 'nationality_code', 'rule' => 'required', 'message' => 'Mã quốc tịch là bắt buộc'],
                ['field' => 'nationality_code', 'rule' => 'unique_if_present', 'table' => 'nationalities', 'message' => 'Mã quốc tịch đã tồn tại'],
                ['field' => 'nationality_name', 'rule' => 'required', 'message' => 'Tên quốc tịch là bắt buộc'],
            ],
            'policies' => [
                ['field' => 'policy_code', 'rule' => 'required', 'message' => 'Mã chính sách là bắt buộc'],
                ['field' => 'policy_code', 'rule' => 'unique_if_present', 'table' => 'policies', 'message' => 'Mã chính sách đã tồn tại'],
                ['field' => 'policy_name', 'rule' => 'required', 'message' => 'Tên chính sách là bắt buộc'],
                ['field' => 'content', 'rule' => 'required', 'message' => 'Nội dung chính sách là bắt buộc'],
            ],
            'salary_components' => [
                ['field' => 'code', 'rule' => 'required', 'message' => 'Mã thành phần lương là bắt buộc'],
                ['field' => 'code', 'rule' => 'unique_if_present', 'table' => 'salary_components', 'message' => 'Mã thành phần lương đã tồn tại'],
                ['field' => 'name', 'rule' => 'required', 'message' => 'Tên thành phần lương là bắt buộc'],
                ['field' => 'type', 'rule' => 'required', 'message' => 'Loại thành phần lương là bắt buộc'],
                ['field' => 'category', 'rule' => 'required', 'message' => 'Nhóm thành phần lương là bắt buộc'],
            ],
            'identity_documents' => [
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'document_type_id', 'rule' => 'required', 'message' => 'Loại giấy tờ là bắt buộc'],
                ['field' => 'document_number', 'rule' => 'required', 'message' => 'Số giấy tờ là bắt buộc'],
            ],
            'qualifications' => [
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'qualification_type_id', 'rule' => 'required', 'message' => 'Loại trình độ là bắt buộc'],
                ['field' => 'qualification_name', 'rule' => 'required', 'message' => 'Tên bằng cấp là bắt buộc'],
            ],
        ];
    }

    // =========================================================================
    // UPDATE VALIDATION — Chỉ cho nhóm A
    // =========================================================================

    private static function updateRules(): array
    {
        return [
            'departments' => [
                ['field' => 'department_code', 'rule' => 'unique_if_present', 'table' => 'departments', 'message' => 'Mã phòng ban đã tồn tại'],
            ],
            'positions' => [
                ['field' => 'position_code', 'rule' => 'unique_if_present', 'table' => 'positions', 'message' => 'Mã chức vụ đã tồn tại'],
            ],
            'job_families' => [
                ['field' => 'code', 'rule' => 'unique_if_present', 'table' => 'job_families', 'message' => 'Mã nhóm công việc đã tồn tại'],
            ],
            'roles' => [
                ['field' => 'role_code', 'rule' => 'unique_if_present', 'table' => 'roles', 'message' => 'Mã vai trò đã tồn tại'],
            ],
            'leave_types' => [
                ['field' => 'leave_type_code', 'rule' => 'unique_if_present', 'table' => 'leave_types', 'message' => 'Mã loại nghỉ đã tồn tại'],
            ],
            'shift_types' => [
                ['field' => 'shift_code', 'rule' => 'unique_if_present', 'table' => 'shift_types', 'message' => 'Mã ca làm đã tồn tại'],
            ],
            'assets' => [
                ['field' => 'asset_code', 'rule' => 'unique_if_present', 'table' => 'assets', 'message' => 'Mã tài sản đã tồn tại'],
            ],
            'policies' => [
                ['field' => 'policy_code', 'rule' => 'unique_if_present', 'table' => 'policies', 'message' => 'Mã chính sách đã tồn tại'],
            ],
            'salary_components' => [
                ['field' => 'code', 'rule' => 'unique_if_present', 'table' => 'salary_components', 'message' => 'Mã thành phần lương đã tồn tại'],
            ],
            'allowances' => [['field' => 'allowance_code', 'rule' => 'unique_if_present', 'table' => 'allowances', 'message' => 'Mã phụ cấp đã tồn tại']],
            'deductions' => [['field' => 'deduction_code', 'rule' => 'unique_if_present', 'table' => 'deductions', 'message' => 'Mã khấu trừ đã tồn tại']],
            'asset_categories' => [['field' => 'category_code', 'rule' => 'unique_if_present', 'table' => 'asset_categories', 'message' => 'Mã danh mục đã tồn tại']],
            'asset_locations' => [['field' => 'location_code', 'rule' => 'unique_if_present', 'table' => 'asset_locations', 'message' => 'Mã vị trí đã tồn tại']],
            'suppliers' => [['field' => 'supplier_code', 'rule' => 'unique_if_present', 'table' => 'suppliers', 'message' => 'Mã nhà cung cấp đã tồn tại']],
            'service_categories' => [['field' => 'category_code', 'rule' => 'unique_if_present', 'table' => 'service_categories', 'message' => 'Mã danh mục đã tồn tại']],
            'news_categories' => [['field' => 'category_code', 'rule' => 'unique_if_present', 'table' => 'news_categories', 'message' => 'Mã danh mục đã tồn tại']],
            'document_types' => [['field' => 'document_type_code', 'rule' => 'unique_if_present', 'table' => 'document_types', 'message' => 'Mã loại giấy tờ đã tồn tại']],
            'qualification_types' => [['field' => 'qualification_type_code', 'rule' => 'unique_if_present', 'table' => 'qualification_types', 'message' => 'Mã loại trình độ đã tồn tại']],
            'insurance_types' => [['field' => 'insurance_type_code', 'rule' => 'unique_if_present', 'table' => 'insurance_types', 'message' => 'Mã loại bảo hiểm đã tồn tại']],
            'banks' => [['field' => 'bank_code', 'rule' => 'unique_if_present', 'table' => 'banks', 'message' => 'Mã ngân hàng đã tồn tại']],
            'nationalities' => [['field' => 'nationality_code', 'rule' => 'unique_if_present', 'table' => 'nationalities', 'message' => 'Mã quốc tịch đã tồn tại']],
        ];
    }

    /**
     * @param array{valid: bool, errors: array<string, list<string>>} $result
     * @return array{valid: bool, errors: array<string, list<string>>}
     */
    private static function validatePayrollAssignment(
        array $result,
        string $table,
        array $payload,
        ?int $excludeId,
    ): array {
        $current = $excludeId ? DB::table($table)->where('id', $excludeId)
            ->when(TenantContext::hasTenant(), fn ($query) => $query->where('tenant_id', TenantContext::id()))
            ->first() : null;
        $merged = array_merge($current ? (array) $current : [], $payload);
        $componentColumn = $table === 'employee_allowances' ? 'allowance_id' : 'deduction_id';
        $amount = (float) ($merged['amount'] ?? 0);
        $percentage = (float) ($merged['percentage'] ?? 0);
        if ($amount <= 0 && $percentage <= 0) {
            $result['errors']['amount'][] = 'Phải nhập số tiền hoặc tỷ lệ lớn hơn 0';
        }
        if ($amount < 0 || $percentage < 0) {
            $result['errors']['amount'][] = 'Số tiền và tỷ lệ không được âm';
        }
        $start = $merged['effective_date'] ?? null;
        $end = $merged['expiry_date'] ?? null;
        if ($start && $end && (string) $end < (string) $start) {
            $result['errors']['expiry_date'][] = 'Ngày kết thúc không được trước ngày hiệu lực';
        }
        if (! empty($merged['employee_id']) && ! empty($merged[$componentColumn]) && $start) {
            $query = DB::table($table)
                ->where('tenant_id', TenantContext::id())
                ->where('employee_id', $merged['employee_id'])
                ->where($componentColumn, $merged[$componentColumn])
                ->when($excludeId, fn ($builder) => $builder->where('id', '<>', $excludeId))
                ->where(function ($builder) use ($end): void {
                    if ($end) {
                        $builder->whereNull('effective_date')->orWhere('effective_date', '<=', $end);
                    }
                })
                ->where(function ($builder) use ($start): void {
                    $builder->whereNull('expiry_date')->orWhere('expiry_date', '>=', $start);
                });
            if ($query->exists()) {
                $result['errors']['effective_date'][] = 'Khoảng hiệu lực bị trùng với một lần gán hiện có';
            }
        }
        $result['valid'] = empty($result['errors']);

        return $result;
    }

    // =========================================================================
    // EVALUATORS
    // =========================================================================

    private static function evaluateDeleteGuard(array $guard, string $resource, int $id): bool
    {
        return match ($guard['type']) {
            'has_related' => self::checkHasRelated($guard, $id),
            'self_status' => self::checkSelfStatus($guard, $resource, $id),
            'self_field' => self::checkSelfField($guard, $resource, $id),
            default => false,
        };
    }

    private static function checkHasRelated(array $guard, int $id): bool
    {
        if (! Schema::hasTable($guard['table'])) {
            return false;
        }

        $query = DB::table($guard['table'])->where($guard['foreign_key'], $id);

        foreach ($guard['conditions'] as $condition) {
            if (! Schema::hasColumn($guard['table'], $condition['column'])) {
                continue;
            }

            match ($condition['operator']) {
                '=' => self::applyScalarCondition($query, $condition['column'], '=', $condition['value']),
                '!=' => self::applyScalarCondition($query, $condition['column'], '!=', $condition['value']),
                'IN' => $query->whereIn($condition['column'], $condition['value']),
                'NOT_IN' => $query->whereNotIn($condition['column'], $condition['value']),
                default => null,
            };
        }

        return $query->exists();
    }

    private static function applyScalarCondition($query, string $column, string $operator, mixed $value): mixed
    {
        if (is_bool($value)) {
            $literal = $value ? 'true' : 'false';

            return $query->whereRaw("{$column} {$operator} {$literal}");
        }

        return $query->where($column, $operator, $value);
    }

    private static function checkSelfStatus(array $guard, string $table, int $id): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $guard['status_column'])) {
            return false;
        }

        $record = DB::table($table)->where('id', $id)->first([$guard['status_column']]);

        return $record && in_array($record->{$guard['status_column']}, $guard['blocked_statuses'], true);
    }

    private static function checkSelfField(array $guard, string $table, int $id): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $guard['field'])) {
            return false;
        }

        $record = DB::table($table)->where('id', $id)->first([$guard['field']]);

        if (! $record) {
            return false;
        }

        $actualValue = $record->{$guard['field']};

        return match ($guard['operator']) {
            '=' => $actualValue == $guard['value'],
            '!=' => $actualValue != $guard['value'],
            default => false,
        };
    }

    // =========================================================================
    // VALIDATION EVALUATORS
    // =========================================================================

    private static function runValidation(array $rules, array $payload, string $resource, ?int $excludeId): array
    {
        $errors = [];

        foreach ($rules as $rule) {
            $field = $rule['field'];
            $value = $payload[$field] ?? null;

            $error = match ($rule['rule']) {
                'required' => self::validateRequired($value, $rule),
                'unique' => self::validateUnique($value, $rule, $excludeId),
                'unique_if_present' => self::validateUniqueIfPresent($value, $rule, $excludeId),
                'exists' => self::validateExists($value, $rule),
                'exists_if_present' => self::validateExistsIfPresent($value, $rule),
                default => null,
            };

            if ($error !== null) {
                $errors[$field][] = $error;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private static function validateRequired(mixed $value, array $rule): ?string
    {
        return ($value === null || (is_string($value) && trim($value) === '')) ? $rule['message'] : null;
    }

    private static function validateUnique(mixed $value, array $rule, ?int $excludeId): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $query = DB::table($rule['table']);

        if (is_string($value)) {
            $query->whereRaw('LOWER('.$rule['field'].') = ?', [mb_strtolower(trim($value))]);
        } else {
            $query->where($rule['field'], $value);
        }

        if (TenantContext::hasTenant() && Schema::hasColumn($rule['table'], 'tenant_id')) {
            $query->where('tenant_id', TenantContext::id());
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists() ? $rule['message'] : null;
    }

    private static function validateUniqueIfPresent(mixed $value, array $rule, ?int $excludeId): ?string
    {
        return ($value === null || $value === '') ? null : self::validateUnique($value, $rule, $excludeId);
    }

    private static function validateExists(mixed $value, array $rule): ?string
    {
        return ($value === null || $value === '')
            ? null
            : (DB::table($rule['table'])->where('id', $value)->exists() ? null : $rule['message']);
    }

    private static function validateExistsIfPresent(mixed $value, array $rule): ?string
    {
        return ($value === null || $value === '') ? null : self::validateExists($value, $rule);
    }
}
