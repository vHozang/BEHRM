<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'ADMIN' => ['name' => 'Quản trị viên', 'meta' => ['is_admin' => true]],
            'HR' => ['name' => 'Nhân sự', 'meta' => ['modules' => ['hr', 'time', 'recruitment', 'communications']]],
            'MANAGER' => ['name' => 'Trưởng phòng', 'meta' => ['modules' => ['time']]],
            'EMPLOYEE' => ['name' => 'Nhân viên', 'meta' => ['modules' => []]],
        ];

        foreach ($roles as $code => $role) {
            DB::table('roles')->updateOrInsert(
                ['role_code' => $code],
                [
                    'role_name' => $role['name'],
                    'description' => "Demo {$role['name']} role",
                    'is_system_role' => DB::raw('true'),
                    'meta' => json_encode($role['meta']),
                    'tenant_id' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $accounts = [
            ['code' => 'NV0001', 'name' => 'Nguyễn Văn An', 'email' => 'an.nguyen@company.com', 'password' => 'test1234', 'role' => 'ADMIN', 'super_admin' => true],
            ['code' => 'NV0003', 'name' => 'Lê Văn Cường', 'email' => 'cuong.le@company.com', 'password' => 'demo1234', 'role' => 'MANAGER'],
            ['code' => 'NV0002', 'name' => 'Trần Thị Mai', 'email' => 'mai.tran@company.com', 'password' => 'demo1234', 'role' => 'HR'],
            ['code' => 'NV0004', 'name' => 'Phạm Thị Hương', 'email' => 'huong.pham@company.com', 'password' => 'demo1234', 'role' => 'EMPLOYEE'],
        ];

        foreach ($accounts as $account) {
            $employeeId = DB::table('employees')->where('company_email', $account['email'])->value('id')
                ?: DB::table('employees')->where('employee_code', $account['code'])->value('id');

            $employeeData = [
                'company_email' => $account['email'],
                'password_hash' => Hash::make($account['password']),
                'status' => 'ACTIVE',
                'updated_at' => now(),
            ];

            if (! $employeeId) {
                $employeeId = DB::table('employees')->insertGetId($employeeData + [
                    'employee_code' => $account['code'],
                    'full_name' => $account['name'],
                    'is_super_admin' => DB::raw(($account['super_admin'] ?? false) ? 'true' : 'false'),
                    'tenant_id' => 1,
                    'legal_entity_id' => 1,
                    'created_at' => now(),
                ]);
            } else {
                if ($account['super_admin'] ?? false) {
                    $employeeData['is_super_admin'] = DB::raw('true');
                }

                DB::table('employees')->where('id', $employeeId)->update($employeeData);
            }

            $roleId = DB::table('roles')->where('role_code', $account['role'])->value('id');

            DB::table('employee_roles')->updateOrInsert(
                ['employee_id' => $employeeId, 'role_id' => $roleId],
                [
                    'is_active' => DB::raw('true'),
                    'tenant_id' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
