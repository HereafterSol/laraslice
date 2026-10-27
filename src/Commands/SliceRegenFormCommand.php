<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\FormRegenerator;

/**
 * Regenerate an existing slice's form views from its Heart
 * (backlog E1). Dry-run by default: the slice is generated
 * into a staging root and the form views are compared, so
 * hand edits show up in the report before anything is
 * overwritten. --write transplants form/create/edit with a
 * timestamped backup of the originals.
 */
class SliceRegenFormCommand extends Command
{
    protected $signature = 'slice:regen-form
                            {slice : Slice name (or use --path for a specific twin)}
                            {--path= : Explicit slice directory (disambiguates name twins)}
                            {--write : Apply the transplant (default is a dry-run report)}
                            {--keep-staging : Keep the staging directory for a full diff}';

    protected $description = 'Regenerate an existing slice\'s form views (form/create/edit) from its manifest';

    public function handle(SliceManager $manager, FormRegenerator $regenerator): int
    {
        $manager->discover();

        $manifest = null;
        if ($path = $this->option('path')) {
            $wanted = realpath((string) $path);
            foreach ($manager->discoveredSlices() as $candidate) {
                if (realpath($candidate->path) === $wanted) {
                    $manifest = $candidate;
                    break;
                }
            }
            if ($manifest === null) {
                $this->error("No discovered slice at path [{$path}].");

                return self::FAILURE;
            }
        } else {
            $manifest = $manager->getSlice((string) $this->argument('slice'));
            if ($manifest === null) {
                $this->error("Slice [{$this->argument('slice')}] not found.");

                return self::FAILURE;
            }
        }

        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged['files']);
            foreach ($report as $row) {
                $this->line(sprintf(
                    '%-18s %-14s current %s / staged %s',
                    $row['view'],
                    $row['status'],
                    $row['current_lines'] ?? '—',
                    $row['staged_lines'] ?? '—'
                ));
                foreach ($row['lost'] ?? [] as $lost) {
                    $this->warn("  would lose: {$lost} (present in the current {$row['view']}, not derivable from the Heart)");
                }
            }

            $changed = array_filter($report, fn ($row) => in_array($row['status'], ['changed', 'new'], true));
            if ($changed === []) {
                $this->info("{$manifest->name}: form views already match the current generator output.");

                return self::SUCCESS;
            }

            if (! $this->option('write')) {
                $this->warn('Dry-run only — nothing was written. Re-run with --write to transplant (originals are backed up first).');
                if ($this->option('keep-staging')) {
                    $this->line("Staging kept at {$staged['staging']} — diff against {$manifest->path}/Resources/views to review every line.");
                }

                return self::SUCCESS;
            }

            $backupRoot = function_exists('storage_path')
                ? storage_path('app/laraslice/regen-backups')
                : sys_get_temp_dir().'/laraslice-regen-backups';
            $backupDir = $regenerator->apply($manifest, $staged['files'], $backupRoot);
            Artisan::call('view:clear');
            $this->info("{$manifest->name}: form views regenerated. Originals backed up at {$backupDir}");

            return self::SUCCESS;
        } finally {
            if (! $this->option('keep-staging')) {
                $regenerator->discardStaging($staged['staging']);
            }
        }
    }
}
