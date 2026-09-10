<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ShiftType extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
