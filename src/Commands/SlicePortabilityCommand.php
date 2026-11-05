<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Porting\TrilogyPortabilityLint;

/**
 * Lint Heart / Mind / Body declarations for runtime-neutral vocabulary.
 *
 * @see docs/MULTI-RUNTIME-PORTABILITY-CONTRACT.md
 */
final class SlicePortabilityCommand extends Command
{
    protected $signature = 'slice:portability
                            {--json : Emit findings as JSON (CI-friendly)}
                            {--root= : Project root to scan (defaults to base_path())}';

    protected $description = 'Lint slice.yaml, porting/system.yaml, and porting/ui-spec for cross-runtime forbidden leakage (exits 1 on findings).';

    public function handle(TrilogyPortabilityLint $lint): int
    {
        $rootOption = $this->option('root');
        $root = is_string($rootOption) && $rootOption !== '' ? $rootOption : null;

        $findings = $lint->scan($root);

        if ($this->option('json')) {
            $payload = [
                'contract' => 'MULTI-RUNTIME-PORTABILITY-CONTRACT.md',
                'finding_count' => count($findings),
                'findings' => $findings,
            ];
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $findings === [] ? self::SUCCESS : self::FAILURE;
        }

        $hearts = $lint->discoverHeartFiles($root ?? base_path());
        $this->line('<fg=gray>Hearts:</> '.count($hearts).' slice.yaml file(s)');
        $mind = ($root ?? base_path()).DIRECTORY_SEPARATOR.'porting'.DIRECTORY_SEPARATOR.'system.yaml';
        if (is_file($mind)) {
            $this->line('<fg=gray>Mind:</> porting/system.yaml');
        }
        $bodyGlob = ($root ?? base_path()).DIRECTORY_SEPARATOR.'porting'.DIRECTORY_SEPARATOR.'ui-spec'.DIRECTORY_SEPARATOR.'*.ui.yaml';
        $bodyCount = count(glob($bodyGlob) ?: []);
        if ($bodyCount > 0) {
            $this->line("<fg=gray>Body:</> {$bodyCount} ui-spec file(s)");
        }

        if ($findings === []) {
            $this->info('Portability lint PASS — no forbidden leakage in trilogy declarations.');

            return self::SUCCESS;
        }

        $this->error(count($findings).' portability finding(s):');
        foreach ($findings as $finding) {
            $loc = $finding['line'] !== null ? ":{$finding['line']}" : '';
            $this->line("  <fg=red>•</> [{$finding['layer']}/{$finding['rule']}] {$finding['file']}{$loc}");
            $this->line("    {$finding['message']}");
        }

        return self::FAILURE;
    }
}
