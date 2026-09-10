<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRequest extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'requests';

    protected $guarded = ['id'];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function approvalHistories(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class, 'request_id');
    }
}
