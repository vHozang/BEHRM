<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePayrollReview extends Model
{
    use Auditable, BelongsToTenant;

    public const UNRESOLVED_STATUSES = ['PENDING', 'STALE'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'default_percent' => 'integer',
            'approved_percent' => 'integer',
            'decided_at' => 'datetime',
            'stale_at' => 'datetime',
            'applied_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'decided_by');
    }
}
