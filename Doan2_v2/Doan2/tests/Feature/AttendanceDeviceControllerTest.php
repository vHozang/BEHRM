<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_detail_is_tenant_scoped_and_does_not_expose_secrets(): void
    {
        $token = $this->adminToken();
        $ownId = $this->device(1, 1, 'own-secret-token', ['ip' => '192.168.1.10', 'api_key' => 'vendor-secret']);

        DB::table('tenants')->insert([
            'id' => 2,
            'name' => 'Other tenant',
            'code' => 'OTHER-DEVICE',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'id' => 2,
            'tenant_id' => 2,
            'name' => 'Other entity',
            'code' => 'OTHER-DEVICE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherId = $this->device(2, 2, 'other-secret-token');

        $this->withToken($token)
            ->getJson("/api/v1/attendance-devices/{$ownId}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownId)
            ->assertJsonMissingPath('data.device_token')
            ->assertJsonMissingPath('data.meta.api_key')
            ->assertJsonPath('data.meta.ip', '192.168.1.10');

        $this->withToken($token)
            ->getJson("/api/v1/attendance-devices/{$otherId}")
            ->assertNotFound();
    }

    public function test_device_token_is_only_returned_when_created_or_rotated(): void
    {
        $token = $this->adminToken();

        $created = $this->withToken($token)->postJson('/api/v1/attendance-devices', [
            'name' => 'QA Device',
            'brand' => 'zkteco',
            'protocol' => 'zk_pull',
        ])->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'device_token']]);

        $id = $created->json('data.id');
        $firstToken = $created->json('data.device_token');

        $this->withToken($token)
            ->getJson('/api/v1/attendance-devices')
            ->assertOk()
            ->assertJsonMissing(['device_token' => $firstToken]);

        $rotated = $this->withToken($token)
            ->postJson("/api/v1/attendance-devices/{$id}/rotate-token")
            ->assertOk()
            ->json('data.device_token');

        $this->assertNotSame($firstToken, $rotated);
    }

    private function adminToken(): string
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'QADEVICE'.Str::upper(Str::random(4)),
            'full_name' => 'Device Admin',
            'company_email' => Str::lower(Str::random(8)).'@device.test',
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

    private function device(int $tenantId, int $legalEntityId, string $token, array $meta = []): int
    {
        return DB::table('attendance_devices')->insertGetId([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'name' => 'Device '.$tenantId,
            'brand' => 'zkteco',
            'protocol' => 'zk_pull',
            'device_token' => $token,
            'status' => 'ACTIVE',
            'meta' => json_encode($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
