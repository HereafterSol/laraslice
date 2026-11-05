<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Porting;

use LaraSlice\Porting\SlicePortService;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * Gate S schema closure at the source: the migration inspector
 * must replay Schema::table alters from up() in filename order.
 * Reading only Schema::create blocks silently dropped every
 * later-added column from real ports (a benchmark table lost its
 * short-code and three feature columns that way), and replaying
 * down() as well would remove them again — both failure modes
 * are pinned here.
 */
final class MigrationAlterReplayTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ls-migtest-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function extract(): array
    {
        $svc = app(SlicePortService::class);
        $ref = new ReflectionMethod($svc, 'extractTablesFromMigrations');
        $ref->setAccessible(true);

        return $ref->invoke($svc, $this->dir);
    }

    public function test_alter_added_columns_join_the_table_schema(): void
    {
        file_put_contents($this->dir.'/2020_01_01_000000_create_widgets_table.php', <<<'PHP'
        <?php
        Schema::create('widgets', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('legacy_flag')->nullable();
        });
        PHP);
        file_put_contents($this->dir.'/2022_05_05_000000_add_code_to_widgets.php', <<<'PHP'
        <?php
        class AddCodeToWidgets {
            public function up() {
                Schema::table('widgets', function ($table) {
                    $table->string('widget_code')->nullable();
                    $table->dropColumn('legacy_flag');
                });
            }
            public function down() {
                Schema::table('widgets', function ($table) {
                    $table->dropColumn('widget_code');
                });
            }
        }
        PHP);

        $tables = $this->extract();
        $names = array_column($tables['widgets']['columns'] ?? [], 'name');

        $this->assertContains('widget_code', $names, 'alter-added column must be present');
        $this->assertNotContains('legacy_flag', $names, 'column dropped in up() must be gone');
    }
}
