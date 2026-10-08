<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\TestCase;

class ChildTableRollbackTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laraslice-child-rollback-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->slicesPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->slicesPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->slicesPath);
        }
        parent::tearDown();
    }

    /** @return array<string, string> relative path => contents */
    private function snapshot(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($dir))] = file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    public function test_a_failure_part_way_through_restores_the_slice_exactly(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice');
        $before = $this->snapshot($path);

        // Let every write happen, then fail at the end
        $modifier = new class($this->slicesPath, 'App\\Slices') extends SliceModifier {
            protected function performAddChildTable(string $sliceName, string $tableName, string $relationType = 'hasMany', array $fields = [], ?string $foreignKey = null): array
            {
                parent::performAddChildTable($sliceName, $tableName, $relationType, $fields, $foreignKey);
                throw new \RuntimeException('simulated failure after all writes');
            }
        };

        try {
            $modifier->addChildTable('Invoice', 'invoice_lines', 'hasMany', [['name' => 'quantity', 'type' => 'integer']]);
            $this->fail('Expected the simulated failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure after all writes', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot($path));
    }

    public function test_a_successful_child_table_is_kept(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice');

        (new SliceModifier($this->slicesPath, 'App\\Slices'))->addChildTable('Invoice', 'invoice_lines', 'hasMany', [['name' => 'quantity', 'type' => 'integer']]);

        $this->assertFileExists($path . '/Models/InvoiceLine.php');
        $this->assertNotEmpty(glob($path . '/Migrations/*invoice_lines*'));
    }
}
