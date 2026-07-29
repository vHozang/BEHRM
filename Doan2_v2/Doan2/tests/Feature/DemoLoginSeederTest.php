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
            'an.nguyen@company.com' => 'test1234',
            'cuong.le@company.com' => 'demo1234',
            'mai.tran@company.com' => 'demo1234',
            'huong.pham@company.com' => 'demo1234',
        ];

        foreach ($accounts as $email => $password) {
            $this->postJson('/api/v1/auth/login', [
                'company_email' => $email,
                'password' => $password,
            ])->assertOk()
                ->assertJsonPath('status', 200)
                ->assertJsonStructure(['data' => ['access_token', 'employee', 'access']]);
        }
    }
}
