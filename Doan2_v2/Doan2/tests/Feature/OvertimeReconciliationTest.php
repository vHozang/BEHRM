<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\OvertimeRequest;
use App\Services\AttendanceReconciliationService;
use App\Services\OvertimeReconciliationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OvertimeReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $employeeId;
    private int $shiftId;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(1, 1);
        $this->employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_code' => 'OT001',
            'full_name' => 'Overtime Employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->shiftId = DB::table('shift_types')->insertGetId([
            'tenant_id' => 1,
            'shift_code' => 'CA1',
            'shift_name' => 'Ca 1',
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shift_assignments')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $this->employeeId,
            'shift_type_id' => $this->shiftId,
            'effective_date' => '2026-01-01',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_approved_hour_with_checkout_at_1440_pays_only_30_minutes(): void
    {
        $this->attendance('06:00:00', '14:40:00');
        $request = $this->approvedRequest('14:00:00', '15:00:00', 1);

        $result = app(OvertimeReconciliationService::class)
            ->reconcileDate(1, $this->employeeId, '2026-08-11');

        $this->assertSame(40, $result['actual_outside_minutes']);
        $this->assertSame(30, $result['payable_overtime_minutes']);
        $this->assertSame('PARTIAL_MATCH', $result['requests'][0]['status']);
        $this->assertSame(30, (int) $request->fresh()->meta['payable_overtime_minutes']);
    }

    public function test_overlapping_approved_requests_do_not_double_count_actual_minutes(): void
    {
        $this->attendance('06:00:00', '15:00:00');
        $this->approvedRequest('14:00:00', '15:00:00', 1);
        $this->approvedRequest('14:30:00', '15:30:00', 1);

        $result = app(OvertimeReconciliationService::class)
            ->reconcileDate(1, $this->employeeId, '2026-08-11');

        $this->assertSame(60, $result['payable_overtime_minutes']);
        $this->assertSame(60, array_sum(array_column($result['requests'], 'payable_minutes')));
    }

    public function test_approved_request_without_completed_punch_is_blocking_and_pays_zero(): void
    {
        $this->approvedRequest('14:00:00', '15:00:00', 1);

        $result = app(OvertimeReconciliationService::class)
            ->reconcileDate(1, $this->employeeId, '2026-08-11');

        $this->assertSame(0, $result['payable_overtime_minutes']);
        $this->assertSame('OT_NO_ATTENDANCE', $result['blocking_issues'][0]['issue_code']);
        $this->assertSame('NO_ATTENDANCE', $result['requests'][0]['status']);
    }

    public function test_comp_off_uses_reconciled_minutes_and_is_idempotent(): void
    {
        $leaveTypeId = DB::table('leave_types')->insertGetId([
            'leave_type_code' => 'COMP_OFF',
            'leave_type_name' => 'Nghỉ bù',
            'category' => 'PAID',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->attendance('06:00:00', '15:00:00');
        $request = $this->approvedRequest('14:00:00', '15:00:00', 1);
        $request->update(['meta' => array_merge($request->meta, ['converted_to_comp_off' => true])]);

        $service = app(OvertimeReconciliationService::class);
        $first = $service->reconcileDate(1, $this->employeeId, '2026-08-11');
        $second = $service->reconcileDate(1, $this->employeeId, '2026-08-11');

        $this->assertSame(60, $first['verified_overtime_minutes']);
        $this->assertSame(0, $first['payable_overtime_minutes']);
        $this->assertSame(60, $second['verified_overtime_minutes']);
        $this->assertDatabaseCount('leave_transactions', 1);
        $this->assertDatabaseHas('leave_transactions', [
            'employee_id' => $this->employeeId,
            'leave_type_id' => $leaveTypeId,
            'reference_type' => 'OVERTIME_RECONCILED',
            'reference_id' => $request->id,
            'quantity' => 0.1250,
        ]);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $this->employeeId,
            'leave_type_id' => $leaveTypeId,
            'year' => '2026',
            'remaining_days' => 0.1250,
        ]);
    }

    private function attendance(string $checkIn, string $checkOut): Attendance
    {
        $attendance = Attendance::create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $this->employeeId,
            'shift_type_id' => $this->shiftId,
            'work_date' => '2026-08-11',
            'check_in_time' => $checkIn,
            'check_out_time' => $checkOut,
            'status' => 'ON_TIME',
        ]);
        app(AttendanceReconciliationService::class)->reconcile($attendance, null, false);

        return $attendance;
    }

    private function approvedRequest(string $start, string $end, float $hours): OvertimeRequest
    {
        return OvertimeRequest::create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $this->employeeId,
            'work_date' => '2026-08-11',
            'start_time' => $start,
            'end_time' => $end,
            'total_hours' => $hours,
            'status' => 'APPROVED',
            'meta' => ['kind' => 'EMPLOYEE_REQUEST', 'pay_factor' => 1.5],
        ]);
    }
}
