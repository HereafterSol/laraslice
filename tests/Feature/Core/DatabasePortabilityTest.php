<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Ai\McpServer;
use LaraSlice\Tests\TestCase;

class DatabasePortabilityTest extends TestCase
{
    public function test_mcp_schema_tool_serves_the_declared_schema_on_sqlite(): void
    {
        // Portability intent, new contract: since Phase B the
        // tool serves the projector's schema contract — the
        // slice's DECLARED fields from its Heart — not a live
        // database introspection by table-name matching.
        // Declaration truth is database-independent, which is
        // what makes the answer identical on SQLite and MySQL.
        $result = app(McpServer::class)->callTool('get_slice_schema', ['slice' => 'users']);

        $this->assertSame('laraslice/slice-schema', $result['schema']);
        $this->assertSame('operations.users', $result['id']);
        $names = array_column($result['fields'], 'name');
        $this->assertContains('phone', $names);
        $this->assertArrayHasKey('phone', $result['json_schema']['properties']);
    }

    public function test_rolling_back_the_package_never_drops_the_host_users_table(): void
    {
        $user = $this->makeUser();
        $migration = require dirname(__DIR__, 3).'/src/Slices/Users/Migrations/2026_01_01_000001_create_laraslice_users_table.php';

        Schema::disableForeignKeyConstraints();
        $migration->down();
        Schema::enableForeignKeyConstraints();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasColumn('users', 'avatar_url'));
        $this->assertSame($user->email, DB::table('users')->where('id', $user->id)->value('email'));
    }
}
