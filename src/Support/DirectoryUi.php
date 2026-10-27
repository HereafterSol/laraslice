<?php

namespace LaraSlice\Support;

use LaraSlice\Core\Audit\AuditLogger;
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
        'code_badge', 'composite_user', 'user_stack', 'status_pill', 'money_breakdown', 'lines',
        'progress_bar', 'badge', 'text_with_badge', 'date_overdue', 'timer'];

    /**
     * Merge ui.directory.columns from the live slice manifest over $fallback.
     *
     * @param  list<array<string, mixed>>  $fallback
     * @return list<array<string, mixed>>
     */
    /**
     * Option-level filter criteria (fidelity vocabulary): a
     * declared filter option may carry `criteria` — a map of
     * field => {op, value} conditions that REPLACE the plain
     * handle equality when that option is chosen. This is how
     * computed facets are declared: the source's "Overdue"
     * status (deadline in the past, not finished/canceled) and
     * its progress buckets (completion between two bounds).
     * Returns the condition list for the base service's
     * __conditions criterion, or null when the chosen option
     * carries no criteria (plain equality applies). Value
     * tokens: $today (Y-m-d), $now (datetime), $user (id).
     *
     * @return list<array{field: string, op: string, value: mixed}>|null
     */
    public static function optionConditions(array $declared, mixed $value, ?int $userId = null): ?array
    {
        foreach ((array) ($declared['options'] ?? []) as $option) {
            if (! is_array($option) || ! array_key_exists('value', $option)
                || (string) $option['value'] !== (string) $value
                || empty($option['criteria']) || ! is_array($option['criteria'])) {
                continue;
            }
            $resolve = static function ($v) use ($userId) {
                return match ($v) {
                    '$today' => date('Y-m-d'),
                    '$now' => date('Y-m-d H:i:s'),
                    '$user' => $userId,
                    default => $v,
                };
            };
            $conditions = [];
            foreach ($option['criteria'] as $field => $cond) {
                if (! is_array($cond) || empty($cond['op'])) {
                    continue;
                }
                $raw = $cond['value'] ?? null;
                $conditions[] = [
                    'field' => (string) $field,
                    'op' => (string) $cond['op'],
                    'value' => is_array($raw) ? array_map($resolve, $raw) : $resolve($raw),
                ];
            }

            return $conditions === [] ? null : $conditions;
        }

        return null;
    }

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
            $options = self::normalizeOptions($c['options'] ?? null);
            if ($options === []) {
                $options = self::normalizeOptions($def['options'] ?? null);
            }
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
                'progress_field' => $c['progress_field'] ?? null,
                'breakdown' => $c['breakdown'] ?? null,
                'label_table' => $c['label_table'] ?? null,
                'label_column' => $c['label_column'] ?? null,
                'color_from' => $c['color_from'] ?? null,
                'relation' => $c['relation'] ?? null,
                'lines' => $c['lines'] ?? null,
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
    /**
     * The record's own label for detail headers (QA5, 25 Oct
     * 2026): the generated chain (title → name → full_name →
     * heading → subject) missed the records whose identity is a
     * {thing}_name or a document number, so project and invoice
     * pages titled themselves "Project #1" / "Invoice #1" while
     * the record's real name sat in the fields below. Candidates
     * first, then any {thing}_name attribute, then null (the
     * caller keeps its "Slice #id" fallback).
     */
    public static function recordLabel(mixed $record): ?string
    {
        if (! is_object($record)) {
            return null;
        }
        foreach (['title', 'name', 'full_name', 'heading', 'subject', 'label'] as $candidate) {
            $value = data_get($record, $candidate);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }
        // A {thing}_name beats a code/number: a project is
        // titled by its name, not its short code.
        $attrs = method_exists($record, 'getAttributes')
            ? array_keys($record->getAttributes())
            : array_keys(get_object_vars($record));
        foreach ($attrs as $attr) {
            if (str_ends_with((string) $attr, '_name')) {
                $value = data_get($record, (string) $attr);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }
        foreach (['invoice_number', 'order_number', 'code', 'reference', 'number'] as $candidate) {
            $value = data_get($record, $candidate);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Identity subtitle for a detail header: the slice may declare
     * ui.show.identity_fields as `from` paths on the record (its own
     * attributes or dotted relation paths, e.g. designation +
     * department for a person). Resolved values join with ' • '.
     * Null when nothing is declared or nothing resolves — callers
     * keep their generic fallback.
     */
    public static function identitySubtitle(string $sliceName, mixed $record): ?string
    {
        if (! is_object($record)) {
            return null;
        }
        $manifest = self::readManifest($sliceName);
        $paths = $manifest['ui']['show']['identity_fields'] ?? null;
        if (! is_array($paths) || $paths === []) {
            return null;
        }
        $parts = [];
        foreach ($paths as $path) {
            $from = is_array($path) ? ($path['from'] ?? null) : $path;
            if (! is_string($from) || $from === '') {
                continue;
            }
            try {
                $value = data_get($record, $from);
            } catch (\Throwable $e) {
                $value = null;
            }
            if (is_scalar($value) && (string) $value !== '') {
                $parts[] = (string) $value;
            }
        }

        return $parts !== [] ? implode(' • ', $parts) : null;
    }

    /**
     * The detail card's heading: declared per slice
     * (ui.show.detail_title), generic otherwise.
     */
    public static function detailTitle(string $sliceName): string
    {
        $manifest = self::readManifest($sliceName);
        $title = $manifest['ui']['show']['detail_title'] ?? null;

        return is_string($title) && $title !== '' ? $title : 'Attribute Parameters';
    }

    public static function detailRows(string $sliceName, mixed $record): array
    {
        $manifest = self::readManifest($sliceName);

        // H3: a declared profile composition wins outright. Entries
        // name a label and a `from` path resolved against the record —
        // its own attributes or dotted relation paths
        // (employeeDetail.designation.name, user.email) — so a detail
        // page can present a curated profile that spans the record's
        // relations instead of one table's columns.
        $declared = $manifest['ui']['show']['detail_fields'] ?? null;
        if (is_array($declared) && $declared !== [] && is_object($record)) {
            $rows = [];
            foreach ($declared as $entry) {
                if (! is_array($entry) || empty($entry['from'])) {
                    continue;
                }
                // A declared path that cannot resolve (a relation the
                // data model lacks) renders as an em dash — a broken
                // declaration must never break the detail page.
                try {
                    $value = data_get($record, (string) $entry['from']);
                } catch (\Throwable $e) {
                    $value = null;
                }
                $type = (string) ($entry['type'] ?? 'text');
                $text = match ($type) {
                    'date', 'datetime' => DataTableRows::formatDateValue($value, $type),
                    'status' => (string) ($value ?? '—'),
                    default => (string) DataTableRows::display($value),
                };
                $rows[] = [
                    'label' => (string) ($entry['label'] ?? \Illuminate\Support\Str::headline((string) $entry['from'])),
                    'value' => $text === '' ? '—' : $text,
                    'html' => false,
                ];
            }
            if ($rows !== []) {
                return $rows;
            }
        }

        $fields = self::fieldIndex($manifest);
        $attributes = is_object($record) && method_exists($record, 'getAttributes')
            ? $record->getAttributes()
            : (is_array($record) ? $record : []);
        if ($fields === [] || $attributes === []) {
            return [];
        }

        // System/audit columns never reach a detail page (the same
        // hygiene law as forms, Tier 0): ownership stamps, approval
        // stamps and tenant keys are machinery, not profile content.
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'company_id', 'tenant_id', 'organization_id', 'workflow_state_entered_at',
            'added_by', 'last_updated_by', 'created_by', 'updated_by', 'approved_by', 'approved_at', 'event_id', 'password', 'remember_token'];
        $columns = [];
        foreach ($fields as $name => $def) {
            $name = (string) $name;
            if (in_array($name, $skip, true) || str_ends_with($name, '_by') || str_ends_with($name, '_ip') || ! array_key_exists($name, $attributes) || ! empty($def['hidden'])) {
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
     * Declared `type: audit` tabs (e.g. an Activity tab) are first-class
     * too: they carry no relation; with a record they gain the record's
     * audit-trail `entries` (AuditLogger, keyed by model class + id)
     * and a `count` — an empty trail renders the panel's empty state,
     * never a silently missing tab.
     *
     * Curation (hub tab curation, 27 Oct 2026): `ui.show.exclude_tabs`
     * is a list of relation names and/or tab handles whose tabs must
     * NOT render — the exclusion wins over every source above,
     * declared tabs included. A declared tab carrying `hidden: true`
     * is likewise suppressed, together with its inferred twin (the
     * exclusion is registered under both its relation and its handle).
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
        $exclude = self::excludedTabKeys($manifest);
        $add = static function (string $relation, ?string $label, ?string $handle = null, array $extras = []) use (&$tabs, &$seen, &$exclude): void {
            // Curation: ui.show.exclude_tabs (and hidden declarations,
            // which register here) suppress a tab by relation OR by
            // handle — the exclusion wins over every tab source.
            $effectiveHandle = $handle !== null && $handle !== '' ? $handle : $relation;
            if (isset($exclude[$relation]) || isset($exclude[$effectiveHandle])) {
                return;
            }
            // Dedupe is by RELATION for plain tabs (a declared
            // tab, a hasMany relation and a repeater naming the
            // same relation are one tab). A RENDER-MODE tab
            // (board / gantt) is an additional presentation of
            // its relation, keyed by its declared handle — a
            // table and a board over one relation are two tabs.
            $isRenderMode = in_array($extras['render'] ?? 'table', ['board', 'gantt'], true);
            $key = $isRenderMode ? ($handle !== null && $handle !== '' ? $handle : $relation) : $relation;
            if ($relation === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $tabs[] = array_merge([
                'handle' => $isRenderMode ? $key : $relation,
                'label' => $label !== null && $label !== '' ? $label : \Illuminate\Support\Str::headline($relation),
                'type' => 'relation',
                'relation' => $relation,
            ], $extras);
        };

        foreach (($manifest['ui']['show']['tabs'] ?? []) as $tab) {
            if (! is_array($tab)) {
                continue;
            }
            if (! empty($tab['hidden'])) {
                // A hidden declaration suppresses the tab AND its
                // inferred twin: register the exclusion under both
                // the relation and the handle before any source runs.
                $hiddenRelation = (string) ($tab['relation'] ?? $tab['handle'] ?? '');
                if ($hiddenRelation !== '') {
                    $exclude[$hiddenRelation] = true;
                }
                if (! empty($tab['handle'])) {
                    $exclude[(string) $tab['handle']] = true;
                }
                continue;
            }
            if (($tab['type'] ?? '') === 'relation') {
                $extras = [];
                foreach (['render', 'group_by', 'start_field', 'end_field', 'label_field'] as $k) {
                    if (! empty($tab[$k])) {
                        $extras[$k] = (string) $tab[$k];
                    }
                }
                $add(
                    (string) ($tab['relation'] ?? $tab['handle'] ?? ''),
                    isset($tab['label']) ? (string) $tab['label'] : null,
                    isset($tab['handle']) ? (string) $tab['handle'] : null,
                    $extras
                );
            } elseif (($tab['type'] ?? '') === 'audit') {
                // Audit tabs carry no relation: they render the
                // record's audit trail (entries attached below when
                // a record is supplied) or its empty state — never
                // silently nothing.
                $handle = (string) ($tab['handle'] ?? 'activity');
                if ($handle === '' || isset($seen['audit:'.$handle]) || isset($exclude[$handle])) {
                    continue;
                }
                $seen['audit:'.$handle] = true;
                $tabs[] = [
                    'handle' => $handle,
                    'label' => ! empty($tab['label']) ? (string) $tab['label'] : 'Activity',
                    'type' => 'audit',
                    'relation' => '',
                ];
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
                if (($tab['type'] ?? '') === 'audit') {
                    $entries = self::auditEntriesForRecord($record);
                    $tabs[$i]['entries'] = $entries;
                    $tabs[$i]['count'] = count($entries);

                    continue;
                }
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
                // H1: a hub tab renders the RELATED slice's declared
                // list definition (columns + DataTableRows values),
                // never a generic ID/Summary/Status skeleton. Tabs that
                // cannot be resolved are flagged 'unresolved' so the
                // drift/presentation checks can catch them.
                $tabs[$i] = self::enrichTabularTab($tabs[$i], $record);
            }
        }

        return $tabs;
    }

    /**
     * The tab-curation exclusion set: `ui.show.exclude_tabs` entries,
     * keyed for lookup. Each entry names a relation or a tab handle
     * whose tab must not render (see relatedTabsFromManifest).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, true>
     */
    public static function excludedTabKeys(array $manifest): array
    {
        $set = [];
        foreach ((array) ($manifest['ui']['show']['exclude_tabs'] ?? []) as $key) {
            if (is_string($key) && $key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }

    /**
     * The declared label for the fields (record detail) tab — the
     * `ui.show.tabs` entry of `type: fields`, e.g. `label: Profile`
     * on people-shaped hubs. Null when no fields tab is declared
     * (or it declares no label): callers fall back to their default
     * wording ('Overview' on the overview hub strip).
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function fieldsTabLabelFromManifest(array $manifest): ?string
    {
        foreach ((array) ($manifest['ui']['show']['tabs'] ?? []) as $tab) {
            if (is_array($tab) && ($tab['type'] ?? '') === 'fields' && ! empty($tab['label'])) {
                return (string) $tab['label'];
            }
        }

        return null;
    }

    /**
     * The record's audit trail for an audit tab, from the framework's
     * audit infrastructure (AuditLogger::forEntity — entries are
     * recorded with entity_type = model class, entity_id = key).
     * Best-effort by design: no audit table, no container or any
     * query failure yields an empty list, and the tab renders its
     * empty state instead of breaking the detail page.
     *
     * @return list<array{action: string, slice: string, actor: string, created_at: string, old_values: array|null, new_values: array|null}>
     */
    protected static function auditEntriesForRecord(object $record): array
    {
        try {
            $key = method_exists($record, 'getKey') ? $record->getKey() : ($record->id ?? null);
            if ($key === null || $key === '') {
                return [];
            }

            return AuditLogger::forEntity(get_class($record), $key, 50)
                ->map(static fn ($row): array => [
                    'action' => (string) ($row->action ?? ''),
                    'slice' => (string) ($row->slice ?? ''),
                    'actor' => (string) ($row->actor_email ?? $row->actor_id ?? 'System'),
                    'created_at' => (string) ($row->created_at ?? ''),
                    'old_values' => is_array($row->old_values ?? null) ? $row->old_values : null,
                    'new_values' => is_array($row->new_values ?? null) ? $row->new_values : null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @var array<string, string>|null table name => owning slice name */
    private static ?array $sliceNameByTable = null;

    /**
     * Map a database table to the slice that owns it (root model wins),
     * scanning the discovered manifests once per request.
     */
    private static function sliceNameForTable(string $table): ?string
    {
        if (self::$sliceNameByTable === null) {
            self::$sliceNameByTable = [];
            try {
                if (function_exists('app') && app()->bound(SliceManager::class)) {
                    $manager = app(SliceManager::class);
                    $manager->discover();
                    foreach ($manager->discoveredSlices() as $slice) {
                        // Root ownership via the manifest's own table
                        // resolution (handles every manifest shape,
                        // including porter-era manifests with no
                        // models section at all).
                        try {
                            $primary = $slice->primaryTable();
                        } catch (\Throwable $e) {
                            $primary = null;
                        }
                        if (is_string($primary) && $primary !== '') {
                            self::$sliceNameByTable[$primary] = (string) $slice->name;
                        }
                        // Declared child models in list-shaped manifests.
                        foreach (($slice->raw['models'] ?? []) as $model) {
                            if (! is_array($model) || empty($model['table'])) {
                                continue;
                            }
                            $t = (string) $model['table'];
                            if (! isset(self::$sliceNameByTable[$t]) || ! empty($model['root'])) {
                                self::$sliceNameByTable[$t] = (string) $slice->name;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Enrichment is best-effort; tabs fall back unflagged-free.
            }
        }

        return self::$sliceNameByTable[$table] ?? null;
    }

    /**
     * H1 enrichment: resolve the tab relation's owning slice, take its
     * declared directory columns (or a field-derived fallback), fetch
     * the rows through the relation and render every cell through the
     * same DataTableRows pipeline the listings use — FK labels, option
     * labels, composite people cells, progress bars and all.
     *
     * @param  array<string, mixed>  $tab
     * @return array<string, mixed>
     */
    private static function enrichTabularTab(array $tab, object $record): array
    {
        $relation = (string) ($tab['relation'] ?? '');
        if ($relation === '' || ! method_exists($record, $relation)) {
            return $tab;
        }
        // Render-mode tabs (board / gantt) consume the RAW
        // related records in their own kit partials — the
        // tabular column pipeline does not apply.
        $render = (string) ($tab['render'] ?? 'table');
        if ($render === 'board' || $render === 'gantt') {
            try {
                $query = $record->{$relation}();
                if (is_object($query) && method_exists($query, 'get')) {
                    $tab['records'] = $query->limit(100)->get();
                }
            } catch (\Throwable $e) {
                $tab['records'] = [];
            }

            return $tab;
        }
        try {
            $query = $record->{$relation}();
            if (! is_object($query) || ! method_exists($query, 'getRelated')) {
                return $tab;
            }
            $related = $query->getRelated();
            $sliceName = self::sliceNameForTable($related->getTable());
            if ($sliceName === null) {
                return $tab + ['unresolved' => true];
            }
            $columns = self::columnsForSlice($sliceName);
            if ($columns === []) {
                $columns = self::fallbackTabColumns($sliceName);
            }
            // Sanity: the resolved slice's columns must describe THIS
            // relation's table. Name/model resolution ambiguities can
            // bind a child table to a sibling slice (the shifts/quota
            // tangle); when no declared column exists on the related
            // table, derive columns from the model itself instead of
            // rendering another slice's directory — and skip that
            // slice's service decoration, which would discard the
            // child's fields the same way.
            $sliceOwnsTable = $columns !== [] && self::columnsDescribeTable($columns, $related->getTable());
            if (! $sliceOwnsTable) {
                $foreignKey = null;
                if ($query instanceof \Illuminate\Database\Eloquent\Relations\HasOneOrMany) {
                    $foreignKey = $query->getForeignKeyName();
                }
                $columns = self::modelDerivedColumns($related->getTable(), $foreignKey);
            }
            if ($columns === []) {
                return $tab + ['unresolved' => true];
            }
            $rows = $query->limit(50)->get();
            // Decorate through the related slice's own listing
            // mapping when it has a service — tabs then show the
            // same computed values as the related directory itself.
            $service = $sliceOwnsTable ? self::serviceForSlice($sliceName) : null;
            if ($service !== null && method_exists($service, 'listingBusinessObjects')) {
                try {
                    $rows = $service->listingBusinessObjects($rows);
                } catch (\Throwable $e) {
                    // Raw models still render through the pipeline.
                }
            }
            // Tab content contract v2: a resolved tab is a working
            // list, not a picture of one — scoped create/export,
            // per-row actions and the related slice's declared
            // inline editor ride along (see tabActionSurface()).
            $surface = $sliceOwnsTable ? self::tabActionSurface($sliceName, $tab, $query, $record, $rows) : null;
            $tab['columns'] = $columns;
            $tab['rows'] = DataTableRows::from($rows, $columns, $surface['extra'] ?? null);
            if ($surface !== null) {
                foreach (['slice', 'create_url', 'create_label', 'export_name', 'inline'] as $k) {
                    if (isset($surface['tab'][$k])) {
                        $tab[$k] = $surface['tab'][$k];
                    }
                }
                if (! empty($surface['raw'])) {
                    foreach ($tab['rows'] as $i => $row) {
                        $rid = $row['__id'] ?? null;
                        if ($rid !== null && isset($surface['raw'][$rid])) {
                            $tab['rows'][$i]['__raw_'.$surface['tab']['inline']['field']] = $surface['raw'][$rid];
                        }
                    }
                }
            }

            return $tab;
        } catch (\Throwable $e) {
            return $tab + ['unresolved' => true];
        }
    }


    /** @var array<string, \LaraSlice\Core\Discovery\SliceManifest|null> slice name => manifest */
    private static array $manifestByName = [];

    private static function manifestForSlice(string $sliceName): ?\LaraSlice\Core\Discovery\SliceManifest
    {
        if (! array_key_exists($sliceName, self::$manifestByName)) {
            self::$manifestByName[$sliceName] = null;
            try {
                if (function_exists('app') && app()->bound(SliceManager::class)) {
                    $manager = app(SliceManager::class);
                    $manager->discover();
                    // Two slices can share one name (a fidelity
                    // slice and its porter twin). The manifest the
                    // tab's columns came from is the one
                    // readManifest() resolved — match by path
                    // first so the action surface (inline_edit,
                    // routes) comes from that same Heart.
                    $wantDir = null;
                    $mp = self::manifestPath($sliceName);
                    if ($mp !== null) {
                        $wantDir = dirname($mp);
                    }
                    $byName = null;
                    foreach ($manager->discoveredSlices() as $slice) {
                        if ((string) $slice->name !== $sliceName) {
                            continue;
                        }
                        if ($wantDir !== null && rtrim((string) $slice->path, '/') === rtrim($wantDir, '/')) {
                            self::$manifestByName[$sliceName] = $slice;
                            break;
                        }
                        $byName ??= $slice;
                    }
                    if (self::$manifestByName[$sliceName] === null) {
                        self::$manifestByName[$sliceName] = $byName;
                    }
                }
            } catch (\Throwable $e) {
                // Action surface is best-effort.
            }
        }

        return self::$manifestByName[$sliceName];
    }

    /**
     * The slice's web route-name prefix, parsed from its own
     * routes/web.php with the same pattern SliceManager uses for
     * declared-route registration.
     */
    private static function routePrefixForSlice(string $sliceName): ?string
    {
        $manifest = self::manifestForSlice($sliceName);
        if ($manifest === null) {
            return null;
        }
        try {
            $routesPath = $manifest->getRoutesPath();
            $webFile = $routesPath ? $routesPath.'/web.php' : null;
            if (! $webFile || ! is_file($webFile)) {
                return null;
            }
            if (preg_match("/Route::prefix\('([^']+)'\)->name\('([^']+)\.'\)/", (string) file_get_contents($webFile), $m)) {
                return $m[2];
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return null;
    }

    /**
     * Tab content contract v2 — the action surface of a resolved
     * tab, derived entirely from the RELATED slice's own Heart:
     * its routes (create/show/edit/destroy), its declared
     * ui.directory.inline_edit (the same allowlist the inline
     * endpoint enforces) and the relation's foreign key for the
     * scoped-create preset. Nothing here is hub-specific.
     *
     * @param  array<string, mixed>  $tab
     * @return array{extra: ?callable, tab: array<string, mixed>, raw: array<array-key, mixed>}|null
     */
    private static function tabActionSurface(string $sliceName, array $tab, object $query, object $record, iterable $rows): ?array
    {
        $prefix = self::routePrefixForSlice($sliceName);
        if ($prefix === null) {
            return null;
        }
        $out = ['extra' => null, 'tab' => ['slice' => $sliceName], 'raw' => []];
        try {
            // Row action URLs obey the RELATED slice's own permissions:
            // a viewer without its edit/delete slugs must not be handed
            // the controls (the endpoints enforce it too, but listings
            // show only the controls the user may use). When the related
            // manifest declares no such slugs there is nothing to gate
            // on and the URLs stay route-driven.
            $tabManifest = self::manifestForSlice($sliceName);
            $tabPerms = $tabManifest?->permissions ?? [];
            $editSlugs = array_values(array_filter($tabPerms, fn ($s) => str_ends_with((string) $s, '.edit') || str_ends_with((string) $s, '.update')));
            $deleteSlugs = array_values(array_filter($tabPerms, fn ($s) => str_ends_with((string) $s, '.delete') || str_ends_with((string) $s, '.destroy')));
            $tabUser = function_exists('auth') ? auth()->user() : null;
            $canEditTab = $editSlugs === [] || ($tabUser !== null && \LaraSlice\Core\Security\Access::allows($tabUser, $editSlugs));
            $canDeleteTab = $deleteSlugs === [] || ($tabUser !== null && \LaraSlice\Core\Security\Access::allows($tabUser, $deleteSlugs));
            if (\Illuminate\Support\Facades\Route::has($prefix.'.show')
                || \Illuminate\Support\Facades\Route::has($prefix.'.edit')
                || \Illuminate\Support\Facades\Route::has($prefix.'.destroy')) {
                $out['extra'] = static function ($item) use ($prefix, $canEditTab, $canDeleteTab) {
                    $id = $item instanceof \Illuminate\Database\Eloquent\Model ? $item->getKey() : ($item->id ?? null);
                    if ($id === null) {
                        return [];
                    }

                    return [
                        'show_url' => \Illuminate\Support\Facades\Route::has($prefix.'.show') ? route($prefix.'.show', $id) : null,
                        'edit_url' => $canEditTab && \Illuminate\Support\Facades\Route::has($prefix.'.edit') ? route($prefix.'.edit', $id) : null,
                        'delete_url' => $canDeleteTab && \Illuminate\Support\Facades\Route::has($prefix.'.destroy') ? route($prefix.'.destroy', $id) : null,
                    ];
                };
            }
            if (\Illuminate\Support\Facades\Route::has($prefix.'.create')) {
                $params = [];
                if ($query instanceof \Illuminate\Database\Eloquent\Relations\HasOneOrMany) {
                    $local = $query->getLocalKeyName();
                    $params[$query->getForeignKeyName()] = $record->{$local};
                }
                $out['tab']['create_url'] = route($prefix.'.create', $params);
                $label = (string) ($tab['label'] ?? $sliceName);
                $out['tab']['create_label'] = 'Add '.\Illuminate\Support\Str::singular($label);
                $out['tab']['export_name'] = \Illuminate\Support\Str::slug($label);
            }
            // Inline editor: the related slice's declared
            // inline_edit fields, restricted to fields that are
            // also columns of this tab; options mirror the
            // endpoint's own allowlist extraction.
            $manifest = self::manifestForSlice($sliceName);
            $raw = $manifest?->raw ?? [];
            $inlineFields = (array) ($raw['ui']['directory']['inline_edit'] ?? []);
            $columnKeys = array_column(self::columnsForSlice($sliceName), 'key');
            foreach ($inlineFields as $field) {
                $field = (string) $field;
                if (! in_array($field, $columnKeys, true)) {
                    continue;
                }
                $options = self::fieldOptions($raw, $field);
                if ($options === []) {
                    continue;
                }
                if (\Illuminate\Support\Facades\Route::has($prefix.'.inline')) {
                    $out['tab']['inline'] = [
                        'field' => $field,
                        'options' => $options,
                        'url' => route($prefix.'.inline', ['id' => '__ID__']),
                    ];
                    foreach ($rows as $row) {
                        $rid = $row instanceof \Illuminate\Database\Eloquent\Model ? $row->getKey() : ($row->id ?? null);
                        if ($rid !== null) {
                            $out['raw'][$rid] = is_object($row) ? ($row->{$field} ?? null) : null;
                        }
                    }
                }
                break;
            }
        } catch (\Throwable $e) {
            // The tab still renders its rows without the surface.
        }

        return $out;
    }

    /**
     * Declared option list for a manifest field, shaped
     * [{value, label}] — the same definitions the inline
     * endpoint validates against.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array{value: string, label: string}>
     */
    private static function fieldOptions(array $raw, string $field): array
    {
        $defs = [];
        foreach ((array) ($raw['fields'] ?? []) as $key => $def) {
            if (! is_array($def)) {
                continue;
            }
            $handle = $def['handle'] ?? $def['name'] ?? (is_string($key) ? $key : null);
            if ($handle !== null) {
                $defs[(string) $handle] = $def;
            }
        }
        foreach ((array) ($raw['models'] ?? []) as $model) {
            foreach ((array) ($model['fields'] ?? []) as $def) {
                if (is_array($def) && isset($def['handle'])) {
                    $defs[(string) $def['handle']] = $def;
                }
            }
        }
        $options = $defs[$field]['options'] ?? null;
        if (! is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $k => $opt) {
            if (is_array($opt)) {
                $value = (string) ($opt['value'] ?? $k);
                $out[] = ['value' => $value, 'label' => (string) ($opt['label'] ?? $value)];
            } elseif (is_int($k)) {
                $out[] = ['value' => (string) $opt, 'label' => (string) $opt];
            } else {
                $out[] = ['value' => (string) $k, 'label' => (string) $opt];
            }
        }

        return $out;
    }

    /** @var array<string, list<string>> table => schema column names */
    private static array $tableColumnsCache = [];

    /** @return list<string> */
    private static function tableColumns(string $table): array
    {
        if (! isset(self::$tableColumnsCache[$table])) {
            try {
                self::$tableColumnsCache[$table] = \Illuminate\Support\Facades\Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                self::$tableColumnsCache[$table] = [];
            }
        }

        return self::$tableColumnsCache[$table];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     */
    private static function columnsDescribeTable(array $columns, string $table): bool
    {
        $actual = self::tableColumns($table);
        if ($actual === []) {
            return true; // cannot verify — do not second-guess the slice
        }
        foreach ($columns as $col) {
            $key = (string) ($col['key'] ?? '');
            if ($key !== '' && $key !== 'id' && in_array($key, $actual, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Columns derived from the related table itself — the fallback for
     * child entities that own no slice/directory (e.g. leave quotas).
     * System/tenant/audit columns are excluded; FK columns resolve to
     * labels through the DataTableRows batch maps.
     *
     * @return list<array<string, mixed>>
     */
    private static function modelDerivedColumns(string $table, ?string $foreignKey = null): array
    {
        $actual = self::tableColumns($table);
        $skip = ['company_id', 'created_at', 'updated_at', 'deleted_at', 'hash', 'added_by', 'last_updated_by', 'created_by', 'updated_by'];
        if ($foreignKey !== null && $foreignKey !== '') {
            $skip[] = $foreignKey; // the parent's key — every row shares it
        }
        $labelish = [];
        $fks = [];
        $scalars = [];
        foreach ($actual as $col) {
            if ($col === 'id' || in_array($col, $skip, true) || str_ends_with($col, '_by')) {
                continue;
            }
            if (str_ends_with($col, '_name') || in_array($col, ['name', 'title', 'heading', 'subject'], true)) {
                $labelish[] = $col;
            } elseif (str_ends_with($col, '_id')) {
                $fks[] = $col;
            } else {
                $scalars[] = $col;
            }
        }
        $columns = [];
        foreach (array_slice(array_merge($labelish, $fks, $scalars), 0, 6) as $key) {
            $label = \Illuminate\Support\Str::headline($key);
            if (str_ends_with($key, '_id')) {
                $label = (string) preg_replace('/\s+Id$/', '', $label);
            }
            $columns[] = [
                'key' => $key,
                'label' => $label,
                'formatter' => $key === 'status' ? 'status_pill' : 'text',
                'html' => $key === 'status',
            ];
        }

        return $columns;
    }

    /** @var array<string, object|null> slice name => listing service */
    private static array $serviceBySlice = [];

    /**
     * Resolve a slice's listing service by naming convention
     * (<Namespace>\Services\<Singular>SliceService), the same class
     * its generated controller uses. Null when the slice has none.
     */
    private static function serviceForSlice(string $sliceName): ?object
    {
        if (array_key_exists($sliceName, self::$serviceBySlice)) {
            return self::$serviceBySlice[$sliceName];
        }
        $service = null;
        try {
            if (function_exists('app') && app()->bound(SliceManager::class)) {
                $manager = app(SliceManager::class);
                $manager->discover();
                $slice = $manager->getSlice($sliceName);
                $ns = $slice?->namespace;
                if (is_string($ns) && $ns !== '') {
                    $singular = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($sliceName));
                    $plural = \Illuminate\Support\Str::studly($sliceName);
                    foreach (array_unique([$singular, $plural]) as $base) {
                        $class = rtrim($ns, '\\').'\\Services\\'.$base.'SliceService';
                        if (class_exists($class)) {
                            $service = app()->bound($class) ? app($class) : new $class;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $service = null;
        }

        return self::$serviceBySlice[$sliceName] = $service;
    }

    /**
     * When the related slice declares no directory columns, derive a
     * modest column set from its manifest fields: the label field
     * first, then scalar/date fields, status last. System/audit
     * columns never appear (Tier 0 hygiene set).
     *
     * @return list<array<string, mixed>>
     */
    private static function fallbackTabColumns(string $sliceName): array
    {
        $manifest = self::readManifest($sliceName);
        $index = self::fieldIndex($manifest);
        if ($index === []) {
            return [];
        }
        $system = ['added_by', 'last_updated_by', 'created_by', 'updated_by', 'approved_by', 'approved_at', 'event_id', 'created_at', 'updated_at', 'deleted_at'];
        $labelKeys = ['name', 'title', 'heading', 'subject', 'full_name', 'label'];
        $picked = [];
        foreach ($labelKeys as $lk) {
            if (isset($index[$lk])) {
                $picked[] = $lk;
                break;
            }
        }
        if ($picked === []) {
            foreach (array_keys($index) as $k) {
                if (str_ends_with((string) $k, '_name')) {
                    $picked[] = (string) $k;
                    break;
                }
            }
        }
        foreach (array_keys($index) as $k) {
            $k = (string) $k;
            if (count($picked) >= 5 || in_array($k, $picked, true) || in_array($k, $system, true)
                || str_ends_with($k, '_by') || $k === 'status') {
                continue;
            }
            $type = $index[$k]['type'] ?? 'string';
            if (in_array($type, ['string', 'date', 'datetime', 'integer', 'decimal', 'boolean', 'text'], true)) {
                $picked[] = $k;
            }
        }
        if (isset($index['status'])) {
            $picked[] = 'status';
        }
        $columns = [];
        foreach ($picked as $k) {
            $def = $index[$k];
            $columns[] = array_filter([
                'key' => $k,
                'label' => (string) ($def['label'] ?? \Illuminate\Support\Str::headline($k)),
                'formatter' => $k === 'status' ? 'status_pill' : 'text',
                'html' => $k === 'status',
                'options' => self::normalizeOptions($def['options'] ?? null) ?: null,
                'field_type' => isset($def['type']) && is_string($def['type']) ? $def['type'] : null,
            ], fn ($v) => $v !== null);
        }

        return $columns;
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
    /**
     * Declared show-page composition (layout kit step 3,
     * 26 Oct 2026). ui.show.composition:
     *   standard (default) — identity header card + metadata
     *     strip + in-page tab ribbon + field card.
     *   overview — the source systems' hub composition: the
     *     record title moves to the topbar (page-title section),
     *     the tab strip fuses under it (filterbar stack), no
     *     header card or metadata strip, declared widgets lead
     *     and the field card follows as a plain 'Details' card.
     * ui.show.fields: end (default) | hide — field-card
     * placement under the overview composition.
     */
    public static function showCompositionFromManifest(array $manifest): array
    {
        $show = $manifest['ui']['show'] ?? [];

        return [
            'mode' => ($show['composition'] ?? 'standard') === 'overview' ? 'overview' : 'standard',
            'fields' => ($show['fields'] ?? 'end') === 'hide' ? 'hide' : 'end',
        ];
    }

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
                    $valueSpec = (array) ($def['value'] ?? []);
                    $value = isset($valueSpec['field'])
                        ? (is_numeric(data_get($record, (string) $valueSpec['field'])) ? (float) data_get($record, (string) $valueSpec['field']) : null)
                        : self::widgetAggregate($record, $valueSpec);
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
                        'placement' => (string) ($def['placement'] ?? 'main'),
                        'type' => 'gauge',
                        'title' => (string) ($def['title'] ?? \Illuminate\Support\Str::headline($handle)),
                        'value' => $value,
                        'max' => $max,
                        'percent' => max(0.0, min(100.0, round($value / $max * 100, 1))),
                        'display' => self::trimNumber($value).' / '.self::trimNumber($max),
                    ];
                } elseif ($type === 'stats' && $handle !== '') {
                    $rows = [];
                    foreach ((array) ($def['measures'] ?? []) as $measure) {
                        if (! is_array($measure) || empty($measure['label'])) {
                            continue;
                        }
                        $v = self::measureValue($record, $measure);
                        if ($v === null) {
                            continue;
                        }
                        $text = isset($measure['decimals'])
                            ? number_format($v, (int) $measure['decimals'])
                            : self::trimNumber($v);
                        $rows[] = [
                            'label' => (string) $measure['label'],
                            'value' => (string) ($measure['prefix'] ?? '').$text.(string) ($measure['suffix'] ?? ''),
                        ];
                    }
                    if ($rows !== []) {
                        $widgets[] = [
                            'handle' => $handle,
                            'placement' => (string) ($def['placement'] ?? 'main'),
                            'type' => 'stats',
                            'title' => (string) ($def['title'] ?? \Illuminate\Support\Str::headline($handle)),
                            'rows' => $rows,
                        ];
                    }
                } elseif ($type === 'list' && $handle !== '') {
                    $relation = (string) ($def['relation'] ?? '');
                    if ($relation === '' || ! method_exists($record, $relation)) {
                        continue;
                    }
                    $query = self::applyWidgetFilters($record->{$relation}(), (array) ($def['filter'] ?? []));
                    $items = $query
                        ->orderBy((string) ($def['order_by'] ?? 'id'), (string) ($def['direction'] ?? 'desc'))
                        ->limit((int) ($def['limit'] ?? 5))
                        ->get();
                    $labelField = (string) ($def['label_field'] ?? 'name');
                    $rows = [];
                    foreach ($items as $item) {
                        $label = data_get($item, $labelField);
                        if (! is_scalar($label) || (string) $label === '') {
                            $label = data_get($item, 'title') ?? data_get($item, 'name') ?? ('#'.data_get($item, 'id'));
                        }
                        $meta = ! empty($def['meta_field']) ? data_get($item, (string) $def['meta_field']) : null;
                        $rows[] = [
                            'label' => (string) $label,
                            'meta' => is_scalar($meta) ? (string) $meta : '',
                        ];
                    }
                    $widgets[] = [
                        'handle' => $handle,
                        'placement' => (string) ($def['placement'] ?? 'main'),
                        'type' => 'list',
                        'title' => (string) ($def['title'] ?? \Illuminate\Support\Str::headline($handle)),
                        'rows' => $rows,
                        'empty' => (string) ($def['empty_text'] ?? 'Nothing to show.'),
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
                        'placement' => (string) ($def['placement'] ?? 'main'),
                        'type' => 'chart',
                        'chart' => (string) ($def['chart'] ?? 'donut'),
                        'title' => (string) ($def['title'] ?? \Illuminate\Support\Str::headline($handle)),
                        'labels' => $labels,
                        'series' => $series,
                    ];
                } elseif ($type === 'bars' && $handle !== '') {
                    // Planned-vs-actual style comparisons: each bar is
                    // a stats measure (field / aggregate / minus) with
                    // its own color; an unset field reads as zero —
                    // a comparison against "not planned" is still a
                    // comparison. Widths scale to the largest bar.
                    $bars = [];
                    foreach ((array) ($def['bars'] ?? []) as $bar) {
                        if (! is_array($bar) || empty($bar['label'])) {
                            continue;
                        }
                        $v = self::measureValue($record, $bar);
                        if ($v === null && isset($bar['field'])) {
                            $v = 0.0;
                        }
                        if ($v === null) {
                            continue;
                        }
                        if (isset($bar['scale']) && is_numeric($bar['scale']) && (float) $bar['scale'] != 0.0) {
                            $v = $v / (float) $bar['scale'];
                        }
                        $text = isset($bar['decimals'])
                            ? number_format($v, (int) $bar['decimals'])
                            : self::trimNumber($v);
                        $bars[] = [
                            'label' => (string) $bar['label'],
                            'value' => $v,
                            'color' => (string) ($bar['color'] ?? '#3b82f6'),
                            'display' => (string) ($bar['prefix'] ?? '').$text.(string) ($bar['suffix'] ?? ''),
                        ];
                    }
                    if ($bars !== []) {
                        $max = max(array_map(fn ($b) => $b['value'], $bars));
                        foreach ($bars as $i => $b) {
                            $bars[$i]['percent'] = $max > 0 ? max(0.0, min(100.0, round($b['value'] / $max * 100, 1))) : 0.0;
                        }
                        $widgets[] = [
                            'handle' => $handle,
                            'placement' => (string) ($def['placement'] ?? 'main'),
                            'type' => 'bars',
                            'title' => (string) ($def['title'] ?? \Illuminate\Support\Str::headline($handle)),
                            'bars' => $bars,
                        ];
                    }
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
    /**
     * The declared-stats filter semantics ('!' excludes),
     * shared by widget aggregates and widget lists.
     */
    protected static function applyWidgetFilters(mixed $query, array $filter): mixed
    {
        foreach ($filter as $column => $value) {
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

        return $query;
    }

    /**
     * One stats measure: a record field, a relation aggregate
     * spec, or the difference of two aggregate specs (profit
     * = earnings - expenses).
     */
    protected static function measureValue(mixed $record, array $measure): ?float
    {
        if (isset($measure['field'])) {
            $v = data_get($record, (string) $measure['field']);

            return is_numeric($v) ? (float) $v : null;
        }
        if (isset($measure['minus']) && is_array($measure['minus']) && count($measure['minus']) === 2) {
            $a = self::widgetAggregate($record, (array) $measure['minus'][0]);
            $b = self::widgetAggregate($record, (array) $measure['minus'][1]);
            if ($a === null || $b === null) {
                return null;
            }

            return $a - $b;
        }

        return self::widgetAggregate($record, $measure);
    }

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
        $query = self::applyWidgetFilters($query, (array) ($spec['filter'] ?? []));

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
        // Roster mode (declared `roster: true`): every member of
        // the row-key label map is a row, with or without records
        // — the source's attendance grid renders the full roster
        // and marks elapsed days absent; a records-only grid
        // reads as an empty page for a quiet month.
        if (! empty($decl['roster'])) {
            foreach ($labelMap as $key => $label) {
                $rows[$key] = [
                    'key' => $key,
                    'label' => (string) $label,
                    'cells' => [],
                    'present' => 0,
                ];
            }
        }
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
            'days_in_month' => $daysInMonth,
            'roster' => ! empty($decl['roster']),
            'legend' => array_values(array_filter((array) ($decl['legend'] ?? []), 'is_array')),
            'row_label' => is_string($decl['row_label'] ?? null) && $decl['row_label'] !== '' ? $decl['row_label'] : 'Employee',
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

    /**
     * Multi-source comparison report (the Income-vs-Expense
     * shape): several money streams, each declared as
     * {label, table, date_field, amount, sign}, aligned by
     * month with a signed Net column, totals and header
     * cards. Declared as a named report under
     * ui.directory.reports[] with a `compare` list; the
     * controller supplies one row set per source with
     * normalized {date, amount} rows.
     *
     * @param  array<string, mixed>  $decl
     * @param  list<array{label: string, sign: int, rows: list<array{date: mixed, amount: mixed}>}>  $sources
     * @return array{title: string, groupLabel: string, columns: list<string>, rows: list<array{label: string, values: list<string>}>, totals: list<string>, count: int, cards: list<array{label: string, value: string}>}|null
     */
    public static function compareFromManifest(array $decl, array $sources): ?array
    {
        if (empty($decl['compare']) || ! is_array($decl['compare']) || $sources === []) {
            return null;
        }
        $money = fn ($v) => number_format((float) $v, 2);
        $periods = [];
        $totals = array_fill(0, count($sources), 0.0);
        $count = 0;
        foreach ($sources as $i => $source) {
            foreach ($source['rows'] as $row) {
                $count++;
                $amount = is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0.0;
                $totals[$i] += $amount;
                $ts = isset($row['date']) && $row['date'] !== null && $row['date'] !== ''
                    ? strtotime((string) $row['date']) : false;
                if ($ts === false) {
                    continue;
                }
                $key = date('Y-m', $ts);
                $periods[$key][$i] = ($periods[$key][$i] ?? 0.0) + $amount;
            }
        }
        ksort($periods);
        $net = fn (array $sums) => array_sum(array_map(
            fn ($i) => ($sources[$i]['sign'] ?? 1) * ($sums[$i] ?? 0.0),
            array_keys($sources)
        ));
        $rows = [];
        foreach ($periods as $key => $sums) {
            $values = [];
            foreach ($sources as $i => $source) {
                $values[] = $money($sums[$i] ?? 0.0);
            }
            $values[] = $money($net($sums));
            $rows[] = ['label' => date('F Y', strtotime($key.'-01')), 'values' => $values];
        }
        $totalValues = array_map($money, $totals);
        $totalValues[] = $money($net($totals));
        $cards = [];
        foreach ($sources as $i => $source) {
            $cards[] = ['label' => 'Total '.$source['label'], 'value' => $money($totals[$i])];
        }
        $cards[] = ['label' => 'Net', 'value' => $money($net($totals))];

        return [
            'title' => (string) ($decl['title'] ?? 'Comparison Report'),
            'groupLabel' => 'Month',
            'columns' => [...array_column($sources, 'label'), 'Net'],
            'rows' => $rows,
            'totals' => $totalValues,
            'count' => $count,
            'cards' => $cards,
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

    /**
     * Declared filter options as a list of {value, label} pairs —
     * the shape every strip/drawer renderer consumes. Manifests
     * declare options in BOTH shapes (maps and pair lists); the
     * renderers must never see the raw map (a map rendered as
     * pairs produced identical 'All' tabs, QA8 26 Oct 2026).
     */
    public static function optionsList(mixed $options): array
    {
        $list = [];
        foreach (static::normalizeOptions($options) as $value => $label) {
            $list[] = ['value' => $value, 'label' => $label];
        }

        return $list;
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

    /** Placements the Page Structure Contract (Body v4) allows for a list page's primary action. */
    public const LIST_PLACEMENTS = ['header-right', 'toolbar-left', 'command-bar', 'below-facets-left'];

    /**
     * Resolve a list page's declared primary-action placement
     * (Body v4, Page Structure Contract). Reads
     * ui.pages.list.placement first, then the legacy
     * ui.directory.placement; anything undeclared or outside the
     * vocabulary falls back to 'below-facets-left' — the actions
     * band under the filter bar, which is the fleet's current
     * rendered behaviour, so undeclared pages never move.
     *
     * @param  array<string, mixed>  $ui  The manifest's `ui` array.
     */
    public static function listPlacement(array $ui): string
    {
        $declared = $ui['pages']['list']['placement']
            ?? $ui['directory']['placement']
            ?? null;

        return is_string($declared) && in_array($declared, self::LIST_PLACEMENTS, true)
            ? $declared
            : 'below-facets-left';
    }

    /**
     * @return array<string, mixed>
     */
    /** @var array<string, array<string, mixed>> slice name => parsed manifest */
    private static array $manifestDataBySlice = [];

    public static function readManifest(string $sliceName): array
    {
        if (array_key_exists($sliceName, self::$manifestDataBySlice)) {
            return self::$manifestDataBySlice[$sliceName];
        }
        $path = self::manifestPath($sliceName);
        if ($path === null || ! is_file($path)) {
            return [];
        }

        try {
            if (str_ends_with(strtolower($path), '.json')) {
                return self::$manifestDataBySlice[$sliceName] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) ?: [];
            }
            if (class_exists(Yaml::class)) {
                $data = Yaml::parseFile($path);

                return self::$manifestDataBySlice[$sliceName] = is_array($data) ? $data : [];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    /** @var array<string, string|null> slice name => manifest path */
    private static array $manifestPathBySlice = [];

    public static function manifestPath(string $sliceName): ?string
    {
        // Route-owner preference (QA5, 25 Oct 2026): when slices
        // share a name (twins), the name index resolves the FIRST
        // discovered — a page then renders its twin's declared
        // columns, whose relations its own model may not carry
        // (the work Projects list rendered the pilot slice's
        // columns and every relation cell came out empty). When
        // a same-named slice's declared route prefix owns the
        // current request path, THAT slice's manifest is the
        // page's own — the G5 own-manifest principle applied to
        // directory reads. The result is deliberately not written
        // to the name-keyed static cache.
        try {
            if (function_exists('app') && app()->bound(SliceManager::class) && app()->bound('request')) {
                $reqPath = trim((string) app('request')->path(), '/');
                if ($reqPath !== '') {
                    $manager = app(SliceManager::class);
                    $manager->discover();
                    // discoveredSlices(), not getAllSlices(): the
                    // name-keyed registry collapses twins to one
                    // entry, which is the shadowing itself.
                    foreach ($manager->discoveredSlices() as $candidate) {
                        if (($candidate->name ?? null) !== $sliceName) {
                            continue;
                        }
                        foreach (glob(rtrim((string) $candidate->path, '/\\').'/Routes/*.php') ?: [] as $routesFile) {
                            $src = (string) @file_get_contents($routesFile);
                            if (! preg_match("/Route::prefix\\(\\s*'([^']+)'\\)/", $src, $m)) {
                                continue;
                            }
                            $prefix = trim($m[1], '/');
                            if ($reqPath === $prefix || str_starts_with($reqPath, $prefix.'/')) {
                                foreach (['slice.yaml', 'slice.yml'] as $file) {
                                    $p = rtrim((string) $candidate->path, '/\\').DIRECTORY_SEPARATOR.$file;
                                    if (is_file($p)) {
                                        return $p;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the name index.
        }

        if (array_key_exists($sliceName, self::$manifestPathBySlice)) {
            return self::$manifestPathBySlice[$sliceName];
        }
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
                            return self::$manifestPathBySlice[$sliceName] = $p;
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
                return self::$manifestPathBySlice[$sliceName] = $found;
            }
        }

        return self::$manifestPathBySlice[$sliceName] = null;
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
