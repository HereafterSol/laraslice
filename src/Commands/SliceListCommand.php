<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;

class SliceListCommand extends Command
{
    protected $signature = 'slice:list {--domain= : Filter slices by domain group}';

    protected $description = 'List all discovered modular vertical slices in the application (Domain-grouped)';

    public function handle(SliceManager $manager): int
    {
        $domainFilter = $this->option('domain');
        $slices = $manager->getAllSlices();

        if (empty($slices)) {
            $this->warn("No slices found. Run 'php artisan slice:make {Name}' to create one.");

            return Command::SUCCESS;
        }

        if ($domainFilter) {
            $slices = array_filter($slices, function ($s) use ($domainFilter) {
                $domain = $s->domain ?: ($s->navigation['group'] ?? 'General / Core');

                return strcasecmp($domain, $domainFilter) === 0;
            });

            if (empty($slices)) {
                $this->warn("No slices found in domain [{$domainFilter}].");

                return Command::SUCCESS;
            }
        }

        $rows = [];
        foreach ($slices as $slice) {
            $domain = $slice->domain ?: ($slice->navigation['group'] ?? 'General / Core');
            $models = ! empty($slice->models) ? implode(', ', $slice->models) : '-';

            $rows[] = [
                $slice->name,
                $domain,
                $slice->title,
                $slice->version ?: '1.0.0',
                $slice->active ? '<fg=green>Active</>' : '<fg=red>Disabled</>',
                $models,
                $slice->path,
            ];
        }

        $this->table(['Slice Name', 'Domain', 'Title', 'Version', 'Status', 'Models', 'Location'], $rows);

        return Command::SUCCESS;
    }
}
