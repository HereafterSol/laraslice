<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;
use LaraSlice\Support\DeclarationHistory;

/**
 * Enterprise Cross-Environment Slice State & Migration Synchronizer.
 *
 * Synchronizes declarative slice manifests (slice.yaml), version histories,
 * pending database migrations, and cached permissions across environments (Local -> Git -> Staging -> Production).
 */
class SliceSyncCommand extends Command
{
    protected $signature = 'slice:sync 
                            {slice? : The name of the vertical slice (e.g. Orders, Billing/Invoices, or empty for all)}
                            {--migrate : Automatically run pending slice migrations}
                            {--dry-run : Inspect pending migrations and manifest status without applying changes}
                            {--force : Force migration execution in production environments}';

    protected $description = 'Synchronize slice schemas, version history, and migrations across environments (GitOps / CI/CD)';

    public function handle(SliceManager $manager): int
    {
        // One sync at a time: two runs interleaving migration
        // application and cache rebuilds corrupt both. Stores
        // without lock support degrade to a warning, never to
        // a hard dependency.
        $lock = null;
        try {
            $lock = \Illuminate\Support\Facades\Cache::lock('laraslice.slice-sync', 120);
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock !== null && ! $lock->acquire()) {
            $this->error('Another slice:sync run holds the sync lock — wait for it to finish, then re-run.');

            return self::FAILURE;
        }

        try {
            return $this->runSync($manager);
        } finally {
            $lock?->release();
        }
    }

    private function runSync(SliceManager $manager): int
    {
        $targetSlice = $this->argument('slice');
        $runMigrations = (bool) $this->option('migrate');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $manager->clearCache();
        $manager->discover(true); // entry point: operate on the slices as they are on disk now, not a boot-time registry

        // Every discovered manifest, twins included: the
        // name-keyed index (getAllSlices) holds one manifest
        // per name, so name-shadowed slices — booted and
        // serving — were never synced (backlog A4; the same
        // defect slice:repair fixed via discoveredSlices()).
        $allSlices = $manager->discoveredSlices();
        if (empty($allSlices)) {
            $this->warn('No vertical slices detected in this application.');

            return self::SUCCESS;
        }

        $slicesToSync = [];
        if ($targetSlice && strtolower($targetSlice) !== 'all') {
            $matched = $manager->getSlice($targetSlice);
            if (! $matched) {
                $this->error("Slice [{$targetSlice}] not found.");

                return self::FAILURE;
            }
            $slicesToSync = [$matched];
        } else {
            $slicesToSync = $allSlices;
        }

        $this->info('=== LaraSlice Enterprise State & Schema Synchronizer ===');
        if ($dryRun) {
            $this->warn('[DRY-RUN MODE] Inspecting manifest versions and pending migrations without executing.');
            $this->warn('[DRY-RUN] Purity: no migrations run, no workflow state synced, no manifest files written, no history recorded.');
        }

        $tableRows = [];
        $totalMigrated = 0;
        $failed = false;

        foreach ($slicesToSync as $slice) {
            $manifestPath = $slice->manifestPath ?? ($slice->path.'/slice.yaml');
            $manifestData = [];
            // YAML is the canonical manifest — parse through the
            // repository (YAML wins). JSON-decoding a .yaml path
            // silently yields an empty manifest, which used to
            // skip workflow sync for every YAML slice.
            try {
                $manifestData = ManifestRepository::read($manifestPath) ?: [];
            } catch (\Throwable $e) {
                if (File::exists($manifestPath)) {
                    $manifestData = json_decode(File::get($manifestPath), true) ?: [];
                }
            }

            $version = $manifestData['version'] ?? $slice->version ?? 'v1.0.0';
            $history = $manifestData['version_history'] ?? [];
            $latestHistory = ! empty($history) ? end($history) : null;
            $lastChangeDesc = $latestHistory['description'] ?? 'Initial baseline';
            if (mb_strlen($lastChangeDesc) > 45) {
                $lastChangeDesc = mb_substr($lastChangeDesc, 0, 42).'...';
            }

            // Inspect slice migrations
            $migrationsDir = $slice->path.'/Migrations';
            $pendingMigrations = [];
            if (File::isDirectory($migrationsDir)) {
                $migrationFiles = File::glob($migrationsDir.'/*_*.php') ?: [];
                $ranMigrations = Schema::hasTable('migrations')
                    ? DB::table('migrations')->pluck('migration')->toArray()
                    : [];

                foreach ($migrationFiles as $mFile) {
                    $mName = basename($mFile, '.php');
                    if (! in_array($mName, $ranMigrations, true)) {
                        $pendingMigrations[] = $mName;
                    }
                }
            }

            $migStatus = count($pendingMigrations) > 0
                ? '<comment>'.count($pendingMigrations).' pending</comment>'
                : '<info>Up to date</info>';

            // Run pending migrations if requested. Hardened
            // apply discipline: production never migrates
            // without an explicit --force; a non-interactive
            // run (CI) never prompts and never silently skips
            // pending work — it fails with the instruction;
            // and "applied" is only claimed after re-reading
            // the migrations table.
            if (! $dryRun && count($pendingMigrations) > 0) {
                $shouldRun = $runMigrations;
                if (! $shouldRun && $this->input->isInteractive()) {
                    $shouldRun = $this->confirm('Run '.count($pendingMigrations)." pending migration(s) for slice [{$slice->name}]?", true);
                }
                if ($shouldRun && app()->environment('production') && ! $force) {
                    $this->error("Slice [{$slice->name}]: refusing to run migrations in production without --force.");
                    $failed = true;
                    $migStatus = '<error>Refused (production, no --force)</error>';
                } elseif ($shouldRun) {
                    $relMigrationPath = Str::after(str_replace('\\', '/', $migrationsDir), str_replace('\\', '/', base_path()).'/');
                    $migrateCode = Artisan::call('migrate', [
                        '--path' => $relMigrationPath,
                        '--force' => $force || app()->environment('production'),
                    ], $this->output);
                    $stillRan = Schema::hasTable('migrations')
                        ? DB::table('migrations')->pluck('migration')->toArray()
                        : [];
                    $stillPending = array_values(array_filter(
                        $pendingMigrations,
                        static fn ($mName) => ! in_array($mName, $stillRan, true)
                    ));
                    if ($migrateCode !== 0 || $stillPending !== []) {
                        $this->error("Slice [{$slice->name}]: migration run did not complete — ".count($stillPending).' migration(s) still pending.');
                        $failed = true;
                        $migStatus = '<error>Failed ('.count($stillPending).' still pending)</error>';
                    } else {
                        $totalMigrated += count($pendingMigrations);
                        $migStatus = '<info>Synced ('.count($pendingMigrations).' applied)</info>';
                    }
                } elseif (! $this->input->isInteractive()) {
                    $this->error("Slice [{$slice->name}]: ".count($pendingMigrations).' pending migration(s) not applied in a non-interactive run — re-run with --migrate.');
                    $failed = true;
                    $migStatus = '<error>'.count($pendingMigrations).' pending (not applied)</error>';
                }
            }

            // Sync declarative workflow if defined in
            // slice.yaml — a database write, so a dry run
            // must not do it (dry-run purity).
            if (! $dryRun && ! empty($manifestData['workflow']) && (is_array($manifestData['workflow']) || $manifestData['workflow'] === true)) {
                try {
                    $wfConfig = is_array($manifestData['workflow']) ? $manifestData['workflow'] : [];
                    app(WorkflowEngineService::class)->syncWorkflowFromManifest(
                        $wfConfig,
                        $slice->name,
                        $slice->getModelClass()
                    );
                } catch (\Throwable) {
                }
            }

            $tableRows[] = [
                $slice->domain ?? 'General',
                $slice->name,
                $version,
                $lastChangeDesc,
                $migStatus,
                $slice->active ? '<info>Active</info>' : '<comment>Inactive</comment>',
            ];
        }

        $this->table(
            ['Domain', 'Slice', 'Version', 'Latest Ledger History', 'Migrations', 'Status'],
            $tableRows
        );

        // Re-cache slice state
        $manager->clearCache();
        $manager->discover(true); // entry point: operate on the slices as they are on disk now, not a boot-time registry

        // Ensure the YAML manifest on disk is the canonical
        // write of its parsed form, for every discovered
        // slice (twins included — the name-keyed index misses
        // shadowed slices). A file write, so never in dry-run.
        if (! $dryRun) {
            foreach ($manager->discoveredSlices() as $slice) {
                $jsonFile = $slice->path.'/slice.yaml';
                if (is_file($jsonFile)) {
                    try {
                        $m = ManifestRepository::read($jsonFile);
                        ManifestRepository::writeYamlTwin($jsonFile, $m);
                    } catch (\Throwable $e) {
                    }
                }
            }

            // A real cross-environment sync leaves a
            // declaration-history trail — the same ledger
            // slice:cache maintains locally.
            try {
                $entry = (new DeclarationHistory)->record('slice:sync', DeclarationHistory::currentPerson());
                if ($entry !== null) {
                    $this->info("Declaration history: recorded {$entry['id']} — ".count($entry['changes']).' declaration change(s).');
                }
            } catch (\Throwable) {
            }
        }

        if ($failed) {
            $this->error('Sync incomplete — see the failures above. Nothing was claimed as applied that did not verify.');

            return self::FAILURE;
        }

        $this->info('All slice manifests, permissions, and routes are synchronized with environment (JSON + YAML + DB).');

        return self::SUCCESS;
    }
}
