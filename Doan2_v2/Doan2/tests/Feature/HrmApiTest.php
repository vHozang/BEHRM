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
        DB::table('employees')->insert([
            'employee_code' => 'T001',
            'full_name' => 'Test Employee',
            'company_email' => 'test.employee@company.com',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
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
}
