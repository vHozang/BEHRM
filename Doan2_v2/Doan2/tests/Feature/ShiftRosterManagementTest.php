<?php

namespace Tests\Feature;

use App\Services\DepartmentManagerRoleService;
use App\Services\ShiftResolver;
use App\Services\ShiftRosterService;
use App\Services\ShiftRosterWorkbook;
use App\Services\TimesheetService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class ShiftRosterManagementTest extends TestCase
{
    use RefreshDatabase;

    private array $manager;

    private array $hr;

    private int $departmentA;

    private int $departmentB;

    /** @var array<string, int> */
    private array $shiftIds = [];

    /** @var array<int, array{id:int,code:string,token:string}> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-10 09:00:00');

        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Shift roster tenant',
            'code' => 'SHIFT-ROSTER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1,
            'name' => 'Shift roster entity',
            'code' => 'SHIFT-ROSTER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->manager = $this->actor('MANAGER', ['time'], 'Trưởng phòng');
        $this->hr = $this->actor('HR', ['hr', 'time', 'recruitment'], 'Nhân sự');

        $this->departmentA = DB::table('departments')->insertGetId([
            'department_code' => 'PX-A',
            'department_name' => 'Phân xưởng A',
            'status' => true,
            'meta' => json_encode(['manager_id' => $this->manager['id']]),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->departmentB = DB::table('departments')->insertGetId([
            'department_code' => 'PX-B',
            'department_name' => 'Phân xưởng B',
            'status' => true,
            'meta' => json_encode([]),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            'CA1' => ['Ca 1', '06:00', '14:00'],
            'CA2' => ['Ca 2', '14:00', '22:00'],
            'CA3' => ['Ca 3', '22:00', '06:00'],
            'HC' => ['Hành chính', '08:00', '17:00'],
        ] as $code => [$name, $start, $end]) {
            $this->shiftIds[$code] = DB::table('shift_types')->insertGetId([
                'shift_code' => $code,
                'shift_name' => $name,
                'start_time' => $start,
                'end_time' => $end,
                'status' => 'ACTIVE',
                'meta' => json_encode(['work_weekdays' => [1, 2, 3, 4, 5, 6], 'color_code' => '#0f766e']),
                'tenant_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['CA1', 'CA2', 'CA3'] as $index => $code) {
            $worker = $this->employee('CN'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT), $this->departmentA);
            $this->workers[] = $worker;
            $this->assignment($worker['id'], $code, '2026-01-01', null, 'standing');
        }

        $otherWorker = $this->employee('PXB0001', $this->departmentB);
        $this->assignment($otherWorker['id'], 'CA1', '2026-01-01', null, 'standing');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_manager_is_scoped_to_direct_department_and_cannot_edit_shift_types(): void
    {
        $this->withToken($this->manager['token'])
            ->getJson('/api/v1/shift-roster/calendar?department_id='.$this->departmentA.'&week_start=2026-08-17')
            ->assertOk()
            ->assertJsonCount(1, 'data.departments')
            ->assertJsonPath('data.department.id', $this->departmentA)
            ->assertJsonPath('data.permissions.manage_shift_types', false)
            ->assertJsonCount(3, 'data.employees');

        $this->withToken($this->manager['token'])
            ->getJson('/api/v1/shift-roster/calendar?department_id='.$this->departmentB.'&week_start=2026-08-17')
            ->assertForbidden();

        $this->withToken($this->manager['token'])->postJson('/api/v1/shift-types', [
            'shift_code' => 'QA-MANAGER',
            'shift_name' => 'Không được tạo',
            'start_time' => '09:00',
            'end_time' => '18:00',
        ])->assertForbidden();

        $this->withToken($this->hr['token'])
            ->getJson('/api/v1/shift-roster/calendar?department_id='.$this->departmentB.'&week_start=2026-08-17')
            ->assertOk()
            ->assertJsonCount(2, 'data.departments')
            ->assertJsonPath('data.permissions.manage_shift_types', true);
    }

    public function test_manager_cannot_modify_assignments_outside_direct_department(): void
    {
        $otherEmployeeId = (int) DB::table('employees')
            ->where('employee_code', 'PXB0001')
            ->value('id');
        $otherAssignmentId = $this->assignment(
            $otherEmployeeId,
            'CA1',
            '2026-08-17',
            '2026-08-17',
            'manual'
        );

        $this->withToken($this->manager['token'])
            ->getJson('/api/v1/shift-assignments/'.$otherAssignmentId)
            ->assertNotFound();

        $this->withToken($this->manager['token'])
            ->postJson('/api/v1/shift-assignments', [])
            ->assertUnprocessable();

        $this->withToken($this->manager['token'])
            ->postJson('/api/v1/shift-assignments', [
                'employee_id' => $otherEmployeeId,
                'shift_type_id' => $this->shiftIds['CA2'],
                'effective_date' => '2026-08-18',
                'expiry_date' => '2026-08-18',
                'status' => 'ACTIVE',
            ])
            ->assertForbidden();

        $this->withToken($this->manager['token'])
            ->patchJson('/api/v1/shift-assignments/'.$otherAssignmentId, [
                'shift_type_id' => $this->shiftIds['CA2'],
            ])
            ->assertForbidden();

        $this->withToken($this->manager['token'])
            ->deleteJson('/api/v1/shift-assignments/'.$otherAssignmentId)
            ->assertForbidden();

        $ownAssignment = $this->withToken($this->manager['token'])
            ->postJson('/api/v1/shift-assignments', [
                'employee_id' => $this->workers[0]['id'],
                'shift_type_id' => $this->shiftIds['CA2'],
                'effective_date' => '2026-08-18',
                'expiry_date' => '2026-08-18',
                'status' => 'ACTIVE',
            ])
            ->assertCreated()
            ->assertJsonPath('data.employee_id', $this->workers[0]['id'])
            ->json('data');

        $this->assertDatabaseHas('shift_assignments', [
            'id' => $ownAssignment['id'],
            'assigned_by' => $this->manager['id'],
        ]);
        $meta = json_decode((string) DB::table('shift_assignments')
            ->where('id', $ownAssignment['id'])
            ->value('meta'), true);
        $this->assertSame('manual', $meta['source'] ?? null);

        $this->withToken($this->hr['token'])
            ->patchJson('/api/v1/shift-assignments/'.$otherAssignmentId, [
                'shift_type_id' => $this->shiftIds['CA3'],
            ])
            ->assertOk()
            ->assertJsonPath('data.shift_type_id', $this->shiftIds['CA3']);
    }

    public function test_rotation_follows_n_plus_one_and_preserves_manual_cells(): void
    {
        $start = '2026-08-17';
        $this->assignment($this->workers[0]['id'], 'CA3', $start, $start, 'manual');

        $preview = $this->withToken($this->manager['token'])->postJson('/api/v1/shift-roster/rotation/preview', [
            'department_id' => $this->departmentA,
            'start_date' => $start,
            'weeks' => 2,
            'employee_ids' => array_column($this->workers, 'id'),
        ])->assertOk()
            ->assertJsonPath('data.employees', 3)
            ->assertJsonPath('data.assignments', 6)
            ->assertJsonCount(1, 'data.manual_conflicts')
            ->json('data');

        $transitions = collect($preview['transitions'])->keyBy('employee_code');
        $this->assertSame('CA2', $transitions['CN0001']['to']);
        $this->assertSame('CA3', $transitions['CN0002']['to']);
        $this->assertSame('CA1', $transitions['CN0003']['to']);

        $this->withToken($this->manager['token'])->postJson('/api/v1/shift-roster/rotation/apply', [
            'preview_token' => $preview['preview_token'],
            'overwrite_manual' => false,
        ])->assertOk()
            ->assertJsonPath('data.assignments_created', 6)
            ->assertJsonPath('data.manual_conflicts_preserved', 1);

        $this->assertSame(6, DB::table('shift_assignments')
            ->whereRaw("json_extract(meta, '$.source') = 'rotation'")
            ->count());

        TenantContext::set(1, 1);
        $resolver = app(ShiftResolver::class);
        $manualDay = $resolver->resolve($this->workers[0]['id'], $start, 1);
        $this->assertSame('CA3', $manualDay->shift_code);
        $this->assertSame('manual', $resolver->assignmentSource($manualDay));

        $weekTwo = $resolver->resolve($this->workers[0]['id'], '2026-08-24', 1);
        $this->assertSame('CA3', $weekTwo->shift_code);
        $this->assertSame('rotation', $resolver->assignmentSource($weekTwo));

        $this->withToken($this->manager['token'])->postJson('/api/v1/shift-roster/rotation/apply', [
            'preview_token' => $preview['preview_token'],
        ])->assertUnprocessable();

        $overwritePreview = $this->withToken($this->manager['token'])->postJson('/api/v1/shift-roster/rotation/preview', [
            'department_id' => $this->departmentA,
            'start_date' => $start,
            'weeks' => 2,
            'employee_ids' => array_column($this->workers, 'id'),
        ])->assertOk()->json('data');
        $this->withToken($this->manager['token'])->postJson('/api/v1/shift-roster/rotation/apply', [
            'preview_token' => $overwritePreview['preview_token'],
            'overwrite_manual' => true,
        ])->assertOk()->assertJsonPath('data.manual_conflicts_preserved', 0);
        $replacedDay = $resolver->resolve($this->workers[0]['id'], $start, 1);
        $this->assertSame('CA2', $replacedDay->shift_code);
        $this->assertSame('rotation', $resolver->assignmentSource($replacedDay));
    }

    public function test_rotation_reports_missing_or_ambiguous_base_shifts_and_rejects_old_week(): void
    {
        $missing = $this->employee('CNMISS', $this->departmentA);
        $this->assignment($missing['id'], 'HC', '2026-01-01', null, 'standing');

        $ambiguous = $this->employee('CNMIX', $this->departmentA);
        $this->assignment($ambiguous['id'], 'CA1', '2026-01-01', null, 'standing');
        foreach (['2026-08-13', '2026-08-14', '2026-08-15'] as $date) {
            $this->assignment($ambiguous['id'], 'CA2', $date, $date, 'manual');
        }

        $calendar = $this->withToken($this->manager['token'])
            ->getJson('/api/v1/shift-roster/calendar?department_id='.$this->departmentA.'&week_start=2026-08-17')
            ->assertOk()
            ->assertJsonCount(3, 'data.employees')
            ->assertJsonCount(2, 'data.skipped_employees')
            ->json('data');
        $reasons = collect($calendar['skipped_employees'])->pluck('reason')->implode(' ');
        $this->assertStringContainsString('Không xác định được ca', $reasons);
        $this->assertStringContainsString('không nhất quán', $reasons);

        $this->withToken($this->manager['token'])->postJson('/api/v1/shift-roster/rotation/preview', [
            'department_id' => $this->departmentA,
            'start_date' => '2026-08-17',
            'weeks' => 1,
            'employee_ids' => [...array_column($this->workers, 'id'), $missing['id'], $ambiguous['id']],
        ])->assertOk()
            ->assertJsonPath('data.employees', 3)
            ->assertJsonCount(2, 'data.skipped_employees');

        $this->withToken($this->manager['token'])->postJson('/api/v1/shift-roster/rotation/preview', [
            'department_id' => $this->departmentA,
            'start_date' => '2026-08-10',
            'weeks' => 1,
        ])->assertUnprocessable();
    }

    public function test_workbook_scales_to_240_rows_and_imports_aliases_and_off_atomically(): void
    {
        TenantContext::set(1, 1);
        $workbook = app(ShiftRosterWorkbook::class);
        $department = DB::table('departments')->where('id', $this->departmentA)->first();
        $shifts = DB::table('shift_types')->where('tenant_id', 1)->get()->all();
        $employees = [];
        for ($index = 1; $index <= 240; $index++) {
            $employees[] = (object) [
                'id' => $index,
                'employee_code' => 'LOAD'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'full_name' => 'Nhân viên tải '.$index,
                'department_id' => $this->departmentA,
                'status' => 'ACTIVE',
            ];
        }
        foreach ([30, 100, 240] as $size) {
            $largeFile = $workbook->create($department, '2026-08-17', array_slice($employees, 0, $size), $shifts, 1);
            $largeSheet = (new XlsxReader)->load($largeFile['path']);
            $expectedCode = 'LOAD'.str_pad((string) $size, 4, '0', STR_PAD_LEFT);
            $this->assertSame($expectedCode, $largeSheet->getActiveSheet()->getCell('B'.($size + 4))->getValue());
            $this->assertTrue($largeSheet->getActiveSheet()->getProtection()->getSheet());
            $this->assertSame(
                Worksheet::SHEETSTATE_VERYHIDDEN,
                $largeSheet->getSheetByName('_HRM_META')->getSheetState()
            );
            $this->assertSame('list', $largeSheet->getActiveSheet()->getCell('D5')->getDataValidation()->getType());
            $this->assertSame('protected', $largeSheet->getActiveSheet()->getStyle('B5')->getProtection()->getLocked());
            $this->assertSame('protected', $largeSheet->getActiveSheet()->getStyle('C5')->getProtection()->getLocked());
            $this->assertSame('unprotected', $largeSheet->getActiveSheet()->getStyle('D5')->getProtection()->getLocked());
            $this->assertSame('unprotected', $largeSheet->getActiveSheet()->getStyle('B2')->getProtection()->getLocked());
            @unlink($largeFile['path']);
        }

        $request = Request::create('/api/v1/shift-roster/template', 'GET');
        $request->attributes->set('auth_employee_id', $this->manager['id']);
        $request->attributes->set('access', ['full' => false, 'roles' => [['role_code' => 'MANAGER']]]);
        $file = app(ShiftRosterService::class)->template($request, $this->departmentA, '2026-08-17');
        $this->assignment($this->workers[0]['id'], 'CA3', '2026-08-17', '2026-08-17', 'manual');
        $spreadsheet = (new XlsxReader)->load($file['path']);
        foreach (range('D', 'J') as $column) {
            $spreadsheet->getActiveSheet()->setCellValue($column.'5', 'CA2');
            $spreadsheet->getActiveSheet()->setCellValue($column.'6', 'S3');
            $spreadsheet->getActiveSheet()->setCellValue($column.'7', 'OFF');
        }
        $spreadsheet->getActiveSheet()->setCellValue('C5', 'Tên nhập sai nhưng mã đúng');
        (new XlsxWriter($spreadsheet))->save($file['path']);

        $preview = $this->withToken($this->manager['token'])->post('/api/v1/shift-roster/import/preview', [
            'department_id' => $this->departmentA,
            'file' => new UploadedFile(
                $file['path'],
                $file['filename'],
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ])->assertOk()
            ->assertJsonPath('data.employees', 3)
            ->assertJsonCount(21, 'data.entries')
            ->assertJsonPath('data.entries.0.current_shift_code', 'CA3')
            ->assertJsonPath('data.entries.7.shift_code', 'CA3')
            ->assertJsonCount(1, 'data.manual_conflicts')
            ->assertJsonCount(1, 'data.warnings')
            ->json('data');

        $this->withToken($this->manager['token'])->postJson('/api/v1/shift-roster/import/apply', [
            'preview_token' => $preview['preview_token'],
        ])->assertOk()
            ->assertJsonPath('data.assignments_created', 20)
            ->assertJsonPath('data.manual_cells_preserved', 1);

        $this->assertSame(20, DB::table('shift_assignments')
            ->whereRaw("json_extract(meta, '$.source') = 'excel-import'")
            ->count());
        $this->assertSame(7, DB::table('shift_assignments')
            ->whereRaw("json_extract(meta, '$.source') = 'excel-import'")
            ->where('is_day_off', true)
            ->count());
    }

    public function test_stale_or_invalid_workbook_is_rejected_without_partial_rows(): void
    {
        TenantContext::set(1, 1);
        $request = Request::create('/api/v1/shift-roster/template', 'GET');
        $request->attributes->set('auth_employee_id', $this->manager['id']);
        $request->attributes->set('access', ['full' => false, 'roles' => [['role_code' => 'MANAGER']]]);
        $service = app(ShiftRosterService::class);

        $invalid = $service->template($request, $this->departmentA, '2026-08-17');
        $sheet = (new XlsxReader)->load($invalid['path']);
        foreach (range('D', 'J') as $column) {
            foreach (range(5, 7) as $row) {
                $sheet->getActiveSheet()->setCellValue($column.$row, 'CA1');
            }
        }
        $sheet->getActiveSheet()->setCellValue('D5', 'KHONG_TON_TAI');
        (new XlsxWriter($sheet))->save($invalid['path']);

        $this->withToken($this->manager['token'])->post('/api/v1/shift-roster/import/preview', [
            'department_id' => $this->departmentA,
            'file' => new UploadedFile($invalid['path'], 'invalid.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('shift_assignments')
            ->whereRaw("json_extract(meta, '$.source') = 'excel-import'")
            ->count());

        $stale = $service->template($request, $this->departmentA, '2026-08-17');
        $newWorker = $this->employee('CN9999', $this->departmentA);
        $this->assignment($newWorker['id'], 'CA1', '2026-01-01', null, 'standing');

        $this->withToken($this->manager['token'])->post('/api/v1/shift-roster/import/preview', [
            'department_id' => $this->departmentA,
            'file' => new UploadedFile($stale['path'], 'stale.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertUnprocessable()
            ->assertJsonFragment(['Danh sách nhân viên đã thay đổi. Hãy tải file mẫu mới trước khi xếp ca.']);
    }

    public function test_workbook_rejects_changed_employee_codes_deleted_rows_and_missing_days(): void
    {
        TenantContext::set(1, 1);
        $request = Request::create('/api/v1/shift-roster/template', 'GET');
        $request->attributes->set('auth_employee_id', $this->manager['id']);
        $request->attributes->set('access', ['full' => false, 'roles' => [['role_code' => 'MANAGER']]]);
        $service = app(ShiftRosterService::class);

        foreach (['duplicate-code', 'deleted-row', 'missing-day'] as $scenario) {
            $file = $service->template($request, $this->departmentA, '2026-08-17');
            $sheet = (new XlsxReader)->load($file['path']);
            foreach (range('D', 'J') as $column) {
                foreach (range(5, 7) as $row) {
                    $sheet->getActiveSheet()->setCellValue($column.$row, 'CA1');
                }
            }

            if ($scenario === 'duplicate-code') {
                $sheet->getActiveSheet()->setCellValue('B5', $sheet->getActiveSheet()->getCell('B6')->getValue());
            } elseif ($scenario === 'deleted-row') {
                $sheet->getActiveSheet()->removeRow(5, 1);
            } else {
                $sheet->getActiveSheet()->setCellValue('G6', '');
            }
            (new XlsxWriter($sheet))->save($file['path']);

            $this->withToken($this->manager['token'])->post('/api/v1/shift-roster/import/preview', [
                'department_id' => $this->departmentA,
                'file' => new UploadedFile($file['path'], $scenario.'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertUnprocessable();
        }

        $this->assertSame(0, DB::table('shift_assignments')
            ->whereRaw("json_extract(meta, '$.source') = 'excel-import'")
            ->count());
    }

    public function test_off_and_date_specific_shift_are_used_by_timesheet_and_check_in(): void
    {
        $offDate = '2026-08-07';
        $this->assignment($this->workers[0]['id'], null, $offDate, $offDate, 'manual', true);

        TenantContext::set(1, 1);
        $grid = app(TimesheetService::class)->monthlyGrid(1, 1, '2026-08', [$this->workers[0]['id']]);
        $this->assertSame('REST', $grid['rows'][0]['cells'][$offDate]['status']);

        $todayAssignment = $this->assignment($this->workers[0]['id'], 'CA3', '2026-08-10', '2026-08-10', 'manual');
        $this->withToken($this->workers[0]['token'])->postJson('/api/v1/attendances/check-in', [
            'employee_id' => $this->workers[0]['id'],
            'source' => 'web',
        ])->assertCreated()
            ->assertJsonPath('data.shift_type_id', $this->shiftIds['CA3']);

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $this->workers[0]['id'],
            'work_date' => '2026-08-10',
            'shift_type_id' => $this->shiftIds['CA3'],
        ]);
        $this->assertDatabaseHas('shift_assignments', ['id' => $todayAssignment]);
    }

    public function test_department_manager_role_sync_is_idempotent_and_reversible(): void
    {
        $candidate = $this->employee('QL0001', null);
        DB::table('departments')->where('id', $this->departmentB)->update([
            'meta' => json_encode(['manager_id' => $candidate['id']]),
        ]);

        $service = app(DepartmentManagerRoleService::class);
        $service->syncTenant(1);
        $service->syncTenant(1);

        $managerRoleId = DB::table('roles')->where('tenant_id', 1)->where('role_code', 'MANAGER')->value('id');
        $this->assertSame(1, DB::table('employee_roles')
            ->where('employee_id', $candidate['id'])
            ->where('role_id', $managerRoleId)
            ->count());
        $this->assertTrue((bool) DB::table('employee_roles')
            ->where('employee_id', $candidate['id'])
            ->where('role_id', $managerRoleId)
            ->value('is_active'));

        DB::table('departments')->where('id', $this->departmentB)->update(['meta' => json_encode([])]);
        $service->syncTenant(1);
        $this->assertFalse((bool) DB::table('employee_roles')
            ->where('employee_id', $candidate['id'])
            ->where('role_id', $managerRoleId)
            ->value('is_active'));
    }

    /** @return array{id:int,code:string,token:string} */
    private function actor(string $roleCode, array $modules, string $roleName): array
    {
        $employee = $this->employee('QA'.$roleCode, null);
        $roleId = DB::table('roles')->insertGetId([
            'role_code' => $roleCode,
            'role_name' => $roleName,
            'is_system_role' => true,
            'meta' => json_encode(['modules' => $modules]),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_roles')->insert([
            'employee_id' => $employee['id'],
            'role_id' => $roleId,
            'is_active' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employee;
    }

    /** @return array{id:int,code:string,token:string} */
    private function employee(string $code, ?int $departmentId): array
    {
        $id = DB::table('employees')->insertGetId([
            'employee_code' => $code,
            'full_name' => 'Nhân viên '.$code,
            'company_email' => Str::lower($code).'.'.Str::lower(Str::random(5)).'@example.test',
            'status' => 'ACTIVE',
            'department_id' => $departmentId,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = Str::random(64);
        DB::table('api_tokens')->insert([
            'employee_id' => $id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'code' => $code, 'token' => $token];
    }

    private function assignment(
        int $employeeId,
        ?string $shiftCode,
        string $effectiveDate,
        ?string $expiryDate,
        string $source,
        bool $isDayOff = false,
    ): int {
        return DB::table('shift_assignments')->insertGetId([
            'employee_id' => $employeeId,
            'shift_type_id' => $shiftCode ? $this->shiftIds[$shiftCode] : null,
            'is_day_off' => $isDayOff,
            'effective_date' => $effectiveDate,
            'expiry_date' => $expiryDate,
            'is_permanent' => $expiryDate === null,
            'status' => 'ACTIVE',
            'meta' => json_encode(['source' => $source]),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
