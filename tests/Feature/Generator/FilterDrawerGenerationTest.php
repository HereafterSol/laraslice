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
        // Seat 3 (13 Nov 2026): the Filter button moved OUT of
        // the generated index into the package list-controls
        // partial every directory composes — it is universal
        // now (the any-field builder works with zero declared
        // filters), so the index carries the include instead of
        // the button, and the declared filterbar stays gated on
        // the declared set.
        $this->assertStringContainsString("@include('laraslice::directory.list-controls')", $index);
        $this->assertStringContainsString("!empty(\$sidebarFilters", $index);
        $this->assertStringContainsString("@include('laraslice::directory.filters-drawer')", $index);

        $controls = file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/directory/list-tools.blade.php');
        $this->assertStringContainsString('data-ls-filters-btn', $controls);
        $this->assertStringContainsString('List View', $controls);
        // The tools render inside the table's one action row
        // via the toolbar-tools slot (design correction,
        // 13 Nov 2026) — never as a separate floating row.
        $this->assertStringContainsString('<x-slot:toolbar-tools>', $index);
        $this->assertStringContainsString("@include('laraslice::directory.list-tools')", $index);

        $drawer = file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/directory/filters-drawer.blade.php');
        // The drawer renders when the builder OR the declared
        // set exists — with filter fields supplied by the
        // controller the builder is always available.
        $this->assertStringContainsString('$lsHasBuilder || $lsHasDeclared', $drawer);
        $this->assertStringContainsString('data-ls-ff-row', $drawer);
        $this->assertStringContainsString('Apply Filters', $drawer);
        // Declared filter fields render as named controls.
        $this->assertStringContainsString('name="{{ $sfHandle }}"', $drawer);
        // AJAX drawer Apply (10 Nov 2026): the form carries the
        // hook the partial's submit interception binds to, the
        // wrapper closes on the close-ls-filters window event,
        // and the interception routes through the data table's
        // lsSwap — the native GET submit stays as the fallback.
        $this->assertStringContainsString('data-ls-drawer-form', $drawer);
        $this->assertStringContainsString('@close-ls-filters.window', $drawer);
        $this->assertStringContainsString('lsSwap', $drawer);
    }
}
