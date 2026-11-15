<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 generator seats: declared record dialogs (D-015),
 * form tabs (D-046), the settings-anatomy single form class
 * (D-027), the record-state banner (D-069), the tree
 * directory index (D-064), rich-text fields (D-054),
 * label_depends_on wiring (D-019) and section descriptions
 * + collapsed state (D-066/D-049). Each is emitted from the
 * Heart declaration alone.
 */
class Phase6GenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-p6-'.bin2hex(random_bytes(8));
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

    private function dealFields(): array
    {
        return [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
            ['name' => 'status', 'type' => 'string', 'label' => 'Status', 'options' => ['open' => 'Open', 'lost' => 'Lost']],
            ['name' => 'enabled', 'type' => 'boolean', 'label' => 'Enabled'],
            ['name' => 'party_name', 'type' => 'integer', 'label' => 'Party', 'section' => 'Outcome',
                'label_depends_on' => ['field' => 'status', 'map' => ['open' => 'Prospect', 'lost' => 'Former Party']]],
            ['name' => 'lost_reason', 'type' => 'string', 'label' => 'Detailed Reason', 'section' => 'Outcome'],
            ['name' => 'body', 'type' => 'richtext', 'label' => 'Body', 'section' => 'Content'],
        ];
    }

    public function test_dialogs_tabs_banner_and_field_seats_are_emitted(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Deal', $this->dealFields(), false, [
            'table' => 'deals',
            'ui' => [
                'dialogs' => [[
                    'name' => 'set_as_lost', 'label' => 'Set as Lost',
                    'trigger' => ['field' => 'status', 'value' => 'lost'],
                    'fields' => ['lost_reason'], 'confirm_label' => 'Declare Lost',
                ]],
                'form_tabs' => [
                    ['label' => 'Details', 'sections' => ['General Information']],
                    ['label' => 'Content', 'sections' => ['Content']],
                ],
                'form_sections' => ['Content' => ['description' => 'Long-form body.', 'collapsed' => true]],
                'state_banner' => ['field' => 'enabled', 'equals' => false, 'message' => 'This Deal is disabled.', 'pill' => 'Disabled'],
            ],
        ]);

        $form = file_get_contents($path.'/Resources/views/form.blade.php');

        // D-015: the dialog state, markup, confirm + cancel wiring.
        $this->assertStringContainsString('lsDlgSetAsLost', $form);
        $this->assertStringContainsString('Set as Lost', $form);
        $this->assertStringContainsString('Declare Lost', $form);
        $this->assertStringContainsString('ls-dialog-cancel-set_as_lost', $form);

        // D-046: tabs wrap the declared sections.
        $this->assertStringContainsString('lsFormTab', $form);
        $this->assertStringContainsString('>Details<', $form);
        $this->assertStringContainsString('>Content<', $form);

        // D-066/D-049: section description + collapsed seat.
        $this->assertStringContainsString('Long-form body.', $form);
        $this->assertStringContainsString('lsSectionOpen', $form);

        // D-069: the state banner condition + message.
        $this->assertStringContainsString('This Deal is disabled.', $form);
        $this->assertStringContainsString('$form->enabled', $form);

        // D-019: the dynamic label wiring.
        $this->assertStringContainsString('data-ls-label-map', $form);

        // D-054: rich text renders the editor, not a plain input.
        $this->assertStringContainsString('contenteditable', $form);
    }

    public function test_dialog_arming_and_stepped_conversion_are_emitted(): void
    {
        // D-071: a declared dialog's header action ARMS its
        // trigger (the button carries data-ls-dialog-arm) and
        // the confirm submits the _dialog marker the save path
        // applies the dialog's effects from.
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Deal', $this->dealFields(), false, [
            'table' => 'deals',
            'ui' => [
                'dialogs' => [[
                    'name' => 'set_as_lost', 'label' => 'Set as Lost',
                    'trigger' => ['field' => 'status', 'value' => 'lost'],
                    'fields' => ['lost_reason'], 'confirm_label' => 'Declare Lost',
                ]],
            ],
        ]);
        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('data-ls-dialog-arm="set_as_lost"', $form);
        $this->assertStringContainsString('name="_dialog" value="set_as_lost"', $form);

        // D-073: a conversion with pre_steps renders the step
        // dialog (checkbox per step + the runner helper), not
        // a plain menu link.
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Lead', $this->dealFields(), false, [
            'table' => 'leads',
            'conversions' => [[
                'target' => 'Opportunities', 'label' => 'Opportunity',
                'pre_steps' => [[
                    'name' => 'create_prospect', 'slice' => 'Prospects', 'default' => true,
                    'name_field' => 'company_name', 'name_label' => 'Prospect Name',
                ]],
            ]],
        ]);
        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        // The step dialog markup is emitted into the template
        // (its steps resolve at runtime via RecordConverter::forSlice).
        $this->assertStringContainsString('data-ls-step-check="{{ $lsStep[\'name\'] }}"', $form);
        $this->assertStringContainsString('lsRunConversion', $form);
    }

    public function test_single_slices_get_the_flat_settings_sheet(): void
    {
        $fields = [['name' => 'title', 'type' => 'string', 'label' => 'Title']];
        $single = (new SliceGenerator($this->slicesPath.'/a', 'App\\Slices'))->generate('DealSetting', $fields, false, [
            'table' => 'deal_settings', 'ui' => ['single' => true],
        ]);
        $carded = (new SliceGenerator($this->slicesPath.'/b', 'App\\Slices'))->generate('Deal', $fields, false, [
            'table' => 'deals',
        ]);

        $singleForm = file_get_contents($single.'/Resources/views/form.blade.php');
        $cardedForm = file_get_contents($carded.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('class="ls-form-sheet w-full{{', $singleForm);
        $this->assertStringContainsString('class="ls-form-sheet bg-card border', $cardedForm);
    }

    public function test_tree_directory_wraps_the_index_table(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('DealGroup', [
            ['name' => 'group_name', 'type' => 'string', 'label' => 'Group Name'],
            ['name' => 'parent_group', 'type' => 'integer', 'label' => 'Parent Group'],
            ['name' => 'is_group', 'type' => 'boolean', 'label' => 'Is Group'],
        ], false, [
            'table' => 'deal_groups',
            'ui' => ['directory' => ['tree' => ['parent_field' => 'parent_group', 'title_field' => 'group_name', 'group_field' => 'is_group']]],
        ]);

        $index = file_get_contents($path.'/Resources/views/index.blade.php');
        $this->assertStringContainsString("@include('laraslice::partials.directory-tree')", $index);
        $this->assertStringContainsString("['view' => 'tree']", $index);
        $this->assertStringContainsString("['view' => 'list']", $index);
    }
}
