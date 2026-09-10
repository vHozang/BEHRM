<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HrmApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_json(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 200);
    }

    public function test_login_and_authenticated_resource_flow(): void
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'T001',
            'full_name' => 'Test Employee',
            'company_email' => 'test.employee@company.com',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'is_super_admin' => true,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'company_email' => 'test.employee@company.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.company_email', 'test.employee@company.com');

        $this->withToken($token)
            ->getJson('/api/v1/auth/hierarchy')
            ->assertOk()
            ->assertJsonPath('data.employee.id', $employeeId)
            ->assertJsonPath('data.scope_employee_ids.0', $employeeId);

        $this->withToken($token)
            ->postJson('/api/v1/departments', [
                'department_code' => 'HR',
                'department_name' => 'Human Resources',
            ])
            ->assertCreated()
            ->assertJsonPath('data.department_code', 'HR');
    }

    public function test_protected_resources_require_bearer_token(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized();
    }

    public function test_employee_without_admin_role_cannot_write_admin_modules(): void
    {
        DB::table('employees')->insert([
            'employee_code' => 'T002',
            'full_name' => 'Portal Employee',
            'company_email' => 'portal.employee@company.com',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'company_email' => 'portal.employee@company.com',
            'password' => 'password',
        ])->assertOk()->json('data.access_token');

        $this->withToken($token)
            ->postJson('/api/v1/departments', [
                'department_code' => 'BLOCKED',
                'department_name' => 'Must not be created',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('departments', ['department_code' => 'BLOCKED']);
    }

    public function test_dashboard_headcount_excludes_system_accounts(): void
    {
        DB::table('employees')->insert([
            [
                'employee_code' => 'SYS001',
                'full_name' => 'System Account',
                'company_email' => 'system@company.com',
                'password_hash' => Hash::make('password'),
                'status' => 'ACTIVE',
                'is_super_admin' => true,
                'profile' => json_encode(['system_account' => true]),
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_code' => 'EMP001',
                'full_name' => 'Business Employee',
                'company_email' => 'business@company.com',
                'password_hash' => Hash::make('password'),
                'status' => 'ACTIVE',
                'is_super_admin' => false,
                'profile' => null,
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'company_email' => 'system@company.com',
            'password' => 'password',
        ])->assertOk()->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/v1/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.employees.total', 1)
            ->assertJsonPath('data.employees.by_status.ACTIVE', 1);
    }
}
