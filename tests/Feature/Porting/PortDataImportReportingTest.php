<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Porting;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Porting\SliceDataPortService;
use LaraSlice\Porting\SlicePortService;
use LaraSlice\Tests\TestCase;

/**
 * Port data-import reporting + ordering guarantees.
 *
 * Regression coverage for two confirmed porting defects:
 *
 *  #12 — ETL before migrations. A combined scaffold + import run
 *  streamed rows into tables that did not exist yet and reported
 *  success with 0 rows. Missing target tables are now RECORDED in
 *  the import result (missing_target_tables), and port flows run
 *  SlicePortService::runTargetMigrations() before importing.
 *
 *  #13 — Silent auth skip. Without the auth opt-in, the ETL skips
 *  roles/permissions/role_user, so imported users arrive with no
 *  role assignments while the port looks successful. The import
 *  result now carries a loud auth_warning naming the remedy.
 */
final class PortDataImportReportingTest extends TestCase
{
    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tasks');
        Schema::dropIfExists('users');
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name')->nullable();
        });

        $this->sourceFile = sys_get_temp_dir().'/ls-port-report-source-'.uniqid().'.sqlite';
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
        $source->statement('CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR)');
        $source->table('users')->insert([
            ['id' => 1, 'email' => 'worker@example.com', 'name' => 'Worker'],
            ['id' => 2, 'email' => 'boss@example.com', 'name' => 'Boss'],
        ]);
        $source->table('tasks')->insert([
            ['id' => 1, 'user_id' => 1, 'heading' => 'Ported task'],
        ]);
        $source->table('roles')->insert([
            ['id' => 1, 'name' => 'manager'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->sourceFile) && is_file($this->sourceFile)) {
            unlink($this->sourceFile);
        }
        parent::tearDown();
    }

    public function test_missing_target_tables_are_reported_not_silent(): void
    {
        // Target has users but NO tasks table (migrations not run yet).
        $result = (new SliceDataPortService)->importTables(['users', 'tasks'], 'laraslice_source');

        // users merged normally...
        $this->assertSame(2, DB::table('users')->count());
        // ...while tasks is recorded as missing — never a silent 0-row success.
        $this->assertSame('not_in_target', $result['results']['tasks']['status']);
        $this->assertSame(['tasks'], $result['missing_target_tables']);
        $this->assertFalse(Schema::hasTable('tasks'));
    }

    public function test_auth_skip_warning_names_the_remedy(): void
    {
        $result = (new SliceDataPortService)->importTables(['roles', 'users'], 'laraslice_source');

        $this->assertSame('skipped_system_auth', $result['results']['roles']['status']);
        $this->assertNotNull($result['auth_warning']);
        $this->assertStringContainsString('roles', $result['auth_warning']);
        $this->assertStringContainsString('no role assignments', $result['auth_warning']);
        $this->assertStringContainsString('--import-auth', $result['auth_warning']);
    }

    public function test_no_auth_warning_when_auth_import_is_enabled(): void
    {
        $service = (new SliceDataPortService)->setImportAuth(true);
        $result = $service->importTables(['roles', 'users'], 'laraslice_source');

        $this->assertSame('normalized_merge', $result['results']['roles']['status']);
        $this->assertNull($result['auth_warning']);
        $this->assertTrue(DB::table('roles')->where('slug', 'manager')->exists());
    }

    public function test_run_target_migrations_creates_slice_tables_before_import(): void
    {
        $slicesDir = sys_get_temp_dir().'/ls-mig-order-'.uniqid();
        $migDir = $slicesDir.'/ProbeSlice/Migrations';
        mkdir($migDir, 0777, true);
        file_put_contents($migDir.'/2026_01_01_000000_create_ported_probe_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ported_probe', function (Blueprint $table) {
            $table->increments('id');
            $table->string('heading')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ported_probe');
    }
};
PHP);

        try {
            Config::set('laraslice.slices_path', $slicesDir);
            $this->assertFalse(Schema::hasTable('ported_probe'));

            $run = (new SlicePortService)->runTargetMigrations();

            $this->assertSame([], $run['errors']);
            $this->assertContains($migDir, $run['paths']);
            // The table the ETL would stream into now exists.
            $this->assertTrue(Schema::hasTable('ported_probe'));
        } finally {
            Schema::dropIfExists('ported_probe');
            $this->app['files']->deleteDirectory($slicesDir);
        }
    }
}
