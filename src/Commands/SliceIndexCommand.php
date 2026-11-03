<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LaraSlice\Support\AgentGuide;
use LaraSlice\Support\CommandIndex;

/**
 * The CLI discovery index: every LaraSlice command, its
 * purpose, arguments and options — as a table for humans,
 * JSON for machines, or Markdown for docs. Agents should
 * discover the CLI surface here instead of guessing.
 *
 * With --agents it (re)generates the app-root AGENTS.md
 * guide + CLAUDE.md shim (the same writer slice:install
 * uses), so existing installs can adopt the guide without
 * reinstalling.
 */
class SliceIndexCommand extends Command
{
    protected $signature = 'slice:index
                            {--format=table : Output format: table, json or md}
                            {--output= : Write to this file instead of stdout}
                            {--agents : Generate AGENTS.md + CLAUDE.md in the app root}
                            {--force : With --agents, overwrite existing files}';

    protected $description = 'Discovery index of all LaraSlice commands (table / JSON / Markdown); --agents generates the AGENTS.md guide';

    public function handle(): int
    {
        if ($this->option('agents')) {
            $status = AgentGuide::write((bool) $this->option('force'));
            foreach ($status as $file => $state) {
                $this->line($state === 'written'
                    ? "✅ {$file} generated at ".base_path($file)
                    : "• {$file} already exists — kept (use --force to regenerate)");
            }

            return self::SUCCESS;
        }

        $rows = CommandIndex::collect();
        $format = strtolower((string) $this->option('format'));
        $content = match ($format) {
            'json' => CommandIndex::toJson($rows),
            'md', 'markdown' => CommandIndex::toMarkdownTable($rows).PHP_EOL,
            default => null,
        };

        if ($content !== null) {
            if ($output = $this->option('output')) {
                File::put($output, $content);
                $this->info('Wrote '.count($rows)." commands to {$output}");
            } else {
                $this->line($content);
            }

            return self::SUCCESS;
        }

        $this->table(
            ['Command', 'What it does'],
            array_map(fn ($row) => [$row['name'], $row['description']], $rows)
        );
        $this->line('<fg=gray>'.count($rows).' LaraSlice commands. Machine index: php artisan slice:index --format=json</>');

        return self::SUCCESS;
    }
}
