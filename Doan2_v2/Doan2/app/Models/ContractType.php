<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ContractType extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'contract_types';

    protected $guarded = ['id'];
}
