<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Role-based, module-level access control.
 *
 * A "module" maps to a sidebar group / functional area. A role grants a set of
 * modules (stored in roles.meta->modules) or full admin access
 * (roles.meta->is_admin = true). An employee's effective access is the union of
 * their active roles.
 *
 * Lockout-safety rules (deliberate):
 *   - Super-admins always have full access.
 *   - A role without a module list grants no management module.
 *   - Unknown / unmapped API paths are denied for restricted roles.
 */
class AccessControl
{
    /** Canonical module catalog (keys) with VN labels. */
    public const MODULES = [
        'hr' => 'Nhân sự & Hợp đồng',
        'time' => 'Công & Lịch',
        'payroll' => 'Lương & Báo cáo',
        'recruitment' => 'Tuyển dụng',
        'communications' => 'Truyền thông',
        'settings' => 'Cấu hình',
    ];

    public const CAPABILITIES = [
        'payslip_issues.view' => 'Xem danh sách phiếu lương chưa phát hành',
    ];

    private const ROLE_CAPABILITIES = [
        'ADMIN' => ['payslip_issues.view'],
        'TENANT_ADMIN' => ['payslip_issues.view'],
        'ACCOUNTANT' => ['payslip_issues.view'],
        'HR' => ['payslip_issues.view'],
    ];

    /**
     * API path-prefix (first segment) → required module. Anything not listed is
     * denied. GET on SHARED_READ prefixes is always allowed (dropdowns/lookups
     * used across modules).
     */
    private const PATH_MODULE = [
        'employees' => 'hr', 'organization' => 'hr', 'departments' => 'hr',
        'contracts' => 'hr', 'contract-types' => 'hr', 'contract-templates' => 'hr',
        'contract-change-logs' => 'hr', 'contract-histories' => 'hr',
        'personnel-decisions' => 'hr',   // quyết định nhân sự: tăng lương/điều chuyển/thôi việc
        'employment-histories' => 'hr', 'dependents' => 'hr',
        'onboarding-checklists' => 'hr', 'profile-change-requests' => 'hr',
        'asset-assignments' => 'hr', 'asset-categories' => 'hr',
        'asset-incidents' => 'hr', 'asset-locations' => 'hr',
        'asset-maintenance' => 'hr', 'assets' => 'hr',
        'certificates' => 'hr', 'certificate-types' => 'hr',
        'document-types' => 'hr', 'identity-documents' => 'hr',
        'qualifications' => 'hr', 'qualification-types' => 'hr',
        'social-insurance-info' => 'hr',

        'attendance' => 'time', 'attendances' => 'time',
        'attendance-adjustments' => 'time', 'leave' => 'time',
        'shift-types' => 'time', 'shift-assignments' => 'time',
        'shift-schedules' => 'time', 'shift-schedule-details' => 'time',
        'shift-roster' => 'time', 'shift-swaps' => 'time',
        'shift-coverage-requests' => 'time', 'shift-coverage-offers' => 'time',
        'overtime-requests' => 'time', 'leave-requests' => 'time',
        'leave-types' => 'time', 'leave-balances' => 'time',
        'leave-advancement-config' => 'time', 'leave-advancement-requests' => 'time',
        'leave-carryover-tracking' => 'time', 'leave-transactions' => 'time',
        'seniority-leave-history' => 'time', 'holidays' => 'time',
        'requests' => 'time', 'approval-flows' => 'time',
        'approval-histories' => 'time', 'approval-roles' => 'time',
        'approval-steps' => 'time',

        'salary-periods' => 'payroll', 'salary-details' => 'payroll',
        'salary-components' => 'payroll', 'salary-attendance-summary' => 'payroll',
        'salary-breakdowns' => 'payroll', 'payroll' => 'payroll',
        'payroll-adjustments' => 'payroll', 'piece-rate-entries' => 'payroll',
        'reports' => 'payroll', 'report-histories' => 'payroll',
        'report-templates' => 'payroll', 'allowances' => 'payroll',
        'deductions' => 'payroll', 'employee-allowances' => 'payroll',
        'employee-deductions' => 'payroll', 'insurance-claims' => 'payroll',
        'insurance-types' => 'payroll',

        'recruitment-candidates' => 'recruitment',
        'recruitment-positions' => 'recruitment', 'recruitment-posts' => 'recruitment',
        'recruitment-ai' => 'recruitment', 'interviews' => 'recruitment',

        'news' => 'communications', 'news-categories' => 'communications',
        'news-reads' => 'communications', 'policies' => 'communications',
        'policy-acknowledgments' => 'communications',
        'service-categories' => 'communications',
        'service-tickets' => 'communications',
        'service-ticket-updates' => 'communications',

        'activity-logs' => 'settings', 'attendance-devices' => 'settings',
        'dashboard-views' => 'settings', 'job-families' => 'settings',
        'job-titles' => 'settings', 'positions' => 'settings',
        'legal-entities' => 'settings', 'audit-logs' => 'settings',
        'roles' => 'settings', 'employee-roles' => 'settings',
        'permissions' => 'settings', 'role-permissions' => 'settings',
        'settings' => 'settings', 'banks' => 'settings',
        'nationalities' => 'settings', 'notification-configs' => 'settings',
        'request-attachments' => 'settings', 'request-types' => 'settings',
        'suppliers' => 'settings', 'users' => 'settings',
    ];

    /** Authenticated endpoints that are self-scoped by their controllers. */
    private const ALWAYS_ALLOWED = ['auth', 'ai', 'notifications'];

    /**
     * GET requests to these prefixes are always allowed for any authenticated
     * user — they are reference/lookup data many modules depend on.
     */
    private const SHARED_READ = [
        'employees', 'departments', 'positions', 'job-titles', 'job-families',
        'nationalities', 'banks', 'contract-types', 'leave-types', 'roles',
        'notifications', 'shift-types',
        // Tin nội bộ + chính sách công ty: MỌI nhân viên phải ĐỌC được (để nắm
        // thông báo + xác nhận nội quy). Chỉ GET mở; tạo/sửa/xóa vẫn cần module
        // communications (SHARED_READ chỉ nới GET).
        'news', 'policies', 'holidays',
    ];

    /**
     * Modules a company has turned ON (feature toggle, separate from role grants).
     * Default: all modules. 'settings' is always enabled so admins can re-enable
     * others. Stored as tenant override modules.enabled (json array of keys).
     *
     * @return array<int,string>
     */
    public static function enabledModules(): array
    {
        $set = \App\Support\HrmConfig::get('modules.enabled', null);
        if (! is_array($set)) {
            return array_keys(self::MODULES); // unset → everything enabled
        }
        $set = array_values(array_filter($set, fn ($m) => isset(self::MODULES[$m])));
        if (! in_array('settings', $set, true)) {
            $set[] = 'settings'; // never lock admins out of config
        }

        return $set;
    }

    /**
     * @return array{
     *   full: bool,
     *   modules: array<int, string>,
     *   enabled: array<int, string>,
     *   capabilities: array<int, string>,
     *   roles: array<int, array{id: int, role_code: string, role_name: string}>
     * }
     */
    public static function forEmployee(int $employeeId, bool $isSuperAdmin = false): array
    {
        $roles = DB::table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->where('employee_roles.employee_id', $employeeId)
            ->whereRaw('employee_roles.is_active IS TRUE')
            ->where(function ($q) {
                $q->whereNull('employee_roles.effective_date')->orWhere('employee_roles.effective_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('employee_roles.expiry_date')->orWhere('employee_roles.expiry_date', '>=', now());
            })
            ->orderBy('roles.id')
            ->get(['roles.id', 'roles.role_code', 'roles.role_name', 'roles.meta']);

        $roleSummaries = $roles->map(fn ($role) => [
            'id' => (int) $role->id,
            'role_code' => (string) $role->role_code,
            'role_name' => (string) ($role->role_name ?: $role->role_code),
        ])->values()->all();

        if ($isSuperAdmin) {
            return [
                'full' => true,
                'modules' => array_keys(self::MODULES),
                'enabled' => self::enabledModules(),
                'capabilities' => array_keys(self::CAPABILITIES),
                'roles' => $roleSummaries,
            ];
        }

        $enabled = self::enabledModules();

        if ($roles->isEmpty()) {
            // No role at all → regular employee (portal only, no admin modules).
            return [
                'full' => false,
                'modules' => [],
                'enabled' => $enabled,
                'capabilities' => [],
                'roles' => [],
            ];
        }

        $modules = [];
        $capabilities = [];
        foreach ($roles as $role) {
            $meta = is_string($role->meta ?? null) ? json_decode($role->meta, true) : (array) ($role->meta ?? []);
            $meta = is_array($meta) ? $meta : [];
            $roleCode = strtoupper((string) $role->role_code);
            $capabilities = array_merge($capabilities, self::ROLE_CAPABILITIES[$roleCode] ?? []);

            if (! empty($meta['is_admin']) || $roleCode === 'ADMIN') {
                return [
                    'full' => true,
                    'modules' => array_keys(self::MODULES),
                    'enabled' => $enabled,
                    'capabilities' => array_keys(self::CAPABILITIES),
                    'roles' => $roleSummaries,
                ];
            }

            if (! array_key_exists('modules', $meta)) {
                continue;
            }

            if (is_array($meta['modules'])) {
                $modules = array_merge($modules, $meta['modules']);
            }
        }

        $modules = array_values(array_unique(array_filter($modules, fn ($m) => isset(self::MODULES[$m]))));
        $capabilities = array_values(array_unique(array_filter(
            $capabilities,
            fn ($capability) => isset(self::CAPABILITIES[$capability])
        )));

        return [
            'full' => false,
            'modules' => $modules,
            'enabled' => $enabled,
            'capabilities' => $capabilities,
            'roles' => $roleSummaries,
        ];
    }

    public static function hasCapability(?int $employeeId, string $capability): bool
    {
        if (! $employeeId || ! isset(self::CAPABILITIES[$capability])) {
            return false;
        }

        $isSuperAdmin = (bool) DB::table('employees')->where('id', $employeeId)->value('is_super_admin');
        $access = self::forEmployee($employeeId, $isSuperAdmin);

        return ! empty($access['full']) || in_array($capability, $access['capabilities'] ?? [], true);
    }

    /** Check an employee's active roles for sensitive actions within a module. */
    public static function hasAnyRole(?int $employeeId, array $roleCodes): bool
    {
        if (! $employeeId) {
            return false;
        }

        if (DB::table('employees')->where('id', $employeeId)->value('is_super_admin')) {
            return true;
        }

        $codes = array_values(array_unique(array_map(
            fn ($code) => strtoupper((string) $code),
            $roleCodes
        )));
        if ($codes === []) {
            return false;
        }

        $roles = DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.employee_id', $employeeId)
            // `IS TRUE` works on PostgreSQL and SQLite; bound booleans become
            // integer `1` on PostgreSQL and cause `boolean = integer` errors.
            ->whereRaw('er.is_active IS TRUE')
            ->where(function ($query): void {
                $query->whereNull('er.effective_date')->orWhere('er.effective_date', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('er.expiry_date')->orWhere('er.expiry_date', '>=', now());
            })
            ->get(['r.role_code', 'r.meta']);

        foreach ($roles as $role) {
            if (in_array(strtoupper((string) $role->role_code), $codes, true)) {
                return true;
            }
            if (in_array('ADMIN', $codes, true)) {
                $meta = is_string($role->meta) ? json_decode($role->meta, true) : (array) ($role->meta ?? []);
                if (($meta['is_admin'] ?? false) === true) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a request (method + path) is permitted for the given access set.
     * $path is the path after the /api/v1/ prefix (e.g. "salary-details/3").
     */
    public static function allows(array $access, string $method, string $path): bool
    {
        if (! empty($access['full'])) {
            return true;
        }

        $segment = strtolower(explode('/', ltrim($path, '/'))[0] ?? '');
        if ($segment === '') {
            return true;
        }

        if (in_array($segment, self::ALWAYS_ALLOWED, true)) {
            return true;
        }

        if ($segment === 'payroll' && str_starts_with(strtolower($path), 'payroll/payslip-issues')) {
            return in_array('payslip_issues.view', $access['capabilities'] ?? [], true);
        }

        // The management dashboard contains tenant-wide aggregates. It is for
        // people/operations roles, never for a portal-only employee or payroll-only role.
        if ($segment === 'dashboard') {
            return count(array_intersect(
                ['hr', 'time', 'recruitment'],
                $access['modules'] ?? []
            )) > 0;
        }

        // Shared lookup reads are always allowed.
        if (strtoupper($method) === 'GET' && in_array($segment, self::SHARED_READ, true)) {
            return true;
        }

        $required = self::PATH_MODULE[$segment] ?? null;
        if ($required === null) {
            return false;
        }

        return in_array($required, $access['modules'] ?? [], true);
    }
}
