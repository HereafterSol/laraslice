<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintValidator;
use Throwable;

final class BlueprintValidateCommand extends Command
{
    protected $signature = 'slice:blueprint:validate {path : Path to a .json, .yaml, or .yml slice blueprint}';

    protected $description = 'Validate a versioned LaraSlice blueprint without changing files or the database';

    public function handle(BlueprintLoader $loader, BlueprintValidator $validator): int
    {
        try {
            $blueprint = $validator->validate($loader->load($this->argument('path')));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Blueprint [{$blueprint['handle']}] is valid (schema v{$blueprint['schema_version']}).");
        $this->line(count($blueprint['models']).' model(s), '.$this->fieldCount($blueprint).' field(s), '.$this->relationCount($blueprint).' relation(s).');

        return self::SUCCESS;
    }

    private function fieldCount(array $blueprint): int
    {
        return array_sum(array_map(static fn (array $model): int => count($model['fields'] ?? []), $blueprint['models']));
    }

    private function relationCount(array $blueprint): int
    {
        return array_sum(array_map(static fn (array $model): int => count($model['relations'] ?? []), $blueprint['models']));
    }
}
