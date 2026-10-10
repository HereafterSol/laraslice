<?php

namespace LaraSlice\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Builds plain rows for the BlatUI <x-ui.data-table> component, which renders every
 * cell as text on the client. Each column key is read with data_get(), so models,
 * listing objects and arrays all work, and values are flattened to display strings.
 * Automatically resolves foreign keys (e.g. invoice_id, company_id) to parent display names.
 */
class DataTableRows
{
    /**
     * Cache for resolved table display columns: table_name => label_column
     * @var array<string, string|null>
     */
    protected static array $labelColumnCache = [];

    /**
     * @param  iterable<mixed>  $items
     * @param  array<int, array{key: string}>  $columns
     * @param  (callable(mixed): array<string, mixed>)|null  $extra  Extra non-column keys per row (e.g. edit_url)
     * @return list<array<string, mixed>>
     */
    public static function from(iterable $items, array $columns, ?callable $extra = null): array
    {
        $rows = [];
        $itemsArray = is_array($items) ? $items : iterator_to_array($items);

        // Pre-resolve foreign key lookups in a single batch query per FK column
        $fkMaps = static::resolveForeignKeysBatch($itemsArray, $columns);

        foreach ($itemsArray as $item) {
            $row = [];
            foreach ($columns as $column) {
                $key = $column['key'];
                $val = data_get($item, $key);

                // If this is a foreign key with a resolved parent name, display the friendly name
                if (isset($fkMaps[$key]) && is_scalar($val) && isset($fkMaps[$key][$val])) {
                    $row[$key] = (string) $fkMaps[$key][$val];
                } elseif (str_ends_with($key, '_id') && $key !== 'id' && $item instanceof Model) {
                    // Check if relation is loaded on the model
                    $relName = Str::camel(substr($key, 0, -3));
                    if ($item->relationLoaded($relName) && ($relModel = $item->getRelation($relName))) {
                        $row[$key] = static::modelDisplayName($relModel);
                    } else {
                        $row[$key] = static::display($val);
                    }
                } else {
                    $row[$key] = static::display($val);
                }
            }

            if ($extra !== null) {
                $row = array_merge($row, $extra($item));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Batch resolve display names for foreign key columns across all items.
     *
     * @param  array<int, mixed>  $items
     * @param  array<int, array{key: string}>  $columns
     * @return array<string, array<int|string, string>>
     */
    protected static function resolveForeignKeysBatch(array $items, array $columns): array
    {
        $fkMaps = [];

        foreach ($columns as $column) {
            $key = $column['key'];
            if (! str_ends_with($key, '_id') || $key === 'id') {
                continue;
            }

            $base = substr($key, 0, -3);
            $candidateTables = [
                Str::plural($base),
                $base,
            ];

            $targetTable = null;
            $labelCol = null;

            foreach ($candidateTables as $tbl) {
                if (array_key_exists($tbl, static::$labelColumnCache)) {
                    $labelCol = static::$labelColumnCache[$tbl];
                    if ($labelCol !== null) {
                        $targetTable = $tbl;
                        break;
                    }
                } elseif (Schema::hasTable($tbl)) {
                    $cols = Schema::getColumnListing($tbl);
                    foreach (['name', 'title', 'company_name', 'label', 'display_name', 'full_name', 'invoice_number', 'order_number', 'sku', 'reference', 'code'] as $candidate) {
                        if (in_array($candidate, $cols, true)) {
                            $labelCol = $candidate;
                            break;
                        }
                    }
                    static::$labelColumnCache[$tbl] = $labelCol;
                    if ($labelCol !== null) {
                        $targetTable = $tbl;
                        break;
                    }
                }
            }

            if ($targetTable && $labelCol) {
                // Collect all unique IDs for this key across items
                $ids = [];
                foreach ($items as $item) {
                    $v = data_get($item, $key);
                    if (is_numeric($v) && $v > 0) {
                        $ids[] = (int) $v;
                    }
                }
                $ids = array_unique($ids);

                if (! empty($ids)) {
                    try {
                        $fkMaps[$key] = DB::table($targetTable)
                            ->whereIn('id', $ids)
                            ->pluck($labelCol, 'id')
                            ->all();
                    } catch (\Throwable $e) {
                        // Safe fallback if table read fails
                        $fkMaps[$key] = [];
                    }
                }
            }
        }

        return $fkMaps;
    }

    /**
     * Get display name from an Eloquent model.
     */
    protected static function modelDisplayName(Model $model): string
    {
        foreach (['name', 'title', 'company_name', 'label', 'display_name', 'full_name', 'invoice_number', 'order_number', 'sku', 'reference', 'code'] as $cand) {
            if (isset($model->{$cand}) && filled($model->{$cand})) {
                return (string) $model->{$cand};
            }
        }

        return (string) ($model->getKey() ?? '—');
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