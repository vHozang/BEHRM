<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Request-scoped holder for the current tenant / legal entity.
 *
 * Resolved by HrmAuth once an authenticated employee is loaded. When nothing
 * has been resolved (console, migrations, unauthenticated public writes) the
 * configured default tenant is used as a fallback so existing public routes
 * keep working.
 */
class TenantContext
{
    protected static ?int $tenantId = null;

    protected static ?int $legalEntityId = null;

    protected static bool $resolved = false;

    public static function set(int $tenantId, ?int $legalEntityId = null): void
    {
        if (self::$resolved && (self::$tenantId !== $tenantId || self::$legalEntityId !== $legalEntityId)) {
            HrmConfig::flushMemoized();
        }
        self::$tenantId = $tenantId;
        self::$legalEntityId = $legalEntityId;
        self::$resolved = true;
    }

    /**
     * Clear the resolved tenant (mainly useful for tests / queue workers).
     */
    public static function clear(): void
    {
        HrmConfig::flushMemoized();
        self::$tenantId = null;
        self::$legalEntityId = null;
        self::$resolved = false;
    }

    /**
     * True only when an explicit tenant has been resolved (e.g. by HrmAuth).
     * Reads use this to decide whether to scope queries.
     */
    public static function hasTenant(): bool
    {
        return self::$resolved && self::$tenantId !== null;
    }

    /**
     * The resolved tenant id, or the configured default when none is set.
     */
    public static function id(): ?int
    {
        if (self::$resolved && self::$tenantId !== null) {
            return self::$tenantId;
        }

        return (int) config('hrm.default_tenant_id', 1);
    }

    /**
     * The resolved legal entity id, or the configured default when none is set.
     */
    public static function legalEntityId(): ?int
    {
        if (self::$resolved && self::$legalEntityId !== null) {
            return self::$legalEntityId;
        }

        return (int) config('hrm.default_legal_entity_id', 1);
    }

    /**
     * Stamp tenant_id (and legal_entity_id when $entityScoped) onto an
     * insert/update payload, only filling keys that are not already present.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function stamp(array $payload, bool $entityScoped = false): array
    {
        if (! array_key_exists('tenant_id', $payload) || $payload['tenant_id'] === null) {
            $payload['tenant_id'] = self::id();
        }

        if ($entityScoped && (! array_key_exists('legal_entity_id', $payload) || $payload['legal_entity_id'] === null)) {
            $payload['legal_entity_id'] = self::legalEntityId();
        }

        return $payload;
    }

    /**
     * True when a row identified by $id in $table exists AND belongs to the
     * current tenant. Used to reject cross-tenant foreign keys that the bare
     * Laravel `exists:` rule (which is not tenant-scoped) would let through.
     *
     * When no tenant is resolved (console/public) the check degrades to a plain
     * existence test so non-tenant flows keep working.
     */
    public static function ownsRow(string $table, $id): bool
    {
        if ($id === null || $id === '') {
            return true; // nothing to validate (nullable FK)
        }

        $query = DB::table($table)->where('id', $id);

        if (self::hasTenant() && Schema::hasColumn($table, 'tenant_id')) {
            $query->where('tenant_id', self::id());
        }

        return $query->exists();
    }
}
