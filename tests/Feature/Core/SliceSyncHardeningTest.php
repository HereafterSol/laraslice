<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use LaraSlice\Tests\TestCase;

/**
 * slice:sync hardening (2 Nov 2026): a dry run is PURE —
 * it reports pending work but performs no migrations, no
 * workflow syncs, no manifest writes and no declaration
 * history entries (the pre-hardening command rewrote
 * every slice.yaml and synced workflows even with
 * --dry-run).
 */
class SliceSyncHardeningTest extends TestCase
{
    public function test_dry_run_is_pure_and_reports_itself(): void
    {
        $indexPath = base_path('porting/history/index.json');
        $before = is_file($indexPath) ? sha1_file($indexPath) : null;

        $code = Artisan::call('slice:sync', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString('Purity', $output);

        $after = is_file($indexPath) ? sha1_file($indexPath) : null;
        $this->assertSame($before, $after, 'a dry run must not record declaration history');
    }
}
