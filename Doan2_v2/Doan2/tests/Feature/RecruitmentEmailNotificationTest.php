<?php

namespace Tests\Feature;

use App\Mail\RecruitmentNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecruitmentEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $tenantId;

    private int $positionId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'recruitment.mail.from_address' => 'hr@devtapcode.io.vn',
            'recruitment.mail.from_name' => 'DEVTAPCODE HR',
        ]);

        $this->tenantId = (int) DB::table('tenants')->where('code', 'DEFAULT')->value('id');
        $this->positionId = DB::table('recruitment_positions')->insertGetId([
            'tenant_id' => $this->tenantId,
            'position_name' => 'Laravel Developer',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'employee_code' => 'MAILHR',
            'full_name' => 'HR Devtapcode',
            'company_email' => 'mail.hr@example.test',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'is_super_admin' => true,
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->token = $this->postJson('/api/v1/auth/login', [
            'company_email' => 'mail.hr@example.test',
            'password' => 'password',
        ])->assertOk()->json('data.access_token');
    }

    public function test_creating_interview_sends_invitation_and_moves_candidate_to_interviewing(): void
    {
        $candidateId = $this->candidate('SCREENING', 'interview@example.test');

        $this->withToken($this->token)->postJson('/api/v1/interviews', [
            'candidate_id' => $candidateId,
            'interview_date' => '2026-08-10T09:30',
            'interviewer' => 'Nguyễn Văn A - Tech Lead',
            'interview_mode' => 'ONLINE',
            'meeting_link' => 'https://meet.google.com/test-room',
            'duration_minutes' => 60,
            'confirmation_deadline' => '2026-08-08',
        ])->assertCreated()
            ->assertJsonPath('data.invitation_email_sent', true)
            ->assertJsonPath('data.interview_time', '09:30');

        $this->assertDatabaseHas('recruitment_candidates', [
            'id' => $candidateId,
            'application_status' => 'INTERVIEWING',
        ]);
        Mail::assertSent(RecruitmentNotificationMail::class, function (RecruitmentNotificationMail $mail): bool {
            return $mail->notificationType === RecruitmentNotificationMail::INTERVIEW_INVITATION
                && $mail->hasTo('interview@example.test')
                && $mail->mailData['interview_time'] === '09:30'
                && $mail->mailData['meeting_link'] === 'https://meet.google.com/test-room'
                && str_contains($mail->render(), 'Nguyễn Văn A - Tech Lead');
        });
    }

    public function test_hiring_candidate_sends_job_offer_email_from_hr_address(): void
    {
        $candidateId = $this->candidate('OFFERED', 'hired@example.test');

        $this->withToken($this->token)->postJson("/api/v1/recruitment-candidates/{$candidateId}/hire", [
            'start_date' => now()->addDays(3)->toDateString(),
            'arrival_time' => '08:30',
            'work_location' => 'Văn phòng DEVTAPCODE',
            'offer_note' => 'Vui lòng mang theo CCCD và hồ sơ cá nhân.',
        ])->assertOk()
            ->assertJsonPath('data.notification_email_sent', true)
            ->assertJsonPath('data.candidate.application_status', 'HIRED');

        Mail::assertSent(RecruitmentNotificationMail::class, function (RecruitmentNotificationMail $mail): bool {
            return $mail->notificationType === RecruitmentNotificationMail::HIRED
                && $mail->hasTo('hired@example.test')
                && $mail->envelope()->from?->address === 'hr@devtapcode.io.vn'
                && $mail->mailData['work_location'] === 'Văn phòng DEVTAPCODE'
                && str_contains($mail->render(), 'Chúc mừng bạn đã trúng tuyển');
        });
    }

    public function test_rejecting_candidate_sends_polite_feedback_email(): void
    {
        $candidateId = $this->candidate('SCREENING', 'rejected@example.test');

        $this->withToken($this->token)->postJson("/api/v1/recruitment-candidates/{$candidateId}/reject", [
            'reason' => 'Kinh nghiệm hiện tại chưa hoàn toàn phù hợp với yêu cầu của vị trí.',
        ])->assertOk()
            ->assertJsonPath('data.application_status', 'REJECTED')
            ->assertJsonPath('data.notification_email_sent', true);

        Mail::assertSent(RecruitmentNotificationMail::class, function (RecruitmentNotificationMail $mail): bool {
            return $mail->notificationType === RecruitmentNotificationMail::REJECTED
                && $mail->hasTo('rejected@example.test')
                && str_contains((string) $mail->mailData['rejection_reason'], 'chưa hoàn toàn phù hợp')
                && str_contains($mail->render(), 'Thông báo kết quả tuyển dụng');
        });
    }

    private function candidate(string $status, string $email): int
    {
        return DB::table('recruitment_candidates')->insertGetId([
            'tenant_id' => $this->tenantId,
            'recruitment_position_id' => $this->positionId,
            'full_name' => 'Ứng viên Email',
            'email' => $email,
            'phone_number' => '0900000000',
            'application_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
