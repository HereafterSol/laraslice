@props([
    'type' => 'donut',
    'series' => [],
    'options' => [],
    'colors' => [],
    'config' => [],
    'labels' => [],
    'height' => 250,
    'label' => null,
])

@php
    // Standard flat shape: parallel labels + numeric series
    // (what ui.show.widgets and ui.dashboard.widgets
    // produce). Rendered server-side as SVG/divs — the
    // component previously delegated to an ApexCharts
    // Alpine helper that host apps may never load, which
    // left a permanently blank panel (backlog A6). The
    // gauge component set the precedent: standard-layer
    // visuals must render with zero JS dependencies.
    $label ??= __('Chart');

    $values = [];
    $flat = true;
    foreach ((array) $series as $entry) {
        if (is_array($entry) || is_object($entry)) {
            $flat = false;
            break;
        }
        $values[] = (float) $entry;
    }
    $labels = array_values((array) $labels);
    while (count($labels) < count($values)) {
        $labels[] = 'Item '.(count($labels) + 1);
    }

    $resolvedColors = array_values(array_filter((array) $colors));
    if (empty($resolvedColors) && ! empty($config)) {
        $resolvedColors = array_values(array_filter(array_map(fn ($c) => is_array($c) ? ($c['color'] ?? null) : null, (array) $config)));
    }
    $fallbacks = ['#e76f51', '#2a9d8f', '#264653', '#e9c46a', '#6a4c93'];
    $colorFor = function (int $i) use ($resolvedColors, $fallbacks) {
        if (isset($resolvedColors[$i])) {
            return $resolvedColors[$i];
        }
        $n = ($i % 5) + 1;

        return "var(--chart-{$n}, {$fallbacks[$i % 5]})";
    };

    $total = array_sum($values);
    $max = $values !== [] ? max($values) : 0.0;
    $supported = $flat && in_array($type, ['donut', 'pie', 'bar'], true) && $values !== [];

    // Donut/pie geometry: stroke segments on a circle
    // (the gauge's technique), accumulated by fraction.
    $segments = [];
    if ($supported && $type !== 'bar') {
        $isPie = $type === 'pie';
        $radius = $isPie ? 21 : 34;
        $circumference = 2 * M_PI * $radius;
        $start = 0.0;
        foreach ($values as $i => $v) {
            $fraction = $total > 0 ? $v / $total : 0.0;
            $length = $fraction * $circumference;
            $segments[] = [
                'color' => $colorFor($i),
                'dash' => max(0.0, $length - ($fraction > 0 && count($values) > 1 ? 1.5 : 0)).' '.$circumference,
                'offset' => -$start * $circumference,
            ];
            $start += $fraction;
        }
    }
    $size = max(120, min((int) $height, 220));
@endphp

<div data-slot="chart" role="img" aria-label="{{ $label }}" {{ $attributes->twMerge('w-full text-xs') }}>
    @if(! $supported)
        <div class="flex items-center justify-center rounded-lg border border-dashed border-border px-4 text-center text-muted-foreground" style="min-height: {{ (int) $height }}px">
            {{ $values === [] ? __('No chart data yet.') : __('This chart type is not available in the standard renderer.') }}
        </div>
    @elseif($type === 'bar')
        <div class="space-y-2.5 py-1">
            @foreach($values as $i => $v)
                <div class="flex items-center gap-3">
                    <span class="w-28 shrink-0 truncate font-medium text-muted-foreground" title="{{ $labels[$i] }}">{{ $labels[$i] }}</span>
                    <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full" style="width: {{ $max > 0 ? round($v / $max * 100, 1) : 0 }}%; background: {{ $colorFor($i) }}"></div>
                    </div>
                    <span class="w-10 shrink-0 text-right font-semibold text-foreground">{{ rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') }}</span>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex flex-wrap items-center gap-5">
            <div class="relative shrink-0" style="width: {{ $size }}px; height: {{ $size }}px">
                <svg viewBox="0 0 84 84" class="h-full w-full -rotate-90">
                    <circle cx="42" cy="42" r="{{ $type === 'pie' ? 21 : 34 }}" fill="none" class="stroke-muted" stroke-width="{{ $type === 'pie' ? 42 : 16 }}" />
                    @foreach($segments as $seg)
                        <circle cx="42" cy="42" r="{{ $type === 'pie' ? 21 : 34 }}" fill="none" stroke="{{ $seg['color'] }}" stroke-width="{{ $type === 'pie' ? 42 : 16 }}"
                            stroke-dasharray="{{ $seg['dash'] }}" stroke-dashoffset="{{ $seg['offset'] }}" />
                    @endforeach
                </svg>
                @if($type === 'donut')
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-lg font-bold text-foreground">{{ rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.') }}</span>
                        <span class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">{{ __('Total') }}</span>
                    </div>
                @endif
            </div>
            <ul class="min-w-36 flex-1 space-y-1.5">
                @foreach($values as $i => $v)
                    <li class="flex items-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $colorFor($i) }}"></span>
                        <span class="flex-1 truncate font-medium text-muted-foreground" title="{{ $labels[$i] }}">{{ $labels[$i] }}</span>
                        <span class="font-semibold text-foreground">{{ rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') }}</span>
                        <span class="w-11 text-right text-muted-foreground">{{ $total > 0 ? round($v / $total * 100, 1) : 0 }}%</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
