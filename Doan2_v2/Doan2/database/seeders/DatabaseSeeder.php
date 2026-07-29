<?php

namespace Database\Seeders;

use App\Services\LeavePolicyService;
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
                'role_name' => 'Administrator',
                'description' => 'System administrator',
                'is_system_role' => 'true',
                'tenant_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        // RBAC access theo role (roles.meta). BẮT BUỘC set — AccessControl fail-OPEN
        // khi meta thiếu key 'modules' (coi như full admin), nên role không cấu hình
        // = mọi nhân viên thành admin. Set tường minh để đóng lỗ hổng đó.
        $roleMeta = [
            'ADMIN' => ['is_admin' => true],
            'HR' => ['modules' => ['hr', 'time', 'recruitment', 'communications']],
            'MANAGER' => ['modules' => ['time']],
            'ACCOUNTANT' => ['modules' => ['payroll']],
            'EMPLOYEE' => ['modules' => []],
        ];
        foreach ($roleMeta as $code => $meta) {
            DB::table('roles')->where('role_code', $code)
                ->update(['meta' => json_encode($meta), 'updated_at' => now()]);
        }

        $this->call(DemoAccountsSeeder::class);

        DB::table('employees')->updateOrInsert(
            ['company_email' => 'admin@company.com'],
            [
                'employee_code' => 'AD0001',
                'full_name' => 'System Administrator',
                'password_hash' => Hash::make('password'),
                'status' => 'ACTIVE',
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $employeeId = DB::table('employees')->where('company_email', 'admin@company.com')->value('id');
        $roleId = DB::table('roles')->where('role_code', 'ADMIN')->value('id');

        DB::table('employee_roles')->updateOrInsert(
            ['employee_id' => $employeeId, 'role_id' => $roleId],
            [
                'is_active' => 'true',
                'tenant_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->call(SeedDepartmentMetaSeeder::class);
        $this->call(LeaveTypeStatutorySeeder::class);
        $this->call(DemoCoverageSeeder::class);
        $this->call(DashboardDemoSeeder::class);
        $this->call(ScaleWorkerSeeder::class);
        $this->call(EnrichWorkerProfilesSeeder::class);
        $this->call(StandardizeAttendanceSeeder::class);
        $this->call(LiveTodayAttendanceSeeder::class);
        $this->call(SeedDemoOvertimeSeeder::class);
        $this->call(BackfillEmployeeProfilesSeeder::class);
        $this->call(FixDemoDataConsistencySeeder::class);
        $this->call(BackfillEmployeeBaseSalarySeeder::class);
        $this->call(StandardizePayrollSeeder::class);

        // Tài khoản demo KẾ TOÁN (role ACCOUNTANT → chỉ module payroll). phuc.trinh
        // (NV0013) do DemoCoverageSeeder tạo nên gán ở đây, sau khi seeder đã chạy.
        $accountantRoleId = DB::table('roles')->where('role_code', 'ACCOUNTANT')->value('id');
        $phuc = DB::table('employees')->where('company_email', 'phuc.trinh@company.com')->value('id');
        if ($accountantRoleId && $phuc) {
            DB::table('employee_roles')->updateOrInsert(
                ['employee_id' => $phuc, 'role_id' => $accountantRoleId],
                ['is_active' => 'true', 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
            DB::table('employees')->where('id', $phuc)
                ->update(['password_hash' => Hash::make('ketoan1234'), 'updated_at' => now()]);
        }

        // Cấp/đối soát số dư phép năm HIỆN TẠI cho mọi nhân viên của từng tenant.
        // BẮT BUỘC: thiếu balance năm N thì nhân viên KHÔNG xin nghỉ phép năm được
        // (LeaveController::store chặn "chưa có số dư phép cho năm N") → hỏng luồng
        // self-service ngay ngày đầu deploy. Đây là dữ liệu, không phải cấu hình.
        $leavePolicy = app(LeavePolicyService::class);
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $leavePolicy->recomputeBalances((int) $tenantId, (int) now()->year);
        }
    }
}
