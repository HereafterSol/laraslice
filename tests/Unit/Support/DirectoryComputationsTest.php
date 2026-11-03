<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DataTableRows;
use PHPUnit\Framework\TestCase;

/**
 * Work-suite directory computations (P3): the declared
 * `compute` column spec (a product of resolved paths,
 * optionally scaled — a timesheet's earnings = minutes x
 * the employee's hourly rate / 60), and the source's
 * datetime idiom in directory cells (declared-zone wall
 * time, "08-02-2024 07:25 pm": no comma, lowercase
 * meridiem).
 */
class DirectoryComputationsTest extends TestCase
{
    public function test_compute_value_multiplies_paths_and_divides(): void
    {
        $item = ['total_minutes' => 300, 'rate' => 31];

        $value = DataTableRows::computeValue($item, [
            'product' => ['total_minutes', 'rate'],
            'divide' => 60,
        ]);

        $this->assertSame(155.0, $value);
    }

    public function test_compute_value_missing_operand_counts_as_zero(): void
    {
        $value = DataTableRows::computeValue(['total_minutes' => 300], [
            'product' => ['total_minutes', 'absent_rate'],
            'divide' => 60,
        ]);

        $this->assertSame(0.0, $value);
    }

    public function test_compute_column_replaces_the_row_value_before_formatting(): void
    {
        $rows = DataTableRows::from(
            [['total_minutes' => 120, 'rate' => 31, 'earnings' => null]],
            [[
                'key' => 'earnings',
                'formatter' => 'money',
                'compute' => ['product' => ['total_minutes', 'rate'], 'divide' => 60],
            ]]
        );

        $this->assertSame('PKR62.00', $rows[0]['earnings']);
        $this->assertSame('62', $rows[0]['earnings__raw']);
    }

    public function test_datetime_cells_use_the_source_idiom(): void
    {
        $rows = DataTableRows::from(
            [['start_time' => '2024-02-08 13:55:00']],
            [['key' => 'start_time', 'formatter' => 'date_time']]
        );

        // Zone-dependent wall time, zone-independent shape:
        // d-m-Y, no comma, lowercase am/pm (the old shape was
        // "08-02-2024, 01:55 PM" in the stored zone).
        $this->assertMatchesRegularExpression('/^08-02-2024 \d{2}:\d{2} (am|pm)$/', $rows[0]['start_time']);
    }

    public function test_midnight_datetime_still_renders_as_a_plain_date(): void
    {
        $rows = DataTableRows::from(
            [['start_time' => '2024-02-08 00:00:00']],
            [['key' => 'start_time', 'formatter' => 'date_time']]
        );

        $this->assertSame('08-02-2024', $rows[0]['start_time']);
    }

    public function test_money_breakdown_derives_paid_from_the_due_amount(): void
    {
        // Invoices persist only due_amount; the source
        // computes paid = total − due.
        $html = DataTableRows::moneyBreakdown(25252, null, 'PKR', 0);

        $this->assertStringContainsString('Total: <span class="font-bold">PKR25,252.00</span>', $html);
        $this->assertStringContainsString('Paid: PKR25,252.00', $html);
        $this->assertStringContainsString('Unpaid: PKR0.00', $html);
    }

    public function test_money_breakdown_derives_the_split_for_an_unpaid_document(): void
    {
        $html = DataTableRows::moneyBreakdown(23761, null, 'PKR', 23761);

        $this->assertStringContainsString('Paid: PKR0.00', $html);
        $this->assertStringContainsString('Unpaid: PKR23,761.00', $html);
    }
}
