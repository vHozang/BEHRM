<?php

namespace App\Services;

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
     * @param  int  $candidateId  ID ứng viên
     * @param  int  $aiScore  Điểm AI ban đầu (0..100)
     * @param  int  $humanScore  Điểm người chấm (0..100)
     * @param  string  $jdText  Mô tả yêu cầu vị trí
     * @param  array  $cvSkills  Danh sách kỹ năng ứng viên
     * @param  array  $matchedSkills  Kỹ năng AI đã match
     * @param  array  $missingSkills  Kỹ năng AI báo thiếu
     * @return array|null Kết quả phân tích từ AI hoặc null nếu lỗi
     */
    public function sendFeedback(
        int $candidateId,
        int $aiScore,
        int $humanScore,
        string $jdText = '',
        array $cvSkills = [],
        array $matchedSkills = [],
        array $missingSkills = [],
        ?int $assessmentId = null,
        ?string $reviewerId = null,
        string $reviewerRole = 'HR',
        string $decision = 'NEEDS_REVIEW',
        string $note = '',
        array $criteria = [],
        bool $blindReview = true,
        bool $eligibleForTraining = false,
    ): ?array {
        $data = $this->requestJson('POST', '/feedback', [
            'candidate_id' => $candidateId,
            'assessment_id' => $assessmentId,
            'ai_score' => $aiScore / 100,       // Chuyển 0..100 → 0..1
            'human_score' => $humanScore / 100,  // Chuyển 0..100 → 0..1
            'reviewer_id' => $reviewerId,
            'reviewer_role' => $reviewerRole,
            'decision' => $decision,
            'note' => $note,
            'criteria' => $criteria,
            'blind_review' => $blindReview,
            'eligible_for_training' => $eligibleForTraining,
            'jd_text' => $jdText,
            'cv_skills' => $cvSkills,
            'ai_matched_skills' => $matchedSkills,
            'ai_missing_skills' => $missingSkills,
        ], 15);

        if ($data !== null) {
            Log::info("AI Feedback sent successfully for candidate #{$candidateId}", [
                'ai_score' => $aiScore,
                'human_score' => $humanScore,
                'delta' => $data['analysis']['delta'] ?? null,
                'direction' => $data['analysis']['direction'] ?? null,
            ]);
        }

        return $data;
    }

    /**
     * Lấy thống kê feedback từ dịch vụ AI.
     *
     * @return array|null Dữ liệu thống kê hoặc null nếu lỗi
     */
    public function getStats(): ?array
    {
        return $this->requestJson('GET', '/feedback/stats');
    }

    /**
     * Lấy gợi ý điều chỉnh trọng số từ dịch vụ AI.
     *
     * @return array|null Dữ liệu điều chỉnh hoặc null nếu lỗi
     */
    public function getAdjustments(): ?array
    {
        return $this->requestJson('GET', '/feedback/adjustments');
    }

    /** Record downstream interview/hiring outcomes as stronger offline labels. */
    public function sendOutcome(
        int $candidateId,
        string $stage,
        string $outcome,
        ?float $score = null,
        string $note = '',
        ?string $recordedBy = null,
        array $meta = [],
    ): ?array {
        return $this->requestJson('POST', '/outcomes', [
            'candidate_id' => $candidateId,
            'stage' => $stage,
            'outcome' => $outcome,
            'score' => $score,
            'note' => $note,
            'recorded_by' => $recordedBy,
            'meta' => $meta,
        ]);
    }

    /** Try the Mac endpoint first, then each configured fallback. */
    private function requestJson(string $method, string $path, array $payload = [], int $timeout = 10): ?array
    {
        foreach (app(AutoRecruitEndpointResolver::class)->urls() as $url) {
            try {
                $request = Http::connectTimeout((int) config('services.autorecruit.connect_timeout', 5))
                    ->timeout($timeout)
                    ->acceptJson();
                $response = strtoupper($method) === 'GET'
                    ? $request->get($url.$path)
                    : $request->post($url.$path, $payload);
            } catch (\Throwable $exception) {
                Log::warning('Resume AI request failed; trying fallback', [
                    'endpoint' => $url,
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $data = $response->json();
            if ($response->successful() && is_array($data)) {
                return $data;
            }

            Log::warning('Resume AI returned an invalid response; trying fallback', [
                'endpoint' => $url,
                'path' => $path,
                'status' => $response->status(),
            ]);
        }

        return null;
    }
}
