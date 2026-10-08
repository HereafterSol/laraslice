<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;

class SliceToggleCommand extends Command
{
    protected $signature = 'slice:toggle 
                            {slice? : The name of the slice to toggle} 
                            {--domain= : Toggle all slices within a domain} 
                            {--enable : Force enable} 
                            {--disable : Force disable}';

    protected $description = 'Toggle activation status of a slice or domain (hide/show in navigation)';

    public function handle(SliceManager $manager): int
    {
        $sliceName = $this->argument('slice');
        $domainName = $this->option('domain');
        $forceEnable = $this->option('enable');
        $forceDisable = $this->option('disable');

        if (! $sliceName && ! $domainName) {
            $this->error('Please specify either a slice name or --domain= option.');
            $this->line('Example: <fg=yellow>php artisan slice:toggle Contacts --disable</>');

            return Command::FAILURE;
        }

        if ($domainName) {
            $domainSlices = $manager->getDomainSlices($domainName);
            if (empty($domainSlices)) {
                $this->error("No slices found in domain [{$domainName}].");

                return Command::FAILURE;
            }

            $currentActive = collect($domainSlices)->some(fn ($s) => $s->active !== false);
            $targetActive = $forceEnable ? true : ($forceDisable ? false : ! $currentActive);

            try {
                $updated = $manager->toggleDomain($domainName, $targetActive);
                $statusStr = $targetActive ? '<fg=green>Enabled</>' : '<fg=red>Disabled (Hidden)</>';
                $this->info("✅ Domain [{$domainName}] is now {$statusStr} (".count($updated).' slices updated).');

                return Command::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('❌ Failed to toggle domain: '.$e->getMessage());

                return Command::FAILURE;
            }
        }

        $slice = $manager->getSlice($sliceName);
        if (! $slice) {
            $this->error("Slice [{$sliceName}] not found.");

            return Command::FAILURE;
        }

        $targetActive = $forceEnable ? true : ($forceDisable ? false : ! $slice->active);

        try {
            $newActive = $manager->toggleSlice($sliceName, $targetActive);
            $statusStr = $newActive ? '<fg=green>Enabled</>' : '<fg=red>Disabled (Hidden)</>';
            $this->info("✅ Slice [{$sliceName}] is now {$statusStr}.");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Failed to toggle slice: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
