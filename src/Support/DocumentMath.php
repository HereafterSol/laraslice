<?php

declare(strict_types=1);

namespace LaraSlice\Support;

/**
 * Document (line-item) arithmetic — backlog D3.
 *
 * A repeater field may declare a `document:` block in the
 * manifest: which row fields are the quantity, the unit
 * price and the line amount, which (optional) row field
 * carries the row TYPE, an optional per-row tax-amount
 * field, and which header fields receive the subtotal
 * and grand total. This helper is the single
 * implementation of that arithmetic: the form component
 * mirrors it in Alpine for live totals, and
 * BaseSliceService applies it authoritatively on save —
 * client-submitted amounts are never trusted.
 *
 * Row types (when a type field is declared): 'item'
 * rows add to the subtotal, 'discount' rows subtract,
 * 'tax' rows add to the total only. Untyped rows are
 * items. A declared per-row tax-amount field adds to
 * the total for item rows.
 */
final class DocumentMath
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{quantity?: string, unit_price?: string, amount?: string, type_field?: ?string, tax_amount?: ?string}  $document
     * @return array{rows: array<int, array<string, mixed>>, subtotal: float, total: float}
     */
    public static function apply(array $rows, array $document): array
    {
        $qty = (string) ($document['quantity'] ?? 'quantity');
        $price = (string) ($document['unit_price'] ?? 'unit_price');
        $amount = (string) ($document['amount'] ?? 'amount');
        $typeField = $document['type_field'] ?? null;
        $taxField = $document['tax_amount'] ?? null;

        $subtotal = 0.0;
        $total = 0.0;
        $out = [];
        foreach ($rows as $row) {
            $line = round(self::num($row[$qty] ?? null) * self::num($row[$price] ?? null), 2);
            $row[$amount] = $line;
            $type = $typeField ? strtolower((string) ($row[$typeField] ?? 'item')) : 'item';
            if ($type === 'discount') {
                $subtotal -= $line;
                $total -= $line;
            } elseif ($type === 'tax') {
                $total += $line;
            } else {
                $subtotal += $line;
                $total += $line;
                if ($taxField) {
                    $total += self::num($row[$taxField] ?? null);
                }
            }
            $out[] = $row;
        }

        return ['rows' => $out, 'subtotal' => round($subtotal, 2), 'total' => round($total, 2)];
    }

    private static function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }
}
