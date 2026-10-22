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
            ['id' => 2, 'heading' => 'Beta', 'board_column_id' => 4, 'due_date' => Carbon::now()->addDay()->toDateTimeString()],
            ['id' => 3, 'heading' => 'Gamma', 'board_column_id' => 4, 'due_date' => Carbon::now()->subWeeks(2)->toDateTimeString()],
            ['id' => 4, 'heading' => 'Delta', 'board_column_id' => 3, 'due_date' => null],
        ];
    }

    private function manifest(array $widgets): array
    {
        return ['ui' => ['dashboard' => ['widgets' => $widgets]]];
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
