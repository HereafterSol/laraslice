<?php

namespace LaraSlice\Tests\Unit\Core;

use DateTimeImmutable;
use LaraSlice\Support\DataTableRows;
use PHPUnit\Framework\TestCase;

class DataTableRowsTest extends TestCase
{
    public function test_builds_rows_from_objects_and_arrays_using_column_keys(): void
    {
        $object = (object) ['id' => 1, 'title' => 'Acme', 'secret' => 'hidden'];
        $array = ['id' => 2, 'title' => 'Globex'];

        $rows = DataTableRows::from([$object, $array], [['key' => 'id'], ['key' => 'title']]);

        $this->assertSame([
            ['id' => 1, 'title' => 'Acme'],
            ['id' => 2, 'title' => 'Globex'],
        ], $rows);
    }

    public function test_formats_values_for_text_cells(): void
    {
        $item = (object) [
            'empty' => null,
            'blank' => '',
            'flag' => true,
            'off' => false,
            'amount' => 99.5,
            'when' => new DateTimeImmutable('2026-10-06 14:30:00'),
            'tags' => ['a', 'b'],
        ];
        $columns = array_map(fn ($key) => ['key' => $key], array_keys((array) $item));

        $row = DataTableRows::from([$item], $columns)[0];

        $this->assertSame('—', $row['empty']);
        $this->assertSame('—', $row['blank']);
        $this->assertSame('Yes', $row['flag']);
        $this->assertSame('No', $row['off']);
        $this->assertSame(99.5, $row['amount']);
        $this->assertSame('2026-10-06 14:30', $row['when']);
        $this->assertSame('["a","b"]', $row['tags']);
    }

    public function test_merges_extra_keys_such_as_action_urls(): void
    {
        $rows = DataTableRows::from(
            [(object) ['id' => 7]],
            [['key' => 'id']],
            fn ($item) => ['edit_url' => '/things/'.$item->id.'/edit']
        );

        $this->assertSame([['id' => 7, 'edit_url' => '/things/7/edit']], $rows);
    }
}
