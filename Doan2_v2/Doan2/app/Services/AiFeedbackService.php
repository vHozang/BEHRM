<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service: Gửi feedback về điểm số từ HR/Trưởng phòng cho dịch vụ AI AutoRecruit.
 *
 * Luồng hoạt động:
 * ┌──────────────────┐    ┌───────────┐    ┌──────────────────┐
 * │ HR chấm lại điểm │───▶│  Laravel   │───▶│ AutoRecruit API  │
 * │ (manager_score)  │    │ Feedback   │    │ POST /feedback   │
 * └──────────────────┘    └───────────┘    └──────────────────┘
 *
 * Khi HR/Trưởng phòng submit manager_score khác với ai_score, service sẽ:
 * 1. Thu thập dữ liệu: ai_score, manager_score, CV skills, position requirements.
 * 2. Gửi HTTP POST tới AutoRecruit /feedback endpoint.
 * 3. Nhận phân tích nguyên nhân lệch điểm và lưu vào meta của review.
 *
 * Xử lý fire-and-forget: Nếu AutoRecruit không khả dụng, vẫn lưu
 * review bình thường và ghi log cảnh báo.
 */
class AiFeedbackService
{
    /**
     * Gửi feedback cho dịch vụ AI khi HR/Trưởng phòng chấm lại điểm.
     *
     * @param  int    $candidateId   ID ứng viên
     * @param  int    $aiScore       Điểm AI ban đầu (0..100)
     * @param  int    $humanScore    Điểm người chấm (0..100)
     * @param  string $jdText        Mô tả yêu cầu vị trí
     * @param  array  $cvSkills      Danh sách kỹ năng ứng viên
     * @param  array  $matchedSkills Kỹ năng AI đã match
     * @param  array  $missingSkills Kỹ năng AI báo thiếu
     * @return array|null            Kết quả phân tích từ AI hoặc null nếu lỗi
     */
    public function sendFeedback(
        int $candidateId,
        int $aiScore,
        int $humanScore,
        string $jdText = '',
        array $cvSkills = [],
        array $matchedSkills = [],
        array $missingSkills = []
    ): ?array {
        try {
            $url = $this->baseUrl();

            $response = Http::timeout(15)
                ->post($url . '/feedback', [
                    'candidate_id' => $candidateId,
                    'ai_score' => $aiScore / 100,       // Chuyển 0..100 → 0..1
                    'human_score' => $humanScore / 100,  // Chuyển 0..100 → 0..1
                    'jd_text' => $jdText,
                    'cv_skills' => $cvSkills,
                    'ai_matched_skills' => $matchedSkills,
                    'ai_missing_skills' => $missingSkills,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::info("AI Feedback sent successfully for candidate #{$candidateId}", [
                    'ai_score' => $aiScore,
                    'human_score' => $humanScore,
                    'delta' => $data['analysis']['delta'] ?? null,
                    'direction' => $data['analysis']['direction'] ?? null,
                ]);

                return $data;
            }

            Log::warning("AI Feedback API call failed: " . $response->status() . " - " . $response->body());

        } catch (\Throwable $e) {
            Log::error("Failed to send AI feedback for candidate #{$candidateId}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Lấy thống kê feedback từ dịch vụ AI.
     *
     * @return array|null  Dữ liệu thống kê hoặc null nếu lỗi
     */
    public function getStats(): ?array
    {
        try {
            $url = $this->baseUrl();

            $response = Http::timeout(10)->get($url . '/feedback/stats');

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("AI Feedback stats API failed: " . $response->status());

        } catch (\Throwable $e) {
            Log::error("Failed to get AI feedback stats: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Lấy gợi ý điều chỉnh trọng số từ dịch vụ AI.
     *
     * @return array|null  Dữ liệu điều chỉnh hoặc null nếu lỗi
     */
    public function getAdjustments(): ?array
    {
        try {
            $url = $this->baseUrl();

            $response = Http::timeout(10)->get($url . '/feedback/adjustments');

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("AI Feedback adjustments API failed: " . $response->status());

        } catch (\Throwable $e) {
            Log::error("Failed to get AI feedback adjustments: " . $e->getMessage());
        }

        return null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.autorecruit.url', 'http://resume-backend:8000'), '/');
    }
}
