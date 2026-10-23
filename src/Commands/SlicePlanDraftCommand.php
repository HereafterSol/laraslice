<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Support\SystemMind;

class SlicePlanDraftCommand extends Command
{
    protected $signature = 'slice:plan:draft {--force : Report what would be drafted without writing}';

    protected $description = 'Draft the Mind (porting/system.yaml) and Body drafts (porting/ui-spec/*.ui.yaml) from the discovered slices — R6 porter drafts for Plan mode review. Existing files are never overwritten.';

    public function handle(): int
    {
        if ($this->option('force')) {
            $this->warn('--force is a dry report in this command: drafting never overwrites existing files.');
        }
        $result = SystemMind::draftFromApp();
        $this->info($result['mind'] ? 'Mind drafted at porting/system.yaml' : 'Mind already exists — left untouched.');
        $this->info("Body drafts written: {$result['bodies']} (existing drafts kept).");
        $this->line('Review and complete them in the Studio Plan mode. Drafted content is marked [draft].');

        return self::SUCCESS;
    }
}
