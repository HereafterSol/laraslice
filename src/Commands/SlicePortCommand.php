<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LaraSlice\Porting\SlicePortService;

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
                            {--source-connection= : Legacy DB connection name from config/database.php (overrides --source-* options)}
                            {--source-host= : Legacy database host (default: 127.0.0.1)}
                            {--source-port= : Legacy database port (default: 3306)}
                            {--source-database= : Legacy database name}
                            {--source-username= : Legacy database username}
                            {--source-password= : Legacy database password}
                            {--data-source= : Data to port: real (live DB, default), seeders (source seed files), both, none}';

    protected $description = 'Reverse-engineer and port a legacy Laravel application into domain-bounded LaraSlice vertical slices';

    public function handle(SlicePortService $portService): int
    {
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
}
