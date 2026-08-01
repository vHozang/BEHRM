<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecruitmentCandidate extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'ai_matched_skills_json' => 'array',
            'ai_missing_skills_json' => 'array',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPosition::class, 'recruitment_position_id');
    }

    public function interviews()
    {
        return $this->hasMany(InterviewSchedule::class, 'candidate_id');
    }

    public function cv(): HasOne
    {
        return $this->hasOne(RecruitmentCandidateCv::class, 'candidate_id');
    }

    public function isInProcess(): bool
    {
        return in_array($this->application_status, [
            'PENDING', 'SCREENING', 'INTERVIEWING', 'OFFERED',
            'CHỜ_XỬ_LÝ', 'ĐANG_PHỎNG_VẤN',
        ], true);
    }

    public function deletionViolations(): array
    {
        $violations = [];

        if ($this->isInProcess()) {
            $violations[] = 'Không thể xóa ứng viên đang trong quy trình tuyển dụng';
        }

        if ($this->interviews()->whereIn('status', ['SCHEDULED', 'ĐÃ_LÊN_LỊCH'])->exists()) {
            $violations[] = 'Không thể xóa ứng viên đang có lịch phỏng vấn';
        }

        return $violations;
    }
}
