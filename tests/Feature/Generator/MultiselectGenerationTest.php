<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\FieldDefinitionNormalizer;
use LaraSlice\Generator\SliceFieldDefinitionException;
use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Table MultiSelect seat: a multiselect field in the fields
 * map must generate the picker vertical — BO id-array
 * property, service multiselectDefinitions() with the four
 * seat keys, a form rendering the kit combobox in multiple
 * mode with the _multiselects marker — and NO column on the
 * parent table. The declaration itself is validated: a
 * picker missing a seat key is rejected at normalize time.
 */
class MultiselectGenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-ms-'.bin2hex(random_bytes(8));
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

    public function test_field_map_multiselect_generates_the_picker_vertical(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Deal', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
            ['name' => 'lost_reasons', 'type' => 'multiselect', 'label' => 'Lost Reasons',
                'table' => 'deal_lost_reasons', 'foreign_key' => 'deal_id',
                'target_field' => 'lost_reason', 'target' => 'LostReasons',
                'parent_type' => 'Deal', 'section' => 'Outcome'],
        ], false, ['table' => 'deals']);

        $dto = file_get_contents($path.'/Contracts/DealFormBusinessObject.php');
        $this->assertStringContainsString('public array $lost_reasons = [];', $dto);
        $this->assertStringNotContainsString('public ?string $lost_reasons', $dto);

        $service = file_get_contents($path.'/Services/DealSliceService.php');
        $this->assertStringContainsString('function multiselectDefinitions()', $service);
        $this->assertStringContainsString("'deal_lost_reasons'", $service);
        $this->assertStringContainsString("'target_field' => 'lost_reason'", $service);
        $this->assertStringContainsString("'target' => 'LostReasons'", $service);
        $this->assertStringContainsString("'parent_type' => 'Deal'", $service);

        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('<x-ui.combobox', $form);
        $this->assertStringContainsString('name="lost_reasons"', $form);
        $this->assertStringContainsString('multiple', $form);
        $this->assertStringContainsString('name="_multiselects[]" value="lost_reasons"', $form);
        $this->assertStringContainsString('$lostReasonsOptions', $form);

        $migration = glob($path.'/Migrations/*.php')[0];
        $this->assertStringNotContainsString("'lost_reasons'", file_get_contents($migration));

        $heart = file_get_contents($path.'/slice.yaml');
        $this->assertStringContainsString('multiselect', $heart);
        $this->assertStringContainsString('target_field', $heart);
        $this->assertStringContainsString('deal_lost_reasons', $heart);
    }

    public function test_slice_without_multiselect_has_no_multiselect_artifacts(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Note', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
        ]);

        $service = file_get_contents($path.'/Services/NoteSliceService.php');
        $this->assertStringNotContainsString('multiselectDefinitions', $service);
        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        $this->assertStringNotContainsString('_multiselects', $form);
    }

    public function test_multiselect_declaration_requires_all_seat_keys(): void
    {
        $normalizer = new FieldDefinitionNormalizer;

        foreach (['table', 'foreign_key', 'target_field', 'target'] as $missing) {
            $field = ['name' => 'picks', 'type' => 'multiselect', 'label' => 'Picks',
                'table' => 'deal_picks', 'foreign_key' => 'deal_id',
                'target_field' => 'reason', 'target' => 'Reasons'];
            unset($field[$missing]);
            try {
                $normalizer->normalize([$field]);
                $this->fail("Missing {$missing} must reject the declaration.");
            } catch (SliceFieldDefinitionException $e) {
                $this->assertStringContainsString($missing, $e->getMessage());
            }
        }

        $ok = $normalizer->normalize([['name' => 'picks', 'type' => 'multiselect', 'label' => 'Picks',
            'table' => 'deal_picks', 'foreign_key' => 'deal_id',
            'target_field' => 'reason', 'target' => 'Reasons']]);
        $this->assertSame('', $ok[0]['form']);
        $this->assertFalse($ok[0]['show_in_list']);
        $this->assertSame('deal_picks', $ok[0]['table']);
        $this->assertSame('reason', $ok[0]['target_field']);
        $this->assertSame('Reasons', $ok[0]['target']);
    }
}
