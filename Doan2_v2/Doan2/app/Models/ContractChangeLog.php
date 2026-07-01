<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ContractChangeLog extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id'];
}
