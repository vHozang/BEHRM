<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecruitmentStatusSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $tenantId;

    private int $positionId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenantId = (int) DB::table('tenants')->where('code', 'DEFAULT')->value('id');
        $this->positionId = DB::table('recruitment_positions')->insertGetId([
            'tenant_id' => $this->tenantId,
            'position_name' => 'Backend Developer',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'employee_code' => 'SYNCADMIN',
            'full_name' => 'Recruitment Sync Admin',
            'company_email' => 'recruitment.sync@example.test',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'is_super_admin' => true,
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->token = $this->postJson('/api/v1/auth/login', [
            'company_email' => 'recruitment.sync@example.test',
            'password' => 'password',
        ])->assertOk()->json('data.access_token');
    }

    public function test_manager_approval_completes_interview_and_moves_candidate_to_offered(): void
    {
        $candidateId = $this->candidate('INTERVIEWING', 'approved@example.test');
        $interviewId = $this->interview($candidateId);

        $this->withToken($this->token)
            ->patchJson("/api/v1/interviews/{$interviewId}/manager-review", [
                'decision' => 'approved',
                'note' => 'Đáp ứng yêu cầu chuyên môn.',
            ])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.result', 'PASSED')
            ->assertJsonPath('data.manager_decision', 'APPROVED')
            ->assertJsonPath('data.meta.result_note', 'Đáp ứng yêu cầu chuyên môn.')
            ->assertJsonPath('data.candidate.application_status', 'OFFERED');

        $this->assertDatabaseHas('recruitment_candidates', [
            'id' => $candidateId,
            'application_status' => 'OFFERED',
        ]);
    }

    public function test_manager_rejection_completes_interview_and_rejects_candidate(): void
    {
        $candidateId = $this->candidate('INTERVIEWING', 'manager-rejected@example.test');
        $interviewId = $this->interview($candidateId);

        $this->withToken($this->token)
            ->patchJson("/api/v1/interviews/{$interviewId}/manager-review", [
                'decision' => 'rejected',
                'note' => 'Chưa đáp ứng yêu cầu vị trí.',
            ])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.result', 'FAILED')
            ->assertJsonPath('data.manager_decision', 'REJECTED')
            ->assertJsonPath('data.candidate.application_status', 'REJECTED');
    }

    public function test_candidate_manager_approval_persists_review_and_advances_pipeline(): void
    {
        $candidateId = $this->candidate('SCREENING', 'candidate-review@example.test');

        $this->withToken($this->token)
            ->patchJson("/api/v1/recruitment-candidates/{$candidateId}/manager-review", [
                'decision' => 'approved',
                'note' => 'Mời ứng viên vào vòng phỏng vấn.',
                'manager_score' => 82,
            ])->assertOk()
            ->assertJsonPath('data.workflow_status', 'APPROVED')
            ->assertJsonPath('data.manager_decision_proposal', 'APPROVED')
            ->assertJsonPath('data.candidate.application_status', 'INTERVIEWING');

        $this->assertDatabaseHas('recruitment_candidate_manager_reviews', [
            'candidate_id' => $candidateId,
            'workflow_status' => 'APPROVED',
            'manager_decision_proposal' => 'APPROVED',
            'manager_score' => 82,
        ]);
    }

    public function test_hiring_candidate_completes_remaining_scheduled_interviews(): void
    {
        $candidateId = $this->candidate('OFFERED', 'sync-hired@example.test');
        $interviewId = $this->interview($candidateId);

        $this->withToken($this->token)
            ->postJson("/api/v1/recruitment-candidates/{$candidateId}/hire", [
                'start_date' => now()->addDays(3)->toDateString(),
            ])->assertOk()
            ->assertJsonPath('data.candidate.application_status', 'HIRED');

        $this->assertDatabaseHas('interview_schedules', [
            'id' => $interviewId,
            'status' => 'COMPLETED',
            'result' => 'PASSED',
            'manager_decision' => 'APPROVED',
        ]);
    }

    public function test_rejecting_candidate_completes_remaining_scheduled_interviews(): void
    {
        $candidateId = $this->candidate('INTERVIEWING', 'sync-rejected@example.test');
        $interviewId = $this->interview($candidateId);

        $this->withToken($this->token)
            ->postJson("/api/v1/recruitment-candidates/{$candidateId}/reject", [
                'reason' => 'Không phù hợp với yêu cầu hiện tại.',
            ])->assertOk()
            ->assertJsonPath('data.application_status', 'REJECTED');

        $this->assertDatabaseHas('interview_schedules', [
            'id' => $interviewId,
            'status' => 'COMPLETED',
            'result' => 'FAILED',
            'manager_decision' => 'REJECTED',
        ]);
    }

    private function candidate(string $status, string $email): int
    {
        return DB::table('recruitment_candidates')->insertGetId([
            'tenant_id' => $this->tenantId,
            'recruitment_position_id' => $this->positionId,
            'full_name' => 'Ứng viên đồng bộ',
            'email' => $email,
            'application_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function interview(int $candidateId): int
    {
        return DB::table('interview_schedules')->insertGetId([
            'tenant_id' => $this->tenantId,
            'candidate_id' => $candidateId,
            'interview_date' => now()->addDays(2)->toDateString(),
            'interview_time' => '09:30:00',
            'interview_mode' => 'ONSITE',
            'status' => 'SCHEDULED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
