<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class EmployeeRole extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_date' => 'date',
            'expiry_date' => 'date',
        ];
    }
}
