<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
                $exitCode = 0;
                try {
                    $exitCode = Artisan::call('migrate', ['--force' => true]);
                    $out = trim(Artisan::output());
                    if ($out !== '') {
                        $this->line($out);
                    }

                    // F9 (Day-2 drill): the slice's migrations
                    // live in the slice's own Migrations
                    // directory, which is NOT on the default
                    // migrate path — the plain call above
                    // migrates the app's own migrations and
                    // reports success while the slice's table
                    // is never created. Run the applied
                    // slice's migrations explicitly.
                    $sliceMigrations = $target.'/Migrations';
                    if (is_dir($sliceMigrations)) {
                        $sliceExit = Artisan::call('migrate', [
                            '--path' => $sliceMigrations,
                            '--realpath' => true,
                            '--force' => true,
                        ]);
                        $sliceOut = trim(Artisan::output());
                        if ($sliceOut !== '') {
                            $this->line($sliceOut);
                        }
                        $exitCode = $exitCode !== 0 ? $exitCode : $sliceExit;
                    }
                } catch (Throwable $e) {
                    $this->components->error('Migration failed: '.$e->getMessage());
                    $this->line('You can retry with: php artisan migrate');

                    return self::FAILURE;
                }

                if ($exitCode !== 0) {
                    $this->components->error("Migration failed: artisan migrate exited with code {$exitCode}. The slice's tables were not verified — apply failed.");

                    return self::FAILURE;
                }

                // Never print success on faith: every table
                // the plan promised (and a migration exists
                // for) must exist now, or apply has failed.
                $missing = [];
                foreach ($plan['database_operations'] ?? [] as $operation) {
                    $kind = $operation['kind'] ?? null;
                    if ($kind === 'create_table' && ! Schema::hasTable((string) $operation['table'])) {
                        $missing[] = (string) $operation['table'];
                    }
                    if ($kind === 'pivot_table' && $this->sliceHasMigrationFor($target, (string) $operation['table'])
                        && ! Schema::hasTable((string) $operation['table'])) {
                        $missing[] = (string) $operation['table'];
                    }
                }
                if ($missing !== []) {
                    $this->components->error('Migration reported success but these planned tables do not exist: '.implode(', ', $missing).'. The slice is NOT usable — apply failed.');

                    return self::FAILURE;
                }
                $this->components->info('Migrations finished.');
            } else {
                $this->comment('Migrations were not run. Next: php artisan migrate');
            }

            $sliceName = $blueprint['name'] ?? $blueprint['handle'] ?? 'Slice';
            $this->newLine();
            $this->comment('Demo data:');
            $this->line('  php artisan slice:seed '.Str::studly((string) $sliceName).' --count=10');
            if ($this->option('seed')) {
                $this->comment('Seeding demo records…');
                try {
                    Artisan::call('slice:seed', [
                        'slice' => Str::studly((string) $sliceName),
                        '--count' => 10,
                    ]);
                    $this->line(trim(Artisan::output()));
                } catch (Throwable $e) {
                    $this->components->warn('Seed failed (slice may need a seeder): '.$e->getMessage());
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Whether the applied slice carries a migration file
     * naming the given table (used to scope the F9
     * post-migrate existence check to tables this apply
     * was actually responsible for creating).
     */
    private function sliceHasMigrationFor(string $target, string $table): bool
    {
        $dir = $target.'/Migrations';
        if (! is_dir($dir)) {
            return false;
        }
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            if (str_contains(basename($file), $table)) {
                return true;
            }
        }

        return false;
    }
}
