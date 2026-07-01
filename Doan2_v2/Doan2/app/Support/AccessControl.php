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
 *   - A role whose meta has NO 'modules' key AND is not flagged is_admin is
 *     treated as UNCONFIGURED → full access (so nothing breaks until an admin
 *     explicitly restricts a role by giving it a modules list).
 *   - Unknown / unmapped API paths are allowed (never block what we don't model).
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

    /**
     * API path-prefix (first segment) → required module. Anything not listed is
     * unguarded. GET on SHARED_READ prefixes is always allowed (dropdowns/lookups
     * used across modules).
     */
    private const PATH_MODULE = [
        'employees' => 'hr',
        'organization' => 'hr',
        'contracts' => 'hr',
        'contract-types' => 'hr',
        'contract-templates' => 'hr',
        'contract-change-logs' => 'hr',
        'employment-histories' => 'hr',
        'dependents' => 'hr',
        'onboarding-checklists' => 'hr',
        'profile-change-requests' => 'hr',
        'departments' => 'hr',

        'attendances' => 'time',
        'attendance-adjustments' => 'time',
        'shift-types' => 'time',
        'shift-assignments' => 'time',
        'shift-schedules' => 'time',
        'shift-swaps' => 'time',
        'shift-coverage-requests' => 'time',
        'shift-coverage-offers' => 'time',
        'overtime-requests' => 'time',
        'leave-requests' => 'time',
        'leave-types' => 'time',
        'leave-balances' => 'time',
        'holidays' => 'time',
        'requests' => 'time',

        'salary-periods' => 'payroll',
        'salary-details' => 'payroll',
        'salary-components' => 'payroll',
        'payroll' => 'payroll',
        'reports' => 'payroll',

        'recruitment-candidates' => 'recruitment',
        'recruitment-positions' => 'recruitment',
        'interviews' => 'recruitment',

        'news' => 'communications',
        'policies' => 'communications',

        'job-families' => 'settings',
        'job-titles' => 'settings',
        'positions' => 'settings',
        'legal-entities' => 'settings',
        'audit-logs' => 'settings',
        'roles' => 'settings',
        'employee-roles' => 'settings',
        'permissions' => 'settings',
        'settings' => 'settings',
    ];

    /**
     * GET requests to these prefixes are always allowed for any authenticated
     * user — they are reference/lookup data many modules depend on.
     */
    private const SHARED_READ = [
        'employees', 'departments', 'positions', 'job-titles', 'job-families',
        'nationalities', 'banks', 'contract-types', 'leave-types', 'roles',
        'dashboard', 'notifications', 'shift-types',
    ];

    /**
     * Effective access for an employee.
     *
     * @return array{full: bool, modules: array<int, string>}
     */
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

    public static function forEmployee(int $employeeId, bool $isSuperAdmin = false): array
    {
        if ($isSuperAdmin) {
            return ['full' => true, 'modules' => array_keys(self::MODULES), 'enabled' => self::enabledModules()];
        }

        $roles = DB::table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->where('employee_roles.employee_id', $employeeId)
            ->whereRaw('employee_roles.is_active = true')
            ->where(function ($q) {
                $q->whereNull('employee_roles.effective_date')->orWhere('employee_roles.effective_date', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('employee_roles.expiry_date')->orWhere('employee_roles.expiry_date', '>=', now());
            })
            ->get(['roles.role_code', 'roles.meta']);

        $enabled = self::enabledModules();

        if ($roles->isEmpty()) {
            // No role at all → regular employee (portal only, no admin modules).
            return ['full' => false, 'modules' => [], 'enabled' => $enabled];
        }

        $modules = [];
        foreach ($roles as $role) {
            $meta = is_string($role->meta ?? null) ? json_decode($role->meta, true) : (array) ($role->meta ?? []);
            $meta = is_array($meta) ? $meta : [];

            if (! empty($meta['is_admin']) || strtoupper((string) $role->role_code) === 'ADMIN') {
                return ['full' => true, 'modules' => array_keys(self::MODULES), 'enabled' => $enabled];
            }

            if (! array_key_exists('modules', $meta)) {
                // Unconfigured non-admin role → treat as full (backward-compatible).
                return ['full' => true, 'modules' => array_keys(self::MODULES), 'enabled' => $enabled];
            }

            if (is_array($meta['modules'])) {
                $modules = array_merge($modules, $meta['modules']);
            }
        }

        $modules = array_values(array_unique(array_filter($modules, fn ($m) => isset(self::MODULES[$m]))));

        return ['full' => false, 'modules' => $modules, 'enabled' => $enabled];
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

        // Shared lookup reads are always allowed.
        if (strtoupper($method) === 'GET' && in_array($segment, self::SHARED_READ, true)) {
            return true;
        }

        $required = self::PATH_MODULE[$segment] ?? null;
        if ($required === null) {
            return true; // unmapped → not guarded
        }

        return in_array($required, $access['modules'] ?? [], true);
    }
}
