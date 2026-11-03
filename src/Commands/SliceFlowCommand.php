<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Support\AppFlow;
use LaraSlice\Support\SystemMind;

/**
 * Generate the App Flow artefact (the pre-build dossier's Flow
 * slot) from declarations: journey lanes from the Mind, the
 * navigation map from the slices' navigation declarations.
 * Founders think in screens — this renders the flow instead of
 * asking anyone to draw it. Writes one file; never edits the
 * Mind or any manifest.
 */
final class SliceFlowCommand extends Command
{
    protected $signature = 'slice:flow {--format=html : Output format: html (standalone page) or md (Mermaid)} {--output= : Output path (default: porting/app-flow.html or porting/app-flow.md)}';

    protected $description = 'Generate the App Flow artefact from the Mind journeys + slice navigation declarations.';

    public function handle(SliceManager $manager): int
    {
        $manager->discover(true); // entry point: operate on the slices as they are on disk now

        $pages = AppFlow::pagesFromSlices($manager->getSlices());

        $mind = SystemMind::load();
        if ($mind === null) {
            $this->warn('No Mind found (porting/system.yaml) — the flow will carry the navigation map only. Draft one with slice:plan:draft.');
        }

        $flow = AppFlow::build($mind, $pages);
        $format = strtolower((string) $this->option('format')) === 'md' ? 'md' : 'html';
        $output = (string) ($this->option('output') ?: base_path('porting/app-flow.'.$format));
        $content = $format === 'md'
            ? "# App Flow — {$flow['system']}\n\n```mermaid\n".AppFlow::renderMermaid($flow)."```\n"
            : AppFlow::renderHtml($flow);

        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }
        file_put_contents($output, $content);
        $this->info("App Flow written: {$output} (".count($flow['journeys']).' journeys, '.count($pages).' pages in '.count($flow['sections']).' sections)');

        return self::SUCCESS;
    }
}
