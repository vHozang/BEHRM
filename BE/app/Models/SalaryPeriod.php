<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryPeriod extends Model
{
    use Auditable, BelongsToTenant;

    const TENANT_ENTITY_SCOPED = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function salaryDetails(): HasMany
    {
        return $this->hasMany(SalaryDetail::class, 'period_id');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['CLOSED', 'ĐÃ_ĐÓNG', 'DA_DONG'], true);
    }
}
