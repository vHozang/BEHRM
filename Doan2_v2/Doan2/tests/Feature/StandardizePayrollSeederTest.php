<?php

namespace Tests\Feature;

use App\Services\PayrollRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\StandardizePayrollSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class StandardizePayrollSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_months_once_and_keeps_the_current_period_open(): void
    {
        CarbonImmutable::setTestNow('2024-04-16 09:00:00');

        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_code' => 'PAY0001',
            'full_name' => 'Payroll Test',
            'status' => 'ACTIVE',
            'hire_date' => '2023-01-01',
            'base_salary' => 15000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendances')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $employeeId,
            'work_date' => '2024-03-01',
            'status' => 'ON_TIME',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchEntityId = DB::table('legal_entities')->insertGetId([
            'tenant_id' => 1,
            'name' => 'Chi nhánh Test',
            'code' => 'BRANCH-TEST',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchEmployeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => $branchEntityId,
            'employee_code' => 'PAY0002',
            'full_name' => 'Branch Payroll Test',
            'status' => 'ACTIVE',
            'hire_date' => '2023-01-01',
            'base_salary' => 15000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendances')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => $branchEntityId,
            'employee_id' => $branchEmployeeId,
            'work_date' => '2024-03-01',
            'status' => 'ON_TIME',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('salary_periods')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'period_code' => 'P-2024-03',
            'period_name' => 'Kỳ lương tháng 03/2024',
            'period_type' => 'MONTHLY',
            'start_date' => '2024-03-01',
            'end_date' => '2024-03-31',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeesByEntity = [1 => $employeeId, $branchEntityId => $branchEmployeeId];
        $this->mock(PayrollRunService::class, function (MockInterface $mock) use ($employeesByEntity): void {
            $mock->shouldReceive('run')->times(4)->andReturnUsing(function (int $periodId) use ($employeesByEntity): array {
                $period = DB::table('salary_periods')->find($periodId);
                $employeeId = $employeesByEntity[(int) $period->legal_entity_id];
                $detailId = DB::table('salary_details')->insertGetId([
                    'tenant_id' => 1,
                    'legal_entity_id' => $period->legal_entity_id,
                    'period_id' => $periodId,
                    'employee_id' => $employeeId,
                    'gross_salary' => 15000000,
                    'net_salary' => 13000000,
                    'transfer_status' => 'PENDING',
                    'meta' => json_encode(['engine' => 'vn-payroll-v1']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('salary_breakdowns')->insert([
                    'tenant_id' => 1,
                    'legal_entity_id' => $period->legal_entity_id,
                    'salary_detail_id' => $detailId,
                    'item_type' => 'EARNING',
                    'item_code' => 'BASE',
                    'item_name' => 'Lương cơ bản',
                    'amount' => 15000000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['period_id' => $periodId, 'employees_processed' => 1];
            });
        });

        $this->seed(StandardizePayrollSeeder::class);
        $this->seed(StandardizePayrollSeeder::class);

        $this->assertSame(4, DB::table('salary_periods')->count());
        $this->assertSame(2, DB::table('salary_periods')->where('period_code', 'P-2024-03')->where('status', 'PAID')->count());
        $this->assertSame(2, DB::table('salary_periods')->where('period_code', 'P-2024-04')->where('status', 'OPEN')->count());
        $this->assertSame(4, DB::table('salary_details')->count());
        $this->assertSame(4, DB::table('piece_rate_entries')->count());
        $this->assertSame(9, DB::table('salary_components')->count());

        CarbonImmutable::setTestNow();
    }
}
