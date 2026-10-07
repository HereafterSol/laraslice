<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Generator\SliceSeederService;
use LaraSlice\Core\Discovery\SliceManager;

class SliceWipeCommand extends Command
{
    protected $signature = 'slice:wipe 
                            {slice? : The name of the slice to wipe} 
                            {--domain= : Wipe all slices within a domain} 
                            {--force : Skip confirmation prompt}';

    protected $description = 'Wipe (truncate) all records from tables of a slice or domain';

    public function handle(SliceSeederService $seeder, SliceManager $manager): int
    {
        $sliceName = $this->argument('slice');
        $domainName = $this->option('domain');
        $force = $this->option('force');

        if (!$sliceName && !$domainName) {
            $this->error('Please specify either a slice name or --domain= option.');
            $this->line('Example: <fg=yellow>php artisan slice:wipe Contacts --force</>');
            return Command::FAILURE;
        }

        $target = $domainName ? "all slices in domain [{$domainName}]" : "slice [{$sliceName}]";

        if (!$force && !$this->confirm("⚠️ Are you sure you want to WIPE all records from {$target}? This cannot be undone!", false)) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        try {
            if ($domainName) {
                $this->info("🧹 Wiping table data for domain: [{$domainName}]...");
                $result = $seeder->wipeDomain($domainName);
            } else {
                $this->info("🧹 Wiping table data for slice: [{$sliceName}]...");
                $result = $seeder->wipeSlice($sliceName);
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->error('❌ ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (!$result['success']) {
            $this->error("❌ Wipe failed: " . ($result['message'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $this->info("✅ " . $result['message']);
        if (!empty($result['tables'])) {
            $this->line("Truncated tables: <fg=cyan>" . implode(', ', $result['tables']) . "</>");
        }

        return Command::SUCCESS;
    }
}
