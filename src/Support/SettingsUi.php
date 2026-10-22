<?php

namespace LaraSlice\Support;

use Illuminate\Support\Str;

/**
 * Declared settings sections (S-UI 5, pattern P9): each slice
 * may declare top-level `settings:` sections in its manifest,
 * and the framework aggregates them into ONE hub — a rail of
 * sections (global and per-feature interleaved, like the
 * reference ERP) where every section is a form with its own
 * save. Values persist through the core Setting store, keyed
 * per section and grouped by the declaring slice.
 *
 * Field types: text, number, textarea, select (options),
 * toggle, secret (stored encrypted; blank saves keep the
 * current value).
 */
class SettingsUi
{
    /**
     * @param  array<int, array{slice: string, manifest: array}>  $entries
     * @param  callable(string, mixed): mixed  $valueResolver  (storage key, default) -> current value
     * @return array<int, array<string, mixed>>
     */
    public static function collectSections(array $entries, callable $valueResolver): array
    {
        $sections = [];
        foreach ($entries as $entry) {
            $slice = (string) ($entry['slice'] ?? '');
            $defs = $entry['manifest']['settings'] ?? [];
            if ($slice === '' || ! is_array($defs)) {
                continue;
            }
            foreach ($defs as $def) {
                if (! is_array($def) || empty($def['handle'])) {
                    continue;
                }
                $handle = (string) $def['handle'];
                $fields = [];
                foreach ((array) ($def['fields'] ?? []) as $field) {
                    if (! is_array($field) || empty($field['handle'])) {
                        continue;
                    }
                    $fieldHandle = (string) $field['handle'];
                    $key = (string) ($field['key'] ?? $handle.'.'.$fieldHandle);
                    $default = $field['default'] ?? null;
                    $fields[] = [
                        'handle' => $fieldHandle,
                        'key' => $key,
                        'label' => (string) ($field['label'] ?? Str::headline($fieldHandle)),
                        'type' => in_array(($field['type'] ?? 'text'), ['text', 'number', 'textarea', 'select', 'toggle', 'secret'], true)
                            ? (string) $field['type']
                            : 'text',
                        'options' => self::normalizeOptions($field['options'] ?? null),
                        'default' => $default,
                        'secret' => ! empty($field['secret']) || ($field['type'] ?? '') === 'secret',
                        'help' => $field['help'] ?? null,
                        'value' => $valueResolver($key, $default),
                    ];
                }
                $sections[] = [
                    'id' => $slice.':'.$handle,
                    'slice' => $slice,
                    'handle' => $handle,
                    'title' => (string) ($def['title'] ?? Str::headline($handle)),
                    'icon' => $def['icon'] ?? null,
                    'description' => $def['description'] ?? null,
                    'fields' => $fields,
                ];
            }
        }

        return $sections;
    }

    protected static function normalizeOptions(mixed $options): array
    {
        $map = [];
        if (! is_array($options)) {
            return $map;
        }
        foreach ($options as $k => $v) {
            if (is_array($v)) {
                $value = $v['value'] ?? $k;
                $label = $v['label'] ?? $value;
            } elseif (is_int($k)) {
                $value = $v;
                $label = $v;
            } else {
                $value = $k;
                $label = $v;
            }
            if (is_scalar($value) && is_scalar($label)) {
                $map[(string) $value] = (string) $label;
            }
        }

        return $map;
    }
}
