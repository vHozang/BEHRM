<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tenant-aware configuration resolver.
 *
 * Three layers (highest first):
 *   1. Per-tenant override  → system_configs row (config_key = dotted path).
 *   2. Statutory/app default → config/hrm.php (config("hrm.$key")).
 *   3. Caller-supplied default.
 *
 * Scalar overrides live in system_configs.config_value (typed by config_type:
 * int|float|bool|string); structured overrides (arrays/objects such as PIT
 * brackets, insurance rates, checklist task lists) live in meta->value with
 * config_type = 'json'. This lets every business rule be changed per tenant from
 * the Settings UI without a code change, while the VN statutory baseline remains
 * in config as a safe fallback.
 */
class HrmConfig
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if (TenantContext::hasTenant()) {
            $row = DB::table('system_configs')
                ->where('tenant_id', TenantContext::id())
                ->where('config_key', $key)
                ->first();

            if ($row) {
                return self::decode($row);
            }
        }

        return config("hrm.$key", $default);
    }

    /**
     * Upsert a tenant override. $value may be scalar or array/object (stored as
     * json in meta->value). Returns nothing; safe no-op without a tenant.
     */
    public static function set(string $key, mixed $value): void
    {
        if (! TenantContext::hasTenant()) {
            return;
        }

        $isStructured = is_array($value) || is_object($value);
        $type = $isStructured ? 'json' : match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            default => 'string',
        };

        $payload = [
            'config_type' => $type,
            'config_value' => $isStructured ? null : ($type === 'bool' ? ($value ? '1' : '0') : (string) $value),
            'meta' => $isStructured ? json_encode(['value' => $value]) : null,
            'updated_at' => now(),
        ];

        $existing = DB::table('system_configs')
            ->where('tenant_id', TenantContext::id())
            ->where('config_key', $key)
            ->first();

        if ($existing) {
            DB::table('system_configs')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('system_configs')->insert(TenantContext::stamp(array_merge($payload, [
                'config_key' => $key,
                'created_at' => now(),
            ])));
        }
    }

    private static function decode(object $row): mixed
    {
        if (($row->config_type ?? null) === 'json') {
            $meta = is_string($row->meta ?? null) ? json_decode($row->meta, true) : (array) ($row->meta ?? []);
            if (is_array($meta) && array_key_exists('value', $meta)) {
                return $meta['value'];
            }

            return json_decode((string) $row->config_value, true);
        }

        $v = $row->config_value;

        return match ($row->config_type ?? 'string') {
            'int' => (int) $v,
            'float' => (float) $v,
            'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            default => $v,
        };
    }
}
