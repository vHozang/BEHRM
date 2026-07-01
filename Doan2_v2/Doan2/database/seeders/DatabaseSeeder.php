<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(LegacyDataSqlSeeder::class);

        DB::table('roles')->updateOrInsert(
            ['role_code' => 'ADMIN'],
            [
                'tenant_id' => 1,
                'role_name' => 'Administrator',
                'description' => 'System administrator',
                'is_system_role' => 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('employees')->updateOrInsert(
            ['company_email' => 'admin@company.com'],
            [
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_code' => 'AD0001',
                'full_name' => 'System Administrator',
                'password_hash' => Hash::make('password'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $employeeId = DB::table('employees')->where('company_email', 'admin@company.com')->value('id');
        $roleId = DB::table('roles')->where('role_code', 'ADMIN')->value('id');

        DB::table('employee_roles')->updateOrInsert(
            ['employee_id' => $employeeId, 'role_id' => $roleId],
            [
                'tenant_id' => 1,
                'is_active' => 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->call(DashboardDemoSeeder::class);
    }
}
