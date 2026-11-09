<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManifest;

/**
 * Heart→DB differ for EXISTING slices (F11, Day-2 drill,
 * 8 Nov 2026).
 *
 * The drill's lifecycle hit a dead end: a field added to
 * an existing slice's Heart had NO framework path to the
 * database. slice:sync runs pending migration FILES (it
 * does not diff the Heart against the schema), blueprint
 * apply is create-only, and regen covers files, not
 * tables — so the alter migration had to be written by
 * hand. This differ closes that gap for the ROOT model's
 * table:
 *
 *  - declared field missing from the table → ADDITIVE
 *    candidate, emitted with the exact column expression
 *    FieldDefinitionNormalizer baked into the create
 *    migration, so the alter can never drift from what a
 *    fresh generate would produce;
 *  - additive is only SAFE when the new column is
 *    nullable, carries a declared default (booleans
 *    always coerce one), or the table is empty — a
 *    NOT NULL column with no default on a populated
 *    table is REFUSED, not guessed at;
 *  - declared field present but different (type family,
 *    varchar length where the driver reports it, or
 *    nullability) → REFUSED, named. slice:alter never
 *    modifies an existing column: any type change —
 *    narrowing OR widening — and any nullability change
 *    is an operator decision with data consequences;
 *  - table column the Heart does not declare (and not a
 *    framework-standard column) → named UNDECLARED and
 *    left untouched. Drops are never generated.
 *
 * Column DEFAULTS of existing columns are deliberately
 * not compared: a default is an application-level
 * concern, changing one rewrites no rows, and drivers
 * report them inconsistently.
 *
 * The command (slice:alter) is separate from slice:sync
 * on purpose: sync's GitOps contract is "apply the
 * migration files the repository carries, identically,
 * everywhere" — a command that GENERATES files from a
 * live diff belongs with the regen/absorb family
 * (dry-run default, --write applies, production needs
 * --force), whose conventions it follows.
 */
final class SliceAlterer
{
    /** Columns every slice table may carry without a field declaration. */
    private const STANDARD_COLUMNS = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'created_by', 'updated_by', 'workflow_state_entered_at',
    ];

    /**
     * Declared field type → live type_name values (as
     * Schema::getColumns reports them) that count as the
     * same family across MySQL/MariaDB and SQLite.
     *
     * @var array<string, array<int, string>>
     */
    private const TYPE_FAMILIES = [
        'string' => ['varchar'],
        'email' => ['varchar'],
        'url' => ['varchar'],
        'select' => ['varchar'],
        'enum' => ['varchar', 'enum'],
        'text' => ['text'],
        'integer' => ['int', 'integer'],
        'bigInteger' => ['bigint', 'integer'],
        'foreign_id' => ['bigint', 'integer'],
        'boolean' => ['tinyint', 'boolean', 'integer'],
        'decimal' => ['decimal', 'numeric'],
        'float' => ['double', 'float', 'real'],
        'date' => ['date'],
        'datetime' => ['datetime', 'timestamp'],
        'timestamp' => ['timestamp', 'datetime'],
        'json' => ['json', 'text'],
    ];

    /**
     * Diff the slice's declared fields against its live
     * root table.
     *
     * @return array{table: ?string, error: ?string, additive: array<int, array{name: string, expression: string}>, refused: array<int, array{name: string, reason: string}>, undeclared: array<int, string>}
     */
    public function plan(SliceManifest $manifest): array
    {
        $empty = ['table' => null, 'error' => null, 'additive' => [], 'refused' => [], 'undeclared' => []];

        $table = $this->tableFor($manifest);
        if ($table === null) {
            return [...$empty, 'error' => "Slice [{$manifest->name}] declares no table to diff against."];
        }
        if (! Schema::hasTable($table)) {
            return [...$empty, 'table' => $table, 'error' => "Table [{$table}] does not exist — slice:alter evolves existing slices; create the slice first (blueprint apply / slice:sync)."];
        }

        $raw = $manifest->raw;
        $defs = [];
        foreach ((array) ($raw['fields'] ?? []) as $def) {
            if (! is_array($def) || empty($def['name'])) {
                continue;
            }
            $field = [
                'name' => (string) $def['name'],
                'label' => (string) ($def['label'] ?? Str::headline((string) $def['name'])),
                'type' => (string) ($def['type'] ?? 'string'),
                'required' => (bool) ($def['required'] ?? ! ($def['nullable'] ?? true)),
                'nullable' => (bool) ($def['nullable'] ?? true),
                'default' => $def['default'] ?? null,
                'options' => $def['options'] ?? [],
                'length' => $def['length'] ?? null,
            ];
            foreach (['relation', 'references', 'hidden', 'show_in_form', 'show_in_list', 'encrypted', 'cast'] as $extra) {
                if (array_key_exists($extra, $def)) {
                    $field[$extra] = $def[$extra];
                }
            }
            $defs[] = $field;
        }
        $normalized = (new FieldDefinitionNormalizer)->normalize($defs);
        $declaredNames = array_map(static fn (array $f): string => (string) $f['name'], $normalized);

        $live = [];
        foreach (Schema::getColumns($table) as $column) {
            $live[(string) $column['name']] = $column;
        }

        $standard = self::STANDARD_COLUMNS;
        if ($manifest->multitenant && $manifest->tenant_key !== '') {
            $standard[] = $manifest->tenant_key;
        }

        $additive = [];
        $refused = [];
        $rowCount = null;
        foreach ($normalized as $field) {
            $name = (string) $field['name'];
            if (in_array($name, self::STANDARD_COLUMNS, true)) {
                continue;
            }
            if (! isset($live[$name])) {
                // Additive candidate — safe only when
                // existing rows cannot violate it.
                if (($field['nullable'] ?? true) || ($field['default'] ?? null) !== null) {
                    $additive[] = ['name' => $name, 'expression' => $field['migration']];
                    continue;
                }
                $rowCount ??= DB::table($table)->count();
                if ($rowCount === 0) {
                    $additive[] = ['name' => $name, 'expression' => $field['migration']];
                    continue;
                }
                $refused[] = [
                    'name' => $name,
                    'reason' => "declared NOT NULL with no default, and table [{$table}] holds {$rowCount} row(s) — adding it would fail or fabricate values; make the field nullable or declare a default in the Heart",
                ];
                continue;
            }

            // Existing column: compare, never modify.
            $column = $live[$name];
            $declaredType = (string) ($field['type'] ?? 'string');
            $liveType = strtolower((string) ($column['type_name'] ?? ''));
            $family = self::TYPE_FAMILIES[$declaredType] ?? null;
            if ($family !== null && $liveType !== '' && ! in_array($liveType, $family, true)) {
                $refused[] = [
                    'name' => $name,
                    'reason' => "type mismatch — Heart declares [{$declaredType}], live column is [{$liveType}]; slice:alter never changes a column's type (narrowing or widening is an operator decision)",
                ];
                continue;
            }
            $liveLength = $this->liveLength($column);
            $declaredLength = is_int($field['length'] ?? null) ? (int) $field['length'] : ($family === ['varchar'] ? 255 : null);
            if ($liveLength !== null && $declaredLength !== null && $liveLength !== $declaredLength) {
                $refused[] = [
                    'name' => $name,
                    'reason' => "length mismatch — Heart declares [{$declaredLength}], live column is [{$liveLength}]; slice:alter never changes a column's length",
                ];
                continue;
            }
            $liveNullable = (bool) ($column['nullable'] ?? true);
            if ($liveNullable !== (bool) ($field['nullable'] ?? true)) {
                $refused[] = [
                    'name' => $name,
                    'reason' => 'nullability mismatch — Heart declares '.(($field['nullable'] ?? true) ? 'nullable' : 'NOT NULL').', live column is '.($liveNullable ? 'nullable' : 'NOT NULL').'; slice:alter never changes nullability',
                ];
            }
        }

        $undeclared = [];
        foreach (array_keys($live) as $columnName) {
            if (! in_array($columnName, $declaredNames, true) && ! in_array($columnName, $standard, true)) {
                $undeclared[] = $columnName;
            }
        }

        return [
            'table' => $table,
            'error' => null,
            'additive' => $additive,
            'refused' => $refused,
            'undeclared' => $undeclared,
        ];
    }

    /**
     * The alter migration source for the additive set —
     * the generator's migration style: anonymous class,
     * hasColumn guards per column in up(), a guarded drop
     * of exactly the added columns in down().
     *
     * @param  array<int, array{name: string, expression: string}>  $additive
     */
    public function migrationSource(string $table, array $additive): string
    {
        $adds = '';
        foreach ($additive as $column) {
            $adds .= "\n            if (! Schema::hasColumn('{$table}', '{$column['name']}')) {\n                {$column['expression']}\n            }";
        }
        $names = implode(', ', array_map(static fn (array $c): string => "'{$c['name']}'", $additive));

        return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

// Generated by slice:alter from the slice's Heart — the
// additive Heart→DB diff (new declared columns only).
// Refused and undeclared columns are named in the
// slice:alter report; this migration never touches them.
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('{$table}')) {
            return;
        }

        Schema::table('{$table}', function (Blueprint \$table) {{$adds}
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('{$table}')) {
            return;
        }

        Schema::table('{$table}', function (Blueprint \$table) {
            \$columns = array_values(array_filter([{$names}], static fn (string \$column): bool => Schema::hasColumn('{$table}', \$column)));
            if (\$columns !== []) {
                \$table->dropColumn(\$columns);
            }
        });
    }
};
PHP;
    }

    /**
     * Write the alter migration into the slice's Migrations
     * directory and return its path. The timestamp is
     * bumped until the filename is free, so repeated
     * --write runs never overwrite an earlier alter.
     *
     * @param  array<int, array{name: string, expression: string}>  $additive
     */
    public function writeMigration(SliceManifest $manifest, string $table, array $additive): string
    {
        $dir = $manifest->path.'/Migrations';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $timestamp = time();
        do {
            $path = $dir.'/'.date('Y_m_d_His', $timestamp)."_add_columns_to_{$table}_table.php";
            $timestamp++;
        } while (file_exists($path));
        file_put_contents($path, $this->migrationSource($table, $additive));

        return $path;
    }

    /**
     * The slice's root table: the manifest's first declared
     * table, else its root model's table, else the
     * canonical plural of the slice name.
     */
    public function tableFor(SliceManifest $manifest): ?string
    {
        $raw = $manifest->raw;
        if (is_array($raw['tables'] ?? null) && is_string($raw['tables'][0] ?? null) && $raw['tables'][0] !== '') {
            return $raw['tables'][0];
        }
        foreach ((array) ($raw['models'] ?? []) as $model) {
            if (is_array($model) && ($model['root'] ?? false) === true && is_string($model['table'] ?? null) && $model['table'] !== '') {
                return $model['table'];
            }
        }
        if (is_array($raw['models'][0] ?? null) && is_string($raw['models'][0]['table'] ?? null) && $raw['models'][0]['table'] !== '') {
            return $raw['models'][0]['table'];
        }
        if (is_array($manifest->tables) && is_string($manifest->tables[0] ?? null) && $manifest->tables[0] !== '') {
            return $manifest->tables[0];
        }

        return Str::plural(Str::snake(SliceName::canonical($manifest->name)));
    }

    /**
     * The length a live column reports, when the driver
     * includes one in the full type string (varchar(100));
     * null when it does not (SQLite stores no length).
     *
     * @param  array<string, mixed>  $column
     */
    private function liveLength(array $column): ?int
    {
        $type = (string) ($column['type'] ?? '');
        if (preg_match('/\(\s*(\d+)\s*\)/', $type, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
