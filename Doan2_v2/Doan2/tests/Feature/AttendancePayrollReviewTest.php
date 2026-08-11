<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Services\AttendancePayrollReviewService;
use App\Services\AttendanceReconciliationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendancePayrollReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $employeeId;
    private int $shiftId;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(1, 1);
        config([
            'hrm.attendance.late_grace_minutes' => 15,
            'hrm.attendance.early_leave_grace_minutes' => 15,
            'hrm.attendance.violation_default_percent' => 0,
        ]);

        $this->employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_code' => 'REVIEW001',
            'full_name' => 'Attendance Review Employee',
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

    public function test_exact_threshold_creates_one_review_for_both_violations(): void
    {
        $attendance = $this->attendance('06:15:00', '13:45:00');

        app(AttendanceReconciliationService::class)->reconcile($attendance, null, false);

        $meta = json_decode((string) $attendance->fresh()->meta, true);
        $this->assertSame(450, $meta['regular_worked_minutes']);
        $this->assertSame(15, $meta['late_minutes']);
        $this->assertSame(15, $meta['early_leave_minutes']);
        $this->assertDatabaseCount('attendance_payroll_reviews', 1);
        $this->assertDatabaseHas('attendance_payroll_reviews', [
            'attendance_id' => $attendance->id,
            'status' => 'PENDING',
            'late_minutes' => 15,
            'early_leave_minutes' => 15,
            'default_percent' => 0,
        ]);
    }

    public function test_under_threshold_is_recorded_but_does_not_create_review(): void
    {
        $attendance = $this->attendance('06:14:00', '14:00:00');

        app(AttendanceReconciliationService::class)->reconcile($attendance, null, false);

        $meta = json_decode((string) $attendance->fresh()->meta, true);
        $this->assertSame(14, $meta['late_minutes']);
        $this->assertDatabaseCount('attendance_payroll_reviews', 0);
    }

    public function test_attendance_change_makes_an_approved_review_stale(): void
    {
        $attendance = $this->attendance('06:15:00', '14:00:00');
        $reconciliation = app(AttendanceReconciliationService::class);
        $reconciliation->reconcile($attendance, null, false);

        $review = $attendance->fresh()->payrollReview;
        app(AttendancePayrollReviewService::class)->decide($review, 25, 'Áp dụng theo nội quy đã công bố.', $this->employeeId);

        $attendance->update(['check_in_time' => '06:20:00']);
        $reconciliation->reconcile($attendance->fresh(), null, false);

        $this->assertDatabaseHas('attendance_payroll_reviews', [
            'attendance_id' => $attendance->id,
            'status' => 'STALE',
            'late_minutes' => 20,
            'approved_percent' => null,
        ]);
    }

    private function attendance(string $checkIn, string $checkOut): Attendance
    {
        return Attendance::create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employee_id' => $this->employeeId,
            'shift_type_id' => $this->shiftId,
            'work_date' => '2026-08-11',
            'check_in_time' => $checkIn,
            'check_out_time' => $checkOut,
            'status' => 'ON_TIME',
        ]);
    }
}
