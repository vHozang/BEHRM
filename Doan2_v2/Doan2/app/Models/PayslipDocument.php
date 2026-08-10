<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipDocument extends Model
{
    use Auditable, BelongsToTenant;

    public const TENANT_ENTITY_SCOPED = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function salaryDetail(): BelongsTo
    {
        return $this->belongsTo(SalaryDetail::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SalaryPeriod::class, 'salary_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
