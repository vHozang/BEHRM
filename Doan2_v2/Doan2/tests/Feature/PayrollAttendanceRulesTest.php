<?php

namespace Tests\Feature;

use App\Services\PayrollRunService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollAttendanceRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_attendance_deduction_reduces_only_net_and_uses_base_salary_per_standard_day(): void
    {
        $violatingEmployee = $this->employee('PAY-LATE-1', 20000000);
        $controlEmployee = $this->employee('PAY-CONTROL', 20000000);
        $periodId = $this->period('PAY-ATT-2026-08');
        $attendanceId = DB::table('attendances')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $violatingEmployee,
            'work_date' => '2026-08-11',
            'check_in_time' => '06:15:00',
            'check_out_time' => '14:00:00',
            'status' => 'LATE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reviewId = DB::table('attendance_payroll_reviews')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'attendance_id' => $attendanceId,
            'employee_id' => $violatingEmployee,
            'work_date' => '2026-08-11',
            'late_minutes' => 15,
            'early_leave_minutes' => 0,
            'default_percent' => 0,
            'approved_percent' => 25,
            'status' => 'APPROVED',
            'decision_note' => 'Áp dụng theo nội quy kiểm thử.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantContext::set(1, 1);
        app(PayrollRunService::class)->run($periodId);

        $violating = DB::table('salary_details')->where('period_id', $periodId)->where('employee_id', $violatingEmployee)->first();
        $control = DB::table('salary_details')->where('period_id', $periodId)->where('employee_id', $controlEmployee)->first();
        $violatingMeta = json_decode((string) $violating->meta, true);
        $controlMeta = json_decode((string) $control->meta, true);
        $standardDays = (float) DB::table('salary_attendance_summary')
            ->where('period_id', $periodId)
            ->where('employee_id', $violatingEmployee)
            ->value('standard_days');
        $expected = round((20000000 / $standardDays) * 0.25, 4);

        $this->assertEqualsWithDelta((float) $control->gross_salary, (float) $violating->gross_salary, 0.01);
        $this->assertEqualsWithDelta((float) $controlMeta['insurance_base'], (float) $violatingMeta['insurance_base'], 0.01);
        $this->assertEqualsWithDelta((float) $controlMeta['taxable_income'], (float) $violatingMeta['taxable_income'], 0.01);
        $this->assertEqualsWithDelta((float) $controlMeta['pit']['tax'], (float) $violatingMeta['pit']['tax'], 0.01);
        $this->assertEqualsWithDelta($expected, (float) $violatingMeta['attendance_violation_deduction'], 0.01);
        $this->assertEqualsWithDelta($expected, (float) $control->net_salary - (float) $violating->net_salary, 0.01);
        $this->assertDatabaseHas('salary_breakdowns', [
            'salary_detail_id' => $violating->id,
            'item_type' => 'DEDUCTION',
            'item_code' => 'ATTENDANCE_VIOLATION',
            'amount' => $expected,
        ]);
        $this->assertDatabaseHas('attendance_payroll_reviews', [
            'id' => $reviewId,
            'status' => 'APPLIED',
        ]);
    }

    public function test_pending_review_blocks_submit_even_when_allow_partial_is_true(): void
    {
        $employeeId = $this->employee('PAY-PENDING', 12000000);
        $periodId = $this->period('PAY-BLOCK-2026-08');
        TenantContext::set(1, 1);
        app(PayrollRunService::class)->run($periodId);

        $attendanceId = DB::table('attendances')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $employeeId,
            'work_date' => '2026-08-11',
            'check_in_time' => '06:15:00',
            'check_out_time' => '14:00:00',
            'status' => 'LATE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendance_payroll_reviews')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'attendance_id' => $attendanceId,
            'employee_id' => $employeeId,
            'work_date' => '2026-08-11',
            'late_minutes' => 15,
            'default_percent' => 0,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $accountant = $this->actor('ACCOUNTANT', ['payroll']);

        $this->withToken($accountant['token'])
            ->postJson("/api/v1/salary-periods/{$periodId}/submit", ['allow_partial' => true])
            ->assertUnprocessable()
            ->assertJsonPath('data.readiness.has_non_bypassable_issues', true)
            ->assertJsonPath('data.readiness.issues.0.can_override', false);

        $this->assertDatabaseHas('salary_periods', ['id' => $periodId, 'status' => 'OPEN']);
    }

    private function employee(string $code, float $baseSalary, array $profile = []): int
    {
        return DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_code' => $code,
            'full_name' => str_replace('-', ' ', $code),
            'company_email' => strtolower($code).'@example.test',
            'base_salary' => $baseSalary,
            'profile' => $profile === [] ? null : json_encode($profile),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function period(string $code): int
    {
        return DB::table('salary_periods')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'period_code' => $code,
            'period_name' => $code,
            'period_type' => 'MONTHLY',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{id:int, token:string} */
    private function actor(string $roleCode, array $modules): array
    {
        $employeeId = $this->employee('SYS-'.$roleCode, 1, ['system_account' => true]);
        $roleId = DB::table('roles')->insertGetId([
            'role_code' => $roleCode,
            'role_name' => Str::headline(strtolower($roleCode)),
            'is_system_role' => true,
            'meta' => json_encode(['modules' => $modules]),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_roles')->insert([
            'employee_id' => $employeeId,
            'role_id' => $roleId,
            'is_active' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'employee_id' => $employeeId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $employeeId, 'token' => $token];
    }
}
