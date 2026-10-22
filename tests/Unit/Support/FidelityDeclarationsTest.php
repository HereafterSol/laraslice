<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DataTableRows;
use PHPUnit\Framework\TestCase;

/**
 * Fidelity-stage vocabulary (plain tests): the named `date`
 * formatter exists, formatter STRINGS are never invoked as
 * callables, and the generator + repair passes carry the
 * fidelity bindings.
 */
class FidelityDeclarationsTest extends TestCase
{
    public function test_date_formatter_formats_date_values(): void
    {
        $rows = DataTableRows::from(
            [['start_date' => '2024-02-07 19:40:00']],
            [['key' => 'start_date', 'formatter' => 'date']]
        );
        $this->assertSame('07 Feb 2024', $rows[0]['start_date']);
    }

    public function test_formatter_name_matching_a_php_function_is_not_invoked(): void
    {
        // 'date' used to explode: is_callable('date') is true, so
        // the name was called as date($value, $item).
        $rows = DataTableRows::from(
            [['x' => '2024-01-01']],
            [['key' => 'x', 'formatter' => 'date']]
        );
        $this->assertSame('01 Jan 2024', $rows[0]['x']);
    }

    public function test_generator_and_repair_carry_fidelity_bindings(): void
    {
        $gen = file_get_contents(dirname(__DIR__, 3).'/src/Generator/SliceGenerator.php');
        $this->assertStringContainsString('filterDefaults', $gen);
        $this->assertStringContainsString("laraslice::directory.actions", $gen);
        // The drawer loop (with its ui:false guard) lives in the
        // package partial since stage 3.
        $drawerPartial = file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/directory/filters-drawer.blade.php');
        $this->assertStringContainsString("sf['ui']", $drawerPartial);

        $mgr = file_get_contents(dirname(__DIR__, 3).'/src/Core/Discovery/SliceManager.php');
        $this->assertStringContainsString('(f) Fidelity defaults', $mgr);
        $this->assertStringContainsString('(g) Fidelity actions', $mgr);
        $this->assertStringContainsString('(h) Fidelity', $mgr);

        $tabs = file_get_contents(dirname(__DIR__, 3).'/resources/stubs/starter/views/components/ui/filter-tabs.blade.php');
        $this->assertStringContainsString('__all__', $tabs);
    }

    public function test_stage_two_markers(): void
    {
        $mgr = file_get_contents(dirname(__DIR__, 3).'/src/Core/Discovery/SliceManager.php');
        $this->assertStringContainsString('(j) Fidelity', $mgr);
        $this->assertStringContainsString('(k) Fidelity', $mgr);

        $gen = file_get_contents(dirname(__DIR__, 3).'/src/Generator/SliceGenerator.php');
        $this->assertStringContainsString('bulkEntryLabel', $gen);
        // The drawer fields (incl. number_range) moved into the
        // package partial in stage 3 — one maintenance point.
        $drawer = file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/directory/filters-drawer.blade.php');
        $this->assertStringContainsString('number_range', $drawer);
        $this->assertStringContainsString("!== 'calendar'", $gen);

        $ctl = file_get_contents(dirname(__DIR__, 3).'/src/Core/Base/BaseSliceWebController.php');
        $this->assertStringContainsString('default_from', $ctl);
        $this->assertStringContainsString('bulkEntryLabel', $ctl);
    }
}
