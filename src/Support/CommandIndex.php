<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * A machine-readable discovery index of every LaraSlice
 * console command (`slice:*`, `laraslice:*`, `workflow:*`).
 *
 * Agents and tooling should never have to guess the CLI
 * surface: `slice:index` renders this index as JSON for
 * machines, a table for humans, or a Markdown table for
 * embedding in AGENTS.md (see AgentGuide).
 */
class CommandIndex
{
    /** Symfony/Laravel global options present on every command. */
    private const GLOBAL_OPTIONS = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'];

    /**
     * @return array<int, array{name: string, description: string, synopsis: string, arguments: array, options: array}>
     */
    public static function collect(): array
    {
        $rows = [];
        foreach (Artisan::all() as $name => $command) {
            if (! str_starts_with($name, 'slice:') && ! str_starts_with($name, 'laraslice:') && ! str_starts_with($name, 'workflow:')) {
                continue;
            }
            if ($command->isHidden()) {
                continue;
            }
            $definition = $command->getDefinition();
            $arguments = [];
            foreach ($definition->getArguments() as $argument) {
                $arguments[] = [
                    'name' => $argument->getName(),
                    'required' => $argument->isRequired(),
                    'array' => $argument->isArray(),
                    'description' => (string) $argument->getDescription(),
                ];
            }
            $options = [];
            foreach ($definition->getOptions() as $option) {
                if (in_array($option->getName(), self::GLOBAL_OPTIONS, true)) {
                    continue;
                }
                $options[] = [
                    'name' => $option->getName(),
                    'shortcut' => $option->getShortcut(),
                    'takes_value' => $option->acceptValue(),
                    'description' => (string) $option->getDescription(),
                ];
            }
            $rows[] = [
                'name' => $name,
                'description' => (string) $command->getDescription(),
                'synopsis' => $command->getSynopsis(),
                'arguments' => $arguments,
                'options' => $options,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    public static function toJson(array $rows): string
    {
        return json_encode(
            ['generated_by' => 'slice:index', 'count' => count($rows), 'commands' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ).PHP_EOL;
    }

    /** Compact name + purpose table for embedding in guides. */
    public static function toMarkdownTable(array $rows): string
    {
        $lines = ['| Command | What it does |', '|---|---|'];
        foreach ($rows as $row) {
            $lines[] = '| `'.$row['name'].'` | '.str_replace('|', '\\|', $row['description']).' |';
        }

        return implode(PHP_EOL, $lines);
    }
}
