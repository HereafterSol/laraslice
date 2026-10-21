<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * S-UI 3 (P2 hub composition): the generated show page orders
 * header -> tab bar -> panels, with the stats strip inside the
 * overview panel (the reference ERP keeps counters within the
 * hub's overview, under the tab bar) and hub tabs at the
 * standard 13px/medium type.
 */
class HubCompositionGenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-sui3-'.bin2hex(random_bytes(8));
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

    public function test_show_orders_tab_bar_before_stats_and_stats_inside_overview(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Client', [
            ['name' => 'name', 'type' => 'string', 'label' => 'Name'],
        ], false, ['table' => 'clients', 'ui' => ['show' => ['stats' => [
            ['handle' => 'projects', 'label' => 'Projects', 'relation' => 'projects', 'aggregate' => 'count'],
        ]]]]);

        $show = file_get_contents($path.'/Resources/views/show.blade.php');
        $ribbon = strpos($show, 'Sub-Tabs Ribbon');
        $overview = strpos($show, "activeTab === 'overview'");
        $stats = strpos($show, 'KPI Metric Strip');

        $this->assertNotFalse($ribbon);
        $this->assertNotFalse($overview);
        $this->assertNotFalse($stats);
        $this->assertLessThan($overview, $ribbon, 'tab bar renders before the panels');
        $this->assertGreaterThan($overview, $stats, 'stats strip lives inside the overview panel');
        $this->assertStringContainsString('text-[13px] font-medium', $show);
        // The shared form template carries the sticky save bar (C6).
        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('Sticky Bottom Form Actions Bar', $form);
    }
}
