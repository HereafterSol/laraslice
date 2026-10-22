<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Porting;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Porting\SliceDataPortService;
use LaraSlice\Tests\TestCase;

/**
 * The identity remap: merged users receive FRESH target ids during
 * a port, so imported tables must have their user references
 * rewritten from source ids to target ids by email — the manual
 * repair this replaces once rewrote 509 cells by hand after a
 * real port left every actor column pointing at the wrong people.
 */
final class IdentityRemapTest extends TestCase
{
    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Target tables (the app DB): users keep their own ids.
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name')->nullable();
        });
        Schema::create('attendances', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('added_by')->nullable();
            $table->string('note')->nullable();
        });
        DB::table('users')->insert([
            ['id' => 1, 'email' => 'admin@example.com', 'name' => 'Admin'],
            // Same person as source user 2, but a DIFFERENT target id.
            ['id' => 5, 'email' => 'worker@example.com', 'name' => 'Worker'],
        ]);

        // Source DB (separate sqlite file): worker is id 2, boss id 3.
        $this->sourceFile = sys_get_temp_dir().'/ls-source-'.uniqid().'.sqlite';
        touch($this->sourceFile);
        Config::set('database.connections.laraslice_source', [
            'driver' => 'sqlite',
            'database' => $this->sourceFile,
            'prefix' => '',
        ]);
        DB::purge('laraslice_source');
        $source = DB::connection('laraslice_source');
        $source->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR, name VARCHAR)');
        $source->statement('CREATE TABLE attendances (id INTEGER PRIMARY KEY, user_id INTEGER, added_by INTEGER, note VARCHAR)');
        $source->table('users')->insert([
            ['id' => 2, 'email' => 'worker@example.com', 'name' => 'Worker'],
            ['id' => 3, 'email' => 'boss@example.com', 'name' => 'Boss'],
        ]);
        $source->table('attendances')->insert([
            ['id' => 1, 'user_id' => 2, 'added_by' => 3, 'note' => 'shift A'],
            ['id' => 2, 'user_id' => 3, 'added_by' => 2, 'note' => 'shift B'],
            ['id' => 3, 'user_id' => 99, 'added_by' => null, 'note' => 'unknown user stays untouched'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('laraslice_source');
        @unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_imported_user_references_are_remapped_by_email(): void
    {
        $result = (new SliceDataPortService)->importTables(['users', 'attendances'], 'laraslice_source');

        $bossTargetId = (int) DB::table('users')->where('email', 'boss@example.com')->value('id');
        $this->assertGreaterThan(0, $bossTargetId);
        $this->assertNotSame(3, $bossTargetId, 'merged users must receive a fresh target id for this test to mean anything');

        $rows = DB::table('attendances')->orderBy('id')->get();
        // worker: source 2 -> target 5; boss: source 3 -> fresh target id.
        $this->assertSame(5, (int) $rows[0]->user_id);
        $this->assertSame($bossTargetId, (int) $rows[0]->added_by);
        $this->assertSame($bossTargetId, (int) $rows[1]->user_id);
        $this->assertSame(5, (int) $rows[1]->added_by);
        // Values with no matching source user are left alone.
        $this->assertSame(99, (int) $rows[2]->user_id);

        $this->assertSame(2, $result['identity_remap']['attendances']['user_id'] ?? null);
        $this->assertSame(2, $result['identity_remap']['attendances']['added_by'] ?? null);
    }
}
