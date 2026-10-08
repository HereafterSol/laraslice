<?php

namespace LaraSlice\Core\Actions;

use Illuminate\Support\Facades\DB;
use LaraSlice\Core\Audit\AuditLogger;
use Throwable;

/**
 * Enterprise Base Action for Atomic Transactions & Compliance Audit Trails.
 *
 * Guarantees that multi-table ERP mutations either fully commit or rollback,
 * with automatic immutable audit logging.
 */
abstract class TransactionalAction
{
    /**
     * Slice identifier for compliance categorization.
     */
    protected string $slice = 'global';

    /**
     * Execute the action inside a verified database transaction.
     *
     * @param  mixed  ...$arguments
     *
     * @throws Throwable
     */
    public function execute(...$arguments): mixed
    {
        return DB::transaction(function () use ($arguments) {
            $result = $this->handle(...$arguments);

            // Capture compliance audit trail
            $auditPayload = $this->auditData($result, $arguments);
            if ($auditPayload !== null) {
                AuditLogger::record(array_merge([
                    'slice' => $this->slice,
                    'action' => class_basename(static::class),
                ], $auditPayload));
            }

            return $result;
        });
    }

    /**
     * Business logic to be implemented by child vertical slice actions.
     */
    abstract protected function handle(...$arguments): mixed;

    /**
     * Prepare data payload for audit log. Can be overridden by child actions.
     */
    protected function auditData(mixed $result, array $arguments): ?array
    {
        return [
            'entity_type' => is_object($result) ? get_class($result) : null,
            'entity_id' => is_object($result) && isset($result->id) ? $result->id : null,
            'metadata' => [
                'action_class' => static::class,
                'status' => 'success',
            ],
        ];
    }
}
