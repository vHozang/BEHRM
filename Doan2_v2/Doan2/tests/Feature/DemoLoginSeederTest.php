<?php

namespace Tests\Feature;

use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLoginSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_login_accounts_are_seeded_and_can_authenticate(): void
    {
        $this->seed(DemoAccountsSeeder::class);

        $accounts = [
            'an.nguyen@company.com' => ['password' => 'test1234', 'role' => 'ADMIN', 'name' => 'Quản trị viên'],
            'cuong.le@company.com' => ['password' => 'demo1234', 'role' => 'MANAGER', 'name' => 'Trưởng phòng'],
            'mai.tran@company.com' => ['password' => 'demo1234', 'role' => 'HR', 'name' => 'Nhân sự'],
            'huong.pham@company.com' => ['password' => 'demo1234', 'role' => 'EMPLOYEE', 'name' => 'Nhân viên'],
        ];

        foreach ($accounts as $email => $account) {
            $login = $this->postJson('/api/v1/auth/login', [
                'company_email' => $email,
                'password' => $account['password'],
            ])->assertOk()
                ->assertJsonPath('status', 200)
                ->assertJsonPath('data.employee.roles.0.role_code', $account['role'])
                ->assertJsonPath('data.employee.roles.0.role_name', $account['name'])
                ->assertJsonPath('data.access.roles.0.role_code', $account['role'])
                ->assertJsonStructure(['data' => ['access_token', 'employee', 'access']]);

            $this->withToken($login->json('data.access_token'))
                ->getJson('/api/v1/auth/me')
                ->assertOk()
                ->assertJsonPath('data.roles.0.role_code', $account['role'])
                ->assertJsonPath('data.roles.0.role_name', $account['name']);
        }
    }
}
