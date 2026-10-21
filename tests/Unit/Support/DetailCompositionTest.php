<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * F3: detail pages compose related-record tabs from the manifest.
 * Precedence: declared ui.show.tabs (type: relation) → root model
 * hasMany relations → repeater form fields, deduplicated by relation,
 * with live counts when the record exposes the relation method.
 */
class DetailCompositionTest extends TestCase
{
    public function test_declared_show_tabs_win_and_deduplicate(): void
    {
        $manifest = [
            'ui' => [
                'show' => ['tabs' => [
                    ['handle' => 'overview', 'label' => 'Overview', 'type' => 'fields'],
                    ['handle' => 'lines', 'label' => 'Lines', 'type' => 'relation', 'relation' => 'items'],
                    ['handle' => 'activity', 'label' => 'Activity', 'type' => 'audit'],
                ]],
                'forms' => ['sections' => [
                    ['fields' => [
                        ['handle' => 'items', 'type' => 'repeater', 'label' => 'Items'],
                    ]],
                ]],
            ],
            'models' => [
                ['name' => 'invoice', 'relations' => [
                    ['name' => 'items', 'type' => 'hasMany'],
                    ['name' => 'payments', 'type' => 'hasMany'],
                    ['name' => 'customer', 'type' => 'belongsTo'],
                ]],
            ],
        ];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest);

        $this->assertSame(['items', 'payments'], array_column($tabs, 'relation'));
        $this->assertSame('Lines', $tabs[0]['label'], 'declared tab label wins over relation fallbacks');
        $this->assertSame('Payments', $tabs[1]['label']);
    }

    public function test_repeater_fields_become_tabs_when_nothing_else_declared(): void
    {
        $manifest = [
            'ui' => ['forms' => ['sections' => [
                ['fields' => [
                    ['handle' => 'number', 'type' => 'string'],
                    ['handle' => 'lines', 'type' => 'repeater', 'label' => 'Order Lines'],
                ]],
            ]]],
            'models' => [['name' => 'order', 'relations' => []]],
        ];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest);

        $this->assertCount(1, $tabs);
        $this->assertSame('lines', $tabs[0]['relation']);
        $this->assertSame('Order Lines', $tabs[0]['label']);
    }

    public function test_counts_attach_only_when_relation_method_exists(): void
    {
        $record = new class
        {
            public function items(): object
            {
                // Like an Eloquent relation: count() arrives via __call,
                // not as a real method — the resolver must probe by calling.
                return new class
                {
                    public function __call(string $name, array $arguments): int
                    {
                        return $name === 'count' ? 3 : 0;
                    }
                };
            }
        };

        $manifest = [
            'models' => [['name' => 'invoice', 'relations' => [
                ['name' => 'items', 'type' => 'hasMany'],
                ['name' => 'payments', 'type' => 'hasMany'],
            ]]],
        ];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);

        $this->assertSame(3, $tabs[0]['count']);
        $this->assertArrayNotHasKey('count', $tabs[1], 'missing relation method must not break or fabricate a count');
    }

    public function test_empty_manifest_yields_no_tabs(): void
    {
        $this->assertSame([], DirectoryUi::relatedTabsFromManifest([]));
    }

    public function test_show_stats_compute_counts_sums_and_filters(): void
    {
        $record = new class
        {
            public function tasks(): object
            {
                return new FakeStatQuery([
                    ['status' => 'incomplete'],
                    ['status' => 'incomplete'],
                    ['status' => 'completed'],
                ]);
            }

            public function projectTimeLogs(): object
            {
                return new FakeStatQuery([
                    ['total_hours' => 4.5],
                    ['total_hours' => 6.5],
                ]);
            }

            public function broken(): object
            {
                throw new \RuntimeException('relation exploded');
            }
        };

        $manifest = ['ui' => ['show' => ['stats' => [
            ['handle' => 'open_tasks', 'label' => 'Open Tasks', 'relation' => 'tasks', 'aggregate' => 'count', 'filter' => ['status' => ['incomplete']]],
            ['handle' => 'all_tasks', 'label' => 'Tasks', 'relation' => 'tasks', 'aggregate' => 'count'],
            ['handle' => 'hours', 'label' => 'Hours Logged', 'relation' => 'projectTimeLogs', 'aggregate' => 'sum', 'column' => 'total_hours'],
            ['handle' => 'avg_hours', 'label' => 'Avg Hours', 'relation' => 'projectTimeLogs', 'aggregate' => 'avg', 'column' => 'total_hours'],
            ['handle' => 'broken', 'label' => 'Broken', 'relation' => 'broken', 'aggregate' => 'count'],
            ['handle' => 'ghost', 'label' => 'Ghost', 'relation' => 'missingRelation', 'aggregate' => 'count'],
        ]]]];

        $stats = DirectoryUi::showStatsFromManifest($manifest, $record);

        $this->assertSame(['open_tasks', 'all_tasks', 'hours', 'avg_hours'], array_column($stats, 'handle'), 'broken and missing relations are skipped, not fatal');
        $this->assertSame('2', $stats[0]['value']);
        $this->assertSame('3', $stats[1]['value']);
        $this->assertSame('11', $stats[2]['value'], 'whole sums render without decimals');
        $this->assertSame('5.5', $stats[3]['value']);
    }

    public function test_show_stats_empty_without_record_or_defs(): void
    {
        $this->assertSame([], DirectoryUi::showStatsFromManifest(['ui' => ['show' => ['stats' => [['relation' => 'tasks']]]]], null));
        $this->assertSame([], DirectoryUi::showStatsFromManifest([], new class
        {
        }));
    }
}

/** Minimal relation-query double: filters and aggregates in memory. */
class FakeStatQuery
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows) {}

    public function whereIn(string $column, array $values): static
    {
        $this->rows = array_values(array_filter($this->rows, fn ($r) => in_array($r[$column] ?? null, $values, true)));

        return $this;
    }

    public function where(string $column, mixed $value): static
    {
        $this->rows = array_values(array_filter($this->rows, fn ($r) => ($r[$column] ?? null) === $value));

        return $this;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function sum(string $column): float
    {
        return array_sum(array_column($this->rows, $column));
    }

    public function avg(string $column): float
    {
        return $this->count() ? $this->sum($column) / $this->count() : 0.0;
    }
}
