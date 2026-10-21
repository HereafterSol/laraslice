<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * F3c extension: ui.show.stats filters support '!'-prefixed values
 * as exclusions (whereNotIn) — needed for definitions like the
 * source application's open tasks (board column is not the
 * complete column), which no inclusion filter can express.
 */
class ShowStatsFilterTest extends TestCase
{
    public function test_exclusion_filter_counts_non_matching_rows(): void
    {
        $record = new ExclusionStatRecord([
            ['board_column_id' => 2, 'status' => 'incomplete'],
            ['board_column_id' => 4, 'status' => 'incomplete'], // on the complete column: excluded
            ['board_column_id' => 3, 'status' => 'completed'],
        ]);
        $manifest = [
            'name' => 'Employee',
            'ui' => ['show' => ['stats' => [[
                'handle' => 'open_tasks', 'label' => 'Open Tasks', 'relation' => 'tasks',
                'aggregate' => 'count', 'filter' => ['board_column_id' => ['!4']],
            ]]]],
        ];

        $stats = DirectoryUi::showStatsFromManifest($manifest, $record);

        $this->assertSame([['handle' => 'open_tasks', 'label' => 'Open Tasks', 'value' => '2']], $stats);
    }
}

class ExclusionStatRecord
{
    public function __construct(private array $rows) {}

    public function tasks(): ExclusionStatQuery
    {
        return new ExclusionStatQuery($this->rows);
    }
}

class ExclusionStatQuery
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
        return $this->rows === [] ? 0.0 : (float) (array_sum(array_column($this->rows, $column)) / count($this->rows));
    }
}
