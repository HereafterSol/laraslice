<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;

class SliceCacheCommand extends Command
{
    protected $signature = 'slice:cache';

    protected $description = 'Create a slice discovery cache file for faster boot times in production (paths stored relative to base_path for portable clones)';

    public function handle(SliceManager $manager): int
    {
        $this->info('Discovering slices...');
        $manager->clearCache();
        $manager->discover(true); // entry point: operate on the slices as they are on disk now, not a boot-time registry

        $count = $manager->cacheSlices();
        $discovered = count($manager->discoveredSlices());

        $this->info("Successfully cached {$count} of {$discovered} discovered slice(s) to [{$manager->getCachedSlicesPath()}].");

        return self::SUCCESS;
    }
}
