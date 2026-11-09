<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Generator\SliceAbsorber;
use Symfony\Component\Yaml\Yaml;

/**
 * Absorb hand knowledge from a slice's generated artefacts
 * back into its Heart (the reverse of slice:regen-artefacts).
 *
 * Regen names everything the Heart cannot re-derive; those
 * losses used to be a dead end — the knowledge (hand-added
 * hub relations, a hand-pointed table, mass-assigned
 * columns) lived only in generated PHP, so the models
 * stayed loss-blocked forever. This command classifies
 * every loss as declarable (a concrete Heart proposal is
 * printed), vocabulary-gap (real knowledge today's schema
 * cannot express — reported with the addition that would
 * express it), or hand-owned (real code; stays gated).
 *
 * Dry-run by default. --write writes ONLY the Heart
 * (slice.yaml), after a timestamped backup; generated
 * artefacts are never touched — a following
 * slice:regen-artefacts is what re-syncs them, now without
 * losses. The framework's own core slices are hand-built
 * by design and are never absorbed.
 */
class SliceAbsorbCommand extends Command
{
    protected $signature = 'slice:absorb
                            {slice? : Slice name (omit with --all, or use --path for a specific twin)}
                            {--path= : Explicit slice directory (disambiguates name twins)}
                            {--all : Analyse every discovered slice}
                            {--write : Write the declarable proposals into the Hearts (default is a dry-run report)}';

    protected $description = 'Absorb hand knowledge from a slice\'s generated artefacts back into its Heart (reverse of slice:regen-artefacts)';

    public function handle(SliceManager $manager, SliceAbsorber $absorber): int
    {
        $manager->discover(true); // operate on the slices as they are on disk now

        $targets = $this->targets($manager);
        if ($targets === null) {
            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $backupRoot = function_exists('storage_path')
            ? storage_path('app/laraslice/absorb-backups')
            : sys_get_temp_dir().'/laraslice-absorb-backups';

        $totals = [SliceAbsorber::CLASS_DECLARABLE => 0, SliceAbsorber::CLASS_GAP => 0, SliceAbsorber::CLASS_HAND => 0];
        $written = 0;
        $refused = 0;

        foreach ($targets as $manifest) {
            $analysis = $absorber->analyse($manifest);
            foreach ($analysis['counts'] as $class => $count) {
                $totals[$class] += $count;
            }
            $this->reportSlice($analysis, $absorber);

            if (! $write || $analysis['excluded']) {
                continue;
            }
            $proposals = $absorber->proposals($analysis);
            if ($proposals === []) {
                continue;
            }
            $result = $absorber->applyHeart($manifest, $analysis, $backupRoot);
            if ($result['written']) {
                $written++;
                $this->info("  → Heart updated (".count($result['applied']).' proposal(s)); original backed up at '.$result['backupDir']);
                // Self-verify: the absorbed items must be
                // gone from a fresh analysis.
                $after = $absorber->analyse(new SliceManifest($manifest->path.'/slice.yaml'));
                $this->line('  → after: '.$after['counts'][SliceAbsorber::CLASS_DECLARABLE].' declarable remaining, '
                    .$after['counts'][SliceAbsorber::CLASS_GAP].' vocabulary-gap, '
                    .$after['counts'][SliceAbsorber::CLASS_HAND].' hand-owned');
            } else {
                $refused++;
                $this->error('  → Heart write REFUSED: '.$result['refusal']);
            }
        }

        $this->line('');
        $this->info(sprintf(
            '%d slice(s) analysed: %d declarable, %d vocabulary-gap, %d hand-owned.%s',
            count($targets),
            $totals[SliceAbsorber::CLASS_DECLARABLE],
            $totals[SliceAbsorber::CLASS_GAP],
            $totals[SliceAbsorber::CLASS_HAND],
            $write ? " {$written} Heart(s) updated".($refused > 0 ? ", {$refused} refused" : '').'.' : ' Dry-run only — nothing was written. Re-run with --write to update the Hearts (originals are backed up first).'
        ));

        return $refused > 0 && ! $this->option('all') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return ?array<int, SliceManifest>
     */
    private function targets(SliceManager $manager): ?array
    {
        if ($this->option('all')) {
            $byPath = [];
            foreach ($manager->discoveredSlices() as $manifest) {
                $real = realpath($manifest->path);
                if ($real !== false) {
                    $byPath[$real] = $manifest;
                }
            }

            return array_values($byPath);
        }
        if ($path = $this->option('path')) {
            $wanted = realpath((string) $path);
            foreach ($manager->discoveredSlices() as $candidate) {
                if (realpath($candidate->path) === $wanted) {
                    return [$candidate];
                }
            }
            $this->error("No discovered slice at path [{$path}].");

            return null;
        }
        $name = (string) $this->argument('slice');
        if ($name === '') {
            $this->error('Give a slice name, --path, or --all.');

            return null;
        }
        $manifest = $manager->getSlice($name);
        if ($manifest === null) {
            $this->error("Slice [{$name}] not found.");

            return null;
        }

        return [$manifest];
    }

    /** @param  array<string, mixed>  $analysis */
    private function reportSlice(array $analysis, SliceAbsorber $absorber): void
    {
        if ($analysis['excluded']) {
            $this->line("{$analysis['slice']}: EXCLUDED — {$analysis['exclusion_reason']}");

            return;
        }
        if ($analysis['items'] === []) {
            $this->line("{$analysis['slice']}: no losses — the Heart already re-derives every artefact.");

            return;
        }
        $counts = $analysis['counts'];
        $this->line(sprintf(
            '%s: %d loss(es) — %d declarable, %d vocabulary-gap, %d hand-owned',
            $analysis['slice'],
            count($analysis['items']),
            $counts[SliceAbsorber::CLASS_DECLARABLE],
            $counts[SliceAbsorber::CLASS_GAP],
            $counts[SliceAbsorber::CLASS_HAND]
        ));
        foreach ($analysis['items'] as $item) {
            $label = match ($item['class']) {
                SliceAbsorber::CLASS_DECLARABLE => 'declarable    ',
                SliceAbsorber::CLASS_GAP => 'vocabulary-gap',
                default => 'hand-owned    ',
            };
            $this->line("  [{$label}] {$item['artefact']} {$item['loss']} — {$item['reason']}");
        }

        $proposals = $absorber->proposals($analysis);
        if ($proposals !== []) {
            $this->line('  Proposed Heart changes:');
            foreach ($proposals as $proposal) {
                foreach ($this->proposalLines($proposal) as $line) {
                    $this->line('  + '.$line);
                }
            }
        }
    }

    /** @return array<int, string> */
    private function proposalLines(array $proposal): array
    {
        $fragment = match ($proposal['kind']) {
            'relation' => ['models[0].relations' => [$proposal['relation']]],
            'field' => ['fields' => [$proposal['field']['name'] => $proposal['field']]],
            'field-encrypted' => ['fields.'.$proposal['column'] => ['encrypted' => true]],
            'field-cast' => ['fields.'.$proposal['column'] => ['cast' => $proposal['cast']]],
            'table' => ['models[0].table' => $proposal['table']],
            'multitenant' => ['multitenant' => true],
            default => [$proposal['kind'] => $proposal],
        };
        $dump = Yaml::dump($fragment, 5, 2);

        return explode("\n", rtrim($dump, "\n"));
    }
}
