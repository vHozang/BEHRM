<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecruitmentPostPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_post_is_published_with_a_linked_position_and_public_jd(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
        $token = $this->adminToken();

        $response = $this->withToken($token)->postJson('/api/v1/recruitment-posts', [
            'title' => 'Senior Laravel Developer',
            'summary' => 'Xây dựng nền tảng HRM ổn định cho doanh nghiệp Việt Nam.',
            'content' => "Thiết kế API Laravel.\nTối ưu hiệu năng và chất lượng mã nguồn.",
            'requirements' => ['Tối thiểu 3 năm kinh nghiệm Laravel', 'Hiểu PostgreSQL và Docker'],
            'benefits' => ['Làm việc hybrid', 'Ngân sách học tập hằng năm'],
            'location' => 'TP. Hồ Chí Minh / Hybrid',
            'salary_range' => '30 - 45 triệu VNĐ',
            'employment_type' => 'FULL_TIME',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'PUBLISHED',
            'meta' => [
                'quantity' => 2,
                'required_skills' => ['Laravel', 'PostgreSQL', 'Docker'],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'PUBLISHED');
        $postId = $response->json('data.id');
        $positionId = $response->json('data.recruitment_position_id');
        $slug = $response->json('data.slug');

        $this->assertDatabaseHas('recruitment_posts', [
            'id' => $postId,
            'title' => 'Senior Laravel Developer',
            'status' => 'PUBLISHED',
        ]);
        $this->assertDatabaseHas('recruitment_positions', [
            'id' => $positionId,
            'position_name' => 'Senior Laravel Developer',
            'status' => 'OPEN',
        ]);

        $this->getJson("/api/v1/public/recruitment-posts/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Senior Laravel Developer')
            ->assertJsonPath('data.requirements.0', 'Tối thiểu 3 năm kinh nghiệm Laravel')
            ->assertJsonPath('data.position.required_skills.0', 'Laravel');

        $this->withToken($token)->putJson("/api/v1/recruitment-posts/{$postId}", [
            'title' => 'Lead Laravel Developer',
            'status' => 'CLOSED',
            'meta' => ['quantity' => 1, 'required_skills' => ['Laravel', 'Leadership']],
        ])->assertOk();

        $this->assertDatabaseHas('recruitment_positions', [
            'id' => $positionId,
            'position_name' => 'Lead Laravel Developer',
            'status' => 'CLOSED',
        ]);
        $this->getJson("/api/v1/public/recruitment-posts/{$slug}")->assertNotFound();
    }

    private function adminToken(): string
    {
        DB::table('employees')->insert([
            'employee_code' => 'POSTADMIN',
            'full_name' => 'Recruitment Admin',
            'company_email' => 'recruitment.admin@example.com',
            'password_hash' => Hash::make('password'),
            'status' => 'ACTIVE',
            'is_super_admin' => true,
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->postJson('/api/v1/auth/login', [
            'company_email' => 'recruitment.admin@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.access_token');
    }
}
