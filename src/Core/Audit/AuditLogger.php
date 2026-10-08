<?php

namespace LaraSlice\Core\Audit;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\SchemaCache;
use Throwable;

/**
 * Enterprise Audit Logger for Regulatory & Government Compliance.
 *
 * Captures immutable audit entries for all transactional slice actions.
 */
class AuditLogger
{
    public const TABLE_NAME = 'laraslice_audit_logs';

    /**
     * Check if a Laravel Application / Facade container is booted.
     */
    protected static function hasApp(): bool
    {
        try {
            return function_exists('app') && Facade::getFacadeApplication() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Safely check if the audit logs database table exists.
     */
    protected static function hasAuditTable(): bool
    {
        if (! self::hasApp()) {
            return false;
        }

        try {
            return class_exists(Schema::class) && SchemaCache::hasTable(self::TABLE_NAME);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Safely log an event without crashing if logger facade is not configured.
     */
    protected static function safeLog(string $level, string $message, array $context = []): void
    {
        if (! self::hasApp()) {
            return;
        }

        try {
            Log::$level($message, $context);
        } catch (Throwable) {
            // Ignore if log driver fails
        }
    }

    /**
     * Safely resolve the current authenticated user without throwing container exceptions.
     */
    protected static function resolveAuthUser(): mixed
    {
        try {
            if (function_exists('auth') && self::hasApp()) {
                $auth = auth();
                if ($auth && method_exists($auth, 'check') && $auth->check()) {
                    return $auth->user();
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Safely resolve the current HTTP request without throwing container exceptions.
     */
    protected static function resolveRequest(): mixed
    {
        try {
            if (function_exists('request') && self::hasApp()) {
                return request();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Record an audit trail entry.
     *
     * @param array{
     *     slice: string,
     *     action: string,
     *     actor_id?: int|string|null,
     *     actor_type?: string|null,
     *     actor_email?: string|null,
     *     entity_type?: string|null,
     *     entity_id?: int|string|null,
     *     old_values?: array|null,
     *     new_values?: array|null,
     *     ip_address?: string|null,
     *     user_agent?: string|null,
     *     metadata?: array|null
     * } $data
     */
    public static function record(array $data): void
    {
        $user = self::resolveAuthUser();
        $request = self::resolveRequest();

        $actorId = $data['actor_id'] ?? ($user ? ($user->id ?? (method_exists($user, 'getKey') ? $user->getKey() : null)) : null);
        $actorType = $data['actor_type'] ?? ($user ? get_class($user) : null);
        $actorEmail = $data['actor_email'] ?? ($user && isset($user->email) ? $user->email : null);

        $payload = [
            'slice' => $data['slice'] ?? 'global',
            'action' => $data['action'] ?? 'unknown',
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'actor_email' => $actorEmail,
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => isset($data['entity_id']) ? (string) $data['entity_id'] : null,
            'old_values' => isset($data['old_values']) ? json_encode($data['old_values']) : null,
            'new_values' => isset($data['new_values']) ? json_encode($data['new_values']) : null,
            'ip_address' => $data['ip_address'] ?? ($request && method_exists($request, 'ip') ? $request->ip() : null),
            'user_agent' => $data['user_agent'] ?? ($request && method_exists($request, 'userAgent') ? substr((string) $request->userAgent(), 0, 255) : null),
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at' => function_exists('now') ? now() : date('Y-m-d H:i:s'),
        ];

        try {
            if (self::hasAuditTable()) {
                DB::table(self::TABLE_NAME)->insert($payload);
            } else {
                self::safeLog('info', "[LaraSlice Audit] {$payload['slice']}.{$payload['action']}", $payload);
            }
        } catch (Throwable $e) {
            // Fail-safe logging so audit errors never crash business transaction
            self::safeLog('warning', '[LaraSlice Audit Failure] '.$e->getMessage(), ['payload' => $payload]);
        }
    }

    /**
     * Query audit entries for a specific slice.
     */
    public static function forSlice(string $slice, int $limit = 50): Collection
    {
        if (! self::hasAuditTable()) {
            return collect();
        }

        try {
            return DB::table(self::TABLE_NAME)
                ->where('slice', $slice)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => self::formatRow($row));
        } catch (Throwable $e) {
            self::safeLog('warning', '[LaraSlice Audit Query Failure] '.$e->getMessage());

            return collect();
        }
    }

    /**
     * Query audit entries for a specific entity.
     */
    public static function forEntity(string $entityType, int|string $entityId, int $limit = 50): Collection
    {
        if (! self::hasAuditTable()) {
            return collect();
        }

        try {
            return DB::table(self::TABLE_NAME)
                ->where('entity_type', $entityType)
                ->where('entity_id', (string) $entityId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => self::formatRow($row));
        } catch (Throwable $e) {
            self::safeLog('warning', '[LaraSlice Audit Query Failure] '.$e->getMessage());

            return collect();
        }
    }

    /**
     * Query recent system-wide audit entries.
     */
    public static function recent(int $limit = 50): Collection
    {
        if (! self::hasAuditTable()) {
            return collect();
        }

        try {
            return DB::table(self::TABLE_NAME)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => self::formatRow($row));
        } catch (Throwable $e) {
            self::safeLog('warning', '[LaraSlice Audit Query Failure] '.$e->getMessage());

            return collect();
        }
    }

    /**
     * Decode json columns into arrays for easy consumption.
     */
    protected static function formatRow(object $row): object
    {
        $cloned = clone $row;
        if (isset($cloned->old_values) && is_string($cloned->old_values)) {
            $cloned->old_values = json_decode($cloned->old_values, true);
        }
        if (isset($cloned->new_values) && is_string($cloned->new_values)) {
            $cloned->new_values = json_decode($cloned->new_values, true);
        }
        if (isset($cloned->metadata) && is_string($cloned->metadata)) {
            $cloned->metadata = json_decode($cloned->metadata, true);
        }

        return $cloned;
    }
}
