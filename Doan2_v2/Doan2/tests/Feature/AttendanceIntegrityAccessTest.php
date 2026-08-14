<?php

namespace Tests\Feature;

use App\DTOs\CheckInData;
use App\Jobs\ProcessAttendanceLog;
use App\Jobs\RunAttendanceRecomputeOperation;
use App\Jobs\RunAttendanceSummaryOperation;
use App\Models\Attendance;
use App\Models\AttendanceOperation;
use App\Repositories\Contracts\AttendanceRepositoryContract;
use App\Services\AttendanceAccess;
use App\Services\AttendanceDayLock;
use App\Services\AttendanceReconciliationService;
use App\Services\AttendanceSummaryService;
use App\Services\TimesheetService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceIntegrityAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hrm.internal_service_token' => 'test-internal-token',
            'hrm.internal_attendance_tenant_id' => 1,
        ]);
        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Attendance integrity tenant', 'code' => 'ATT-INTEGRITY',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1, 'name' => 'Entity one', 'code' => 'ATT-E1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        TenantContext::set(1, 1);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_patch_whitelist_self_clock_state_machine_and_closed_period(): void
    {
        $admin = $this->actor('ADMIN', ['time'], true);
        $first = $this->actor('EMPLOYEE');
        $second = $this->actor('EMPLOYEE');
        $attendanceId = DB::table('attendances')->insertGetId([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $first['id'],
            'work_date' => '2026-08-10', 'status' => 'ON_TIME',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($admin['token'])->patchJson('/api/v1/attendances/'.$attendanceId, [
            'tenant_id' => 99, 'employee_id' => $second['id'], 'status' => 'ABSENT', 'meta' => ['unsafe' => true],
        ])->assertStatus(422);
        $this->assertDatabaseHas('attendances', [
            'id' => $attendanceId, 'tenant_id' => 1, 'employee_id' => $first['id'], 'status' => 'ON_TIME',
        ]);

        $this->withToken($first['token'])->postJson('/api/v1/attendances/check-in', ['employee_id' => $second['id']])
            ->assertForbidden();
        $this->travelTo(now()->setDate(2026, 8, 13)->setTime(6, 0));
        $this->withToken($first['token'])->postJson('/api/v1/attendances/check-in')->assertCreated();
        $this->withToken($first['token'])->postJson('/api/v1/attendances/check-in')->assertStatus(422);
        $this->withToken($first['token'])->postJson('/api/v1/attendances/check-out')->assertOk();
        $this->withToken($first['token'])->postJson('/api/v1/attendances/check-in')->assertOk();
        $this->withToken($first['token'])->postJson('/api/v1/attendances/check-out')->assertOk();
        $this->withToken($first['token'])->postJson('/api/v1/attendances/check-in')->assertStatus(422);
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $first['id'], 'work_date' => '2026-08-13',
            'check_in_time' => '06:00:00', 'check_out_time' => '06:00:00',
            'check_in_time_2' => '06:00:00', 'check_out_time_2' => '06:00:00',
        ]);

        DB::table('salary_periods')->insert([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'period_code' => 'CLOSED-202608',
            'period_name' => 'Closed August', 'period_type' => 'MONTHLY',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'CLOSED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($admin['token'])->patchJson('/api/v1/attendances/'.$attendanceId, ['notes' => 'blocked'])
            ->assertStatus(409);
        $this->withToken($admin['token'])->postJson('/api/v1/attendances/'.$attendanceId.'/verify', ['decision' => 'reject'])
            ->assertStatus(409);
    }

    public function test_unique_constraint_preflight_and_device_batch_limit(): void
    {
        Storage::fake('local');
        $employee = $this->actor('EMPLOYEE');
        DB::table('attendances')->insert([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $employee['id'],
            'work_date' => '2026-08-10', 'status' => 'ON_TIME',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            DB::table('attendances')->insert([
                'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $employee['id'],
                'work_date' => '2026-08-10', 'status' => 'LATE',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('Unique attendance employee/day constraint was not enforced.');
        } catch (QueryException) {
            $this->assertDatabaseCount('attendances', 1);
        }

        $punches = array_map(fn (int $index): array => [
            'employee_code' => $employee['id'],
            'timestamp' => now()->addSeconds($index)->toIso8601String(),
            'type' => 'CHECK_IN',
        ], range(1, 201));
        $this->withHeaders(['x-internal-token' => 'test-internal-token'])
            ->postJson('/api/v1/internal/attendance/device-punch', ['punches' => $punches])
            ->assertStatus(422);
    }

    public function test_preflight_duplicate_exits_nonzero_and_writes_private_csv(): void
    {
        Storage::fake('local');
        $employee = $this->actor('EMPLOYEE');
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropUnique('attendances_tenant_employee_work_date_unique');
        });
        foreach ([1, 2] as $index) {
            DB::table('attendances')->insert([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_id' => $employee['id'],
                'work_date' => '2026-08-14',
                'status' => $index === 1 ? 'ON_TIME' : 'LATE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('attendance:preflight-unique', ['--output' => 'attendance-preflight/test-duplicates.csv'])
            ->assertExitCode(1)
            ->expectsOutputToContain('FAIL');
        Storage::disk('local')->assertExists('attendance-preflight/test-duplicates.csv');
        $csv = Storage::disk('local')->get('attendance-preflight/test-duplicates.csv');
        $this->assertStringContainsString((string) $employee['id'], $csv);
        $this->assertStringContainsString('2026-08-14', $csv);
    }

    public function test_accountant_can_read_and_export_timesheet_but_not_attendance_crud_or_recompute(): void
    {
        Queue::fake();
        $accountant = $this->actor('ACCOUNTANT', ['payroll']);
        $worker = $this->actor('EMPLOYEE');
        DB::table('attendances')->insert([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $worker['id'],
            'work_date' => '2026-08-10', 'status' => 'ON_TIME',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($accountant['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08')
            ->assertOk();
        $this->withToken($accountant['token'])
            ->getJson('/api/v1/attendance/timesheet/overview?month=2026-08')
            ->assertOk();
        $this->withToken($accountant['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08&employee_id='.$worker['id'])
            ->assertOk()
            ->assertJsonPath('data.rows.0.employee_id', $worker['id']);
        $this->withToken($accountant['token'])
            ->postJson('/api/v1/attendance/timesheet/exports', [
                'month' => '2026-08', 'format' => 'csv', 'employee_id' => $worker['id'],
            ])
            ->assertCreated();
        $this->withToken($accountant['token'])->getJson('/api/v1/attendances')->assertForbidden();
        $this->withToken($accountant['token'])
            ->postJson('/api/v1/attendance/recompute', ['month' => '2026-08'])
            ->assertForbidden();
    }

    public function test_cursor_payload_includes_nested_payroll_review_id(): void
    {
        $hr = $this->actor('HR', ['hr', 'time']);
        $worker = $this->actor('EMPLOYEE');
        $attendanceId = DB::table('attendances')->insertGetId([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $worker['id'],
            'work_date' => '2026-08-10', 'status' => 'LATE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $reviewId = DB::table('attendance_payroll_reviews')->insertGetId([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'attendance_id' => $attendanceId,
            'employee_id' => $worker['id'], 'work_date' => '2026-08-10',
            'late_minutes' => 20, 'early_leave_minutes' => 0,
            'default_percent' => 25, 'status' => 'PENDING',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken($hr['token'])
            ->getJson('/api/v1/attendances?pagination=cursor&from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.items.0.payroll_review.id', $reviewId)
            ->assertJsonPath('data.items.0.payroll_review.status', 'PENDING');
    }

    public function test_preflight_pass_and_operation_owner_scope(): void
    {
        Queue::fake();
        $hr = $this->actor('HR', ['hr', 'time']);
        $otherHr = $this->actor('HR', ['hr', 'time']);
        $this->artisan('attendance:preflight-unique')->assertExitCode(0)
            ->expectsOutputToContain('PASS');

        $runId = $this->withToken($hr['token'])->postJson('/api/v1/attendance/recompute', [
            'month' => '2026-08',
        ])->assertStatus(202)->json('data.run_id');
        Queue::assertPushed(RunAttendanceRecomputeOperation::class, fn ($job) => $job->operationId === $runId);
        $this->withToken($hr['token'])->getJson('/api/v1/attendance/operations/'.$runId)->assertOk();
        $this->withToken($otherHr['token'])->getJson('/api/v1/attendance/operations/'.$runId)->assertNotFound();
    }

    public function test_recompute_operation_updates_progress_and_emits_one_scope_refresh(): void
    {
        config(['queue.default' => 'sync', 'broadcasting.default' => 'null']);
        $hr = $this->actor('HR', ['hr', 'time']);
        $departmentId = DB::table('departments')->insertGetId([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'department_code' => 'ATT-D1',
            'department_name' => 'Attendance D1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $worker = $this->actor('EMPLOYEE');
        DB::table('employees')->where('id', $worker['id'])->update(['department_id' => $departmentId]);
        DB::table('attendances')->insert([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $worker['id'],
            'work_date' => '2026-08-11', 'check_in_time' => '08:00:00', 'check_out_time' => '17:00:00',
            'status' => 'PRESENT', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $operation = AttendanceOperation::create([
            'id' => (string) Str::uuid(), 'tenant_id' => 1, 'legal_entity_id' => 1,
            'requested_by' => $hr['id'], 'type' => 'RECOMPUTE', 'status' => 'PENDING',
            'filters' => ['start' => '2026-08-01', 'end' => '2026-08-31', 'employee_ids' => [$worker['id']]],
        ]);

        app(RunAttendanceRecomputeOperation::class, ['operationId' => $operation->id])
            ->handle(app(TimesheetService::class));
        $operation->refresh();
        $this->assertSame('COMPLETED', $operation->status);
        $this->assertSame(1, (int) $operation->total_items);
        $this->assertSame(1, (int) $operation->processed_items);
        $this->assertSame(1, DB::table('attendance_change_events')->where('change_type', 'recompute_refresh')->count());
        $this->assertSame(0, DB::table('attendance_change_events')->whereNotNull('attendance_id')->count());

        $request = Request::create('/api/v1/attendance/changes', 'GET');
        $request->attributes->set('auth_employee_id', $worker['id']);
        $request->attributes->set('access', ['full' => false, 'roles' => []]);
        $rows = app(AttendanceAccess::class)->scopeChangeEvents(DB::table('attendance_change_events'), $request)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('recompute_refresh', $rows->first()->change_type);
    }

    public function test_reconciliation_ignores_json_object_key_order(): void
    {
        $worker = $this->actor('EMPLOYEE');
        $shiftId = DB::table('shift_types')->insertGetId([
            'tenant_id' => 1, 'shift_code' => 'ORDER-CA1', 'shift_name' => 'Order shift',
            'start_time' => '06:00:00', 'end_time' => '14:00:00', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shift_assignments')->insert([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $worker['id'],
            'shift_type_id' => $shiftId, 'effective_date' => '2026-01-01', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attendance = Attendance::create([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $worker['id'],
            'shift_type_id' => $shiftId, 'work_date' => '2026-08-11',
            'check_in_time' => '06:00:00', 'check_out_time' => '14:00:00',
            'status' => 'PRESENT',
        ]);
        $shift = DB::table('shift_types')->where('id', $shiftId)->first();
        $reconciliation = app(AttendanceReconciliationService::class);
        $reconciliation->reconcileWithShift($attendance, $shift, null, false, false);

        $meta = $attendance->fresh()->meta;
        foreach (['shift_intervals', 'presence_intervals', 'outside_shift_intervals'] as $key) {
            $meta[$key] = array_map(
                fn (array $interval): array => array_reverse($interval, true),
                $meta[$key] ?? [],
            );
        }
        DB::table('attendances')->where('id', $attendance->id)->update([
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);

        $prepared = $reconciliation->prepareWithShift($attendance->fresh(), $shift, false);
        $this->assertSame([], $prepared['changes']);
    }

    public function test_recompute_preloads_payroll_reviews_once_per_chunk(): void
    {
        $worker = $this->actor('EMPLOYEE');
        foreach (range(1, 20) as $day) {
            DB::table('attendances')->insert([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'employee_id' => $worker['id'],
                'work_date' => sprintf('2026-07-%02d', $day),
                'status' => 'PRESENT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = app(TimesheetService::class)->recompute(
                1,
                1,
                '2026-07-01',
                '2026-07-31',
                [$worker['id']],
            );
            $queries = collect(DB::getQueryLog())
                ->pluck('query')
                ->filter(fn (string $query): bool => str_starts_with(strtolower(ltrim($query)), 'select')
                    && str_contains(strtolower($query), 'attendance_payroll_reviews')
                )
                ->values();
            $configQueries = collect(DB::getQueryLog())
                ->pluck('query')
                ->filter(fn (string $query): bool => str_starts_with(strtolower(ltrim($query)), 'select')
                    && str_contains(strtolower($query), 'system_configs')
                )
                ->values();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(20, $result['scanned']);
        $this->assertCount(1, $queries, $queries->implode("\n"));
        $this->assertLessThanOrEqual(4, $configQueries->count(), $configQueries->implode("\n"));
    }

    public function test_recompute_sends_one_entity_scoped_hr_notification_for_review_changes(): void
    {
        config(['broadcasting.default' => 'null']);
        $requestingHr = $this->actor('HR', ['hr', 'time']);
        $sameEntityHr = $this->actor('HR', ['hr', 'time']);
        $admin = $this->actor('ADMIN', ['time'], true);

        DB::table('legal_entities')->insert([
            'id' => 2, 'tenant_id' => 1, 'name' => 'Entity two', 'code' => 'ATT-E2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherEntityHr = $this->actor('HR', ['hr', 'time']);
        DB::table('employees')->where('id', $otherEntityHr['id'])->update(['legal_entity_id' => 2]);

        $shiftId = DB::table('shift_types')->insertGetId([
            'tenant_id' => 1, 'shift_code' => 'NOTICE-CA1', 'shift_name' => 'Notice shift',
            'start_time' => '06:00:00', 'end_time' => '14:00:00', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $firstWorker = $this->actor('EMPLOYEE');
        $secondWorker = $this->actor('EMPLOYEE');
        foreach ([$firstWorker['id'], $secondWorker['id']] as $employeeId) {
            DB::table('shift_assignments')->insert([
                'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $employeeId,
                'shift_type_id' => $shiftId, 'effective_date' => '2026-01-01', 'status' => 'ACTIVE',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('attendances')->insert([
                'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $employeeId,
                'shift_type_id' => $shiftId, 'work_date' => '2026-08-11',
                'check_in_time' => '06:20:00', 'check_out_time' => '14:00:00',
                'status' => 'PRESENT', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $operation = AttendanceOperation::create([
            'id' => (string) Str::uuid(), 'tenant_id' => 1, 'legal_entity_id' => 1,
            'requested_by' => $requestingHr['id'], 'type' => 'RECOMPUTE', 'status' => 'PENDING',
            'filters' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
        ]);

        (new RunAttendanceRecomputeOperation($operation->id))
            ->handle(app(TimesheetService::class));

        $operation->refresh();
        $this->assertSame(2, (int) data_get($operation->result, 'created_reviews'));
        $recipients = DB::table('notifications')
            ->where('reference_type', 'attendance_recompute')
            ->pluck('receiver_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $expected = collect([$requestingHr['id'], $sameEntityHr['id'], $admin['id']])->sort()->values()->all();
        $this->assertSame($expected, $recipients);
        $this->assertNotContains($otherEntityHr['id'], $recipients);
        $this->assertSame(3, DB::table('notifications')->where('reference_type', 'attendance_recompute')->count());
    }

    public function test_legacy_attendance_job_keeps_session_order_and_is_idempotent(): void
    {
        $worker = $this->actor('EMPLOYEE');
        $handle = function (string $action, string $checkedAt) use ($worker): void {
            $job = new ProcessAttendanceLog(new CheckInData(
                employeeId: $worker['id'],
                action: $action,
                source: 'API',
                deviceId: 'legacy-test',
                locationCode: null,
                ipAddress: null,
                metadata: null,
                checkedAt: $checkedAt,
            ));
            $job->handle(
                app(AttendanceRepositoryContract::class),
                app(AttendanceDayLock::class),
                app(AttendanceReconciliationService::class),
            );
        };

        $handle('CHECK_IN', '2026-08-14T06:00:00+07:00');
        $handle('CHECK_IN', '2026-08-14T06:05:00+07:00');
        $handle('CHECK_OUT', '2026-08-14T10:00:00+07:00');
        $handle('CHECK_IN', '2026-08-14T11:00:00+07:00');
        $handle('CHECK_OUT', '2026-08-14T14:00:00+07:00');
        $handle('CHECK_OUT', '2026-08-14T14:00:00+07:00');

        $attendance = DB::table('attendances')->where('employee_id', $worker['id'])->first();
        $this->assertSame('06:00:00', substr((string) $attendance->check_in_time, 0, 8));
        $this->assertSame('10:00:00', substr((string) $attendance->check_out_time, 0, 8));
        $this->assertSame('11:00:00', substr((string) $attendance->check_in_time_2, 0, 8));
        $this->assertSame('14:00:00', substr((string) $attendance->check_out_time_2, 0, 8));
        $this->assertSame(5, DB::table('attendance_logs')->where('employee_id', $worker['id'])->count());
        $this->assertSame(1, DB::table('attendances')->where('employee_id', $worker['id'])->count());
    }

    public function test_summary_operation_chunks_progress_and_is_idempotent(): void
    {
        $accountant = $this->actor('ACCOUNTANT', ['payroll']);
        foreach (range(1, 205) as $index) {
            DB::table('employees')->insert([
                'tenant_id' => 1, 'legal_entity_id' => 1,
                'employee_code' => 'SUM'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'full_name' => 'Summary employee '.$index, 'status' => 'ACTIVE',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $periodId = DB::table('salary_periods')->insertGetId([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'period_code' => 'SUMMARY-205',
            'period_name' => 'Summary 205', 'period_type' => 'MONTHLY',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $operation = AttendanceOperation::create([
            'id' => (string) Str::uuid(), 'tenant_id' => 1, 'legal_entity_id' => 1,
            'requested_by' => $accountant['id'], 'type' => 'SUMMARY', 'status' => 'PENDING',
            'filters' => ['salary_period_id' => $periodId],
        ]);

        $job = new RunAttendanceSummaryOperation($operation->id);
        $job->handle(app(AttendanceSummaryService::class));
        $operation->refresh();
        $expected = DB::table('employees')->where('tenant_id', 1)->where('legal_entity_id', 1)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])->count();
        $this->assertSame('COMPLETED', $operation->status);
        $this->assertSame($expected, (int) $operation->processed_items);
        $this->assertSame($expected, DB::table('salary_attendance_summary')->where('period_id', $periodId)->count());

        $job->handle(app(AttendanceSummaryService::class));
        $this->assertSame($expected, DB::table('salary_attendance_summary')->where('period_id', $periodId)->count());
    }

    public function test_timesheet_overview_totals_match_multiple_grid_pages(): void
    {
        $hr = $this->actor('HR', ['hr', 'time']);
        foreach (range(1, 31) as $index) {
            $worker = $this->actor('EMPLOYEE');
            DB::table('attendances')->insert([
                'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $worker['id'],
                'work_date' => '2026-08-10', 'status' => 'ON_TIME',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $pageOne = $this->withToken($hr['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08&page=1&per_page=25')
            ->assertOk();
        $pageTwo = $this->withToken($hr['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08&page=2&per_page=25')
            ->assertOk();
        $overview = $this->withToken($hr['token'])
            ->getJson('/api/v1/attendance/timesheet/overview?month=2026-08')
            ->assertOk();

        $this->assertGreaterThan(25, (int) $overview->json('data.employees'));
        $this->assertSame(
            (int) $pageOne->json('data.pagination.total'),
            (int) $overview->json('data.employees'),
        );
        $this->assertNotEmpty($pageTwo->json('data.rows'));
    }

    /** @return array{id:int,token:string} */
    private function actor(string $roleCode, array $modules = [], bool $admin = false): array
    {
        $suffix = Str::upper(Str::random(8));
        $id = DB::table('employees')->insertGetId([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_code' => 'AT'.$suffix,
            'full_name' => Str::headline($roleCode).' '.$suffix, 'company_email' => strtolower($suffix).'@example.test',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($roleCode !== 'EMPLOYEE') {
            $roleId = DB::table('roles')->insertGetId([
                'tenant_id' => 1, 'role_code' => $roleCode, 'role_name' => $roleCode,
                'is_system_role' => true,
                'meta' => json_encode($admin ? ['is_admin' => true] : ['modules' => $modules]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('employee_roles')->insert([
                'tenant_id' => 1, 'employee_id' => $id, 'role_id' => $roleId,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'tenant_id' => 1, 'employee_id' => $id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('id', 'token');
    }
}
