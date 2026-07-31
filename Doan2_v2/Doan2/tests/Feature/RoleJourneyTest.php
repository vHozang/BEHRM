<?php

namespace Tests\Feature;

use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * @return array{id:int, code:string, token:string}
     */
    private function actor(string $name, ?array $modules = null, bool $admin = false): array
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'QA'.strtoupper(substr($name, 0, 6)).Str::upper(Str::random(4)),
            'full_name' => Str::headline($name),
            'company_email' => $name.'.'.Str::lower(Str::random(6)).'@example.test',
            'status' => 'ACTIVE',
            'is_super_admin' => false,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($modules !== null || $admin) {
            $roleId = DB::table('roles')->insertGetId([
                'role_code' => strtoupper($name),
                'role_name' => Str::headline($name),
                'is_system_role' => true,
                'meta' => json_encode($admin ? ['is_admin' => true] : ['modules' => $modules]),
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
        }

        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'employee_id' => $employeeId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'tenant_id' => 1,
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
