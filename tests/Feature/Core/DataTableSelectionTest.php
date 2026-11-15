<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Tests\TestCase;

/**
 * D-085 regression guard: the data-table component's
 * selectedRecordIds() must resolve selection indices
 * through the `sorted` wrapper ({r, i}) and read the
 * row identity via the __id ladder — the framework's own
 * payload shape (DataTableRows). The previous body
 * searched the flat rows for an `i` they never carry and
 * never read __id, so every bulk action (delete, export
 * selected, workflow transitions) posted ZERO ids and
 * the endpoint answered 422 "No records selected." while
 * the UI showed the selection correctly.
 */
class DataTableSelectionTest extends TestCase
{
    public function test_stub_selected_record_ids_uses_sorted_wrapper_and_id_ladder(): void
    {
        $stub = file_get_contents(dirname(__DIR__, 3).'/resources/stubs/starter/views/components/ui/data-table.blade.php');
        $start = strpos($stub, 'selectedRecordIds()');
        $this->assertNotFalse($start);
        $body = substr($stub, $start, 1200);
        $this->assertStringContainsString('this.sorted.find', $body);
        $this->assertStringContainsString('row.__id', $body);
        $this->assertStringNotContainsString('this.rows.find(r => r.i === i)', $body);
    }
}
