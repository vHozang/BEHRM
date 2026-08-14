<?php

namespace Tests\Feature;

use App\Events\AttendanceChanged;
use App\Jobs\GenerateTimesheetExport;
use App\Models\Attendance;
use App\Models\AttendanceTimesheetExport;
use App\Services\AttendanceChangePublisher;
use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Role test tenant',
            'code' => 'ROLE-TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1,
            'name' => 'Role test entity',
            'code' => 'ROLE-TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_management_roles_only_reach_their_modules(): void
    {
        $admin = $this->actor('admin', null, true);
        $hr = $this->actor('hr', ['hr', 'time', 'recruitment', 'communications']);
        $manager = $this->actor('manager', ['time']);
        $accountant = $this->actor('accountant', ['payroll']);
        $employee = $this->actor('employee');

        $this->withToken($manager['token'])->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles.0.role_code', 'MANAGER')
            ->assertJsonPath('data.roles.0.role_name', 'Manager')
            ->assertJsonPath('access.roles.0.role_code', 'MANAGER');
        $this->withToken($employee['token'])->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('access.roles', []);

        $this->withToken($admin['token'])->getJson('/api/v1/settings/catalog')->assertOk();
        $this->withToken($admin['token'])->getJson('/api/v1/salary-periods')->assertOk();

        $this->withToken($hr['token'])->getJson('/api/v1/assets')->assertOk();
        $this->withToken($hr['token'])->getJson('/api/v1/recruitment-candidates')->assertOk();
        $this->withToken($hr['token'])->getJson('/api/v1/recruitment-posts')->assertOk();
        Http::fake([
            '*/feedback/stats' => Http::response(['total_feedback' => 0]),
            '*/feedback/adjustments' => Http::response(['adjustments' => []]),
        ]);
        $this->withToken($hr['token'])->getJson('/api/v1/recruitment-ai/feedback-stats')->assertOk();
        $this->withToken($hr['token'])->getJson('/api/v1/salary-periods')->assertForbidden();

        $this->withToken($manager['token'])->getJson('/api/v1/attendances')->assertOk();
        $this->withToken($manager['token'])->getJson('/api/v1/assets')->assertForbidden();
        $this->withToken($manager['token'])->getJson('/api/v1/salary-periods')->assertForbidden();
        $managerDashboard = $this->withToken($manager['token'])->getJson('/api/v1/dashboard/stats')->assertOk()->json('data');
        $this->assertArrayHasKey('attendances_today', $managerDashboard);
        $this->assertArrayNotHasKey('recruitment', $managerDashboard);
        $this->assertArrayNotHasKey('contracts', $managerDashboard);
        $this->assertArrayNotHasKey('upcoming', $managerDashboard);

        $this->withToken($accountant['token'])->getJson('/api/v1/salary-periods')->assertOk();
        $this->withToken($accountant['token'])->getJson('/api/v1/attendances')->assertForbidden();
        $this->withToken($accountant['token'])->getJson('/api/v1/assets')->assertForbidden();
        $this->withToken($accountant['token'])->getJson('/api/v1/dashboard/stats')->assertForbidden();

        $this->withToken($employee['token'])->getJson('/api/v1/news')->assertOk();
        $this->withToken($employee['token'])->postJson('/api/v1/news', ['title' => 'Blocked'])->assertForbidden();
        $this->withToken($employee['token'])->getJson('/api/v1/assets')->assertForbidden();
        $this->withToken($employee['token'])->getJson('/api/v1/dashboard/stats')->assertForbidden();
        $this->withToken($employee['token'])
            ->getJson('/api/v1/attendances?employee_id='.$employee['id'])
            ->assertOk();

        $this->assertFalse(AccessControl::allows(
            ['full' => false, 'modules' => []],
            'GET',
            'unmapped-future-resource'
        ));
    }

    public function test_attendance_cursor_list_is_light_and_overview_is_separate(): void
    {
        $manager = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('attendance-worker');
        $now = now();
        $attendanceIds = [];

        foreach ([
            ['2026-08-01', 'ON_TIME', ['review_status' => 'needs_review']],
            ['2026-08-02', 'LATE', []],
            ['2026-08-03', 'EARLY_LEAVE', []],
            ['2026-08-04', 'ABSENT', []],
        ] as [$date, $status, $meta]) {
            $attendanceIds[] = DB::table('attendances')->insertGetId([
                'employee_id' => $employee['id'],
                'work_date' => $date,
                'status' => $status,
                'meta' => json_encode($meta),
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('attendance_payroll_reviews')->insert([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'attendance_id' => $attendanceIds[1],
            'employee_id' => $employee['id'],
            'work_date' => '2026-08-02',
            'late_minutes' => 15,
            'early_leave_minutes' => 0,
            'default_percent' => 0,
            'status' => 'PENDING',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendances?pagination=cursor&from=2026-08-01&to=2026-08-31&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.has_more', true)
            ->assertJsonMissingPath('data.items.0.meta')
            ->assertJsonMissingPath('data.items.0.device_events');

        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/overview?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.present', 1)
            ->assertJsonPath('data.absent', 1)
            ->assertJsonPath('data.late', 1)
            ->assertJsonPath('data.early_leave', 1)
            ->assertJsonPath('data.needs_review', 1)
            ->assertJsonPath('data.payroll_review_pending', 1);

        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendances?pagination=cursor&from=2026-08-01&to=2026-08-31&payroll_review=unresolved&limit=50')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $attendanceIds[1]);
    }

    public function test_attendance_cursor_navigation_has_no_duplicates_or_gaps(): void
    {
        $manager = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('cursor-worker');
        $ids = [];

        foreach (range(1, 6) as $day) {
            $ids[] = DB::table('attendances')->insertGetId([
                'employee_id' => $employee['id'],
                'work_date' => sprintf('2026-08-%02d', $day),
                'status' => 'ON_TIME',
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $first = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendances?pagination=cursor&limit=2')
            ->assertOk()
            ->json('data');
        $second = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendances?pagination=cursor&limit=2&cursor='.urlencode($first['next_cursor']))
            ->assertOk()
            ->json('data');
        $back = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendances?pagination=cursor&limit=2&cursor='.urlencode($second['prev_cursor']))
            ->assertOk()
            ->json('data');

        $firstIds = array_column($first['items'], 'id');
        $secondIds = array_column($second['items'], 'id');
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        $this->assertSame($firstIds, array_column($back['items'], 'id'));
        $this->assertSame(array_slice(array_reverse($ids), 0, 4), array_merge($firstIds, $secondIds));
    }

    public function test_attendance_detail_is_scoped_to_owner_tenant_and_entity(): void
    {
        $employee = $this->actor('detail-owner');
        $otherEmployee = $this->actor('detail-other');
        $manager = $this->actor('hr', ['hr', 'time']);
        $otherEntityManager = $this->actor('hr-other-entity', ['hr', 'time']);
        DB::table('legal_entities')->insert([
            'id' => 2,
            'tenant_id' => 1,
            'name' => 'Second entity',
            'code' => 'ROLE-SECOND',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $otherEntityManager['id'])->update(['legal_entity_id' => 2]);

        $attendanceId = DB::table('attendances')->insertGetId([
            'employee_id' => $employee['id'],
            'work_date' => '2026-08-10',
            'status' => 'ON_TIME',
            'meta' => json_encode(['device_events' => [['device_id' => 'private-device']]]),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($employee['token'])->getJson('/api/v1/attendances/'.$attendanceId)->assertOk();
        $this->withToken($otherEmployee['token'])->getJson('/api/v1/attendances/'.$attendanceId)->assertForbidden();
        $this->withToken($manager['token'])->getJson('/api/v1/attendances/'.$attendanceId)->assertOk();
        $this->withToken($otherEntityManager['token'])->getJson('/api/v1/attendances/'.$attendanceId)->assertNotFound();

        $foreignTenant = $this->actor('detail-foreign', null, false, 2);
        $this->withToken($foreignTenant['token'])->getJson('/api/v1/attendances/'.$attendanceId)->assertForbidden();
    }

    public function test_attendance_changes_and_overview_cache_are_invalidated_after_commit(): void
    {
        Event::fake([AttendanceChanged::class]);
        $manager = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('changes-worker');

        $baseline = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/changes')
            ->assertOk()
            ->json('data.next_cursor');
        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/overview?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        TenantContext::set(1, 1);
        $attendance = Attendance::create([
            'employee_id' => $employee['id'],
            'work_date' => '2026-08-12',
            'status' => 'ON_TIME',
            'legal_entity_id' => 1,
        ]);
        TenantContext::clear();

        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/overview?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/changes?since='.urlencode($baseline))
            ->assertOk()
            ->assertJsonPath('data.items.0.attendance_id', $attendance->id)
            ->assertJsonPath('data.items.0.employee_id', $employee['id']);
        Event::assertDispatched(AttendanceChanged::class);
    }

    public function test_attendance_changes_requests_a_reset_when_cursor_is_older_than_retention(): void
    {
        $manager = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('changes-reset-worker');
        $ids = [];
        foreach (range(1, 3) as $index) {
            $ids[] = DB::table('attendance_change_events')->insertGetId([
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'attendance_id' => $index,
                'employee_id' => $employee['id'],
                'work_date' => '2026-08-12',
                'change_type' => 'updated',
                'created_at' => now(),
            ]);
        }
        DB::table('attendance_change_events')->whereIn('id', array_slice($ids, 0, 2))->delete();

        $staleCursor = AttendanceChangePublisher::encodeCursor(0);
        $response = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/changes?since='.urlencode($staleCursor))
            ->assertOk()
            ->assertJsonPath('data.reset_required', true)
            ->assertJsonPath('data.has_more', false)
            ->assertJsonCount(0, 'data.items');

        $this->assertGreaterThan(
            0,
            AttendanceChangePublisher::decodeCursor((string) $response->json('data.next_cursor'))
        );
    }

    public function test_attendance_overview_still_works_when_cache_store_is_unavailable(): void
    {
        $manager = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('overview-cache-worker');
        DB::table('attendances')->insert([
            'employee_id' => $employee['id'],
            'work_date' => '2026-08-12',
            'status' => 'ON_TIME',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config([
            'cache.stores.attendance-unavailable' => [
                'driver' => 'redis',
                'connection' => 'attendance-unavailable',
            ],
            'database.redis.attendance-unavailable' => [
                'url' => null,
                'host' => '127.0.0.1',
                'username' => null,
                'password' => null,
                'port' => 1,
                'database' => 0,
                'read_timeout' => 0.05,
                'timeout' => 0.05,
            ],
            'hrm.attendance.overview_cache_store' => 'attendance-unavailable',
        ]);

        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/overview?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.present', 1);
    }

    public function test_attendance_realtime_private_channel_auth_enforces_scope(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'public-key',
            'broadcasting.connections.reverb.secret' => 'private-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        $manager = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('realtime-worker');

        $this->withToken($employee['token'])->postJson('/api/v1/attendance/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-attendance.employee.'.$employee['id'],
        ])->assertOk()->assertJsonStructure(['auth']);
        $this->withToken($employee['token'])->postJson('/api/v1/attendance/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-attendance.employee.'.$manager['id'],
        ])->assertForbidden();
        $this->withToken($employee['token'])->postJson('/api/v1/attendance/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-attendance.tenant.1.entity.1',
        ])->assertForbidden();
        $this->withToken($manager['token'])->postJson('/api/v1/attendance/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-attendance.tenant.1.entity.1',
        ])->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_manager_realtime_and_change_replay_are_limited_to_managed_department(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'public-key',
            'broadcasting.connections.reverb.secret' => 'private-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        $manager = $this->actor('manager', ['time']);
        $managed = $this->actor('managed-realtime');
        $other = $this->actor('other-realtime');
        $managedDepartment = DB::table('departments')->insertGetId([
            'department_code' => 'RT-MANAGED', 'department_name' => 'Realtime managed',
            'tenant_id' => 1, 'legal_entity_id' => 1,
            'meta' => json_encode(['manager_id' => $manager['id']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDepartment = DB::table('departments')->insertGetId([
            'department_code' => 'RT-OTHER', 'department_name' => 'Realtime other',
            'tenant_id' => 1, 'legal_entity_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $managed['id'])->update(['department_id' => $managedDepartment]);
        DB::table('employees')->where('id', $other['id'])->update(['department_id' => $otherDepartment]);
        $baseline = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/changes')
            ->assertOk()
            ->json('data.next_cursor');
        foreach ([[$managed, $managedDepartment], [$other, $otherDepartment]] as [$employee, $departmentId]) {
            DB::table('attendance_change_events')->insert([
                'tenant_id' => 1, 'legal_entity_id' => 1, 'department_id' => $departmentId,
                'attendance_id' => null, 'employee_id' => $employee['id'],
                'work_date' => '2026-08-12', 'change_type' => 'updated', 'created_at' => now(),
            ]);
        }

        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/realtime/config')
            ->assertOk()
            ->assertJsonPath('data.channels.0', 'attendance.tenant.1.department.'.$managedDepartment)
            ->assertJsonCount(1, 'data.channels');
        $this->withToken($manager['token'])->postJson('/api/v1/attendance/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-attendance.tenant.1.department.'.$managedDepartment,
        ])->assertOk();
        $this->withToken($manager['token'])->postJson('/api/v1/attendance/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-attendance.tenant.1.department.'.$otherDepartment,
        ])->assertForbidden();
        $items = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/changes?since='.urlencode($baseline))
            ->assertOk()
            ->json('data.items');
        $this->assertSame([$managed['id']], array_column($items, 'employee_id'));
    }

    public function test_reverb_allowed_origins_are_normalized_to_hostnames(): void
    {
        $previous = getenv('REVERB_ALLOWED_ORIGINS');
        try {
            putenv('REVERB_ALLOWED_ORIGINS=https://devtapcode.io.vn,https://www.devtapcode.io.vn');
            $config = require config_path('reverb.php');

            $this->assertSame(
                ['devtapcode.io.vn', 'www.devtapcode.io.vn'],
                $config['apps']['apps'][0]['allowed_origins']
            );
        } finally {
            $previous === false
                ? putenv('REVERB_ALLOWED_ORIGINS')
                : putenv('REVERB_ALLOWED_ORIGINS='.$previous);
        }
    }

    public function test_timesheet_pagination_and_exports_enforce_owner_scope(): void
    {
        Queue::fake();
        $manager = $this->actor('hr', ['hr', 'time']);
        $employees = [];
        foreach (range(1, 4) as $index) {
            $employees[] = $this->actor('timesheet-worker-'.$index);
        }

        $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08&page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.pagination.last_page', 3);

        $employee = $employees[0];
        $other = $employees[1];
        $this->withToken($employee['token'])->postJson('/api/v1/attendance/timesheet/exports', [
            'month' => '2026-08',
        ])->assertForbidden();
        $this->withToken($employee['token'])->postJson('/api/v1/attendance/timesheet/exports', [
            'month' => '2026-08',
            'employee_id' => $other['id'],
        ])->assertForbidden();
        $exportId = $this->withToken($employee['token'])->postJson('/api/v1/attendance/timesheet/exports', [
            'month' => '2026-08',
            'format' => 'csv',
            'employee_id' => $employee['id'],
        ])->assertCreated()->json('data.id');
        Queue::assertPushed(GenerateTimesheetExport::class, fn ($job) => $job->exportId === $exportId);
        $this->withToken($employee['token'])->getJson('/api/v1/attendance/timesheet/exports/'.$exportId)->assertOk();
        $this->withToken($other['token'])->getJson('/api/v1/attendance/timesheet/exports/'.$exportId)->assertForbidden();
    }

    public function test_manager_timesheet_and_export_are_limited_to_managed_department(): void
    {
        Queue::fake();
        $manager = $this->actor('manager', ['time']);
        $managedWorker = $this->actor('managed-worker');
        $otherWorker = $this->actor('other-worker');
        $managedDepartment = DB::table('departments')->insertGetId([
            'department_code' => 'QA-MANAGED',
            'department_name' => 'Managed department',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'meta' => json_encode(['manager_id' => $manager['id']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherDepartment = DB::table('departments')->insertGetId([
            'department_code' => 'QA-OTHER',
            'department_name' => 'Other department',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $managedWorker['id'])->update(['department_id' => $managedDepartment]);
        DB::table('employees')->where('id', $otherWorker['id'])->update(['department_id' => $otherDepartment]);

        $rows = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08&page=1&per_page=25')
            ->assertOk()
            ->json('data.rows');
        $this->assertContains($managedWorker['id'], array_column($rows, 'employee_id'));
        $this->assertNotContains($otherWorker['id'], array_column($rows, 'employee_id'));

        $exportId = $this->withToken($manager['token'])
            ->postJson('/api/v1/attendance/timesheet/exports', ['month' => '2026-08', 'format' => 'csv'])
            ->assertCreated()
            ->json('data.id');
        $filters = DB::table('attendance_timesheet_exports')->where('id', $exportId)->value('filters');
        $filters = is_string($filters) ? json_decode($filters, true) : (array) $filters;
        $this->assertContains($managedWorker['id'], $filters['employee_ids']);
        $this->assertNotContains($otherWorker['id'], $filters['employee_ids']);
    }

    public function test_accountant_can_queue_summary_but_cannot_read_or_modify_attendance(): void
    {
        Queue::fake();
        $accountant = $this->actor('accountant', ['payroll']);
        $employee = $this->actor('summary-worker');
        $periodId = DB::table('salary_periods')->insertGetId([
            'period_code' => 'QA-SUMMARY',
            'period_name' => 'QA Summary',
            'period_type' => 'MONTHLY',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'OPEN',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendanceId = DB::table('attendances')->insertGetId([
            'employee_id' => $employee['id'],
            'work_date' => '2026-08-01',
            'status' => 'ON_TIME',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = $this->withToken($accountant['token'])
            ->postJson('/api/v1/attendance/summary/run', ['salary_period_id' => $periodId])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING')
            ->json('data.run_id');
        $this->withToken($accountant['token'])->getJson('/api/v1/attendance/operations/'.$runId)->assertOk();
        $this->withToken($accountant['token'])->getJson('/api/v1/attendances')->assertForbidden();
        $this->withToken($accountant['token'])
            ->patchJson('/api/v1/attendances/'.$attendanceId, ['notes' => 'blocked'])
            ->assertForbidden();
    }

    public function test_adjustment_pagination_manager_scope_and_self_approval_guard(): void
    {
        $manager = $this->actor('manager', ['time']);
        $managed = $this->actor('managed-adjustment');
        $other = $this->actor('other-adjustment');
        $managedDepartment = DB::table('departments')->insertGetId([
            'department_code' => 'ADJ-MANAGED', 'department_name' => 'Managed adjustments',
            'tenant_id' => 1, 'legal_entity_id' => 1,
            'meta' => json_encode(['manager_id' => $manager['id']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDepartment = DB::table('departments')->insertGetId([
            'department_code' => 'ADJ-OTHER', 'department_name' => 'Other adjustments',
            'tenant_id' => 1, 'legal_entity_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $managed['id'])->update(['department_id' => $managedDepartment]);
        DB::table('employees')->where('id', $other['id'])->update(['department_id' => $otherDepartment]);

        foreach (range(1, 16) as $index) {
            DB::table('attendance_adjustment_requests')->insert([
                'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $managed['id'],
                'work_date' => sprintf('2026-07-%02d', $index),
                'requested_check_in_time' => '08:00:00', 'reason' => 'Managed '.$index,
                'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $foreignId = DB::table('attendance_adjustment_requests')->insertGetId([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $other['id'],
            'work_date' => '2026-07-20', 'requested_check_in_time' => '08:00:00',
            'reason' => 'Outside department', 'status' => 'PENDING',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $selfId = DB::table('attendance_adjustment_requests')->insertGetId([
            'tenant_id' => 1, 'legal_entity_id' => 1, 'employee_id' => $manager['id'],
            'work_date' => '2026-07-21', 'requested_check_in_time' => '08:00:00',
            'reason' => 'Self request', 'status' => 'PENDING',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pageOne = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance-adjustments?per_page=15&page=1')
            ->assertOk()
            ->assertJsonCount(15, 'data.items')
            ->assertJsonPath('data.pagination.total', 17)
            ->assertJsonPath('data.pagination.last_page', 2);
        $pageTwo = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance-adjustments?per_page=15&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
        $visibleIds = array_merge(
            array_column($pageOne->json('data.items'), 'id'),
            array_column($pageTwo->json('data.items'), 'id'),
        );
        $this->assertNotContains($foreignId, $visibleIds);
        $this->withToken($manager['token'])
            ->postJson('/api/v1/attendance-adjustments/'.$foreignId.'/approve')
            ->assertNotFound();
        $this->withToken($manager['token'])
            ->postJson('/api/v1/attendance-adjustments/'.$selfId.'/approve')
            ->assertStatus(422);
    }

    public function test_overtime_pagination_summary_and_manager_scope_cover_full_filtered_set(): void
    {
        $manager = $this->actor('manager', ['time']);
        $managed = $this->actor('managed-ot-page');
        $other = $this->actor('other-ot-page');
        $managedDepartment = DB::table('departments')->insertGetId([
            'department_code' => 'OT-MANAGED', 'department_name' => 'Managed overtime',
            'tenant_id' => 1, 'legal_entity_id' => 1,
            'meta' => json_encode(['manager_id' => $manager['id']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDepartment = DB::table('departments')->insertGetId([
            'department_code' => 'OT-OTHER', 'department_name' => 'Other overtime',
            'tenant_id' => 1, 'legal_entity_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $managed['id'])->update(['department_id' => $managedDepartment]);
        DB::table('employees')->where('id', $other['id'])->update(['department_id' => $otherDepartment]);

        foreach (range(1, 16) as $index) {
            DB::table('overtime_requests')->insert([
                'tenant_id' => 1, 'employee_id' => $managed['id'],
                'work_date' => sprintf('2026-07-%02d', $index),
                'start_time' => '18:00:00', 'end_time' => '19:00:00', 'total_hours' => 1,
                'status' => $index <= 10 ? 'PENDING' : 'APPROVED',
                'meta' => json_encode(['kind' => 'EMPLOYEE_REQUEST', 'payable_overtime_minutes' => 60]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $foreignId = DB::table('overtime_requests')->insertGetId([
            'tenant_id' => 1, 'employee_id' => $other['id'], 'work_date' => '2026-07-20',
            'start_time' => '18:00:00', 'end_time' => '19:00:00', 'total_hours' => 1,
            'status' => 'PENDING', 'meta' => json_encode(['kind' => 'EMPLOYEE_REQUEST']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pageOne = $this->withToken($manager['token'])
            ->getJson('/api/v1/overtime-requests?kind=EMPLOYEE_REQUEST&per_page=15&page=1')
            ->assertOk()
            ->assertJsonCount(15, 'data.items')
            ->assertJsonPath('data.pagination.total', 16)
            ->assertJsonPath('data.summary.total', 16)
            ->assertJsonPath('data.summary.pending', 10)
            ->assertJsonPath('data.summary.approved', 6)
            ->assertJsonPath('data.summary.payable_minutes', 960);
        $pageTwo = $this->withToken($manager['token'])
            ->getJson('/api/v1/overtime-requests?kind=EMPLOYEE_REQUEST&per_page=15&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
        $visibleIds = array_merge(
            array_column($pageOne->json('data.items'), 'id'),
            array_column($pageTwo->json('data.items'), 'id'),
        );
        $this->assertNotContains($foreignId, $visibleIds);
        $this->withToken($manager['token'])
            ->postJson('/api/v1/overtime-requests/'.$foreignId.'/approve')
            ->assertNotFound();
    }

    public function test_overtime_legacy_kind_and_get_are_read_only(): void
    {
        $hr = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('legacy-ot-worker');
        $otId = DB::table('overtime_requests')->insertGetId([
            'employee_id' => $employee['id'],
            'work_date' => '2026-08-10',
            'start_time' => '18:00:00',
            'end_time' => '19:00:00',
            'total_hours' => 1,
            'status' => 'APPROVED',
            'meta' => json_encode(['reason' => 'legacy']),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = DB::table('overtime_requests')->where('id', $otId)->first(['meta', 'updated_at']);

        $this->withToken($hr['token'])
            ->getJson('/api/v1/overtime-requests?kind=EMPLOYEE_REQUEST&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $otId)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.summary.total', 1);

        $after = DB::table('overtime_requests')->where('id', $otId)->first(['meta', 'updated_at']);
        $this->assertSame($before->meta, $after->meta);
        $this->assertSame((string) $before->updated_at, (string) $after->updated_at);
    }

    public function test_timesheet_page_cache_is_reused_and_invalidated_by_attendance_version(): void
    {
        Cache::store('array')->flush();
        $manager = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('timesheet-cache-worker');
        config([
            'hrm.attendance.overview_cache_store' => 'array',
            'hrm.attendance.timesheet_cache_seconds' => 60,
        ]);

        $first = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08&page=1&per_page=25')
            ->assertOk()
            ->json('data');

        DB::table('employees')->where('id', $employee['id'])->update(['full_name' => 'Changed outside cache']);
        $cached = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08&page=1&per_page=25')
            ->assertOk()
            ->json('data');
        $this->assertSame($first, $cached);

        TenantContext::set(1, 1);
        Attendance::create([
            'employee_id' => $employee['id'],
            'work_date' => '2026-08-12',
            'status' => 'ON_TIME',
            'legal_entity_id' => 1,
        ]);
        TenantContext::clear();

        $refreshed = $this->withToken($manager['token'])
            ->getJson('/api/v1/attendance/timesheet?month=2026-08&page=1&per_page=25')
            ->assertOk()
            ->json('data');
        $this->assertNotSame($first, $refreshed);
        $this->assertContains('Changed outside cache', array_column($refreshed['rows'], 'full_name'));
    }

    public function test_timesheet_export_generates_private_xlsx_and_csv_files(): void
    {
        Storage::fake('local');
        $manager = $this->actor('hr', ['hr', 'time']);
        $employee = $this->actor('export-worker');
        DB::table('attendances')->insert([
            'employee_id' => $employee['id'],
            'work_date' => '2026-08-12',
            'check_in_time' => '06:00:00',
            'check_out_time' => '14:00:00',
            'status' => 'ON_TIME',
            'meta' => json_encode(['worked_hours' => 8]),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['xlsx', 'csv'] as $format) {
            $exportId = $this->withToken($manager['token'])->postJson('/api/v1/attendance/timesheet/exports', [
                'month' => '2026-08',
                'format' => $format,
                'employee_id' => $employee['id'],
            ])->assertCreated()->json('data.id');

            $export = AttendanceTimesheetExport::withoutTenantScope()->findOrFail($exportId);
            $this->assertSame('COMPLETED', $export->status);
            $this->assertNotNull($export->file_path);
            Storage::disk('local')->assertExists($export->file_path);
            $this->assertGreaterThan(0, (int) $export->file_size);
            $response = $this->withToken($manager['token'])
                ->get('/api/v1/attendance/timesheet/exports/'.$exportId.'/download')
                ->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        }
    }

    public function test_expired_timesheet_export_is_not_downloadable(): void
    {
        Storage::fake('local');
        $manager = $this->actor('hr', ['hr', 'time']);
        $path = 'attendance/timesheet-exports/1/expired.csv';
        Storage::disk('local')->put($path, "employee_code,full_name\n");
        $exportId = (string) Str::uuid();
        DB::table('attendance_timesheet_exports')->insert([
            'id' => $exportId,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'requested_by' => $manager['id'],
            'month' => '2026-08',
            'format' => 'csv',
            'status' => 'COMPLETED',
            'file_path' => $path,
            'file_size' => Storage::disk('local')->size($path),
            'completed_at' => now()->subDays(2),
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->withToken($manager['token'])
            ->get('/api/v1/attendance/timesheet/exports/'.$exportId.'/download')
            ->assertNotFound();
    }

    public function test_helpdesk_is_self_scoped_and_keeps_history(): void
    {
        $first = $this->actor('first');
        $second = $this->actor('second');
        $hr = $this->actor('helpdesk', ['communications']);

        $firstTicket = $this->withToken($first['token'])->postJson('/api/v1/service-tickets', [
            'ticket_code' => 'QA-1',
            'requester_id' => $second['id'],
            'title' => 'First ticket',
            'description' => 'Created by first employee',
            'priority' => 'normal',
            'status' => 'completed',
        ])->assertCreated()
            ->assertJsonPath('data.requester_id', $first['id'])
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $secondTicket = $this->withToken($second['token'])->postJson('/api/v1/service-tickets', [
            'ticket_code' => 'QA-2',
            'title' => 'Second ticket',
            'description' => 'Created by second employee',
            'priority' => 'high',
        ])->assertCreated()->json('data.id');

        $this->withToken($first['token'])->getJson('/api/v1/service-tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $firstTicket);
        $this->withToken($first['token'])
            ->getJson('/api/v1/service-tickets/'.$secondTicket)
            ->assertNotFound();
        $this->withToken($first['token'])
            ->patchJson('/api/v1/service-tickets/'.$secondTicket, ['status' => 'cancelled'])
            ->assertForbidden();

        $this->withToken($first['token'])
            ->patchJson('/api/v1/service-tickets/'.$firstTicket, ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->withToken($first['token'])
            ->deleteJson('/api/v1/service-tickets/'.$firstTicket)
            ->assertConflict();
        $this->assertDatabaseHas('service_tickets', ['id' => $firstTicket, 'status' => 'cancelled']);

        $this->withToken($hr['token'])->getJson('/api/v1/service-tickets')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
        $this->withToken($hr['token'])
            ->patchJson('/api/v1/service-tickets/'.$secondTicket, ['status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_employee_directory_hides_sensitive_fields_from_regular_employees(): void
    {
        $viewer = $this->actor('viewer');
        DB::table('employees')->where('id', $viewer['id'])->update([
            'personal_email' => 'private@example.test',
            'date_of_birth' => '1990-01-01',
            'base_salary' => 50000000,
            'profile' => json_encode(['identity_number' => 'secret']),
        ]);

        $item = $this->withToken($viewer['token'])
            ->getJson('/api/v1/employees?employee_code='.$viewer['code'])
            ->assertOk()
            ->json('data.items.0');

        $this->assertArrayNotHasKey('personal_email', $item);
        $this->assertArrayNotHasKey('date_of_birth', $item);
        $this->assertArrayNotHasKey('base_salary', $item);
        $this->assertArrayNotHasKey('profile', $item);
    }

    public function test_employee_cannot_read_another_employees_private_profile(): void
    {
        $viewer = $this->actor('profile-viewer');
        $target = $this->actor('profile-target');
        $hr = $this->actor('profile-hr', ['hr']);

        DB::table('employees')->where('id', $target['id'])->update([
            'base_salary' => 50000000,
            'profile' => json_encode(['identity_number' => 'secret-profile']),
        ]);
        DB::table('social_insurance_info')->insert([
            'employee_id' => $target['id'],
            'social_insurance_number' => 'SI-SECRET',
            'tax_code' => 'TAX-SECRET',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($viewer['token'])
            ->getJson('/api/v1/employees/'.$target['id'].'/profile')
            ->assertForbidden()
            ->assertJsonMissing(['base_salary' => 50000000])
            ->assertJsonMissing(['tax_code' => 'TAX-SECRET']);

        $this->withToken($target['token'])
            ->getJson('/api/v1/employees/'.$target['id'].'/profile')
            ->assertOk()
            ->assertJsonPath('data.employee.base_salary', 50000000)
            ->assertJsonPath('data.social_insurance.tax_code', 'TAX-SECRET');

        $this->withToken($hr['token'])
            ->getJson('/api/v1/employees/'.$target['id'].'/profile')
            ->assertOk()
            ->assertJsonPath('data.social_insurance.social_insurance_number', 'SI-SECRET');
    }

    public function test_management_ui_endpoints_enforce_roles_tenants_and_idempotency(): void
    {
        DB::table('tenants')->insert([
            'id' => 2, 'name' => 'Second tenant', 'code' => 'ROLE-TEST-2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'id' => 2, 'tenant_id' => 2, 'name' => 'Second entity', 'code' => 'ROLE-TEST-2',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $admin = $this->actor('admin-ui', null, true);
        $otherAdmin = $this->actor('admin-ui-2', null, true, 2);
        $hr = $this->actor('hr', ['hr', 'time', 'recruitment']);
        $manager = $this->actor('manager', ['time']);
        $accountant = $this->actor('accountant', ['payroll']);
        $target = $this->actor('certificate-target');

        $template = [
            'notification_type' => 'candidate_applied',
            'recipients' => 'HR',
            'template_subject' => 'Có ứng viên mới',
            'template_body' => 'Ứng viên {{candidate_name}} vừa nộp hồ sơ.',
            'status' => true,
        ];
        $this->withToken($admin['token'])->putJson('/api/v1/settings/notifications', ['items' => [$template]])
            ->assertOk()->assertJsonPath('data.items.0.notification_type', 'candidate_applied');
        $this->withToken($otherAdmin['token'])->getJson('/api/v1/settings/notifications')
            ->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($hr['token'])->getJson('/api/v1/settings/notifications')->assertForbidden();

        $probationEmail = 'probation.'.Str::lower(Str::random(8)).'@example.test';
        $this->withToken($hr['token'])->postJson('/api/v1/employees/import-probation', [
            'employees' => [
                ['employee_code' => 'TV'.Str::upper(Str::random(6)), 'full_name' => 'Valid Probation', 'company_email' => $probationEmail],
                ['employee_code' => 'TV'.Str::upper(Str::random(6)), 'full_name' => 'Missing Email'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonCount(1, 'data.errors');
        $this->withToken($manager['token'])->postJson('/api/v1/employees/import-probation', [
            'employees' => [['full_name' => 'Blocked', 'company_email' => 'blocked@example.test']],
        ])->assertForbidden();

        $certificateId = $this->withToken($hr['token'])->postJson('/api/v1/employees/'.$target['id'].'/certificates', [
            'certificate_name' => 'AWS Developer',
            'issued_by' => 'Amazon Web Services',
            'issued_date' => '2026-01-01',
            'expiry_date' => '2028-01-01',
            'certificate_number' => 'AWS-QA-1',
            'score' => 90,
            'file_url' => 'https://example.test/aws-certificate',
        ])->assertCreated()->json('data.id');
        $this->withToken($hr['token'])->getJson('/api/v1/employees/'.$target['id'].'/certificates')
            ->assertOk()->assertJsonPath('data.0.id', $certificateId);
        $this->withToken($manager['token'])->deleteJson('/api/v1/employees/'.$target['id'].'/certificates/'.$certificateId)
            ->assertForbidden();
        $this->withToken($hr['token'])->deleteJson('/api/v1/employees/'.$target['id'].'/certificates/'.$certificateId)
            ->assertOk();

        Http::fake([
            '*/feedback/stats' => Http::response(['total_feedbacks' => 3, 'distribution' => ['aligned_pct' => 66.7]]),
            '*/feedback/adjustments' => Http::response(['total_feedbacks' => 3, 'adjustments' => []]),
        ]);
        $this->withToken($hr['token'])->getJson('/api/v1/recruitment-ai/feedback-stats')->assertOk();
        $this->withToken($manager['token'])->getJson('/api/v1/recruitment-ai/feedback-stats')->assertForbidden();

        DB::table('employees')->where('id', $target['id'])->update([
            'base_salary' => 12000000,
            'hire_date' => '2025-01-01',
        ]);
        $periodId = DB::table('salary_periods')->insertGetId([
            'period_code' => 'QA-BONUS-2026', 'period_name' => 'QA Bonus', 'period_type' => 'MONTHLY',
            'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'OPEN',
            'tenant_id' => 1, 'legal_entity_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bonusPayload = [
            'salary_period_id' => $periodId,
            'window_start' => '2026-01-01',
            'window_end' => '2026-06-30',
            'rate_percent' => 50,
        ];
        $this->withToken($hr['token'])->postJson('/api/v1/payroll/bonus-run', $bonusPayload)->assertForbidden();
        $this->withToken($accountant['token'])->postJson('/api/v1/payroll/bonus-run', $bonusPayload)
            ->assertOk()->assertJsonPath('data.batch', 'BONUS-20260101-20260630');
        $firstBatchCount = DB::table('payroll_adjustments')->where('paid_period_id', $periodId)->count();
        $this->withToken($accountant['token'])->postJson('/api/v1/payroll/bonus-run', $bonusPayload)->assertOk();
        $this->assertSame($firstBatchCount, DB::table('payroll_adjustments')->where('paid_period_id', $periodId)->count());
        DB::table('salary_periods')->where('id', $periodId)->update(['status' => 'LOCKED']);
        $this->withToken($accountant['token'])->postJson('/api/v1/payroll/bonus-run', $bonusPayload)->assertStatus(422);

        $this->withToken($hr['token'])->postJson('/api/v1/leave/accrual/run', ['year' => 2026])
            ->assertOk()->assertJsonPath('data.year', 2026);
        $this->withToken($manager['token'])->postJson('/api/v1/leave/accrual/run', ['year' => 2026])->assertForbidden();
    }

    /**
     * @return array{id:int, code:string, token:string}
     */
    private function actor(string $name, ?array $modules = null, bool $admin = false, int $tenantId = 1): array
    {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'QA'.strtoupper(substr($name, 0, 6)).Str::upper(Str::random(4)),
            'full_name' => Str::headline($name),
            'company_email' => $name.'.'.Str::lower(Str::random(6)).'@example.test',
            'status' => 'ACTIVE',
            'is_super_admin' => false,
            'tenant_id' => $tenantId,
            'legal_entity_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($modules !== null || $admin) {
            $roleId = DB::table('roles')->insertGetId([
                'role_code' => strtoupper($name),
                'role_name' => Str::headline($name),
                'is_system_role' => true,
                'meta' => json_encode($admin ? ['is_admin' => true] : ['modules' => $modules]),
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('employee_roles')->insert([
                'employee_id' => $employeeId,
                'role_id' => $roleId,
                'is_active' => true,
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'employee_id' => $employeeId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $employeeId,
            'code' => DB::table('employees')->where('id', $employeeId)->value('employee_code'),
            'token' => $token,
        ];
    }
}
