<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

use Illuminate\Support\Str;

/**
 * Porter fidelity scorecard (Proposal #5, ServiceStack R&D):
 * the fidelity measures the porting discipline already
 * defines — the scored port matrix (must 3 / should 2 /
 * could 1, yes 1 / partial ½ / no 0, skips excluded) and the
 * five gates' evidence rules — emitted BY the porter run
 * itself, computed from what the run actually produced
 * (its inspection of the source, the declarations it built,
 * the data import it performed, and the gate evidence
 * recorded in the target app), never assembled afterwards
 * from memory.
 *
 * Every source fact the run can see becomes one matrix row:
 * captured (yes), captured with a named loss (partial),
 * deliberately not ported (skip, reason required), or
 * dropped (no). A fact the run cannot measure is NOT scored
 * zero — the dimension is listed as NOT MEASURED with the
 * reason, the same honesty rule PortEvidence applies to
 * gate suites.
 *
 * The score is the matrix's legible number, in the matrix's
 * vocabulary, with the matrix's verdict rule. It never
 * upgrades a gate verdict: a port's status remains the
 * lowest of Gates S/D/F/T/V, and Furniture (F), Tasks (T)
 * and Visual (V) conformance are outside what a port run
 * can measure — the card says so on its face.
 */
final class PortScorecard
{
    /** Matrix weights (port-matrix.md): must 3, should 2, could 1. */
    private const WEIGHTS = ['must' => 3, 'should' => 2, 'could' => 1];

    /** Matrix credits: yes 1, partial ½, no 0. Skips are excluded. */
    private const CREDITS = ['yes' => 1.0, 'partial' => 0.5, 'no' => 0.0];

    /** Columns the porter excludes from declarations by design (framework-owned). */
    private const SYSTEM_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    public const JSON_FILE = 'scorecard.json';

    public const MD_FILE = 'scorecard.md';

    /**
     * Score a source-mode run: the inspection the porter made
     * of the source plus the run's own results (data import).
     *
     * @param  array<string, mixed>  $inspection  SlicePortService::inspect() output
     * @param  array<string, mixed>  $run  the run's result array (data_import, slices_generated, …)
     * @return array<string, mixed> the scorecard
     */
    public static function fromInspectionRun(array $inspection, array $run): array
    {
        $tables = (array) ($inspection['tables'] ?? []);
        $classified = (array) ($inspection['classified'] ?? []);
        $systemTables = array_fill_keys(array_keys((array) ($classified['system'] ?? [])), true);
        $unmappedPivots = array_fill_keys((array) ($inspection['unmapped_pivots'] ?? []), true);
        $tenancy = (array) ($inspection['tenancy'] ?? []);

        // Produced facts: index the declarations the run built.
        $modelsByTable = [];
        $allRelations = [];
        foreach ((array) ($inspection['studio_slices'] ?? []) as $studio) {
            $blueprint = (array) ($studio['blueprint'] ?? []);
            foreach ((array) ($studio['allRelations'] ?? []) as $relation) {
                $allRelations[] = $relation;
            }
            foreach ((array) ($blueprint['models'] ?? []) as $model) {
                $table = (string) ($model['table'] ?? '');
                if ($table === '') {
                    continue;
                }
                $modelsByTable[$table] = [
                    'slice' => (string) ($studio['name'] ?? ''),
                    'model' => $model,
                    'blueprint' => $blueprint,
                ];
                foreach ((array) ($model['relations'] ?? []) as $relation) {
                    $relation['source'] = $table;
                    $allRelations[] = $relation;
                }
            }
        }

        $rows = [];
        $notMeasured = [];

        // ---- Structure (Gate S inputs): every source table
        // and column is declared, skipped with a reason, or
        // flagged as dropped.
        foreach ($tables as $table => $info) {
            $info = (array) $info;
            if (isset($systemTables[$table])) {
                $rows[] = self::row("Table {$table} carried", 'Structure', 'must', 'skip',
                    'porter classification', 'Framework/system table — carried by the framework itself, never ported as a slice.');

                continue;
            }
            $claim = $modelsByTable[$table] ?? null;
            if ($claim === null) {
                $rows[] = self::row("Table {$table} declared as a slice model", 'Structure', 'must',
                    isset($unmappedPivots[$table]) ? 'no' : 'no',
                    'porter inspection',
                    isset($unmappedPivots[$table])
                        ? 'Unmapped pivot: not attached to any slice — flagged for manual review.'
                        : 'No produced slice model claims this table.');

                continue;
            }
            $rows[] = self::row("Table {$table} declared as a slice model", 'Structure', 'must', 'yes',
                "slice {$claim['slice']} model ".($claim['model']['handle'] ?? $table), '');

            $fields = [];
            foreach ((array) ($claim['model']['fields'] ?? []) as $field) {
                $fields[(string) ($field['handle'] ?? '')] = $field;
            }
            foreach (self::columnsByName((array) ($info['columns'] ?? [])) as $name => $column) {
                if (in_array($name, self::SYSTEM_COLUMNS, true)) {
                    continue;
                }
                $rows[] = isset($fields[$name])
                    ? self::row("Column {$table}.{$name} declared", 'Structure', 'should', 'yes',
                        "slice {$claim['slice']} fields", '')
                    : self::row("Column {$table}.{$name} declared", 'Structure', 'should', 'no',
                        'porter inspection', 'Column is absent from the produced declaration — dropped unless a deviation records it.');
            }
        }

        // ---- Relations: every source foreign key is carried
        // by a declared relation or an FK field whose target
        // resolves — or it is flagged, never silently dropped.
        foreach ($tables as $table => $info) {
            $info = (array) $info;
            if (isset($systemTables[$table])) {
                continue;
            }
            $targets = (array) ($info['foreign_key_targets'] ?? []);
            foreach ((array) ($info['foreign_keys'] ?? []) as $fk) {
                $fk = (string) $fk;
                $target = isset($targets[$fk]) ? (string) $targets[$fk] : null;
                $feature = "Foreign key {$table}.{$fk}".($target !== null ? " → {$target}" : '');
                $claim = $modelsByTable[$table] ?? null;
                // The tenant key is not a relation: it is the
                // model's tenant scope, measured under
                // Identity. Say which, on the row itself.
                if (! empty($tenancy['enabled']) && $fk === (string) ($tenancy['tenant_key'] ?? '')) {
                    $tenantDeclared = $claim !== null && ! empty($claim['model']['multitenant']);
                    $rows[] = self::row($feature, 'Relations', 'must', $tenantDeclared ? 'yes' : 'no',
                        $claim !== null ? "slice {$claim['slice']} model" : 'porter inspection',
                        $tenantDeclared
                            ? 'Tenant key — carried as the model\'s tenant scope (HasTenant); tenancy fidelity is scored under Identity.'
                            : 'Tenant key whose model is not declared multitenant — see Identity.');

                    continue;
                }
                if ($claim === null) {
                    $rows[] = self::row($feature, 'Relations', 'must', 'no', 'porter inspection',
                        'Owning table was not ported, so this foreign key has no declaration home.');

                    continue;
                }
                if (self::relationDeclared($allRelations, $table, $fk, $target)) {
                    $rows[] = self::row($feature, 'Relations', 'must', 'yes',
                        "slice {$claim['slice']} relations", '');

                    continue;
                }
                $field = null;
                foreach ((array) ($claim['model']['fields'] ?? []) as $candidate) {
                    if ((string) ($candidate['handle'] ?? '') === $fk) {
                        $field = $candidate;
                        break;
                    }
                }
                if ($field === null) {
                    $rows[] = self::row($feature, 'Relations', 'must', 'no', 'porter inspection',
                        'No field or relation in the produced declaration carries this foreign key — dropped.');

                    continue;
                }
                // The FK survives as a field. It resolves by the
                // name convention (singular of the column) unless
                // the porter recorded an explicit relation
                // override; when the schema's real target differs
                // from the name-derived one and no override was
                // recorded, the field points at the wrong model.
                $nameDerived = Str::singular(Str::replaceLast('_id', '', $fk));
                if ($target !== null && Str::singular($target) !== $nameDerived && empty($field['relation'])) {
                    $rows[] = self::row($feature, 'Relations', 'must', 'partial',
                        "slice {$claim['slice']} fields",
                        "Carried as a field, but the schema target is {$target} while the name convention resolves to {$nameDerived} and no relation override was recorded — the generated relation would target the wrong model.");

                    continue;
                }
                $rows[] = self::row($feature, 'Relations', 'must', 'yes',
                    "slice {$claim['slice']} fields",
                    $target !== null
                        ? 'Carried as an FK field; the relation resolves by declaration/convention.'
                        : 'Convention-inferred foreign key (the schema declares no explicit constraint for it); carried as an FK field.');
            }
        }

        // ---- Identity & tenancy: a tenant-scoped source
        // table must land on a multitenant model, and a data
        // import's identity remap is recorded, not invisible.
        $tenantKey = (string) ($tenancy['tenant_key'] ?? '');
        if (! empty($tenancy['enabled']) && $tenantKey !== '') {
            $identityRowsBefore = count($rows);
            foreach ($tables as $table => $info) {
                $info = (array) $info;
                if (isset($systemTables[$table]) || ! isset($modelsByTable[$table])) {
                    continue;
                }
                $columns = array_map(static fn ($c) => (string) ((array) $c)['name'] ?? '', (array) ($info['columns'] ?? []));
                if (! in_array($tenantKey, $columns, true)) {
                    continue;
                }
                $model = $modelsByTable[$table]['model'];
                $rows[] = ! empty($model['multitenant'])
                    ? self::row("Table {$table} tenant isolation declared ({$tenantKey})", 'Identity', 'should', 'yes',
                        "slice {$modelsByTable[$table]['slice']} model", '')
                    : self::row("Table {$table} tenant isolation declared ({$tenantKey})", 'Identity', 'should', 'no',
                        'porter inspection', 'Source table is tenant-scoped but the produced model is not declared multitenant.');
            }
            if (count($rows) === $identityRowsBefore) {
                // Tenancy was in play but nothing measurable
                // was claimed — the dimension must say so
                // rather than vanish (goldfish round).
                $notMeasured[] = ['dimension' => 'Identity', 'reason' => 'Tenancy is enabled for this port but no produced model claims a tenant-scoped table, so tenant isolation could not be measured.'];
            }
        } else {
            $notMeasured[] = ['dimension' => 'Identity', 'reason' => 'The source schema is single-tenant (no tenant key detected), so tenant isolation has nothing to measure.'];
        }

        // ---- Allowable values: enum vocabularies the schema
        // itself declares must survive into field options.
        foreach ($tables as $table => $info) {
            $info = (array) $info;
            if (isset($systemTables[$table])) {
                continue;
            }
            foreach (self::columnsByName((array) ($info['columns'] ?? [])) as $column) {
                $options = SlicePortService::parseEnumOptions(
                    isset($column['raw_type']) && is_string($column['raw_type']) ? $column['raw_type'] : null
                ) ?? SlicePortService::parseEnumOptions(
                    isset($column['type']) && is_string($column['type']) ? $column['type'] : null
                );
                if ($options === null || $options === []) {
                    // An enum-TYPED column whose values the
                    // extraction did not retain: the vocabulary
                    // exists in the source but cannot have been
                    // captured — flag the loss, never skip it
                    // silently (that silence is the defect this
                    // card exists to end).
                    if (($column['type'] ?? '') === 'enum') {
                        $rows[] = self::row(
                            "Allowable values {$table}.".(string) ($column['name'] ?? '').' captured',
                            'Allowable values', 'should', 'no', 'porter inspection',
                            'Source column is an enum, but the extraction retained no value list for it and the produced field carries no options — the vocabulary was not captured.'
                        );
                    }

                    continue;
                }
                $name = (string) ($column['name'] ?? '');
                $feature = "Allowable values {$table}.{$name} captured";
                $claim = $modelsByTable[$table] ?? null;
                $field = null;
                if ($claim !== null) {
                    foreach ((array) ($claim['model']['fields'] ?? []) as $candidate) {
                        if ((string) ($candidate['handle'] ?? '') === $name) {
                            $field = $candidate;
                            break;
                        }
                    }
                }
                if ($field === null) {
                    $rows[] = self::row($feature, 'Allowable values', 'should', 'no', 'porter inspection',
                        'The field itself is missing from the produced declaration (see Structure), so its vocabulary is lost with it.');

                    continue;
                }
                $declared = array_map('strval', array_keys((array) ($field['options'] ?? [])));
                $missing = array_values(array_diff(array_map('strval', array_keys($options)), $declared));
                if ($missing === []) {
                    $rows[] = self::row($feature, 'Allowable values', 'should', 'yes',
                        "slice {$claim['slice']} fields", count($options).' value(s) declared as field options.');
                } elseif ($declared === []) {
                    $rows[] = self::row($feature, 'Allowable values', 'should', 'no',
                        'porter inspection', 'Enum vocabulary dropped — the produced field carries no options.');
                } else {
                    $rows[] = self::row($feature, 'Allowable values', 'should', 'partial',
                        "slice {$claim['slice']} fields", 'Options are missing value(s): '.implode(', ', $missing).'.');
                }
            }
        }

        self::dataRows($run, $rows, $notMeasured);
        self::gateEvidenceRows($rows, $notMeasured);

        return self::card('source', $inspection['domain'] ?? null, $rows, $notMeasured);
    }

    /**
     * Score a plan-mode run (slice:port --plan). The plan
     * carries tables and record counts but no column/FK
     * detail, so those dimensions are NOT MEASURED rather
     * than scored from nothing.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $run  executeFromPlan() result
     * @return array<string, mixed>
     */
    public static function fromPlanRun(array $plan, array $run): array
    {
        $rows = [];
        $created = array_fill_keys(array_map('strval', (array) ($run['slices_created'] ?? [])), true);
        $errors = (array) ($run['errors'] ?? []);
        $collisions = [];
        foreach ((array) ($run['collisions'] ?? []) as $hit) {
            if (is_array($hit) && isset($hit['slice'])) {
                $collisions[(string) $hit['slice']] = $hit;
            }
        }
        $childrenCreated = array_fill_keys(array_map('strval', (array) ($run['child_tables_created'] ?? [])), true);

        foreach ((array) ($plan['modules'] ?? []) as $module) {
            foreach ((array) (($module['slices'] ?? []) ?: []) as $slice) {
                $slice = (array) $slice;
                $name = (string) ($slice['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (isset($errors[$name])) {
                    $rows[] = self::row("Slice {$name} scaffolded", 'Structure', 'must', 'no',
                        'porter run result', 'Porter error: '.(string) $errors[$name]);
                } elseif (isset($collisions[$name])) {
                    $rows[] = self::row("Slice {$name} scaffolded", 'Structure', 'must', 'partial',
                        'porter run result', 'Name/table collision with existing slice '.(string) ($collisions[$name]['existing_slice'] ?? '').' — the incoming slice did not simply land.');
                } elseif (isset($created[$name])) {
                    $rows[] = self::row("Slice {$name} scaffolded", 'Structure', 'must', 'yes',
                        'porter run result', 'Root table '.(string) ($slice['root_table'] ?? '').'.');
                } else {
                    $rows[] = self::row("Slice {$name} scaffolded", 'Structure', 'must', 'no',
                        'porter run result', 'Slice is absent from the run\'s created list.');
                }
                foreach ((array) ($slice['child_tables'] ?? []) as $child) {
                    $childName = is_array($child) ? (string) ($child['table'] ?? '') : (string) $child;
                    if ($childName === '') {
                        continue;
                    }
                    $rows[] = isset($childrenCreated[$childName])
                        ? self::row("Child table {$childName} of {$name} scaffolded", 'Structure', 'should', 'yes', 'porter run result', '')
                        : self::row("Child table {$childName} of {$name} scaffolded", 'Structure', 'should', 'no', 'porter run result',
                            'Child table is absent from the run\'s created list.');
                }
            }
        }

        $notMeasured = [
            ['dimension' => 'Relations', 'reason' => 'The architecture plan carries no foreign-key detail; relation fidelity is measured on source-mode runs.'],
            ['dimension' => 'Identity', 'reason' => 'The architecture plan does not carry per-table tenant columns; tenancy fidelity is measured on source-mode runs.'],
            ['dimension' => 'Allowable values', 'reason' => 'The architecture plan carries no enum vocabularies; allowable-value fidelity is measured on source-mode runs.'],
            ['dimension' => 'Structure (columns)', 'reason' => 'The architecture plan carries no column detail; column fidelity is measured on source-mode runs.'],
        ];

        self::dataRows($run, $rows, $notMeasured);
        self::gateEvidenceRows($rows, $notMeasured);

        return self::card('plan', $plan['domain'] ?? null, $rows, $notMeasured);
    }

    /**
     * Data dimension (Gate D inputs) from the run's ETL
     * result: per table, the import's own verdict — rows
     * landed, target missing (rows NOT imported), or the
     * table was excluded by the port's data rules (skip).
     *
     * @param  array<string, mixed>  $run
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array{dimension: string, reason: string}>  $notMeasured
     */
    private static function dataRows(array $run, array &$rows, array &$notMeasured): void
    {
        $import = $run['data_import'] ?? null;
        if (! is_array($import)) {
            $notMeasured[] = ['dimension' => 'Data', 'reason' => 'This run did not import data, so data fidelity (Gate D) has nothing to measure.'];

            return;
        }
        if (! empty($import['error'])) {
            $rows[] = self::row('Data import completed', 'Data', 'must', 'no', 'ETL result', 'Data import failed: '.(string) $import['error']);

            return;
        }
        $missingTargets = array_fill_keys(array_map('strval', (array) ($import['missing_target_tables'] ?? [])), true);
        $results = (array) ($import['results'] ?? []);
        if ($results === []) {
            $notMeasured[] = ['dimension' => 'Data', 'reason' => 'The data import reported no per-table results, so per-table data fidelity cannot be measured from this run.'];

            return;
        }
        foreach ($results as $table => $result) {
            if (! is_array($result)) {
                continue;
            }
            $table = (string) $table;
            $status = (string) ($result['status'] ?? '');
            $target = (string) ($result['target_table'] ?? $table);
            if ($status === 'not_in_target' || isset($missingTargets[$target]) || isset($missingTargets[$table])) {
                $rows[] = self::row("Data for table {$table} imported", 'Data', 'must', 'no', 'ETL result',
                    'Target table did not exist — the rows were NOT imported.');

                continue;
            }
            if (in_array($status, ['imported', 'success'], true)) {
                $rows[] = self::row("Data for table {$table} imported", 'Data', 'must', 'yes', 'ETL result',
                    number_format((int) ($result['rows'] ?? 0)).' row(s) imported.');

                continue;
            }
            $rows[] = self::row("Data for table {$table} imported", 'Data', 'must', 'partial', 'ETL result',
                "Import status '{$status}'".(isset($result['rows']) ? ' — '.number_format((int) $result['rows']).' row(s).' : '.'));
        }
        foreach ((array) ($import['skipped_tables'] ?? []) as $skipped) {
            $name = is_array($skipped) ? (string) ($skipped['table'] ?? '') : (string) $skipped;
            if ($name === '') {
                continue;
            }
            $rows[] = self::row("Data for table {$name} imported", 'Data', 'must', 'skip', 'ETL result',
                'Excluded by the port\'s data rules (protected/auth tables are merged, never streamed).');
        }
        $remap = (array) ($import['identity_remap'] ?? []);
        if ($remap !== []) {
            $rows[] = self::row('Imported user identities remapped by email', 'Identity', 'should', 'yes', 'ETL result',
                count($remap).' identit(ies) remapped so imported references resolve to the merged users.');
        }
    }

    /**
     * Gate-evidence dimension: the suites recorded in the
     * target app's porting/evidence/ (PortEvidence), cited
     * with their provenance — recorded by gate runners, not
     * produced by this run.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array{dimension: string, reason: string}>  $notMeasured
     */
    private static function gateEvidenceRows(array &$rows, array &$notMeasured): void
    {
        try {
            $evidence = PortEvidence::load();
        } catch (\Throwable $e) {
            $notMeasured[] = ['dimension' => 'Gate evidence', 'reason' => 'Recorded gate evidence could not be read: '.$e->getMessage()];

            return;
        }
        if (($evidence['suites'] ?? []) === []) {
            $notMeasured[] = ['dimension' => 'Gate evidence', 'reason' => 'No gate suites are recorded in porting/evidence/ for this app yet.'];

            return;
        }
        foreach ($evidence['suites'] as $suite) {
            $percent = $suite['checks'] > 0 ? round(100 * $suite['passed'] / $suite['checks'], 1) : 0.0;
            $port = $suite['failed'] === []
                ? 'yes'
                : ($percent > 0 ? 'partial' : 'no');
            $rows[] = self::row("Gate evidence: {$suite['suite']}", 'Gate evidence', 'should', $port,
                $suite['file'].($suite['recorded_at'] ? " recorded {$suite['recorded_at']}" : ''),
                "{$suite['passed']}/{$suite['checks']} checks passed ({$percent}%). Recorded evidence — produced by gate runners, not by this port run.");
        }
    }

    /**
     * Is this foreign key carried by a declared relation?
     * Either direction counts: a belongsTo on the owning
     * model, or a hasMany on the target model naming this
     * key (the shape the porter emits for child tables).
     *
     * @param  array<int, array<string, mixed>>  $allRelations
     */
    private static function relationDeclared(array $allRelations, string $table, string $fk, ?string $target): bool
    {
        foreach ($allRelations as $relation) {
            if ((string) ($relation['foreign_key'] ?? '') !== $fk) {
                continue;
            }
            $type = (string) ($relation['type'] ?? '');
            $source = (string) ($relation['source'] ?? '');
            $relationTarget = (string) ($relation['target'] ?? $relation['table'] ?? '');
            if ($type === 'belongsTo' && $source === $table) {
                return true;
            }
            if ($type === 'hasMany' && $target !== null && $source === $target && $relationTarget === $table) {
                return true;
            }
            if ($type === 'hasMany' && $target === null && $relationTarget === $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * Source columns keyed by name, first occurrence wins.
     * The migration extractor lists a column twice when the
     * migration also declares its foreign key (the
     * `$table->foreign('x')` line parses as a column entry);
     * the porter itself dedupes the same way when it builds
     * fields, and one source column must earn exactly one
     * scorecard row.
     *
     * @param  array<int, mixed>  $columns
     * @return array<string, array<string, mixed>>
     */
    private static function columnsByName(array $columns): array
    {
        $byName = [];
        foreach ($columns as $column) {
            $column = (array) $column;
            $name = (string) ($column['name'] ?? '');
            if ($name !== '' && ! isset($byName[$name])) {
                $byName[$name] = $column;
            }
        }

        return $byName;
    }

    /**
     * @return array{feature: string, area: string, priority: string, source: string, port: string, evidence: string, notes: string}
     */
    private static function row(string $feature, string $area, string $priority, string $port, string $evidence, string $notes): array
    {
        return [
            'feature' => $feature,
            'area' => $area,
            'priority' => $priority,
            'source' => 'yes',
            'port' => $port,
            'evidence' => $evidence,
            'notes' => $notes,
        ];
    }

    /**
     * Assemble the card: score the rows with the matrix rule
     * and phrase the verdict in the matrix's vocabulary.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array{dimension: string, reason: string}>  $notMeasured
     * @return array<string, mixed>
     */
    private static function card(string $runKind, mixed $domain, array $rows, array $notMeasured): array
    {
        $scored = self::score($rows);

        return [
            'kind' => 'porter-fidelity-scorecard',
            'version' => 1,
            'generated_at' => date('c'),
            'run' => $runKind,
            'domain' => is_string($domain) ? $domain : null,
            'score' => $scored['score'],
            'must_haves' => $scored['must_haves'],
            'verdict' => $scored['verdict'],
            'areas' => $scored['areas'],
            'not_measured' => $notMeasured,
            'gate_note' => 'Score only — the score never upgrades a gate verdict; port status remains the lowest of Gates S/D/F/T/V. This card measures declaration, relation, identity, allowable-value and data fidelity at port time, plus recorded gate evidence. Furniture (F), Tasks (T) and Visual (V) conformance require the running apps and are NOT measured here.',
            'rows' => $rows,
        ];
    }

    /**
     * The port matrix scoring rule (port-matrix.md), shared
     * by every dimension: weights must 3 / should 2 / could 1;
     * credits yes 1 / partial ½ / no 0; skip rows and
     * port-side additions excluded; verdict — any must-have
     * not fully done means NOT SHIPPABLE, all must-haves done
     * and score ≥ 80 means SHIPPABLE.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{score: float, must_haves: array{done: int, total: int}, verdict: string, areas: array<string, array{score: ?float, rows: int}>}
     */
    public static function score(array $rows): array
    {
        $earned = 0.0;
        $possible = 0.0;
        $mustDone = 0;
        $mustTotal = 0;
        $areas = [];
        foreach ($rows as $row) {
            $area = (string) ($row['area'] ?? 'General');
            $areas[$area] ??= ['earned' => 0.0, 'possible' => 0.0, 'rows' => 0];
            if (($row['port'] ?? '') === 'skip' || ($row['source'] ?? 'yes') === 'no') {
                continue;
            }
            $weight = self::WEIGHTS[(string) ($row['priority'] ?? 'should')] ?? 2;
            $credit = self::CREDITS[(string) ($row['port'] ?? 'no')] ?? 0.0;
            $earned += $weight * $credit;
            $possible += $weight;
            $areas[$area]['earned'] += $weight * $credit;
            $areas[$area]['possible'] += $weight;
            $areas[$area]['rows']++;
            if (($row['priority'] ?? '') === 'must') {
                $mustTotal++;
                if (($row['port'] ?? '') === 'yes') {
                    $mustDone++;
                }
            }
        }

        $score = $possible > 0 ? round(100 * $earned / $possible, 1) : 0.0;
        $verdict = ($mustTotal > 0 && $mustDone === $mustTotal && $score >= 80.0)
            ? 'SHIPPABLE'
            : 'NOT SHIPPABLE';

        $areaScores = [];
        foreach ($areas as $area => $totals) {
            $areaScores[$area] = [
                'score' => $totals['possible'] > 0 ? round(100 * $totals['earned'] / $totals['possible'], 1) : null,
                'rows' => $totals['rows'],
            ];
        }
        // Weakest first, like the matrix report.
        uasort($areaScores, static fn ($a, $b) => ($a['score'] ?? -1) <=> ($b['score'] ?? -1));

        return [
            'score' => $score,
            'must_haves' => ['done' => $mustDone, 'total' => $mustTotal],
            'verdict' => $verdict,
            'areas' => $areaScores,
        ];
    }

    /**
     * Write the durable artefacts (JSON + founder-legible
     * markdown) into the target app's porting directory.
     *
     * @param  array<string, mixed>  $card
     * @return array{json: string, markdown: string}
     */
    public static function emit(array $card, string $dir): array
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $json = $dir.DIRECTORY_SEPARATOR.self::JSON_FILE;
        $md = $dir.DIRECTORY_SEPARATOR.self::MD_FILE;
        file_put_contents($json, json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($md, self::renderMarkdown($card));

        return ['json' => $json, 'markdown' => $md];
    }

    /**
     * The legible rendering: the REPLICA-STATUS-style score
     * line first, then per-dimension scores (weakest first),
     * the not-measured list with reasons, and the missing
     * list in build order (priority, then least-done first)
     * — the same report shape port_matrix.py --markdown
     * produces for the hand-maintained matrix.
     *
     * @param  array<string, mixed>  $card
     */
    public static function renderMarkdown(array $card): string
    {
        $date = date('j M Y', strtotime((string) ($card['generated_at'] ?? 'now')) ?: time());
        $must = (array) ($card['must_haves'] ?? []);
        $weakest = [];
        foreach ((array) ($card['areas'] ?? []) as $area => $info) {
            if (($info['score'] ?? null) !== null && $info['score'] < 100.0) {
                $weakest[] = "{$area} {$info['score']}";
            }
        }
        $verdictLine = ($card['verdict'] ?? 'NOT SHIPPABLE') === 'SHIPPABLE'
            ? 'SHIPPABLE'
            : 'NOT SHIPPABLE — '.max(0, (int) ($must['total'] ?? 0) - (int) ($must['done'] ?? 0)).' must-have(s) not fully done';

        $out = [];
        $out[] = '# Porter fidelity scorecard';
        $out[] = '';
        $out[] = "Emitted by the porter run itself ({$card['run']} run".(! empty($card['domain']) ? ", domain {$card['domain']}" : '').", {$date}) — computed from the run's inspection, the declarations it produced, its data import and the recorded gate evidence. Not assembled by hand.";
        $out[] = '';
        $out[] = "Fidelity score ({$date}, porting/scorecard.json): {$card['score']}/100 · must-haves ".($must['done'] ?? 0).'/'.($must['total'] ?? 0)
            .($weakest !== [] ? ' · weakest: '.implode(', ', array_slice($weakest, 0, 3)) : '')
            ." · {$verdictLine}. (Score only — the gate verdict stands; see the note below.)";
        $out[] = '';
        $out[] = '## Dimensions';
        $out[] = '';
        $out[] = '| Dimension | Score | Measured rows |';
        $out[] = '|---|---|---|';
        foreach ((array) ($card['areas'] ?? []) as $area => $info) {
            $out[] = "| {$area} | ".($info['score'] === null ? '—' : $info['score'].'/100')." | {$info['rows']} |";
        }
        $out[] = '';

        $notMeasured = (array) ($card['not_measured'] ?? []);
        if ($notMeasured !== []) {
            $out[] = '## Not measured by this run';
            $out[] = '';
            foreach ($notMeasured as $entry) {
                $out[] = "- **{$entry['dimension']}** — {$entry['reason']}";
            }
            $out[] = '';
        }

        $missing = array_values(array_filter(
            (array) ($card['rows'] ?? []),
            static fn ($row) => in_array($row['port'] ?? '', ['no', 'partial'], true)
        ));
        usort($missing, static function ($a, $b) {
            $byPriority = (self::WEIGHTS[$b['priority'] ?? 'should'] ?? 2) <=> (self::WEIGHTS[$a['priority'] ?? 'should'] ?? 2);
            if ($byPriority !== 0) {
                return $byPriority;
            }

            return (self::CREDITS[$a['port'] ?? 'no'] ?? 0) <=> (self::CREDITS[$b['port'] ?? 'no'] ?? 0);
        });
        if ($missing !== []) {
            $out[] = '## Missing or partial — in build order';
            $out[] = '';
            $out[] = '| Feature | Dimension | Priority | Port | Notes |';
            $out[] = '|---|---|---|---|---|';
            foreach ($missing as $row) {
                $out[] = "| {$row['feature']} | {$row['area']} | {$row['priority']} | {$row['port']} | ".str_replace('|', '\\|', (string) ($row['notes'] ?? '')).' |';
            }
            $out[] = '';
        }

        $out[] = '> '.(string) ($card['gate_note'] ?? '');
        $out[] = '';

        return implode("\n", $out);
    }
}
