<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\TestCase;

class SchemaSyncSafetyTest extends TestCase
{
    private string $slicesPath;
    private string $slicePath;
    private SliceModifier $modifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laraslice-sync-' . bin2hex(random_bytes(6));
        $this->slicePath = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice', [
            ['name' => 'reference', 'type' => 'string', 'label' => 'Reference'],
            ['name' => 'notes', 'type' => 'text', 'label' => 'Notes'],
            ['name' => 'amount', 'type' => 'integer', 'label' => 'Amount'],
        ]);
        $this->modifier = new SliceModifier($this->slicesPath, 'App\\Slices');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->slicesPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->slicesPath);
        parent::tearDown();
    }

    public function test_core_columns_cannot_be_dropped(): void
    {
        foreach (['id', 'title', 'status', 'created_by', 'updated_at'] as $column) {
            try {
                $this->modifier->syncFields('Invoice', 'invoices', [], [$column]);
                $this->fail("Dropping {$column} must be refused.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($column, $e->getMessage());
            }
        }

        $this->assertSame([], glob($this->slicePath . '/Migrations/*sync_schema*'));
    }

    public function test_dropping_a_column_writes_a_restoring_down_and_updates_model_and_rules(): void
    {
        $this->modifier->syncFields('Invoice', 'invoices', [], ['notes', 'amount']);

        $migration = file_get_contents(glob($this->slicePath . '/Migrations/*sync_schema*')[0]);
        $this->assertStringContainsString("dropColumn('notes')", $migration);
        $this->assertStringContainsString("\$table->text('notes')->nullable()", $migration, 'down() recreates the column');
        $this->assertStringNotContainsString('definition unknown', $migration);
        $this->assertStringContainsString("\$table->integer('amount')->nullable()", $migration);
        token_get_all($migration, TOKEN_PARSE);

        $model = file_get_contents($this->slicePath . '/Models/Invoice.php');
        $this->assertStringNotContainsString("'notes'", $model);
        $this->assertStringContainsString("'reference'", $model);

        $service = file_get_contents($this->slicePath . '/Services/InvoiceSliceService.php');
        $this->assertStringNotContainsString("'notes' =>", $service);
        $this->assertStringContainsString("'reference' =>", $service);
        token_get_all($service, TOKEN_PARSE);
    }

    public function test_rollback_refuses_migration_paths_outside_the_slice(): void
    {
        $manifestFile = $this->slicePath . '/slice.json';
        $manifest = json_decode(file_get_contents($manifestFile), true);
        $manifest['version'] = '1.0.1';
        $manifest['version_history'][] = ['version' => '1.0.1', 'migration' => '../../../evil.php', 'description' => 'x'];
        file_put_contents($manifestFile, json_encode($manifest));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid migration file');
        $this->modifier->rollbackVersion('Invoice', '1.0.0');
    }

    public function test_a_failing_down_stops_the_rollback_and_keeps_the_manifest(): void
    {
        $manifestFile = $this->slicePath . '/slice.json';
        $manifest = json_decode(file_get_contents($manifestFile), true);
        $manifest['version'] = '1.0.1';
        $manifest['version_history'][] = ['version' => '1.0.1', 'migration' => '2026_01_01_000000_broken.php', 'description' => 'broken'];
        file_put_contents($manifestFile, json_encode($manifest));
        file_put_contents($this->slicePath . '/Migrations/2026_01_01_000000_broken.php', "<?php\nreturn new class { public function down(): void { throw new \\RuntimeException('cannot drop'); } };\n");
        $before = file_get_contents($manifestFile);

        try {
            $this->modifier->rollbackVersion('Invoice', '1.0.0');
            $this->fail('Expected the rollback to stop.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot drop', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents($manifestFile));
    }
}
