<?php

declare(strict_types=1);

namespace LaraSlice\Core\Conformance;

use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Metadata\MetadataProjector;

/**
 * Cross-runtime conformance runner (Proposal #7 — the
 * PATTERN, proven against the Laravel runtime).
 *
 * A declaration corpus (conformance/corpus/: one directory
 * per entry, a Heart in slice.yaml plus the facts a runtime
 * must project from it in expected.json) is projected
 * through THIS runtime's own surfaces — the Phase A/B
 * MetadataProjector: describe(), schema(), index() — and
 * every entry is checked for:
 *
 *  - FACT FIDELITY: each fact pinned in expected.json
 *    (primary table, field types/required/enums, relation
 *    name+kind set, table/operation/permission counts,
 *    tenancy, update semantics) matches the projection.
 *  - STRUCTURE: describe carries the contract keys; the
 *    schema document carries the laraslice/slice-schema
 *    header and a draft-07 json_schema, and is declaration
 *    only (no live row counts anywhere in it).
 *  - CANONICAL STABILITY: a fresh projector over a fresh
 *    manifest parse — constructed with the shared
 *    declaration cache OFF, so the comparison is against a
 *    genuinely rebuilt projection, not a cache entry —
 *    produces byte-identical schema JSON: the projection is
 *    a pure function of the declaration, independent of
 *    instance and cache state.
 *  - LADDER REPRODUCIBILITY: the index (the ladder's first
 *    rung) reports the same field/relation/table counts
 *    for the entry as describe (its second rung).
 *
 * A second runtime proves itself by executing the SAME
 * corpus through ITS projector and producing the same
 * fidelity table. Nothing here is Laravel-shaped except
 * the projector adapter itself (managerOver()).
 *
 * The runner reports failures per entry and never throws
 * for a broken entry: a corpus that cannot fail is not a
 * conformance suite.
 */
final class ConformanceRunner
{
    private readonly string $corpusDir;

    public function __construct(?string $corpusDir = null)
    {
        $this->corpusDir = $corpusDir ?? dirname(__DIR__, 3).'/conformance/corpus';
    }

    public function corpusDir(): string
    {
        return $this->corpusDir;
    }

    public static function defaultCorpusDir(): string
    {
        return dirname(__DIR__, 3).'/conformance/corpus';
    }

    public static function fidelityTablePath(): string
    {
        return dirname(__DIR__, 3).'/conformance/fidelity-table.md';
    }

    /**
     * Entry slugs: subdirectories of the corpus holding a
     * slice.yaml, sorted for a deterministic run order.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        $entries = [];
        foreach (glob($this->corpusDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir.'/slice.yaml')) {
                $entries[] = basename($dir);
            }
        }
        sort($entries);

        return $entries;
    }

    /**
     * @return array{
     *     corpus_dir: string,
     *     passed: bool,
     *     entries_passed: int,
     *     entries_total: int,
     *     entries: array<string, array{
     *         passed: bool,
     *         facts: list<array{fact: string, expected: string, projected: string, ok: bool}>,
     *         invariants: list<array{check: string, ok: bool, detail: string}>
     *     }>
     * }
     */
    public function run(): array
    {
        $result = [
            'corpus_dir' => $this->corpusDir,
            'passed' => true,
            'entries_passed' => 0,
            'entries_total' => 0,
            'entries' => [],
        ];

        foreach ($this->entries() as $slug) {
            $result['entries_total']++;
            try {
                $entry = $this->runEntry($slug);
            } catch (\Throwable $e) {
                $entry = [
                    'passed' => false,
                    'facts' => [],
                    'invariants' => [[
                        'check' => 'entry projects without throwing',
                        'ok' => false,
                        'detail' => get_class($e).': '.$e->getMessage(),
                    ]],
                ];
            }
            $result['entries'][$slug] = $entry;
            if ($entry['passed']) {
                $result['entries_passed']++;
            } else {
                $result['passed'] = false;
            }
        }

        return $result;
    }

    /**
     * @return array{passed: bool, facts: list<array{fact: string, expected: string, projected: string, ok: bool}>, invariants: list<array{check: string, ok: bool, detail: string}>}
     */
    private function runEntry(string $slug): array
    {
        $dir = $this->corpusDir.'/'.$slug;
        $facts = [];
        $invariants = [];
        $check = static function (string $name, bool $ok, string $detail = '') use (&$invariants): void {
            $invariants[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
        };
        $fact = static function (string $name, mixed $expected, mixed $projected) use (&$facts): void {
            $facts[] = [
                'fact' => $name,
                'expected' => self::render($expected),
                'projected' => self::render($projected),
                'ok' => $expected === $projected,
            ];
        };

        $expectedPath = $dir.'/expected.json';
        $expected = is_file($expectedPath)
            ? json_decode((string) file_get_contents($expectedPath), true)
            : null;
        $check('expected.json present and valid', is_array($expected) && is_array($expected['facts'] ?? null),
            is_array($expected) ? '' : 'A corpus entry without pinned facts is not a conformance entry.');
        $pinned = is_array($expected) ? (array) ($expected['facts'] ?? []) : [];

        $manifest = new SliceManifest($dir.'/slice.yaml');
        $projector = new MetadataProjector($this->managerOver([$manifest]));
        $name = (string) $manifest->name;

        $describe = $projector->describe($name);
        $check('describe resolves the entry by name', $describe !== null);
        if ($describe === null) {
            return $this->verdict($facts, $invariants);
        }

        foreach (['id', 'name', 'table', 'fields', 'tables', 'relations', 'operations', 'auth', 'update'] as $key) {
            $check("describe carries '{$key}'", array_key_exists($key, $describe));
        }

        $schema = $projector->schema($name);
        $check('schema resolves the entry', $schema !== null);
        if ($schema !== null) {
            $check('schema header is laraslice/slice-schema v1',
                ($schema['schema'] ?? null) === 'laraslice/slice-schema' && ($schema['schema_version'] ?? null) === 1);
            $check('schema carries a draft-07 json_schema with properties',
                is_array($schema['json_schema'] ?? null) && is_array($schema['json_schema']['properties'] ?? null));
            // Declaration-only, checked over the WHOLE schema
            // structure (goldfish round: the first version
            // inspected only the tables list).
            $leakedRows = false;
            $scan = static function ($node) use (&$scan, &$leakedRows): void {
                if (! is_array($node)) {
                    return;
                }
                if (array_key_exists('rows', $node)) {
                    $leakedRows = true;

                    return;
                }
                foreach ($node as $child) {
                    $scan($child);
                }
            };
            $scan($schema);
            $check('schema is declaration-only (no live row counts)', ! $leakedRows);

            // Canonical stability: a FRESH manifest parse and
            // a FRESH projector — constructed with the shared
            // declaration cache OFF, so the second schema is
            // genuinely rebuilt rather than served from the
            // first projection's cache entry (goldfish round)
            // — must produce byte-identical schema JSON:
            // projection is a pure function of the declaration.
            $freshProjector = new MetadataProjector($this->managerOver([new SliceManifest($dir.'/slice.yaml')]), useCache: false);
            $schemaAgain = $freshProjector->schema($name);
            $check('schema is canonically stable across instances',
                $schemaAgain !== null
                && json_encode($schema, JSON_UNESCAPED_SLASHES) === json_encode($schemaAgain, JSON_UNESCAPED_SLASHES));
        }

        // Ladder reproducibility: the index row for this
        // entry states the same declared counts as describe.
        $index = $projector->index();
        $row = null;
        foreach ((array) ($index['slices'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === $name) {
                $row = $candidate;
                break;
            }
        }
        $check('index lists the entry exactly once', $row !== null && (int) ($index['total'] ?? 0) === 1);
        if ($row !== null) {
            $check('index counts agree with describe (fields/relations/tables)',
                (int) ($row['fields'] ?? -1) === count($describe['fields'])
                && (int) ($row['relations'] ?? -1) === count($describe['relations'])
                && (int) ($row['tables'] ?? -1) === count($describe['tables']),
                'index: fields '.($row['fields'] ?? '?').', relations '.($row['relations'] ?? '?').', tables '.($row['tables'] ?? '?')
                .' — describe: '.count($describe['fields']).'/'.count($describe['relations']).'/'.count($describe['tables']));
        }

        $this->compareFacts($pinned, $describe, $fact);

        return $this->verdict($facts, $invariants);
    }

    /**
     * @param  array<string, mixed>  $pinned
     * @param  array<string, mixed>  $describe
     * @param  callable(string, mixed, mixed): void  $fact
     */
    private function compareFacts(array $pinned, array $describe, callable $fact): void
    {
        if (array_key_exists('table', $pinned)) {
            $fact('table', $pinned['table'], $describe['table'] ?? null);
        }
        if (array_key_exists('fields_count', $pinned)) {
            $fact('fields_count', $pinned['fields_count'], count((array) ($describe['fields'] ?? [])));
        }
        if (is_array($pinned['fields'] ?? null)) {
            $byName = [];
            foreach ((array) ($describe['fields'] ?? []) as $field) {
                if (is_array($field) && isset($field['name'])) {
                    $byName[(string) $field['name']] = $field;
                }
            }
            foreach ($pinned['fields'] as $fieldName => $want) {
                $got = $byName[(string) $fieldName] ?? null;
                $fact("field '{$fieldName}' present", true, $got !== null);
                if ($got === null || ! is_array($want)) {
                    continue;
                }
                if (array_key_exists('type', $want)) {
                    $fact("field '{$fieldName}' type", $want['type'], $got['type'] ?? null);
                }
                if (array_key_exists('required', $want)) {
                    $fact("field '{$fieldName}' required", $want['required'], $got['required'] ?? null);
                }
                if (array_key_exists('enum', $want)) {
                    $fact("field '{$fieldName}' enum", $want['enum'], $got['enum'] ?? null);
                }
                if (array_key_exists('enum_count', $want)) {
                    // The served count: the enum list's size
                    // when served whole, else the declared
                    // count the projector reports alongside
                    // the truncation marker.
                    $count = isset($got['enum']) && is_array($got['enum'])
                        ? count($got['enum'])
                        : ($got['enum_count'] ?? null);
                    $fact("field '{$fieldName}' enum_count", $want['enum_count'], $count);
                }
                if (array_key_exists('enum_truncated', $want)) {
                    $fact("field '{$fieldName}' enum_truncated", $want['enum_truncated'], ! empty($got['enum_truncated']));
                }
                if (array_key_exists('relation', $want)) {
                    $fact("field '{$fieldName}' relation", $want['relation'], $got['relation'] ?? null);
                }
            }
        }
        if (is_array($pinned['relations'] ?? null)) {
            $projected = [];
            foreach ((array) ($describe['relations'] ?? []) as $relation) {
                if (is_array($relation) && isset($relation['name'], $relation['kind'])) {
                    $projected[] = [(string) $relation['name'], (string) $relation['kind']];
                }
            }
            $expected = [];
            foreach ($pinned['relations'] as $relation) {
                if (is_array($relation) && isset($relation['name'], $relation['kind'])) {
                    $expected[] = [(string) $relation['name'], (string) $relation['kind']];
                }
            }
            sort($projected);
            sort($expected);
            $fact('relations (name+kind set)', $expected, $projected);
        }
        if (array_key_exists('relations_count', $pinned)) {
            $fact('relations_count', $pinned['relations_count'], count((array) ($describe['relations'] ?? [])));
        }
        if (array_key_exists('tables_count', $pinned)) {
            $fact('tables_count', $pinned['tables_count'], count((array) ($describe['tables'] ?? [])));
        }
        if (array_key_exists('operations_count', $pinned)) {
            $fact('operations_count', $pinned['operations_count'], count((array) ($describe['operations'] ?? [])));
        }
        if (array_key_exists('permissions_count', $pinned)) {
            $fact('permissions_count', $pinned['permissions_count'], count((array) ($describe['auth']['permissions'] ?? [])));
        }
        if (array_key_exists('multitenant', $pinned)) {
            $fact('multitenant', $pinned['multitenant'], ! empty($describe['auth']['multitenant']));
            if ($pinned['multitenant'] === true && array_key_exists('tenant_key', $pinned)) {
                $fact('tenant_key', $pinned['tenant_key'], $describe['auth']['tenant_key'] ?? null);
            }
        }
        if (is_array($pinned['update'] ?? null)) {
            $fact('update.mode', $pinned['update']['mode'] ?? null, $describe['update']['mode'] ?? null);
            $fact('update.declared', $pinned['update']['declared'] ?? null, $describe['update']['declared'] ?? null);
        }
    }

    /**
     * A SliceManager over an explicit manifest list — the
     * projector adapter. A second runtime replaces exactly
     * this seam with its own discovery.
     *
     * @param  array<int, SliceManifest>  $manifests
     */
    private function managerOver(array $manifests): SliceManager
    {
        $app = app();

        return new class($app, $manifests) extends SliceManager
        {
            /** @param array<int, SliceManifest> $fixtures */
            public function __construct($app, private readonly array $fixtures)
            {
                parent::__construct($app);
            }

            public function discover(bool $force = false): void {}

            public function discoveredSlices(): array
            {
                return $this->fixtures;
            }
        };
    }

    /**
     * @param  list<array{fact: string, expected: string, projected: string, ok: bool}>  $facts
     * @param  list<array{check: string, ok: bool, detail: string}>  $invariants
     * @return array{passed: bool, facts: list<array{fact: string, expected: string, projected: string, ok: bool}>, invariants: list<array{check: string, ok: bool, detail: string}>}
     */
    private function verdict(array $facts, array $invariants): array
    {
        $passed = true;
        foreach ($facts as $row) {
            $passed = $passed && $row['ok'];
        }
        foreach ($invariants as $row) {
            $passed = $passed && $row['ok'];
        }

        return ['passed' => $passed, 'facts' => $facts, 'invariants' => $invariants];
    }

    private static function render(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        $text = is_string($json) ? $json : gettype($value);

        return mb_strlen($text) > 90 ? mb_substr($text, 0, 90).'…' : $text;
    }

    /**
     * The fidelity table artefact: per entry, expected vs
     * projected for every pinned fact, plus the invariant
     * failures (a green table lists none). Produced by the
     * runner — regenerated with `slice:corpus --write-table`.
     *
     * @param  array<string, mixed>  $result  run() output
     */
    public function fidelityTable(array $result): string
    {
        $out = [];
        $out[] = '# Conformance fidelity table';
        $out[] = '';
        $out[] = 'Produced by the conformance runner (`php artisan slice:corpus --write-table`) projecting every corpus entry through the Laravel runtime\'s metadata projector (describe/schema/index). Expected facts are pinned per entry in `conformance/corpus/<entry>/expected.json`. This table proves the pattern against the Laravel runtime only; a second runtime earns its claim by producing the same table from the same corpus.';
        $out[] = '';
        $out[] = "**Result: {$result['entries_passed']}/{$result['entries_total']} entries conform.**";
        $out[] = '';

        foreach ((array) ($result['entries'] ?? []) as $slug => $entry) {
            $out[] = "## {$slug} — ".($entry['passed'] ? 'CONFORMS' : 'FAILS');
            $out[] = '';
            $out[] = '| Fact | Expected | Projected | |';
            $out[] = '|---|---|---|---|';
            foreach ((array) ($entry['facts'] ?? []) as $row) {
                $out[] = "| {$row['fact']} | `{$row['expected']}` | `{$row['projected']}` | ".($row['ok'] ? '✓' : '✗').' |';
            }
            $out[] = '';
            $failed = array_values(array_filter(
                (array) ($entry['invariants'] ?? []),
                static fn ($row) => ! ($row['ok'] ?? false)
            ));
            if ($failed !== []) {
                $out[] = 'Failed invariants:';
                $out[] = '';
                foreach ($failed as $row) {
                    $out[] = "- ✗ {$row['check']}".($row['detail'] !== '' ? " — {$row['detail']}" : '');
                }
                $out[] = '';
            } else {
                $passedCount = count((array) ($entry['invariants'] ?? []));
                $out[] = "All {$passedCount} contract invariants hold (structure, schema header, declaration-only schema, canonical stability, ladder reproducibility).";
                $out[] = '';
            }
        }

        return implode("\n", $out);
    }
}
