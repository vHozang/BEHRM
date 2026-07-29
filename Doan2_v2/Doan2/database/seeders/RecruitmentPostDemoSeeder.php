<?php

namespace Database\Seeders;

use App\Services\RecruitmentPostService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Seed public vacancies so the careers page has useful content on first deploy. */
class RecruitmentPostDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::table('tenants')->where('code', 'DEFAULT')->where('status', 'ACTIVE')->value('id');
        if (! $tenantId) {
            return;
        }

        $positions = DB::table('recruitment_positions')
            ->where('tenant_id', $tenantId)
            ->whereIn('legacy_id', [910001, 910002, 910003])
            ->get(['id', 'legacy_id', 'position_name', 'employment_type']);

        $positionByLegacyId = $positions->keyBy('legacy_id');
        $deadline = now()->addDays(45)->toDateString();
        $posts = [
            [
                'slug' => 'ai-hr-analyst',
                'title' => 'AI HR Analyst',
                'summary' => 'Biến dữ liệu nhân sự thành những quyết định rõ ràng, công bằng và có tác động.',
                'content' => 'Bạn sẽ đồng hành cùng đội ngũ HR và sản phẩm để xây dựng báo cáo, mô hình phân tích và các trải nghiệm nhân sự tốt hơn. Đây là vị trí dành cho người thích đặt câu hỏi đúng trước khi tìm câu trả lời bằng dữ liệu.',
                'benefits' => ['Môi trường ưu tiên dữ liệu và tự chủ', 'Ngân sách học tập hằng năm', 'Làm việc linh hoạt tại TP. Hồ Chí Minh'],
                'requirements' => ['Có kinh nghiệm với HR analytics hoặc business intelligence', 'Sử dụng tốt SQL và các công cụ dashboard', 'Tư duy phân tích, giao tiếp rõ ràng với các bên liên quan'],
                'location' => 'TP. Hồ Chí Minh / Hybrid',
                'salary_range' => '25 - 40 triệu VNĐ',
                'position_legacy_id' => 910001,
            ],
            [
                'slug' => 'talent-acquisition-specialist',
                'title' => 'Talent Acquisition Specialist',
                'summary' => 'Xây dựng trải nghiệm ứng viên đáng nhớ và đưa đúng người về đúng đội ngũ.',
                'content' => 'Bạn sẽ phụ trách toàn bộ hành trình tuyển dụng cho các nhóm sản phẩm và vận hành: từ tìm kiếm, phỏng vấn đến chăm sóc ứng viên. Chúng tôi tìm một người vừa tinh tế với con người, vừa thích thử nghiệm cách làm mới.',
                'benefits' => ['Thưởng tuyển dụng theo hiệu quả', 'Bảo hiểm sức khỏe mở rộng', 'Được tham gia xây dựng thương hiệu tuyển dụng'],
                'requirements' => ['Có kinh nghiệm tuyển dụng end-to-end', 'Kỹ năng phỏng vấn và đánh giá ứng viên tốt', 'Giao tiếp chủ động, tôn trọng dữ liệu và sự đa dạng'],
                'location' => 'TP. Hồ Chí Minh',
                'salary_range' => '18 - 30 triệu VNĐ',
                'position_legacy_id' => 910002,
            ],
            [
                'slug' => 'payroll-operations-executive',
                'title' => 'Payroll Operations Executive',
                'summary' => 'Giữ cho mỗi kỳ lương chính xác, minh bạch và đúng hẹn.',
                'content' => 'Bạn sẽ vận hành quy trình payroll cho nhiều nhóm nhân viên, phối hợp với kế toán và HR để xử lý dữ liệu, đối soát và tuân thủ. Vị trí phù hợp với người kỹ tính, yêu thích hệ thống và luôn tìm cách giảm sai sót lặp lại.',
                'benefits' => ['Lộ trình phát triển chuyên môn payroll', 'Thưởng theo kết quả vận hành', 'Văn hóa hỗ trợ và làm việc có quy trình'],
                'requirements' => ['Có kinh nghiệm payroll hoặc vận hành nhân sự', 'Thành thạo Excel và đối soát dữ liệu', 'Nắm được các nguyên tắc tuân thủ lao động cơ bản'],
                'location' => 'TP. Hồ Chí Minh / Hybrid',
                'salary_range' => '16 - 26 triệu VNĐ',
                'position_legacy_id' => 910003,
            ],
        ];

        $postService = app(RecruitmentPostService::class);
        foreach ($posts as $post) {
            $position = $positionByLegacyId->get($post['position_legacy_id']);
            if (! $position) {
                continue;
            }

            $payload = [
                'tenant_id' => $tenantId,
                'recruitment_position_id' => $position->id,
                'slug' => $post['slug'],
                'title' => $post['title'],
                'summary' => $post['summary'],
                'content' => $post['content'],
                'benefits' => json_encode($post['benefits'], JSON_UNESCAPED_UNICODE),
                'requirements' => json_encode($post['requirements'], JSON_UNESCAPED_UNICODE),
                'location' => $post['location'],
                'salary_range' => $post['salary_range'],
                'employment_type' => $position->employment_type ?: 'FULL_TIME',
                'deadline' => $deadline,
                'status' => 'PUBLISHED',
                'published_at' => now(),
                'meta' => json_encode(['source' => 'recruitment-demo'], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ];

            // Only bootstrap missing demo posts; never overwrite HR edits on deploy.
            DB::table('recruitment_posts')->insertOrIgnore($payload + ['created_at' => now()]);

            $saved = DB::table('recruitment_posts')->where('slug', $post['slug'])->first();
            if ($saved) {
                $postService->syncToRedis($saved);
            }
        }
    }
}
