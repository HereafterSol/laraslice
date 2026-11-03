<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use LaraSlice\Tests\TestCase;

class SliceOperateCommandTest extends TestCase
{
    public function test_health_reports_the_operate_checks(): void
    {
        Artisan::call('slice:operate', ['action' => 'health']);
        $output = Artisan::output();
        $this->assertStringContainsString('Database', $output);
        $this->assertStringContainsString('Slices', $output);
        $this->assertStringContainsString('Backups:', $output);
    }

    public function test_backups_action_succeeds_with_none_on_record(): void
    {
        $this->artisan('slice:operate', ['action' => 'backups'])
            ->expectsOutputToContain('No backups yet')
            ->assertSuccessful();
    }

    public function test_unknown_action_is_invalid(): void
    {
        $this->artisan('slice:operate', ['action' => 'dance'])
            ->assertExitCode(2);
    }
}
