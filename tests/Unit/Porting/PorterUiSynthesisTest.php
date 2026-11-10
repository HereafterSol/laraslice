<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\SlicePortService;
use LaraSlice\Tests\TestCase;

/**
 * Cold-port UI synthesis (evaluation finding B4): the porter
 * must not ship raw-schema directories and stat-less hubs.
 *
 *  - internal/secret fields (slack_username, *_token,
 *    *_secret, …) never become directory columns;
 *  - a hub-shaped slice (2+ hasMany relations) gets declared
 *    ui.show.stats — the work quartet first, a time-log
 *    relation with total_hours as a sum — because the runtime
 *    renders the KPI strip from the declaration and a cold
 *    port previously declared none.
 */
final class PorterUiSynthesisTest extends TestCase
{
    private function ui(array $fields, array $relations): array
    {
        return app(SlicePortService::class)
            ->buildUiFromInspectedFields('EmployeeDetails', $fields, 'company_id', $relations);
    }

    public function test_internal_fields_never_become_directory_columns(): void
    {
        $ui = $this->ui([
            ['handle' => 'user_id', 'type' => 'integer'],
            ['handle' => 'employee_id', 'type' => 'string'],
            ['handle' => 'slack_username', 'type' => 'string'],
            ['handle' => 'api_token', 'type' => 'string'],
            ['handle' => 'two_factor_secret', 'type' => 'string'],
            ['handle' => 'hourly_rate', 'type' => 'decimal'],
        ], []);

        $handles = array_column($ui['directory']['columns'] ?? [], 'handle');
        $this->assertContains('employee_id', $handles);
        $this->assertNotContains('slack_username', $handles);
        $this->assertNotContains('api_token', $handles);
        $this->assertNotContains('two_factor_secret', $handles);
    }

    public function test_hub_stats_are_declared_in_work_quartet_order(): void
    {
        $relations = [
            ['name' => 'tickets', 'type' => 'hasMany', 'table' => 'tickets'],
            ['name' => 'projectTimeLogs', 'type' => 'hasMany', 'table' => 'project_time_logs', 'fields' => [['handle' => 'total_hours'], ['handle' => 'start_time']]],
            ['name' => 'projects', 'type' => 'hasMany', 'table' => 'projects'],
            ['name' => 'tasks', 'type' => 'hasMany', 'table' => 'tasks'],
        ];

        $ui = $this->ui([['handle' => 'user_id', 'type' => 'integer']], $relations);
        $stats = $ui['show']['stats'] ?? [];

        $this->assertSame(['tasks', 'projects', 'projectTimeLogs', 'tickets'], array_column($stats, 'relation'));
        $this->assertSame('sum', $stats[2]['aggregate'] ?? null);
        $this->assertSame('total_hours', $stats[2]['column'] ?? null);
        $this->assertSame('Hours Logged', $stats[2]['label'] ?? null);
    }

    public function test_non_hub_slices_declare_no_stats(): void
    {
        $ui = $this->ui(
            [['handle' => 'name', 'type' => 'string']],
            [['name' => 'items', 'type' => 'hasMany', 'table' => 'invoice_items']]
        );

        $this->assertArrayNotHasKey('stats', $ui['show'] ?? []);
    }
}
