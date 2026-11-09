<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Carbon\Carbon;

/**
 * Project burndown series — the source systems' burndown math,
 * reproduced faithfully (quirks included) as a pure computation
 * so any hub chart tab can render it and tests can pin it.
 *
 * Inputs are the project's tasks (rows carrying start_date,
 * due_date, completed_on, created_at). The source algorithm:
 *
 *  - Scope: tasks with a due_date; an optional from-date keeps
 *    tasks whose start_date is on/after it, an optional to-date
 *    keeps tasks whose due_date is on/before it.
 *  - Window: from the from-date (else the project's start date)
 *    to the to-date (else the project deadline, else today) —
 *    one label per day.
 *  - Ideal line: starts at total − (tasks due on day 0), then
 *    steps down by the number of tasks due on each day (the
 *    "deadline" burndown: work is expected to finish by its
 *    due date).
 *  - Actual line (days up to today only; later days are null):
 *    tasks not yet completed on that day (completed_on null or
 *    on/after the day), PLUS — for every day after day 0 — the
 *    tasks created on that day (the source's scope-change
 *    adjustment, which can push the actual line above the
 *    total; preserved deliberately, it is the source's math).
 *  - Axis maximum: round(ideal[0] × 2), as the source sets it.
 */
final class BurndownChart
{
    /**
     * @param  iterable<object|array{start_date?: mixed, due_date?: mixed, completed_on?: mixed, created_at?: mixed}>  $tasks
     * @return array{labels: list<string>, ideal: list<int>, actual: list<int|null>, y_max: int, total: int, from: string, to: string}
     */
    public static function series(iterable $tasks, ?string $projectStart, ?string $projectDeadline, ?string $from = null, ?string $to = null, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $fromDate = self::parseDate($from) ?? self::parseDate($projectStart) ?? $today->copy();
        $toDate = self::parseDate($to) ?? self::parseDate($projectDeadline) ?? $today->copy();

        $fromFilter = self::parseDate($from);
        $toFilter = self::parseDate($to);

        $scoped = [];
        foreach ($tasks as $task) {
            $due = self::parseDate(self::value($task, 'due_date'));
            if ($due === null) {
                continue;
            }
            if ($fromFilter !== null) {
                $start = self::parseDate(self::value($task, 'start_date'));
                if ($start === null || $start->lt($fromFilter)) {
                    continue;
                }
            }
            if ($toFilter !== null && $due->gt($toFilter)) {
                continue;
            }
            $scoped[] = [
                'due' => $due,
                'completed' => self::parseDate(self::value($task, 'completed_on')),
                'created' => self::parseDate(self::value($task, 'created_at')),
            ];
        }

        $total = count($scoped);
        $labels = [];
        $ideal = [];
        $actual = [];

        $cursor = $fromDate->copy();
        $key = 0;
        while ($cursor->lte($toDate)) {
            $labels[] = $cursor->format('d-m-Y');

            $dueThatDay = 0;
            $createdThatDay = 0;
            $uncompleted = 0;
            foreach ($scoped as $task) {
                if ($task['due']->equalTo($cursor)) {
                    $dueThatDay++;
                }
                if ($task['created'] !== null && $task['created']->equalTo($cursor)) {
                    $createdThatDay++;
                }
                if ($task['completed'] === null || $task['completed']->gte($cursor)) {
                    $uncompleted++;
                }
            }

            $ideal[] = $key === 0 ? $total - $dueThatDay : $ideal[$key - 1] - $dueThatDay;

            if ($cursor->lte($today)) {
                $actual[] = $key === 0 ? $uncompleted : $uncompleted + $createdThatDay;
            } else {
                $actual[] = null;
            }

            $cursor->addDay();
            $key++;
        }

        return [
            'labels' => $labels,
            'ideal' => $ideal,
            'actual' => $actual,
            'y_max' => $ideal === [] ? 0 : (int) round($ideal[0] * 2),
            'total' => $total,
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
        ];
    }

    private static function value(object|array $task, string $key): mixed
    {
        return is_array($task) ? ($task[$key] ?? null) : ($task->{$key} ?? null);
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($value))->startOfDay();
        }
        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
