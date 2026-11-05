<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Porting\TrilogyPortabilityLint;
use LaraSlice\Support\PortabilityAudit;

/**
 * Runtime portability (slice:portability): the gate that
 * keeps the declarations runtime-neutral. The Mind, Bodies
 * and Hearts are shared truth for every runtime; a PHP
 * class name, a .blade.php path or a framework helper call
 * inside one of them leaks THIS runtime into that truth.
 *
 * Runs the shipped {@see PortabilityAudit} on Declaration History
 * files, plus {@see TrilogyPortabilityLint} for contract §1–2 checks.
 */
final class SlicePortabilityCommand extends Command
{
    protected $signature = 'slice:portability
                            {--format= : Output format for declaration audit: text (default) or json}
                            {--json : Emit TrilogyPortabilityLint findings as JSON (CI-friendly)}
                            {--root= : Project root for trilogy scan (defaults to base_path())}
                            {--audit-only : Run declaration audit only (skip trilogy lint)}';

    protected $description = 'Audit declarations (Mind, Bodies, Hearts) for Laravel/PHP leakage; optional trilogy contract lint';

    public function handle(TrilogyPortabilityLint $lint): int
    {
        $rootOption = $this->option('root');
        $root = is_string($rootOption) && $rootOption !== '' ? $rootOption : null;

        if ($this->option('json')) {
            return $this->emitTrilogyJson($lint, $root);
        }

        $auditExit = $this->runDeclarationAudit();
        if ($this->option('audit-only')) {
            return $auditExit;
        }

        $trilogyExit = $this->runTrilogyLint($lint, $root);

        return $auditExit === self::SUCCESS && $trilogyExit === self::SUCCESS
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function runDeclarationAudit(): int
    {
        $audit = PortabilityAudit::run();
        $json = $this->option('format') === 'json';

        if ($json) {
            $this->line((string) json_encode($audit, JSON_PRETTY_PRINT));

            return ($audit['clean'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if (! ($audit['available'] ?? false)) {
            $this->warn($audit['reason'] ?? 'Portability is not available.');

            return self::SUCCESS;
        }

        $findings = $audit['findings'];
        $bindingsNote = ($audit['bindings'] ?? 0) > 0
            ? "Plus {$audit['bindings']} runtime binding(s) — each Heart's namespace: line, its declared home in this runtime. Expected; every runtime declares its own."
            : null;
        if ($findings === []) {
            $this->info("Declarations are runtime-neutral: {$audit['files_scanned']} files scanned, no leakage found.");
            if ($bindingsNote !== null) {
                $this->line($bindingsNote);
            }

            return self::SUCCESS;
        }

        $files = count(array_unique(array_column($findings, 'file')));
        $this->error('Portability: '.count($findings)." finding(s) in {$files} file(s) — declarations should read the same on any runtime:");

        $rows = [];
        foreach (array_slice($findings, 0, 50) as $finding) {
            $rows[] = [$finding['file'], $finding['line'], $finding['term'], $finding['suggestion']];
        }
        $this->table(['File', 'Line', 'Term', 'Suggestion'], $rows);
        if (count($findings) > 50) {
            $this->line('… and '.(count($findings) - 50).' more (slice:portability --format=json lists them all).');
        }
        if ($bindingsNote !== null) {
            $this->line($bindingsNote);
        }

        return self::FAILURE;
    }

    private function runTrilogyLint(TrilogyPortabilityLint $lint, ?string $root): int
    {
        $findings = $lint->scan($root);

        $hearts = $lint->discoverHeartFiles($root ?? base_path());
        $this->line('<fg=gray>Trilogy hearts:</> '.count($hearts).' slice.yaml file(s)');
        $mind = ($root ?? base_path()).DIRECTORY_SEPARATOR.'porting'.DIRECTORY_SEPARATOR.'system.yaml';
        if (is_file($mind)) {
            $this->line('<fg=gray>Trilogy mind:</> porting/system.yaml');
        }
        $bodyGlob = ($root ?? base_path()).DIRECTORY_SEPARATOR.'porting'.DIRECTORY_SEPARATOR.'ui-spec'.DIRECTORY_SEPARATOR.'*.ui.yaml';
        $bodyCount = count(glob($bodyGlob) ?: []);
        if ($bodyCount > 0) {
            $this->line("<fg=gray>Trilogy body:</> {$bodyCount} ui-spec file(s)");
        }

        if ($findings === []) {
            $this->info('Portability lint PASS — no forbidden leakage in trilogy declarations.');

            return self::SUCCESS;
        }

        $this->error(count($findings).' trilogy portability finding(s):');
        foreach ($findings as $finding) {
            $loc = $finding['line'] !== null ? ":{$finding['line']}" : '';
            $this->line("  <fg=red>•</> [{$finding['layer']}/{$finding['rule']}] {$finding['file']}{$loc}");
            $this->line("    {$finding['message']}");
        }

        return self::FAILURE;
    }

    private function emitTrilogyJson(TrilogyPortabilityLint $lint, ?string $root): int
    {
        $findings = $lint->scan($root);
        $payload = [
            'contract' => 'MULTI-RUNTIME-PORTABILITY-CONTRACT.md',
            'finding_count' => count($findings),
            'findings' => $findings,
        ];
        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }
}
