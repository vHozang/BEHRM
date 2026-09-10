<?php

namespace Tests\Feature;

use App\Support\EmployeeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_is_not_changed_to_terminated_by_missing_or_expired_contracts(): void
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'STAT0001',
            'full_name' => 'Status Test',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('ACTIVE', EmployeeStatus::resolve($employeeId, '2026-07-16'));

        $probationTypeId = DB::table('contract_types')->insertGetId([
            'contract_type_code' => 'HDTV',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId,
            'contract_type_id' => $probationTypeId,
            'status' => 'ACTIVE',
            'start_date' => '2024-01-01',
            'end_date' => '2024-03-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('ACTIVE', EmployeeStatus::resolve($employeeId, '2026-07-16'));

        DB::table('contracts')->where('id', $contractId)->update(['end_date' => null]);
        $this->assertSame('PROBATION', EmployeeStatus::resolve($employeeId, '2026-07-16'));

        $activeId = DB::table('employees')->insertGetId([
            'employee_code' => 'STAT0002',
            'full_name' => 'Active Test',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $statuses = EmployeeStatus::resolveMany(
            DB::table('employees')->whereIn('id', [$employeeId, $activeId])->get(),
            '2026-07-16'
        );
        $this->assertSame('PROBATION', $statuses[$employeeId]);
        $this->assertSame('ACTIVE', $statuses[$activeId]);

        DB::table('employees')->where('id', $employeeId)->update(['status' => 'TERMINATED']);
        $this->assertSame('TERMINATED', EmployeeStatus::resolve($employeeId, '2026-07-16'));
    }
}
