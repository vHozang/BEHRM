<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingChecklist extends Model
{
    use Auditable, BelongsToTenant;

    // Tenant-scoped only (no legal_entity_id).

    protected $table = 'onboarding_checklists';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'meta' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class, 'checklist_id');
    }
}
