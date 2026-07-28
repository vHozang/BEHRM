<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Milestone3ChallengerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create a test employee with auth token
        $this->employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'EMP_TEST_M3',
            'full_name' => 'M3 Test Employee',
            'company_email' => 'm3.test@company.com',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'is_super_admin' => true,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Login to get token
        $response = $this->postJson('/api/v1/auth/login', [
            'company_email' => 'm3.test@company.com',
            'password' => 'password',
        ]);

        $this->token = $response->json('data.access_token');
    }

    // =========================================================================
    // ATTENDANCE PATCH ROUTE & META MAPPING
    // =========================================================================

    public function test_patch_attendance_success(): void
    {
        $attendanceId = DB::table('attendances')->insertGetId([
            'employee_id' => $this->employeeId,
            'work_date' => '2026-06-22',
            'check_in_time' => '08:00:00',
            'check_out_time' => '17:00:00',
            'status' => 'PRESENT',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/attendances/{$attendanceId}", [
                'status' => 'ON_TIME',
                'check_in_time' => '08:05:00',
                'device_id' => 'DEV_456', // extra attribute -> meta
                'notes' => 'Arrived slightly late', // extra attribute -> meta
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ON_TIME')
            ->assertJsonPath('data.check_in_time', '08:05:00');

        $this->assertDatabaseHas('attendances', [
            'id' => $attendanceId,
            'status' => 'ON_TIME',
            'check_in_time' => '08:05:00',
        ]);

        // Verify meta column was populated and merged
        $attendance = DB::table('attendances')->where('id', $attendanceId)->first();
        $this->assertNotNull($attendance->meta);
        $meta = json_decode($attendance->meta, true);
        $this->assertEquals('DEV_456', $meta['device_id']);
        $this->assertEquals('Arrived slightly late', $meta['notes']);
    }

    public function test_patch_attendance_meta_merging(): void
    {
        $attendanceId = DB::table('attendances')->insertGetId([
            'employee_id' => $this->employeeId,
            'work_date' => '2026-06-22',
            'status' => 'PRESENT',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'meta' => json_encode(['original_key' => 'keep_me', 'notes' => 'old notes']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/attendances/{$attendanceId}", [
                'notes' => 'new notes',
                'extra_param' => 'value1',
            ]);

        $response->assertOk();

        $attendance = DB::table('attendances')->where('id', $attendanceId)->first();
        $meta = json_decode($attendance->meta, true);
        $this->assertEquals('keep_me', $meta['original_key']);
        $this->assertEquals('new notes', $meta['notes']);
        $this->assertEquals('value1', $meta['extra_param']);
    }

    public function test_patch_attendance_not_found(): void
    {
        $response = $this->withToken($this->token)
            ->patchJson('/api/v1/attendances/999999', [
                'status' => 'PRESENT',
            ]);

        $response->assertNotFound();
    }

    public function test_patch_attendance_validation_error(): void
    {
        $attendanceId = DB::table('attendances')->insertGetId([
            'employee_id' => $this->employeeId,
            'work_date' => '2026-06-22',
            'status' => 'PRESENT',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/attendances/{$attendanceId}", [
                'employee_id' => 999999, // non-existent employee
            ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // SALARY COMPONENTS DYNAMIC CRUD
    // =========================================================================

    public function test_salary_components_crud_dynamic(): void
    {
        // 1. Create (POST)
        $createResponse = $this->withToken($this->token)
            ->postJson('/api/v1/salary-components', [
                'code' => 'SC_BONUS',
                'name' => 'Performance Bonus',
                'type' => 'ALLOWANCE',
                'category' => 'KPI',
                'is_taxable' => true,
                'is_active' => true,
                'formula' => 'base * 0.1', // extra field -> meta
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.code', 'SC_BONUS');

        $componentId = $createResponse->json('data.id');
        $this->assertNotNull($componentId);

        // Verify in Database
        $this->assertDatabaseHas('salary_components', [
            'code' => 'SC_BONUS',
            'name' => 'Performance Bonus',
        ]);

        $component = DB::table('salary_components')->where('id', $componentId)->first();
        $this->assertNotNull($component->meta);
        $meta = json_decode($component->meta, true);
        $this->assertEquals('base * 0.1', $meta['formula']);

        // 2. Update (PUT / PATCH through GenericResourceController update method)
        $updateResponse = $this->withToken($this->token)
            ->putJson("/api/v1/salary-components/{$componentId}", [
                'name' => 'Updated Performance Bonus',
                'formula' => 'base * 0.15', // update extra field
                'tax_rate' => 0.1, // new extra field
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.name', 'Updated Performance Bonus');

        $component = DB::table('salary_components')->where('id', $componentId)->first();
        $meta = json_decode($component->meta, true);
        $this->assertEquals('base * 0.15', $meta['formula']);
        $this->assertEquals(0.1, $meta['tax_rate']);

        // 3. List (GET)
        $listResponse = $this->withToken($this->token)
            ->getJson('/api/v1/salary-components');
        $listResponse->assertOk();

        // 4. Delete (DELETE)
        $deleteResponse = $this->withToken($this->token)
            ->deleteJson("/api/v1/salary-components/{$componentId}");
        $deleteResponse->assertOk();

        $this->assertDatabaseMissing('salary_components', [
            'id' => $componentId,
        ]);
    }

    // =========================================================================
    // SALARY DETAILS VIA PAYROLL CONTROLLER
    // =========================================================================

    public function test_salary_details_crud(): void
    {
        // Setup a period
        $periodId = DB::table('salary_periods')->insertGetId([
            'period_code' => 'T07-2026',
            'status' => 'OPEN',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Create (POST)
        $createResponse = $this->withToken($this->token)
            ->postJson('/api/v1/salary-details', [
                'period_id' => $periodId,
                'employee_id' => $this->employeeId,
                'gross_salary' => 50000000,
                'net_salary' => 45000000,
                'transfer_status' => 'PENDING',
                'bonus_reason' => 'Quarterly Top Performer', // extra field -> meta
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.transfer_status', 'PENDING');

        $detailId = $createResponse->json('data.id');
        $this->assertNotNull($detailId);

        // Verify in Database
        $this->assertDatabaseHas('salary_details', [
            'id' => $detailId,
            'period_id' => $periodId,
            'employee_id' => $this->employeeId,
            'transfer_status' => 'PENDING',
        ]);

        $detail = DB::table('salary_details')->where('id', $detailId)->first();
        $this->assertNotNull($detail->meta);
        $meta = json_decode($detail->meta, true);
        $this->assertEquals('Quarterly Top Performer', $meta['bonus_reason']);

        // 2. Update (PUT)
        $updateResponse = $this->withToken($this->token)
            ->putJson("/api/v1/salary-details/{$detailId}", [
                'gross_salary' => 55000000,
                'bonus_reason' => 'Annual Top Performer', // update extra field
                'currency' => 'VND', // new extra field
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.gross_salary', '55000000.0000');

        $detail = DB::table('salary_details')->where('id', $detailId)->first();
        $meta = json_decode($detail->meta, true);
        $this->assertEquals('Annual Top Performer', $meta['bonus_reason']);
        $this->assertEquals('VND', $meta['currency']);

        // 3. Validation errors (POST)
        $invalidResponse = $this->withToken($this->token)
            ->postJson('/api/v1/salary-details', [
                'period_id' => 999999, // invalid period
                'employee_id' => $this->employeeId,
                'gross_salary' => -100, // invalid amount
            ]);
        $invalidResponse->assertStatus(422);

        // 4. Update not found (PUT)
        $notFoundResponse = $this->withToken($this->token)
            ->putJson('/api/v1/salary-details/999999', [
                'gross_salary' => 60000000,
            ]);
        $notFoundResponse->assertNotFound();
    }

    // =========================================================================
    // SHIFT ASSIGNMENTS & TYPES DYNAMIC CRUD
    // =========================================================================

    public function test_shift_assignments_and_types_crud(): void
    {
        // 1. Create a shift type
        $shiftTypeResponse = $this->withToken($this->token)
            ->postJson('/api/v1/shift-types', [
                'shift_code' => 'HC_TEST',
                'shift_name' => 'Hanh Chinh Test',
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'break_minutes' => 60,
            ]);

        $shiftTypeResponse->assertCreated()
            ->assertJsonPath('data.shift_code', 'HC_TEST');

        $shiftTypeId = $shiftTypeResponse->json('data.id');
        $this->assertNotNull($shiftTypeId);

        // 2. Create a shift assignment using that shift type
        $assignmentResponse = $this->withToken($this->token)
            ->postJson('/api/v1/shift-assignments', [
                'employee_id' => $this->employeeId,
                'shift_type_id' => $shiftTypeId,
                'effective_date' => '2026-06-22',
                'is_permanent' => true,
                'status' => 'ACTIVE',
                'custom_rule' => 'No overtime automatic trigger', // extra field -> meta
            ]);

        $assignmentResponse->assertCreated()
            ->assertJsonPath('data.shift_type_id', $shiftTypeId);

        $assignmentId = $assignmentResponse->json('data.id');
        $this->assertNotNull($assignmentId);

        // Verify assignment in DB
        $this->assertDatabaseHas('shift_assignments', [
            'id' => $assignmentId,
            'employee_id' => $this->employeeId,
            'shift_type_id' => $shiftTypeId,
        ]);

        $assignment = DB::table('shift_assignments')->where('id', $assignmentId)->first();
        $meta = json_decode($assignment->meta, true);
        $this->assertEquals('No overtime automatic trigger', $meta['custom_rule']);
    }
}
