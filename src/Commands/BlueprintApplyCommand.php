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
                            {--yes : Confirm writing the reviewed slice files}
                            {--migrate : Run database migrations after applying (default with --yes when interactive)}
                            {--seed : Seed demo records after migrate (implies --migrate)}';

    protected $description = 'Generate a supported slice from a reviewed blueprint; optionally migrate and seed';

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
            if (! $this->option('yes') && ! $this->confirm('Generate the reviewed files?', false)) {
                return self::FAILURE;
            }

            $target = $applier->apply($blueprint, $plan, $slicesPath, config('laraslice.slices_namespace', 'App\\Slices'));
            $this->components->info("Generated slice at [{$target}].");
            $this->comment('Files were written atomically.');

            $doMigrate = (bool) $this->option('migrate') || (bool) $this->option('seed');
            if (! $doMigrate && $this->input->isInteractive() && ! $this->option('no-interaction')) {
                $doMigrate = $this->confirm('Run database migrations now?', true);
            }

            if ($doMigrate) {
                $this->comment('Running migrations…');
                try {
                    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
                    $out = trim(\Illuminate\Support\Facades\Artisan::output());
                    if ($out !== '') {
                        $this->line($out);
                    }
                    $this->components->info('Migrations finished.');
                } catch (\Throwable $e) {
                    $this->components->error('Migration failed: '.$e->getMessage());
                    $this->line('You can retry with: php artisan migrate');
                }
            } else {
                $this->comment('Migrations were not run. Next: php artisan migrate');
            }

            $sliceName = $blueprint['name'] ?? $blueprint['handle'] ?? 'Slice';
            $this->newLine();
            $this->comment('Demo data:');
            $this->line('  php artisan slice:seed '.\Illuminate\Support\Str::studly((string) $sliceName).' --count=10');
            if ($this->option('seed')) {
                $this->comment('Seeding demo records…');
                try {
                    \Illuminate\Support\Facades\Artisan::call('slice:seed', [
                        'slice' => \Illuminate\Support\Str::studly((string) $sliceName),
                        '--count' => 10,
                    ]);
                    $this->line(trim(\Illuminate\Support\Facades\Artisan::output()));
                } catch (\Throwable $e) {
                    $this->components->warn('Seed failed (slice may need a seeder): '.$e->getMessage());
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
