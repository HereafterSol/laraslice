<?php

namespace LaraSlice\Support;

use BackedEnum;
use DateTimeInterface;
use UnitEnum;

/**
 * Builds plain rows for the BlatUI <x-ui.data-table> component, which renders every
 * cell as text on the client. Each column key is read with data_get(), so models,
 * listing objects and arrays all work, and values are flattened to display strings.
 */
class DataTableRows
{
    /**
     * @param  iterable<mixed>  $items
     * @param  array<int, array{key: string}>  $columns
     * @param  (callable(mixed): array<string, mixed>)|null  $extra  Extra non-column keys per row (e.g. edit_url)
     * @return list<array<string, mixed>>
     */
    public static function from(iterable $items, array $columns, ?callable $extra = null): array
    {
        $rows = [];

        foreach ($items as $item) {
            $row = [];
            foreach ($columns as $column) {
                $key = $column['key'];
                $row[$key] = static::display(data_get($item, $key));
            }

            if ($extra !== null) {
                $row = array_merge($row, $extra($item));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public static function display(mixed $value): string|int|float
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_int($value), is_float($value) => $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value), is_object($value) && ! method_exists($value, '__toString') => json_encode($value),
            default => (string) $value,
        };
    }
}
