<?php

namespace LaraSlice\Tests\Unit\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\SchemaCache;
use LaraSlice\Tests\TestCase;

/**
 * SchemaCache must never cache a NEGATIVE: a table probed before
 * it exists (install, migration, a database rebuilt under a
 * running server) has to become visible the moment it is created,
 * without a process restart. Regression pin for the post-port
 * audit (2 Nov 2026): the stuck negative silently suppressed
 * audit-trail writes on the ported app for a whole server life.
 */
class SchemaCacheTest extends TestCase
{
    private string $table = 'laraslice_schema_cache_probe';

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->table);
        SchemaCache::flush();
        parent::tearDown();
    }

    public function test_a_table_created_after_a_negative_probe_becomes_visible(): void
    {
        SchemaCache::flush();
        $this->assertFalse(SchemaCache::hasTable($this->table));

        Schema::create($this->table, function (Blueprint $t) {
            $t->id();
            $t->string('probe_name')->nullable();
        });

        $this->assertTrue(SchemaCache::hasTable($this->table));
        $this->assertTrue(SchemaCache::hasColumn($this->table, 'probe_name'));
        $this->assertFalse(SchemaCache::hasColumn($this->table, 'nope'));
    }
}
