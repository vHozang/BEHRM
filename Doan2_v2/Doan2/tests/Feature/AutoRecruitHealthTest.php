<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoRecruitHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_check_primary_and_fallback_resume_backends(): void
    {
        config([
            'services.autorecruit.url' => 'http://resume-primary.test',
            'services.autorecruit.fallback_urls' => ['http://resume-fallback.test'],
        ]);
        Http::fake([
            'http://resume-primary.test/health' => Http::response([], 503),
            'http://resume-fallback.test/health' => Http::response(['status' => 'ok']),
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/v1/settings/integrations/autorecruit/health')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.checks.0.healthy', false)
            ->assertJsonPath('data.checks.1.healthy', true);
    }

    private function adminToken(): string
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'QAHEALTH'.Str::upper(Str::random(4)),
            'full_name' => 'Integration Admin',
            'company_email' => Str::lower(Str::random(8)).'@health.test',
            'status' => 'ACTIVE',
            'is_super_admin' => true,
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
