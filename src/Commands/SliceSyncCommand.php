<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * Enterprise Cross-Environment Slice State & Migration Synchronizer.
 *
 * Synchronizes declarative slice manifests (slice.json), version histories,
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
        $targetSlice = $this->argument('slice');
        $runMigrations = (bool) $this->option('migrate');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $manager->clearCache();
        $manager->discover();

        $allSlices = $manager->getAllSlices();
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

        $this->info("=== LaraSlice Enterprise State & Schema Synchronizer ===");
        if ($dryRun) {
            $this->warn('[DRY-RUN MODE] Inspecting manifest versions and pending migrations without executing.');
        }

        $tableRows = [];
        $totalMigrated = 0;

        foreach ($slicesToSync as $slice) {
            $manifestPath = $slice->manifestPath ?? ($slice->path.'/slice.json');
            $manifestData = [];
            if (File::exists($manifestPath)) {
                $manifestData = json_decode(File::get($manifestPath), true) ?: [];
            } elseif (File::exists($slice->path.'/slice.yaml')) {
                try {
                    $manifestData = \Symfony\Component\Yaml\Yaml::parse(File::get($slice->path.'/slice.yaml')) ?: [];
                } catch (\Throwable $e) {}
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
                    ? \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->toArray()
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

            // Run pending migrations if requested
            if (! $dryRun && count($pendingMigrations) > 0 && ($runMigrations || $this->confirm("Run ".count($pendingMigrations)." pending migration(s) for slice [{$slice->name}]?", true))) {
                $relMigrationPath = Str::after(str_replace('\\', '/', $migrationsDir), str_replace('\\', '/', base_path()).'/');
                Artisan::call('migrate', [
                    '--path' => $relMigrationPath,
                    '--force' => $force || app()->environment('production'),
                ], $this->output);
                $totalMigrated += count($pendingMigrations);
                $migStatus = '<info>Synced ('.count($pendingMigrations).' applied)</info>';
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
        $manager->discover();

        $this->info('All slice manifests, permissions, and routes are synchronized with environment.');
        return self::SUCCESS;
    }
}
