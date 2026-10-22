<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Support\DataTableRows;
use PHPUnit\Framework\TestCase;

/**
 * The user_stack list formatter (avatar stacks for assignees /
 * members columns) and the standard table footer's range text
 * contract in the published data-table component.
 */
class UserStackTest extends TestCase
{
    public function test_stack_renders_initials_and_overflow_chip(): void
    {
        $html = DataTableRows::userStack([
            ['name' => 'Oswald Lemke'],
            ['name' => 'Kyra Dare'],
            ['name' => 'Lina Walter'],
            ['name' => 'Boss Man'],
            ['name' => 'Fifth Person'],
        ]);
        $this->assertStringContainsString('-space-x-2', $html);
        $this->assertStringContainsString('>OL<', $html);
        $this->assertStringContainsString('>+1<', $html);
        $this->assertStringContainsString('title="Oswald Lemke, Kyra Dare, Lina Walter, Boss Man, Fifth Person"', $html);
    }

    public function test_stack_from_mixed_entries_via_rows_pipeline(): void
    {
        $rows = DataTableRows::from([
            ['id' => 1, 'members' => [['name' => 'Ana Ray', 'email' => 'a@x.io'], 'Bob Fox']],
            ['id' => 2, 'members' => []],
        ], [
            ['key' => 'members', 'formatter' => 'user_stack'],
        ]);
        $this->assertStringContainsString('>AR<', $rows[0]['members']);
        $this->assertStringContainsString('>BF<', $rows[0]['members']);
        $this->assertStringContainsString('—', $rows[1]['members']);
    }

    public function test_initials_use_first_and_last_words(): void
    {
        $this->assertSame('OL', DataTableRows::initialsOf('Oswald Lemke'));
        $this->assertSame('MW', DataTableRows::initialsOf('Mary Jane Watson'));
        $this->assertSame('AB', DataTableRows::initialsOf('Ab'));
    }

    public function test_data_table_footer_has_range_and_page_size(): void
    {
        $stub = file_get_contents(dirname(__DIR__, 3).'/resources/stubs/starter/views/components/ui/data-table.blade.php');
        $this->assertStringContainsString('{{ __(\'Showing\') }}', $stub);
        $this->assertStringContainsString('{{ __(\'Per page\') }}', $stub);
        $this->assertStringContainsString('user_stack', $stub);
    }
}
