<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DataTableRows;
use PHPUnit\Framework\TestCase;

/**
 * S-UI 2 / G8: every data-table row carries the record's key
 * under the reserved `__id` name so row-level JS (inline field
 * updates, workflow transitions) can address the record even
 * when `id` is not a declared column.
 */
class InlineRowIdentityTest extends TestCase
{
    public function test_rows_carry_reserved_id_from_dto_objects(): void
    {
        $item = (object) ['id' => 7, 'status' => 'pending'];
        $rows = DataTableRows::from([$item], [
            ['key' => 'status', 'formatter' => 'status_pill'],
        ]);

        $this->assertSame(7, $rows[0]['__id']);
        $this->assertSame('pending', $rows[0]['status__raw']);
    }

    public function test_extra_callback_still_merges(): void
    {
        $item = (object) ['id' => 3, 'status' => 'approved'];
        $rows = DataTableRows::from([$item], [
            ['key' => 'status', 'formatter' => 'status_pill'],
        ], fn ($i) => ['show_url' => '/x/'.$i->id]);

        $this->assertSame('/x/3', $rows[0]['show_url']);
        $this->assertSame(3, $rows[0]['__id']);
    }
}
