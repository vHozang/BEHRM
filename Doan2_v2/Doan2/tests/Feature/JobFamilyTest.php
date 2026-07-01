<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JobFamilyTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('employees')->insert([
            'employee_code' => 'T001',
            'full_name' => 'Test Employee',
            'company_email' => 'test.employee@company.com',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->token = $this->postJson('/api/v1/auth/login', [
            'company_email' => 'test.employee@company.com',
            'password' => 'password',
        ])->json('data.access_token');
    }

    public function test_job_families_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('job_families'));
        $this->assertTrue(Schema::hasColumns('job_families', [
            'id', 'code', 'name', 'description', 'is_active', 'meta', 'created_at', 'updated_at'
        ]));
    }

    public function test_positions_table_has_job_family_id(): void
    {
        $this->assertTrue(Schema::hasTable('positions'));
        $this->assertTrue(Schema::hasColumn('positions', 'job_family_id'));
    }

    public function test_can_insert_job_family_and_associate_with_position(): void
    {
        $jobFamilyId = DB::table('job_families')->insertGetId([
            'code' => 'JF-ENG',
            'name' => 'Engineering',
            'description' => 'Software engineering job family',
            'is_active' => true,
            'meta' => json_encode(['level_count' => 5]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'position_code' => 'P-SWE',
            'position_name' => 'Software Engineer',
            'job_family_id' => $jobFamilyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $position = DB::table('positions')->where('id', $positionId)->first();
        $this->assertEquals($jobFamilyId, $position->job_family_id);

        // Test nullOnDelete
        DB::table('job_families')->where('id', $jobFamilyId)->delete();

        $positionAfterDelete = DB::table('positions')->where('id', $positionId)->first();
        $this->assertNull($positionAfterDelete->job_family_id);
    }

    public function test_create_job_family_with_duplicate_code_triggers_422_validation_error(): void
    {
        // Insert existing job family
        DB::table('job_families')->insert([
            'code' => 'JF-ENG',
            'name' => 'Engineering',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to create a new one with same code
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/job-families', [
                'code' => 'JF-ENG',
                'name' => 'New Engineering',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('data.errors'));
        $this->assertContains('Mã nhóm công việc đã tồn tại', $response->json('data.errors.code'));
    }

    public function test_create_job_family_with_missing_code_or_name_triggers_422_validation_error(): void
    {
        // 1. Missing code
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/job-families', [
                'name' => 'Engineering',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('code', $response->json('data.errors'));
        $this->assertContains('Mã nhóm công việc là bắt buộc', $response->json('data.errors.code'));

        // 2. Missing name
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/job-families', [
                'code' => 'JF-ENG',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('data.errors'));
        $this->assertContains('Tên nhóm công việc là bắt buộc', $response->json('data.errors.name'));
    }

    public function test_create_job_family_with_is_active_true_works_correctly(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/job-families', [
                'code' => 'JF-HR',
                'name' => 'Human Resources',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $this->assertEquals('JF-HR', $response->json('data.code'));
        $this->assertEquals('Human Resources', $response->json('data.name'));
        
        // Retrieve and assert database contains correct boolean value
        $jobFamily = DB::table('job_families')->where('code', 'JF-HR')->first();
        $this->assertNotNull($jobFamily);
        $this->assertTrue(filter_var($jobFamily->is_active, FILTER_VALIDATE_BOOLEAN));
    }
}
