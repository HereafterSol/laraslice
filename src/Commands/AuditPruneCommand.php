<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LaraSlice\Core\Audit\AuditLogger;

class AuditPruneCommand extends Command
{
    protected $signature = 'laraslice:audit:prune 
                            {--days=90 : Number of days of audit records to retain}
                            {--slice= : Optional slice filter to prune}
                            {--force : Force pruning without confirmation}';

    protected $description = 'Prune old enterprise audit log records to optimize database performance';

    public function handle(): int
    {
        if ($this->option('days') !== null) {
            $days = (int) $this->option('days');
        } else {
            $dbDays = DB::table('settings')->where('key', 'audit.retention_days')->value('value');
            $days = $dbDays ? (int) $dbDays : (int) config('laraslice.audit.retention_days', 90);
        }
        if ($days < 1) {
            $this->error('The --days option must be at least 1.');
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $slice = $this->option('slice');

        $query = DB::table(AuditLogger::TABLE_NAME)
            ->where('created_at', '<', $cutoff);

        if ($slice) {
            $query->where('slice', $slice);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("No audit logs older than {$days} days found to prune.");
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Are you sure you want to permanently delete {$count} audit records older than {$cutoff->toDateTimeString()}?")) {
            $this->warn('Pruning aborted.');
            return self::SUCCESS;
        }

        $this->info("Pruning {$count} audit log records...");
        
        $deletedTotal = 0;
        do {
            $deleted = DB::table(AuditLogger::TABLE_NAME)
                ->where('created_at', '<', $cutoff)
                ->when($slice, fn($q) => $q->where('slice', $slice))
                ->limit(1000)
                ->delete();

            $deletedTotal += $deleted;
            $this->output->write('.');
        } while ($deleted > 0);

        $this->newLine();
        $this->info("Successfully pruned {$deletedTotal} old audit log records.");

        return self::SUCCESS;
    }
}