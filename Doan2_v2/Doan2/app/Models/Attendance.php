<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Attendance extends Model
{
    use Auditable, BelongsToTenant;

    const TENANT_ENTITY_SCOPED = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // 'date:Y-m-d' serializes as a plain date ("2026-06-25") — NOT a
            // UTC datetime — so the browser doesn't shift the day across the
            // +07 boundary. (Bare 'date' caused work_date to come back as
            // "...T17:00:00Z" and display one day off.)
            'work_date' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollReview(): HasOne
    {
        return $this->hasOne(AttendancePayrollReview::class);
    }

    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }
}
