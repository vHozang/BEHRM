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
            'hrm.internal_attendance_tenant_id' => 1,
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

    public function test_batch_is_limited_to_two_hundred_punches(): void
    {
        $punches = array_fill(0, 201, [
            'enroll_id' => '1',
            'timestamp' => '2026-08-03 08:00:00',
        ]);

        $this->withHeader('x-internal-token', 'test-internal-token')
            ->postJson('/api/v1/internal/attendance/device-punch', ['punches' => $punches])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.punches.0', 'Tối đa 200 punch/request.');
    }

    public function test_batch_of_exactly_two_hundred_punches_is_accepted(): void
    {
        $employeeId = $this->createEmployee('ZK200');
        $punches = array_map(fn (int $index): array => [
            'enroll_id' => (string) $employeeId,
            'timestamp' => sprintf('2026-08-%02d 08:00:%02d', intdiv($index, 60) + 1, $index % 60),
            'punch_state' => 'CHECK_IN',
            'device_state' => 0,
        ], range(0, 199));

        $this->withHeader('x-internal-token', 'test-internal-token')
            ->postJson('/api/v1/internal/attendance/device-punch', ['punches' => $punches])
            ->assertOk()
            ->assertJsonPath('data.processed', 200)
            ->assertJsonCount(0, 'data.errors');
    }

    public function test_device_token_never_resolves_an_employee_from_another_tenant(): void
    {
        DB::table('tenants')->insert([
            'id' => 2, 'name' => 'Other tenant', 'code' => 'OTHER-TENANT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'id' => 2, 'tenant_id' => 2, 'name' => 'Other entity', 'code' => 'OTHER-ENTITY',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignEmployeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'ONLY-TENANT-2', 'full_name' => 'Foreign employee',
            'status' => 'ACTIVE', 'tenant_id' => 2, 'legal_entity_id' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attendance_devices')->insert([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'name' => 'Tenant one device',
            'brand' => 'zkteco', 'protocol' => 'zk_pull', 'device_token' => 'tenant-one-token',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('x-device-token', 'tenant-one-token')
            ->postJson('/api/v1/internal/attendance/device-punch', [
                'enroll_id' => 'ONLY-TENANT-2',
                'timestamp' => '2026-08-03 08:00:00',
                'punch_state' => 'CHECK_IN',
            ])
            ->assertOk()
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.errors.0.enroll_id', 'ONLY-TENANT-2');
        $this->assertDatabaseMissing('attendances', ['employee_id' => $foreignEmployeeId]);
    }

    public function test_legacy_internal_token_requires_a_fixed_tenant(): void
    {
        config(['hrm.internal_attendance_tenant_id' => null]);

        $this->withHeader('x-internal-token', 'test-internal-token')
            ->postJson('/api/v1/internal/attendance/device-punch', [
                'enroll_id' => '1',
                'timestamp' => '2026-08-03 08:00:00',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Legacy internal token chưa được khóa vào tenant cố định.');
    }

    public function test_tenant_two_device_creates_review_and_notifies_only_tenant_two_hr(): void
    {
        DB::table('tenants')->insert([
            'id' => 2, 'name' => 'Tenant two', 'code' => 'TENANT-TWO',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'id' => 2, 'tenant_id' => 2, 'name' => 'Tenant two entity', 'code' => 'TENANT-TWO-ENTITY',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tenantOneHr = DB::table('employees')->insertGetId([
            'employee_code' => 'DEVICE-HR-T1', 'full_name' => 'Tenant one HR',
            'status' => 'ACTIVE', 'tenant_id' => 1, 'legal_entity_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenantTwoHr = DB::table('employees')->insertGetId([
            'employee_code' => 'DEVICE-HR-T2', 'full_name' => 'Tenant two HR',
            'status' => 'ACTIVE', 'tenant_id' => 2, 'legal_entity_id' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenantTwoWorker = DB::table('employees')->insertGetId([
            'employee_code' => 'DEVICE-WORKER-T2', 'full_name' => 'Tenant two worker',
            'status' => 'ACTIVE', 'tenant_id' => 2, 'legal_entity_id' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenantOneHrRole = DB::table('roles')->insertGetId([
            'tenant_id' => 1, 'role_code' => 'HR', 'role_name' => 'HR tenant one',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenantTwoHrRole = DB::table('roles')->insertGetId([
            'tenant_id' => 2, 'role_code' => 'HR', 'role_name' => 'HR tenant two',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_roles')->insert([
            [
                'tenant_id' => 1, 'employee_id' => $tenantOneHr, 'role_id' => $tenantOneHrRole,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'tenant_id' => 2, 'employee_id' => $tenantTwoHr, 'role_id' => $tenantTwoHrRole,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $shiftId = DB::table('shift_types')->insertGetId([
            'tenant_id' => 2, 'shift_code' => 'DEVICE-CA1-T2', 'shift_name' => 'Tenant two shift',
            'start_time' => '06:00:00', 'end_time' => '14:00:00', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shift_assignments')->insert([
            'tenant_id' => 2, 'legal_entity_id' => 2, 'employee_id' => $tenantTwoWorker,
            'shift_type_id' => $shiftId, 'effective_date' => '2026-01-01', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attendance_devices')->insert([
            'tenant_id' => 2, 'legal_entity_id' => 2, 'name' => 'Tenant two device',
            'brand' => 'zkteco', 'protocol' => 'zk_pull', 'device_token' => 'tenant-two-token',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('x-device-token', 'tenant-two-token')
            ->postJson('/api/v1/internal/attendance/device-punch', [
                'enroll_id' => 'DEVICE-WORKER-T2',
                'timestamp' => '2026-08-03 06:20:00',
                'punch_state' => 'CHECK_IN',
                'device_state' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonCount(0, 'data.errors');

        $attendanceId = DB::table('attendances')
            ->where('tenant_id', 2)
            ->where('employee_id', $tenantTwoWorker)
            ->value('id');
        $this->assertNotNull($attendanceId);
        $this->assertDatabaseHas('attendance_payroll_reviews', [
            'tenant_id' => 2,
            'legal_entity_id' => 2,
            'attendance_id' => $attendanceId,
            'employee_id' => $tenantTwoWorker,
            'late_minutes' => 20,
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('notifications', [
            'tenant_id' => 2,
            'receiver_id' => $tenantTwoHr,
            'reference_type' => 'attendance_payroll_review',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'receiver_id' => $tenantOneHr,
            'reference_type' => 'attendance_payroll_review',
        ]);
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
