<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Support\DeclarationHistory;

/**
 * Declaration history (slice:history): the ledger over
 * the Mind, Bodies and Hearts. Recording also happens
 * automatically in slice:cache and slice:sync; this
 * command lists entries, records on demand, and diffs an
 * entry against its parent state.
 */
class SliceHistoryCommand extends Command
{
    protected $signature = 'slice:history {--record : Record a pass now (normally automatic via slice:cache / slice:sync)} {--actor= : Who is recording (default: cli)} {--diff= : Show the unified diffs of one entry id} {--format= : Output format: text (default) or json}';

    protected $description = 'Declaration history: list, record and diff changes to the Mind, Bodies and Hearts (porting/history/)';

    public function handle(): int
    {
        $history = new DeclarationHistory;
        $json = $this->option('format') === 'json';

        try {
            if ($this->option('diff')) {
                return $this->showDiff($history, (string) $this->option('diff'), $json);
            }

            if ($this->option('record')) {
                $entry = $history->record((string) ($this->option('actor') ?: 'cli'), DeclarationHistory::currentPerson());
                if ($json) {
                    $this->line((string) json_encode(['command' => 'slice:history', 'recorded' => $entry], JSON_PRETTY_PRINT));
                } elseif ($entry === null) {
                    $this->info('No declaration changes since the last entry — nothing recorded.');
                } else {
                    $this->info("Recorded {$entry['id']} ({$entry['actor']}): ".count($entry['changes']).' change(s).');
                    foreach ($entry['changes'] as $change) {
                        $this->line("  {$change['kind']} {$change['path']}");
                    }
                }

                return self::SUCCESS;
            }

            $entries = $history->entries();
            if ($json) {
                $this->line((string) json_encode(['command' => 'slice:history', 'entries' => $entries], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            if ($entries === []) {
                $this->warn('No declaration history yet. Record the baseline with: php artisan slice:history --record');

                return self::SUCCESS;
            }
            $rows = [];
            foreach (array_slice($entries, 0, 20) as $entry) {
                $kinds = array_count_values(array_column($entry['changes'], 'kind'));
                $summary = collect($kinds)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ');
                $rows[] = [$entry['id'], $entry['recorded_at'], $entry['actor'], (string) ($entry['by'] ?? ''), $summary];
            }
            $this->table(['Entry', 'Recorded', 'Actor', 'By', 'Changes'], $rows);
            $this->line('Diff an entry: php artisan slice:history --diff=<entry id>');

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function showDiff(DeclarationHistory $history, string $entryId, bool $json): int
    {
        $diffs = $history->diff($entryId);
        if ($diffs === []) {
            $this->error("No history entry with id [{$entryId}].");

            return self::FAILURE;
        }
        if ($json) {
            $this->line((string) json_encode(['command' => 'slice:history', 'diff' => $diffs], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        foreach ($diffs as $path => $diff) {
            $this->line($diff);
            $this->line('');
        }

        return self::SUCCESS;
    }
}
