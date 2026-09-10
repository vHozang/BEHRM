<?php

namespace Tests\Feature;

use Database\Seeders\ShiftQuickLoginSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftQuickLoginSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_three_idempotent_employee_shift_accounts_and_preserves_signed_contracts(): void
    {
        $this->seedReferences();
        $this->seed(ShiftQuickLoginSeeder::class);

        $accounts = [
            ['code' => 'CN00003', 'email' => 'nhanvien.ca1@devtapcode.io.vn', 'shift' => 'CA1'],
            ['code' => 'CN00001', 'email' => 'nhanvien.ca2@devtapcode.io.vn', 'shift' => 'CA2'],
            ['code' => 'CN00002', 'email' => 'nhanvien.ca3@devtapcode.io.vn', 'shift' => 'CA3'],
        ];

        foreach ($accounts as $account) {
            $login = $this->postJson('/api/v1/auth/login', [
                'company_email' => $account['email'],
                'password' => 'congnhan123',
            ])->assertOk()
                ->assertJsonPath('data.employee.employee_code', $account['code'])
                ->assertJsonPath('data.access.full', false)
                ->assertJsonPath('data.access.modules', []);

            $employeeId = (int) $login->json('data.employee.id');
            $shiftCode = DB::table('shift_assignments as assignment')
                ->join('shift_types as shift', 'shift.id', '=', 'assignment.shift_type_id')
                ->where('assignment.employee_id', $employeeId)
                ->where('assignment.status', 'ACTIVE')
                ->value('shift.shift_code');
            $this->assertSame($account['shift'], $shiftCode);

            $meta = json_decode((string) DB::table('contracts')->where('employee_id', $employeeId)->value('meta'), true);
            $this->assertSame('PENDING_SIGN', $meta['sign_status'] ?? null);
        }

        $signedEmployeeId = (int) DB::table('employees')->where('employee_code', 'CN00003')->value('id');
        $signedContractId = (int) DB::table('contracts')->where('employee_id', $signedEmployeeId)->value('id');
        DB::table('contracts')->where('id', $signedContractId)->update(['meta' => json_encode([
            'sign_status' => 'SIGNED',
            'signature' => ['image' => 'data:image/png;base64,c2lnbmVk'],
        ])]);

        $this->seed(ShiftQuickLoginSeeder::class);

        $this->assertSame(3, DB::table('employees')->whereIn('employee_code', array_column($accounts, 'code'))->count());
        $this->assertSame(3, DB::table('shift_assignments')->whereIn('employee_id',
            DB::table('employees')->whereIn('employee_code', array_column($accounts, 'code'))->pluck('id')
        )->where('status', 'ACTIVE')->count());
        $this->assertSame('SIGNED', json_decode((string) DB::table('contracts')->where('id', $signedContractId)->value('meta'), true)['sign_status']);
    }

    private function seedReferences(): void
    {
        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Demo tenant', 'code' => 'DEMO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1, 'name' => 'Demo entity', 'code' => 'DEMO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('roles')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1, 'role_code' => 'EMPLOYEE', 'role_name' => 'Employee',
            'is_system_role' => true, 'meta' => json_encode(['modules' => []]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contract_types')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1, 'contract_type_code' => 'HDLD01',
            'contract_type_name' => 'Hợp đồng không xác định thời hạn', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('departments')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1, 'legal_entity_id' => 1, 'department_code' => 'PX-A',
            'department_name' => 'Phân xưởng A', 'status' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('positions')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1, 'position_code' => 'CN',
            'position_name' => 'Công nhân', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['CA1', 'Ca 1'], ['CA2', 'Ca 2'], ['CA3', 'Ca 3']] as [$code, $name]) {
            DB::table('shift_types')->insert([
                'tenant_id' => 1, 'shift_code' => $code, 'shift_name' => $name,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
