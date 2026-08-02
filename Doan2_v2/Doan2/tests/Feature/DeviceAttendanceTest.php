<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_replayed_device_punches_do_not_corrupt_attendance_times(): void
    {
        config(['hrm.internal_service_token' => 'test-internal-token']);

        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'ZK001',
            'full_name' => 'ZK Test Employee',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->punch($employeeId, '2026-07-28 08:00:00')
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        $this->punch($employeeId, '2026-07-28 08:00:00')->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('08:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertNull($attendance->check_out_time);

        $this->punch($employeeId, '2026-07-28 17:00:00')->assertOk();
        $this->punch($employeeId, '2026-07-28 12:00:00')->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('08:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertSame('17:00:00', substr((string) $attendance->check_out_time, 0, 8));
    }

    public function test_utc_device_timestamp_is_stored_in_tenant_timezone(): void
    {
        config(['hrm.internal_service_token' => 'test-internal-token']);

        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'ZK002',
            'full_name' => 'ZK Timezone Employee',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->punch($employeeId, '2026-08-02T04:20:59.000Z')
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('2026-08-02', (string) $attendance->work_date);
        $this->assertSame('11:20:59', substr((string) $attendance->check_in_time, 0, 8));
    }

    private function punch(int $employeeId, string $timestamp)
    {
        return $this->withHeader('x-internal-token', 'test-internal-token')
            ->postJson('/api/v1/internal/attendance/device-punch', [
                'enroll_id' => (string) $employeeId,
                'timestamp' => $timestamp,
                'device_id' => 'test-zk-device',
                'verify_method' => 'fingerprint',
            ]);
    }
}
