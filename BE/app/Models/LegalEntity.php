<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalEntity extends Model
{
    use Auditable, BelongsToTenant;

    // Tenant-scoped only: it has tenant_id but NO legal_entity_id of its own.
    // Do NOT set TENANT_ENTITY_SCOPED here.

    protected $table = 'legal_entities';

    protected $fillable = [
        'name',
        'code',
        'tax_code',
        'address',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
