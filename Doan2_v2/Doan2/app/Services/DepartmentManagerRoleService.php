<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DepartmentManagerRoleService
{
    private const SOURCE = 'department-manager-sync';

    public function syncTenant(int $tenantId): void
    {
        $roleId = $this->managerRoleId($tenantId);
        if (! $roleId) {
            return;
        }

        $managerIds = DB::table('departments')
            ->where('tenant_id', $tenantId)
            ->get(['status', 'meta'])
            ->filter(fn ($department): bool => ! in_array(
                $department->status ?? true,
                [false, 0, '0', 'false', 'FALSE', 'INACTIVE'],
                true,
            ))
            ->map(function ($department): int {
                $meta = $this->decodeMeta($department->meta ?? null);

                return (int) ($meta['manager_id'] ?? 0);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
        $managerIds = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $managerIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($managerIds as $employeeId) {
            $existing = DB::table('employee_roles')
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)
                ->where('role_id', $roleId)
                ->first();

            if ($existing) {
                DB::table('employee_roles')->where('id', $existing->id)->update([
                    'is_active' => DB::raw('true'),
                    'expiry_date' => null,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('employee_roles')->insert([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'role_id' => $roleId,
                'department_id' => null,
                'effective_date' => now()->toDateString(),
                'expiry_date' => null,
                'is_active' => DB::raw('true'),
                'meta' => json_encode(['source' => self::SOURCE], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('employee_roles')
            ->where('tenant_id', $tenantId)
            ->where('role_id', $roleId)
            ->get(['id', 'employee_id', 'meta'])
            ->each(function ($assignment) use ($managerIds): void {
                $meta = $this->decodeMeta($assignment->meta ?? null);
                if (($meta['source'] ?? null) !== self::SOURCE) {
                    return;
                }
                if (in_array((int) $assignment->employee_id, $managerIds, true)) {
                    return;
                }

                DB::table('employee_roles')->where('id', $assignment->id)->update([
                    'is_active' => DB::raw('false'),
                    'expiry_date' => now()->toDateString(),
                    'updated_at' => now(),
                ]);
            });
    }

    private function managerRoleId(int $tenantId): ?int
    {
        $roleId = DB::table('roles')
            ->where('tenant_id', $tenantId)
            ->where('role_code', 'MANAGER')
            ->value('id');

        if ($roleId) {
            $currentMeta = $this->decodeMeta(DB::table('roles')->where('id', $roleId)->value('meta'));
            unset($currentMeta['is_admin']);
            $currentMeta['modules'] = ['time'];
            DB::table('roles')->where('id', $roleId)->update([
                'role_name' => 'Trưởng phòng',
                'meta' => json_encode($currentMeta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            return (int) $roleId;
        }

        return (int) DB::table('roles')->insertGetId([
            'role_code' => 'MANAGER',
            'role_name' => 'Trưởng phòng',
            'description' => 'Trưởng phòng được gán trong sơ đồ phòng ban',
            'is_system_role' => DB::raw('true'),
            'meta' => json_encode(['modules' => ['time']], JSON_UNESCAPED_UNICODE),
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_object($meta)) {
            return (array) $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
