<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\EmployeeController;
use App\Models\Contract;
use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->updateOrInsert(['id' => 1], [
            'name' => 'Test tenant',
            'code' => 'TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_entities')->updateOrInsert(['id' => 1], [
            'tenant_id' => 1,
            'name' => 'Test entity',
            'code' => 'TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        TenantContext::set(1, 1);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_partial_employee_profile_update_preserves_existing_keys(): void
    {
        $employee = Employee::create([
            'employee_code' => 'TEST0001',
            'full_name' => 'Test Employee',
            'company_email' => 'employee@test.local',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'profile' => ['bank_account' => '123456', 'address' => 'Old address'],
        ]);

        $request = Request::create('/', 'PATCH', [
            'profile' => ['address' => 'New address'],
        ]);

        (new EmployeeController)->update($request, $employee->id);

        $profile = $employee->fresh()->profile;
        $this->assertSame('New address', $profile['address']);
        $this->assertSame('123456', $profile['bank_account']);
    }

    public function test_partial_contract_meta_update_preserves_signature_audit(): void
    {
        $contract = Contract::create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'status' => 'ACTIVE',
            'meta' => ['notes' => 'Old note', 'sign_status' => 'SIGNED', 'signature_audit_id' => 'audit-1'],
        ]);

        $request = Request::create('/', 'PATCH', [
            'meta' => ['notes' => 'New note'],
        ]);

        (new ContractController)->update($request, $contract->id);

        $meta = $contract->fresh()->meta;
        $this->assertSame('New note', $meta['notes']);
        $this->assertSame('SIGNED', $meta['sign_status']);
        $this->assertSame('audit-1', $meta['signature_audit_id']);
    }

    public function test_employee_and_contract_history_cannot_be_hard_deleted(): void
    {
        $employee = Employee::create([
            'employee_code' => 'TEST0002',
            'full_name' => 'History Owner',
            'company_email' => 'history@test.local',
            'tenant_id' => 1,
            'legal_entity_id' => 1,
        ]);
        $contract = Contract::create([
            'employee_id' => $employee->id,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'status' => 'INACTIVE',
        ]);

        $this->assertSame(409, (new EmployeeController)->destroy($employee->id)->getStatusCode());
        $this->assertSame(409, (new ContractController)->destroy($contract->id)->getStatusCode());
        $this->assertTrue($employee->fresh()->exists);
        $this->assertTrue($contract->fresh()->exists);
    }

    public function test_candidate_cv_requires_authentication(): void
    {
        $this->getJson('/api/v1/recruitment-candidates/1/cv')->assertUnauthorized();
    }

    public function test_public_recruitment_is_explicitly_tenant_scoped_and_requires_cv(): void
    {
        DB::table('tenants')->insert([
            'id' => 2,
            'name' => 'Other tenant',
            'code' => 'OTHER',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = DB::table('recruitment_positions')->insertGetId([
            'position_name' => 'Tenant one role',
            'status' => 'OPEN',
            'tenant_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('recruitment_positions')->insert([
            'position_name' => 'Tenant two role',
            'status' => 'OPEN',
            'tenant_id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        TenantContext::clear();

        $this->getJson('/api/v1/public/positions')->assertUnprocessable();
        $this->getJson('/api/v1/public/positions?tenant_code=TEST')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.position_name', 'Tenant one role');

        $this->postJson('/api/v1/public/recruitment/applications', [
            'tenant_code' => 'TEST',
            'full_name' => 'Scoped Candidate',
            'email' => 'candidate@test.local',
            'recruitment_position_id' => $positionId,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['post_slug', 'cv'], 'data.errors');

        $this->assertDatabaseMissing('recruitment_candidates', [
            'email' => 'candidate@test.local',
        ]);
    }

    public function test_internal_ai_worker_fails_closed_without_configured_token(): void
    {
        config(['hrm.internal_service_token' => null]);

        $this->postJson('/api/v1/internal/recruitment-ai-jobs/process')->assertForbidden();
    }
}
