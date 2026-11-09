<?php

declare(strict_types=1);

namespace LaraSlice\Core\Metadata;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Discovery\UpdateSemantics;

/**
 * Proposal #1 (ServiceStack R&D, owner-approved 8 Nov 2026):
 * the runtime Heart→metadata projection — LaraSlice's analogue
 * of ServiceStack's MetadataTypes core.
 *
 * ServiceStack projects per request with NO document cache,
 * because their source of truth is compiled DTO classes already
 * resident in memory. Ours is owner-held YAML on disk (~1.2 MB
 * across a fleet), so the projection here is cached in three
 * tiers instead:
 *
 *  (a) a per-slice projection cache keyed on the Heart file's
 *      CONTENT HASH — a Heart edit produces a new key, so the
 *      edited slice (and only it) re-projects; the warm path
 *      performs zero YAML parses beyond discovery's own;
 *  (b) per-request memoisation on the projector instance;
 *  (c) HTTP ETag/304 on the endpoints (see MetadataController).
 *
 * The projection derives ONLY from SliceManager discovery — the
 * same source SliceManager::inventorySummary() reads — so the
 * index totals can never drift from the Studio/CLI/Copilot
 * inventory (the F4 lesson). Nothing is written to disk or DB:
 * there is no second copy of the metadata to go stale.
 *
 * Payload discipline (study track, 8 Nov 2026): the index is
 * identity + declared counts only (target ≈3K tokens for a
 * ~174-slice fleet, paginated for 4K-context devices); describe
 * ships the data core (fields/relations/operations/auth) by
 * default and the UI tier only on explicit request — roughly
 * half a Heart is UI material no data question needs.
 */
final class MetadataProjector
{
    private const CACHE_PREFIX = 'laraslice.metadata.v1';

    /** @var array<string, array<string, mixed>> per-request memo of index rows, by slice cache key */
    private ?array $memoRows = null;

    /** @var array<string, array<string, mixed>> per-request memo of describe builds, by slice cache key + tier */
    private array $memoDescribe = [];

    /** @var array<string, string> per-request memo of Heart content hashes, by manifest path */
    private array $memoHashes = [];

    /**
     * @param  bool  $useCache  the shared declaration cache is
     *                          on for every serving path; the
     *                          conformance runner constructs its
     *                          second projector with it OFF so
     *                          its canonical-stability check
     *                          compares a genuinely rebuilt
     *                          projection, not a cache entry
     *                          (goldfish round).
     */
    public function __construct(private SliceManager $slices, private bool $useCache = true) {}

    /**
     * The fleet index: one compact row per discovered slice.
     *
     * Options: domain (?string), page (int), per_page (int),
     * live (bool — add the primary table's live row count; the
     * row then leaves the content-hash cache path for that field
     * only), format ('json'|'rows').
     *
     * @return array<string, mixed>
     */
    public function index(array $options = []): array
    {
        $summary = $this->slices->inventorySummary();
        $rows = $this->allRows();

        $domain = isset($options['domain']) ? trim((string) $options['domain']) : '';
        if ($domain !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => strcasecmp((string) $row['domain'], $domain) === 0
            ));
        }
        $filtered = count($rows);

        $perPage = max(1, min(500, (int) ($options['per_page'] ?? 250)));
        $page = max(1, (int) ($options['page'] ?? 1));
        $pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        if (! empty($options['live'])) {
            foreach ($pageRows as &$row) {
                $row['rows'] = $this->liveRowCount($row['table'] ?? null);
            }
            unset($row);
        }

        $envelope = [
            'total' => $summary['total'],
            'domains' => $summary['domains'],
            'filtered' => $filtered,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => count($pageRows),
        ];

        if (($options['format'] ?? 'json') === 'rows') {
            $columns = ['id', 'name', 'title', 'domain', 'status', 'table', 'tables', 'fields', 'relations'];
            if (! empty($options['live'])) {
                $columns[] = 'rows';
            }
            $envelope['format'] = 'rows';
            $envelope['columns'] = $columns;
            $envelope['rows'] = array_map(
                fn (array $row): array => array_map(fn (string $col) => $row[$col] ?? null, $columns),
                $pageRows
            );
        } else {
            $envelope['slices'] = $pageRows;
        }

        return $envelope;
    }

    /**
     * One slice's projected schema, or null when no discovered
     * slice matches the id (canonical id, name, or handle).
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $id, bool $withUi = false): ?array
    {
        $manifest = $this->resolve($id);
        if ($manifest === null) {
            return null;
        }

        $build = $this->describeBuild($manifest);

        // Live overlay: per-table row counts are data, not
        // declaration — they are read at request time and never
        // enter the content-hash cache.
        foreach ($build['tables'] as &$table) {
            $table['rows'] = $this->liveRowCount($table['table'] ?? null);
        }
        unset($table);

        if (! $withUi) {
            unset($build['ui']);
        }

        return $build;
    }

    /**
     * The per-slice SCHEMA contract (Proposal #2): the describe
     * core, versioned. Where describe() is the operational view
     * (it overlays live row counts), the schema is declaration
     * only — no `rows` anywhere — and canonical JSON, because
     * consumers (forms, validators, MCP clients) build on it
     * and anything a caller must emit stays canonical
     * (study track §3: compact reads, canonical writes).
     *
     * The document carries the describe core verbatim (fields,
     * tables, relations, operations, auth — the same facts the
     * HTTP describe and the MCP schema tool serve) plus a
     * draft-07 `json_schema` for the primary table, the shape
     * ServiceStack's api_describe standardised: properties with
     * types/formats/enums and the required list, so a form or
     * validator can be generated without knowing LaraSlice's
     * field vocabulary.
     *
     * @return array<string, mixed>|null
     */
    public function schema(string $id, bool $withUi = false): ?array
    {
        $manifest = $this->resolve($id);
        if ($manifest === null) {
            return null;
        }

        $build = $this->describeBuild($manifest);
        if (! $withUi) {
            unset($build['ui']);
        }
        // The primary table's fields live at the top level of
        // the describe build; in the schema document they sit
        // under `fields` exactly as describe serves them.
        $build['json_schema'] = $this->jsonSchema($build);

        return array_merge([
            'schema' => 'laraslice/slice-schema',
            'schema_version' => 1,
        ], $build);
    }

    /**
     * Draft-07 JSON Schema for the slice's primary table,
     * projected from the describe build's field entries.
     *
     * @param  array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function jsonSchema(array $build): array
    {
        $properties = [];
        $required = [];
        foreach ((array) ($build['fields'] ?? []) as $field) {
            if (! is_array($field) || empty($field['name'])) {
                continue;
            }
            $name = (string) $field['name'];
            $property = $this->jsonSchemaProperty($field);
            $property['title'] = (string) ($field['label'] ?? $name);
            if (! empty($field['relation'])) {
                $property['x-relation'] = (string) $field['relation'];
            }
            $properties[$name] = $property;
            if (! empty($field['required'])) {
                $required[] = $name;
            }
        }

        $schema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => (string) ($build['name'] ?? 'Slice'),
            'type' => 'object',
            'properties' => $properties === [] ? (object) [] : $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * One field's draft-07 property. Heart types map onto JSON
     * Schema's small vocabulary; anything unmapped stays a
     * string (the Heart type remains authoritative in the
     * field entry itself — this is a projection, not a second
     * truth).
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function jsonSchemaProperty(array $field): array
    {
        $type = strtolower((string) ($field['type'] ?? 'string'));
        $property = match (true) {
            in_array($type, ['integer', 'int', 'bigint', 'smallint', 'tinyint', 'foreign_id'], true) => ['type' => 'integer'],
            in_array($type, ['decimal', 'float', 'double', 'money', 'number'], true) => ['type' => 'number'],
            in_array($type, ['boolean', 'bool', 'toggle', 'checkbox'], true) => ['type' => 'boolean'],
            $type === 'date' => ['type' => 'string', 'format' => 'date'],
            in_array($type, ['datetime', 'timestamp', 'datetimetz'], true) => ['type' => 'string', 'format' => 'date-time'],
            $type === 'time' => ['type' => 'string', 'format' => 'time'],
            $type === 'email' => ['type' => 'string', 'format' => 'email'],
            in_array($type, ['json', 'array', 'object'], true) => ['type' => 'object'],
            default => ['type' => 'string'],
        };

        if (isset($field['enum']) && is_array($field['enum'])) {
            $property['enum'] = array_values($field['enum']);
        }
        if (! empty($field['enum_truncated'])) {
            // The declared set exceeds the served cap: say so
            // in the schema too, so a generated form never
            // presents a partial list as the whole truth.
            $property['x-enum-count'] = (int) ($field['enum_count'] ?? 0);
            $property['x-enum-truncated'] = true;
        }

        return $property;
    }

    /**
     * Resolve a describe id against discovery: canonical id,
     * then exact name, then declared handle, then the manager's
     * own tolerant resolver as the last resort — accepted only
     * when the match is one of its STRICT passes (name, path
     * basename, title, handle, model file, table).
     *
     * The guard matters: the manager's final pass strips the
     * path's separators and case, then matches the query as a
     * substring of the result, which resolves junk names to
     * unrelated slices (on the proof fleet, 'Nope' matched the
     * Flags slice: with separators stripped,
     * "adjudicatio**n**" + "**ope**rations" spells "nope").
     * That pass exists for UI page resolution, where a loose
     * match renders a page; on an AI-facing contract a
     * wrong-slice answer is worse than an error — the caller
     * must get the correctable unknown-slice rejection the
     * contract promises.
     */
    public function resolve(string $id): ?SliceManifest
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $byName = null;
        $byHandle = null;
        foreach ($this->discovered() as $slice) {
            if ((string) ($slice->canonical_id ?? '') === $id) {
                return $slice;
            }
            if ($byName === null && strcasecmp((string) $slice->name, $id) === 0) {
                $byName = $slice;
            }
            $handle = $slice->raw['handle'] ?? null;
            if ($byHandle === null && is_string($handle) && strcasecmp($handle, $id) === 0) {
                $byHandle = $slice;
            }
        }
        if ($byName !== null || $byHandle !== null) {
            return $byName ?? $byHandle;
        }

        $fallback = $this->slices->getSlice($id);

        return $fallback !== null && $this->strictlyMatches($fallback, $id) ? $fallback : null;
    }

    /**
     * The manager resolver's strict passes, re-checked here:
     * normalized name / path basename / title / handle, the
     * model-file probe, or the slice's table. Deliberately
     * excludes its path-substring pass (see resolve()).
     */
    private function strictlyMatches(SliceManifest $slice, string $id): bool
    {
        $normalize = fn (string $v): string => strtolower(str_replace([' ', '_', '-'], '', $v));
        $norm = $normalize($id);
        $norms = array_unique([$norm, Str::singular($norm), Str::plural($norm)]);

        if (in_array($normalize((string) $slice->name), $norms, true)) {
            return true;
        }
        if (in_array($normalize(basename((string) $slice->path)), $norms, true)) {
            return true;
        }
        if (isset($slice->title) && in_array($normalize((string) $slice->title), $norms, true)) {
            return true;
        }
        $handle = $slice->raw['handle'] ?? null;
        if (is_string($handle) && in_array($normalize($handle), $norms, true)) {
            return true;
        }
        if (file_exists($slice->path.'/Models/'.Str::studly(Str::singular($id)).'.php')) {
            return true;
        }

        return ! empty($slice->tables) && in_array(Str::snake(Str::plural($id)), (array) $slice->tables, true);
    }

    /** @return array<int, SliceManifest> */
    private function discovered(): array
    {
        $this->slices->discover();

        return $this->slices->discoveredSlices();
    }

    /**
     * Every slice's index row, sorted by domain then name so the
     * payload is byte-stable for a given fleet state.
     *
     * @return array<int, array<string, mixed>>
     */
    private function allRows(): array
    {
        if ($this->memoRows !== null) {
            return array_values($this->memoRows);
        }

        $rows = [];
        foreach ($this->discovered() as $slice) {
            $rows[] = $this->indexRow($slice);
        }
        usort($rows, fn (array $a, array $b): int => [$a['domain'], strtolower((string) $a['name'])] <=> [$b['domain'], strtolower((string) $b['name'])]);

        $this->memoRows = $rows;

        return $rows;
    }

    /** @return array<string, mixed> */
    private function indexRow(SliceManifest $slice): array
    {
        $key = $this->cacheKey('row', $slice, $this->heartHash($slice));

        return $this->remember($key, function () use ($slice): array {
            $fields = $this->primaryFields($slice);
            $tables = $this->declaredTables($slice);

            return [
                'id' => (string) ($slice->canonical_id ?? ''),
                'name' => (string) $slice->name,
                'title' => (string) $slice->title,
                'domain' => SliceManager::domainLabelFor($slice),
                'status' => $slice->active ? 'active' : 'inactive',
                'table' => $slice->primaryTable(),
                'tables' => count($tables),
                'fields' => count($fields),
                'relations' => count($this->mergedRelations($slice)),
            ];
        });
    }

    /**
     * The full describe build (both tiers) for one slice, cached
     * under the slice's declaration hash — the Heart content hash
     * combined with its routes files' hashes, since operations
     * are projected from the routes files.
     *
     * @return array<string, mixed>
     */
    private function describeBuild(SliceManifest $slice): array
    {
        $hash = sha1($this->heartHash($slice).'|'.$this->routesHash($slice));
        $memoKey = $this->cacheKey('desc', $slice, $hash);
        if (isset($this->memoDescribe[$memoKey])) {
            return $this->memoDescribe[$memoKey];
        }

        $build = $this->remember($this->cacheKey('desc', $slice, $hash), function () use ($slice): array {
            $raw = (array) ($slice->raw ?? []);
            $primary = $slice->primaryTable();

            $out = [
                'id' => (string) ($slice->canonical_id ?? ''),
                'name' => (string) $slice->name,
            ];
            if (is_string($raw['handle'] ?? null) && $raw['handle'] !== '') {
                $out['handle'] = (string) $raw['handle'];
            }
            $out['title'] = (string) $slice->title;
            $out['domain'] = SliceManager::domainLabelFor($slice);
            if (! empty($slice->app)) {
                $out['app'] = (string) $slice->app;
            }
            if (! empty($slice->module)) {
                $out['module'] = (string) $slice->module;
            }
            if (! empty($slice->sub_domain)) {
                $out['sub_domain'] = (string) $slice->sub_domain;
            }
            $out['status'] = $slice->active ? 'active' : 'inactive';
            $out['version'] = (string) ($slice->version ?? '1.0.0');
            $out['table'] = $primary;
            $out['fields'] = $this->primaryFields($slice);
            $out['tables'] = $this->tableBuilds($slice);
            $out['relations'] = $this->mergedRelations($slice);
            $out['operations'] = $this->operations($slice);

            // Permissions are strings in most Hearts, but a
            // Heart may declare them as maps (the Users slice
            // declares {slug, name} entries). Project each entry
            // as declared — coercing a map with strval() throws,
            // which made describe() unusable for that slice.
            $auth = ['permissions' => array_values(array_map(
                fn ($permission) => is_array($permission) ? $permission : (string) $permission,
                (array) ($slice->permissions ?? [])
            ))];
            if ($slice->multitenant) {
                $auth['multitenant'] = true;
                $auth['tenant_key'] = (string) ($slice->tenant_key ?? 'company_id');
            }
            if (is_array($raw['access'] ?? null) && $raw['access'] !== []) {
                $auth['access'] = array_keys($raw['access']);
            }
            $out['auth'] = $auth;

            // Update semantics (Proposal #6): the Heart's
            // declared mode, resolved through the same
            // UpdateSemantics resolver the write path enforces,
            // with `declared` distinguishing an explicit Heart
            // declaration from the framework default (patch) —
            // a default is never served as if it were declared.
            $out['update'] = UpdateSemantics::fromManifest($raw);

            $ui = $this->uiTier($slice);
            if ($ui !== []) {
                $out['ui'] = $ui;
            }

            return $out;
        });

        return $this->memoDescribe[$memoKey] = $build;
    }

    /**
     * The primary table's declared fields, merged across the two
     * Heart shapes: the root model's field list (blueprint/porter
     * `models:`) wins; the top-level `fields:` map fills and
     * extends. Each entry carries exactly what a caller or a form
     * needs to act: name, label, type, required — plus the
     * declared enum values and the FK target table when declared.
     *
     * @return array<int, array<string, mixed>>
     */
    private function primaryFields(SliceManifest $slice): array
    {
        $raw = (array) ($slice->raw ?? []);
        $root = $this->rootModel($raw);

        $defs = [];
        foreach ((array) ($root['fields'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = (string) ($item['handle'] ?? $item['name'] ?? '');
            if ($name !== '') {
                $defs[$name] = $item;
            }
        }
        if (is_array($raw['fields'] ?? null)) {
            foreach ($raw['fields'] as $name => $item) {
                if (! is_string($name) || ! is_array($item)) {
                    continue;
                }
                // The models list wins on conflict; the map only
                // fills keys the model entry does not carry.
                $defs[$name] = isset($defs[$name]) ? array_merge($item, $defs[$name]) : $item;
            }
        }

        $relations = $this->mergedRelations($slice);
        $fields = [];
        foreach ($defs as $name => $def) {
            $fields[] = $this->fieldEntry((string) $name, $def, $relations);
        }

        return $fields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $relations
     * @return array<string, mixed>
     */
    private function fieldEntry(string $name, array $def, array $relations): array
    {
        $required = array_key_exists('required', $def)
            ? filter_var($def['required'], FILTER_VALIDATE_BOOLEAN)
            : (isset($def['nullable']) ? ! filter_var($def['nullable'], FILTER_VALIDATE_BOOLEAN) : false);

        $entry = [
            'name' => $name,
            'label' => (string) ($def['label'] ?? Str::title(str_replace('_', ' ', $name))),
            'type' => (string) ($def['type'] ?? 'string'),
            'required' => $required,
        ];

        // Declared allowable values (enum-style options map).
        // The cap is 50 (Phase A contract): above it the values
        // are not served — but the truncation is VISIBLE
        // (Phase B consumer contract): `enum_count` states how
        // many values are declared and `enum_truncated` marks
        // that the served set is incomplete, so a consumer
        // never reads absence as "unconstrained".
        $options = $def['options'] ?? null;
        if (is_array($options) && $options !== []) {
            if (count($options) <= 50) {
                $values = [];
                $isList = array_is_list($options);
                foreach ($options as $k => $v) {
                    $values[] = $isList ? $v : $k;
                }
                $entry['enum'] = array_values($values);
            } else {
                $entry['enum_count'] = count($options);
                $entry['enum_truncated'] = true;
            }
        }

        // FK target: the field's own declaration first
        // (options_table / references / relation / label_table —
        // the F10 escape hatches), then a declared belongsTo
        // whose foreign key is this field.
        $target = null;
        foreach (['options_table', 'label_table'] as $key) {
            if (is_string($def[$key] ?? null) && $def[$key] !== '') {
                $target = (string) $def[$key];
                break;
            }
        }
        if ($target === null && is_string($def['references'] ?? null) && $def['references'] !== '') {
            $target = explode('.', (string) $def['references'])[0];
        }
        if ($target === null && is_string($def['relation'] ?? null)) {
            foreach ($relations as $relation) {
                if (strcasecmp((string) $relation['name'], (string) $def['relation']) === 0 && ! empty($relation['target'])) {
                    $target = (string) $relation['target'];
                    break;
                }
            }
        }
        if ($target === null) {
            foreach ($relations as $relation) {
                if (($relation['kind'] ?? '') === 'belongsTo' && ($relation['fk'] ?? null) === $name && ! empty($relation['target'])) {
                    $target = (string) $relation['target'];
                    break;
                }
            }
        }
        if ($target !== null && $target !== '') {
            $entry['relation'] = $target;
        }

        return $entry;
    }

    /**
     * Every declared table of the slice: handle, table, root —
     * and, for non-primary tables, their own declared fields
     * (the primary table's fields live at the describe top level,
     * so nothing is duplicated).
     *
     * @return array<int, array<string, mixed>>
     */
    private function tableBuilds(SliceManifest $slice): array
    {
        $raw = (array) ($slice->raw ?? []);
        $primary = $slice->primaryTable();
        $tables = [];
        $seen = [];

        foreach ((array) ($raw['models'] ?? []) as $model) {
            if (! is_array($model) || empty($model['table'])) {
                continue;
            }
            $table = (string) $model['table'];
            if (isset($seen[$table])) {
                continue;
            }
            $seen[$table] = true;
            $isRoot = ($model['root'] ?? false) === true || $table === $primary;
            $entry = [
                'handle' => (string) ($model['handle'] ?? Str::singular($table)),
                'table' => $table,
                'root' => $isRoot,
            ];
            if ($isRoot) {
                $entry['field_count'] = count($this->primaryFields($slice));
            } else {
                $fields = [];
                foreach ((array) ($model['fields'] ?? []) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $fname = (string) ($item['handle'] ?? $item['name'] ?? '');
                    if ($fname !== '') {
                        $fields[] = $this->fieldEntry($fname, $item, []);
                    }
                }
                $entry['field_count'] = count($fields);
                $entry['fields'] = $fields;
            }
            $tables[] = $entry;
        }

        // Tables declared outside models: (older manifests).
        foreach ($this->declaredTables($slice) as $table) {
            if (! isset($seen[$table])) {
                $seen[$table] = true;
                $tables[] = [
                    'handle' => Str::singular($table),
                    'table' => $table,
                    'root' => $table === $primary,
                    'field_count' => $table === $primary ? count($this->primaryFields($slice)) : 0,
                ];
            }
        }

        return $tables;
    }

    /** @return array<int, string> */
    private function declaredTables(SliceManifest $slice): array
    {
        $tables = [];
        foreach ((array) ($slice->tables ?? []) as $table) {
            if (is_string($table) && $table !== '') {
                $tables[] = $table;
            } elseif (is_array($table) && ! empty($table['table'])) {
                $tables[] = (string) $table['table'];
            } elseif (is_array($table) && ! empty($table['name'])) {
                $tables[] = (string) $table['name'];
            }
        }
        foreach ((array) ($slice->raw['models'] ?? []) as $model) {
            if (is_array($model) && ! empty($model['table'])) {
                $tables[] = (string) $model['table'];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Declared relations merged from every Heart shape — the
     * top-level `relations:` and each model's `relations:` list —
     * deduplicated by name+kind (porter Hearts repeat a relation
     * once bare and once with nested fields; the richer entry
     * wins). Targets resolve to table names through the models
     * map whenever the declaration names a model.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mergedRelations(SliceManifest $slice): array
    {
        $raw = (array) ($slice->raw ?? []);

        // Model handle/class → table, for target resolution.
        $modelTables = [];
        foreach ((array) ($raw['models'] ?? []) as $model) {
            if (! is_array($model) || empty($model['table'])) {
                continue;
            }
            foreach ([(string) ($model['handle'] ?? ''), (string) ($model['name'] ?? ''), (string) ($model['model'] ?? '')] as $alias) {
                if ($alias !== '') {
                    $modelTables[strtolower($alias)] = (string) $model['table'];
                    $modelTables[strtolower(class_basename($alias))] = (string) $model['table'];
                }
            }
        }

        $found = [];
        $add = function (string $name, array $def) use (&$found, $modelTables): void {
            if ($name === '') {
                return;
            }
            $kind = (string) ($def['type'] ?? $def['relation'] ?? $def['kind'] ?? 'belongsTo');
            $target = null;
            if (! empty($def['table'])) {
                $target = (string) $def['table'];
            } elseif (! empty($def['model'])) {
                $model = (string) $def['model'];
                $target = $modelTables[strtolower($model)]
                    ?? $modelTables[strtolower(class_basename($model))]
                    ?? $modelTables[strtolower(Str::snake(class_basename($model)))]
                    ?? $model;
            } elseif (! empty($def['related'])) {
                $target = (string) $def['related'];
            } elseif (! empty($def['target'])) {
                $target = (string) $def['target'];
            }

            $entry = ['name' => $name, 'kind' => $kind];
            if ($target !== null && $target !== '') {
                $entry['target'] = $target;
            }
            $fk = $def['foreign_key'] ?? $def['foreignKey'] ?? null;
            if (is_string($fk) && $fk !== '') {
                $entry['fk'] = $fk;
            }
            if (is_array($def['pivot'] ?? null) && ! empty($def['pivot']['table'])) {
                $entry['pivot'] = (string) $def['pivot']['table'];
            }

            $key = strtolower($name).'|'.strtolower($kind);
            // Keep the richer declaration when a relation repeats.
            if (! isset($found[$key]) || count($entry) > count($found[$key])) {
                $found[$key] = $entry;
            }
        };

        foreach ((array) ($slice->relations ?? []) as $key => $def) {
            if (is_array($def)) {
                $add(is_string($key) ? $key : (string) ($def['name'] ?? ''), $def);
            } elseif (is_string($def)) {
                $add(is_string($key) ? $key : $def, ['type' => $def]);
            }
        }
        foreach ((array) ($raw['models'] ?? []) as $model) {
            if (! is_array($model)) {
                continue;
            }
            foreach ((array) ($model['relations'] ?? []) as $def) {
                if (is_array($def)) {
                    $add((string) ($def['name'] ?? ''), $def);
                }
            }
        }

        $relations = array_values($found);
        usort($relations, fn (array $a, array $b): int => strtolower((string) $a['name']) <=> strtolower((string) $b['name']));

        return $relations;
    }

    /**
     * The operations the slice exposes, projected from its own
     * routes files (Routes/web.php, Routes/api.php) — the same
     * explicit generated style registerDeclaredRoutes() reads.
     * Redirect aliases are not operations and are skipped.
     *
     * @return array<int, array{method: string, uri: string, name: string}>
     */
    private function operations(SliceManifest $slice): array
    {
        $routesPath = $slice->getRoutesPath();
        if ($routesPath === null) {
            return [];
        }

        $ops = [];
        $seen = [];
        foreach (['web.php', 'api.php'] as $file) {
            $path = $routesPath.'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            $src = (string) file_get_contents($path);

            $prefix = '';
            $namePrefix = '';
            if (preg_match("/Route::prefix\\('([^']+)'\\)->name\\('([^']+)'\\)/", $src, $m)) {
                $prefix = trim($m[1], '/');
                $namePrefix = $m[2];
            }

            if (preg_match_all("/Route::(get|post|put|patch|delete)\\(\\s*'([^']+)'[^;]*?->name\\(\\s*'([^']+)'\\s*\\)/s", $src, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $uri = '/'.trim($prefix.'/'.ltrim($match[2], '/'), '/');
                    if ($match[2] === '/' || $match[2] === '') {
                        $uri = '/'.$prefix;
                    }
                    $key = strtoupper($match[1]).' '.$uri;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $ops[] = [
                        'method' => strtoupper($match[1]),
                        'uri' => $uri,
                        'name' => $namePrefix.$match[3],
                    ];
                }
            }
        }

        return $ops;
    }

    /**
     * The UI tier — served only on explicit request (?ui=1):
     * directory columns, directory views, and the form's field
     * handles, all as declared in the Heart.
     *
     * @return array<string, mixed>
     */
    private function uiTier(SliceManifest $slice): array
    {
        $raw = (array) ($slice->raw ?? []);
        $ui = (array) ($raw['ui'] ?? []);
        $out = [];

        $columns = [];
        foreach ((array) ($ui['directory']['columns'] ?? []) as $column) {
            if (! is_array($column)) {
                continue;
            }
            $handle = (string) ($column['handle'] ?? $column['key'] ?? '');
            if ($handle === '') {
                continue;
            }
            $entry = ['handle' => $handle];
            if (! empty($column['label'])) {
                $entry['label'] = (string) $column['label'];
            }
            $columns[] = $entry;
        }
        if ($columns !== []) {
            $out['columns'] = $columns;
        }

        $views = $ui['directory']['views'] ?? null;
        if (is_array($views) && $views !== []) {
            $out['views'] = array_values(array_map('strval', $views));
        }

        $form = [];
        foreach ((array) ($ui['form']['fields'] ?? []) as $field) {
            $handle = is_array($field) ? (string) ($field['handle'] ?? $field['name'] ?? '') : (string) $field;
            if ($handle !== '') {
                $form[] = $handle;
            }
        }
        if ($form === [] && is_array($raw['fields'] ?? null)) {
            foreach ($raw['fields'] as $name => $def) {
                if (is_string($name) && is_array($def) && ($def['show_in_form'] ?? true)) {
                    $form[] = $name;
                }
            }
        }
        if ($form !== []) {
            $out['form'] = $form;
        }

        if (is_array($ui['presentation'] ?? null) && $ui['presentation'] !== []) {
            $out['presentation'] = $ui['presentation'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function rootModel(array $raw): array
    {
        $first = [];
        foreach ((array) ($raw['models'] ?? []) as $model) {
            if (! is_array($model)) {
                continue;
            }
            if (($model['root'] ?? false) === true) {
                return $model;
            }
            if ($first === []) {
                $first = $model;
            }
        }

        return $first;
    }

    private function liveRowCount(?string $table): ?int
    {
        if ($table === null || $table === '' || ! Schema::hasTable($table)) {
            return null;
        }

        try {
            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Content hash of the slice's Heart file — the cache key's
     * freshness proof. A Heart edit changes the hash, which
     * orphans the old cache entries and re-projects only this
     * slice (the study's prescription; mtime alone is weaker —
     * it cannot distinguish two writes inside one second with
     * equal size).
     */
    private function heartHash(SliceManifest $slice): string
    {
        $file = $this->heartFile($slice);
        if ($file === null) {
            return 'noheart';
        }
        if (isset($this->memoHashes[$file])) {
            return $this->memoHashes[$file];
        }
        clearstatcache(true, $file);

        return $this->memoHashes[$file] = (string) sha1_file($file);
    }

    private function routesHash(SliceManifest $slice): string
    {
        $routesPath = $slice->getRoutesPath();
        if ($routesPath === null) {
            return 'noroutes';
        }
        $hashes = [];
        foreach (['web.php', 'api.php'] as $file) {
            $path = $routesPath.'/'.$file;
            $hashes[] = is_file($path) ? (string) sha1_file($path) : '-';
        }

        return sha1(implode('|', $hashes));
    }

    private function heartFile(SliceManifest $slice): ?string
    {
        foreach (['slice.yaml', 'slice.yml'] as $file) {
            $path = $slice->path.'/'.$file;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The one door to the shared declaration cache — so the
     * cache-bypass construction (conformance runner) skips
     * every layer it guards at once.
     */
    private function remember(string $key, \Closure $build): array
    {
        return $this->useCache ? Cache::rememberForever($key, $build) : $build();
    }

    private function cacheKey(string $kind, SliceManifest $slice, string $hash): string
    {
        return self::CACHE_PREFIX.':'.$kind.':'.sha1((string) $slice->path).':'.$hash;
    }
}
