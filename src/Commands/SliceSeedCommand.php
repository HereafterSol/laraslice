<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use LaraSlice\Generator\SliceSeederService;
use LaraSlice\Core\Discovery\SliceManager;

class SliceSeedCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'slice:seed 
                            {slice? : The name of the slice to seed} 
                            {--domain= : Seed all slices within a domain} 
                            {--count=10 : Number of sample records to generate per model}
                            {--force : Allow seeding demo data in production}';

    protected $description = 'Seed realistic demo and relational data into modular slice tables';

    public function handle(SliceSeederService $seeder, SliceManager $manager): int
    {
        $sliceName = $this->argument('slice');
        $domainName = $this->option('domain');
        $count = (int) $this->option('count');

        if (!$sliceName && !$domainName) {
            $this->error('Please specify either a slice name or --domain= option.');
            $this->line('Example: <fg=yellow>php artisan slice:seed Contacts</> or <fg=yellow>php artisan slice:seed --domain=CRM</>');
            return Command::FAILURE;
        }

        // Demo data in a production database is almost never intended
        if (! $this->confirmToProceed('Seeding fake demo records into a production database')) {
            return Command::FAILURE;
        }

        if ($domainName) {
            $this->info("⚡ Seeding demo data for all slices in domain: [{$domainName}] (count: {$count})...");
            $result = $seeder->seedDomain($domainName, $count);
        } else {
            $this->info("⚡ Seeding demo data for slice: [{$sliceName}] (count: {$count})...");
            $result = $seeder->seedSlice($sliceName, $count);
        }

        if (!$result['success']) {
            $this->error("❌ Seeding failed: " . ($result['message'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $this->info("✅ " . $result['message']);

        if (!empty($result['records'])) {
            $rows = [];
            foreach ($result['records'] as $model => $cnt) {
                $rows[] = [$model, "<fg=green>{$cnt}</>"];
            }
            $this->table(['Model / Table', 'Records Seeded'], $rows);
        }

        return Command::SUCCESS;
    }
}
