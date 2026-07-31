<?php

namespace Tests\Feature;

use App\Mail\RecruitmentNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicRecruitmentApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'recruitment.mail.from_address' => 'hr@devtapcode.io.vn',
            'recruitment.mail.from_name' => 'DEVTAPCODE HR',
        ]);
    }

    public function test_public_application_stores_cv_and_ai_result(): void
    {
        [$tenantId, $post] = $this->createPublishedPost();
        Storage::fake('local');
        config(['services.autorecruit.url' => 'http://resume-backend.test']);
        Http::fake([
            'http://resume-backend.test/screen' => Http::response([
                'job_id' => 42,
                'candidate' => [
                    'skills' => ['SQL', 'Dashboard'],
                    'years_experience' => 3,
                    'scores' => [
                        'final_score' => 0.84,
                        'matched_skills' => ['SQL'],
                        'missing_skills' => ['Power BI'],
                        'recommendation' => 'high_fit',
                    ],
                ],
            ]),
        ]);

        $response = $this->post('/api/v1/public/recruitment/applications', [
            'tenant_code' => 'DEFAULT',
            'post_slug' => $post['slug'],
            'full_name' => 'Nguyễn Ứng Viên',
            'email' => 'candidate@example.com',
            'phone' => '0900000000',
            'cover_letter' => 'Tôi rất quan tâm đến vị trí này.',
            'cv' => UploadedFile::fake()->create('candidate.pdf', 200, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.ai_scoring_status', 'DONE')
            ->assertJsonPath('data.ai_score', 84)
            ->assertJsonPath('data.matched_skills.0', 'SQL')
            ->assertJsonPath('data.missing_skills.0', 'Power BI')
            ->assertJsonPath('data.confirmation_email_sent', true);

        $candidateId = $response->json('data.candidate_id');
        $this->assertDatabaseHas('recruitment_candidates', [
            'id' => $candidateId,
            'tenant_id' => $tenantId,
            'email' => 'candidate@example.com',
            'ai_scoring_status' => 'DONE',
        ]);
        $this->assertDatabaseHas('recruitment_candidate_cvs', [
            'candidate_id' => $candidateId,
            'original_filename' => 'candidate.pdf',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'http://resume-backend.test/screen');
        Mail::assertSent(RecruitmentNotificationMail::class, function (RecruitmentNotificationMail $mail): bool {
            return $mail->notificationType === RecruitmentNotificationMail::APPLICATION_RECEIVED
                && $mail->hasTo('candidate@example.com')
                && $mail->envelope()->from?->address === 'hr@devtapcode.io.vn'
                && str_contains($mail->envelope()->subject, 'AI HR Analyst')
                && str_contains($mail->render(), 'Nguyễn Ứng Viên');
        });
    }

    public function test_public_application_rejects_unknown_post_and_invalid_cv(): void
    {
        $this->createPublishedPost();

        $this->post('/api/v1/public/recruitment/applications', [
            'tenant_code' => 'DEFAULT',
            'post_slug' => 'does-not-exist',
            'full_name' => 'Candidate',
            'email' => 'candidate@example.com',
            'cv' => UploadedFile::fake()->create('candidate.pdf', 10, 'application/pdf'),
        ])->assertNotFound();

        $this->post('/api/v1/public/recruitment/applications', [
            'tenant_code' => 'DEFAULT',
            'post_slug' => 'ai-hr-analyst',
            'full_name' => 'Candidate',
            'email' => 'candidate@example.com',
            'cv' => UploadedFile::fake()->create('candidate.txt', 10, 'text/plain'),
        ])->assertUnprocessable();
    }

    public function test_public_application_requires_a_cv_and_does_not_create_a_candidate(): void
    {
        [, $post] = $this->createPublishedPost();

        $this->postJson('/api/v1/public/recruitment/applications', [
            'tenant_code' => 'DEFAULT',
            'post_slug' => $post['slug'],
            'full_name' => 'Candidate Without CV',
            'email' => 'without-cv@example.com',
        ])->assertUnprocessable()
            ->assertJsonPath('data.errors.cv.0', 'Vui lòng tải lên CV của bạn');

        $this->assertDatabaseMissing('recruitment_candidates', [
            'email' => 'without-cv@example.com',
        ]);
    }

    public function test_public_application_falls_back_to_the_second_resume_backend(): void
    {
        $this->createPublishedPost();
        Storage::fake('local');
        config([
            'services.autorecruit.url' => 'http://resume-primary.test',
            'services.autorecruit.fallback_urls' => ['http://resume-fallback.test'],
        ]);
        Http::fake([
            'http://resume-primary.test/screen' => Http::response([], 503),
            'http://resume-fallback.test/screen' => Http::response([
                'job_id' => 99,
                'candidate' => ['scores' => ['final_score' => 0.75]],
            ]),
        ]);

        $this->post('/api/v1/public/recruitment/applications', [
            'tenant_code' => 'DEFAULT',
            'post_slug' => 'ai-hr-analyst',
            'full_name' => 'Fallback Candidate',
            'email' => 'fallback@example.com',
            'cv' => UploadedFile::fake()->create('fallback.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.ai_scoring_status', 'DONE')
            ->assertJsonPath('data.ai_score', 75);

        Http::assertSentCount(2);
    }

    private function createPublishedPost(): array
    {
        $tenantId = DB::table('tenants')->where('code', 'DEFAULT')->value('id');
        if (! $tenantId) {
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'Default Company',
                'code' => 'DEFAULT',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $positionId = DB::table('recruitment_positions')->insertGetId([
            'tenant_id' => $tenantId,
            'position_name' => 'AI HR Analyst',
            'status' => 'OPEN',
            'required_skills_json' => json_encode(['SQL', 'Dashboard']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $post = [
            'tenant_id' => $tenantId,
            'recruitment_position_id' => $positionId,
            'slug' => 'ai-hr-analyst',
            'title' => 'AI HR Analyst',
            'summary' => 'Analyze people data.',
            'content' => 'Build dashboards for HR.',
            'requirements' => json_encode(['SQL experience']),
            'status' => 'PUBLISHED',
            'deadline' => now()->addDays(7)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('recruitment_posts')->insert($post);

        return [$tenantId, $post];
    }
}
