<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalaryDetail extends Model
{
    use Auditable, BelongsToTenant;

    const TENANT_ENTITY_SCOPED = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gross_salary' => 'decimal:4',
            'net_salary' => 'decimal:4',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SalaryPeriod::class, 'period_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function payslipDocument(): HasOne
    {
        return $this->hasOne(PayslipDocument::class);
    }
}
