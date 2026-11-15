<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Str;

/**
 * Seat 3 — free-form directory conditions: the
 * add-any-field filter the source's list view carries
 * (field + operator + value rows). Conditions arrive
 * as ff[<i>][field|op|value] request params, are
 * validated against the slice's Heart (fields only —
 * never arbitrary columns), ride the filter object,
 * and are applied by the service next to the declared
 * criteria. The same operator vocabulary renders the
 * builder rows and the applied-filter chips, glyphs
 * included (=, ≠, ≈, ≥, ≤).
 */
class DirectoryConditions
{
    /** op => [glyph, label] */
    public const OPERATORS = [
        'is' => ['=', 'Equals'],
        'is_not' => ['≠', 'Not equals'],
        'like' => ['≈', 'Contains'],
        'not_like' => ['≁', 'Does not contain'],
        'in' => ['in', 'In list'],
        'not_in' => ['not in', 'Not in list'],
        'gt' => ['>', 'Greater than'],
        'gte' => ['≥', 'Greater than or equal'],
        'lt' => ['<', 'Less than'],
        'lte' => ['≤', 'Less than or equal'],
        'empty' => ['is', 'Is empty'],
        'not_empty' => ['is not', 'Is not empty'],
    ];

    /** Pseudo-fields every directory can filter/sort by. */
    public const PSEUDO_FIELDS = [
        'created_at' => 'Created On',
        'updated_at' => 'Last Updated On',
        'id' => 'ID',
    ];

    /**
     * Validate raw ff params into clean conditions.
     *
     * @return array<int, array{field: string, op: string, value: mixed, label: string, glyph: string, value_label: string}>
     */
    public static function parse(mixed $raw, array $manifest): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $fields = self::fieldOptions($manifest);
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field['name']] = $field;
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $field = (string) ($row['field'] ?? '');
            $op = (string) ($row['op'] ?? '');
            if (! isset($byName[$field]) || ! isset(self::OPERATORS[$op])) {
                continue;
            }
            $value = $row['value'] ?? null;
            if (in_array($op, ['empty', 'not_empty'], true)) {
                $value = null;
            } elseif (in_array($op, ['in', 'not_in'], true)) {
                $value = is_array($value) ? array_values(array_filter(array_map('strval', $value), fn ($v) => $v !== ''))
                    : array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
                if ($value === []) {
                    continue;
                }
            } else {
                if (! is_scalar($value) || (string) $value === '') {
                    continue;
                }
                $value = (string) $value;
            }
            [$glyph, $opLabel] = self::OPERATORS[$op];
            $out[] = [
                'field' => $field,
                'op' => $op,
                'value' => $value,
                'label' => $byName[$field]['label'],
                'glyph' => $glyph,
                'value_label' => self::valueLabel($byName[$field], $op, $value),
            ];
        }

        return $out;
    }

    /** Apply validated conditions to an Eloquent/Query builder. */
    public static function apply(mixed $query, array $conditions): mixed
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $value = $condition['value'];
            switch ($condition['op']) {
                case 'is':
                    $query->where($field, $value);
                    break;
                case 'is_not':
                    $query->where(fn ($q) => $q->where($field, '!=', $value)->orWhereNull($field));
                    break;
                case 'like':
                    $query->where($field, 'like', '%'.$value.'%');
                    break;
                case 'not_like':
                    $query->where(fn ($q) => $q->where($field, 'not like', '%'.$value.'%')->orWhereNull($field));
                    break;
                case 'in':
                    $query->whereIn($field, (array) $value);
                    break;
                case 'not_in':
                    $query->whereNotIn($field, (array) $value);
                    break;
                case 'gt':
                    $query->where($field, '>', $value);
                    break;
                case 'gte':
                    $query->where($field, '>=', $value);
                    break;
                case 'lt':
                    $query->where($field, '<', $value);
                    break;
                case 'lte':
                    $query->where($field, '<=', $value);
                    break;
                case 'empty':
                    $query->where(fn ($q) => $q->whereNull($field)->orWhere($field, ''));
                    break;
                case 'not_empty':
                    $query->where(fn ($q) => $q->whereNotNull($field)->where($field, '!=', ''));
                    break;
            }
        }

        return $query;
    }

    /**
     * The filterable field catalogue for the builder:
     * manifest fields + the pseudo-fields.
     *
     * @return array<int, array{name: string, label: string, type: string, options: array<int, array{value: string, label: string}>}>
     */
    public static function fieldOptions(array $manifest): array
    {
        $out = [];
        foreach (SliceRecordOptions::manifestFieldsOf($manifest) as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $options = [];
            foreach (($definition['options'] ?? []) as $optKey => $option) {
                if (is_array($option) && isset($option['value'])) {
                    $options[] = ['value' => (string) $option['value'], 'label' => (string) ($option['label'] ?? $option['value'])];
                } elseif (is_scalar($option) && is_string($optKey)) {
                    // Map-shaped declarations ({slug: Label}) — the KEY is the
                    // stored value; dropping it made the filter builder emit
                    // labels as values, so equality filters never matched.
                    $options[] = ['value' => (string) $optKey, 'label' => (string) $option];
                } elseif (is_scalar($option)) {
                    $options[] = ['value' => (string) $option, 'label' => Str::headline((string) $option)];
                }
            }
            $out[] = [
                'name' => (string) $name,
                'label' => (string) ($definition['label'] ?? Str::headline((string) $name)),
                'type' => (string) ($definition['type'] ?? 'string'),
                'options' => $options,
                'in_list_view' => ! empty($definition['in_list_view']),
            ];
        }
        foreach (self::PSEUDO_FIELDS as $name => $label) {
            $out[] = ['name' => $name, 'label' => $label, 'type' => $name === 'id' ? 'number' : 'date', 'options' => [], 'in_list_view' => true];
        }

        return $out;
    }

    private static function valueLabel(array $field, string $op, mixed $value): string
    {
        if (in_array($op, ['empty', 'not_empty'], true)) {
            return $op === 'empty' ? 'empty' : 'not empty';
        }
        $labels = [];
        foreach ((array) $value as $single) {
            $label = null;
            foreach ($field['options'] as $option) {
                if ($option['value'] === (string) $single) {
                    $label = $option['label'];
                    break;
                }
            }
            $labels[] = $label ?? (string) $single;
        }

        return implode(', ', $labels);
    }
}
