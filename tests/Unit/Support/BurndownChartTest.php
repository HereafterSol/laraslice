<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use Illuminate\Support\Carbon;
use LaraSlice\Support\BurndownChart;
use PHPUnit\Framework\TestCase;

/**
 * The project burndown math, pinned against the benchmark
 * project's hand-computed series (the source system's
 * algorithm, quirks included): ideal steps down on due
 * dates; actual counts tasks not yet completed at each
 * day's start and ADDS the tasks created that day — so the
 * actual line can spike above the total on a creation day.
 */
class BurndownChartTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function benchmarkTasks(): array
    {
        // Project 1 shape: five tasks, all created 2024-02-13,
        // none completed; dues 01-24, 02-01, 02-05, 02-13 ×2.
        $mk = static fn (string $due): array => [
            'start_date' => null,
            'due_date' => $due,
            'created_at' => '2024-02-13 10:00:00',
            'completed_on' => null,
        ];

        return [$mk('2024-01-24'), $mk('2024-02-01'), $mk('2024-02-05'), $mk('2024-02-13'), $mk('2024-02-13')];
    }

    public function test_benchmark_project_series_matches_the_source_math(): void
    {
        $s = BurndownChart::series(
            $this->benchmarkTasks(),
            '2023-12-13',
            '2024-04-13',
            null,
            null,
            Carbon::parse('2026-11-07'),
        );

        $this->assertSame(5, $s['total']);
        $this->assertCount(123, $s['labels']);
        $this->assertSame('13-12-2023', $s['labels'][0]);
        $this->assertSame('13-04-2024', $s['labels'][122]);

        // Ideal: 5 until the first due date passes.
        $this->assertSame(5, $s['ideal'][0]);
        $this->assertSame(5, $s['ideal'][41]);   // 2024-01-23
        $this->assertSame(4, $s['ideal'][42]);   // 2024-01-24
        $this->assertSame(3, $s['ideal'][50]);   // 2024-02-01
        $this->assertSame(2, $s['ideal'][54]);   // 2024-02-05
        $this->assertSame(0, $s['ideal'][62]);   // 2024-02-13
        $this->assertSame(0, $s['ideal'][122]);

        // Actual: flat 5 (nothing completed) with the
        // creation-day spike on 2024-02-13.
        $this->assertSame(5, $s['actual'][0]);
        $this->assertSame(5, $s['actual'][61]);  // 2024-02-12
        $this->assertSame(10, $s['actual'][62]); // 2024-02-13: 5 open + 5 created
        $this->assertSame(5, $s['actual'][63]);
        $this->assertSame(5, $s['actual'][122]);

        $this->assertSame(10, $s['y_max']);
    }

    public function test_actual_is_null_after_today(): void
    {
        $s = BurndownChart::series(
            $this->benchmarkTasks(),
            '2023-12-13',
            '2024-04-13',
            null,
            null,
            Carbon::parse('2023-12-15'),
        );

        $this->assertSame(5, $s['actual'][0]);
        $this->assertNotNull($s['actual'][2]);  // 2023-12-15
        $this->assertNull($s['actual'][3]);     // 2023-12-16
        $this->assertNull($s['actual'][122]);
        // The ideal line is complete regardless of today.
        $this->assertSame(0, $s['ideal'][122]);
    }

    public function test_completed_tasks_leave_the_actual_line_on_their_day(): void
    {
        $tasks = $this->benchmarkTasks();
        $tasks[0]['completed_on'] = '2024-01-20 09:00:00';

        $s = BurndownChart::series($tasks, '2023-12-13', '2024-04-13', null, null, Carbon::parse('2026-11-07'));

        // Still counted on the morning of its completion day
        // (completed_on >= day start), gone the day after.
        $idxCompletion = 38; // 2024-01-20
        $this->assertSame(5, $s['actual'][$idxCompletion]);
        $this->assertSame(4, $s['actual'][$idxCompletion + 1]);
    }

    public function test_undated_tasks_are_excluded_and_filters_apply(): void
    {
        $tasks = $this->benchmarkTasks();
        $tasks[] = ['start_date' => null, 'due_date' => null, 'created_at' => '2024-02-13', 'completed_on' => null];

        $s = BurndownChart::series($tasks, '2023-12-13', '2024-04-13', null, null, Carbon::parse('2026-11-07'));
        $this->assertSame(5, $s['total']);

        // The to-filter (due_date <= to) drops the two
        // 2024-02-13 tasks when to = 2024-02-10.
        $s2 = BurndownChart::series($tasks, '2023-12-13', '2024-04-13', null, '2024-02-10', Carbon::parse('2026-11-07'));
        $this->assertSame(3, $s2['total']);
        $this->assertSame('2024-02-10', $s2['to']);
    }
}
