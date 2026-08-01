<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewSchedule extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // A date-only field must not be serialized as a UTC timestamp.
            'interview_date' => 'date:Y-m-d',
            'meta' => 'array',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCandidate::class, 'candidate_id');
    }
}
