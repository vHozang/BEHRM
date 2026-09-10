<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level tenant record. NOT tenant-scoped (it IS the tenant).
 */
class Tenant extends Model
{
    protected $table = 'tenants';

    protected $fillable = [
        'name',
        'code',
        'status',
        'locale',
        'currency',
        'timezone',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function legalEntities(): HasMany
    {
        return $this->hasMany(LegalEntity::class);
    }
}
