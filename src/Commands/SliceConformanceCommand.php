<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Porting\ConformanceGate;
use LaraSlice\Porting\DesignLint;
use LaraSlice\Porting\PortEvidence;
use LaraSlice\Porting\SourceAnatomyExtractor;
use Symfony\Component\Yaml\Yaml;

class SliceConformanceCommand extends Command
{
    protected $signature = 'slice:conformance {--lint : Also run the design lint (DESIGN.md contrast pairs + blade colour/spacing patterns)} {--visual : Also run the Tier-5 visual gate (source.design.yaml tokens vs port design captures, plus form-presentation findings)} {--format= : Output format: text (default) or json — JSON carries the gate result with per-finding fix hints for agents}';

    protected $description = 'Fidelity conformance gate: compare every anatomy spec (source evidence) against the ported Hearts and judge all differences against the deviation register (porting/deviations.yaml). Exits 1 on UNRECORDED deviations.';

    /** @var array<string, mixed> */
    private array $jsonPayload = [];

    /** @var list<array<string, mixed>> */
    private array $lintFindings = [];

    public function handle(): int
    {
        if ($this->option('format') !== 'json') {
            return $this->runGate();
        }
        // JSON mode: the gate runs against a buffered output so
        // the only thing on stdout is the structured result.
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        $real = $this->output;
        $this->output = $buffer;
        try {
            $code = $this->runGate();
        } finally {
            $this->output = $real;
        }
        $this->line((string) json_encode($this->jsonPayload + [
            'command' => 'slice:conformance',
            'exit' => $code,
        ], JSON_PRETTY_PRINT));

        return $code;
    }

    private function runGate(): int
    {
        // Evidence (porting/evidence/*.json) is the second
        // measurement source: scripted journey gates and audit
        // runners record checks there. Anatomy measures
        // structure; evidence measures behaviour. The two
        // percents are reported side by side, never blended.
        $evidence = PortEvidence::load();
        $evidencePayload = $this->evidencePayload($evidence);

        $specDir = base_path('porting/ui-spec');
        $specFiles = is_dir($specDir) ? (glob($specDir.'/*.anatomy.ui.yaml') ?: []) : [];
        if ($specFiles === []) {
            $hasSlices = false;
            $slicesRootEarly = base_path('app/Slices');
            if (is_dir($slicesRootEarly)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($slicesRootEarly, \FilesystemIterator::SKIP_DOTS)) as $earlyFile) {
                    if ($earlyFile->isFile() && $earlyFile->getFilename() === 'slice.yaml') {
                        $hasSlices = true;
                        break;
                    }
                }
            }

            if ($evidence['checks'] > 0) {
                // No anatomy recorded, but journey evidence is:
                // the port IS measured — by its recorded checks.
                // A failing recorded check fails the gate; a
                // malformed evidence file fails it too.
                $this->reportEvidence($evidence);
                $failed = $evidence['failed_count'] > 0 || $evidence['errors'] !== [];
                $this->jsonPayload = [
                    'pages' => 0, 'conforming' => 0,
                    'percent' => $evidence['percent'], 'measured' => true, 'measured_by' => 'evidence',
                    'unrecorded' => [], 'open' => [], 'approved' => [], 'stale' => [], 'per_page' => [],
                    'evidence' => $evidencePayload,
                    'note' => 'No anatomy specs at porting/ui-spec/*.anatomy.ui.yaml — structure conformance NOT MEASURED; the percent shown is the recorded port evidence (porting/evidence/). Record anatomy with slice:port --anatomy to measure structure too.',
                ];
                $this->warn('No anatomy specs — structure conformance NOT MEASURED. Port evidence: '.$evidence['passed'].'/'.$evidence['checks'].' checks passed ('.$evidence['percent'].'%).');

                return $failed ? self::FAILURE : self::SUCCESS;
            }

            // Nothing recorded at all = NOT MEASURED, never
            // "100%": a gate that passes on zero evidence teaches
            // teams to trust a number that was never computed.
            // When the app actually has slices installed, the
            // absence of specs is a failed gate (record them);
            // on a bare app with nothing to measure it stays a
            // no-op success.
            $this->jsonPayload = ['pages' => 0, 'conforming' => 0, 'percent' => null, 'measured' => false, 'measured_by' => 'none', 'unrecorded' => [], 'open' => [], 'approved' => [], 'stale' => [], 'per_page' => [], 'evidence' => $evidencePayload, 'note' => 'No anatomy specs at porting/ui-spec/*.anatomy.ui.yaml — conformance NOT MEASURED. Run slice:port --anatomy first.'];
            $this->warn('No anatomy specs at porting/ui-spec/*.anatomy.ui.yaml — conformance NOT MEASURED (0 pages recorded). Run slice:port --anatomy first.');

            return $hasSlices ? self::FAILURE : self::SUCCESS;
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
        $perPage = [];
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
            $perPage[$slug] = ['state' => $state, 'candidates' => count($ids), 'heart' => str_replace(base_path().'/', '', $info['path'])];
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

        $pct = $pages > 0 ? round(100 * $conforming / $pages) : null;
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
        if ($evidence['checks'] > 0 || $evidence['errors'] !== []) {
            // Recorded journey evidence stands beside the
            // anatomy verdict: a port whose Gate T record
            // carries a failure is not conforming, whatever
            // the structure comparison says.
            $this->reportEvidence($evidence);
            $failed = $failed || $evidence['failed_count'] > 0 || $evidence['errors'] !== [];
        }
        if ($this->option('lint')) {
            $failed = $this->runLint() || $failed;
        }

        $withFixes = static fn (array $lines) => array_map(fn ($line) => [
            'entry' => $line,
            'fix' => \LaraSlice\Support\FindingFixes::forLine($line),
        ], $lines);
        $this->jsonPayload = [
            'pages' => $pages,
            'conforming' => $conforming,
            'percent' => $pages > 0 ? round(100 * $conforming / $pages) : null,
            'measured' => $pages > 0,
            'measured_by' => $evidence['checks'] > 0 ? 'anatomy+evidence' : 'anatomy',
            'evidence' => $evidencePayload,
            'per_page' => $perPage,
            'unrecorded' => $withFixes($allErrors),
            'open' => $withFixes($allOpen),
            'approved' => $allApproved,
            'stale' => $allStale,
            'lint_findings' => array_map(fn ($f) => [
                'kind' => $f['kind'] ?? 'lint',
                'severity' => $f['severity'] ?? 'warning',
                'message' => $f['message'] ?? '',
                'fix' => \LaraSlice\Support\FindingFixes::forKind($f['kind'] ?? null),
            ], $this->lintFindings),
        ];

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The evidence block carried in the JSON payload (and
     * summarised in text mode): per-suite counts with the
     * recording timestamp, so a reader can judge freshness —
     * evidence is a measurement with a date, not a status.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function evidencePayload(array $evidence): array
    {
        return [
            'checks' => $evidence['checks'],
            'passed' => $evidence['passed'],
            'percent' => $evidence['percent'],
            'suites' => array_map(static fn (array $suite) => [
                'suite' => $suite['suite'],
                'file' => $suite['file'],
                'recorded_at' => $suite['recorded_at'],
                'app' => $suite['app'],
                'checks' => $suite['checks'],
                'passed' => $suite['passed'],
                'failed' => array_column($suite['failed'], 'id'),
            ], $evidence['suites']),
            'errors' => $evidence['errors'],
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function reportEvidence(array $evidence): void
    {
        foreach ($evidence['suites'] as $suite) {
            $when = $suite['recorded_at'] !== null ? " (recorded {$suite['recorded_at']})" : '';
            $this->line("  evidence {$suite['suite']}: {$suite['passed']}/{$suite['checks']} checks passed{$when}");
            foreach ($suite['failed'] as $failedCheck) {
                $detail = $failedCheck['detail'] !== '' ? " — {$failedCheck['detail']}" : '';
                $this->error("  evidence {$suite['suite']} {$failedCheck['id']}: FAILED — {$failedCheck['label']}{$detail}");
            }
        }
        foreach ($evidence['errors'] as $error) {
            $this->error('  evidence '.$error);
        }
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

        $this->lintFindings = $findings;
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
