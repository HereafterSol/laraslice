<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Blueprint;

use Illuminate\Support\Facades\Schema;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Tests\TestCase;
use RuntimeException;

/**
 * OBS-A (Phase 2 closing observation, Day-2 drill programme):
 * the Studio applier's auto_migrate ran only the app's default
 * migrate path — a generated slice's migrations live in its own
 * Migrations directory, which is not on it — then reported
 * "migrations were automatically executed" while the slice's
 * table did not exist; real failures were downgraded to a
 * warning under the success headline. The CLI apply got the
 * F9 fix in Phase 1; this pins the Studio twin:
 *  - the applied slice's own Migrations directory runs;
 *  - success is reported only after every planned create_table
 *    is verified to exist;
 *  - a planned table that does not exist after migrating is a
 *    loud failure naming the table.
 */
final class StudioApplyMigrationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/ls-studio-apply-'.uniqid();
        mkdir($this->tmp.'/Migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function migrateAppliedSlice(string $target, array $plan): string
    {
        // app() + reflection, per StudioStabilityTest: directly
        // instantiating a subclass of this controller dies in its
        // middleware-registering constructor outside the router.
        $controller = app(BlueprintStudioController::class);
        $method = new \ReflectionMethod($controller, 'migrateAppliedSlice');
        $method->setAccessible(true);

        return $method->invoke($controller, $target, $plan);
    }

    public function test_studio_migrate_runs_the_slices_own_migrations_and_verifies_the_table(): void
    {
        file_put_contents($this->tmp.'/Migrations/2026_11_08_000001_create_studio_widgets_table.php', <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('studio_widgets', function (Blueprint $table) {
                        $table->id();
                        $table->string('label');
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('studio_widgets');
                }
            };
            PHP);

        $this->assertFalse(Schema::hasTable('studio_widgets'));

        $this->migrateAppliedSlice($this->tmp, [
            'database_operations' => [
                ['kind' => 'create_table', 'table' => 'studio_widgets'],
            ],
        ]);

        $this->assertTrue(Schema::hasTable('studio_widgets'), 'the slice-folder migration must run in the Studio apply, not only on the default path');
    }

    public function test_studio_migrate_fails_loudly_when_a_planned_table_does_not_exist(): void
    {
        try {
            $this->migrateAppliedSlice($this->tmp, [
                'database_operations' => [
                    ['kind' => 'create_table', 'table' => 'ghost_widgets'],
                ],
            ]);
            $this->fail('A planned table with no migration must fail the apply, not report success.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ghost_widgets', $e->getMessage());
            $this->assertStringContainsString('NOT usable', $e->getMessage());
        }
    }
}
