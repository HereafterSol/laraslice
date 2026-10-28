<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\BladeHubExtractor;
use LaraSlice\Tests\TestCase;

final class BladeHubExtractorTest extends TestCase
{
    private BladeHubExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new BladeHubExtractor;
    }

    public function test_extracts_tabs_from_blade_markup(): void
    {
        $blade = <<<'BLADE'
        <ul class="-primary">
            <li><x-tab :href="route('employees.show', $employee->id)" :text="__('modules.employees.profile')" class="profile" /></li>
            <li><x-tab :href="route('employees.show', $employee->id) . '?tab=projects'" :text="__('app.menu.projects')" ajax="false" class="projects" /></li>
            <li><x-tab :href="route('employees.show', $employee->id) . '?tab=tasks'" :text="__('app.menu.tasks')" ajax="false" class="tasks" /></li>
            <li><x-tab :href="route('employees.show', $employee->id) . '?tab=leaves-quota'" :text="__('app.menu.leavesQuota')" class="leaves-quota" /></li>
            <li><x-tab :href="route('employees.show', $employee->id) . '?tab=timelogs'" :text="__('app.menu.timeLogs')" ajax="false" class="timelogs" /></li>
            <li><x-tab :href="route('employees.show', $employee->id) . '?tab=activity'" :text="__('modules.employees.activity')" class="activity" /></li>
        </ul>
BLADE;

        $tabs = $this->extractor->extractTabs($blade);
        $this->assertCount(6, $tabs);
        $this->assertSame('profile', $tabs[0]['handle']);
        $this->assertSame('fields', $tabs[0]['type']);
        $this->assertSame('projects', $tabs[1]['handle']);
        $this->assertSame('relation', $tabs[1]['type']);
        $this->assertSame('projects', $tabs[1]['relation']);
        $this->assertSame('leaves_quota', $tabs[3]['handle']);
        $this->assertSame('employeeLeaveQuotas', $tabs[3]['relation']);
        $this->assertSame('timelogs', $tabs[4]['handle']);
        $this->assertSame('projectTimeLogs', $tabs[4]['relation']);
        $this->assertSame('activity', $tabs[5]['handle']);
        $this->assertSame('audit', $tabs[5]['type']);
    }

    public function test_extracts_stats_and_widgets(): void
    {
        $blade = <<<'BLADE'
        <div class="card-footer">
            <span>
                <label>@lang('app.openTasks')</label>
                <p>{{ $employee->open_tasks_count }}</p>
            </span>
            <span>
                <label>@lang('app.menu.projects')</label>
                <p>{{ $employee->member_count }}</p>
            </span>
            <span>
                <label>@lang('modules.employees.hoursLogged')</label>
                <p>{{ $hoursLogged }}</p>
            </span>
            <span>
                <label>@lang('app.menu.tickets')</label>
                <p>{{ $employee->agents_count }}</p>
            </span>
        </div>
        <x-pie-chart id="task-chart" />
        <x-cards.data :title="__('modules.appreciations.appreciation')">
            <span>Award</span>
        </x-cards.data>
        <x-cards.widget :title="__('modules.dashboard.lateAttendanceMark')" />
BLADE;

        $stats = $this->extractor->extractStats($blade);
        $this->assertCount(4, $stats);
        $this->assertSame('open_tasks', $stats[0]['handle']);
        $this->assertSame('tasks', $stats[0]['relation']);
        $this->assertSame('projects', $stats[1]['handle']);
        $this->assertSame('projects', $stats[1]['relation']);
        $this->assertSame('hours_logged', $stats[2]['handle']);
        $this->assertSame('projectTimeLogs', $stats[2]['relation']);
        $this->assertSame('tickets', $stats[3]['handle']);
        $this->assertSame('tickets', $stats[3]['relation']);

        $widgets = $this->extractor->extractWidgets($blade);
        $this->assertNotEmpty($widgets);
        $handles = array_column($widgets, 'handle');
        $this->assertContains('tasks_by_column', $handles);
        $this->assertContains('appreciations', $handles);
        $this->assertContains('attendance_leaves', $handles);
    }
}
