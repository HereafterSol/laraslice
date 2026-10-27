<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * S-UI 2: the standard list pattern renders declared filters
 * behind a Filters button opening a right drawer (the reference
 * ERP's two-layer filtering), instead of an inline strip.
 */
class FilterDrawerGenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-sui2-'.bin2hex(random_bytes(8));
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

    public function test_index_renders_filters_button_and_drawer_when_filters_declared(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Ticket', [
            ['name' => 'subject', 'type' => 'string', 'label' => 'Subject'],
            ['name' => 'status', 'type' => 'string', 'label' => 'Status'],
        ], false, ['table' => 'tickets', 'ui' => ['directory' => [
            'sidebar_filters' => [
                ['handle' => 'status', 'label' => 'Status', 'options' => [
                    ['value' => 'open', 'label' => 'Open'],
                    ['value' => 'closed', 'label' => 'Closed'],
                ]],
            ],
        ]]]);

        $index = file_get_contents($path.'/Resources/views/index.blade.php');
        // The Filters button is generated inline, gated on the
        // declared set being non-empty; the drawer itself is the
        // package partial every directory composes.
        $this->assertStringContainsString('data-ls-filters-btn', $index);
        $this->assertStringContainsString("!empty(\$sidebarFilters", $index);
        $this->assertStringContainsString("@include('laraslice::directory.filters-drawer')", $index);

        $drawer = file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/directory/filters-drawer.blade.php');
        // The drawer is gated on the declared set being non-empty:
        // slices without sidebar_filters never render it.
        $this->assertStringContainsString("!empty(\$sidebarFilters", $drawer);
        $this->assertStringContainsString('Apply Filters', $drawer);
        // Declared filter fields render as named controls.
        $this->assertStringContainsString('name="{{ $sfHandle }}"', $drawer);
    }
}
