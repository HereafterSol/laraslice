<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Porting\SlicePortService;
use PHPUnit\Framework\TestCase;

/**
 * F2: a repeater (line-item) field in the UI config must generate the
 * full vertical: DTO collection property, model hasMany, service
 * repeater definitions, and a form that renders existing rows. Before
 * F2 the porter emitted repeaters with empty sub-fields and submitted
 * rows were silently dropped.
 */
class RepeaterGenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-f2-'.bin2hex(random_bytes(8));
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

    private function repeaterUi(): array
    {
        return ['forms' => ['sections' => [
            ['key' => 'general', 'title' => 'General', 'grid' => 2, 'fields' => [
                ['handle' => 'invoice_number', 'span' => 1],
            ]],
            ['key' => 'items_items', 'title' => 'Items', 'grid' => 1, 'fields' => [[
                'handle' => 'items',
                'type' => 'repeater',
                'label' => 'Items',
                'model' => 'invoice_item',
                'table' => 'invoice_items',
                'foreign_key' => 'invoice_id',
                'repeater_fields' => [
                    ['handle' => 'item_name', 'label' => 'Item Name', 'type' => 'string', 'required' => true],
                    ['handle' => 'quantity', 'label' => 'Quantity', 'type' => 'integer', 'required' => true],
                    ['handle' => 'price', 'label' => 'Price', 'type' => 'decimal', 'required' => true],
                ],
                'add_button_text' => 'Add Item',
            ]]],
        ]]];
    }

    public function test_repeater_generates_dto_model_service_and_form_support(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice', [
            ['name' => 'invoice_number', 'type' => 'string', 'label' => 'Invoice Number', 'required' => true],
            ['name' => 'total', 'type' => 'decimal', 'label' => 'Total'],
        ], false, ['table' => 'invoices', 'ui' => $this->repeaterUi()]);

        $dto = file_get_contents($path.'/Contracts/InvoiceFormBusinessObject.php');
        $this->assertStringContainsString('public array $items = [];', $dto);

        $model = file_get_contents($path.'/Models/Invoice.php');
        $this->assertStringContainsString('function items()', $model);
        $this->assertStringContainsString('HasMany', $model);
        $this->assertStringContainsString("'invoice_id'", $model);

        $service = file_get_contents($path.'/Services/InvoiceSliceService.php');
        $this->assertStringContainsString('function repeaterDefinitions()', $service);
        $this->assertStringContainsString("'invoice_items'", $service);
        $this->assertStringContainsString("'item_name'", $service);

        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('<x-ui.repeater', $form);
        $this->assertStringContainsString("'item_name'", $form);
        $this->assertStringContainsString(":initial-items=\"old('items', \$form->items ?? [])\"", $form);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
            }
        }
    }

    public function test_slice_without_repeater_has_no_repeater_artifacts(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Note', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
        ]);

        $service = file_get_contents($path.'/Services/NoteSliceService.php');
        $this->assertStringNotContainsString('repeaterDefinitions', $service);
        $dto = file_get_contents($path.'/Contracts/NoteFormBusinessObject.php');
        $this->assertStringNotContainsString('public array $', $dto);
    }

    public function test_porter_ui_builder_populates_repeater_fields_from_relation(): void
    {
        $service = new SlicePortService;
        $ui = $service->buildUiFromInspectedFields('Invoices', [
            ['handle' => 'invoice_number', 'label' => 'Invoice Number', 'type' => 'string'],
        ], 'company_id', [[
            'name' => 'invoice_items',
            'type' => 'hasMany',
            'model' => 'invoice_item',
            'table' => 'invoice_items',
            'foreign_key' => 'invoice_id',
            'fields' => [
                ['handle' => 'item_name', 'label' => 'Item Name', 'type' => 'string', 'required' => true],
                ['handle' => 'quantity', 'label' => 'Quantity', 'type' => 'integer', 'required' => true],
            ],
        ]]);

        $repeater = null;
        foreach ($ui['forms']['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (($field['type'] ?? '') === 'repeater') {
                    $repeater = $field;
                }
            }
        }

        $this->assertNotNull($repeater, 'UI config must contain a repeater field for the hasMany relation');
        $this->assertSame('invoice_items', $repeater['table']);
        $this->assertSame('invoice_id', $repeater['foreign_key']);
        $this->assertSame(['item_name', 'quantity'], array_column($repeater['repeater_fields'], 'handle'));
    }
}
