<?php

namespace LaraSlice\Support;

/**
 * Document numbering (C1 fidelity fix, 8 Nov 2026).
 *
 * The source formats document numbers from the invoice_settings
 * row at creation time: its StoreInvoice / contract StoreRequest
 * rewrite the entered raw number through a NumberFormat helper —
 * `{prefix}{separator}{zero-padded number}` with the padding
 * width from the row's digit setting (CONT + # + 001) — and an
 * observer keeps the raw part in `original_invoice_number`.
 * The settings were carried and editable in the port's hub but
 * nothing consumed them; this is the consuming half, read
 * through the same SettingsUi bound-row path the hub writes.
 *
 * Kinds: contract, invoice, estimate, credit_note, order — the
 * source helper's full set. Each kind reads its own
 * `{kind}_prefix` / `{kind}_number_separator` (invoice's is
 * `invoice_number_separator`) / `{kind}_digit` columns.
 */
class Numbering
{
    /** @var array<string, string> kind => separator column */
    protected static array $separatorColumns = [
        'contract' => 'contract_number_separator',
        'invoice' => 'invoice_number_separator',
        'estimate' => 'estimate_number_separator',
        'credit_note' => 'credit_note_number_separator',
        'order' => 'order_number_separator',
    ];

    /** @return array<int, string> */
    public static function kinds(): array
    {
        return array_keys(self::$separatorColumns);
    }

    /**
     * Format a raw entered number for a kind, exactly as the
     * source helper does. Without a settings row (or a blank
     * number) the value passes through unchanged — numbering
     * must never invent a number the user did not enter.
     */
    public static function format(string $kind, mixed $number): string
    {
        $number = is_scalar($number) ? (string) $number : '';
        if ($number === '' || ! isset(self::$separatorColumns[$kind])) {
            return $number;
        }
        $setting = SettingsUi::boundRow('invoice_settings');
        if ($setting === null) {
            return $number;
        }
        $prefix = (string) ($setting->{$kind.'_prefix'} ?? '');
        $separator = (string) ($setting->{self::$separatorColumns[$kind]} ?? '');
        $digit = (int) ($setting->{$kind.'_digit'} ?? 0);
        $zero = '';
        if (strlen($number) < $digit) {
            $zero = str_repeat('0', $digit - strlen($number));
        }

        return $prefix.$separator.$zero.$number;
    }

    /**
     * The raw part of a formatted number — the source observer's
     * derivation of `original_invoice_number` (strip the
     * prefix + separator back off).
     */
    public static function originalPart(string $kind, string $formatted): string
    {
        if (! isset(self::$separatorColumns[$kind])) {
            return $formatted;
        }
        $setting = SettingsUi::boundRow('invoice_settings');
        if ($setting === null) {
            return $formatted;
        }
        $prefix = (string) ($setting->{$kind.'_prefix'} ?? '');
        $separator = (string) ($setting->{self::$separatorColumns[$kind]} ?? '');
        $head = $prefix.$separator;

        return $head !== '' && str_starts_with($formatted, $head)
            ? substr($formatted, strlen($head))
            : $formatted;
    }
}
