<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use LaraSlice\Core\Audit\AuditLogger;
use LaraSlice\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class AuditConfigTest extends TestCase
{
    public function test_disabled_audit_writes_nothing(): void
    {
        config(['laraslice.audit.enabled' => false]);

        AuditLogger::record(['slice' => 'Users', 'action' => 'updated']);

        $this->assertSame(0, DB::table(AuditLogger::TABLE_NAME)->count());
    }

    public function test_prune_uses_the_configured_retention_when_days_is_not_given(): void
    {
        config(['laraslice.audit.retention_days' => 10]);
        foreach ([5, 20] as $age) {
            DB::table(AuditLogger::TABLE_NAME)->insert(['slice' => 'Users', 'action' => 'updated', 'created_at' => now()->subDays($age)]);
        }

        $this->artisan('laraslice:audit:prune', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, DB::table(AuditLogger::TABLE_NAME)->count());
    }

    public function test_auto_prune_is_not_scheduled_by_default(): void
    {
        $this->assertFalse($this->pruneIsScheduled());
    }

    #[DefineEnvironment('enableAutoPrune')]
    public function test_auto_prune_schedules_a_daily_prune(): void
    {
        $this->assertTrue($this->pruneIsScheduled());
    }

    protected function enableAutoPrune($app): void
    {
        $app['config']->set('laraslice.audit.auto_prune', true);
    }

    private function pruneIsScheduled(): bool
    {
        return collect(app(Schedule::class)->events())
            ->contains(fn ($event) => str_contains((string) $event->command, 'laraslice:audit:prune'));
    }
}
