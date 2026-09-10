<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'total_hours' => 'decimal:4',
            'meta' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
