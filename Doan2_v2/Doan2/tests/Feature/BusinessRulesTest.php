<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test employee with auth token
        $this->employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'TEST0001',
            'full_name' => 'Test Employee',
            'company_email' => 'test@company.com',
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
            'company_email' => 'test@company.com',
            'password' => 'password',
        ]);

        $this->token = $response->json('data.access_token');
    }

    // =========================================================================
    // DELETE GUARD TESTS — EMPLOYEES
    // =========================================================================

    public function test_cannot_delete_employee_with_active_contract(): void
    {
        if (! Schema::hasTable('contracts')) {
            $this->markTestSkipped('contracts table does not exist');
        }

        DB::table('contracts')->insert([
            'employee_id' => $this->employeeId,
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/employees/{$this->employeeId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('status', 409);
        $this->assertNotEmpty($response->json('data.violations'));
        $this->assertStringContainsString('hợp đồng', $response->json('data.violations.0'));
    }

    public function test_cannot_delete_employee_with_unpaid_salary(): void
    {
        if (! Schema::hasTable('salary_details') || ! Schema::hasTable('salary_periods')) {
            $this->markTestSkipped('salary tables do not exist');
        }

        $periodId = DB::table('salary_periods')->insertGetId([
            'period_code' => 'T06-2026',
            'status' => 'OPEN',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('salary_details')->insert([
            'period_id' => $periodId,
            'employee_id' => $this->employeeId,
            'transfer_status' => 'PENDING',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/employees/{$this->employeeId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('lương', $response->json('data.violations.0'));
    }

    public function test_cannot_delete_employee_with_active_role(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('employee_roles')) {
            $this->markTestSkipped('role tables do not exist');
        }

        $roleId = DB::table('roles')->insertGetId([
            'role_code' => 'MANAGER',
            'role_name' => 'Manager',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_roles')->insert([
            'employee_id' => $this->employeeId,
            'role_id' => $roleId,
            'is_active' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/employees/{$this->employeeId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('vai trò', $response->json('data.violations.0'));
    }

    public function test_cannot_delete_employee_with_unreturned_asset(): void
    {
        if (! Schema::hasTable('asset_assignments')) {
            $this->markTestSkipped('asset_assignments table does not exist');
        }

        DB::table('asset_assignments')->insert([
            'asset_id' => 1,
            'employee_id' => $this->employeeId,
            'status' => 'ASSIGNED',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/employees/{$this->employeeId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('tài sản', $response->json('data.violations.0'));
    }

    public function test_employee_history_is_never_hard_deleted(): void
    {
        // Create a fresh employee with no dependencies
        $freeEmployeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'FREE0001',
            'full_name' => 'Free Employee',
            'company_email' => 'free@company.com',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/employees/{$freeEmployeeId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
        $this->assertNotNull(DB::table('employees')->where('id', $freeEmployeeId)->first());
    }

    public function test_cannot_delete_employee_with_multiple_violations(): void
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasTable('employee_roles')) {
            $this->markTestSkipped('Required tables do not exist');
        }

        // Add active contract
        DB::table('contracts')->insert([
            'employee_id' => $this->employeeId,
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add active role
        $roleId = DB::table('roles')->insertGetId([
            'role_code' => 'MGR_MULTI',
            'role_name' => 'Manager',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_roles')->insert([
            'employee_id' => $this->employeeId,
            'role_id' => $roleId,
            'is_active' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/employees/{$this->employeeId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
        // Should return multiple violations
        $violations = $response->json('data.violations');
        $this->assertGreaterThanOrEqual(2, count($violations));
    }

    // =========================================================================
    // DELETE GUARD TESTS — DEPARTMENTS
    // =========================================================================

    public function test_cannot_delete_department_with_employees(): void
    {
        if (! Schema::hasTable('departments')) {
            $this->markTestSkipped('departments table does not exist');
        }

        $deptId = DB::table('departments')->insertGetId([
            'department_code' => 'DEPT_TEST',
            'department_name' => 'Test Department',
            'status' => true,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assign employee to department
        DB::table('employees')->where('id', $this->employeeId)->update([
            'department_id' => $deptId,
        ]);

        $response = $this->deleteJson("/api/v1/departments/{$deptId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('nhân viên', $response->json('data.violations.0'));
    }

    // =========================================================================
    // DELETE GUARD TESTS — ROLES
    // =========================================================================

    public function test_cannot_delete_system_role(): void
    {
        if (! Schema::hasTable('roles')) {
            $this->markTestSkipped('roles table does not exist');
        }

        $roleId = DB::table('roles')->insertGetId([
            'role_code' => 'SYS_TEST',
            'role_name' => 'System Test Role',
            'is_system_role' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/roles/{$roleId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('system role', $response->json('data.violations.0'));
    }

    public function test_can_delete_unused_non_system_role(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'role_code' => 'QA_UNUSED_ROLE',
            'role_name' => 'QA Unused Role',
            'is_system_role' => false,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/roles/{$roleId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ])->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_cannot_delete_role_assigned_to_an_active_employee(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'role_code' => 'QA_ASSIGNED_ROLE',
            'role_name' => 'QA Assigned Role',
            'is_system_role' => false,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_roles')->insert([
            'employee_id' => $this->employeeId,
            'role_id' => $roleId,
            'is_active' => true,
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/roles/{$roleId}", [], [
            'Authorization' => "Bearer {$this->token}",
        ])->assertConflict();

        $this->assertDatabaseHas('roles', ['id' => $roleId]);
    }

    public function test_generic_resources_reject_empty_payloads(): void
    {
        $cases = [
            '/api/v1/roles' => 'role_code',
            '/api/v1/dependents' => 'employee_id',
            '/api/v1/leave-types' => 'leave_type_code',
            '/api/v1/shift-types' => 'shift_code',
            '/api/v1/shift-assignments' => 'employee_id',
            '/api/v1/assets' => 'asset_code',
            '/api/v1/asset-assignments' => 'asset_id',
            '/api/v1/news' => 'title',
            '/api/v1/policies' => 'policy_code',
            '/api/v1/salary-components' => 'code',
        ];

        foreach ($cases as $url => $field) {
            $this->postJson($url, [], [
                'Authorization' => "Bearer {$this->token}",
            ])->assertUnprocessable()
                ->assertJsonStructure(['data' => ['errors' => [$field]]]);
        }
    }

    public function test_generic_catalog_codes_are_unique_within_a_tenant(): void
    {
        $cases = [
            ['/api/v1/roles', ['role_code' => 'QA_DUP_ROLE', 'role_name' => 'QA Role']],
            ['/api/v1/leave-types', ['leave_type_code' => 'QA_DUP_LEAVE', 'leave_type_name' => 'QA Leave']],
            ['/api/v1/shift-types', ['shift_code' => 'QA_DUP_SHIFT', 'shift_name' => 'QA Shift', 'start_time' => '08:00', 'end_time' => '17:00']],
            ['/api/v1/assets', ['asset_code' => 'QA_DUP_ASSET', 'asset_name' => 'QA Asset']],
            ['/api/v1/policies', ['policy_code' => 'QA_DUP_POLICY', 'policy_name' => 'QA Policy', 'content' => 'Policy body']],
            ['/api/v1/salary-components', ['code' => 'QA_DUP_COMPONENT', 'name' => 'QA Component', 'type' => 'earning', 'category' => 'fixed']],
        ];

        foreach ($cases as [$url, $payload]) {
            $this->postJson($url, $payload, [
                'Authorization' => "Bearer {$this->token}",
            ])->assertCreated();

            $this->postJson($url, $payload, [
                'Authorization' => "Bearer {$this->token}",
            ])->assertUnprocessable();
        }
    }

    public function test_legal_entity_duplicate_code_returns_validation_error(): void
    {
        $payload = [
            'name' => 'QA Legal Entity',
            'code' => 'QA-LEGAL-ENTITY',
        ];

        $this->postJson('/api/v1/legal-entities', $payload, [
            'Authorization' => "Bearer {$this->token}",
        ])->assertCreated();

        $this->postJson('/api/v1/legal-entities', $payload, [
            'Authorization' => "Bearer {$this->token}",
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['code']]]);
    }

    // =========================================================================
    // STORE VALIDATION TESTS
    // =========================================================================

    public function test_cannot_create_employee_without_full_name(): void
    {
        $response = $this->postJson('/api/v1/employees', [
            'company_email' => 'new@company.com',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('full_name', $response->json('data.errors'));
    }

    public function test_cannot_create_employee_without_email(): void
    {
        $response = $this->postJson('/api/v1/employees', [
            'full_name' => 'New Employee',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('company_email', $response->json('data.errors'));
    }

    public function test_cannot_create_employee_with_duplicate_email(): void
    {
        $response = $this->postJson('/api/v1/employees', [
            'full_name' => 'Duplicate Employee',
            'company_email' => 'test@company.com', // same as setUp employee
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('company_email', $response->json('data.errors'));
    }

    public function test_can_create_employee_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/employees', [
            'full_name' => 'Valid Employee',
            'company_email' => 'valid@company.com',
            'employee_code' => 'VAL0001',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $this->assertNotNull(DB::table('employees')->where('company_email', 'valid@company.com')->first());
    }

    public function test_cannot_create_contract_without_employee_id(): void
    {
        if (! Schema::hasTable('contracts')) {
            $this->markTestSkipped('contracts table does not exist');
        }

        $response = $this->postJson('/api/v1/contracts', [
            'start_date' => '2026-01-01',
            'status' => 'ACTIVE',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('employee_id', $response->json('data.errors'));
    }

    // =========================================================================
    // UPDATE VALIDATION TESTS
    // =========================================================================

    public function test_cannot_update_employee_with_duplicate_email(): void
    {
        // Create another employee
        $otherEmployeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'OTH0001',
            'full_name' => 'Other Employee',
            'company_email' => 'other@company.com',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Try to update this employee's email to the first employee's email
        $response = $this->putJson("/api/v1/employees/{$otherEmployeeId}", [
            'company_email' => 'test@company.com', // already used by setUp employee
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('company_email', $response->json('data.errors'));
    }

    public function test_contract_update_validates_inputs(): void
    {
        if (! Schema::hasTable('contracts')) {
            $this->markTestSkipped('contracts table does not exist');
        }

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $this->employeeId,
            'start_date' => '2026-01-01',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Put invalid start_date / end_date
        $response = $this->putJson("/api/v1/contracts/{$contractId}", [
            'start_date' => 'invalid-date',
            'end_date' => 'invalid-date',
            'contract_type_id' => 99999, // non-existent
            'department_id' => 99999,    // non-existent
            'position_id' => 99999,      // non-existent
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
        $errors = $response->json('data.errors');
        $this->assertArrayHasKey('start_date', $errors);
        $this->assertArrayHasKey('end_date', $errors);
        $this->assertArrayHasKey('contract_type_id', $errors);
        $this->assertArrayHasKey('department_id', $errors);
        $this->assertArrayHasKey('position_id', $errors);
    }

    public function test_contract_update_creates_separate_logs_for_each_field(): void
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasTable('contract_change_logs')) {
            $this->markTestSkipped('Required tables do not exist');
        }

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $this->employeeId,
            'start_date' => '2026-01-01',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Perform update on multiple fields
        $response = $this->putJson("/api/v1/contracts/{$contractId}", [
            'start_date' => '2026-02-02',
            'end_date' => '2026-12-31',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);

        // Check change logs
        $logs = DB::table('contract_change_logs')
            ->where('contract_id', $contractId)
            ->get();

        $this->assertCount(2, $logs);

        $changeTypes = $logs->pluck('change_type')->toArray();
        $this->assertContains('UPDATE_START_DATE', $changeTypes);
        $this->assertContains('UPDATE_END_DATE', $changeTypes);

        // Check meta actor
        foreach ($logs as $log) {
            $meta = json_decode($log->meta, true);
            $this->assertEquals($this->employeeId, $meta['changed_by']);
        }
    }

    public function test_contract_update_syncs_org_for_active_contracts(): void
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasTable('departments') || ! Schema::hasTable('positions')) {
            $this->markTestSkipped('Required tables do not exist');
        }

        $deptId = DB::table('departments')->insertGetId([
            'department_code' => 'D001',
            'department_name' => 'Dept 1',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $posId = DB::table('positions')->insertGetId([
            'position_code' => 'P001',
            'position_name' => 'Position 1',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $this->employeeId,
            'start_date' => '2026-01-01',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Perform update to set department and position
        $response = $this->putJson("/api/v1/contracts/{$contractId}", [
            'department_id' => $deptId,
            'position_id' => $posId,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);

        // Verify employee was updated
        $employee = DB::table('employees')->where('id', $this->employeeId)->first();
        $this->assertEquals($deptId, $employee->department_id);
        $this->assertEquals($posId, $employee->position_id);
    }

    public function test_employee_creation_saves_all_columns_and_normalizes_status(): void
    {
        $response = $this->postJson('/api/v1/employees', [
            'full_name' => 'New Guy',
            'company_email' => 'new.guy@company.com',
            'employee_code' => 'NG0001',
            'phone' => '0987654321',
            'personal_email' => 'new.guy.personal@gmail.com',
            'gender' => 'male',
            'status' => 'inactive',
            'hire_date' => '2026-06-01',
            'date_of_birth' => '1995-05-15',
            'profile' => ['skills' => ['PHP', 'Laravel']],
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $empId = $response->json('data.id');

        $employee = DB::table('employees')->where('id', $empId)->first();
        $this->assertEquals('NG0001', $employee->employee_code);
        $this->assertEquals('0987654321', $employee->phone_number);
        $this->assertEquals('new.guy.personal@gmail.com', $employee->personal_email);
        $this->assertEquals('INACTIVE', $employee->status); // Normalised to uppercase
        $this->assertStringStartsWith('2026-06-01', $employee->hire_date);
        $this->assertStringStartsWith('1995-05-15', $employee->date_of_birth);
        $this->assertEquals('MALE', $employee->gender);
        $profile = json_decode($employee->profile, true);
        $this->assertEquals(['skills' => ['PHP', 'Laravel']], $profile);
    }

    public function test_department_hierarchy_and_manager_meta_merging(): void
    {
        DB::table('employees')->where('id', $this->employeeId)->update([
            'is_super_admin' => true,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
        ]);

        $parentId = DB::table('departments')->insertGetId([
            'department_code' => 'PARENT',
            'department_name' => 'Parent Department',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Create a department with hierarchy/manager
        $response = $this->postJson('/api/v1/departments', [
            'department_code' => 'ENG',
            'department_name' => 'Engineering',
            'parent_id' => $parentId,
            'manager_id' => $this->employeeId,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $deptId = $response->json('data.id');

        $this->assertEquals($parentId, $response->json('data.parent_id'));
        $this->assertEquals($parentId, $response->json('data.parent_department_id'));
        $this->assertEquals($this->employeeId, $response->json('data.manager_id'));

        // Verify it was serialized into meta JSONB
        $dept = DB::table('departments')->where('id', $deptId)->first();
        $meta = json_decode($dept->meta, true);
        $this->assertEquals($parentId, $meta['parent_id']);
        $this->assertEquals($parentId, $meta['parent_department_id']);
        $this->assertEquals($this->employeeId, $meta['manager_id']);

        // 2. Read department detail
        $showResponse = $this->getJson("/api/v1/departments/{$deptId}", [
            'Authorization' => "Bearer {$this->token}",
        ]);
        $showResponse->assertStatus(200);
        $this->assertEquals($parentId, $showResponse->json('data.parent_id'));
        $this->assertEquals($parentId, $showResponse->json('data.parent_department_id'));
        $this->assertEquals($this->employeeId, $showResponse->json('data.manager_id'));

        // 3. Update department
        $updateResponse = $this->putJson("/api/v1/departments/{$deptId}", [
            'department_name' => 'Engineering Updated',
            'parent_id' => null,
            'manager_id' => $this->employeeId,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);
        $updateResponse->assertStatus(200);
        $this->assertNull($updateResponse->json('data.parent_id'));
        $this->assertNull($updateResponse->json('data.parent_department_id'));
        $this->assertEquals($this->employeeId, $updateResponse->json('data.manager_id'));

        // Verify updated in DB
        $dept = DB::table('departments')->where('id', $deptId)->first();
        $meta = json_decode($dept->meta, true);
        $this->assertNull($meta['parent_id'] ?? null);
        $this->assertNull($meta['parent_department_id'] ?? null);
        $this->assertEquals($this->employeeId, $meta['manager_id']);

        // Nested meta must not bypass the shared validation chokepoint.
        $this->patchJson("/api/v1/departments/{$deptId}", [
            'meta' => ['parent_id' => 'xyz', 'manager_id' => 'abc'],
        ], [
            'Authorization' => "Bearer {$this->token}",
        ])->assertStatus(422);

        $meta = json_decode(DB::table('departments')->where('id', $deptId)->value('meta'), true);
        $this->assertNull($meta['parent_id'] ?? null);
        $this->assertEquals($this->employeeId, $meta['manager_id']);
    }

    public function test_contract_update_validates_employee_id_if_present(): void
    {
        if (! Schema::hasTable('contracts')) {
            $this->markTestSkipped('Contracts table does not exist');
        }

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $this->employeeId,
            'start_date' => '2026-01-01',
            'status' => 'ACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->putJson("/api/v1/contracts/{$contractId}", [
            'employee_id' => 99999, // Invalid employee
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422); // Validation error
    }

    public function test_contract_inactive_does_not_sync_org(): void
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasTable('departments') || ! Schema::hasTable('positions')) {
            $this->markTestSkipped('Required tables do not exist');
        }

        $deptId = DB::table('departments')->insertGetId([
            'department_code' => 'D002',
            'department_name' => 'Dept 2',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $this->employeeId,
            'start_date' => '2026-01-01',
            'status' => 'INACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Reset employee department
        DB::table('employees')->where('id', $this->employeeId)->update(['department_id' => null]);

        // Update INACTIVE contract's department_id
        $response = $this->putJson("/api/v1/contracts/{$contractId}", [
            'department_id' => $deptId,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);

        // Employee should NOT be updated because the contract status is not ACTIVE
        $employee = DB::table('employees')->where('id', $this->employeeId)->first();
        $this->assertNull($employee->department_id);
    }

    public function test_contract_transition_from_inactive_to_active_syncs_unconditionally(): void
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasTable('departments') || ! Schema::hasTable('positions')) {
            $this->markTestSkipped('Required tables do not exist');
        }

        $deptId = DB::table('departments')->insertGetId([
            'department_code' => 'D003',
            'department_name' => 'Dept 3',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $this->employeeId,
            'start_date' => '2026-01-01',
            'department_id' => $deptId,
            'status' => 'INACTIVE',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Reset employee department
        DB::table('employees')->where('id', $this->employeeId)->update(['department_id' => null]);

        // Update status of contract to ACTIVE, keeping department_id unchanged in payload
        $response = $this->putJson("/api/v1/contracts/{$contractId}", [
            'status' => 'ACTIVE',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);

        // Employee should be updated with department_id because status transitioned from INACTIVE to ACTIVE
        $employee = DB::table('employees')->where('id', $this->employeeId)->first();
        $this->assertEquals($deptId, $employee->department_id);
    }
}
