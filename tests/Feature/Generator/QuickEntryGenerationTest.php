<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * Quick-entry dialog seat (D-011): a slice declaring
 * ui.quick_entry.fields emits a directory that includes
 * the framework's quick-entry partial (the modal with
 * exactly the declared subset + Edit Full Form), and the
 * runtime resolver returns exactly the eligible subset —
 * repeater / multiselect / unknown handles in the
 * declaration are ignored (ignore-with-warning policy).
 * A slice without the declaration emits no modal markup.
 */
class QuickEntryGenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-qe-'.bin2hex(random_bytes(8));
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

    public function test_declared_slice_emits_quick_entry_include_and_resolves_exact_subset(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Probe Contact', [
            ['name' => 'first_name', 'type' => 'string', 'label' => 'First Name'],
            ['name' => 'phone', 'type' => 'string', 'label' => 'Phone'],
            ['name' => 'status', 'type' => 'string', 'label' => 'Status',
                'options' => ['open' => 'Open', 'closed' => 'Closed']],
            ['name' => 'picks', 'type' => 'multiselect', 'label' => 'Picks',
                'table' => 'probe_picks', 'foreign_key' => 'probe_contact_id',
                'target_field' => 'reason', 'target' => 'Reasons'],
        ], false, [
            'table' => 'probe_contacts',
            'ui' => ['quick_entry' => ['fields' => ['first_name', 'phone', 'status', 'picks', 'ghost_field']]],
        ]);

        $index = file_get_contents($path.'/Resources/views/index.blade.php');
        $this->assertStringContainsString("@include('laraslice::directory.quick-entry')", $index);

        $manifest = ManifestRepository::readFromSliceDir($path);
        $this->assertSame(['first_name', 'phone', 'status', 'picks', 'ghost_field'], $manifest['ui']['quick_entry']['fields'] ?? null);

        // Runtime resolution: exactly the eligible subset, in
        // declared order — the multiselect and the unknown
        // handle are ignored, never rendered.
        $resolved = DirectoryUi::quickEntryFields($manifest);
        $this->assertSame(['first_name', 'phone', 'status'], array_column($resolved, 'handle'));
        $status = $resolved[2];
        $this->assertSame('select', $status['input']);
        $this->assertSame(
            [['value' => 'open', 'label' => 'Open'], ['value' => 'closed', 'label' => 'Closed']],
            $status['options']
        );

        // The shared partial carries the dialog contract:
        // same-store form, Save, and the Edit Full Form link.
        $partial = file_get_contents(__DIR__.'/../../../src/Wizard/views/directory/quick-entry.blade.php');
        $this->assertStringContainsString('data-ls-quick-entry-form', $partial);
        $this->assertStringContainsString('$quickEntryStoreUrl', $partial);
        $this->assertStringContainsString('Edit Full Form', $partial);
        $this->assertStringContainsString('data-ls-quick-entry-full', $partial);
    }

    public function test_slice_without_declaration_emits_no_quick_entry_markup(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Plain Note', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
        ]);

        $index = file_get_contents($path.'/Resources/views/index.blade.php');
        $this->assertStringNotContainsString('quick-entry', $index);
        $this->assertStringNotContainsString('quick_entry', $index);

        $manifest = ManifestRepository::readFromSliceDir($path);
        $this->assertSame([], DirectoryUi::quickEntryFields($manifest));
    }

    public function test_relation_field_options_come_from_controller_resolved_options(): void
    {
        $manifest = [
            'fields' => [
                'country_id' => ['name' => 'country_id', 'label' => 'Country', 'type' => 'integer', 'nullable' => false, 'options' => []],
                'is_primary' => ['name' => 'is_primary', 'label' => 'Is Primary', 'type' => 'boolean', 'nullable' => true, 'options' => []],
            ],
            'ui' => ['quick_entry' => ['fields' => ['country_id', 'is_primary']]],
        ];

        $resolved = DirectoryUi::quickEntryFields($manifest, [
            'countryOptions' => [7 => 'Pakistan', 9 => 'Australia'],
        ]);

        $this->assertSame('select', $resolved[0]['input']);
        $this->assertTrue($resolved[0]['required']);
        $this->assertSame(
            [['value' => 7, 'label' => 'Pakistan'], ['value' => 9, 'label' => 'Australia']],
            $resolved[0]['options']
        );
        $this->assertSame('checkbox', $resolved[1]['input']);
    }
}
