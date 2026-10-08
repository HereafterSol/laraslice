<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Blueprint\BlueprintApplier;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintValidator;
use Throwable;

final class BlueprintApplyCommand extends Command
{
    protected $signature = 'slice:blueprint:apply
                            {path : Path to a validated .json, .yaml, or .yml blueprint}
                            {--plan-hash= : SHA-256 plan hash shown by slice:blueprint:plan}
                            {--yes : Confirm writing the reviewed slice files}';

    protected $description = 'Generate a supported slice from a reviewed blueprint without running migrations';

    public function handle(BlueprintLoader $loader, BlueprintValidator $validator, BlueprintPlanner $planner): int
    {
        try {
            $blueprint = $validator->validate($loader->load($this->argument('path')));
            $slicesPath = config('laraslice.slices_path', app_path('Slices'));
            $plan = $planner->plan($blueprint, $slicesPath);
            $applier = new BlueprintApplier(static fn (string $table): bool => Schema::hasTable($table));
            $applier->assertSupported($blueprint);

            if ($plan['conflict']) {
                throw new \RuntimeException('The destination already exists. Review a plan for a new, unique slice name.');
            }
            $providedHash = (string) $this->option('plan-hash');
            if ($providedHash === '' || ! hash_equals($plan['plan_hash'], $providedHash)) {
                $this->components->error('The plan hash is missing or stale. Review the current plan before applying.');
                $this->line('Current plan hash: '.$plan['plan_hash']);

                return self::FAILURE;
            }
            if (! $this->option('yes') && ! $this->confirm('Generate the reviewed files? Database migrations will not run.', false)) {
                return self::FAILURE;
            }

            $target = $applier->apply($blueprint, $plan, $slicesPath, config('laraslice.slices_namespace', 'App\\Slices'));
            $this->components->info("Generated slice at [{$target}].");
            $this->comment('Files were written atomically. Database migrations were not run.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
