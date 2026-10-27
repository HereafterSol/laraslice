<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * S-UI 3: declared overview widgets (ui.show.widgets) — gauge
 * math with filtered relation aggregates, and chart grouping
 * that follows the declared label order. Uncomputable widgets
 * are skipped, never misreported.
 */
class ShowWidgetsTest extends TestCase
{
    private function record(): WidgetRecord
    {
        return new WidgetRecord([
            ['board_column_id' => 3],
            ['board_column_id' => 4],
            ['board_column_id' => 4],
            ['board_column_id' => 1],
            ['board_column_id' => 4],
        ]);
    }

    public function test_gauge_computes_filtered_value_over_total(): void
    {
        $manifest = ['ui' => ['show' => ['widgets' => [[
            'handle' => 'task_completion', 'type' => 'gauge', 'title' => 'Task Completion',
            'value' => ['relation' => 'tasks', 'aggregate' => 'count', 'filter' => ['board_column_id' => 4]],
            'max' => ['relation' => 'tasks', 'aggregate' => 'count'],
        ]]]]];

        $widgets = DirectoryUi::showWidgetsFromManifest($manifest, $this->record());
        $this->assertCount(1, $widgets);
        $this->assertSame(3.0, $widgets[0]['value']);
        $this->assertSame(5.0, $widgets[0]['max']);
        $this->assertSame(60.0, $widgets[0]['percent']);
        $this->assertSame('3 / 5', $widgets[0]['display']);
    }

    public function test_chart_groups_follow_declared_label_order(): void
    {
        $manifest = ['ui' => ['show' => ['widgets' => [[
            'handle' => 'by_column', 'type' => 'chart', 'chart' => 'donut', 'title' => 'By Column',
            'relation' => 'tasks', 'group_by' => 'board_column_id',
            'labels' => [1 => 'Incomplete', 2 => 'To Do', 3 => 'Doing', 4 => 'Completed'],
        ]]]]];

        $widgets = DirectoryUi::showWidgetsFromManifest($manifest, $this->record());
        $this->assertSame(['Incomplete', 'Doing', 'Completed'], $widgets[0]['labels']);
        $this->assertSame([1, 1, 3], $widgets[0]['series']);
    }

    public function test_field_gauge_stats_measures_and_rail_list(): void
    {
        $manifest = ['ui' => ['show' => ['widgets' => [
            [
                'handle' => 'progress', 'type' => 'gauge', 'title' => 'Progress',
                'value' => ['field' => 'completion'], 'max' => 100,
            ],
            [
                'handle' => 'statistics', 'type' => 'stats', 'title' => 'Statistics', 'placement' => 'rail',
                'measures' => [
                    ['label' => 'Done', 'relation' => 'tasks', 'aggregate' => 'count', 'filter' => ['board_column_id' => 4]],
                    ['label' => 'Remaining', 'minus' => [
                        ['relation' => 'tasks', 'aggregate' => 'count'],
                        ['relation' => 'tasks', 'aggregate' => 'count', 'filter' => ['board_column_id' => 4]],
                    ]],
                ],
            ],
            [
                'handle' => 'recent', 'type' => 'list', 'title' => 'Recent', 'placement' => 'rail',
                'relation' => 'tasks', 'label_field' => 'board_column_id', 'limit' => 2,
            ],
        ]]]];

        $widgets = DirectoryUi::showWidgetsFromManifest($manifest, $this->record());
        $this->assertSame(['progress', 'statistics', 'recent'], array_column($widgets, 'handle'));
        $this->assertSame('main', $widgets[0]['placement']);
        $this->assertSame(88.0, $widgets[0]['percent']);
        $this->assertSame('rail', $widgets[1]['placement']);
        $this->assertSame(
            [['label' => 'Done', 'value' => '3'], ['label' => 'Remaining', 'value' => '2']],
            $widgets[1]['rows']
        );
        $this->assertSame('rail', $widgets[2]['placement']);
        $this->assertCount(2, $widgets[2]['rows']);
    }

    public function test_broken_widget_is_skipped(): void
    {
        $manifest = ['ui' => ['show' => ['widgets' => [
            ['handle' => 'bad', 'type' => 'gauge', 'value' => ['relation' => 'missing', 'aggregate' => 'count'], 'max' => 10],
            ['handle' => 'ok', 'type' => 'gauge', 'title' => 'Ok', 'value' => ['relation' => 'tasks', 'aggregate' => 'count'], 'max' => 10],
        ]]]];

        $widgets = DirectoryUi::showWidgetsFromManifest($manifest, $this->record());
        $this->assertSame(['ok'], array_column($widgets, 'handle'));
    }
}

class WidgetRecord
{
    public mixed $completion = 88;

    public function __construct(private array $rows) {}

    public function tasks(): WidgetQuery
    {
        return new WidgetQuery($this->rows);
    }
}

class WidgetQuery
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

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        usort($this->rows, fn ($a, $b) => (($a[$column] ?? 0) <=> ($b[$column] ?? 0)) * ($direction === 'desc' ? -1 : 1));

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

    public function limit(int $n): static
    {
        $this->rows = array_slice($this->rows, 0, $n);

        return $this;
    }

    public function get(): array
    {
        return array_map(fn ($r) => (object) $r, $this->rows);
    }
}
