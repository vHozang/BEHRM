<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PayrollController;
use App\Services\PayrollRunService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollDataCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_links_the_effective_contract_and_payslip_returns_employee_context(): void
    {
        DB::table('legal_entities')->where('id', 1)->update([
            'name' => 'Công ty Demo',
            'tax_code' => '0312345678',
            'address' => 'TP. Hồ Chí Minh',
            'meta' => json_encode(['representative' => 'Nguyễn Văn A']),
        ]);
        $departmentId = DB::table('departments')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'department_code' => 'PAY',
            'department_name' => 'Tiền lương',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = DB::table('positions')->insertGetId([
            'tenant_id' => 1,
            'position_code' => 'PAYROLL',
            'position_name' => 'Chuyên viên tiền lương',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_code' => 'PAY0001',
            'full_name' => 'Payroll Test',
            'company_email' => 'payroll.test@example.com',
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'status' => 'ACTIVE',
            'base_salary' => 20000000,
            'profile' => json_encode([
                'bank_name' => 'Vietcombank',
                'bank_account' => '0123456789',
                'tax_number' => '079123456789',
                'insurance_number' => 'BHXH001',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('contracts')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $employeeId,
            'contract_number' => 'OLD-001',
            'status' => 'ACTIVE',
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
            'meta' => json_encode(['basic_salary' => 18000000]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $employeeId,
            'contract_number' => 'CURRENT-001',
            'status' => 'ACTIVE',
            'start_date' => '2026-07-01',
            'end_date' => null,
            'meta' => json_encode(['basic_salary' => 20000000]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $periodId = DB::table('salary_periods')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'period_code' => 'P-2026-07',
            'period_name' => 'Kỳ lương tháng 07/2026',
            'period_type' => 'MONTHLY',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantContext::set(1, 1);
        app(PayrollRunService::class)->run($periodId);

        $detail = DB::table('salary_details')->where('period_id', $periodId)->where('employee_id', $employeeId)->first();
        $this->assertSame($contractId, (int) $detail->contract_id);

        $payload = app(PayrollController::class)->payslip((int) $detail->id)->getData(true)['data'];
        $this->assertSame('Tiền lương', $payload['salary_detail']['employee']['department']['department_name']);
        $this->assertSame('Chuyên viên tiền lương', $payload['salary_detail']['employee']['position']['position_name']);
        $this->assertSame('0123456789', $payload['salary_detail']['employee']['profile']['bank_account']);
        $this->assertSame('079123456789', $payload['salary_detail']['employee']['profile']['tax_number']);
        $this->assertSame('CURRENT-001', $payload['salary_detail']['contract']['contract_number']);
        $this->assertSame('2026-07-01', $payload['salary_detail']['period']['start_date']);
        $this->assertSame('2026-07-31', $payload['salary_detail']['period']['end_date']);
        $this->assertSame('OPEN', $payload['salary_detail']['period']['status']);
        $this->assertSame('0312345678', $payload['legal_entity']['tax_code']);

        TenantContext::clear();
    }
}
