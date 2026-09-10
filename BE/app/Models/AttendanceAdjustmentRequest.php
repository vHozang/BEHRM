<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAdjustmentRequest extends Model
{
    use Auditable, BelongsToTenant;

    const TENANT_ENTITY_SCOPED = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'requested_check_in_time' => 'string',
            'requested_check_out_time' => 'string',
            'decided_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
