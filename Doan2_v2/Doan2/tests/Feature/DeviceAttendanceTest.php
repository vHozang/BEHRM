<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hrm.internal_service_token' => 'test-internal-token',
            'hrm.attendance.device_auto_checkout_min_minutes' => 60,
        ]);
    }

    public function test_replayed_device_punches_do_not_corrupt_attendance_times(): void
    {
        $employeeId = $this->createEmployee('ZK001');

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
        $employeeId = $this->createEmployee('ZK002');

        $this->punch($employeeId, '2026-08-02T04:20:59.000Z')
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('2026-08-02', (string) $attendance->work_date);
        $this->assertSame('11:20:59', substr((string) $attendance->check_in_time, 0, 8));
    }

    public function test_repeated_check_in_state_never_becomes_check_out(): void
    {
        $employeeId = $this->createEmployee('ZK003');

        $this->punch($employeeId, '2026-08-03 08:00:00', 'CHECK_IN', 0)->assertOk();
        $this->punch($employeeId, '2026-08-03 12:00:00', 'CHECK_IN', 0)->assertOk();
        $this->punch($employeeId, '2026-08-03 12:00:00', 'CHECK_IN', 0)->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('08:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertNull($attendance->check_out_time);

        $meta = $this->decodeMeta($attendance->meta);
        $this->assertSame('CHECK_IN', $meta['punch_state']);
        $this->assertSame(0, $meta['device_state']);
        $this->assertCount(2, $meta['device_events']);
    }

    public function test_explicit_check_in_and_check_out_states_set_correct_times(): void
    {
        $employeeId = $this->createEmployee('ZK004');

        $this->punch($employeeId, '2026-08-03 08:00:00', 'CHECK_IN', 0)->assertOk();
        $this->punch($employeeId, '2026-08-03 17:00:00', 'CHECK_OUT', 1)->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('08:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertSame('17:00:00', substr((string) $attendance->check_out_time, 0, 8));

        $states = array_column($this->decodeMeta($attendance->meta)['device_events'], 'punch_state');
        $this->assertSame(['CHECK_IN', 'CHECK_OUT'], $states);
    }

    public function test_checkout_received_before_checkin_is_reconciled_when_batch_is_out_of_order(): void
    {
        $employeeId = $this->createEmployee('ZK005');

        $this->punch($employeeId, '2026-08-03 17:00:00', 'CHECK_OUT', 1)->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertNull($attendance->check_in_time);
        $this->assertSame('17:00:00', substr((string) $attendance->check_out_time, 0, 8));

        $this->punch($employeeId, '2026-08-03 08:00:00', 'CHECK_IN', 0)->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('08:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertSame('17:00:00', substr((string) $attendance->check_out_time, 0, 8));
    }

    public function test_break_states_are_audited_without_changing_main_attendance_times(): void
    {
        $employeeId = $this->createEmployee('ZK006');

        $this->punch($employeeId, '2026-08-03 08:00:00', 'CHECK_IN', 0)->assertOk();
        $this->punch($employeeId, '2026-08-03 12:00:00', 'BREAK_OUT', 2)->assertOk();
        $this->punch($employeeId, '2026-08-03 13:00:00', 'BREAK_IN', 3)->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('08:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertNull($attendance->check_out_time);

        $states = array_column($this->decodeMeta($attendance->meta)['device_events'], 'punch_state');
        $this->assertSame(['CHECK_IN', 'BREAK_OUT', 'BREAK_IN'], $states);
    }

    public function test_legacy_auto_state_ignores_an_immediate_repeated_scan(): void
    {
        $employeeId = $this->createEmployee('ZK007');

        $this->punch($employeeId, '2026-08-03 08:00:00')->assertOk();
        $this->punch($employeeId, '2026-08-03 08:05:00')->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertNull($attendance->check_out_time);

        $this->punch($employeeId, '2026-08-03 17:00:00')->assertOk();
        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('17:00:00', substr((string) $attendance->check_out_time, 0, 8));
    }

    public function test_raw_device_state_is_used_when_punch_state_is_missing(): void
    {
        $employeeId = $this->createEmployee('ZK008');

        $this->punch($employeeId, '2026-08-03 08:00:00', null, 0)->assertOk();
        $this->punch($employeeId, '2026-08-03 17:00:00', null, 1)->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('08:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertSame('17:00:00', substr((string) $attendance->check_out_time, 0, 8));
    }

    public function test_overnight_checkout_updates_the_previous_shift_start_date(): void
    {
        $employeeId = $this->createEmployee('ZK009');
        $shiftId = DB::table('shift_types')->insertGetId([
            'tenant_id' => 1,
            'shift_code' => 'CA3',
            'shift_name' => 'Ca 3',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shift_assignments')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $employeeId,
            'shift_type_id' => $shiftId,
            'effective_date' => '2026-01-01',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->punch($employeeId, '2026-08-03 22:00:00', 'CHECK_IN', 0)->assertOk();
        $this->punch($employeeId, '2026-08-04 06:00:00', 'CHECK_OUT', 1)->assertOk();

        $attendance = DB::table('attendances')->where('employee_id', $employeeId)->first();
        $this->assertSame('2026-08-03', (string) $attendance->work_date);
        $this->assertSame('22:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertSame('06:00:00', substr((string) $attendance->check_out_time, 0, 8));
        $this->assertSame(480, $this->decodeMeta($attendance->meta)['regular_worked_minutes']);
    }

    private function createEmployee(string $code): int
    {
        return DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'full_name' => "Employee {$code}",
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function decodeMeta($meta): array
    {
        return is_string($meta) ? (json_decode($meta, true) ?: []) : (array) $meta;
    }

    private function punch(
        int $employeeId,
        string $timestamp,
        ?string $punchState = null,
        $deviceState = null
    ) {
        return $this->withHeader('x-internal-token', 'test-internal-token')
            ->postJson('/api/v1/internal/attendance/device-punch', [
                'enroll_id' => (string) $employeeId,
                'timestamp' => $timestamp,
                'device_id' => 'test-zk-device',
                'verify_method' => 'fingerprint',
                'punch_state' => $punchState,
                'device_state' => $deviceState,
            ]);
    }
}
