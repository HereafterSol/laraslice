<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Conformance\ConformanceRunner;

/**
 * Cross-runtime conformance suite (Proposal #7): project
 * every declaration in the conformance corpus through this
 * runtime's metadata projector and check the pinned facts
 * plus the contract invariants (structure, canonical
 * stability, ladder reproducibility). --write-table
 * regenerates the fidelity-table artefact from the run.
 *
 * Exits FAILURE when any entry does not conform — the
 * suite must be able to fail.
 */
class SliceCorpusCommand extends Command
{
    protected $signature = 'slice:corpus
                            {--dir= : Corpus directory (default: the framework conformance corpus)}
                            {--write-table : Regenerate conformance/fidelity-table.md from this run}';

    protected $description = 'Run the cross-runtime conformance corpus against this runtime\'s metadata projection';

    public function handle(): int
    {
        $dir = $this->option('dir');
        $runner = new ConformanceRunner(is_string($dir) && $dir !== '' ? $dir : null);
        $result = $runner->run();

        foreach ($result['entries'] as $slug => $entry) {
            if ($entry['passed']) {
                $this->info("✓ {$slug} — conforms (".count($entry['facts']).' facts, '.count($entry['invariants']).' invariants)');

                continue;
            }
            $this->error("✗ {$slug} — FAILS");
            foreach ($entry['facts'] as $row) {
                if (! $row['ok']) {
                    $this->line("    fact {$row['fact']}: expected {$row['expected']}, projected {$row['projected']}");
                }
            }
            foreach ($entry['invariants'] as $row) {
                if (! $row['ok']) {
                    $this->line("    invariant {$row['check']}".($row['detail'] !== '' ? " — {$row['detail']}" : ''));
                }
            }
        }

        if ($this->option('write-table')) {
            $path = ConformanceRunner::fidelityTablePath();
            file_put_contents($path, $runner->fidelityTable($result));
            $this->info("Fidelity table written: {$path}");
        }

        $this->info("Conformance: {$result['entries_passed']}/{$result['entries_total']} entries conform.");

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
