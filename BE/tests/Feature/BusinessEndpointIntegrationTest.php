<?php

namespace Tests\Feature;

use App\Services\CatalogBackfillService;
use App\Services\PayrollRunService;
use Database\Seeders\BusinessCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessEndpointIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryStoragePath = sys_get_temp_dir().'/hrm-business-tests-'.Str::uuid();
        File::ensureDirectoryExists($this->temporaryStoragePath);
        app()->useStoragePath($this->temporaryStoragePath);

        $this->ensureTenant(1, 1, 'Primary entity');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryStoragePath);
        parent::tearDown();
    }

    public function test_requests_use_canonical_type_and_follow_tenant_scoped_multi_step_flow(): void
    {
        $manager = $this->actor('request-manager', 'MANAGER', ['time']);
        $hr = $this->actor('request-hr', 'HR', ['hr', 'time']);
        $employee = $this->actor('request-owner');
        $this->seedCapabilities();

        $departmentId = DB::table('departments')->insertGetId([
            'department_code' => 'REQ-DEPT',
            'department_name' => 'Request department',
            'meta' => json_encode(['manager_id' => $manager['id']]),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('id', $employee['id'])->update([
            'department_id' => $departmentId,
            'manager_id' => $manager['id'],
        ]);
        DB::table('employee_roles')->where('employee_id', $manager['id'])->update(['department_id' => $departmentId]);

        $typeId = DB::table('request_types')->insertGetId([
            'request_type_code' => 'EQUIPMENT',
            'request_type_name' => 'Yêu cầu thiết bị',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $flowId = DB::table('approval_flows')->insertGetId([
            'request_type_id' => $typeId,
            'flow_name' => 'Manager then HR',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('request_types')->where('id', $typeId)->update(['approval_flow_id' => $flowId]);
        $managerStep = DB::table('approval_steps')->insertGetId([
            'approval_flow_id' => $flowId,
            'step_order' => 1,
            'approver_role_id' => $manager['role_id'],
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $hrStep = DB::table('approval_steps')->insertGetId([
            'approval_flow_id' => $flowId,
            'step_order' => 2,
            'approver_role_id' => $hr['role_id'],
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($employee['token'])
            ->getJson('/api/v1/request-types?status=ACTIVE')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $typeId);

        $requestId = $this->withToken($employee['token'])->postJson('/api/v1/requests', [
            'request_type_id' => $typeId,
            'title' => 'Cấp laptop',
            'description' => 'Dùng cho dự án mới',
        ])->assertCreated()
            ->assertJsonPath('data.request_type_id', $typeId)
            ->assertJsonPath('data.current_step_id', $managerStep)
            ->json('data.id');

        $this->withToken($hr['token'])->postJson("/api/v1/requests/{$requestId}/approve")
            ->assertForbidden();
        $this->withToken($manager['token'])->postJson("/api/v1/requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS')
            ->assertJsonPath('data.current_step_id', $hrStep);
        $this->withToken($manager['token'])->postJson("/api/v1/requests/{$requestId}/approve")
            ->assertForbidden();
        $this->withToken($hr['token'])->postJson("/api/v1/requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $this->ensureTenant(2, 3, 'Foreign entity');
        $foreignTypeId = DB::table('request_types')->insertGetId([
            'request_type_code' => 'FOREIGN',
            'request_type_name' => 'Foreign type',
            'status' => 'ACTIVE',
            'tenant_id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withToken($employee['token'])->postJson('/api/v1/requests', [
            'request_type_id' => $foreignTypeId,
            'title' => 'Cross tenant',
        ])->assertUnprocessable();
    }

    public function test_salary_period_suggestion_and_uniqueness_are_scoped_per_legal_entity(): void
    {
        $this->ensureTenant(1, 2, 'Second entity');
        $admin = $this->actor('period-admin', null, [], 1, 1, true);
        $accountant = $this->actor('period-accountant', 'ACCOUNTANT', ['payroll']);
        $this->seedCapabilities();

        $suggestion = $this->withToken($accountant['token'])
            ->getJson('/api/v1/salary-periods/suggestion?month=2026-08&legal_entity_id=1')
            ->assertOk()
            ->assertJsonPath('data.period_code', 'P-2026-08')
            ->assertJsonPath('data.start_date', '2026-08-01')
            ->assertJsonPath('data.end_date', '2026-08-31')
            ->json('data');

        $firstId = $this->withToken($accountant['token'])->postJson('/api/v1/salary-periods', $suggestion)
            ->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')
            ->json('data.id');
        $this->withToken($accountant['token'])->postJson('/api/v1/salary-periods', $suggestion)
            ->assertUnprocessable();

        $secondPayload = [...$suggestion, 'legal_entity_id' => 2, 'legal_entity_name' => 'Second entity'];
        $this->withToken($admin['token'])->postJson('/api/v1/salary-periods', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.period_code', 'P-2026-08')
            ->assertJsonPath('data.legal_entity_id', 2);

        $this->withToken($accountant['token'])->patchJson("/api/v1/salary-periods/{$firstId}", [
            'status' => 'CLOSED',
        ])->assertUnprocessable();
        $this->assertSame(2, DB::table('salary_periods')->where('period_code', 'P-2026-08')->count());
    }

    public function test_payroll_adjustment_enforces_maker_checker_period_lock_and_idempotent_engine_use(): void
    {
        config(['hrm.payroll.prorate_by_attendance' => false]);
        $maker = $this->actor('adjustment-maker', 'ACCOUNTANT', ['payroll']);
        $checker = $this->actor('adjustment-checker', 'ACCOUNTANT', ['payroll']);
        $employee = $this->actor('adjustment-target');
        DB::table('employees')->where('id', $employee['id'])->update([
            'base_salary' => 10000000,
            'hire_date' => '2025-01-01',
        ]);
        $this->seedCapabilities();

        $periodId = DB::table('salary_periods')->insertGetId([
            'period_code' => 'P-2026-08',
            'period_name' => 'Lương tháng 08/2026',
            'period_type' => 'MONTHLY',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'OPEN',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'employee_id' => $employee['id'],
            'paid_period_id' => $periodId,
            'adjustment_type' => 'EARNING',
            'amount' => 500000,
            'note' => 'Thưởng dự án',
        ];
        $adjustmentId = $this->withToken($maker['token'])->postJson('/api/v1/payroll-adjustments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->json('data.id');
        $this->withToken($maker['token'])->postJson("/api/v1/payroll-adjustments/{$adjustmentId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'SUBMITTED');
        $this->withToken($maker['token'])->postJson("/api/v1/payroll-adjustments/{$adjustmentId}/approve")
            ->assertUnprocessable();
        $this->withToken($checker['token'])->postJson("/api/v1/payroll-adjustments/{$adjustmentId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $firstRun = app(PayrollRunService::class)->run($periodId);
        $this->assertSame(1, $firstRun['employees_processed']);
        $this->assertDatabaseHas('payroll_adjustments', ['id' => $adjustmentId, 'status' => 'APPLIED']);
        $detail = DB::table('salary_details')->where('period_id', $periodId)->where('employee_id', $employee['id'])->first();
        $this->assertNotNull($detail);
        $this->assertSame(10500000.0, (float) $detail->gross_salary);

        app(PayrollRunService::class)->run($periodId);
        $this->assertSame(1, DB::table('salary_details')->where('period_id', $periodId)->where('employee_id', $employee['id'])->count());
        $this->assertSame(10500000.0, (float) DB::table('salary_details')->where('id', $detail->id)->value('gross_salary'));

        $lockedAdjustment = $this->withToken($maker['token'])->postJson('/api/v1/payroll-adjustments', $payload)
            ->assertCreated()->json('data.id');
        $this->withToken($maker['token'])->postJson("/api/v1/payroll-adjustments/{$lockedAdjustment}/submit")->assertOk();
        DB::table('salary_periods')->where('id', $periodId)->update(['status' => 'LOCKED']);
        $this->withToken($checker['token'])->postJson("/api/v1/payroll-adjustments/{$lockedAdjustment}/approve")
            ->assertConflict();
    }

    public function test_catalog_backfill_supports_dry_run_exact_imported_and_unclassified_values(): void
    {
        $knownCategory = DB::table('asset_categories')->insertGetId([
            'category_code' => 'LAPTOP',
            'category_name' => 'Máy tính xách tay',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assetIds = [];
        foreach ([
            ['ASSET-1', ['category' => '  laptop  ']],
            ['ASSET-2', ['category' => ' Máy   in ']],
            ['ASSET-3', []],
        ] as [$code, $meta]) {
            $assetIds[] = DB::table('assets')->insertGetId([
                'asset_code' => $code,
                'asset_name' => $code,
                'status' => 'ACTIVE',
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'BANK-LEGACY',
            'full_name' => 'Legacy Bank Employee',
            'company_email' => 'legacy-bank@example.test',
            'status' => 'ACTIVE',
            'profile' => json_encode(['bank_name' => 'MB Bank'], JSON_UNESCAPED_UNICODE),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('hrm:backfill-catalogs', [
            '--tenant' => 1,
            '--dry-run' => true,
            '--resource' => ['assets', 'employees'],
        ])
            ->assertSuccessful();
        $this->assertNull(DB::table('assets')->where('id', $assetIds[0])->value('category_id'));

        $planId = $this->latestCatalogBackfillPlanId();
        $this->artisan('hrm:backfill-catalogs', [
            '--apply' => $planId,
            '--max-runtime' => 0,
        ])->assertSuccessful();
        $this->assertSame($knownCategory, (int) DB::table('assets')->where('id', $assetIds[0])->value('category_id'));

        $importedId = (int) DB::table('assets')->where('id', $assetIds[1])->value('category_id');
        $imported = DB::table('asset_categories')->where('id', $importedId)->first();
        $this->assertSame('Máy   in', $imported->category_name);
        $this->assertTrue((bool) data_get(json_decode($imported->meta, true), 'imported_from_legacy'));

        $unclassifiedId = (int) DB::table('assets')->where('id', $assetIds[2])->value('category_id');
        $this->assertSame('UNCLASSIFIED', DB::table('asset_categories')->where('id', $unclassifiedId)->value('category_code'));
        $this->assertSame('laptop', trim((string) data_get(
            json_decode(DB::table('assets')->where('id', $assetIds[0])->value('meta'), true),
            'legacy_category',
        )));
        $employeeProfile = json_decode(DB::table('employees')->where('id', $employeeId)->value('profile'), true);
        $bank = DB::table('banks')->where('id', $employeeProfile['bank_id'])->first();
        $this->assertSame('MB Bank', $bank->bank_name);
        $this->assertTrue((bool) $bank->status);
    }

    public function test_catalog_backfill_stops_on_ambiguous_name_and_writes_report_without_changes(): void
    {
        foreach (['A', 'B'] as $code) {
            DB::table('asset_categories')->insert([
                'category_code' => $code,
                'category_name' => 'Thiết bị dùng chung',
                'status' => 'ACTIVE',
                'tenant_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $assetId = DB::table('assets')->insertGetId([
            'asset_code' => 'AMB-1',
            'asset_name' => 'Ambiguous asset',
            'status' => 'ACTIVE',
            'meta' => json_encode(['category' => ' thiết bị   dùng chung '], JSON_UNESCAPED_UNICODE),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('hrm:backfill-catalogs', [
            '--plan' => true,
            '--tenant' => 1,
            '--resource' => ['assets'],
        ])->assertExitCode(1);

        $this->assertNull(DB::table('assets')->where('id', $assetId)->value('category_id'));
        $reports = File::glob(storage_path('app/private/catalog-backfill/plans/*/ambiguities.csv'));
        $this->assertCount(1, $reports);
        $this->assertStringContainsString('matching_ids', (string) File::get($reports[0]));
    }

    public function test_catalog_backfill_requires_a_plan_and_rejects_tampering_or_source_drift(): void
    {
        $assetId = DB::table('assets')->insertGetId([
            'asset_code' => 'PLAN-SAFE-1',
            'asset_name' => 'Plan safety asset',
            'status' => 'ACTIVE',
            'meta' => json_encode(['category' => 'Thiết bị kế hoạch'], JSON_UNESCAPED_UNICODE),
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('hrm:backfill-catalogs')->assertExitCode(2);
        $this->artisan('hrm:backfill-catalogs', [
            '--plan' => true,
            '--tenant' => 1,
            '--resource' => ['assets'],
        ])->assertSuccessful();
        $planId = $this->latestCatalogBackfillPlanId();
        $planDirectory = storage_path('app/private/catalog-backfill/plans/'.$planId);

        DB::table('assets')->where('id', $assetId)->update([
            'meta' => json_encode(['category' => 'Dữ liệu đã đổi'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->artisan('hrm:backfill-catalogs', ['--apply' => $planId])->assertExitCode(1);
        $this->assertSame('STALE', json_decode((string) File::get($planDirectory.'/checkpoint.json'), true)['status']);
        $this->assertNull(DB::table('assets')->where('id', $assetId)->value('category_id'));

        $this->artisan('hrm:backfill-catalogs', [
            '--plan' => true,
            '--tenant' => 1,
            '--resource' => ['assets'],
        ])->assertSuccessful();
        $tamperedPlanId = $this->latestCatalogBackfillPlanId([$planId]);
        $manifestPath = storage_path('app/private/catalog-backfill/plans/'.$tamperedPlanId.'/manifest.json');
        File::append($manifestPath, "\n");
        $this->artisan('hrm:backfill-catalogs', ['--apply' => $tamperedPlanId])->assertExitCode(1);
    }

    public function test_catalog_backfill_pauses_on_a_chunk_boundary_and_resumes_idempotently(): void
    {
        $now = now();
        foreach (array_chunk(range(1, 1001), 250) as $numbers) {
            DB::table('assets')->insert(array_map(fn (int $number): array => [
                'asset_code' => 'CHUNK-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                'asset_name' => 'Chunk asset '.$number,
                'status' => 'ACTIVE',
                'meta' => json_encode(['category' => 'Thiết bị theo lô'], JSON_UNESCAPED_UNICODE),
                'tenant_id' => 1,
                'legal_entity_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], $numbers));
        }

        $plan = app(CatalogBackfillService::class)->createPlan(1, ['assets']);
        $pausingService = new class extends CatalogBackfillService
        {
            protected function runtimeExceeded(float $startedAt, int $maxRuntime): bool
            {
                return true;
            }
        };
        $paused = $pausingService->applyPlan($plan['plan_id'], 500, 1, false);
        $this->assertSame('PAUSED', $paused['status']);
        $this->assertSame(500, $paused['processed']);
        $this->assertSame(500, DB::table('assets')->whereNotNull('category_id')->count());

        $completed = app(CatalogBackfillService::class)->applyPlan($plan['plan_id'], 500, 0, true);
        $this->assertSame('COMPLETE', $completed['status']);
        $this->assertSame(1001, DB::table('assets')->whereNotNull('category_id')->count());

        $rerun = app(CatalogBackfillService::class)->applyPlan($plan['plan_id'], 500, 0, false);
        $this->assertSame('COMPLETE', $rerun['status']);
        $this->assertSame(1, DB::table('asset_categories')->where('category_name', 'Thiết bị theo lô')->count());
    }

    public function test_leave_detail_edit_and_holiday_seed_are_owner_and_capability_scoped(): void
    {
        $owner = $this->actor('leave-owner');
        $other = $this->actor('leave-other');
        $hr = $this->actor('leave-hr', 'HR', ['hr', 'time']);
        $manager = $this->actor('leave-manager', 'MANAGER', ['time']);
        $this->seedCapabilities();
        $leaveTypeId = DB::table('leave_types')->insertGetId([
            'leave_type_code' => 'SICK',
            'leave_type_name' => 'Nghỉ ốm',
            'category' => 'PAID',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $start = Carbon::now()->addWeeks(2)->next(Carbon::MONDAY)->toDateString();
        $nextDay = Carbon::parse($start)->addDay()->toDateString();
        $leaveId = $this->withToken($owner['token'])->postJson('/api/v1/leave-requests', [
            'employee_id' => $owner['id'],
            'leave_type_id' => $leaveTypeId,
            'start_date' => $start,
            'end_date' => $start,
            'reason' => 'Khám bệnh',
        ])->assertCreated()->json('data.id');

        $this->withToken($owner['token'])->getJson("/api/v1/leave-requests/{$leaveId}")->assertOk();
        $this->withToken($other['token'])->getJson("/api/v1/leave-requests/{$leaveId}")->assertNotFound();
        $this->withToken($owner['token'])->patchJson("/api/v1/leave-requests/{$leaveId}", [
            'start_date' => $nextDay,
            'end_date' => $nextDay,
            'reason' => 'Dời lịch khám',
        ])->assertOk()
            ->assertJsonPath('data.start_date', $nextDay)
            ->assertJsonPath('data.decoded_meta.reason', 'Dời lịch khám');
        $this->withToken($other['token'])->patchJson("/api/v1/leave-requests/{$leaveId}", [
            'reason' => 'Unauthorized',
        ])->assertForbidden();

        $this->withToken($owner['token'])->getJson('/api/v1/holidays/statutory-preview?year=2027')
            ->assertOk()
            ->assertJsonPath('data.year', 2027);
        $this->assertSame(0, DB::table('holidays')->count());
        $this->withToken($manager['token'])->postJson('/api/v1/holidays/seed-vn', ['year' => 2027])
            ->assertForbidden();
        $firstSeed = $this->withToken($hr['token'])->postJson('/api/v1/holidays/seed-vn', ['year' => 2027])
            ->assertOk()->json('data');
        $secondSeed = $this->withToken($hr['token'])->postJson('/api/v1/holidays/seed-vn', ['year' => 2027])
            ->assertOk()->json('data');
        $this->assertNotEmpty($firstSeed['created']);
        $this->assertSame([], $secondSeed['created']);
        $this->assertNotEmpty($secondSeed['skipped']);
    }

    public function test_private_employee_files_are_not_public_and_downloads_are_scope_checked(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $owner = $this->actor('file-owner');
        $other = $this->actor('file-other');
        $hr = $this->actor('file-hr', 'HR', ['hr']);
        $this->ensureTenant(1, 2, 'Other entity');
        $otherEntityHr = $this->actor('file-hr-other', 'HR', ['hr'], 1, 2);
        $this->seedCapabilities();

        $documentTypeId = DB::table('document_types')->insertGetId([
            'document_type_code' => 'CCCD',
            'document_type_name' => 'Căn cước công dân',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $documentId = DB::table('identity_documents')->insertGetId([
            'employee_id' => $owner['id'],
            'document_type_id' => $documentTypeId,
            'document_number' => 'PRIVATE-001',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($hr['token'])->post(
            "/api/v1/employee-record-files/identity-documents/{$documentId}/front",
            ['file' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf')],
        )->assertOk();
        $path = DB::table('identity_documents')->where('id', $documentId)->value('front_image_url');
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        $this->withToken($owner['token'])
            ->get("/api/v1/employee-record-files/identity-documents/{$documentId}/front")
            ->assertOk();
        $this->withToken($other['token'])
            ->get("/api/v1/employee-record-files/identity-documents/{$documentId}/front")
            ->assertForbidden();
        $this->withToken($otherEntityHr['token'])
            ->get("/api/v1/employee-record-files/identity-documents/{$documentId}/front")
            ->assertForbidden();
    }

    public function test_report_templates_accept_only_allowlisted_definitions_and_disable_raw_sql(): void
    {
        $accountant = $this->actor('report-accountant', 'ACCOUNTANT', ['payroll']);
        $hr = $this->actor('report-hr', 'HR', ['hr']);
        $this->seedCapabilities();
        $payload = [
            'template_code' => 'HC-DEPT',
            'template_name' => 'Headcount theo phòng',
            'report_type' => 'headcount',
            'columns' => ['department_name', 'headcount'],
            'filters' => [],
            'chart' => ['type' => 'BAR', 'x' => 'department_name', 'y' => 'headcount'],
            'sql_query' => 'DROP TABLE employees',
        ];
        $templateId = $this->withToken($accountant['token'])->postJson('/api/v1/reports/templates', $payload)
            ->assertCreated()->json('data.id');
        $this->assertNull(DB::table('report_templates')->where('id', $templateId)->value('sql_query'));

        $this->withToken($accountant['token'])->postJson('/api/v1/reports/templates', [
            ...$payload,
            'template_code' => 'INVALID-COLUMN',
            'columns' => ['base_salary'],
        ])->assertUnprocessable();
        $this->withToken($hr['token'])->getJson('/api/v1/reports/templates')->assertForbidden();

        $legacyId = DB::table('report_templates')->insertGetId([
            'template_code' => 'LEGACY-SQL',
            'template_name' => 'Legacy SQL',
            'report_type' => 'headcount',
            'sql_query' => 'SELECT * FROM employees',
            'status' => 'LEGACY_DISABLED',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withToken($accountant['token'])->getJson("/api/v1/reports/templates/{$legacyId}")
            ->assertOk()
            ->assertJsonPath('data.legacy_disabled', true);
        $this->withToken($accountant['token'])->putJson("/api/v1/reports/templates/{$legacyId}", [
            ...$payload,
            'template_code' => 'LEGACY-SQL',
            'status' => 'ACTIVE',
        ])->assertOk();
        $this->assertNull(DB::table('report_templates')->where('id', $legacyId)->value('sql_query'));
        $this->assertSame('ACTIVE', DB::table('report_templates')->where('id', $legacyId)->value('status'));
    }

    public function test_offboarding_through_employee_update_revokes_access_and_refresh_tokens(): void
    {
        $hr = $this->actor('offboard-hr', 'HR', ['hr']);
        $employee = $this->actor('offboard-target');
        $this->seedCapabilities();
        $family = Str::uuid()->toString();
        DB::table('api_tokens')->where('employee_id', $employee['id'])->update(['family_id' => $family]);
        DB::table('api_refresh_tokens')->insert([
            'tenant_id' => 1,
            'employee_id' => $employee['id'],
            'family_id' => $family,
            'token_hash' => hash('sha256', Str::random(96)),
            'expires_at' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($hr['token'])->patchJson('/api/v1/employees/'.$employee['id'], [
            'status' => 'TERMINATED',
        ])->assertOk()
            ->assertJsonPath('data.status', 'TERMINATED');

        $this->assertSame(0, DB::table('api_tokens')->where('employee_id', $employee['id'])->count());
        $this->assertSame(0, DB::table('api_refresh_tokens')->where('employee_id', $employee['id'])->whereNull('revoked_at')->count());
        $this->withToken($employee['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_approved_termination_decision_revokes_every_employee_session(): void
    {
        $hr = $this->actor('decision-hr', 'HR', ['hr']);
        $admin = $this->actor('decision-admin', null, [], 1, 1, true);
        $employee = $this->actor('decision-target');
        $this->seedCapabilities();
        $family = Str::uuid()->toString();
        DB::table('api_tokens')->where('employee_id', $employee['id'])->update(['family_id' => $family]);
        DB::table('api_refresh_tokens')->insert([
            'tenant_id' => 1,
            'employee_id' => $employee['id'],
            'family_id' => $family,
            'token_hash' => hash('sha256', Str::random(96)),
            'expires_at' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $decisionId = $this->withToken($hr['token'])->postJson('/api/v1/personnel-decisions', [
            'employee_id' => $employee['id'],
            'change_type' => 'TERMINATION',
            'effective_date' => now()->toDateString(),
            'reason' => 'Kết thúc quan hệ lao động',
        ])->assertOk()->json('data.id');
        $this->withToken($admin['token'])->postJson("/api/v1/personnel-decisions/{$decisionId}/approve")
            ->assertOk();

        $this->assertDatabaseHas('employees', ['id' => $employee['id'], 'status' => 'TERMINATED']);
        $this->assertSame(0, DB::table('api_tokens')->where('employee_id', $employee['id'])->count());
        $this->assertSame(0, DB::table('api_refresh_tokens')->where('employee_id', $employee['id'])->whereNull('revoked_at')->count());
    }

    public function test_generic_crud_fallback_is_fail_closed_for_workflows_and_engine_data(): void
    {
        $admin = $this->actor('generic-policy-admin', null, [], 1, 1, true);
        $employee = $this->actor('generic-policy-employee');
        $this->seedCapabilities();

        // These resources have dedicated workflow controllers. Unsupported
        // verbs must not fall through to the generic CRUD controller.
        $this->withToken($admin['token'])->putJson('/api/v1/insurance-claims/999', [
            'status' => 'PAID',
        ])->assertNotFound();
        $this->withToken($admin['token'])->postJson('/api/v1/service-ticket-updates', [
            'comment' => 'Bypass attempt',
        ])->assertNotFound();
        $this->withToken($admin['token'])->getJson('/api/v1/report-histories')
            ->assertNotFound();

        // Engine output is readable only through the intended capability and
        // can never be mutated through the generic endpoint.
        $this->withToken($admin['token'])->getJson('/api/v1/salary-breakdowns')
            ->assertOk();
        $this->withToken($admin['token'])->postJson('/api/v1/salary-breakdowns', [
            'amount' => 999999,
        ])->assertStatus(405);

        // Employment history is append-only: HR may add a manual event, while
        // generic update/delete paths remain closed.
        $historyId = $this->withToken($admin['token'])->postJson('/api/v1/employment-histories', [
            'employee_id' => $employee['id'],
            'start_date' => '2026-08-01',
            'notes' => 'Manual audit event',
        ])->assertCreated()->json('data.id');
        $this->withToken($admin['token'])->patchJson("/api/v1/employment-histories/{$historyId}", [
            'notes' => 'Mutated event',
        ])->assertStatus(405);
        $this->withToken($admin['token'])->deleteJson("/api/v1/employment-histories/{$historyId}")
            ->assertStatus(405);
        $this->assertDatabaseHas('employment_histories', [
            'id' => $historyId,
            'notes' => 'Manual audit event',
        ]);

        // Ledger data is not a tenant-wide self-service endpoint.
        $this->withToken($employee['token'])->getJson('/api/v1/leave-transactions')
            ->assertForbidden();
    }

    private function seedCapabilities(): void
    {
        $this->seed(BusinessCapabilitySeeder::class);
    }

    /** @param array<int, string> $exclude */
    private function latestCatalogBackfillPlanId(array $exclude = []): string
    {
        $directories = array_values(array_filter(
            File::directories(storage_path('app/private/catalog-backfill/plans')),
            fn (string $path): bool => ! in_array(basename($path), $exclude, true),
        ));
        $this->assertNotEmpty($directories, 'Expected a catalog backfill plan directory.');

        return basename($directories[array_key_last($directories)]);
    }

    private function ensureTenant(int $tenantId, int $entityId, string $entityName): void
    {
        DB::table('tenants')->updateOrInsert(['id' => $tenantId], [
            'name' => "Tenant {$tenantId}",
            'code' => "TENANT-{$tenantId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => $entityId], [
            'tenant_id' => $tenantId,
            'name' => $entityName,
            'code' => "ENTITY-{$entityId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{id:int,token:string,role_id:?int} */
    private function actor(
        string $name,
        ?string $roleCode = null,
        array $modules = [],
        int $tenantId = 1,
        int $legalEntityId = 1,
        bool $superAdmin = false,
    ): array {
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'BE'.Str::upper(substr(hash('sha256', $name.Str::random()), 0, 8)),
            'full_name' => Str::headline($name),
            'company_email' => $name.'.'.Str::lower(Str::random(8)).'@example.test',
            'status' => 'ACTIVE',
            'is_super_admin' => $superAdmin,
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = null;
        if ($roleCode !== null) {
            $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('role_code', $roleCode)->value('id');
            if (! $roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'role_code' => $roleCode,
                    'role_name' => Str::headline(strtolower($roleCode)),
                    'is_system_role' => true,
                    'meta' => json_encode(['modules' => $modules]),
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
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

        return ['id' => $employeeId, 'token' => $token, 'role_id' => $roleId ? (int) $roleId : null];
    }
}
