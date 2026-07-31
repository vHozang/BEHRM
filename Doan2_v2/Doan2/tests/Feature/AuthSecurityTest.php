<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_credentials_return_401_without_creating_a_token(): void
    {
        $this->createEmployee('auth.invalid@example.test', 'CorrectPassword123');

        $this->postJson('/api/v1/auth/login', [
            'company_email' => 'auth.invalid@example.test',
            'password' => 'WrongPassword123',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials')
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_login_is_throttled_after_ten_attempts_per_minute(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.13']);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'company_email' => 'missing@example.test',
                'password' => 'WrongPassword123',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'company_email' => 'missing@example.test',
            'password' => 'WrongPassword123',
        ])->assertStatus(429);
    }

    public function test_change_password_rejects_an_incorrect_current_password(): void
    {
        $employeeId = $this->createEmployee('auth.current@example.test', 'OldPassword123');
        $token = $this->login('auth.current@example.test', 'OldPassword123');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'IncorrectPassword123',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $passwordHash = DB::table('employees')->where('id', $employeeId)->value('password_hash');
        $this->assertTrue(Hash::check('OldPassword123', $passwordHash));
        $this->assertDatabaseCount('api_tokens', 1);
    }

    public function test_change_password_updates_the_hash_and_revokes_every_session(): void
    {
        $employeeId = $this->createEmployee('auth.change@example.test', 'OldPassword123');
        $firstToken = $this->login('auth.change@example.test', 'OldPassword123');
        $secondToken = $this->login('auth.change@example.test', 'OldPassword123');

        $this->assertDatabaseCount('api_tokens', 2);

        $this->withToken($firstToken)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        $passwordHash = DB::table('employees')->where('id', $employeeId)->value('password_hash');
        $this->assertTrue(Hash::check('NewPassword456', $passwordHash));
        $this->assertFalse(Hash::check('OldPassword123', $passwordHash));
        $this->assertDatabaseCount('api_tokens', 0);

        $this->withToken($secondToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'company_email' => 'auth.change@example.test',
            'password' => 'OldPassword123',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'company_email' => 'auth.change@example.test',
            'password' => 'NewPassword456',
        ])->assertOk();
    }

    public function test_change_password_enforces_confirmation_and_password_policy(): void
    {
        $this->createEmployee('auth.policy@example.test', 'OldPassword123');
        $token = $this->login('auth.policy@example.test', 'OldPassword123');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword123',
            'password' => 'onlyletters',
            'password_confirmation' => 'different-value',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_forgot_password_returns_a_neutral_response_and_creates_a_reset_request(): void
    {
        $employeeId = $this->createEmployee('auth.forgot@example.test', 'OldPassword123');
        config(['app.frontend_url' => 'https://devtapcode.io.vn']);

        $expectedResponse = [
            'status' => 200,
            'message' => 'If the email exists, a reset request has been created',
            'data' => null,
        ];

        $this->postJson('/api/v1/auth/forgot-password', [
            'company_email' => 'auth.forgot@example.test',
        ])->assertOk()->assertExactJson($expectedResponse);

        $reset = DB::table('password_reset_requests')
            ->where('employee_id', $employeeId)
            ->first();

        $this->assertNotNull($reset);
        $this->assertSame(64, strlen($reset->token));
        $this->assertNull($reset->used_at);

        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString(
            'https://devtapcode.io.vn/reset-password?token='.$reset->token,
            $messages->first()->getOriginalMessage()->getTextBody(),
        );

        $this->postJson('/api/v1/auth/forgot-password', [
            'company_email' => 'missing@example.test',
        ])->assertOk()->assertExactJson($expectedResponse);

        $this->assertDatabaseMissing('password_reset_requests', [
            'company_email' => 'missing@example.test',
        ]);
    }

    public function test_reset_password_is_single_use_and_revokes_existing_sessions(): void
    {
        $employeeId = $this->createEmployee('auth.reset@example.test', 'OldPassword123');
        $activeToken = $this->login('auth.reset@example.test', 'OldPassword123');
        $resetToken = str_repeat('a', 64);

        DB::table('password_reset_requests')->insert([
            'employee_id' => $employeeId,
            'company_email' => 'auth.reset@example.test',
            'token' => $resetToken,
            'expires_at' => now()->addHour(),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'token' => $resetToken,
            'password' => 'ResetPassword456',
            'password_confirmation' => 'ResetPassword456',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        $passwordHash = DB::table('employees')->where('id', $employeeId)->value('password_hash');
        $this->assertTrue(Hash::check('ResetPassword456', $passwordHash));
        $this->assertNotNull(DB::table('password_reset_requests')->where('token', $resetToken)->value('used_at'));
        $this->assertDatabaseCount('api_tokens', 0);
        $this->withToken($activeToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid reset token');
    }

    public function test_reset_password_rejects_an_expired_token(): void
    {
        $employeeId = $this->createEmployee('auth.expired@example.test', 'OldPassword123');
        $resetToken = str_repeat('b', 64);

        DB::table('password_reset_requests')->insert([
            'employee_id' => $employeeId,
            'company_email' => 'auth.expired@example.test',
            'token' => $resetToken,
            'expires_at' => now()->subMinute(),
            'tenant_id' => 1,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken,
            'password' => 'ResetPassword456',
            'password_confirmation' => 'ResetPassword456',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid reset token');

        $passwordHash = DB::table('employees')->where('id', $employeeId)->value('password_hash');
        $this->assertTrue(Hash::check('OldPassword123', $passwordHash));
    }

    private function createEmployee(string $email, string $password): int
    {
        return DB::table('employees')->insertGetId([
            'employee_code' => 'AUTH'.strtoupper(substr(hash('sha256', $email), 0, 8)),
            'full_name' => 'Auth Security Tester',
            'company_email' => $email,
            'password_hash' => Hash::make($password),
            'status' => 'ACTIVE',
            'is_super_admin' => false,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function login(string $email, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'company_email' => $email,
            'password' => $password,
        ])->assertOk()->json('data.access_token');
    }
}
