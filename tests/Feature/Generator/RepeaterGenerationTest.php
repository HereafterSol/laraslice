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

    public function test_field_map_repeater_generates_the_same_vertical(): void
    {
        // The field dialect: a Table field carried in the
        // fields map (the ported-settings shape) must produce
        // the identical vertical as the ui.forms declaration —
        // and no column on the parent table.
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('BookingSetting', [
            ['name' => 'duration', 'type' => 'integer', 'label' => 'Duration'],
            ['name' => 'slots', 'type' => 'repeater', 'label' => 'Slots', 'table' => 'booking_slots', 'foreign_key' => 'booking_setting_id', 'section' => 'Scheduling', 'depends_on' => ['field' => 'duration', 'not_empty' => true], 'repeater_fields' => [
                ['handle' => 'day_of_week', 'label' => 'Day', 'type' => 'string', 'required' => true],
                ['handle' => 'from_time', 'label' => 'From', 'type' => 'string'],
            ]],
        ], false, ['table' => 'booking_settings']);

        $dto = file_get_contents($path.'/Contracts/BookingSettingFormBusinessObject.php');
        $this->assertStringContainsString('public array $slots = [];', $dto);
        $this->assertStringNotContainsString('public ?string $slots', $dto);

        $model = file_get_contents($path.'/Models/BookingSetting.php');
        $this->assertStringContainsString('function slots()', $model);
        $this->assertStringContainsString('HasMany', $model);

        $service = file_get_contents($path.'/Services/BookingSettingSliceService.php');
        $this->assertStringContainsString('function repeaterDefinitions()', $service);
        $this->assertStringContainsString("'booking_slots'", $service);
        $this->assertStringContainsString("'day_of_week'", $service);

        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('<x-ui.repeater', $form);
        $this->assertStringContainsString("old('slots'", $form);
        // The declared depends_on wraps the grid in the same
        // behaviour field-wrapper scalar inputs carry.
        $this->assertStringContainsString('data-ls-field="slots"', $form);
        $this->assertStringContainsString('data-ls-depends-on=', $form);

        $migration = glob($path.'/Migrations/*.php')[0];
        $this->assertStringNotContainsString("'slots'", file_get_contents($migration));

        $heart = file_get_contents($path.'/slice.yaml');
        $this->assertStringContainsString('repeater_fields', $heart);
    }

    public function test_repeater_sub_field_target_survives_normalization_heart_form_and_service(): void
    {
        // D-030: a repeater sub-field may declare `target`
        // (the slice its Link points at). The key must ride
        // the whole chain — normalizer, Heart, service
        // definitions and the rendered component call — while
        // an undeclared sub-field keeps the historical shape.
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Order', [
            ['name' => 'reference', 'type' => 'string', 'label' => 'Reference'],
            ['name' => 'lines', 'type' => 'repeater', 'label' => 'Lines', 'table' => 'order_lines', 'foreign_key' => 'order_id', 'repeater_fields' => [
                ['handle' => 'company', 'label' => 'Company', 'type' => 'integer', 'required' => true, 'target' => 'Companies'],
                ['handle' => 'memo', 'label' => 'Memo', 'type' => 'string'],
            ]],
        ], false, ['table' => 'orders']);

        $heart = file_get_contents($path.'/slice.yaml');
        $this->assertStringContainsString('Companies', $heart);
        $this->assertStringContainsString('target', $heart);

        $service = file_get_contents($path.'/Services/OrderSliceService.php');
        $this->assertStringContainsString("'target' => 'Companies'", $service);

        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        $this->assertStringContainsString("'target' => 'Companies'", $form);
        // Exactly one sub-field declares a target.
        $this->assertSame(1, substr_count($form, "'target' =>"));
    }

    public function test_repeater_sub_field_target_must_be_a_slice_name(): void
    {
        $this->expectException(\LaraSlice\Generator\SliceFieldDefinitionException::class);
        (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Order', [
            ['name' => 'lines', 'type' => 'repeater', 'label' => 'Lines', 'table' => 'order_lines', 'foreign_key' => 'order_id', 'repeater_fields' => [
                ['handle' => 'company', 'label' => 'Company', 'type' => 'integer', 'target' => 'not a slice!'],
            ]],
        ], false, ['table' => 'orders']);
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
