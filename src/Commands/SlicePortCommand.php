<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LaraSlice\Porting\PermissionMatrixExtractor;
use LaraSlice\Porting\SlicePortService;
use LaraSlice\Porting\SourceAnatomyExtractor;
use Symfony\Component\Yaml\Yaml;

/**
 * Enterprise Monolith Reverse-Engineering & Legacy System Porting Command.
 *
 * Introspects legacy Laravel schemas, classifies tables into domain-bounded vertical slices,
 * generates canonical YAML blueprints, extracts taxonomy seeders, and migrates operational data + files.
 */
class SlicePortCommand extends Command
{
    protected $signature = 'slice:port
                            {source? : Path to source project root (Laravel or other PHP e.g. Dolibarr) or leave empty and use --source-database}
                            {--plan= : Path to JSON architecture plan (e.g. docs/porting-architecture-plan.json)}
                            {--import-data : Stream and import operational data via ETL}
                            {--import-auth : Also import legacy roles/permissions via normalized merge (framework auth tables stay protected by default)}
                            {--super-domain= : Target Super-Domain (e.g. ERP, Legal, Core)}
                            {--domain= : Target Domain Group (default: Adjudication)}
                            {--mode= : Porting mode: system-base (taxonomies & dropdown seeders) or full-data (historical data migration)}
                            {--with-files : Copy physical file attachments from storage/app/public}
                            {--scaffold : Automatically scaffold physical vertical slices into app/Slices}
                            {--dry-run : Preview table-to-slice mapping without writing files}
                            {--strip-prefix= : Comma-separated table prefixes to strip from slice names (e.g. llx_,wp_). Defaults from config LARASLICE_PORT_STRIP_PREFIXES}
                            {--table-prefix= : Prefix applied to every ported TARGET table (e.g. ws_) so this port coexists with tables an earlier port or the host already owns. Slice and model names stay clean; framework auth tables are never prefixed}
                            {--source-connection= : Legacy DB connection name from config/database.php (overrides --source-* options)}
                            {--source-host= : Legacy database host (default: 127.0.0.1)}
                            {--source-port= : Legacy database port (default: 3306)}
                            {--source-database= : Legacy database name}
                            {--source-username= : Legacy database username}
                            {--source-password= : Legacy database password}
                            {--data-source= : Data to port: real (live DB, default), seeders (source seed files), both, none}
                            {--anatomy : Page-anatomy pass only: read page probes from porting/probes/ (<slug>.json, <slug>-detail.json) and write Body UI-spec evidence to porting/ui-spec/<slug>.anatomy.ui.yaml}
                            {--permissions : Permission-matrix pass only: extract the source roles x permissions matrix (via --source-* options) and write porting/permissions.yaml with proposed LaraSlice slugs and Mind club drafts}';

    protected $description = 'Reverse-engineer and port a legacy Laravel application into domain-bounded LaraSlice vertical slices';

    public function handle(SlicePortService $portService): int
    {
        if ($this->option('anatomy')) {
            return $this->runAnatomy();
        }

        if ($this->option('permissions')) {
            return $this->runPermissions();
        }

        $this->info('====================================================');
        $this->info('   LaraSlice Enterprise Legacy Porting Engine       ');
        $this->info('====================================================');

        // Check if an Architecture Plan JSON was provided
        $planPath = $this->option('plan');
        if (! empty($planPath)) {
            if (! File::exists($planPath)) {
                $cand = base_path($planPath);
                if (File::exists($cand)) {
                    $planPath = $cand;
                } else {
                    $this->error("Architecture plan file not found: {$planPath}");
                    return self::FAILURE;
                }
            }

            $this->info("📋 Loading Architecture Plan from: {$planPath}");
            $plan = json_decode(File::get($planPath), true);
            if (! is_array($plan) || empty($plan['modules'])) {
                $this->error("Invalid architecture plan format: missing 'modules' array.");
                return self::FAILURE;
            }

            $domain = $plan['domain'] ?? 'ERP';
            $summary = $plan['summary'] ?? [];
            $modules = $plan['modules'] ?? [];
            $totalSlices = $summary['total_canonical_slices'] ?? count($modules);
            $totalRecords = $summary['total_records_detected'] ?? 0;
            $tenancy = $summary['multi_tenancy'] ?? [];

            $this->info("✓ Target Domain: {$domain}");
            $this->info("✓ Canonical Slices: {$totalSlices} across " . count($modules) . " modules");
            $this->info("✓ Legacy Database Records: " . number_format($totalRecords));
            if (! empty($tenancy['enabled'])) {
                $this->info("✓ Multi-Tenancy Scoping: {$tenancy['tenant_key']} ({$tenancy['coverage_percentage']}% coverage)");
            }

            // Print summary table
            $rows = [];
            foreach ($modules as $mod) {
                $modName = $mod['name'] ?? 'Core';
                foreach ($mod['slices'] ?? [] as $sl) {
                    $cTables = empty($sl['child_tables']) ? '-' : implode(', ', array_map(fn($c) => is_array($c) ? ($c['table'] ?? '') : (string)$c, $sl['child_tables']));
                    $rows[] = [
                        $modName,
                        $sl['name'] ?? '',
                        $sl['root_table'] ?? '',
                        $cTables,
                        $sl['records_count'] ?? 0,
                    ];
                }
            }
            $this->table(['Module', 'Slice', 'Root Table', 'Child Tables', 'Records'], $rows);

            if ($this->option('dry-run')) {
                $this->warn('Dry-run mode: skipping slice generation and migrations.');
                return self::SUCCESS;
            }

            if (! $this->option('no-interaction') && ! $this->confirm('Execute slice generation and migrations from this plan?', true)) {
                $this->warn('Porting aborted by user.');
                return self::SUCCESS;
            }

            $importData = (bool) $this->option('import-data');
            $dataSource = $this->option('data-source');
            if (! $importData && ! $this->option('no-interaction')) {
                $importData = $this->confirm('Stream and import operational database records via ETL?', true);
            }

            // Ask which data source if importing and not specified
            if ($importData && ! $dataSource && ! $this->option('no-interaction')) {
                $dataSource = $this->choice(
                    'Which data source should be ported?',
                    [
                        'real' => 'Real data (live database — always up-to-date)',
                        'seeders' => 'Seeders (source seed files — may be outdated)',
                        'both' => 'Both (real data for transactions, seeders for reference)',
                        'none' => 'None (schema only)',
                    ],
                    'real'
                );
                $this->comment("  → Seeders may be outdated. Live database is always current.");
            }
            $dataSource = $dataSource ?: 'real';

            $withFiles = (bool) $this->option('with-files');

            $this->comment("⚙️  Scaffolding vertical slices, migrations, and models from plan...");
            $importAuth = (bool) $this->option('import-auth');
            $result = $portService->executeFromPlan($plan, $importData, $withFiles, $importAuth, $sourceConnection ?? null);

            if (! empty($result['slices_created'])) {
                $this->info("✓ Scaffolded " . count($result['slices_created']) . " canonical slices!");
            }
            if (! empty($result['child_tables_created'])) {
                $this->info("✓ Scaffolded " . count($result['child_tables_created']) . " child tables & models!");
            }
            if (! empty($result['errors'])) {
                foreach ($result['errors'] as $s => $err) {
                    $this->warn("  [{$s}] {$err}");
                }
            }
            if (! empty($result['collisions'])) {
                $this->warn('⚠  Slice collisions detected — existing slices already claim these names/tables; their UI may keep serving the ported data:');
                foreach ($result['collisions'] as $hit) {
                    $this->warn("  [{$hit['slice']}] {$hit['type']} collision on table {$hit['table']} with existing slice {$hit['existing_slice']} ({$hit['existing_path']})");
                }
            }

            if (! empty($result['data_import'])) {
                $di = $result['data_import'];
                $this->info("✓ ETL Data Streaming: {$di['tables_imported']} tables imported, " . number_format($di['total_rows']) . " rows migrated!");
            }

            $this->line('');
            $this->info('🎉 Porting from architecture plan completed successfully!');
            $this->comment('Next: Run `php artisan slice:install` to sync super-admin permissions and access.');
            return self::SUCCESS;
        }

        $source = $this->argument('source');
        if (! $source) {
            $source = $this->ask('Enter path to legacy Laravel project root (e.g. e:/laragon/www/erp or .):', '.');
        }

        $domain = $this->option('domain');
        if (! $domain) {
            $domain = $this->ask('Enter target Domain name (e.g. Adjudication, ERP, SalesCRM, HRMS):', 'Adjudication');
        }

        $superDomain = $this->option('super-domain');

        // Apply --strip-prefix to config before inspect naming
        $stripOpt = $this->option('strip-prefix');
        if (is_string($stripOpt) && $stripOpt !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', $stripOpt))));
            config(['laraslice.porting.strip_table_prefixes' => $list]);
        }

        // Apply --table-prefix to config before inspect/scaffold/ETL
        $tablePrefix = $this->option('table-prefix');
        if (is_string($tablePrefix) && $tablePrefix !== '') {
            config(['laraslice.porting.table_prefix' => $tablePrefix]);
            $this->info("✓ Target table prefix: {$tablePrefix} (ported tables land as {$tablePrefix}<table>; auth tables exempt)");
        }

        $mode = $this->option('mode');
        if (! $mode) {
            // --dry-run / --no-interaction must not hang on prompts (CI, Manus)
            if ($this->option('dry-run') || ! $this->input->isInteractive()) {
                $mode = 'system-base';
            } else {
                $modeChoice = $this->choice(
                    'Select Data Porting Mode:',
                    [
                        'system-base' => 'System Base Only (Extract master taxonomies, statuses, categories & seeders - 0 blank dropdowns)',
                        'full-data' => 'Full Historical Data Migration (Port all operational records + file attachments)',
                    ],
                    'system-base'
                );
                $mode = ($modeChoice === 'System Base Only (Extract master taxonomies, statuses, categories & seeders - 0 blank dropdowns)') ? 'system-base' : 'full-data';
            }
        }

        $withFiles = (bool) $this->option('with-files');
        if ($mode === 'full-data' && ! $this->option('with-files') && $this->input->isInteractive() && ! $this->option('dry-run')) {
            $withFiles = $this->confirm('Do you want to copy physical file attachments from legacy storage/app/public?', true);
        }

        $this->line('');
        $this->comment("🔍 Introspecting legacy schema at: {$source}...");

        try {
            $inspection = $portService->inspect($source, $domain);
        } catch (\Throwable $e) {
            $this->error("Inspection failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("✓ Discovered {$inspection['total_tables']} total database tables.");

        // Tenancy detection feedback
        if ($inspection['tenancy']['enabled'] ?? false) {
            $tKey = $inspection['tenancy']['tenant_key'];
            $pct = $inspection['tenancy']['coverage_percentage'];
            $aff = $inspection['tenancy']['affected_tables'];
            $this->info("✓ Enterprise Multi-Tenancy Detected: Key '{$tKey}' present across {$aff} tables ({$pct}% database coverage).");
            $this->comment("  -> Models and slices will automatically be equipped with HasTenant isolation.");
        } else {
            $this->line("  Multi-Tenancy: Single-tenant schema (no tenant key scoping required).");
        }
        $this->line('');

        // Render Table-to-Slice Mapping Summary
        $rows = [];
        foreach ($inspection['slice_mapping'] as $sName => $info) {
            $children = empty($info['child_tables']) ? 'None' : implode(', ', $info['child_tables']);
            $rows[] = [$sName, $info['type'], $info['primary_table'], $children];
        }

        $this->table(['Target Slice', 'Architecture Type', 'Primary Table', 'Attached Child / Pivot Tables'], $rows);

        if (! empty($inspection['unmapped_pivots'])) {
            $this->warn('Pivot tables not attached to any slice (review manually): ' . implode(', ', $inspection['unmapped_pivots']));
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry-run mode: skipping blueprint and seeder generation.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Proceed with porting and blueprint generation?', true)) {
            $this->info('Porting aborted by user.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->comment("⚙️  Synthesizing domain blueprint & {$mode} seeders...");

        // Build legacy source DB connection from --source-* options (or named connection).
        $sourceConnectionName = $this->option('source-connection');
        if ($sourceConnectionName) {
            $connConfig = config("database.connections.{$sourceConnectionName}");
            if (! $connConfig) {
                $this->error("Source connection [{$sourceConnectionName}] is not defined in config/database.php.");
                return self::FAILURE;
            }
            $sourceConnection = [
                'host' => $connConfig['host'] ?? '127.0.0.1',
                'port' => $connConfig['port'] ?? 3306,
                'database' => $connConfig['database'] ?? '',
                'username' => $connConfig['username'] ?? '',
                'password' => $connConfig['password'] ?? '',
            ];
            config(['laraslice.porting.source_connection' => $sourceConnectionName]);
        } else {
            $sourceConnection = [
                'host' => $this->option('source-host') ?: '127.0.0.1',
                'port' => $this->option('source-port') ?: 3306,
                'database' => $this->option('source-database') ?: '',
                'username' => $this->option('source-username') ?: '',
                'password' => $this->option('source-password') ?: '',
            ];

            // Register runtime connection if database provided (for live table discovery)
            if (! empty($sourceConnection['database'])) {
                config(['database.connections.laraslice_source' => [
                    'driver' => 'mysql',
                    'host' => $sourceConnection['host'],
                    'port' => $sourceConnection['port'],
                    'database' => $sourceConnection['database'],
                    'username' => $sourceConnection['username'],
                    'password' => $sourceConnection['password'],
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                ]]);
                config(['laraslice.porting.source_connection' => 'laraslice_source']);
                $this->info("✓ Source database connection registered: {$sourceConnection['database']}@{$sourceConnection['host']}");
            }
        }

        try {
            $importAuth = (bool) $this->option('import-auth');
            if ($importAuth) {
                $this->warn('Auth import enabled: legacy roles/permissions will be merged by normalized slug. Framework roles (super-admin, super-dev) are never overwritten.');
            } else {
                $this->line('<fg=gray>Auth tables protected: roles/permissions skipped, users merged by email.</>');
            }
            $result = $portService->execute($source, $domain, $mode, $withFiles, $mode === 'full-data', $sourceConnection, $importAuth);
        } catch (\Throwable $e) {
            $this->error("Porting execution failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("✓ Domain blueprint synthesized: {$result['domain_blueprint']}");
        $this->info("✓ Vertical Slices mapped: " . implode(', ', $result['slices_generated']));

        if (! empty($result['seeders_generated'])) {
            $this->info("✓ Master seeders generated: " . count($result['seeders_generated']) . " file(s)");
        }

        if ($withFiles) {
            $this->info("✓ File assets migrated: {$result['files_migrated']} file(s) ({$result['bytes_migrated']} bytes)");
        }

        if ($this->option('scaffold') || (! $this->input->isInteractive() && $this->option('scaffold')) || ($this->input->isInteractive() && $this->confirm('Do you want to scaffold physical vertical slices into app/Slices now?', true))) {
            $this->line('');
            $targetPath = $superDomain ? "app/Slices/{$superDomain}/{$domain}/" : "app/Slices/{$domain}/";
            $this->comment("⚙️  Scaffolding physical vertical slices into {$targetPath}...");
            $scaffoldResult = $portService->scaffoldSlices($inspection, $domain, $superDomain);
            $this->info("✓ Successfully scaffolded " . count($scaffoldResult['created']) . " physical vertical slices!");

            $moduleGroups = [];
            foreach ($scaffoldResult['created'] as $s) {
                $moduleGroups[$s['module']][] = $s['slice'];
            }
            foreach ($moduleGroups as $mod => $sls) {
                $this->line("   [{$mod}] -> " . implode(', ', $sls));
            }

            if (! empty($scaffoldResult['errors'])) {
                $this->warn("Skipped " . count($scaffoldResult['errors']) . " slice(s) due to existing directories or conflicts:");
                foreach ($scaffoldResult['errors'] as $s => $err) {
                    $this->warn("  [{$s}] {$err}");
                }
            }

            // Handle --import-data with --scaffold (executeFromPlan is bypassed)
            if ($this->option('import-data')) {
                $this->comment("⚙️  Importing data via ETL...");
                try {
                    $dataPortService = new \LaraSlice\Porting\SliceDataPortService;
                    $dataImport = $dataPortService->importFromDatabase(
                        $sourceConnection['database'] ?? 'erp',
                        $sourceConnection['host'] ?? '127.0.0.1',
                        (int) ($sourceConnection['port'] ?? 3306),
                        $sourceConnection['username'] ?? 'root',
                        $sourceConnection['password'] ?? ''
                    );
                    if ($dataImport['success'] ?? false) {
                        $this->info("✓ Imported " . ($dataImport['total_rows'] ?? 0) . " rows across " . ($dataImport['tables_imported'] ?? 0) . " tables.");
                    } else {
                        $this->warn("Data import completed with issues.");
                    }
                } catch (\Throwable $e) {
                    $this->error("Data import failed: " . $e->getMessage());
                }
            }
        }

        $this->line('');
        $this->info('🎉 Porting analysis, blueprint synthesis & slice scaffolding completed successfully!');
        $this->info("You can now open /laraslice/wizard in your browser to view the active modules and slices.");

        return self::SUCCESS;
    }

    /**
     * Page-anatomy pass (P2): probes captured from the running
     * source app (porting/probes/<slug>.json for lists,
     * <slug>-detail.json for detail pages) become Body UI-spec
     * evidence files. The extractor holds the classification
     * rules; this pass only routes files.
     */
    /**
     * Resolution stats for the extracted matrix against
     * the TARGET app's declared permissions: a proposed
     * slug resolves when it is declared, or when it is a
     * view-own construct over a declared view. Grants
     * whose primary misses but whose parent-collapsed
     * alternative hits are counted separately.
     *
     * @param  array<string, mixed>  $matrix
     * @return array<string, int>
     */
    private function permissionResolution(array $matrix): array
    {
        $stats = ['declared_permissions' => 0, 'proposed' => 0, 'resolved' => 0, 'resolved_via_alt' => 0];
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('permissions')) {
                return $stats;
            }
            $declared = array_flip(\Illuminate\Support\Facades\DB::table('permissions')->pluck('slug')->all());
            $stats['declared_permissions'] = count($declared);
            $resolves = static function (?string $slug) use ($declared): bool {
                if ($slug === null || $slug === '*') {
                    return false;
                }
                if (isset($declared[$slug])) {
                    return true;
                }

                return str_ends_with($slug, '.view-own') && isset($declared[substr($slug, 0, -9).'.view']);
            };
            foreach ($matrix['roles'] as $role) {
                $alts = [];
                foreach ($role['grants'] as $grant) {
                    $alts[$grant['slug'] ?? ''] = $grant['alt_slug'] ?? null;
                }
                foreach ($role['club']['permissions'] as $slug) {
                    if ($slug === '*') {
                        continue;
                    }
                    $stats['proposed']++;
                    if ($resolves($slug)) {
                        $stats['resolved']++;
                    } elseif ($resolves($alts[$slug] ?? null)) {
                        $stats['resolved_via_alt']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Stats are advisory; the artifact stands without them.
        }

        return $stats;
    }

    /**
     * Permission-matrix pass (backlog C3): extract the
     * source's roles × permissions matrix into
     * porting/permissions.yaml — per-role grants with
     * source name, scope and proposed LaraSlice slug,
     * plus a Mind club draft per role. Proposals resolve
     * against the real registry via slice:drift, exactly
     * like Mind patterns.
     */
    private function runPermissions(): int
    {
        $database = (string) ($this->option('source-database') ?: '');
        if ($database === '') {
            $this->error('The --permissions pass needs --source-database (plus --source-username/--source-password when required).');

            return self::FAILURE;
        }
        $host = (string) ($this->option('source-host') ?: '127.0.0.1');
        $port = (int) ($this->option('source-port') ?: 3306);
        try {
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$database}",
                (string) ($this->option('source-username') ?: ''),
                (string) ($this->option('source-password') ?: ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
            );
        } catch (\Throwable $e) {
            $this->error('Could not connect to the source database: '.$e->getMessage());

            return self::FAILURE;
        }

        $matrix = PermissionMatrixExtractor::extract($pdo);
        foreach ($matrix['notes'] as $note) {
            $this->warn($note);
        }
        $matrix['resolution'] = $this->permissionResolution($matrix);

        $dir = base_path('porting');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $header = "# Source permission matrix (slice:port --permissions) — roles x permissions with scopes,\n"
            ."# normalized to proposed LaraSlice slugs. Proposals resolve against the registry via\n"
            ."# slice:drift (the Mind-pattern mechanism); 'clubs' is a draft for the system Mind.\n";
        file_put_contents(
            $dir.'/permissions.yaml',
            $header.Yaml::dump(['permissions_matrix' => $matrix], 8, 2)
        );

        $t = $matrix['totals'];
        $this->info(sprintf(
            'Permission matrix written: %d roles, %d source permissions, %d effective grants (%d unparsed) → porting/permissions.yaml',
            $t['roles'] ?? 0,
            $t['permissions'] ?? 0,
            $t['effective_grants'] ?? 0,
            $t['unparsed'] ?? 0
        ));
        $r = $matrix['resolution'];
        if (($r['declared_permissions'] ?? 0) > 0) {
            $this->line(sprintf(
                '  resolution vs target registry: %d/%d club slugs resolve directly, %d more via a parent-collapsed alt',
                $r['resolved'] ?? 0,
                $r['proposed'] ?? 0,
                $r['resolved_via_alt'] ?? 0
            ));
        }
        foreach ($matrix['roles'] as $role) {
            $this->line(sprintf(
                '  %-12s %s grants=%d club_permissions=%d',
                $role['role'],
                $role['super'] ? 'SUPER' : '     ',
                count($role['grants']),
                count($role['club']['permissions'])
            ));
        }

        return self::SUCCESS;
    }

    private function runAnatomy(): int
    {
        $probeDir = base_path('porting/probes');
        $specDir = base_path('porting/ui-spec');
        if (! is_dir($probeDir)) {
            $this->warn('No probes at porting/probes/ — capture the source pages first (the probe harness writes <slug>.json / <slug>-detail.json there).');
            return self::SUCCESS;
        }

        $lists = [];
        $details = [];
        foreach (glob($probeDir.'/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data)) {
                continue;
            }
            $base = basename($file, '.json');
            if (str_ends_with($base, '-detail')) {
                $details[substr($base, 0, -7)] = $data;
            } else {
                $lists[$base] = $data;
            }
        }

        File::ensureDirectoryExists($specDir);
        $slugs = array_values(array_unique([...array_keys($lists), ...array_keys($details)]));
        sort($slugs);
        $designTokens = null;
        $designForms = [];
        $designDivergences = [];
        $interactions = [];
        $inventoryFile = $probeDir.'/inventory.json';
        if (is_file($inventoryFile)) {
            $inventory = json_decode((string) file_get_contents($inventoryFile), true);
            if (is_array($inventory)) {
                $interactions = SourceAnatomyExtractor::extractInteractions($inventory);
            }
        }
        foreach ($slugs as $slug) {
            $pages = [];
            if (isset($lists[$slug])) {
                $pages['list'] = SourceAnatomyExtractor::extractList($lists[$slug], $slug)['pages']['list'];
                $design = SourceAnatomyExtractor::extractDesign($lists[$slug]);
                if ($design !== null) {
                    if (($design['forms'] ?? []) !== []) {
                        $designForms[$slug] = $design['forms'];
                    }
                    unset($design['forms']);
                    if ($designTokens === null) {
                        $designTokens = $design;
                    } else {
                        $divergent = SourceAnatomyExtractor::designDivergences($designTokens, $design);
                        if ($divergent !== []) {
                            $designDivergences[$slug] = $divergent;
                        }
                    }
                }
            }
            if (isset($details[$slug])) {
                $pages['detail'] = SourceAnatomyExtractor::extractDetail($details[$slug], $slug)['pages']['detail'];
            }
            $header = "# Source anatomy (slice:port --anatomy) — {$slug} · probed from the running source app
"
                ."# Evidence only: ports declare from this; decisions stay in the reviewed ui-spec.
";
            $anatomyPayload = ['slice' => $slug, 'pages' => $pages];
            $sliceInteractions = null;
            foreach ($interactions as $path => $entry) {
                if (basename((string) $path) === $slug && ($entry['type'] ?? '') === 'list') {
                    $sliceInteractions = $entry;
                    break;
                }
            }
            if ($sliceInteractions !== null) {
                $anatomyPayload['interactions'] = $sliceInteractions;
            }
            if (isset($lists[$slug])) {
                $hints = SourceAnatomyExtractor::declarationHints(
                    $lists[$slug],
                    $slug,
                    $sliceInteractions['workflow_actions'] ?? []
                );
                if ($hints !== []) {
                    $anatomyPayload['declaration_hints'] = $hints;
                }
            }
            file_put_contents(
                $specDir."/{$slug}.anatomy.ui.yaml",
                $header.Yaml::dump($anatomyPayload, 6, 2)
            );
            $list = $pages['list'] ?? null;
            $this->line(sprintf(
                '  %-16s list: %s · facets %d · columns %d%s',
                $slug,
                $list === null ? '—' : ($list['placement'] ?? 'unclassified'),
                $list === null ? 0 : count($list['facets']),
                $list === null ? 0 : count($list['columns']),
                isset($pages['detail']) ? ' · detail: tabs '.count($pages['detail']['tabs_observed']) : ''
            ));
        }
        $this->info('Anatomy specs written: '.count($slugs).' → porting/ui-spec/*.anatomy.ui.yaml');

        if ($designTokens !== null) {
            $designHeader = "# Source design capture (slice:port --anatomy) — Tier-5 evidence: computed design tokens + form presentations probed from the running source app\n"
                ."# The visual gate (SKILL 19B) asserts the port against these values; decisions stay in the reviewed ui-spec.\n";
            $designPayload = [
                'name' => 'source design capture',
                'tokens' => $designTokens,
                'forms' => $designForms,
                'divergences' => $designDivergences,
            ];
            if ($interactions !== []) {
                $designPayload['interactions'] = $interactions;
            }
            file_put_contents(
                $specDir.'/source.design.yaml',
                $designHeader.Yaml::dump($designPayload, 6, 2)
            );
            $this->info('Design spec written: porting/ui-spec/source.design.yaml · forms '.count($designForms).' · divergent pages '.count($designDivergences));
        }

        return self::SUCCESS;
    }
}
