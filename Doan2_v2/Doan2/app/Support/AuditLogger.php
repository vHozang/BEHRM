<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records immutable audit trail rows for create/update/delete actions.
 *
 * Safe to call from anywhere: never throws, works without a request/tenant,
 * strips secret keys, and never audits the audit_logs table itself.
 */
class AuditLogger
{
    /**
     * Column names whose values must never be persisted into the audit trail.
     * Matching is case-insensitive.
     */
    private const SECRET_KEYS = [
        'password',
        'password_hash',
        'remember_token',
        'token',
        'token_hash',
        'secret',
        'api_key',
    ];

    /**
     * Insert an audit row.
     *
     * @param  string  $action  create|update|delete
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     * @param  array<string,mixed>|null  $context  optional extra metadata (unused for now)
     */
    public static function log(
        string $action,
        string $table,
        ?int $recordId,
        ?array $before,
        ?array $after,
        ?array $context = null
    ): void {
        // Never recurse: do not audit writes to the audit table itself.
        if ($table === 'audit_logs') {
            return;
        }

        try {
            $before = $before !== null ? self::stripSecrets($before) : null;
            $after = $after !== null ? self::stripSecrets($after) : null;

            $changes = [];
            if ($before !== null) {
                $changes['before'] = $before;
            }
            if ($after !== null) {
                $changes['after'] = $after;
            }

            $actorId = null;
            $ip = null;

            // request() may be unavailable (console/queue) — guard everything.
            if (function_exists('request') && app()->bound('request')) {
                $request = request();
                $actorId = $request->attributes->get('auth_employee_id');
                $actorId = $actorId !== null ? (int) $actorId : null;
                $ip = $request->ip();
            }

            DB::table('audit_logs')->insert([
                'tenant_id' => TenantContext::id(),
                'actor_employee_id' => $actorId,
                'action' => $action,
                'table_name' => $table,
                'record_id' => $recordId,
                'changes' => empty($changes) ? null : json_encode($changes),
                'ip_address' => $ip,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the primary operation.
        }
    }

    /**
     * Compute changed-only before/after maps for an update.
     *
     * Returns ['before' => [...], 'after' => [...]] containing only the keys
     * whose values differ between the two snapshots.
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array{before: array<string,mixed>, after: array<string,mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];

        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;

            // Loose comparison after stringifying scalars handles DB string<->int drift.
            if (self::normalize($oldValue) !== self::normalize($newValue)) {
                $changedBefore[$key] = array_key_exists($key, $before) ? $before[$key] : null;
                $changedAfter[$key] = $newValue;
            }
        }

        return ['before' => $changedBefore, 'after' => $changedAfter];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function stripSecrets(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (in_array(strtolower((string) $key), self::SECRET_KEYS, true)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value === null ? null : (string) $value;
        }

        return json_encode($value);
    }
}
