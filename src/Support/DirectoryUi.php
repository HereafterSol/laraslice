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
        'code_badge', 'composite_user', 'user_stack', 'status_pill', 'money_breakdown',
        'progress_bar', 'badge', 'text_with_badge', 'date_overdue', 'timer'];

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
            if ($options === []) {
                // The value set may live only in the directory's
                // filter declarations (literal options, or
                // options_from: distinct:<col>) — the inline
                // editor and other consumers need it on the
                // column itself.
                $options = self::filterDeclaredOptions($manifest, (string) $key, $sliceName);
            }
            $columns[] = array_filter([
                'key' => $key,
                'label' => $label,
                'sortable' => $c['sortable'] ?? true,
                'alwaysVisible' => ! empty($c['always_visible']),
                'formatter' => $fmt,
                'html' => in_array($fmt, self::HTML_FORMATTERS, true),
                'badge_from' => $c['badge_from'] ?? null,
                'breakdown' => $c['breakdown'] ?? null,
                'label_table' => $c['label_table'] ?? null,
                'label_column' => $c['label_column'] ?? null,
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
     * Compute the declared header stats for a slice's detail page.
     *
     * Manifest contract (`ui.show.stats`): each entry names a relation
     * on the record, an aggregate (count | sum | avg, with `column` for
     * sum/avg), an optional equality/`whereIn` `filter` map over the
     * related table's columns, and a label. This is how a hub header
     * shows domain counters (open tasks, hours logged) instead of the
     * generic record-id/status strip. A stat whose relation or query
     * fails is skipped — a broken declaration must never break or
     * misreport the page. (F3c.)
     *
     * @return list<array{handle: string, label: string, value: string}>
     */
    public static function showStats(string $sliceName, mixed $record = null): array
    {
        return self::showStatsFromManifest(self::readManifest($sliceName), $record);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{handle: string, label: string, value: string}>
     */
    /**
     * Declared overview widgets (ui.show.widgets) for a record:
     * the hub-overview composition layer (S-UI 3). Types:
     *
     * - gauge: {value: {relation, aggregate, filter?},
     *   max: {relation, ...} | {field} | number} -> value/max
     *   with a computed percent.
     * - chart: {relation, group_by, chart: donut|pie|bar,
     *   labels: {value: label}} -> grouped counts.
     *
     * A widget that cannot be computed is skipped, never
     * misreported (same rule as declared stats).
     */
    public static function showWidgetsFromManifest(array $manifest, mixed $record = null): array
    {
        $defs = $manifest['ui']['show']['widgets'] ?? [];
        if (! is_array($defs) || $defs === [] || ! is_object($record)) {
            return [];
        }

        $widgets = [];
        foreach ($defs as $def) {
            if (! is_array($def)) {
                continue;
            }
            $handle = (string) ($def['handle'] ?? '');
            $type = (string) ($def['type'] ?? '');
            try {
                if ($type === 'gauge' && $handle !== '') {
                    $value = self::widgetAggregate($record, (array) ($def['value'] ?? []));
                    $maxSpec = $def['max'] ?? null;
                    $max = is_numeric($maxSpec) ? (float) $maxSpec
                        : (is_array($maxSpec) && isset($maxSpec['field'])
                            ? (float) (data_get($record, (string) $maxSpec['field']) ?? 0)
                            : (is_array($maxSpec) ? self::widgetAggregate($record, $maxSpec) : null));
                    if ($value === null || $max === null || $max <= 0) {
                        continue;
                    }
                    $widgets[] = [
                        'handle' => $handle,
                        'type' => 'gauge',
                        'title' => (string) ($def['title'] ?? \Illuminate\Support\Str::headline($handle)),
                        'value' => $value,
                        'max' => $max,
                        'percent' => max(0.0, min(100.0, round($value / $max * 100, 1))),
                        'display' => self::trimNumber($value).' / '.self::trimNumber($max),
                    ];
                } elseif ($type === 'chart' && $handle !== '') {
                    $relation = (string) ($def['relation'] ?? '');
                    $groupBy = (string) ($def['group_by'] ?? '');
                    if ($relation === '' || $groupBy === '' || ! method_exists($record, $relation)) {
                        continue;
                    }
                    $items = $record->{$relation}()->limit(500)->get();
                    $counts = [];
                    foreach ($items as $item) {
                        $gv = data_get($item, $groupBy);
                        if ($gv === null || ! is_scalar($gv)) {
                            continue;
                        }
                        $counts[(string) $gv] = ($counts[(string) $gv] ?? 0) + 1;
                    }
                    if ($counts === []) {
                        continue;
                    }
                    $labelsMap = (array) ($def['labels'] ?? []);
                    if ($labelsMap !== []) {
                        // Declared label maps also declare the
                        // group order (board columns, statuses).
                        $ordered = [];
                        foreach ($labelsMap as $k => $_) {
                            if (isset($counts[(string) $k])) {
                                $ordered[(string) $k] = $counts[(string) $k];
                            }
                        }
                        foreach ($counts as $k => $n) {
                            $ordered[$k] ??= $n;
                        }
                        $counts = $ordered;
                    }
                    $labels = [];
                    $series = [];
                    foreach ($counts as $k => $n) {
                        $labels[] = (string) ($labelsMap[$k] ?? \Illuminate\Support\Str::headline($k));
                        $series[] = $n;
                    }
                    $widgets[] = [
                        'handle' => $handle,
                        'type' => 'chart',
                        'chart' => (string) ($def['chart'] ?? 'donut'),
                        'title' => (string) ($def['title'] ?? \Illuminate\Support\Str::headline($handle)),
                        'labels' => $labels,
                        'series' => $series,
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $widgets;
    }

    /**
     * One relation aggregate for a widget spec:
     * {relation, aggregate: count|sum|avg, column?, filter?}
     * with the declared-stats filter semantics ('!' excludes).
     */
    protected static function widgetAggregate(mixed $record, array $spec): ?float
    {
        $relation = (string) ($spec['relation'] ?? '');
        if ($relation === '' || ! is_object($record) || ! method_exists($record, $relation)) {
            return null;
        }
        $query = $record->{$relation}();
        if (! is_object($query)) {
            return null;
        }
        foreach (($spec['filter'] ?? []) as $column => $value) {
            if (is_array($value)) {
                $exclude = array_values(array_filter($value, fn ($v) => is_string($v) && str_starts_with($v, '!')));
                $include = array_values(array_diff($value, $exclude));
                if ($exclude !== []) {
                    $query = $query->whereNotIn((string) $column, array_map(fn ($v) => substr($v, 1), $exclude));
                }
                if ($include !== []) {
                    $query = $query->whereIn((string) $column, $include);
                }
            } else {
                $query = $query->where((string) $column, $value);
            }
        }

        return match ((string) ($spec['aggregate'] ?? 'count')) {
            'sum' => (float) $query->sum((string) ($spec['column'] ?? '')),
            'avg' => (float) $query->avg((string) ($spec['column'] ?? '')),
            default => (float) $query->count(),
        };
    }

    protected static function trimNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    public static function showStatsFromManifest(array $manifest, mixed $record = null): array
    {
        $defs = $manifest['ui']['show']['stats'] ?? [];
        if (! is_array($defs) || $defs === [] || ! is_object($record)) {
            return [];
        }

        $stats = [];
        foreach ($defs as $def) {
            if (! is_array($def)) {
                continue;
            }
            $relation = (string) ($def['relation'] ?? '');
            $handle = (string) ($def['handle'] ?? $relation);
            if ($relation === '' || $handle === '' || ! method_exists($record, $relation)) {
                continue;
            }
            try {
                $query = $record->{$relation}();
                if (! is_object($query)) {
                    continue;
                }
                foreach (($def['filter'] ?? []) as $column => $value) {
                    if (is_array($value)) {
                        // '!'-prefixed values exclude (whereNotIn) —
                        // e.g. open tasks = board column is not the
                        // complete column, the source app's own
                        // definition, which no status filter expresses.
                        $exclude = array_values(array_filter($value, fn ($v) => is_string($v) && str_starts_with($v, '!')));
                        $include = array_values(array_diff($value, $exclude));
                        if ($exclude !== []) {
                            $query = $query->whereNotIn((string) $column, array_map(fn ($v) => substr($v, 1), $exclude));
                        }
                        if ($include !== []) {
                            $query = $query->whereIn((string) $column, $include);
                        }
                    } else {
                        $query = $query->where((string) $column, $value);
                    }
                }
                $aggregate = (string) ($def['aggregate'] ?? 'count');
                $raw = match ($aggregate) {
                    'sum' => (float) $query->sum((string) ($def['column'] ?? '')),
                    'avg' => (float) $query->avg((string) ($def['column'] ?? '')),
                    default => (int) $query->count(),
                };
            } catch (\Throwable $e) {
                continue;
            }
            $stats[] = [
                'handle' => $handle,
                'label' => (string) ($def['label'] ?? \Illuminate\Support\Str::headline($handle)),
                'value' => $aggregate === 'count'
                    ? number_format((int) $raw)
                    : rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.'),
            ];
        }

        return $stats;
    }

    /**
     * Index the manifest's field definitions by field name, from both the
     * top-level `fields` map and each model's `fields` list.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, mixed>>
     */
    /**
     * Resolve a declared printable document (G2): ui.show.document
     * turns a record into a document structure — header fields, a
     * line-items table (from the declared relation or repeater), and
     * a totals block. This is the standard "document" pattern every
     * slice inherits by declaring it; the source application's
     * invoice documents are the reference shape.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{title: string, header: list<array{label: string, value: string}>, items: ?array{columns: list<array{handle: string, label: string}>, rows: list<list<string>>}, totals: list<array{label: string, value: string}>}|null
     */
    public static function documentFromManifest(array $manifest, mixed $record): ?array
    {
        $decl = $manifest['ui']['show']['document'] ?? null;
        if (! is_array($decl) || ! is_object($record)) {
            return null;
        }
        $fields = self::fieldIndex($manifest);
        $attributes = method_exists($record, 'getAttributes') ? $record->getAttributes() : [];
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'company_id', 'tenant_id', 'organization_id', 'workflow_state_entered_at'];

        $header = [];
        foreach (($decl['fields'] ?? array_keys($fields)) as $handle) {
            $handle = (string) $handle;
            if (in_array($handle, $skip, true) || ! array_key_exists($handle, $attributes)) {
                continue;
            }
            $header[] = [
                'label' => (string) (($fields[$handle]['label'] ?? null) ?? \Illuminate\Support\Str::headline($handle)),
                'value' => self::documentValue($handle, $fields[$handle] ?? [], $attributes[$handle]),
            ];
        }

        $items = null;
        $itemsHandle = (string) ($decl['line_items'] ?? '');
        if ($itemsHandle === '') {
            foreach ($fields as $handle => $def) {
                if (($def['type'] ?? null) === 'repeater') {
                    $itemsHandle = (string) $handle;
                    break;
                }
            }
        }
        if ($itemsHandle !== '' && method_exists($record, $itemsHandle)) {
            try {
                $rows = $record->{$itemsHandle};
                $rows = is_iterable($rows) ? collect($rows) : collect();
                $columns = [];
                $repeaterFields = $fields[$itemsHandle]['repeater_fields'] ?? [];
                if (is_array($repeaterFields) && $repeaterFields !== []) {
                    foreach ($repeaterFields as $rf) {
                        if (is_array($rf) && ! empty($rf['handle'])) {
                            $columns[] = ['handle' => (string) $rf['handle'], 'label' => (string) ($rf['label'] ?? \Illuminate\Support\Str::headline((string) $rf['handle']))];
                        }
                    }
                }
                $formatted = [];
                foreach ($rows as $row) {
                    $rowAttrs = is_object($row) && method_exists($row, 'getAttributes') ? $row->getAttributes() : (is_array($row) ? $row : []);
                    if ($columns === [] && $rowAttrs !== []) {
                        foreach (array_keys($rowAttrs) as $key) {
                            if (in_array($key, $skip, true) || str_ends_with((string) $key, '_id')) {
                                continue;
                            }
                            $columns[] = ['handle' => (string) $key, 'label' => \Illuminate\Support\Str::headline((string) $key)];
                            if (count($columns) >= 6) {
                                break;
                            }
                        }
                    }
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = self::documentValue($col['handle'], [], $rowAttrs[$col['handle']] ?? null);
                    }
                    $formatted[] = $line;
                }
                if ($columns !== []) {
                    $items = ['columns' => $columns, 'rows' => $formatted];
                }
            } catch (\Throwable) {
                $items = null;
            }
        }

        $totals = [];
        foreach (($decl['totals'] ?? []) as $handle) {
            $handle = (string) $handle;
            if (! array_key_exists($handle, $attributes)) {
                continue;
            }
            $totals[] = [
                'label' => (string) (($fields[$handle]['label'] ?? null) ?? \Illuminate\Support\Str::headline($handle)),
                'value' => self::documentValue($handle, $fields[$handle] ?? [], $attributes[$handle]),
            ];
        }

        return [
            'title' => (string) ($decl['title'] ?? \Illuminate\Support\Str::headline((string) ($manifest['name'] ?? 'Document'))),
            'header' => $header,
            'items' => $items,
            'totals' => $totals,
        ];
    }

    protected static function documentValue(string $handle, array $def, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }
        if (is_bool($raw)) {
            return $raw ? 'Yes' : 'No';
        }
        $inference = \LaraSlice\Support\FieldInference::analyze($handle, (string) ($def['type'] ?? 'string'));
        if ((($inference['is_money'] ?? false) || ($inference['formatter'] ?? null) === 'money') && is_numeric($raw)) {
            return number_format((float) $raw, 2);
        }
        if (is_string($raw) && (str_ends_with($handle, '_at') || str_contains((string) ($def['type'] ?? ''), 'date'))) {
            $ts = strtotime($raw);
            if ($ts !== false) {
                return date('M j, Y', $ts);
            }
        }

        return is_scalar($raw) ? (string) $raw : json_encode($raw);
    }

    /**
     * Resolve a declared matrix directory view (G2): records become
     * a rows x days-of-month grid — the standard pattern behind
     * timesheet/attendance-style screens. Declared as
     * ui.directory.matrix { row_key, date_field, cell_field?, marker?,
     * title? }. Pure transform: the controller fetches the month's
     * records (declared-filter criteria), this builds the grid.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<int|string, string>  $labelMap  row_key value => label (relationship options)
     * @return array{title: string, days: list<array{n: int, dow: string, weekend: bool}>, rows: list<array{key: mixed, label: string, cells: array<int, string>, present: int}>, month: int, year: int}|null
     */
    public static function matrixFromManifest(array $manifest, iterable $records, int $month, int $year, array $labelMap = []): ?array
    {
        $decl = $manifest['ui']['directory']['matrix'] ?? null;
        if (! is_array($decl) || empty($decl['row_key']) || empty($decl['date_field'])) {
            return null;
        }
        $rowKey = (string) $decl['row_key'];
        $dateField = (string) $decl['date_field'];
        $cellField = isset($decl['cell_field']) ? (string) $decl['cell_field'] : null;
        $marker = (string) ($decl['marker'] ?? 'P');
        $labelField = isset($decl['row_label']) ? (string) $decl['row_label'] : (str_ends_with($rowKey, '_id') ? substr($rowKey, 0, -3).'_name' : $rowKey);

        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $days = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ts = mktime(0, 0, 0, $month, $d, $year);
            $dow = (int) date('N', $ts);
            $days[] = ['n' => $d, 'dow' => date('D', $ts), 'weekend' => $dow >= 6];
        }

        $rows = [];
        foreach ($records as $record) {
            // Records arrive as Eloquent models (getAttributes), as
            // listing business objects (typed public properties), or
            // as plain arrays — accept all three.
            $attrs = match (true) {
                is_object($record) && method_exists($record, 'getAttributes') => $record->getAttributes(),
                is_array($record) => $record,
                is_object($record) => get_object_vars($record),
                default => [],
            };
            $rawDate = $attrs[$dateField] ?? null;
            $key = $attrs[$rowKey] ?? null;
            if ($rawDate === null || $key === null) {
                continue;
            }
            $ts = strtotime((string) $rawDate);
            if ($ts === false || (int) date('n', $ts) !== $month || (int) date('Y', $ts) !== $year) {
                continue;
            }
            $day = (int) date('j', $ts);
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'key' => $key,
                    'label' => (string) ($labelMap[$key] ?? $attrs[$labelField] ?? $key),
                    'cells' => [],
                    'present' => 0,
                ];
            }
            if (! isset($rows[$key]['cells'][$day])) {
                $cell = $cellField !== null && isset($attrs[$cellField]) && $attrs[$cellField] !== ''
                    ? mb_strimwidth((string) $attrs[$cellField], 0, 3)
                    : $marker;
                $rows[$key]['cells'][$day] = $cell;
                $rows[$key]['present']++;
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return [
            'title' => (string) ($decl['title'] ?? \Illuminate\Support\Str::headline((string) ($manifest['name'] ?? 'Matrix'))),
            'days' => $days,
            'rows' => $rows,
            'month' => $month,
            'year' => $year,
        ];
    }



    /**
     * Resolve a declared record timer (G8): a start/stop work
     * timer rendered on the show page, writing session rows to a
     * related log model. Declared as ui.show.timer:
     * { relation, start_field?, end_field?, user_field?, title? }.
     * One active timer per user across the whole log table —
     * starting a second one is refused (the source application's
     * rule). Stop computes total_hours/total_minutes when the log
     * table carries those columns.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{relation: string, start_field: string, end_field: string, user_field: string, title: string}|null
     */
    public static function timerFromManifest(array $manifest): ?array
    {
        $decl = $manifest['ui']['show']['timer'] ?? null;
        if (! is_array($decl) || empty($decl['relation'])) {
            return null;
        }

        return [
            'relation' => (string) $decl['relation'],
            'start_field' => (string) ($decl['start_field'] ?? 'start_time'),
            'end_field' => (string) ($decl['end_field'] ?? 'end_time'),
            'user_field' => (string) ($decl['user_field'] ?? 'user_id'),
            'title' => (string) ($decl['title'] ?? 'Timer'),
        ];
    }

    /**
     * Resolve a declared bulk-entry view (G8): a one-date grid over a
     * row source (the row key's relationship options) with per-row
     * editable fields — the standard pattern behind screens like
     * Mark Attendance. Declared as ui.directory.bulk_entry:
     * { row_key?, date_field?, fields: [handle | {handle, input?,
     * options?}], title? }. row_key/date_field default to the
     * matrix declaration's when present.
     *
     * Field inputs: time | datetime | text | select. A datetime
     * field renders as a time input whose value is composed with
     * the entry date on save. Labels default to the directory
     * column labels.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{title: string, row_key: string, date_field: string, fields: list<array{handle: string, label: string, input: string, options: list<string>}>}|null
     */
    /**
     * Declared CSV import (P13): ui.directory.import =
     * {format: csv, key?: <field for upsert>, columns: {CSV header:
     * field}}. The Mind declares the pipeline + dedupe rule in
     * plain words; the Heart declares the executable map here.
     */
    public static function importFromManifest(array $manifest): ?array
    {
        $decl = $manifest['ui']['directory']['import'] ?? null;
        if (! is_array($decl) || empty($decl['columns']) || ! is_array($decl['columns'])) {
            return null;
        }
        $columns = [];
        foreach ($decl['columns'] as $header => $field) {
            if (is_string($header) && is_string($field) && $field !== '') {
                $columns[$header] = $field;
            }
        }
        if ($columns === []) {
            return null;
        }

        return [
            'format' => (string) ($decl['format'] ?? 'csv'),
            'key' => isset($decl['key']) && is_string($decl['key']) && $decl['key'] !== '' ? $decl['key'] : null,
            'columns' => $columns,
        ];
    }

    /**
     * Declared invite/provisioning (P14): ui.directory.invite =
     * {link: user_id, name_field: full_name, role?: Employee}.
     * Lists records whose link field is empty; provisioning
     * creates the user, assigns the role club, links the record,
     * and sends the activation (password-reset) email.
     */
    public static function inviteFromManifest(array $manifest): ?array
    {
        $decl = $manifest['ui']['directory']['invite'] ?? null;
        if (! is_array($decl) || empty($decl['link'])) {
            return null;
        }

        return [
            'link' => (string) $decl['link'],
            'name_field' => (string) ($decl['name_field'] ?? 'name'),
            'role' => isset($decl['role']) && is_string($decl['role']) ? $decl['role'] : null,
        ];
    }

    public static function bulkEntryFromManifest(array $manifest): ?array
    {
        $decl = $manifest['ui']['directory']['bulk_entry'] ?? null;
        if (! is_array($decl)) {
            return null;
        }
        $matrix = $manifest['ui']['directory']['matrix'] ?? [];
        $rowKey = (string) ($decl['row_key'] ?? $matrix['row_key'] ?? '');
        $dateField = (string) ($decl['date_field'] ?? $matrix['date_field'] ?? '');
        if ($rowKey === '' || $dateField === '' || empty($decl['fields']) || ! is_array($decl['fields'])) {
            return null;
        }

        $labels = [];
        foreach (($manifest['ui']['directory']['columns'] ?? []) as $col) {
            if (is_array($col) && ! empty($col['key'])) {
                $labels[(string) $col['key']] = (string) ($col['label'] ?? \Illuminate\Support\Str::headline((string) $col['key']));
            }
        }

        $fields = [];
        foreach ($decl['fields'] as $f) {
            if (is_string($f)) {
                $f = ['handle' => $f];
            }
            if (! is_array($f) || empty($f['handle'])) {
                continue;
            }
            $handle = (string) $f['handle'];
            // Options are flat values; a {value, label} entry also
            // contributes its label (id-valued selects, e.g. shift
            // pickers in a bulk-assign grid).
            $options = [];
            $optionLabels = [];
            foreach ((array) ($f['options'] ?? []) as $opt) {
                if (is_array($opt) && isset($opt['value'])) {
                    $options[] = (string) $opt['value'];
                    if (isset($opt['label'])) {
                        $optionLabels[(string) $opt['value']] = (string) $opt['label'];
                    }
                } else {
                    $options[] = (string) $opt;
                }
            }
            $fields[] = [
                'handle' => $handle,
                'label' => (string) ($f['label'] ?? $labels[$handle] ?? \Illuminate\Support\Str::headline($handle)),
                'input' => (string) ($f['input'] ?? 'text'),
                'options' => $options,
                'option_labels' => $optionLabels,
            ];
        }
        if ($fields === []) {
            return null;
        }

        // Range mode (P10 extension): one submission applies the
        // same row values across a date range — the source
        // systems' bulk-mark semantics — skipping declared
        // holiday dates (and weekends when declared).
        $holidays = null;
        if (! empty($decl['holidays']) && is_array($decl['holidays']) && ! empty($decl['holidays']['slice'])) {
            $holidays = [
                'slice' => (string) $decl['holidays']['slice'],
                'date_field' => (string) ($decl['holidays']['date_field'] ?? 'date'),
            ];
        }

        return [
            'title' => (string) ($decl['title'] ?? 'Bulk Entry'),
            'row_key' => $rowKey,
            'date_field' => $dateField,
            'fields' => $fields,
            'range' => (bool) ($decl['range'] ?? false),
            'skip_weekends' => (bool) ($decl['skip_weekends'] ?? false),
            'holidays' => $holidays,
        ];
    }

    /**
     * Resolve a declared report view (G2): records grouped by a field
     * with computed aggregates — the standard pattern behind tabular
     * report pages (group-by + count/sum/avg + a totals row).
     * Declared as ui.directory.report { group_by, aggregates:
     * [{fn: count|sum|avg, column?, label?}], date_field?, title? }.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<int|string, string>  $labelMap  group value => label (relationship options)
     * @return array{title: string, groupLabel: string, columns: list<string>, rows: list<array{label: string, values: list<string>}>, totals: list<string>, count: int}|null
     */
    public static function reportFromManifest(array $manifest, iterable $records, array $labelMap = []): ?array
    {
        $decl = $manifest['ui']['directory']['report'] ?? null;
        if (! is_array($decl) || empty($decl['group_by']) || empty($decl['aggregates']) || ! is_array($decl['aggregates'])) {
            return null;
        }
        $fields = self::fieldIndex($manifest);
        $groupBy = (string) $decl['group_by'];
        $groupDef = $fields[$groupBy] ?? [];
        $labelField = str_ends_with($groupBy, '_id') ? substr($groupBy, 0, -3).'_name' : null;

        $aggregates = [];
        foreach ($decl['aggregates'] as $agg) {
            if (is_array($agg) && ! empty($agg['fn'])) {
                $aggregates[] = [
                    'fn' => in_array($agg['fn'], ['sum', 'avg'], true) ? $agg['fn'] : 'count',
                    'column' => isset($agg['column']) ? (string) $agg['column'] : null,
                    'label' => (string) ($agg['label'] ?? \Illuminate\Support\Str::headline((string) ($agg['column'] ?? 'count'))),
                ];
            }
        }
        if ($aggregates === []) {
            return null;
        }

        $groups = [];
        $all = [];
        foreach ($records as $record) {
            $attrs = match (true) {
                is_object($record) && method_exists($record, 'getAttributes') => $record->getAttributes(),
                is_array($record) => $record,
                is_object($record) => get_object_vars($record),
                default => [],
            };
            $all[] = $attrs;
            $key = $attrs[$groupBy] ?? null;
            $key = $key === null ? '—' : (string) $key;
            $groups[$key] ??= ['label' => (string) ($labelMap[$key] ?? ($labelField ? ($attrs[$labelField] ?? null) : null) ?? self::optionLabel($groupDef, $key) ?? $key), 'rows' => []];
            $groups[$key]['rows'][] = $attrs;
        }

        $compute = function (array $rows) use ($aggregates, $fields): array {
            $values = [];
            foreach ($aggregates as $agg) {
                if ($agg['fn'] === 'count' || $agg['column'] === null) {
                    $values[] = number_format(count($rows));
                    continue;
                }
                $col = $agg['column'];
                $nums = array_filter(array_map(fn ($r) => $r[$col] ?? null, $rows), fn ($v) => is_numeric($v));
                $raw = $agg['fn'] === 'sum' ? array_sum($nums) : (count($nums) ? array_sum($nums) / count($nums) : 0);
                $values[] = self::documentValue($col, $fields[$col] ?? [], $raw);
            }

            return $values;
        };

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = ['label' => $group['label'], 'values' => $compute($group['rows'])];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return [
            'title' => (string) ($decl['title'] ?? \Illuminate\Support\Str::headline((string) ($manifest['name'] ?? 'Report')).' Report'),
            'groupLabel' => (string) ($decl['group_label'] ?? ($groupDef['label'] ?? \Illuminate\Support\Str::headline($groupBy))),
            'columns' => array_column($aggregates, 'label'),
            'rows' => $rows,
            'totals' => $compute($all),
            'count' => count($all),
        ];
    }

    protected static function optionLabel(array $fieldDef, string $value): ?string
    {
        $options = $fieldDef['options'] ?? null;
        if (! is_array($options)) {
            return null;
        }
        foreach ($options as $k => $opt) {
            if (is_array($opt) && isset($opt['value']) && (string) $opt['value'] === $value) {
                return isset($opt['label']) ? (string) $opt['label'] : null;
            }
            if (is_string($k) && $k === $value && is_string($opt)) {
                return $opt;
            }
        }

        return null;
    }

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
    /**
     * Options for a column from the directory's filter
     * declarations when the field itself declares none.
     */
    protected static function filterDeclaredOptions(array $manifest, string $handle, string $sliceName): array
    {
        $directory = $manifest['ui']['directory'] ?? [];
        foreach (['quick_filters', 'sidebar_filters'] as $group) {
            foreach ((array) ($directory[$group] ?? []) as $filter) {
                if (! is_array($filter) || ($filter['handle'] ?? null) !== $handle) {
                    continue;
                }
                if (! empty($filter['options'])) {
                    return self::normalizeOptions($filter['options']);
                }
                $from = (string) ($filter['options_from'] ?? '');
                if (str_starts_with($from, 'distinct:')) {
                    $column = substr($from, strlen('distinct:'));
                    try {
                        $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
                        // Models are usually the singular of the
                        // slice name (Leaves -> Leaf).
                        $modelClass = $manager->modelClass(\Illuminate\Support\Str::studly($sliceName))
                            ?? $manager->modelClass(\Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($sliceName)));
                        if ($modelClass && class_exists($modelClass)) {
                            $values = $modelClass::query()->distinct()->pluck($column);
                            $map = [];
                            foreach ($values as $value) {
                                if (is_scalar($value) && (string) $value !== '') {
                                    $map[(string) $value] = \Illuminate\Support\Str::headline((string) $value);
                                }
                            }

                            return $map;
                        }
                    } catch (\Throwable) {
                        return [];
                    }
                }
            }
        }

        return [];
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
