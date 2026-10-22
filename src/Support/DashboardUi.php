<?php

namespace LaraSlice\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Declared dashboard widgets (S-UI 4, pattern P4): each slice
 * may declare `ui.dashboard.widgets`, and the framework
 * aggregates them into the standard dashboard. Types:
 *
 * - counter: a model aggregate (with declared-stats filter
 *   semantics, '!' excludes) as a big number card.
 * - list: the most recent records as a mini list linking to
 *   their detail pages.
 * - week: a week strip — records bucketed per day of the
 *   current week by a date field.
 * - chart: model counts grouped by a column, with labels and
 *   group order from the declaration (same as show widgets).
 *
 * A widget that cannot be computed is skipped, never
 * misreported.
 */
class DashboardUi
{
    /**
     * @param  array<int, array{manifest: array, model: ?string, routes: array{index?: ?string, show?: ?string}}>  $entries
     * @return array<int, array<string, mixed>>
     */
    public static function collect(array $entries): array
    {
        $widgets = [];
        foreach ($entries as $entry) {
            foreach (self::widgetsFromManifest(
                (array) ($entry['manifest'] ?? []),
                $entry['model'] ?? null,
                (array) ($entry['routes'] ?? [])
            ) as $widget) {
                $widgets[] = $widget;
            }
        }

        return $widgets;
    }

    /**
     * @param  array{index?: ?string, show?: ?string}  $routes  URI patterns; show carries an {id} placeholder
     * @return array<int, array<string, mixed>>
     */
    public static function widgetsFromManifest(array $manifest, ?string $modelClass, array $routes = []): array
    {
        $defs = $manifest['ui']['dashboard']['widgets'] ?? [];
        if (! is_array($defs) || $defs === [] || ! $modelClass || ! class_exists($modelClass)) {
            return [];
        }

        $widgets = [];
        foreach ($defs as $def) {
            if (! is_array($def)) {
                continue;
            }
            $type = (string) ($def['type'] ?? '');
            $handle = (string) ($def['handle'] ?? $type);
            try {
                $widget = match ($type) {
                    'counter' => self::counter($def, $modelClass, $routes),
                    'list' => self::recentList($def, $modelClass, $routes),
                    'week' => self::week($def, $modelClass, $routes),
                    'chart' => self::chart($def, $modelClass),
                    default => null,
                };
                if ($widget !== null) {
                    $widgets[] = ['handle' => $handle] + $widget;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $widgets;
    }

    protected static function counter(array $def, string $modelClass, array $routes): ?array
    {
        $query = self::filteredQuery($modelClass, (array) ($def['filter'] ?? []));
        $aggregate = (string) ($def['aggregate'] ?? 'count');
        $raw = match ($aggregate) {
            'sum' => (float) $query->sum((string) ($def['column'] ?? '')),
            'avg' => (float) $query->avg((string) ($def['column'] ?? '')),
            default => (int) $query->count(),
        };

        return [
            'type' => 'counter',
            'title' => (string) ($def['title'] ?? Str::headline((string) ($def['handle'] ?? 'Counter'))),
            'icon' => $def['icon'] ?? null,
            'value' => $aggregate === 'count' ? number_format($raw) : self::trimNumber((float) $raw),
            'link' => $routes['index'] ?? null,
        ];
    }

    protected static function recentList(array $def, string $modelClass, array $routes): ?array
    {
        $labelField = (string) ($def['label_field'] ?? 'name');
        $items = $modelClass::query()
            ->orderBy((string) ($def['order_by'] ?? 'id'), (string) ($def['direction'] ?? 'desc'))
            ->limit((int) ($def['limit'] ?? 5))
            ->get();
        $rows = [];
        foreach ($items as $item) {
            $label = data_get($item, $labelField);
            if (! is_scalar($label) || (string) $label === '') {
                $label = data_get($item, 'title') ?? data_get($item, 'name') ?? ('#'.data_get($item, 'id'));
            }
            $rows[] = [
                'label' => (string) $label,
                'url' => isset($routes['show']) && $routes['show']
                    ? str_replace('{id}', (string) data_get($item, 'id'), (string) $routes['show'])
                    : null,
            ];
        }
        if ($rows === []) {
            return null;
        }

        return [
            'type' => 'list',
            'title' => (string) ($def['title'] ?? 'Recent'),
            'rows' => $rows,
            'link' => $routes['index'] ?? null,
        ];
    }

    protected static function week(array $def, string $modelClass, array $routes): ?array
    {
        $field = (string) ($def['date_field'] ?? '');
        if ($field === '') {
            return null;
        }
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();
        $values = $modelClass::query()
            ->whereBetween($field, [$start->toDateTimeString(), $end->toDateTimeString()])
            ->pluck($field);
        $buckets = array_fill(0, 7, 0);
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $day = Carbon::parse($value);
            if ($day->betweenIncluded($start, $end)) {
                $buckets[($day->dayOfWeekIso - 1)]++;
            }
        }
        $todayIso = Carbon::now()->dayOfWeekIso;
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $days[] = [
                'label' => $date->format('D'),
                'date' => $date->format('M j'),
                'count' => $buckets[$i],
                'today' => $date->isToday(),
            ];
        }

        return [
            'type' => 'week',
            'title' => (string) ($def['title'] ?? 'This Week'),
            'days' => $days,
            'link' => $routes['index'] ?? null,
        ];
    }

    protected static function chart(array $def, string $modelClass): ?array
    {
        $groupBy = (string) ($def['group_by'] ?? '');
        if ($groupBy === '') {
            return null;
        }
        $values = $modelClass::query()->limit(1000)->pluck($groupBy);
        $counts = [];
        foreach ($values as $value) {
            if ($value === null || ! is_scalar($value)) {
                continue;
            }
            $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }
        $labelsMap = (array) ($def['labels'] ?? []);
        if ($labelsMap !== []) {
            $ordered = [];
            foreach ($labelsMap as $k => $_) {
                if (isset($counts[(string) $k])) {
                    $ordered[(string) $k] = $counts[(string) $k];
                }
            }
            foreach ($counts as $k => $n) {
                $ordered[$k] ??= $n;
            }
            $counts = $ordered;
        }
        $labels = [];
        $series = [];
        foreach ($counts as $k => $n) {
            $labels[] = (string) ($labelsMap[$k] ?? Str::headline($k));
            $series[] = $n;
        }

        return [
            'type' => 'chart',
            'chart' => (string) ($def['chart'] ?? 'donut'),
            'title' => (string) ($def['title'] ?? 'Chart'),
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * A model query with the declared-stats filter semantics:
     * scalar = where, array = whereIn, '!'-prefixed = whereNotIn.
     */
    protected static function filteredQuery(string $modelClass, array $filter): mixed
    {
        $query = $modelClass::query();
        foreach ($filter as $column => $value) {
            if (is_array($value)) {
                $exclude = array_values(array_filter($value, fn ($v) => is_string($v) && str_starts_with($v, '!')));
                $include = array_values(array_diff($value, $exclude));
                if ($exclude !== []) {
                    $query = $query->whereNotIn((string) $column, array_map(fn ($v) => substr($v, 1), $exclude));
                }
                if ($include !== []) {
                    $query = $query->whereIn((string) $column, $include);
                }
            } else {
                $query = $query->where((string) $column, $value);
            }
        }

        return $query;
    }

    protected static function trimNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
