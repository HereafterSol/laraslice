<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Porting\ConformanceGate;
use LaraSlice\Porting\DesignLint;
use LaraSlice\Porting\SourceAnatomyExtractor;
use Symfony\Component\Yaml\Yaml;

class SliceConformanceCommand extends Command
{
    protected $signature = 'slice:conformance {--lint : Also run the design lint (DESIGN.md contrast pairs + blade colour/spacing patterns)} {--visual : Also run the Tier-5 visual gate (source.design.yaml tokens vs port design captures, plus form-presentation findings)}';

    protected $description = 'Fidelity conformance gate: compare every anatomy spec (source evidence) against the ported Hearts and judge all differences against the deviation register (porting/deviations.yaml). Exits 1 on UNRECORDED deviations.';

    public function handle(): int
    {
        $specDir = base_path('porting/ui-spec');
        $specFiles = is_dir($specDir) ? (glob($specDir.'/*.anatomy.ui.yaml') ?: []) : [];
        if ($specFiles === []) {
            $this->warn('No anatomy specs at porting/ui-spec/*.anatomy.ui.yaml — run slice:port --anatomy first.');

            return self::SUCCESS;
        }

        // Hearts by handle.
        $hearts = [];
        $slicesRoot = base_path('app/Slices');
        if (is_dir($slicesRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($slicesRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === 'slice.yaml') {
                    try {
                        $parsed = Yaml::parseFile($file->getPathname());
                        if (is_array($parsed)) {
                            // Hearts are heterogeneous: match on
                            // handle, else name/title, else the
                            // directory name — normalized.
                            $keys = [];
                            foreach (['handle', 'name', 'title'] as $field) {
                                if (isset($parsed[$field])) {
                                    $keys[] = (string) $parsed[$field];
                                }
                            }
                            $keys[] = basename(dirname($file->getPathname()));
                            $ui = is_array($parsed['ui'] ?? null) ? $parsed['ui'] : [];
                            foreach ($keys as $key) {
                                $norm = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';
                                if ($norm === '') {
                                    continue;
                                }
                                // Name collisions exist (twin slices,
                                // demo stubs): keep the RICHEST Heart
                                // per key — the developed port, not
                                // the stub — and remember its path.
                                $richness = count($ui['directory'] ?? []) + count($ui['pages'] ?? []) + count($ui['show'] ?? []);
                                if (! isset($hearts[$norm]) || $richness > $hearts[$norm]['richness']) {
                                    $hearts[$norm] = ['ui' => $ui, 'richness' => $richness, 'path' => $file->getPathname()];
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // unparsable Heart: drift --structure owns that report
                    }
                }
            }
        }

        $register = [];
        $registerPath = base_path('porting/deviations.yaml');
        if (is_file($registerPath)) {
            try {
                $parsed = Yaml::parseFile($registerPath);
                $register = is_array($parsed['deviations'] ?? null) ? $parsed['deviations'] : [];
            } catch (\Throwable) {
                $this->error('porting/deviations.yaml is unparsable — the register must stay readable.');

                return self::FAILURE;
            }
        }

        // Gather every page's candidates first, then evaluate
        // ONCE against the whole register — staleness is a
        // global property (an entry is stale only if NO page
        // produces its deviation any more).
        $perSlug = [];
        $allCandidates = [];
        $pages = 0;
        foreach ($specFiles as $specFile) {
            try {
                $spec = Yaml::parseFile($specFile);
            } catch (\Throwable) {
                continue;
            }
            if (! is_array($spec) || ! isset($spec['slice'])) {
                continue;
            }
            $slug = (string) $spec['slice'];
            if (! isset($hearts[$slug])) {
                $this->line("  {$slug}: no ported slice with this handle — skipped");

                continue;
            }
            $pages++;
            $candidates = ConformanceGate::compare($spec['pages'] ?? [], $hearts[$slug]['ui'], $slug);
            $perSlug[$slug] = ['candidates' => $candidates, 'path' => $hearts[$slug]['path']];
            $allCandidates = [...$allCandidates, ...$candidates];
        }

        // Tier-5 visual gate (P4): token assertions against
        // the port's measured design + form-presentation
        // findings, folded into the same register semantics.
        $visualDiffs = [];
        if ($this->option('visual')) {
            $designSpecPath = $specDir.'/source.design.yaml';
            $designSpec = is_file($designSpecPath) ? Yaml::parseFile($designSpecPath) : null;
            if (! is_array($designSpec) || ! is_array($designSpec['tokens'] ?? null)) {
                $this->warn('Visual gate: no source.design.yaml at porting/ui-spec/ — run slice:port --anatomy with design captures first; visual assertions skipped.');
            } else {
                $sourceTokens = $designSpec['tokens'];
                $capturedForms = is_array($designSpec['forms'] ?? null) ? $designSpec['forms'] : [];
                foreach ($perSlug as $slug => $info) {
                    $extra = [];
                    $portProbePath = base_path("porting/probes/{$slug}-port.json");
                    $portProbe = is_file($portProbePath) ? json_decode((string) file_get_contents($portProbePath), true) : null;
                    $portDesign = is_array($portProbe) ? SourceAnatomyExtractor::extractDesign($portProbe) : null;
                    if ($portDesign === null) {
                        $extra[] = [
                            'id' => "{$slug}.visual",
                            'kind' => 'visual',
                            'source' => 'design capture in source.design.yaml',
                            'port' => 'not captured — run probes/capture_port_design.py',
                        ];
                    } else {
                        // extractDesign returns the token tree at
                        // the top level (forms/decision are its
                        // siblings, not a 'tokens' wrapper).
                        $portTokens = $portDesign;
                        unset($portTokens['forms'], $portTokens['decision'], $portTokens['name']);
                        $extra = [...$extra, ...ConformanceGate::visualCandidates($sourceTokens, $portTokens, $slug)];
                        $visualDiffs[$slug] = ConformanceGate::visualDiffs($sourceTokens, $portTokens);
                    }
                    // Form presentations: Heart declaration vs
                    // captured evidence (P2 lint, gated here).
                    $declared = [];
                    $heartUi = $hearts[$slug]['ui'] ?? [];
                    if (is_array($heartUi['presentation'] ?? null)) {
                        $declared = $heartUi['presentation'];
                    }
                    foreach (DesignLint::presentationFindings($slug, $capturedForms[$slug] ?? [], $declared) as $finding) {
                        if ($finding['kind'] === 'lint.presentation-mismatch') {
                            $extra[] = [
                                'id' => "{$slug}.presentation-{$finding['verb']}",
                                'kind' => 'presentation',
                                'source' => "{$finding['verb']} renders a {$finding['source']}",
                                'port' => "declares {$finding['port']}",
                            ];
                        } else {
                            $this->warn('  '.$finding['message']);
                        }
                    }
                    if ($extra !== []) {
                        $perSlug[$slug]['candidates'] = [...$info['candidates'], ...$extra];
                        $allCandidates = [...$allCandidates, ...$extra];
                    }
                }
            }
        }

        $result = ConformanceGate::evaluate($allCandidates, $register);
        $allErrors = $result['errors'];
        $allOpen = $result['open'];
        $allApproved = $result['approved'];
        $allStale = $result['stale'];

        $conforming = 0;
        foreach ($perSlug as $slug => $info) {
            $ids = array_column($info['candidates'], 'id');
            $hasError = false;
            $hasOpen = false;
            foreach ($ids as $id) {
                foreach ($allErrors as $line) {
                    if (str_starts_with($line, $id.':')) {
                        $hasError = true;
                    }
                }
                foreach ($allOpen as $line) {
                    if (str_starts_with($line, $id.':')) {
                        $hasOpen = true;
                    }
                }
            }
            if (! $hasError && ! $hasOpen) {
                $conforming++;
            }
            $state = $hasError ? 'UNRECORDED' : ($hasOpen ? 'open deviations' : 'conforming');
            $matched = str_replace(base_path().'/', '', $info['path']);
            $this->line("  {$slug}: {$state} (".count($ids)." deviation candidate(s)) [{$matched}]");
            foreach ($visualDiffs[$slug] ?? [] as $diff) {
                $this->line("    visual {$diff['key']}: source {$diff['source']} vs port {$diff['port']}");
            }
        }

        foreach ($allErrors as $error) {
            $this->error($error);
        }
        foreach ($allOpen as $open) {
            $this->warn($open);
        }
        foreach ($allApproved as $approved) {
            $this->line('  ✓ '.$approved);
        }
        foreach ($allStale as $stale) {
            $this->warn('stale: '.$stale);
        }

        $pct = $pages > 0 ? round(100 * $conforming / $pages) : 100;
        $this->info("Conformance: {$conforming}/{$pages} pages at 100% (fixed or approved-and-recorded) — {$pct}%. "
            .count($allErrors).' unrecorded, '.count($allOpen).' open, '.count($allApproved).' approved deviation(s).');

        if ($this->option('visual')) {
            $visualCount = static fn (array $lines) => count(array_filter(
                $lines,
                static fn (string $line) => preg_match('/^[a-z0-9-]+\.visual:/', $line) === 1
            ));
            $this->info('Visual gate: '.$visualCount($allErrors).' unrecorded, '.$visualCount($allOpen).' open, '
                .$visualCount($allApproved)." approved visual deviation(s) across {$pages} pages — a page with none is visually conforming.");
        }

        $failed = $allErrors !== [];
        if ($this->option('lint')) {
            $failed = $this->runLint() || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Design lint: DESIGN.md contrast pairs (app root first,
     * else the kit's own DESIGN.md in the package) + pattern
     * checks over every generated index blade. Returns true
     * when any error-severity finding exists.
     */
    private function runLint(): bool
    {
        $findings = [];
        $designPath = base_path('DESIGN.md');
        if (! is_file($designPath)) {
            $designPath = __DIR__.'/../../DESIGN.md';
        }
        if (is_file($designPath)) {
            $tokens = DesignLint::parseTokens((string) file_get_contents($designPath));
            $findings = [...$findings, ...DesignLint::contrastFindings($tokens)];
        } else {
            $this->warn('Design lint: no DESIGN.md found (app root or package) — token contrast unaudited.');
        }

        $slicesRoot = base_path('app/Slices');
        if (is_dir($slicesRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($slicesRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === 'index.blade.php') {
                    $label = str_replace(base_path().'/', '', dirname($file->getPathname(), 3));
                    $findings = [...$findings, ...DesignLint::bladeFindings((string) file_get_contents($file->getPathname()), $label)];
                }
            }
        }

        $errors = 0;
        $warnings = 0;
        foreach ($findings as $finding) {
            if ($finding['severity'] === 'error') {
                $errors++;
                $this->error("[{$finding['kind']}] {$finding['message']}");
            } else {
                $warnings++;
            }
        }
        $this->line("Design lint: {$errors} error(s), {$warnings} warning(s) across the fleet's index blades (inline styles / arbitrary values; errors above are the failures).");

        return $errors > 0;
    }
}
