<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\ArtefactRegenerator;

/**
 * Regenerate an existing slice's baked artefacts from its
 * Heart (Comparative Fix Programme tooling gap):
 * slice:regen-form covers the form views only, but the
 * service class (repeaterDefinitions / validation), the
 * primary model ($fillable / relations), the show blade
 * and — since the F12 fix (Day-2 drill) — the Contracts
 * (Form / Listing / Filter business objects, whose
 * property lists are the request-binding contract) also
 * bake manifest data in at generation time. Dry-run
 * by default: the slice is generated into a staging root
 * and the artefacts are compared, with a per-artefact
 * loss report naming anything the Heart cannot re-derive.
 * --write transplants the artefacts whose loss list is
 * empty (originals backed up first); an artefact with
 * detected losses is SKIPPED unless --force is given.
 */
class SliceRegenArtefactsCommand extends Command
{
    protected $signature = 'slice:regen-artefacts
                            {slice : Slice name (or use --path for a specific twin)}
                            {--path= : Explicit slice directory (disambiguates name twins)}
                            {--write : Apply the transplant (default is a dry-run report)}
                            {--force : Transplant even when an artefact\'s loss report is non-empty}
                            {--keep-staging : Keep the staging directory for a full diff}';

    protected $description = 'Regenerate an existing slice\'s baked artefacts (service, model, show blade, contracts) from its manifest';

    public function handle(SliceManager $manager, ArtefactRegenerator $regenerator): int
    {
        $manager->discover(true); // entry point: operate on the slices as they are on disk now, not a boot-time registry

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
            $report = $regenerator->diff($manifest, $staged, ArtefactRegenerator::allArtefacts());
            foreach ($report as $row) {
                $this->line(sprintf(
                    '%-36s %-14s current %s / staged %s (+%d/-%d)',
                    $row['file'],
                    $row['status'],
                    $row['current_lines'] ?? '—',
                    $row['staged_lines'] ?? '—',
                    $row['added_lines'],
                    $row['removed_lines']
                ));
                foreach ($row['lost'] as $lost) {
                    $this->warn("  would lose: {$lost} (present in the current {$row['file']}, not derivable from the Heart)");
                }
                if ($row['status'] === 'namespace-mismatch') {
                    $this->error("  staging could not reproduce this artefact's namespace — it will NOT be transplanted, even with --force. Check the Heart's namespace and tier declaration.");
                }
            }

            $changed = array_filter($report, fn ($row) => in_array($row['status'], ['changed', 'new'], true));
            if ($changed === []) {
                $mismatched = array_filter($report, fn ($row) => $row['status'] === 'namespace-mismatch');
                if ($mismatched !== []) {
                    $this->warn("{$manifest->name}: ".count($mismatched).' artefact(s) could not be staged faithfully (namespace mismatch) — nothing was written.');

                    return self::SUCCESS;
                }
                $this->info("{$manifest->name}: all artefacts already match the current generator output.");

                return self::SUCCESS;
            }

            if (! $this->option('write')) {
                $this->warn('Dry-run only — nothing was written. Re-run with --write to transplant (originals are backed up first).');
                if ($this->option('keep-staging')) {
                    $this->line("Staging kept at {$staged['staging']} — diff against {$manifest->path} to review every line.");
                }

                return self::SUCCESS;
            }

            $split = $regenerator->writableRows($report, (bool) $this->option('force'));
            foreach ($split['blocked'] as $row) {
                $this->warn("  skipped: {$row['file']} — transplanting would destroy ".count($row['lost']).' detected loss(es); declare them in the Heart or re-run with --force.');
            }

            if ($split['writable'] !== []) {
                $backupRoot = function_exists('storage_path')
                    ? storage_path('app/laraslice/regen-backups')
                    : sys_get_temp_dir().'/laraslice-regen-backups';
                $result = $regenerator->apply($manifest, $staged, $split['writable'], $backupRoot);
                Artisan::call('view:clear');
                $this->info("{$manifest->name}: ".count($result['written']).' artefact(s) regenerated ('.implode(', ', $result['written'])."). Originals backed up at {$result['backupDir']}");
            } else {
                $this->warn("{$manifest->name}: nothing transplanted — every changed artefact has detected losses (see above).");
            }

            return self::SUCCESS;
        } finally {
            if (! $this->option('keep-staging')) {
                $regenerator->discardStaging($staged['staging']);
            }
        }
    }
}
