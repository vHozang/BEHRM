<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Database\Seeders\ScaleWorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScaleWorkerSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repairs_existing_workers_and_keeps_related_data_in_sync(): void
    {
        CarbonImmutable::setTestNow('2026-07-17 09:00:00');
        putenv('HRM_SCALE_WORKERS=3');

        try {
            $probationTypeId = DB::table('contract_types')->insertGetId([
                'tenant_id' => 1,
                'contract_type_code' => 'HDTV',
                'contract_type_name' => 'Hợp đồng thử việc',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permanentTypeId = DB::table('contract_types')->insertGetId([
                'tenant_id' => 1,
                'contract_type_code' => 'HDLD01',
                'contract_type_name' => 'Hợp đồng không xác định thời hạn',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('shift_types')->insert([
                'tenant_id' => 1,
                'shift_code' => 'C1',
                'shift_name' => 'Ca 1',
                'start_time' => '06:00:00',
                'end_time' => '14:00:00',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $departmentId = DB::table('departments')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'department_code' => 'SX',
                'department_name' => 'Sản xuất',
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $boardId = DB::table('departments')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'department_code' => 'BGD',
                'department_name' => 'Ban Giám đốc',
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $directorPositionId = DB::table('positions')->insertGetId([
                'tenant_id' => 1,
                'position_code' => 'GD',
                'position_name' => 'Giám đốc',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $deputyPositionId = DB::table('positions')->insertGetId([
                'tenant_id' => 1,
                'position_code' => 'PGD',
                'position_name' => 'Phó giám đốc',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $managerId = DB::table('employees')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_code' => 'NV1000',
                'full_name' => 'Quản lý sản xuất',
                'department_id' => $departmentId,
                'position_id' => $directorPositionId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('departments')->where('id', $departmentId)->update([
                'meta' => json_encode(['manager_id' => $managerId]),
            ]);
            DB::table('employee_departments')->insert([
                'tenant_id' => 1,
                'employee_id' => $managerId,
                'department_id' => $departmentId,
                'role_in_dept' => 'MANAGER',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $expiredManagerContractId = DB::table('contracts')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_id' => $managerId,
                'contract_type_id' => $permanentTypeId,
                'status' => 'CÓ_HIỆU_LỰC',
                'start_date' => '2024-01-01',
                'end_date' => '2025-12-31',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $deputyId = DB::table('employees')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_code' => 'NV1001',
                'full_name' => 'Phó giám đốc',
                'department_id' => $departmentId,
                'position_id' => $deputyPositionId,
                'manager_id' => $managerId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $deputyContractId = DB::table('contracts')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_id' => $deputyId,
                'contract_type_id' => $permanentTypeId,
                'department_id' => $departmentId,
                'position_id' => $deputyPositionId,
                'status' => 'CÓ_HIỆU_LỰC',
                'start_date' => '2024-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('employee_departments')->insert([
                'tenant_id' => 1,
                'employee_id' => $deputyId,
                'department_id' => $departmentId,
                'role_in_dept' => 'MEMBER',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $workerId = DB::table('employees')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_code' => 'CN00001',
                'full_name' => 'Công nhân cũ',
                'department_id' => $departmentId,
                'status' => 'ACTIVE',
                'base_salary' => 7000000,
                'hire_date' => '2025-01-01',
                'profile' => json_encode(['source' => 'scale-seed']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('contracts')->insert([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_id' => $workerId,
                'contract_type_id' => $probationTypeId,
                'status' => 'CÓ_HIỆU_LỰC',
                'start_date' => '2025-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->seed(ScaleWorkerSeeder::class);

            $codes = ['CN00001', 'CN00002', 'CN00003'];
            $this->assertSame(3, DB::table('employees')->whereIn('employee_code', $codes)->count());
            $this->assertSame(3, DB::table('employees')->whereIn('employee_code', $codes)->where('manager_id', $managerId)->count());
            $this->assertSame($permanentTypeId, (int) DB::table('contracts')->where('employee_id', $workerId)->value('contract_type_id'));
            $this->assertNull(DB::table('contracts')->where('employee_id', $workerId)->value('end_date'));

            $newWorkerId = DB::table('employees')->where('employee_code', 'CN00002')->value('id');
            $this->assertSame($probationTypeId, (int) DB::table('contracts')->where('employee_id', $newWorkerId)->value('contract_type_id'));
            $this->assertSame('HẾT_HIỆU_LỰC', DB::table('contracts')->where('id', $expiredManagerContractId)->value('status'));
            $this->assertSame(2, DB::table('contracts')->where('employee_id', $managerId)->count());
            $this->assertSame($boardId, (int) DB::table('employees')->where('id', $managerId)->value('department_id'));
            $this->assertSame($managerId, (int) data_get(json_decode(DB::table('departments')->where('id', $boardId)->value('meta'), true), 'manager_id'));
            $this->assertSame($boardId, (int) DB::table('employees')->where('id', $deputyId)->value('department_id'));
            $this->assertSame($boardId, (int) DB::table('contracts')->where('id', $deputyContractId)->value('department_id'));
            $this->assertSame([$boardId], DB::table('employee_departments')->where('employee_id', $deputyId)->pluck('department_id')->map(fn ($id) => (int) $id)->all());
            $this->assertSame(3, DB::table('employee_departments')->whereIn('employee_id', DB::table('employees')->whereIn('employee_code', $codes)->pluck('id'))->count());
            $this->assertSame(3, DB::table('employment_histories')->whereRaw('is_current = true')->whereIn('employee_id', DB::table('employees')->whereIn('employee_code', $codes)->pluck('id'))->count());
            $this->assertSame(3, DB::table('shift_assignments')->whereIn('employee_id', DB::table('employees')->whereIn('employee_code', $codes)->pluck('id'))->count());

            $this->seed(ScaleWorkerSeeder::class);
            $this->assertSame(3, DB::table('employees')->whereIn('employee_code', $codes)->count());
            $this->assertSame(3, DB::table('contracts')->whereIn('employee_id', DB::table('employees')->whereIn('employee_code', $codes)->pluck('id'))->count());
        } finally {
            putenv('HRM_SCALE_WORKERS');
            CarbonImmutable::setTestNow();
        }
    }
}
