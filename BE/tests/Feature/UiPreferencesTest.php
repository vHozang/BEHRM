<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UiPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['hrm.display.money_group_separator' => '.']);
        config(['hrm.attendance.weekly_rest_weekday' => 6]);
        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'UI preference tenant',
            'code' => 'UI-PREF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1,
            'name' => 'UI preference entity',
            'code' => 'UI-PREF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_admin_configures_money_separator_for_every_authenticated_role(): void
    {
        $adminToken = $this->actor(true);
        $employeeToken = $this->actor(false);

        $this->withToken($employeeToken)
            ->getJson('/api/v1/auth/ui-preferences')
            ->assertOk()
            ->assertJsonPath('data.money_group_separator', '.')
            ->assertJsonPath('data.weekly_rest_weekday', 6);

        $this->withToken($adminToken)
            ->postJson('/api/v1/settings/save', [
                'items' => [[
                    'key' => 'display.money_group_separator',
                    'value' => ',',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.saved.0', 'display.money_group_separator');

        $this->withToken($employeeToken)
            ->getJson('/api/v1/auth/ui-preferences')
            ->assertOk()
            ->assertJsonPath('data.money_group_separator', ',');

        $this->withToken($adminToken)
            ->postJson('/api/v1/settings/save', [
                'items' => [[
                    'key' => 'display.money_group_separator',
                    'value' => ';',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['Dấu phân cách tiền chỉ có thể là dấu chấm hoặc dấu phẩy']);
    }

    private function actor(bool $superAdmin): string
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'UIP'.Str::upper(Str::random(7)),
            'full_name' => $superAdmin ? 'UI Admin' : 'UI Employee',
            'company_email' => Str::lower(Str::random(10)).'@ui-pref.test',
            'status' => 'ACTIVE',
            'is_super_admin' => $superAdmin,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
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

        return $token;
    }
}
