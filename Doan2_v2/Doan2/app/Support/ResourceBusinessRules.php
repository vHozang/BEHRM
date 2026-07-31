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
            'employee_deductions' => ['employee_id' => 'employees'],
            'shift_assignments' => ['employee_id' => 'employees', 'shift_type_id' => 'shift_types', 'assigned_by' => 'employees'],
            'employee_roles' => ['employee_id' => 'employees', 'role_id' => 'roles'],
            'dependents' => ['employee_id' => 'employees'],
            'leave_balances' => ['employee_id' => 'employees', 'leave_type_id' => 'leave_types'],
            'asset_assignments' => ['employee_id' => 'employees', 'asset_id' => 'assets'],
            'qualifications' => ['employee_id' => 'employees'],
            'certificates' => ['employee_id' => 'employees'],
            'identity_documents' => ['employee_id' => 'employees'],
            'social_insurance_info' => ['employee_id' => 'employees'],
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
                    'conditions' => [['column' => 'application_status', 'operator' => 'NOT_IN', 'value' => ['REJECTED', 'WITHDRAWN', 'TỪ_CHỐI']]],
                    'message' => 'Không thể xóa vị trí tuyển dụng đang có ứng viên',
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
                ['field' => 'shift_type_id', 'rule' => 'required', 'message' => 'Ca làm là bắt buộc'],
                ['field' => 'effective_date', 'rule' => 'required', 'message' => 'Ngày hiệu lực là bắt buộc'],
            ],
            'assets' => [
                ['field' => 'asset_code', 'rule' => 'required', 'message' => 'Mã tài sản là bắt buộc'],
                ['field' => 'asset_code', 'rule' => 'unique_if_present', 'table' => 'assets', 'message' => 'Mã tài sản đã tồn tại'],
                ['field' => 'asset_name', 'rule' => 'required', 'message' => 'Tên tài sản là bắt buộc'],
            ],
            'asset_assignments' => [
                ['field' => 'asset_id', 'rule' => 'required', 'message' => 'Tài sản là bắt buộc'],
                ['field' => 'employee_id', 'rule' => 'required', 'message' => 'Nhân viên là bắt buộc'],
                ['field' => 'assigned_date', 'rule' => 'required', 'message' => 'Ngày bàn giao là bắt buộc'],
            ],
            'news' => [
                ['field' => 'title', 'rule' => 'required', 'message' => 'Tiêu đề tin tức là bắt buộc'],
                ['field' => 'content', 'rule' => 'required', 'message' => 'Nội dung tin tức là bắt buộc'],
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
        ];
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
