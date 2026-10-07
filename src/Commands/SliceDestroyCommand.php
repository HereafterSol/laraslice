<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;

class SliceDestroyCommand extends Command
{
    protected $signature = 'slice:destroy 
                            {slice? : The name of the slice to destroy} 
                            {--domain= : Destroy all slices within a domain} 
                            {--mode=complete : Destruction mode (complete, code_only, db_only, wipe_data)} 
                            {--force : Skip confirmation prompt}';

    protected $description = 'Safely tear down a slice or domain (drop tables, clean migrations, delete files)';

    public function handle(SliceManager $manager): int
    {
        $sliceName = $this->argument('slice');
        $domainName = $this->option('domain');
        $mode = $this->option('mode') ?: 'complete';
        $force = $this->option('force');

        if (!in_array($mode, ['complete', 'code_only', 'db_only', 'wipe_data'])) {
            $this->error("Invalid mode [{$mode}]. Allowed modes: complete, code_only, db_only, wipe_data");
            return Command::FAILURE;
        }

        if (!$sliceName && !$domainName) {
            $this->error('Please specify either a slice name or --domain= option.');
            $this->line('Example: <fg=yellow>php artisan slice:destroy Tests --mode=complete --force</>');
            return Command::FAILURE;
        }

        $target = $domainName ? "all slices in domain [{$domainName}]" : "slice [{$sliceName}]";

        if (!$force && !$this->confirm("🚨 DANGER: Are you sure you want to DESTROY {$target} (Mode: {$mode})?", false)) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        try {
            if ($domainName) {
                $this->warn("🚨 Destroying domain: [{$domainName}] with mode: {$mode}...");
                $result = $manager->destroyDomain($domainName, $mode);
            } else {
                $this->warn("🚨 Destroying slice: [{$sliceName}] with mode: {$mode}...");
                $result = $manager->destroySlice($sliceName, $mode);
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->error('❌ ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (!$result['success']) {
            $this->error("❌ Destruction failed: " . ($result['message'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $this->info("✅ " . $result['message']);
        return Command::SUCCESS;
    }
}
