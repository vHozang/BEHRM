<?php

namespace Tests\Feature;

use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Role test tenant',
            'code' => 'ROLE-TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1,
            'name' => 'Role test entity',
            'code' => 'ROLE-TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_management_roles_only_reach_their_modules(): void
    {
        $admin = $this->actor('admin', null, true);
        $hr = $this->actor('hr', ['hr', 'time', 'recruitment', 'communications']);
        $manager = $this->actor('manager', ['time']);
        $accountant = $this->actor('accountant', ['payroll']);
        $employee = $this->actor('employee');

        $this->withToken($admin['token'])->getJson('/api/v1/settings/catalog')->assertOk();
        $this->withToken($admin['token'])->getJson('/api/v1/salary-periods')->assertOk();

        $this->withToken($hr['token'])->getJson('/api/v1/assets')->assertOk();
        $this->withToken($hr['token'])->getJson('/api/v1/recruitment-candidates')->assertOk();
        $this->withToken($hr['token'])->getJson('/api/v1/recruitment-posts')->assertOk();
        Http::fake([
            '*/feedback/stats' => Http::response(['total_feedback' => 0]),
            '*/feedback/adjustments' => Http::response(['adjustments' => []]),
        ]);
        $this->withToken($hr['token'])->getJson('/api/v1/recruitment-ai/feedback-stats')->assertOk();
        $this->withToken($hr['token'])->getJson('/api/v1/salary-periods')->assertForbidden();

        $this->withToken($manager['token'])->getJson('/api/v1/attendances')->assertOk();
        $this->withToken($manager['token'])->getJson('/api/v1/assets')->assertForbidden();
        $this->withToken($manager['token'])->getJson('/api/v1/salary-periods')->assertForbidden();
        $managerDashboard = $this->withToken($manager['token'])->getJson('/api/v1/dashboard/stats')->assertOk()->json('data');
        $this->assertArrayHasKey('attendances_today', $managerDashboard);
        $this->assertArrayNotHasKey('recruitment', $managerDashboard);
        $this->assertArrayNotHasKey('contracts', $managerDashboard);
        $this->assertArrayNotHasKey('upcoming', $managerDashboard);

        $this->withToken($accountant['token'])->getJson('/api/v1/salary-periods')->assertOk();
        $this->withToken($accountant['token'])->getJson('/api/v1/attendances')->assertForbidden();
        $this->withToken($accountant['token'])->getJson('/api/v1/assets')->assertForbidden();
        $this->withToken($accountant['token'])->getJson('/api/v1/dashboard/stats')->assertForbidden();

        $this->withToken($employee['token'])->getJson('/api/v1/news')->assertOk();
        $this->withToken($employee['token'])->postJson('/api/v1/news', ['title' => 'Blocked'])->assertForbidden();
        $this->withToken($employee['token'])->getJson('/api/v1/assets')->assertForbidden();
        $this->withToken($employee['token'])->getJson('/api/v1/dashboard/stats')->assertForbidden();
        $this->withToken($employee['token'])
            ->getJson('/api/v1/attendances?employee_id='.$employee['id'])
            ->assertOk();

        $this->assertFalse(AccessControl::allows(
            ['full' => false, 'modules' => []],
            'GET',
            'unmapped-future-resource'
        ));
    }

    public function test_helpdesk_is_self_scoped_and_keeps_history(): void
    {
        $first = $this->actor('first');
        $second = $this->actor('second');
        $hr = $this->actor('helpdesk', ['communications']);

        $firstTicket = $this->withToken($first['token'])->postJson('/api/v1/service-tickets', [
            'ticket_code' => 'QA-1',
            'requester_id' => $second['id'],
            'title' => 'First ticket',
            'description' => 'Created by first employee',
            'priority' => 'normal',
            'status' => 'completed',
        ])->assertCreated()
            ->assertJsonPath('data.requester_id', $first['id'])
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $secondTicket = $this->withToken($second['token'])->postJson('/api/v1/service-tickets', [
            'ticket_code' => 'QA-2',
            'title' => 'Second ticket',
            'description' => 'Created by second employee',
            'priority' => 'high',
        ])->assertCreated()->json('data.id');

        $this->withToken($first['token'])->getJson('/api/v1/service-tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $firstTicket);
        $this->withToken($first['token'])
            ->getJson('/api/v1/service-tickets/'.$secondTicket)
            ->assertNotFound();
        $this->withToken($first['token'])
            ->patchJson('/api/v1/service-tickets/'.$secondTicket, ['status' => 'cancelled'])
            ->assertForbidden();

        $this->withToken($first['token'])
            ->patchJson('/api/v1/service-tickets/'.$firstTicket, ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->withToken($first['token'])
            ->deleteJson('/api/v1/service-tickets/'.$firstTicket)
            ->assertConflict();
        $this->assertDatabaseHas('service_tickets', ['id' => $firstTicket, 'status' => 'cancelled']);

        $this->withToken($hr['token'])->getJson('/api/v1/service-tickets')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
        $this->withToken($hr['token'])
            ->patchJson('/api/v1/service-tickets/'.$secondTicket, ['status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_employee_directory_hides_sensitive_fields_from_regular_employees(): void
    {
        $viewer = $this->actor('viewer');
        DB::table('employees')->where('id', $viewer['id'])->update([
            'personal_email' => 'private@example.test',
            'date_of_birth' => '1990-01-01',
            'base_salary' => 50000000,
            'profile' => json_encode(['identity_number' => 'secret']),
        ]);

        $item = $this->withToken($viewer['token'])
            ->getJson('/api/v1/employees?employee_code='.$viewer['code'])
            ->assertOk()
            ->json('data.items.0');

        $this->assertArrayNotHasKey('personal_email', $item);
        $this->assertArrayNotHasKey('date_of_birth', $item);
        $this->assertArrayNotHasKey('base_salary', $item);
        $this->assertArrayNotHasKey('profile', $item);
    }

    public function test_employee_cannot_read_another_employees_private_profile(): void
    {
        $viewer = $this->actor('profile-viewer');
        $target = $this->actor('profile-target');
        $hr = $this->actor('profile-hr', ['hr']);

        DB::table('employees')->where('id', $target['id'])->update([
            'base_salary' => 50000000,
            'profile' => json_encode(['identity_number' => 'secret-profile']),
        ]);
        DB::table('social_insurance_info')->insert([
            'employee_id' => $target['id'],
            'social_insurance_number' => 'SI-SECRET',
            'tax_code' => 'TAX-SECRET',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($viewer['token'])
            ->getJson('/api/v1/employees/'.$target['id'].'/profile')
            ->assertForbidden()
            ->assertJsonMissing(['base_salary' => 50000000])
            ->assertJsonMissing(['tax_code' => 'TAX-SECRET']);

        $this->withToken($target['token'])
            ->getJson('/api/v1/employees/'.$target['id'].'/profile')
            ->assertOk()
            ->assertJsonPath('data.employee.base_salary', 50000000)
            ->assertJsonPath('data.social_insurance.tax_code', 'TAX-SECRET');

        $this->withToken($hr['token'])
            ->getJson('/api/v1/employees/'.$target['id'].'/profile')
            ->assertOk()
            ->assertJsonPath('data.social_insurance.social_insurance_number', 'SI-SECRET');
    }

    public function test_management_ui_endpoints_enforce_roles_tenants_and_idempotency(): void
    {
        DB::table('tenants')->insert([
            'id' => 2, 'name' => 'Second tenant', 'code' => 'ROLE-TEST-2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'id' => 2, 'tenant_id' => 2, 'name' => 'Second entity', 'code' => 'ROLE-TEST-2',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $admin = $this->actor('admin-ui', null, true);
        $otherAdmin = $this->actor('admin-ui-2', null, true, 2);
        $hr = $this->actor('hr', ['hr', 'time', 'recruitment']);
        $manager = $this->actor('manager', ['time']);
        $accountant = $this->actor('accountant', ['payroll']);
        $target = $this->actor('certificate-target');

        $template = [
            'notification_type' => 'candidate_applied',
            'recipients' => 'HR',
            'template_subject' => 'Có ứng viên mới',
            'template_body' => 'Ứng viên {{candidate_name}} vừa nộp hồ sơ.',
            'status' => true,
        ];
        $this->withToken($admin['token'])->putJson('/api/v1/settings/notifications', ['items' => [$template]])
            ->assertOk()->assertJsonPath('data.items.0.notification_type', 'candidate_applied');
        $this->withToken($otherAdmin['token'])->getJson('/api/v1/settings/notifications')
            ->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($hr['token'])->getJson('/api/v1/settings/notifications')->assertForbidden();

        $probationEmail = 'probation.'.Str::lower(Str::random(8)).'@example.test';
        $this->withToken($hr['token'])->postJson('/api/v1/employees/import-probation', [
            'employees' => [
                ['employee_code' => 'TV'.Str::upper(Str::random(6)), 'full_name' => 'Valid Probation', 'company_email' => $probationEmail],
                ['employee_code' => 'TV'.Str::upper(Str::random(6)), 'full_name' => 'Missing Email'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonCount(1, 'data.errors');
        $this->withToken($manager['token'])->postJson('/api/v1/employees/import-probation', [
            'employees' => [['full_name' => 'Blocked', 'company_email' => 'blocked@example.test']],
        ])->assertForbidden();

        $certificateId = $this->withToken($hr['token'])->postJson('/api/v1/employees/'.$target['id'].'/certificates', [
            'certificate_name' => 'AWS Developer',
            'issued_by' => 'Amazon Web Services',
            'issued_date' => '2026-01-01',
            'expiry_date' => '2028-01-01',
            'certificate_number' => 'AWS-QA-1',
            'score' => 90,
            'file_url' => 'https://example.test/aws-certificate',
        ])->assertCreated()->json('data.id');
        $this->withToken($hr['token'])->getJson('/api/v1/employees/'.$target['id'].'/certificates')
            ->assertOk()->assertJsonPath('data.0.id', $certificateId);
        $this->withToken($manager['token'])->deleteJson('/api/v1/employees/'.$target['id'].'/certificates/'.$certificateId)
            ->assertForbidden();
        $this->withToken($hr['token'])->deleteJson('/api/v1/employees/'.$target['id'].'/certificates/'.$certificateId)
            ->assertOk();

        Http::fake([
            '*/feedback/stats' => Http::response(['total_feedbacks' => 3, 'distribution' => ['aligned_pct' => 66.7]]),
            '*/feedback/adjustments' => Http::response(['total_feedbacks' => 3, 'adjustments' => []]),
        ]);
        $this->withToken($hr['token'])->getJson('/api/v1/recruitment-ai/feedback-stats')->assertOk();
        $this->withToken($manager['token'])->getJson('/api/v1/recruitment-ai/feedback-stats')->assertForbidden();

        DB::table('employees')->where('id', $target['id'])->update([
            'base_salary' => 12000000,
            'hire_date' => '2025-01-01',
        ]);
        $periodId = DB::table('salary_periods')->insertGetId([
            'period_code' => 'QA-BONUS-2026', 'period_name' => 'QA Bonus', 'period_type' => 'MONTHLY',
            'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'OPEN',
            'tenant_id' => 1, 'legal_entity_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bonusPayload = [
            'salary_period_id' => $periodId,
            'window_start' => '2026-01-01',
            'window_end' => '2026-06-30',
            'rate_percent' => 50,
        ];
        $this->withToken($hr['token'])->postJson('/api/v1/payroll/bonus-run', $bonusPayload)->assertForbidden();
        $this->withToken($accountant['token'])->postJson('/api/v1/payroll/bonus-run', $bonusPayload)
            ->assertOk()->assertJsonPath('data.batch', 'BONUS-20260101-20260630');
        $firstBatchCount = DB::table('payroll_adjustments')->where('paid_period_id', $periodId)->count();
        $this->withToken($accountant['token'])->postJson('/api/v1/payroll/bonus-run', $bonusPayload)->assertOk();
        $this->assertSame($firstBatchCount, DB::table('payroll_adjustments')->where('paid_period_id', $periodId)->count());
        DB::table('salary_periods')->where('id', $periodId)->update(['status' => 'LOCKED']);
        $this->withToken($accountant['token'])->postJson('/api/v1/payroll/bonus-run', $bonusPayload)->assertStatus(422);

        $this->withToken($hr['token'])->postJson('/api/v1/leave/accrual/run', ['year' => 2026])
            ->assertOk()->assertJsonPath('data.year', 2026);
        $this->withToken($manager['token'])->postJson('/api/v1/leave/accrual/run', ['year' => 2026])->assertForbidden();
    }

    /**
     * @return array{id:int, code:string, token:string}
     */
    private function actor(string $name, ?array $modules = null, bool $admin = false, int $tenantId = 1): array
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'QA'.strtoupper(substr($name, 0, 6)).Str::upper(Str::random(4)),
            'full_name' => Str::headline($name),
            'company_email' => $name.'.'.Str::lower(Str::random(6)).'@example.test',
            'status' => 'ACTIVE',
            'is_super_admin' => false,
            'tenant_id' => $tenantId,
            'legal_entity_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($modules !== null || $admin) {
            $roleId = DB::table('roles')->insertGetId([
                'role_code' => strtoupper($name),
                'role_name' => Str::headline($name),
                'is_system_role' => true,
                'meta' => json_encode($admin ? ['is_admin' => true] : ['modules' => $modules]),
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('employee_roles')->insert([
                'employee_id' => $employeeId,
                'role_id' => $roleId,
                'is_active' => true,
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'employee_id' => $employeeId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $employeeId,
            'code' => DB::table('employees')->where('id', $employeeId)->value('employee_code'),
            'token' => $token,
        ];
    }
}
