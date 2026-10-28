<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Porting\SurfaceDerivationPipeline;

class SliceDeriveSurfaceCommand extends Command
{
    protected $signature = 'slice:derive-surface
                            {slice : The name of the slice to derive declarations for}
                            {--source= : Path to benchmark/source ERP root}
                            {--probe= : Optional path to live DOM probe JSON}
                            {--apply : Write derived declarations directly to slice.yaml}';

    protected $description = 'Derive declarative Slice UI (columns, formatters, hub tabs, stats) from source DataTable and Blade views';

    public function handle(SliceManager $manager, SurfaceDerivationPipeline $pipeline): int
    {
        $manager->discover(true);
        $sliceName = $this->argument('slice');
        $slice = $manager->getSlice($sliceName);

        if ($slice === null) {
            $this->error("Slice [{$sliceName}] not found.");

            return self::FAILURE;
        }

        $sourcePath = $this->option('source') ?: base_path('../erp');
        if (! is_dir($sourcePath)) {
            $this->error("Source path [{$sourcePath}] does not exist.");

            return self::FAILURE;
        }

        $probePath = $this->option('probe');
        $probe = null;
        if ($probePath && is_file($probePath)) {
            $probe = json_decode((string) file_get_contents($probePath), true);
        } else {
            // Auto-detect probe from standard porting/probes folder
            $candidates = [
                base_path('porting/probes/'.strtolower($sliceName).'.json'),
                base_path('porting/probes/'.strtolower(str_replace('Details', '', $sliceName)).'.json'),
                base_path('porting/probes/'.strtolower(str_replace('Details', 's', $sliceName)).'.json'),
            ];
            foreach ($candidates as $cand) {
                if (is_file($cand)) {
                    $probe = json_decode((string) file_get_contents($cand), true);
                    $this->line("<fg=gray>Auto-loaded probe from {$cand}</>");
                    break;
                }
            }
        }

        $existingManifest = $slice->manifestData;
        $derived = $pipeline->derive($sourcePath, $sliceName, $probe, $existingManifest);

        $this->info("Derived surface declarations for [{$sliceName}]:");
        $cols = $derived['ui']['directory']['columns'] ?? [];
        $this->line('<fg=cyan>Visible Columns ('.count($cols).'):</>');
        foreach ($cols as $c) {
            $this->line(sprintf(
                '  - <fg=green>%-15s</> label="%-16s" formatter=%-15s inline=%s',
                $c['handle'],
                $c['label'] ?? '',
                $c['formatter'] ?? 'text',
                ($c['inline_edit'] ?? false) ? '<fg=yellow>yes</>' : 'no'
            ));
        }

        $tabs = $derived['ui']['show']['tabs'] ?? [];
        $this->line('<fg=cyan>Hub Tabs ('.count($tabs).'):</>');
        foreach ($tabs as $t) {
            $this->line(sprintf('  - <fg=green>%-15s</> label="%-16s" type=%s', $t['handle'], $t['label'], $t['type']));
        }

        $stats = $derived['ui']['show']['stats'] ?? [];
        $this->line('<fg=cyan>KPI Stats ('.count($stats).'):</>');
        foreach ($stats as $s) {
            $this->line(sprintf('  - <fg=green>%-15s</> label="%-16s" aggregate=%s relation=%s', $s['handle'], $s['label'], $s['aggregate'] ?? 'count', $s['relation'] ?? ''));
        }

        if ($this->option('apply')) {
            $manifestPath = $slice->manifestPath;
            if (str_ends_with($manifestPath, '.json')) {
                $yamlPath = substr($manifestPath, 0, -5).'.yaml';
            } else {
                $yamlPath = $manifestPath;
            }

            ManifestRepository::writeBoth($manifestPath, $derived);
            $this->info("<fg=green>Applied derived declarations to {$yamlPath}</>");
        } else {
            $this->comment('Dry-run complete. Run with --apply to write to slice.yaml.');
        }

        return self::SUCCESS;
    }
}
