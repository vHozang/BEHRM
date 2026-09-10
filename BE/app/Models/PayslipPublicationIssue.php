<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipPublicationIssue extends Model
{
    use Auditable, BelongsToTenant;

    public const TENANT_ENTITY_SCOPED = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SalaryPeriod::class, 'salary_period_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PayslipDocument::class, 'payslip_document_id');
    }
}
