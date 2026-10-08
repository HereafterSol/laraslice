<?php

namespace LaraSlice\Core\Audit\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraSlice\Core\Audit\AuditLogger;

/**
 * Trait AuditableSlice
 *
 * Enterprise Full-Lifecycle Auditing for LaraSlice:
 * 1. Intrinsic Temporal & Userstamps:
 *    - Creation: created_at, created_by
 *    - Mutation: updated_at, updated_by
 *    - Soft Deletion: deleted_at, deleted_by
 * 2. Immutable Event Ledger: Captures full attribute diffs in laraslice_audit_logs.
 */
trait AuditableSlice
{
    /**
     * Whether auditing is temporarily disabled for this model instance.
     */
    protected bool $auditDisabled = false;

    /**
     * Boot the AuditableSlice trait for the model.
     */
    public static function bootAuditableSlice(): void
    {
        // 1. Automatically stamp created_by & updated_by on creating
        static::creating(function ($model) {
            if (auth()->check()) {
                $userId = auth()->id();
                if (empty($model->created_by) && $model->hasAuditColumn('created_by')) {
                    $model->created_by = $userId;
                }
                if (empty($model->updated_by) && $model->hasAuditColumn('updated_by')) {
                    $model->updated_by = $userId;
                }
            }
        });

        // 2. Automatically stamp updated_by on updating
        static::updating(function ($model) {
            if (auth()->check() && $model->hasAuditColumn('updated_by')) {
                $model->updated_by = auth()->id();
            }
        });

        // 3. Automatically stamp deleted_by on soft deletion
        static::deleting(function ($model) {
            if (auth()->check() && $model->hasAuditColumn('deleted_by')) {
                // If the model uses SoftDeletes, stamp deleted_by
                if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true) || method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                    $model->deleted_by = auth()->id();
                    $model->saveQuietly();
                }
            }
        });

        // 4. Reset deleted_by on restore
        if (method_exists(static::class, 'restoring')) {
            static::restoring(function ($model) {
                if ($model->hasAuditColumn('deleted_by')) {
                    $model->deleted_by = null;
                }
            });
        }

        // 5. Immutable Audit Event Log Records
        static::created(function ($model) {
            if (! $model->shouldAudit('created')) {
                return;
            }

            $newValues = $model->filterAuditAttributes($model->getAttributes());

            AuditLogger::record([
                'slice'       => $model->getAuditSlice(),
                'action'      => 'created',
                'entity_type' => get_class($model),
                'entity_id'   => $model->getKey(),
                'old_values'  => null,
                'new_values'  => $newValues,
                'metadata'    => $model->getAuditMetadata('created'),
            ]);
        });

        static::updated(function ($model) {
            if (! $model->shouldAudit('updated')) {
                return;
            }

            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if (empty($changes)) {
                return;
            }

            $oldValues = [];
            $newValues = [];

            foreach ($changes as $key => $newValue) {
                if ($model->isAuditExcluded($key)) {
                    continue;
                }

                $oldValues[$key] = $model->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            if (empty($newValues)) {
                return;
            }

            AuditLogger::record([
                'slice'       => $model->getAuditSlice(),
                'action'      => 'updated',
                'entity_type' => get_class($model),
                'entity_id'   => $model->getKey(),
                'old_values'  => $oldValues,
                'new_values'  => $newValues,
                'metadata'    => $model->getAuditMetadata('updated'),
            ]);
        });

        static::deleted(function ($model) {
            if (! $model->shouldAudit('deleted')) {
                return;
            }

            $rawAttributes = method_exists($model, 'getOriginal') && !empty($model->getOriginal())
                ? $model->getOriginal()
                : $model->getAttributes();

            $oldValues = $model->filterAuditAttributes($rawAttributes);

            AuditLogger::record([
                'slice'       => $model->getAuditSlice(),
                'action'      => method_exists($model, 'isForceDeleting') && $model->isForceDeleting() ? 'force_deleted' : 'deleted',
                'entity_type' => get_class($model),
                'entity_id'   => $model->getKey(),
                'old_values'  => $oldValues,
                'new_values'  => null,
                'metadata'    => $model->getAuditMetadata('deleted'),
            ]);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                if (! $model->shouldAudit('restored')) {
                    return;
                }

                AuditLogger::record([
                    'slice'       => $model->getAuditSlice(),
                    'action'      => 'restored',
                    'entity_type' => get_class($model),
                    'entity_id'   => $model->getKey(),
                    'old_values'  => null,
                    'new_values'  => $model->filterAuditAttributes($model->getAttributes()),
                    'metadata'    => $model->getAuditMetadata('restored'),
                ]);
            });
        }
    }

    /**
     * Relationship: User who created this record.
     */
    public function creator(): BelongsTo
    {
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        return $this->belongsTo($userModel, 'created_by');
    }

    /**
     * Relationship: User who last updated this record.
     */
    public function updater(): BelongsTo
    {
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        return $this->belongsTo($userModel, 'updated_by');
    }

    /**
     * Relationship: User who soft-deleted this record.
     */
    public function deleter(): BelongsTo
    {
        $userModel = config('auth.providers.users.model', \App\Models\User::class);
        return $this->belongsTo($userModel, 'deleted_by');
    }

    /**
     * Determine if a column exists on this model's table or fillable.
     */
    public function hasAuditColumn(string $column): bool
    {
        if (in_array($column, $this->getFillable(), true) || array_key_exists($column, $this->attributes)) {
            return true;
        }

        try {
            return \LaraSlice\Support\SchemaCache::hasColumn($this->getTable(), $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Execute a callback with auditing temporarily disabled.
     */
    public function withoutAuditing(callable $callback): mixed
    {
        $previous = $this->auditDisabled;
        $this->auditDisabled = true;

        try {
            return $callback($this);
        } finally {
            $this->auditDisabled = $previous;
        }
    }

    /**
     * Determine if the action should be audited.
     */
    public function shouldAudit(string $action): bool
    {
        if ($this->auditDisabled) {
            return false;
        }

        if (isset($this->auditEnabled) && ! $this->auditEnabled) {
            return false;
        }

        return true;
    }

    /**
     * Determine the slice name for this model.
     */
    public function getAuditSlice(): string
    {
        if (isset($this->auditSlice) && !empty($this->auditSlice)) {
            return (string) $this->auditSlice;
        }

        $className = get_class($this);

        if (preg_match('/Slices\\\\([^\\\\]+)/', $className, $matches)) {
            return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $matches[1]));
        }

        if (method_exists($this, 'getTable')) {
            return $this->getTable();
        }

        return 'global';
    }

    /**
     * Filter out excluded or sensitive attributes.
     */
    public function filterAuditAttributes(array $attributes): array
    {
        $filtered = [];
        foreach ($attributes as $key => $value) {
            if (! $this->isAuditExcluded($key)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    /**
     * Check if a specific attribute is excluded from auditing.
     */
    public function isAuditExcluded(string $key): bool
    {
        $defaults = [
            'password',
            'remember_token',
            'api_token',
            'token',
            'secret',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'updated_at',
        ];

        if (in_array($key, $defaults, true)) {
            return true;
        }

        if (isset($this->auditExclude) && is_array($this->auditExclude)) {
            if (in_array($key, $this->auditExclude, true)) {
                return true;
            }
        }

        if (method_exists($this, 'getHidden') && in_array($key, $this->getHidden(), true)) {
            return true;
        }

        return false;
    }

    /**
     * Get optional metadata to record with the audit entry.
     */
    public function getAuditMetadata(string $action): ?array
    {
        return null;
    }

    /**
     * Query audit logs for this specific entity instance.
     */
    public function getAuditTrail(int $limit = 50)
    {
        return AuditLogger::forEntity(get_class($this), $this->getKey(), $limit);
    }
}