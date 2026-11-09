<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\SliceAlterer;
use Throwable;

/**
 * Evolve an EXISTING slice's table from its Heart (F11,
 * Day-2 drill): diff the declared fields against the live
 * root table and generate + run the alter migration for
 * the additive changes (new nullable/defaulted columns).
 *
 * This is the regen-family discipline applied to the
 * database — dry-run by default, --write generates one
 * migration into the slice's Migrations directory and
 * runs it, success is claimed only for columns verified
 * to exist afterwards. Anything the diff cannot safely
 * do — a type/length/nullability change on an existing
 * column, a NOT NULL column with no default on a
 * populated table — is REFUSED with a named reason, never
 * guessed at; columns the Heart does not declare are
 * named and left untouched (drops are never generated).
 * A run that leaves refusals outstanding exits FAILURE
 * in both modes: a dry-run that exits 0 while the Heart
 * and the database disagree would repeat, in miniature,
 * the silent-success failure this command exists to kill.
 *
 * Why not slice:sync? Sync's GitOps contract is to apply
 * the migration files the repository carries, identically
 * in every environment — it must never GENERATE files
 * from a live diff. Generating from the Heart is the
 * regen/absorb family's job, so the alter path lives
 * here, as its own command in that family.
 */
final class SliceAlterCommand extends Command
{
    protected $signature = 'slice:alter
                            {slice : Slice name (or use --path for a specific twin)}
                            {--path= : Explicit slice directory (disambiguates name twins)}
                            {--write : Generate the alter migration and run it (default is a dry-run report)}
                            {--force : Required with --write in production}';

    protected $description = 'Diff a slice\'s Heart against its live table and generate/run the additive alter migration';

    public function handle(SliceManager $manager, SliceAlterer $alterer): int
    {
        $manager->discover(true); // entry point: operate on the slices as they are on disk now, not a boot-time registry

        $manifest = null;
        if ($path = $this->option('path')) {
            $wanted = realpath((string) $path);
            foreach ($manager->discoveredSlices() as $candidate) {
                if (realpath($candidate->path) === $wanted) {
                    $manifest = $candidate;
                    break;
                }
            }
            if ($manifest === null) {
                $this->error("No discovered slice at path [{$path}].");

                return self::FAILURE;
            }
        } else {
            $manifest = $manager->getSlice((string) $this->argument('slice'));
            if ($manifest === null) {
                $this->error("Slice [{$this->argument('slice')}] not found.");

                return self::FAILURE;
            }
        }

        try {
            $plan = $alterer->plan($manifest);
        } catch (Throwable $e) {
            $this->error("Slice [{$manifest->name}]: the Heart could not be diffed — {$e->getMessage()}");

            return self::FAILURE;
        }
        if ($plan['error'] !== null) {
            $this->error("Slice [{$manifest->name}]: {$plan['error']}");

            return self::FAILURE;
        }
        $table = (string) $plan['table'];
        $write = (bool) $this->option('write');

        $this->info("{$manifest->name}: Heart ↔ table [{$table}] diff".($write ? '' : ' (dry run)'));

        foreach ($plan['additive'] as $column) {
            $this->line("  ADDITIVE   {$column['name']} — {$column['expression']}");
        }
        foreach ($plan['refused'] as $refusal) {
            $this->line("  REFUSED    {$refusal['name']} — {$refusal['reason']}");
        }
        foreach ($plan['undeclared'] as $column) {
            $this->line("  UNDECLARED {$column} — present in [{$table}] but not declared in the Heart; left untouched (slice:alter never drops)");
        }

        if ($plan['additive'] === [] && $plan['refused'] === []) {
            $this->info($plan['undeclared'] === []
                ? "{$manifest->name}: Heart and table are in sync — nothing to alter."
                : "{$manifest->name}: no additive changes; undeclared columns listed above are left as they are.");

            return self::SUCCESS;
        }

        if (! $write) {
            if ($plan['additive'] !== []) {
                $this->comment('Dry run — re-run with --write to generate and run the alter migration.');
            }
            if ($plan['refused'] !== []) {
                $this->error('Refusals outstanding — the Heart and the table cannot be reconciled by slice:alter alone (see REFUSED lines above).');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to alter a production database without --force.');

            return self::FAILURE;
        }

        if ($plan['additive'] !== []) {
            $migrationPath = $alterer->writeMigration($manifest, $table, $plan['additive']);
            $this->line('  Generated '.basename($migrationPath));

            try {
                $exit = Artisan::call('migrate', [
                    '--path' => $manifest->path.'/Migrations',
                    '--realpath' => true,
                    '--force' => true,
                ]);
                $out = trim(Artisan::output());
                if ($out !== '') {
                    $this->line($out);
                }
            } catch (Throwable $e) {
                $this->error('Alter migration failed: '.$e->getMessage());

                return self::FAILURE;
            }
            if ($exit !== 0) {
                $this->error("Alter migration failed: artisan migrate exited with code {$exit}.");

                return self::FAILURE;
            }

            // Claim only what is verified to exist.
            $missing = [];
            foreach ($plan['additive'] as $column) {
                if (! Schema::hasColumn($table, $column['name'])) {
                    $missing[] = $column['name'];
                }
            }
            if ($missing !== []) {
                $this->error('Alter migration ran but these columns do not exist afterwards: '.implode(', ', $missing).'.');

                return self::FAILURE;
            }
            $this->info('Altered ['.$table.']: added '.implode(', ', array_column($plan['additive'], 'name')).'.');
        }

        if ($plan['refused'] !== []) {
            $this->error('Refusals outstanding — the additive changes above were applied, but the Heart and the table are NOT fully reconciled (see REFUSED lines above).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
