<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;

class SliceCacheCommand extends Command
{
    protected $signature = 'slice:cache';

    protected $description = 'Create a slice discovery cache file for faster boot times in production';

    public function handle(SliceManager $manager): int
    {
        $this->info('Discovering slices...');
        $manager->clearCache();
        $manager->discover();

        $count = $manager->cacheSlices();

        $this->info("Successfully cached {$count} slice(s) to [{$manager->getCachedSlicesPath()}].");

        return self::SUCCESS;
    }
}
