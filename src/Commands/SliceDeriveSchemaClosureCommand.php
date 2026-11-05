<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Porting\SlicePortService;

/**
 * Gate S schema closure for an EXISTING port.
 *
 * The porter's migration inspector now replays alter migrations,
 * so fresh ports land complete. Ports scaffolded before that fix
 * (or from a migrations-only inspection) are missing every column
 * the source added after its create migration — the ETL then had
 * nowhere to put those values. This command performs the Gate S
 * column-level diff per manifest table and, with --apply, closes
 * it the way a careful hand-carry would:
 *
 *   1. one guarded migration adding the missing columns with the
 *      source's own types / nullability / defaults;
 *   2. the fields appended to the slice manifest, the Schema
 *      form, and the Listing/Form business objects (a value that
 *      exists in the table but not in the listing BO still
 *      renders as empty — the BO is part of the closure);
 *   3. the values copied from the source by primary key.
 *
 * Deliberate exclusions, reported rather than silently skipped:
 * framework infrastructure tables and `deleted_at` (hard-delete
 * design: soft-delete stamps are not carried). The auth-merged
 * tables (users/roles/permissions — the target auth schema
 * differs by design) are closed for their NON-auth columns only:
 * credentials, tokens, MFA material and permission payloads
 * never cross (see AUTH_SENSITIVE_COLUMNS); plain data columns
 * the source added later (a role's display_name, a user's
 * last_login) close like any other table's.
 *
 * Dry-run by default; --apply writes.
 */
class SliceDeriveSchemaClosureCommand extends Command
{
    protected $signature = 'slice:derive-schema-closure
        {--slice= : Only this slice name}
        {--source-host= : Legacy database host}
        {--source-port= : Legacy database port}
        {--source-database= : Legacy database name}
        {--source-username= : Legacy database username}
        {--source-password= : Legacy database password}
        {--apply : Write the closure migration, manifest/Schema/BO fields, and copy values}
        {--no-data : With --apply, add columns and declarations but do not copy values}';

    protected $description = 'Gate S: diff ported tables against the source schema and close missing columns (migration + declarations + values)';

    private const AUTH_TABLES = ['users', 'roles', 'permissions', 'role_user', 'permission_role', 'model_has_roles', 'model_has_permissions'];

    /**
     * Columns that must never be schema-closed on an auth table:
     * credentials, session/recovery material, MFA secrets and
     * permission payloads. Matched exactly, plus any *_token /
     * *_secret column by suffix.
     */
    private const AUTH_SENSITIVE_COLUMNS = [
        'password', 'remember_token', 'api_token', 'customised_permissions',
        'mfa_channel', 'mfa_secret', 'mfa_confirmed_at',
        'two_factor_secret', 'two_factor_recovery_codes',
        'failed_attempts', 'locked_until', 'last_login_ip',
        // Permission payloads, payment identifiers, opaque blobs
        // and auth-behaviour switches are not descriptive data.
        'permission_sync', 'allowed_permissions', 'stripe_id',
        'trial_ends_at', 'headers', 'login',
        // The pivot's permission-scope column is permission
        // machinery (its target-layer scope is an owner decision),
        // not descriptive data.
        'permission_type_id',
    ];

    private const DESIGNED_OUT_COLUMNS = ['deleted_at'];

    /**
     * Framework-managed columns: present in every ported table,
     * deliberately never declared as slice fields (§21.6 — forms
     * never render system columns). Not closure gaps.
     */
    private const SYSTEM_COLUMNS = ['id', 'created_at', 'updated_at', 'company_id'];

    public function handle(SliceManager $manager): int
    {
        $conn = $this->sourceConnection();
        if ($conn === null) {
            $this->error('No source database. Pass --source-database/--source-username/--source-password (or set laraslice.porting.source_connection).');

            return self::FAILURE;
        }

        $manager->discover(true);
        $only = $this->option('slice');
        $apply = (bool) $this->option('apply');

        $plan = [];   // sliceName => ['dir'=>, 'tables'=>[ table => ['missing'=>[colmeta], 'model'=>handle] ]]
        $skipped = [];
        $claimed = [];
        foreach ($manager->getSlices() as $slice) {
            if ($only && $slice->name !== $only) {
                continue;
            }
            $raw = $slice->raw ?? [];
            foreach (($raw['models'] ?? []) as $model) {
                $table = (string) ($model['table'] ?? '');
                if ($table === '') {
                    continue;
                }
                $claimed[$table] = true;
                if (in_array($table, SlicePortService::FRAMEWORK_INFRASTRUCTURE_TABLES, true)) {
                    $skipped[$table] = 'framework design';

                    continue;
                }
                $authMode = in_array($table, self::AUTH_TABLES, true);
                if (! Schema::hasTable($table)) {
                    $skipped[$table] = 'target table missing';

                    continue;
                }
                $sourceCols = $this->sourceColumns($conn, $table);
                if ($sourceCols === null) {
                    $skipped[$table] = 'not in source';

                    continue;
                }
                $targetCols = Schema::getColumnListing($table);
                $declared = collect($model['fields'] ?? [])->pluck('handle')->all();
                $missing = [];
                foreach ($sourceCols as $name => $meta) {
                    if (in_array($name, self::DESIGNED_OUT_COLUMNS, true) || in_array($name, self::SYSTEM_COLUMNS, true)) {
                        continue;
                    }
                    if ($authMode && self::isAuthSensitive($name)) {
                        continue;
                    }
                    if (! in_array($name, $targetCols, true) || ! in_array($name, $declared, true)) {
                        $missing[$name] = $meta + ['in_table' => in_array($name, $targetCols, true), 'in_manifest' => in_array($name, $declared, true)];
                    }
                }
                if ($missing !== []) {
                    $plan[$slice->name]['dir'] = $slice->path;
                    $plan[$slice->name]['tables'][$table] = ['missing' => $missing, 'model' => (string) ($model['handle'] ?? '')];
                }
            }
        }

        // Auth tables no app slice owns (the framework's bundled
        // auth slices carry them): close their non-sensitive data
        // columns as migration + values only — there is no app
        // manifest to declare into, and surfaces resolve the
        // values through the model once the columns exist.
        if (! $only) {
            foreach (self::AUTH_TABLES as $table) {
                if (isset($claimed[$table]) || ! Schema::hasTable($table)) {
                    continue;
                }
                $sourceCols = $this->sourceColumns($conn, $table);
                if ($sourceCols === null) {
                    continue;
                }
                $targetCols = Schema::getColumnListing($table);
                $missing = [];
                foreach ($sourceCols as $name => $meta) {
                    if (in_array($name, self::DESIGNED_OUT_COLUMNS, true) || in_array($name, self::SYSTEM_COLUMNS, true)
                        || self::isAuthSensitive($name)) {
                        continue;
                    }
                    if (! in_array($name, $targetCols, true)) {
                        $missing[$name] = $meta + ['in_table' => false, 'in_manifest' => false];
                    }
                }
                if ($missing !== []) {
                    $plan['(auth tables)']['dir'] = null;
                    $plan['(auth tables)']['tables'][$table] = ['missing' => $missing, 'model' => '', 'auth' => true];
                }
            }
        }

        $totalCols = 0;
        foreach ($plan as $sliceName => $info) {
            foreach ($info['tables'] as $table => $t) {
                $totalCols += count($t['missing']);
                $this->line("<info>{$sliceName}</info> ({$table}): +".count($t['missing']).' columns — '.implode(', ', array_keys($t['missing'])));
            }
        }
        foreach ($skipped as $table => $why) {
            $this->line("<fg=gray>skipped {$table} ({$why})</>");
        }
        $this->line("Total: {$totalCols} missing columns across ".count($plan).' slices.');

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to close.');

            return self::SUCCESS;
        }
        if ($plan === []) {
            $this->info('Nothing to close.');

            return self::SUCCESS;
        }

        // 1. Migration for columns missing from the TABLES.
        $migrationTables = [];
        foreach ($plan as $info) {
            foreach ($info['tables'] as $table => $t) {
                foreach ($t['missing'] as $name => $meta) {
                    if (! $meta['in_table']) {
                        $migrationTables[$table][$name] = $meta;
                    }
                }
            }
        }
        if ($migrationTables !== []) {
            $path = $this->writeMigration($migrationTables);
            $this->info('Migration written: '.basename($path));
            Artisan::call('migrate', ['--force' => true]);
            $this->info('Migrations run.');
        }

        // 2+3. Declarations + data per slice/table.
        foreach ($plan as $sliceName => $info) {
            foreach ($info['tables'] as $table => $t) {
                if (($info['dir'] ?? null) !== null) {
                    $this->closeManifest($info['dir'], $table, $t['missing']);
                    $this->closeSchemaAndBos($info['dir'], $t['model'], $t['missing']);
                }
                if (! $this->option('no-data')) {
                    // Auth rows pair by natural key, never by id:
                    // the auth merge reassigns ids (framework seed
                    // roles/permissions precede the imported ones),
                    // so an id copy would smear values across
                    // unrelated rows.
                    $copied = ! empty($t['auth'])
                        ? $this->copyAuthValues($conn, $table, array_keys($t['missing']))
                        : $this->copyValues($conn, $table, array_keys($t['missing']));
                    $this->line("  {$table}: values copied for {$copied} rows.");
                }
            }
        }

        try {
            Artisan::call('slice:cache');
        } catch (\Throwable) {
        }
        $this->info('Closure applied. Re-run without --apply to verify the diff is empty.');

        return self::SUCCESS;
    }

    private static function isAuthSensitive(string $column): bool
    {
        return in_array($column, self::AUTH_SENSITIVE_COLUMNS, true)
            || str_ends_with($column, '_token')
            || str_ends_with($column, '_secret')
            || str_ends_with($column, '_ip')
            || str_starts_with($column, 'two_factor_')
            || str_starts_with($column, 'two_fa_')
            || str_starts_with($column, 'pm_');
    }

    private function sourceConnection(): ?string
    {
        if ($this->option('source-database')) {
            config(['database.connections.laraslice_closure_source' => [
                'driver' => 'mysql',
                'host' => $this->option('source-host') ?: '127.0.0.1',
                'port' => $this->option('source-port') ?: 3306,
                'database' => $this->option('source-database'),
                'username' => $this->option('source-username') ?: '',
                'password' => $this->option('source-password') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]]);

            return 'laraslice_closure_source';
        }

        return config('laraslice.porting.source_connection') ?: null;
    }

    /** @return array<string, array{type:string, raw:string, nullable:bool, default:mixed}>|null */
    private function sourceColumns(string $conn, string $table): ?array
    {
        $rows = DB::connection($conn)->select(
            'SELECT column_name AS n, column_type AS t, is_nullable AS nullable, column_default AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if ($rows === []) {
            return null;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[$r->n] = ['type' => $this->baseType((string) $r->t), 'raw' => (string) $r->t, 'nullable' => $r->nullable === 'YES', 'default' => $r->d];
        }

        return $out;
    }

    private function baseType(string $columnType): string
    {
        $t = strtolower($columnType);
        if (preg_match('/^enum\((.*)\)$/', $t, $m)) {
            return 'enum:'.$m[1];
        }

        return preg_replace('/\(.*$/', '', $t) ?? $t;
    }

    /** @return array{0:string,1:string,2:string,3:string} [manifest type, blueprint call, BO type+default, schema input] */
    private function typeMap(array $meta): array
    {
        $raw = $meta['raw'];
        if ($meta['type'] === 'tinyint' && str_contains($raw, '(1)')) {
            return ['boolean', "\$table->boolean('%s')", 'bool $%s = false', 'boolean'];
        }
        if (str_starts_with($meta['type'], 'enum:')) {
            return ['string', "\$table->string('%s')", '?string $%s = NULL', 'text'];
        }

        return match ($meta['type']) {
            'int', 'bigint', 'smallint', 'mediumint', 'tinyint', 'year' => ['integer', "\$table->bigInteger('%s')", '?int $%s = NULL', 'number'],
            'decimal', 'numeric', 'float', 'double' => ['decimal', "\$table->decimal('%s', 15, 2)", '?float $%s = NULL', 'decimal'],
            'text', 'mediumtext', 'longtext', 'json' => ['text', "\$table->text('%s')", '?string $%s = NULL', 'textarea'],
            'date' => ['date', "\$table->date('%s')", '?string $%s = NULL', 'date'],
            'datetime', 'timestamp' => ['datetime', "\$table->timestamp('%s')", '?string $%s = NULL', 'datetime-local'],
            default => ['string', "\$table->string('%s')", '?string $%s = NULL', 'text'],
        };
    }

    private function writeMigration(array $tables): string
    {
        $blocks = '';
        foreach ($tables as $table => $cols) {
            $blocks .= "        Schema::table('{$table}', function (Blueprint \$table) {\n";
            foreach ($cols as $name => $meta) {
                [, $call] = $this->typeMap($meta);
                $stmt = sprintf($call, $name);
                if ($meta['nullable']) {
                    $stmt .= '->nullable()';
                } elseif ($meta['default'] !== null) {
                    $stmt .= '->default('.var_export($meta['default'], true).')';
                }
                $blocks .= "            if (! Schema::hasColumn('{$table}', '{$name}')) {\n                {$stmt};\n            }\n";
            }
            $blocks .= "        });\n";
        }
        $content = <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        /**
         * Gate S schema closure (slice:derive-schema-closure): columns
         * the source added after its create migrations, carried into
         * this port with the source's types so ETL values have a home.
         */
        return new class extends Migration
        {
            public function up(): void
            {
        {$blocks}    }
        };
        PHP;
        $path = database_path('migrations/'.date('Y_m_d_His').'_gate_s_schema_closure.php');
        file_put_contents($path, $content);

        return $path;
    }

    private function closeManifest(string $dir, string $table, array $missing): void
    {
        $file = $dir.'/slice.yaml';
        if (! is_file($file)) {
            return;
        }
        $lines = explode("\n", (string) file_get_contents($file));
        // Locate the model block for this table and its fields region.
        $modelStart = null;
        $fieldsEnd = null;
        $inModels = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^models:/', $line)) {
                $inModels = true;

                continue;
            }
            if ($inModels && preg_match('/^[a-z_]+:/', $line)) {
                break;
            }
            if ($inModels && preg_match('/^  -$/', $line)) {
                $modelStart = $i;
                $fieldsEnd = null;

                continue;
            }
            if ($modelStart !== null && preg_match('/^    table: '.preg_quote($table, '/').'$/', $line)) {
                // Anchor on the model's `fields:` key FIRST (sibling
                // keys like `root:` sit between `table:` and
                // `fields:` — inserting at the first sibling key
                // lands the new fields outside the list entirely).
                $fieldsStart = null;
                for ($j = $i + 1; $j < count($lines); $j++) {
                    if (preg_match('/^    fields:/', $lines[$j])) {
                        $fieldsStart = $j;
                        break;
                    }
                    if (preg_match('/^  -$/', $lines[$j]) || preg_match('/^[a-z_]+:/', $lines[$j])) {
                        break;
                    }
                }
                if ($fieldsStart !== null) {
                    for ($j = $fieldsStart + 1; $j < count($lines); $j++) {
                        if (preg_match('/^    [a-z_]+:/', $lines[$j]) || preg_match('/^  -$/', $lines[$j]) || preg_match('/^[a-z_]+:/', $lines[$j])) {
                            $fieldsEnd = $j;
                            break;
                        }
                    }
                    $fieldsEnd ??= count($lines);
                }
                break;
            }
        }
        if ($fieldsEnd === null) {
            $this->warn("  manifest: model block for {$table} not found — skipped");

            return;
        }
        $insert = [];
        foreach ($missing as $name => $meta) {
            if ($meta['in_manifest']) {
                continue;
            }
            [$type] = $this->typeMap($meta);
            $label = Str::headline($name);
            $labelOut = preg_match('/^[A-Za-z]+$/', $label) ? $label : "'{$label}'";
            $insert[] = '      -';
            $insert[] = "        handle: {$name}";
            $insert[] = "        label: {$labelOut}";
            $insert[] = "        type: {$type}";
            $insert[] = '        required: false';
        }
        if ($insert !== []) {
            array_splice($lines, $fieldsEnd, 0, $insert);
            file_put_contents($file, implode("\n", $lines));
        }
    }

    private function closeSchemaAndBos(string $dir, string $modelHandle, array $missing): void
    {
        if ($modelHandle === '') {
            return;
        }
        $studly = Str::studly($modelHandle);
        $schemaFile = $dir."/Schemas/{$studly}Schema.php";
        if (is_file($schemaFile)) {
            $src = (string) file_get_contents($schemaFile);
            $add = [];
            foreach ($missing as $name => $meta) {
                if (str_contains($src, "'{$name}'")) {
                    continue;
                }
                [, , , $input] = $this->typeMap($meta);
                $add[] = "            Field::make('{$name}', '{$input}')->label('".Str::headline($name)."'),";
            }
            if ($add !== []) {
                // Line-wise insertion before form()'s closing `];`,
                // comma-terminating the previous last field line if
                // the generator left it bare (a regex over the raw
                // tail silently no-ops once the tail is trimmed).
                $lines = explode("\n", $src);
                $formLine = null;
                $closeLine = null;
                foreach ($lines as $i => $line) {
                    if (str_contains($line, 'function form()')) {
                        $formLine = $i;
                    }
                    if ($formLine !== null && trim($line) === '];') {
                        $closeLine = $i;
                        break;
                    }
                }
                if ($closeLine !== null) {
                    for ($j = $closeLine - 1; $j > $formLine; $j--) {
                        if (trim($lines[$j]) === '') {
                            continue;
                        }
                        if (str_contains($lines[$j], 'Field::make') && ! str_ends_with(rtrim($lines[$j]), ',')) {
                            $lines[$j] = rtrim($lines[$j]).',';
                        }
                        break;
                    }
                    array_splice($lines, $closeLine, 0, $add);
                    file_put_contents($schemaFile, implode("\n", $lines));
                }
            }
        }
        foreach (['Listing', 'Form'] as $kind) {
            $boFile = $dir."/Contracts/{$studly}{$kind}BusinessObject.php";
            if (! is_file($boFile)) {
                continue;
            }
            $src = (string) file_get_contents($boFile);
            $add = '';
            foreach ($missing as $name => $meta) {
                if (str_contains($src, '$'.$name)) {
                    continue;
                }
                [, , $boType] = $this->typeMap($meta);
                $add .= '    public '.sprintf($boType, $name).";\n";
            }
            if ($add !== '') {
                $pos = strrpos($src, "\n}");
                if ($pos !== false) {
                    $src = substr($src, 0, $pos)."\n".$add.substr($src, $pos);
                    file_put_contents($boFile, $src);
                }
            }
        }
    }

    /**
     * Value copy for auth tables: pair source and target rows by
     * their natural key (users by email, roles/permissions by
     * name). Falls back to the id copy when either side lacks
     * the key column.
     */
    private function copyAuthValues(string $conn, string $table, array $columns): int
    {
        $key = match ($table) {
            'users' => 'email',
            'roles', 'permissions' => 'name',
            default => 'id',
        };
        if ($key === 'id' || ! Schema::hasColumn($table, $key)) {
            return $this->copyValues($conn, $table, $columns);
        }
        $columns = array_values(array_filter($columns, static fn ($c) => $c !== 'id'));
        $targetCols = Schema::getColumnListing($table);
        $columns = array_values(array_intersect($columns, $targetCols));
        if ($columns === []) {
            return 0;
        }
        $sourceKeyed = [];
        DB::connection($conn)->table($table)->select(array_merge([$key], $columns))
            ->chunk(500, function ($rows) use (&$sourceKeyed, $key, $columns) {
                foreach ($rows as $row) {
                    $set = [];
                    foreach ($columns as $c) {
                        $set[$c] = $row->{$c};
                    }
                    $sourceKeyed[(string) $row->{$key}] = $set;
                }
            });
        $updated = 0;
        foreach ($sourceKeyed as $keyValue => $set) {
            $updated += DB::table($table)->where($key, $keyValue)->update($set);
        }

        return $updated;
    }

    private function copyValues(string $conn, string $table, array $columns): int
    {
        $columns = array_values(array_filter($columns, static fn ($c) => $c !== 'id'));
        if ($columns === [] || ! Schema::hasTable($table)) {
            return 0;
        }
        $targetCols = Schema::getColumnListing($table);
        $columns = array_values(array_intersect($columns, $targetCols));
        if ($columns === []) {
            return 0;
        }
        $updated = 0;
        DB::connection($conn)->table($table)->select(array_merge(['id'], $columns))->orderBy('id')
            ->chunk(500, function ($rows) use ($table, $columns, &$updated) {
                foreach ($rows as $row) {
                    $set = [];
                    foreach ($columns as $c) {
                        $set[$c] = $row->{$c};
                    }
                    $updated += DB::table($table)->where('id', $row->id)->update($set);
                }
            });

        return $updated;
    }
}
