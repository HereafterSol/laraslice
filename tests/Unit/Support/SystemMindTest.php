<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\SystemMind;
use PHPUnit\Framework\TestCase;

class SystemMindTest extends TestCase
{
    public function test_form_round_trip_preserves_untouched_sections(): void
    {
        $mind = [
            'system' => ['name' => 'Ops', 'purpose' => 'Old purpose.'],
            'actors' => [['name' => 'Admin', 'kind' => 'internal', 'description' => 'Runs it.']],
            'roles' => ['model' => 'role-clubs-with-user-overrides', 'clubs' => [['name' => 'Admin', 'permissions' => ['hr.*']]], 'overrides' => 'Per-person grants.'],
            'journeys' => [['name' => 'Hire', 'actor' => 'Admin', 'steps' => ['Post job', 'Interview'], 'outcome' => 'Hired.']],
            'operations' => ['backups' => 'daily'],
            'navigation' => [['label' => 'HR']],
        ];

        $form = SystemMind::toForm($mind);
        $this->assertSame('Old purpose.', $form['purpose']);
        $this->assertStringContainsString('Admin | hr.*', $form['roles']);
        $this->assertStringContainsString('Post job > Interview', $form['journeys']);

        $form['purpose'] = 'New purpose.';
        $form['roles'] = "Admin | hr.*, work.*\nEmployee | tasks.view-own";
        $updated = SystemMind::applyForm($mind, $form);

        $this->assertSame('New purpose.', $updated['system']['purpose']);
        $this->assertSame(['hr.*', 'work.*'], $updated['roles']['clubs'][0]['permissions']);
        $this->assertSame('Employee', $updated['roles']['clubs'][1]['name']);
        $this->assertSame('Per-person grants.', $updated['roles']['overrides'], 'untouched sections survive');
        $this->assertSame([['label' => 'HR']], $updated['navigation']);
    }

    public function test_diff_marks_changes_and_render_round_trips(): void
    {
        $a = ['system' => ['purpose' => 'One.']];
        $b = ['system' => ['purpose' => 'Two.']];
        $diff = SystemMind::diff(SystemMind::render($a), SystemMind::render($b));
        $this->assertStringContainsString('- ', $diff);
        $this->assertStringContainsString('+ ', $diff);
        $this->assertStringContainsString('Two.', $diff);
        $this->assertSame('', SystemMind::diff('x', 'x'));
    }
}
