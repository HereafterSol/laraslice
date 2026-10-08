<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintValidator;
use Throwable;

final class BlueprintPlanCommand extends Command
{
    protected $signature = 'slice:blueprint:plan
                            {path : Path to a .json, .yaml, or .yml slice blueprint}
                            {--format=table : Output format: table or json}';

    protected $description = 'Show the deterministic file and schema plan for a LaraSlice blueprint (read-only)';

    public function handle(BlueprintLoader $loader, BlueprintValidator $validator, BlueprintPlanner $planner): int
    {
        try {
            $blueprint = $validator->validate($loader->load($this->argument('path')));
            $plan = $planner->plan($blueprint, config('laraslice.slices_path', app_path('Slices')));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $plan['conflict'] ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('format') !== 'table') {
            $this->components->error('The --format option must be table or json.');

            return self::FAILURE;
        }

        $this->components->info("Plan for [{$plan['blueprint']['handle']}]");
        $this->line('Target: '.$plan['target']);
        $this->line('Root model: '.$plan['root_model']);
        $this->newLine();

        $this->table(
            ['Model', 'Table', 'Fields', 'Relations'],
            array_map(static fn (array $model): array => [
                $model['handle'],
                $model['table'],
                $model['field_count'],
                $model['relation_count'],
            ], $plan['models'])
        );

        $this->newLine();
        $this->components->twoColumnDetail('Files', (string) count($plan['files']));
        foreach ($plan['files'] as $file) {
            $this->line('  '.$file);
        }
        foreach ($plan['file_changes'] as $change) {
            $this->line('  Update '.$change['path'].': '.$change['reason']);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Database operations', (string) count($plan['database_operations']));
        foreach ($plan['database_operations'] as $operation) {
            $description = match ($operation['kind']) {
                'create_table' => "Create {$operation['table']} (".implode(', ', array_column($operation['columns'], 'column')).')',
                'foreign_key' => "Add {$operation['table']}.{$operation['column']} -> {$operation['references']}",
                'pivot_table' => "Create {$operation['table']} pivot (".implode(' <-> ', $operation['models']).')',
                default => json_encode($operation, JSON_UNESCAPED_SLASHES),
            };
            $this->line('  '.$description);
        }

        $this->newLine();
        if ($plan['conflict']) {
            $this->components->error('The destination already exists. Generation must stop to protect existing files.');

            return self::FAILURE;
        }

        $this->comment('Read-only preview: no files or database tables were changed.');
        $this->line('Apply hash: '.$plan['plan_hash']);
        $this->comment('Apply currently supports a root model with direct hasMany children and supported field types.');
        $this->line('Use slice:blueprint:apply with this hash after reviewing the file and database plan.');

        return self::SUCCESS;
    }
}
