<?php

namespace Database\Seeders;

use App\Support\AccessControl;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $permissionIds = [];
            foreach (AccessControl::CAPABILITIES as $code => $name) {
                DB::table('permissions')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'permission_code' => $code],
                    [
                        'permission_name' => $name,
                        'module' => AccessControl::capabilityModule($code),
                        'description' => $name,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
                $permissionIds[$code] = (int) DB::table('permissions')
                    ->where('tenant_id', $tenantId)
                    ->where('permission_code', $code)
                    ->value('id');
            }

            $roles = DB::table('roles')->where('tenant_id', $tenantId)->get(['id', 'role_code']);
            foreach ($roles as $role) {
                foreach (AccessControl::defaultCapabilitiesForRole((string) $role->role_code) as $code) {
                    $permissionId = $permissionIds[$code] ?? null;
                    if (! $permissionId) {
                        continue;
                    }
                    DB::table('role_permissions')->updateOrInsert(
                        ['tenant_id' => $tenantId, 'role_id' => $role->id, 'permission_id' => $permissionId],
                        ['updated_at' => $now, 'created_at' => $now],
                    );
                }
            }
        }
    }
}
