<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Porting;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Porting\SliceDataPortService;
use LaraSlice\Tests\TestCase;

/**
 * A prefixed port: with laraslice.porting.table_prefix set,
 * imported rows land in {prefix}{table} while framework auth
 * tables (users) still merge into the host's own tables and
 * the identity remap rewrites user references inside the
 * PREFIXED tables. Without the prefix the same import targets
 * the plain table names, exactly as before.
 */
final class TablePrefixImportTest extends TestCase
{
    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ws_tasks');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name')->nullable();
        });
        Schema::create('ws_tasks', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('heading')->nullable();
        });
        DB::table('users')->insert([
            ['id' => 1, 'email' => 'admin@example.com', 'name' => 'Admin'],
            ['id' => 5, 'email' => 'worker@example.com', 'name' => 'Worker'],
        ]);

        $this->sourceFile = sys_get_temp_dir().'/ls-prefix-source-'.uniqid().'.sqlite';
        touch($this->sourceFile);
        Config::set('database.connections.laraslice_source', [
            'driver' => 'sqlite',
            'database' => $this->sourceFile,
            'prefix' => '',
        ]);
        DB::purge('laraslice_source');
        $source = DB::connection('laraslice_source');
        $source->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR, name VARCHAR)');
        $source->statement('CREATE TABLE tasks (id INTEGER PRIMARY KEY, user_id INTEGER, heading VARCHAR)');
        $source->table('users')->insert([
            ['id' => 2, 'email' => 'worker@example.com', 'name' => 'Worker'],
            ['id' => 3, 'email' => 'boss@example.com', 'name' => 'Boss'],
        ]);
        $source->table('tasks')->insert([
            ['id' => 1, 'user_id' => 2, 'heading' => 'Prefixed task'],
        ]);
    }

    public function test_prefixed_import_lands_in_prefixed_tables_and_remaps(): void
    {
        Config::set('laraslice.porting.table_prefix', 'ws_');

        $result = (new SliceDataPortService)->importTables(['users', 'tasks'], 'laraslice_source');

        // users merged into the HOST table (never prefixed)
        $this->assertSame(3, DB::table('users')->count());
        // tasks landed in ws_tasks, nothing in a plain tasks table
        $this->assertSame('imported', $result['results']['tasks']['status']);
        $this->assertSame('ws_tasks', $result['results']['tasks']['target_table']);
        $row = DB::table('ws_tasks')->first();
        $this->assertSame('Prefixed task', $row->heading);
        // identity remap ran against the prefixed table: source
        // user 2 (worker@) is target user 5
        $this->assertSame(5, (int) $row->user_id);
    }

    public function test_unprefixed_import_still_requires_the_plain_table(): void
    {
        $result = (new SliceDataPortService)->importTables(['tasks'], 'laraslice_source');
        $this->assertSame('not_in_target', $result['results']['tasks']['status']);
    }
}
