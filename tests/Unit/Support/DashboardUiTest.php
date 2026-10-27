<?php

namespace LaraSlice\Tests\Unit\Support;

use Illuminate\Support\Carbon;
use LaraSlice\Support\DashboardUi;
use PHPUnit\Framework\TestCase;

/**
 * S-UI 4: declared dashboard widgets (ui.dashboard.widgets)
 * aggregate per slice — counters with stats filter semantics,
 * recent lists with show links, week strips bucketed Mon–Sun,
 * charts in declared label order. Broken widgets are skipped.
 */
class DashboardUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DashboardFakeModel::$rows = [
            ['id' => 1, 'heading' => 'Alpha', 'board_column_id' => 2, 'due_date' => Carbon::now()->toDateTimeString()],
            // Monday of the current week: always inside the
            // Mon–Sun week strip (addDay() crossed into next
            // week when the suite ran on a Sunday).
            ['id' => 2, 'heading' => 'Beta', 'board_column_id' => 4, 'due_date' => Carbon::now()->startOfWeek(Carbon::MONDAY)->setTime(10, 0)->toDateTimeString()],
            ['id' => 3, 'heading' => 'Gamma', 'board_column_id' => 4, 'due_date' => Carbon::now()->subWeeks(2)->toDateTimeString()],
            ['id' => 4, 'heading' => 'Delta', 'board_column_id' => 3, 'due_date' => null],
        ];
    }

    private function manifest(array $widgets): array
    {
        return ['ui' => ['dashboard' => ['widgets' => $widgets]]];
    }

    public function test_people_dates_projects_recurrences_into_the_window(): void
    {
        $today = Carbon::today();
        DashboardFakeModel::$rows = [
            // Birthday in 5 days (different birth year): inside.
            ['id' => 1, 'date_of_birth' => $today->copy()->addDays(5)->subYears(30)->toDateString(), 'user' => ['name' => 'Soon']],
            // Birthday today: inside, labelled Today.
            ['id' => 2, 'date_of_birth' => $today->copy()->subYears(41)->toDateString(), 'user' => ['name' => 'Today Person']],
            // Birthday in 60 days: outside a 30-day window.
            ['id' => 3, 'date_of_birth' => $today->copy()->addDays(60)->subYears(22)->toDateString(), 'user' => ['name' => 'Far']],
            // Birthday yesterday: rolls to next year, outside.
            ['id' => 4, 'date_of_birth' => $today->copy()->subDay()->subYears(28)->toDateString(), 'user' => ['name' => 'Past']],
            // No date at all: skipped silently.
            ['id' => 5, 'date_of_birth' => null, 'user' => ['name' => 'Unknown']],
        ];

        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'birthdays', 'type' => 'people_dates', 'title' => 'Birthdays', 'field' => 'date_of_birth', 'days' => 30],
        ]), DashboardFakeModel::class, ['index' => '/employees', 'show' => '/employees/{id}']);

        $this->assertCount(1, $widgets);
        $this->assertSame(['Today Person', 'Soon'], array_column($widgets[0]['rows'], 'label'));
        $this->assertStringContainsString('Today', $widgets[0]['rows'][0]['meta']);
        $this->assertStringContainsString('in 5d', $widgets[0]['rows'][1]['meta']);
        $this->assertSame('/employees/2', $widgets[0]['rows'][0]['url']);
    }

    public function test_counter_list_week_and_chart(): void
    {
        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'open', 'type' => 'counter', 'title' => 'Open', 'filter' => ['board_column_id' => ['!4']]],
            ['handle' => 'recent', 'type' => 'list', 'title' => 'Recent', 'label_field' => 'heading', 'limit' => 2],
            ['handle' => 'week', 'type' => 'week', 'title' => 'Week', 'date_field' => 'due_date'],
            ['handle' => 'chart', 'type' => 'chart', 'title' => 'By Column', 'group_by' => 'board_column_id',
                'labels' => [2 => 'To Do', 3 => 'Doing', 4 => 'Completed']],
        ]), DashboardFakeModel::class, ['index' => '/tasks', 'show' => '/tasks/{id}']);

        $this->assertSame(['open', 'recent', 'week', 'chart'], array_column($widgets, 'handle'));
        $this->assertSame('2', $widgets[0]['value']);
        $this->assertSame('/tasks', $widgets[0]['link']);
        $this->assertSame(['Delta', 'Gamma'], array_column($widgets[1]['rows'], 'label'));
        $this->assertSame('/tasks/4', $widgets[1]['rows'][0]['url']);
        $week = $widgets[2]['days'];
        $this->assertCount(7, $week);
        $this->assertSame(2, array_sum(array_column($week, 'count')));
        $this->assertSame(1, count(array_filter($week, fn ($d) => $d['today'])));
        $this->assertSame(['To Do', 'Doing', 'Completed'], $widgets[3]['labels']);
        $this->assertSame([1, 1, 2], $widgets[3]['series']);
    }

    public function test_today_widget_includes_date_only_values(): void
    {
        DashboardFakeModel::$rows = [
            ['id' => 1, 'heading' => 'Timed', 'due_date' => Carbon::today()->setTime(9, 30)->toDateTimeString()],
            ['id' => 2, 'heading' => 'DateOnly', 'due_date' => Carbon::today()->toDateString()],
            ['id' => 3, 'heading' => 'Tomorrow', 'due_date' => Carbon::tomorrow()->toDateTimeString()],
        ];
        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'due_today', 'type' => 'today', 'title' => 'Due Today', 'date_field' => 'due_date', 'label_field' => 'heading'],
        ]), DashboardFakeModel::class, ['index' => '/tasks', 'show' => '/tasks/{id}']);

        $this->assertCount(1, $widgets);
        $rows = $widgets[0]['rows'];
        $this->assertSame(['DateOnly', 'Timed'], array_column($rows, 'label'));
        $this->assertSame('', $rows[0]['meta']);
        $this->assertSame('09:30', $rows[1]['meta']);
    }

    public function test_own_scoped_widget_without_a_user_is_skipped_never_widened(): void
    {
        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'mine', 'type' => 'counter', 'title' => 'Mine', 'scope' => 'own', 'own' => ['field' => 'user_id']],
            ['handle' => 'all', 'type' => 'counter', 'title' => 'All'],
        ]), DashboardFakeModel::class);

        $this->assertSame(['all'], array_column($widgets, 'handle'));
    }

    public function test_broken_widgets_are_skipped(): void
    {
        $widgets = DashboardUi::widgetsFromManifest($this->manifest([
            ['handle' => 'bad', 'type' => 'week', 'title' => 'Bad'],
            ['handle' => 'ok', 'type' => 'counter', 'title' => 'All'],
        ]), DashboardFakeModel::class);

        $this->assertSame(['ok'], array_column($widgets, 'handle'));
        $this->assertSame('4', $widgets[0]['value']);
    }
}

class DashboardFakeModel
{
    public static array $rows = [];

    public static function query(): DashboardFakeQuery
    {
        return new DashboardFakeQuery(static::$rows);
    }
}

class DashboardFakeQuery
{
    public function __construct(private array $rows) {}

    public function where(string $column, mixed $value): static
    {
        $this->rows = array_values(array_filter($this->rows, fn ($r) => ($r[$column] ?? null) == $value));

        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        $this->rows = array_values(array_filter($this->rows, fn ($r) => in_array($r[$column] ?? null, $values)));

        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        $this->rows = array_values(array_filter($this->rows, fn ($r) => ! in_array($r[$column] ?? null, $values)));

        return $this;
    }

    public function whereBetween(string $column, array $range): static
    {
        $this->rows = array_values(array_filter($this->rows, fn ($r) => ($r[$column] ?? null) !== null && $r[$column] >= $range[0] && $r[$column] <= $range[1]));

        return $this;
    }

    public function whereDate(string $column, mixed $date): static
    {
        $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
        $this->rows = array_values(array_filter($this->rows, fn ($r) => ($r[$column] ?? null) !== null && substr((string) $r[$column], 0, 10) === $day));

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        usort($this->rows, fn ($a, $b) => $direction === 'desc' ? ($b[$column] <=> $a[$column]) : ($a[$column] <=> $b[$column]));

        return $this;
    }

    public function limit(int $n): static
    {
        $this->rows = array_slice($this->rows, 0, $n);

        return $this;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function sum(string $column): float
    {
        return (float) array_sum(array_column($this->rows, $column));
    }

    public function avg(string $column): float
    {
        return $this->count() ? $this->sum($column) / $this->count() : 0.0;
    }

    public function get(): array
    {
        return array_map(fn ($r) => (object) $r, $this->rows);
    }

    public function pluck(string $column): array
    {
        return array_column($this->rows, $column);
    }
}
