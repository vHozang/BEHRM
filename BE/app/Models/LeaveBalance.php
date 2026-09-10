<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_days' => 'decimal:4',
            'used_days' => 'decimal:4',
            'remaining_days' => 'decimal:4',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
