<?php

namespace App\Models\Concerns;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Records an immutable audit trail for create / update / delete on the model.
 *
 * On boot it registers created/updated/deleted observers that forward to
 * App\Support\AuditLogger::log with the model's table, primary key, and the
 * relevant attribute snapshot:
 *
 *  - created: full new attributes (getAttributes())
 *  - updated: only the changed columns, before (getOriginal()) and after
 *             (getChanges())
 *  - deleted: full final attributes (getOriginal())
 *
 * AuditLogger is fully self-guarding: it never throws, strips secret columns,
 * and refuses to audit the audit_logs table itself, so there is no recursion
 * and a logging failure can never break the primary write.
 *
 * A model can opt out of auditing by declaring either:
 *
 *     public bool $auditable = false;   // property, OR
 *     const AUDITABLE = false;          // class constant
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            if (! $model->isAuditingEnabled()) {
                return;
            }

            AuditLogger::log(
                'create',
                $model->getTable(),
                $model->auditRecordId(),
                null,
                $model->getAttributes(),
            );
        });

        static::updated(function (Model $model): void {
            if (! $model->isAuditingEnabled()) {
                return;
            }

            $changed = $model->getChanges();

            // Nothing actually changed (e.g. touch with no dirty columns).
            if (empty($changed)) {
                return;
            }

            $original = $model->getOriginal();
            $before = [];
            foreach (array_keys($changed) as $key) {
                $before[$key] = $original[$key] ?? null;
            }

            AuditLogger::log(
                'update',
                $model->getTable(),
                $model->auditRecordId(),
                $before,
                $changed,
            );
        });

        static::deleted(function (Model $model): void {
            if (! $model->isAuditingEnabled()) {
                return;
            }

            AuditLogger::log(
                'delete',
                $model->getTable(),
                $model->auditRecordId(),
                $model->getOriginal(),
                null,
            );
        });
    }

    /**
     * Whether auditing is currently enabled for this model instance.
     * Resolved from $auditable property or AUDITABLE class constant.
     */
    public function isAuditingEnabled(): bool
    {
        if (property_exists($this, 'auditable')) {
            return (bool) $this->auditable;
        }

        if (defined(static::class . '::AUDITABLE')) {
            return (bool) constant(static::class . '::AUDITABLE');
        }

        return true;
    }

    /**
     * Primary key value as a nullable int for the audit record_id column.
     */
    protected function auditRecordId(): ?int
    {
        $key = $this->getKey();

        return $key !== null && is_numeric($key) ? (int) $key : null;
    }
}
