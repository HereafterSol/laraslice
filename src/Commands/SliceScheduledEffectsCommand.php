<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Support\ScheduledEffects;

/**
 * Runs the declared scheduled effects (Phase 8,
 * DIFFERENCES D-079): every `effects` entry in every
 * slice Heart — settings-driven sweeps and derivation
 * recomputes — so a declared setting has a consumer and
 * date-derived statuses refresh as time passes, the way
 * Desk's daily scheduler jobs behave. Registered with
 * the Laravel scheduler daily by the service provider
 * (config: laraslice.effects.schedule).
 */
class SliceScheduledEffectsCommand extends Command
{
    protected $signature = 'slice:run-effects {--dry-run : Report what would change without writing}';

    protected $description = 'Run the declared scheduled effects from every slice Heart';

    public function handle(): int
    {
        $results = ScheduledEffects::runAll((bool) $this->option('dry-run'));
        foreach ($results as $result) {
            $this->line(sprintf(
                '%s [%s on %s] affected=%d skipped=%d — %s',
                $result['effect'],
                $result['type'],
                $result['slice'],
                $result['affected'],
                $result['skipped'],
                $result['detail']
            ));
        }
        if ($results === []) {
            $this->line('No scheduled effects declared.');
        }

        return self::SUCCESS;
    }
}
