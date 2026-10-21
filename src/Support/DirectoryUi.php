<?php

namespace LaraSlice\Support;

use LaraSlice\Core\Discovery\SliceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolve directory column config (formatters) at request time from the
 * slice's registered path — works for path-repo, vendor, and published installs.
 */
final class DirectoryUi
{
    /** @var list<string> */
    public const HTML_FORMATTERS = [
        'code_badge', 'composite_user', 'status_pill', 'money_breakdown',
        'progress_bar', 'badge', 'text_with_badge', 'date_overdue',
    ];

    /**
     * Merge ui.directory.columns from the live slice manifest over $fallback.
     *
     * @param  list<array<string, mixed>>  $fallback
     * @return list<array<string, mixed>>
     */
    public static function columnsForSlice(string $sliceName, array $fallback = []): array
    {
        $manifest = self::readManifest($sliceName);
        $dirCols = $manifest['ui']['directory']['columns'] ?? null;
        if (! is_array($dirCols) || $dirCols === []) {
            return $fallback;
        }

        $fieldIndex = self::fieldIndex($manifest);

        $columns = [];
        foreach ($dirCols as $c) {
            if (! is_array($c)) {
                continue;
            }
            $key = $c['key'] ?? $c['handle'] ?? null;
            if (! $key) {
                continue;
            }
            $fmt = $c['formatter'] ?? $c['type'] ?? 'text';
            $label = (string) ($c['label'] ?? \Illuminate\Support\Str::headline((string) $key));
            // Display labels drop the storage suffix: "Leave Type Id" -> "Leave Type".
            if (str_ends_with((string) $key, '_id') && $key !== 'id') {
                $label = (string) preg_replace('/\s+Id$/', '', $label);
            }
            $def = $fieldIndex[(string) $key] ?? [];
            $options = self::normalizeOptions($def['options'] ?? null);
            $columns[] = array_filter([
                'key' => $key,
                'label' => $label,
                'sortable' => $c['sortable'] ?? true,
                'formatter' => $fmt,
                'html' => in_array($fmt, self::HTML_FORMATTERS, true),
                'badge_from' => $c['badge_from'] ?? null,
                'breakdown' => $c['breakdown'] ?? null,
                'options' => $options !== [] ? $options : null,
                'field_type' => isset($def['type']) && is_string($def['type']) ? $def['type'] : null,
            ], fn ($v) => $v !== null);
        }

        return $columns !== [] ? $columns : $fallback;
    }


    /**
     * Build display rows for a record's detail (show) view from the slice
     * manifest — the detail-page counterpart of columnsForSlice(). Values go
     * through the same DataTableRows pipeline as listings, so FK labels
     * resolve, declared option labels replace raw values, and dates render
     * human-readably instead of dumping raw model attributes (tenant keys,
     * raw ids, storage timestamps) onto the page.
     *
     * @return list<array{label: string, value: string, html: bool}>
     */
    public static function detailRows(string $sliceName, mixed $record): array
    {
        $manifest = self::readManifest($sliceName);
        $fields = self::fieldIndex($manifest);
        $attributes = is_object($record) && method_exists($record, 'getAttributes')
            ? $record->getAttributes()
            : (is_array($record) ? $record : []);
        if ($fields === [] || $attributes === []) {
            return [];
        }

        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'company_id', 'tenant_id', 'organization_id', 'workflow_state_entered_at'];
        $columns = [];
        foreach ($fields as $name => $def) {
            $name = (string) $name;
            if (in_array($name, $skip, true) || ! array_key_exists($name, $attributes) || ! empty($def['hidden'])) {
                continue;
            }
            $label = (string) ($def['label'] ?? \Illuminate\Support\Str::headline($name));
            if (str_ends_with($name, '_id') && $name !== 'id') {
                $label = (string) preg_replace('/\s+Id$/', '', $label);
            }
            $options = self::normalizeOptions($def['options'] ?? null);
            $isStatus = in_array($name, ['status', 'state', 'stage'], true);
            $fieldType = isset($def['type']) && is_string($def['type']) ? $def['type'] : null;
            $formatter = 'text';
            if ($isStatus) {
                $formatter = 'status_pill';
            } elseif (in_array($fieldType, ['boolean', 'bool'], true)) {
                // Raw storage 0/1 never reaches a detail page.
                $formatter = static fn ($v) => $v ? 'Yes' : 'No';
            }
            $columns[] = array_filter([
                'key' => $name,
                'label' => $label,
                'formatter' => $formatter,
                'html' => $isStatus,
                'options' => $options !== [] ? $options : null,
                'field_type' => $fieldType,
            ], fn ($v) => $v !== null);
        }
        if ($columns === []) {
            return [];
        }

        $row = DataTableRows::from([$record], $columns)[0] ?? [];
        $rows = [];
        foreach ($columns as $col) {
            $rows[] = [
                'label' => $col['label'],
                'value' => (string) ($row[$col['key']] ?? '—'),
                'html' => ! empty($col['html']),
            ];
        }

        return $rows;
    }

    /**
     * Resolve the related-record tabs for a slice's detail (show) page.
     *
     * Sources, in precedence order, deduplicated by relation name:
     *  1. `ui.show.tabs` entries declared with `type: relation`;
     *  2. the root model's `hasMany` relations in the manifest;
     *  3. repeater (line-item) form fields — aggregate children are the
     *     record's most important related records.
     *
     * When a record is supplied and exposes the relation method, each tab
     * gains a `count` so the detail page reads as a hub, not a flat grid.
     * (F3: this replaces a generated-template fallback that read the
     * manifest as JSON and silently found nothing once manifests became
     * YAML-only.)
     *
     * @return list<array{handle: string, label: string, type: string, relation: string, count?: int}>
     */
    public static function relatedTabs(string $sliceName, mixed $record = null): array
    {
        return self::relatedTabsFromManifest(self::readManifest($sliceName), $record);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{handle: string, label: string, type: string, relation: string, count?: int}>
     */
    public static function relatedTabsFromManifest(array $manifest, mixed $record = null): array
    {
        $tabs = [];
        $seen = [];
        $add = static function (string $relation, ?string $label) use (&$tabs, &$seen): void {
            if ($relation === '' || isset($seen[$relation])) {
                return;
            }
            $seen[$relation] = true;
            $tabs[] = [
                'handle' => $relation,
                'label' => $label !== null && $label !== '' ? $label : \Illuminate\Support\Str::headline($relation),
                'type' => 'relation',
                'relation' => $relation,
            ];
        };

        foreach (($manifest['ui']['show']['tabs'] ?? []) as $tab) {
            if (is_array($tab) && ($tab['type'] ?? '') === 'relation') {
                $add((string) ($tab['relation'] ?? $tab['handle'] ?? ''), isset($tab['label']) ? (string) $tab['label'] : null);
            }
        }

        $root = $manifest['models'][0] ?? null;
        if (is_array($root)) {
            foreach (($root['relations'] ?? []) as $rel) {
                if (is_array($rel) && ($rel['type'] ?? '') === 'hasMany') {
                    $add((string) ($rel['name'] ?? ''), isset($rel['label']) ? (string) $rel['label'] : null);
                }
            }
        }

        foreach (($manifest['ui']['forms']['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach (($section['fields'] ?? []) as $field) {
                if (is_array($field) && ($field['type'] ?? '') === 'repeater' && isset($field['handle'])) {
                    $add((string) $field['handle'], isset($field['label']) ? (string) $field['label'] : null);
                }
            }
        }

        if (is_object($record)) {
            foreach ($tabs as $i => $tab) {
                $relation = $tab['relation'];
                if (! method_exists($record, $relation)) {
                    continue;
                }
                try {
                    $query = $record->{$relation}();
                    if (is_object($query)) {
                        // Relations forward count() via __call, so probe by
                        // calling it — the catch keeps a bad relation safe.
                        $tabs[$i]['count'] = (int) $query->count();
                    }
                } catch (\Throwable $e) {
                    // A broken relation must never break the detail page.
                }
            }
        }

        return $tabs;
    }

    /**
     * Index the manifest's field definitions by field name, from both the
     * top-level `fields` map and each model's `fields` list.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, mixed>>
     */
    protected static function fieldIndex(array $manifest): array
    {
        $index = [];
        $add = static function ($name, $def) use (&$index): void {
            if (is_string($name) && $name !== '' && is_array($def)) {
                $index[$name] ??= $def;
            }
        };
        if (isset($manifest['fields']) && is_array($manifest['fields'])) {
            foreach ($manifest['fields'] as $name => $def) {
                $add($name, $def);
            }
        }
        if (isset($manifest['models']) && is_array($manifest['models'])) {
            foreach ($manifest['models'] as $model) {
                if (! is_array($model) || ! isset($model['fields']) || ! is_array($model['fields'])) {
                    continue;
                }
                foreach ($model['fields'] as $k => $def) {
                    if (is_array($def)) {
                        $add($def['name'] ?? $def['handle'] ?? (is_string($k) ? $k : null), $def);
                    }
                }
            }
        }

        return $index;
    }

    /**
     * Normalize a field's declared options into a value => label map.
     * Accepts {value: label} maps, lists of {value, label} pairs, and
     * plain scalar lists.
     *
     * @return array<string, string>
     */
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

    /**
     * @return array<string, mixed>
     */
    public static function readManifest(string $sliceName): array
    {
        $path = self::manifestPath($sliceName);
        if ($path === null || ! is_file($path)) {
            return [];
        }

        try {
            if (str_ends_with(strtolower($path), '.json')) {
                return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) ?: [];
            }
            if (class_exists(Yaml::class)) {
                $data = Yaml::parseFile($path);

                return is_array($data) ? $data : [];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    public static function manifestPath(string $sliceName): ?string
    {
        try {
            if (function_exists('app') && app()->bound(SliceManager::class)) {
                /** @var SliceManager $manager */
                $manager = app(SliceManager::class);
                $manager->discover();
                $slice = $manager->getSlice($sliceName);
                if ($slice) {
                    foreach (['slice.yaml', 'slice.yaml', 'slice.yml'] as $file) {
                        $p = rtrim($slice->path, '/\\').DIRECTORY_SEPARATOR.$file;
                        if (is_file($p)) {
                            return $p;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to path heuristics
        }

        // Heuristics when container not ready
        $candidates = [];
        if (function_exists('app_path')) {
            $candidates[] = app_path('Slices');
            $candidates[] = base_path('app/Slices');
        }
        $candidates[] = dirname(__DIR__, 2).'/Slices';

        foreach ($candidates as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $found = self::findManifestUnder($root, $sliceName, 0, 6);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    protected static function findManifestUnder(string $dir, string $sliceName, int $depth, int $max): ?string
    {
        if ($depth > $max) {
            return null;
        }
        $base = basename($dir);
        if (strcasecmp($base, $sliceName) === 0 || strcasecmp($base, str_replace(' ', '', $sliceName)) === 0) {
            foreach (['slice.yaml', 'slice.yaml', 'slice.yml'] as $file) {
                $p = $dir.DIRECTORY_SEPARATOR.$file;
                if (is_file($p)) {
                    return $p;
                }
            }
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $sub) {
            $hit = self::findManifestUnder($sub, $sliceName, $depth + 1, $max);
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }
}
