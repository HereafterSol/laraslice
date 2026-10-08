<?php

namespace LaraSlice\Tests\Unit\Audit;

use Illuminate\Support\Collection;
use LaraSlice\Core\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase
{
    public function test_audit_logger_records_without_crashing_when_no_db_table(): void
    {
        // Must never throw exception even in un-migrated or unit test environments
        $error = null;
        try {
            AuditLogger::record([
                'slice' => 'orders',
                'action' => 'created',
                'actor_id' => 42,
                'actor_email' => 'admin@laraslice.com',
                'entity_type' => 'App\\Slices\\Orders\\Models\\Order',
                'entity_id' => 101,
                'new_values' => ['total' => 299.99, 'status' => 'pending'],
            ]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertNull($error, 'AuditLogger::record threw: '.$error);
    }

    public function test_for_slice_query_returns_collection_gracefully(): void
    {
        $logs = AuditLogger::forSlice('orders');
        $this->assertInstanceOf(Collection::class, $logs);
    }

    public function test_for_entity_query_returns_collection_gracefully(): void
    {
        $logs = AuditLogger::forEntity('Order', 101);
        $this->assertInstanceOf(Collection::class, $logs);
    }

    public function test_recent_query_returns_collection_gracefully(): void
    {
        $logs = AuditLogger::recent(10);
        $this->assertInstanceOf(Collection::class, $logs);
    }
}
