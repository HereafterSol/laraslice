<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * F3: relations declared in the manifest (models[].relations, passed to
 * the generator via options) must become real methods on the root
 * model — detail-page relation tabs and $model->relation access call
 * them. Before F3 the generator ignored declared relations entirely,
 * so ported models declared relations they did not implement.
 */
class DeclaredRelationsGenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-f3-'.bin2hex(random_bytes(8));
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

    public function test_declared_relations_become_model_methods_without_duplicates(): void
    {
        $ui = ['forms' => ['sections' => [
            ['key' => 'items_items', 'title' => 'Items', 'grid' => 1, 'fields' => [[
                'handle' => 'items',
                'type' => 'repeater',
                'label' => 'Items',
                'model' => 'invoice_item',
                'table' => 'invoice_items',
                'foreign_key' => 'invoice_id',
                'repeater_fields' => [
                    ['handle' => 'name', 'label' => 'Name', 'type' => 'string', 'required' => true],
                ],
            ]]],
        ]]];

        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice', [
            ['name' => 'invoice_number', 'type' => 'string', 'label' => 'Invoice Number'],
            ['name' => 'customer_id', 'type' => 'foreign_id', 'label' => 'Customer'],
        ], false, [
            'table' => 'invoices',
            'ui' => $ui,
            'relations' => [
                // Same handle as the repeater: must NOT double-emit items().
                ['name' => 'items', 'type' => 'hasMany', 'model' => 'invoice_item', 'foreign_key' => 'invoice_id'],
                ['name' => 'payments', 'type' => 'hasMany', 'model' => 'payment', 'foreign_key' => 'invoice_id'],
                ['name' => 'currency', 'type' => 'belongsTo', 'model' => 'currency', 'foreign_key' => 'currency_code'],
            ],
        ]);

        $model = file_get_contents($path.'/Models/Invoice.php');

        $this->assertSame(1, substr_count($model, 'function items('), 'repeater relation must be emitted exactly once');
        $this->assertSame(1, substr_count($model, 'function payments('));
        $this->assertStringContainsString('function currency()', $model);
        $this->assertStringContainsString("hasMany(\$class, 'invoice_id')", $model);
        $this->assertStringContainsString("belongsTo(\$class, 'currency_code')", $model);
        // The FK-field belongsTo for customer_id still generates.
        $this->assertSame(1, substr_count($model, 'function customer('));

        token_get_all($model, TOKEN_PARSE);
    }

    public function test_slice_without_declared_relations_is_unchanged(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Note', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
        ]);

        $model = file_get_contents($path.'/Models/Note.php');
        $this->assertStringNotContainsString('function payments(', $model);
        token_get_all($model, TOKEN_PARSE);
    }
}
