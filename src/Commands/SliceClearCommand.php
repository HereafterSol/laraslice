<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;

class SliceClearCommand extends Command
{
    protected $signature = 'slice:clear';

    protected $description = 'Remove the slice discovery cache file';

    public function handle(SliceManager $manager): int
    {
        $manager->clearCache();
        $this->info('Slice discovery cache cleared successfully.');

        return self::SUCCESS;
    }
}
