<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DocumentMath;
use PHPUnit\Framework\TestCase;

/**
 * Document arithmetic (backlog D3): line amounts are
 * recomputed from quantity × unit price (never trusted
 * from the client), typed rows steer the totals, and a
 * per-row tax amount joins the grand total.
 */
final class DocumentMathTest extends TestCase
{
    public function test_line_amounts_subtotal_and_total(): void
    {
        $r = DocumentMath::apply([
            ['name' => 'A', 'quantity' => 2, 'price' => 100, 'total' => 999],
            ['name' => 'B', 'quantity' => '1.5', 'price' => '40', 'total' => null],
        ], ['quantity' => 'quantity', 'unit_price' => 'price', 'amount' => 'total']);

        $this->assertSame(200.0, $r['rows'][0]['total'], 'client amount overwritten');
        $this->assertSame(60.0, $r['rows'][1]['total']);
        $this->assertSame(260.0, $r['subtotal']);
        $this->assertSame(260.0, $r['total']);
    }

    public function test_typed_rows_discount_subtracts_tax_adds(): void
    {
        $r = DocumentMath::apply([
            ['quantity' => 1, 'unit_price' => 500, 'type' => 'item'],
            ['quantity' => 1, 'unit_price' => 50, 'type' => 'discount'],
            ['quantity' => 1, 'unit_price' => 45, 'type' => 'tax'],
        ], ['quantity' => 'quantity', 'unit_price' => 'unit_price', 'amount' => 'amount', 'type_field' => 'type']);

        $this->assertSame(450.0, $r['subtotal']);
        $this->assertSame(495.0, $r['total']);
    }

    public function test_per_row_tax_amount_joins_total_for_items_only(): void
    {
        $r = DocumentMath::apply([
            ['quantity' => 2, 'price' => 100, 'tax' => 30],
            ['quantity' => 1, 'price' => 10, 'tax' => 99, 'type' => 'discount'],
        ], ['quantity' => 'quantity', 'unit_price' => 'price', 'amount' => 'total', 'tax_amount' => 'tax', 'type_field' => 'type']);

        $this->assertSame(190.0, $r['subtotal']);
        $this->assertSame(220.0, $r['total'], 'item tax added; discount-row tax ignored');
    }

    public function test_non_numeric_and_empty_rows_are_safe(): void
    {
        $r = DocumentMath::apply([
            ['quantity' => 'abc', 'unit_price' => null],
        ], ['quantity' => 'quantity', 'unit_price' => 'unit_price', 'amount' => 'amount']);
        $this->assertSame(0.0, $r['rows'][0]['amount']);
        $this->assertSame(0.0, $r['total']);
    }
}
