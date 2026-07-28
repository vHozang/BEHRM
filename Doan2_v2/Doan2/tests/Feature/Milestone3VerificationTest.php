<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Milestone3VerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $employeeId;
    private int $shiftTypeId;
    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create a test employee with auth token
        $this->employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'EMP001',
            'full_name' => 'Challenger Verifier',
            'company_email' => 'verifier@company.com',
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
            'company_email' => 'verifier@company.com',
            'password' => 'password',
        ]);

        $this->token = $response->json('data.access_token');

        // 2. Create a shift type
        $this->shiftTypeId = DB::table('shift_types')->insertGetId([
            'shift_code' => 'SHIFT_A',
            'shift_name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'status' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Create a salary period
        $this->periodId = DB::table('salary_periods')->insertGetId([
            'period_code' => 'PERIOD_2026_06',
            'period_name' => 'June 2026',
            'period_type' => 'MONTHLY',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'status' => 'OPEN',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * PATCH /attendances/{id} with standard columns updates correctly.
     */
    public function test_patch_attendance_standard_fields(): void
    {
        $attendanceId = DB::table('attendances')->insertGetId([
            'employee_id' => $this->employeeId,
            'shift_type_id' => $this->shiftTypeId,
            'work_date' => '2026-06-22',
            'check_in_time' => '08:00:00',
            'check_out_time' => null,
            'status' => 'ON_TIME',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/attendances/{$attendanceId}", [
                'status' => 'LATE',
                'check_in_time' => '08:15:00',
                'check_out_time' => '17:00:00',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'LATE')
            ->assertJsonPath('data.check_in_time', '08:15:00')
            ->assertJsonPath('data.check_out_time', '17:00:00');

        $this->assertDatabaseHas('attendances', [
            'id' => $attendanceId,
            'status' => 'LATE',
            'check_in_time' => '08:15:00',
            'check_out_time' => '17:00:00',
        ]);
    }

    /**
     * PATCH /attendances/{id} with non-standard columns merges them into JSON 'meta' column.
     */
    public function test_patch_attendance_non_standard_fields_meta(): void
    {
        $attendanceId = DB::table('attendances')->insertGetId([
            'employee_id' => $this->employeeId,
            'shift_type_id' => $this->shiftTypeId,
            'work_date' => '2026-06-22',
            'check_in_time' => '08:00:00',
            'status' => 'ON_TIME',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'meta' => json_encode(['existing_key' => 'old_val']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/api/v1/attendances/{$attendanceId}", [
                'late_minutes' => 15,
                'notes' => 'traffic jam',
            ]);

        $response->assertOk();

        $updated = DB::table('attendances')->find($attendanceId);
        $meta = json_decode($updated->meta, true);

        $this->assertEquals('old_val', $meta['existing_key'] ?? null);
        $this->assertEquals(15, $meta['late_minutes'] ?? null);
        $this->assertEquals('traffic jam', $meta['notes'] ?? null);
        $this->assertEquals('ON_TIME', $updated->status);
    }

    /**
     * PATCH /attendances/{id} validation check.
     */
    public function test_patch_attendance_invalid_data(): void
    {
        $attendanceId = DB::table('attendances')->insertGetId([
            'employee_id' => $this->employeeId,
            'shift_type_id' => $this->shiftTypeId,
            'work_date' => '2026-06-22',
            'status' => 'ON_TIME',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($this->token)
            ->patchJson("/api/v1/attendances/{$attendanceId}", [
                'employee_id' => 999999, // non-existent employee
            ])
            ->assertStatus(422);

        $this->withToken($this->token)
            ->patchJson("/api/v1/attendances/{$attendanceId}", [
                'shift_type_id' => 999999, // non-existent shift type
            ])
            ->assertStatus(422);
    }

    /**
     * POST /salary-details creates salary detail entry with meta support.
     */
    public function test_store_salary_detail(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/salary-details', [
                'period_id' => $this->periodId,
                'employee_id' => $this->employeeId,
                'gross_salary' => 5000,
                'net_salary' => 4500,
                'transfer_status' => 'PENDING',
                'custom_field' => 'custom_val',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('salary_details', [
            'period_id' => $this->periodId,
            'employee_id' => $this->employeeId,
            'transfer_status' => 'PENDING',
        ]);

        $detail = DB::table('salary_details')->where('employee_id', $this->employeeId)->first();
        $this->assertNotNull($detail->meta);
        $meta = json_decode($detail->meta, true);
        $this->assertEquals('custom_val', $meta['custom_field'] ?? null);
    }

    /**
     * POST /salary-details validation.
     */
    public function test_store_salary_detail_invalid(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/salary-details', [
                'period_id' => $this->periodId,
                'employee_id' => $this->employeeId,
                'gross_salary' => -100, // invalid negative salary
            ])
            ->assertStatus(422);
    }

    /**
     * PUT /salary-details/{id} updates entry with meta support.
     */
    public function test_update_salary_detail(): void
    {
        $detailId = DB::table('salary_details')->insertGetId([
            'period_id' => $this->periodId,
            'employee_id' => $this->employeeId,
            'gross_salary' => 5000,
            'net_salary' => 4500,
            'transfer_status' => 'PENDING',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'meta' => json_encode(['old_key' => 'old_val']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withToken($this->token)
            ->putJson("/api/v1/salary-details/{$detailId}", [
                'gross_salary' => 5500,
                'new_field' => 'new_val',
            ]);

        $response->assertOk();

        $updated = DB::table('salary_details')->find($detailId);
        $this->assertEquals(5500, (float)$updated->gross_salary);

        $meta = json_decode($updated->meta, true);
        $this->assertEquals('old_val', $meta['old_key'] ?? null);
        $this->assertEquals('new_val', $meta['new_field'] ?? null);
    }

    /**
     * Verify Salary Components CRUD via GenericResourceController.
     */
    public function test_salary_components_crud_generic(): void
    {
        // 1. Read list
        $this->withToken($this->token)
            ->getJson('/api/v1/salary-components')
            ->assertOk();

        // 2. Create component
        $createResponse = $this->withToken($this->token)
            ->postJson('/api/v1/salary-components', [
                'code' => 'COMP_BASE',
                'name' => 'Base Salary Component',
                'type' => 'EARNING',
                'category' => 'BASE',
                'is_taxable' => true,
                'is_active' => true,
            ]);

        $createResponse->assertCreated();
        $componentId = $createResponse->json('data.id');

        $this->assertDatabaseHas('salary_components', [
            'id' => $componentId,
            'code' => 'COMP_BASE',
        ]);

        // 3. Update component
        $this->withToken($this->token)
            ->putJson("/api/v1/salary-components/{$componentId}", [
                'name' => 'Updated Base Salary Component',
            ])
            ->assertOk();

        $this->assertDatabaseHas('salary_components', [
            'id' => $componentId,
            'name' => 'Updated Base Salary Component',
        ]);

        // 4. Delete component
        $this->withToken($this->token)
            ->deleteJson("/api/v1/salary-components/{$componentId}")
            ->assertOk();

        $this->assertDatabaseMissing('salary_components', [
            'id' => $componentId,
        ]);
    }
}
