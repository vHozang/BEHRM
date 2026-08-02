<?php

namespace Tests\Feature;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceDeviceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Attendance sync tenant',
            'code' => 'ATT-SYNC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1,
            'name' => 'Attendance sync entity',
            'code' => 'ATT-SYNC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_hr_can_request_immediate_sync_and_bridge_reports_completion(): void
    {
        $hr = $this->actor('HR', ['hr', 'time']);
        $manager = $this->actor('MANAGER', ['time']);
        $deviceToken = 'dev_'.Str::random(48);
        $deviceId = $this->device($deviceToken);
        $offlineId = $this->device('dev_'.Str::random(48), false, 'ZZ Offline attendance device');

        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/device-sync')
            ->assertForbidden();

        $this->withToken($hr['token'])
            ->getJson('/api/v1/attendance/device-sync')
            ->assertOk()
            ->assertJsonPath('data.upload_delay_minutes', 15)
            ->assertJsonPath('data.devices.0.id', $deviceId);

        $requested = $this->withToken($hr['token'])
            ->postJson('/api/v1/attendance/device-sync')
            ->assertOk()
            ->assertJsonPath('data.devices.0.sync_request.status', 'PENDING');
        $requestId = $requested->json('data.devices.0.sync_request.id');
        $offlineMeta = json_decode((string) DB::table('attendance_devices')->where('id', $offlineId)->value('meta'), true);
        $this->assertArrayNotHasKey('sync_request', $offlineMeta);

        $this->withHeader('x-device-token', $deviceToken)
            ->getJson('/api/v1/internal/attendance/device-control')
            ->assertOk()
            ->assertJsonPath('data.upload_delay_minutes', 15)
            ->assertJsonPath('data.sync_request.id', $requestId);

        $this->withHeader('x-device-token', $deviceToken)
            ->postJson('/api/v1/internal/attendance/device-sync-status', [
                'request_id' => $requestId,
                'status' => 'RUNNING',
            ])
            ->assertOk()
            ->assertJsonPath('data.sync_request.status', 'RUNNING');

        $this->withHeader('x-device-token', $deviceToken)
            ->postJson('/api/v1/internal/attendance/device-sync-status', [
                'request_id' => $requestId,
                'status' => 'SUCCESS',
                'processed' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.sync_request.status', 'SUCCESS');

        $this->withToken($hr['token'])
            ->getJson('/api/v1/attendance/device-sync')
            ->assertOk()
            ->assertJsonPath('data.devices.0.last_sync.status', 'SUCCESS')
            ->assertJsonPath('data.devices.0.last_sync.processed', 3);
    }

    public function test_admin_config_changes_bridge_upload_delay_with_bounds(): void
    {
        $admin = $this->actor('ADMIN', [], true);
        $deviceToken = 'dev_'.Str::random(48);
        $this->device($deviceToken);

        $this->withToken($admin['token'])
            ->postJson('/api/v1/settings/save', [
                'items' => [[
                    'key' => 'attendance.device_upload_delay_minutes',
                    'value' => 7,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.saved.0', 'attendance.device_upload_delay_minutes');

        $this->withHeader('x-device-token', $deviceToken)
            ->getJson('/api/v1/internal/attendance/device-control')
            ->assertOk()
            ->assertJsonPath('data.upload_delay_minutes', 7);

        $this->withToken($admin['token'])
            ->postJson('/api/v1/settings/save', [
                'items' => [[
                    'key' => 'attendance.device_upload_delay_minutes',
                    'value' => 0,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['Thời gian tải dữ liệu phải từ 1 đến 1440 phút']);
    }

    /** @return array{id:int,token:string} */
    private function actor(string $roleCode, array $modules, bool $superAdmin = false): array
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'SYNC'.Str::upper(Str::random(6)),
            'full_name' => $roleCode.' Sync User',
            'company_email' => Str::lower(Str::random(10)).'@sync.test',
            'status' => 'ACTIVE',
            'is_super_admin' => $superAdmin,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $superAdmin) {
            $roleId = DB::table('roles')->insertGetId([
                'role_code' => $roleCode,
                'role_name' => $roleCode,
                'is_system_role' => true,
                'meta' => json_encode(['modules' => $modules]),
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

        return ['id' => $employeeId, 'token' => $token];
    }

    private function device(string $token, bool $online = true, string $name = 'QA attendance device'): int
    {
        return DB::table('attendance_devices')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'name' => $name,
            'brand' => 'wiseeye',
            'protocol' => 'zk_pull',
            'device_token' => $token,
            'status' => 'ACTIVE',
            'location' => 'QA room',
            'meta' => json_encode(array_filter([
                'ip' => '169.254.248.131',
                'last_control_at' => $online ? now()->toIso8601String() : null,
            ])),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
