<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Makes an Eloquent model tenant-aware:
 *
 *  - On create, fills tenant_id from TenantContext (and legal_entity_id when
 *    the model is entity-scoped) if those columns exist and are empty.
 *  - Adds a global scope filtering reads by tenant_id while a tenant is
 *    resolved. When no tenant is resolved (console / migrations) reads are
 *    not filtered.
 *
 * A model marks itself entity-scoped (needs legal_entity_id too) by declaring:
 *
 *     public bool $tenantEntityScoped = true;     // property, OR
 *     const TENANT_ENTITY_SCOPED = true;          // class constant, OR
 *     public function isTenantEntityScoped(): bool // method override
 *     {
 *         return true;
 *     }
 *
 * Bypass the read scope with Model::withoutTenantScope()->...  for
 * admin / cross-tenant platform queries.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            if (! TenantContext::hasTenant()) {
                return;
            }

            if ($model->hasTenantColumn('tenant_id') && empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', TenantContext::id());
            }

            if ($model->isTenantEntityScoped()
                && $model->hasTenantColumn('legal_entity_id')
                && empty($model->getAttribute('legal_entity_id'))) {
                $model->setAttribute('legal_entity_id', TenantContext::legalEntityId());
            }
        });
    }

    /**
     * Whether this model also needs legal_entity_id stamped (entity-scoped).
     * Resolved from $tenantEntityScoped property or TENANT_ENTITY_SCOPED const.
     */
    public function isTenantEntityScoped(): bool
    {
        if (property_exists($this, 'tenantEntityScoped')) {
            return (bool) $this->tenantEntityScoped;
        }

        if (defined(static::class . '::TENANT_ENTITY_SCOPED')) {
            return (bool) constant(static::class . '::TENANT_ENTITY_SCOPED');
        }

        return false;
    }

    /**
     * Query without the tenant global scope (admin / platform use).
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }

    protected function hasTenantColumn(string $column): bool
    {
        return in_array(
            $column,
            $this->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($this->getTable()),
            true
        );
    }
}

/**
 * Global scope that constrains reads to the resolved tenant.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! TenantContext::hasTenant()) {
            return;
        }

        $builder->where($model->getTable() . '.tenant_id', TenantContext::id());
    }
}
