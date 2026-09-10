<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'onboarding_tasks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'done_at' => 'datetime',
            'due_date' => 'date:Y-m-d',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(OnboardingChecklist::class, 'checklist_id');
    }
}
