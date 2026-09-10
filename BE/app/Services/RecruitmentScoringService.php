<?php

namespace App\Services;

/**
 * Service: Chấm điểm ứng viên tuyển dụng theo luật cố định (deterministic).
 *
 * KHÔNG gọi AI/network. Điểm được tính 100% từ dữ liệu sẵn có trong DB:
 *  - Kỹ năng ứng viên: recruitment_candidates.meta (skills / experience_years).
 *  - Yêu cầu vị trí:   recruitment_positions.required_skills_json (+ meta).
 *
 * Công thức (0-100):
 *   score = skillOverlapRatio * 70 + experienceAdequacy * 30
 *   - skillOverlapRatio: tỉ lệ kỹ năng yêu cầu mà ứng viên đáp ứng (so khớp
 *     token không phân biệt hoa/thường).
 *   - experienceAdequacy: số năm kinh nghiệm / số năm yêu cầu, clamp 0..1.
 * Nếu thiếu dữ liệu để tính một thành phần, thành phần đó trả về fallback hợp lý
 * thay vì làm crash; nếu thiếu cả hai phía kỹ năng thì trả về điểm mặc định 50.
 */
class RecruitmentScoringService
{
    /** Điểm mặc định khi không đủ dữ liệu để chấm. */
    public const FALLBACK_SCORE = 50;

    /**
     * Chấm điểm một ứng viên cho một vị trí.
     *
     * @param  object|array|null  $candidate  Row ứng viên (cần có ->meta jsonb).
     * @param  object|array|null  $position   Row vị trí (cần ->required_skills_json, ->meta).
     * @return array{score:int, matched_skills:array<int,string>, missing_skills:array<int,string>}
     */
    public function score($candidate, $position): array
    {
        $candidateMeta = $this->decodeJson($this->prop($candidate, 'meta'));
        $positionMeta = $this->decodeJson($this->prop($position, 'meta'));

        $candidateSkills = $this->extractSkills(
            $candidateMeta['skills'] ?? null
        );

        $requiredSkills = $this->extractSkills(
            $this->decodeJson($this->prop($position, 'required_skills_json'))
        );
        if (empty($requiredSkills)) {
            $requiredSkills = $this->extractSkills($positionMeta['required_skills'] ?? null);
        }

        // Không có thông tin kỹ năng ở cả hai phía => không đủ căn cứ chấm.
        if (empty($candidateSkills) && empty($requiredSkills)) {
            return [
                'score' => self::FALLBACK_SCORE,
                'matched_skills' => [],
                'missing_skills' => [],
            ];
        }

        [$matched, $missing, $skillRatio] = $this->matchSkills($candidateSkills, $requiredSkills);

        $experienceComponent = $this->experienceAdequacy($candidateMeta, $positionMeta);

        $raw = ($skillRatio * 70) + ($experienceComponent * 30);
        $score = (int) round(max(0, min(100, $raw)));

        return [
            'score' => $score,
            'matched_skills' => array_values($matched),
            'missing_skills' => array_values($missing),
        ];
    }

    /**
     * So khớp kỹ năng ứng viên với kỹ năng yêu cầu (case-insensitive token).
     *
     * @return array{0:array<int,string>,1:array<int,string>,2:float}
     */
    private function matchSkills(array $candidateSkills, array $requiredSkills): array
    {
        // Nếu vị trí không nêu yêu cầu kỹ năng nhưng ứng viên có khai => coi như đạt.
        if (empty($requiredSkills)) {
            return [[], [], 1.0];
        }

        $candidateTokens = array_map([$this, 'normalize'], $candidateSkills);

        $matched = [];
        $missing = [];

        foreach ($requiredSkills as $required) {
            $needle = $this->normalize($required);
            if ($needle === '') {
                continue;
            }

            if (in_array($needle, $candidateTokens, true)) {
                $matched[] = $required;
            } else {
                $missing[] = $required;
            }
        }

        $total = count($matched) + count($missing);
        $ratio = $total > 0 ? count($matched) / $total : 1.0;

        return [$matched, $missing, $ratio];
    }

    /**
     * Mức độ đáp ứng kinh nghiệm (0..1).
     * Nếu vị trí không yêu cầu số năm => trả 1.0 (không trừ điểm).
     * Nếu ứng viên không khai số năm nhưng vị trí có yêu cầu => 0.5 (fallback).
     */
    private function experienceAdequacy(array $candidateMeta, array $positionMeta): float
    {
        $required = $this->extractYears($positionMeta['required_experience_years']
            ?? $positionMeta['experience_years']
            ?? null);

        if ($required === null || $required <= 0) {
            return 1.0;
        }

        $have = $this->extractYears($candidateMeta['experience_years']
            ?? $candidateMeta['experience']
            ?? null);

        if ($have === null) {
            return 0.5;
        }

        return max(0.0, min(1.0, $have / $required));
    }

    /**
     * Chuẩn hoá một danh sách kỹ năng thành mảng string sạch.
     * Chấp nhận: array, chuỗi phân tách bởi dấu phẩy, hoặc null.
     *
     * @return array<int,string>
     */
    private function extractSkills($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/[,;\n]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $skills = [];
        foreach ($value as $item) {
            // Hỗ trợ cả mảng object dạng ['name' => 'PHP'].
            if (is_array($item)) {
                $item = $item['name'] ?? $item['skill'] ?? $item['label'] ?? null;
            }
            if (is_string($item) && trim($item) !== '') {
                $skills[] = trim($item);
            }
        }

        return $skills;
    }

    /** Chuẩn hoá token để so khớp không phân biệt hoa/thường, khoảng trắng. */
    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    /** Trích số năm kinh nghiệm từ nhiều kiểu dữ liệu, trả null nếu không đọc được. */
    private function extractYears($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/\d+(\.\d+)?/', $value, $m)) {
            return (float) $m[0];
        }

        return null;
    }

    /** Lấy thuộc tính từ object hoặc array, trả null nếu không có. */
    private function prop($source, string $key)
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        if (is_object($source)) {
            return $source->{$key} ?? null;
        }

        return null;
    }

    /** Giải mã jsonb/string thành array; trả mảng/giá trị gốc khi phù hợp. */
    private function decodeJson($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return [];
    }
}
