<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Support\PortabilityAudit;

/**
 * Runtime portability (slice:portability): the gate that
 * keeps the declarations runtime-neutral. The Mind, Bodies
 * and Hearts are shared truth for every runtime; a PHP
 * class name, a .blade.php path or a framework helper call
 * inside one of them leaks THIS runtime into that truth.
 * Exit code is the gate: success when clean, failure when
 * any leakage is found, so CI can hold the line.
 */
class SlicePortabilityCommand extends Command
{
    protected $signature = 'slice:portability {--format= : Output format: text (default) or json}';

    protected $description = 'Audit the declarations (Mind, Bodies, Hearts) for Laravel/PHP leakage that would break sibling runtimes';

    public function handle(): int
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
}
