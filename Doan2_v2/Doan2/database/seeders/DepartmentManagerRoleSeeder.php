<?php

namespace Database\Seeders;

use App\Services\DepartmentManagerRoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentManagerRoleSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(DepartmentManagerRoleService::class);

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $service->syncTenant((int) $tenantId);
        }
    }
}
