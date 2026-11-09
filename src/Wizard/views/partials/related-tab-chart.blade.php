{{--
    Related-tab CHART panel (declared ui.show.tabs type:
    chart): renders the tab's server-computed series —
    today the project burndown (DirectoryUi chart tabs,
    BurndownChart math) — as a zero-JS SVG line chart, with
    the source's own controls: a date-range form that reloads
    the hub with bd_from / bd_to. The series also rides in a
    JSON payload so the plotted numbers are machine-checkable
    against the database, not just eyeballable.
--}}
@php
    $data = $rtab['chart_data'] ?? null;
    $labels = $data['labels'] ?? [];
    $ideal = $data['ideal'] ?? [];
    $actual = $data['actual'] ?? [];
    $yMax = max(1, (int) ($data['y_max'] ?? 0));
    $n = count($labels);
    // Plot geometry (viewBox 720x280, padded axes).
    $px = 46; $py = 12; $pw = 720 - 46 - 14; $ph = 280 - 12 - 30;
    $xAt = static fn (int $i): float => $n <= 1 ? $px : $px + ($pw * $i / ($n - 1));
    $yAt = static fn (float $v): float => $py + $ph - ($ph * $v / $yMax);
    $idealPts = [];
    foreach ($ideal as $i => $v) {
        $idealPts[] = round($xAt($i), 1).','.round($yAt((float) $v), 1);
    }
    // Actual renders stepped, like the source: horizontal to
    // the next day, then the vertical drop/rise.
    $actualPath = '';
    $prev = null;
    foreach ($actual as $i => $v) {
        if ($v === null) {
            $prev = null;
            continue;
        }
        $x = round($xAt($i), 1);
        $y = round($yAt((float) $v), 1);
        if ($prev === null) {
            $actualPath .= "M {$x} {$y} ";
        } else {
            $actualPath .= "L {$x} {$prev} L {$x} {$y} ";
        }
        $prev = $y;
    }
@endphp
@if($data === null || $n === 0)
    <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
        No chart data for this record yet.
    </div>
@else
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="flex flex-wrap items-end justify-between gap-3 px-4 py-3 border-b border-border/60 bg-muted/30">
            <form method="GET" action="{{ request()->url() }}" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="tab" value="{{ $rtab['handle'] }}">
                <label class="text-[11px] font-semibold text-muted-foreground">Start Date
                    <input type="date" name="bd_from" value="{{ $data['from'] }}" class="mt-1 block rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground">
                </label>
                <label class="text-[11px] font-semibold text-muted-foreground">End Date
                    <input type="date" name="bd_to" value="{{ $data['to'] }}" class="mt-1 block rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground">
                </label>
                <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground">Apply</button>
                <a href="{{ request()->url() }}?tab={{ $rtab['handle'] }}" class="rounded-md border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground">Reset</a>
            </form>
            <div class="flex items-center gap-4 text-[11px] font-semibold text-muted-foreground">
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-0.5 w-5" style="background:#1d82f5"></span> Actual</span>
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-0.5 w-5" style="background:#a3a3a3"></span> Ideal</span>
                <span>{{ (int) $data['total'] }} task(s) with due dates</span>
            </div>
        </div>
        <div class="p-3">
            <svg viewBox="0 0 720 280" class="w-full" role="img" aria-label="Burndown chart">
                @foreach([0, 0.5, 1] as $frac)
                    @php $gy = $py + $ph - ($ph * $frac); $gv = round($yMax * $frac); @endphp
                    <line x1="{{ $px }}" y1="{{ round($gy, 1) }}" x2="{{ $px + $pw }}" y2="{{ round($gy, 1) }}" stroke="currentColor" stroke-opacity="0.12" />
                    <text x="{{ $px - 6 }}" y="{{ round($gy + 3, 1) }}" text-anchor="end" font-size="10" fill="currentColor" fill-opacity="0.55">{{ $gv }}</text>
                @endforeach
                @foreach([0, intdiv(max(0, $n - 1), 2), $n - 1] as $li)
                    @if(isset($labels[$li]))
                        <text x="{{ round($xAt($li), 1) }}" y="{{ $py + $ph + 18 }}" text-anchor="{{ $li === 0 ? 'start' : ($li === $n - 1 ? 'end' : 'middle') }}" font-size="10" fill="currentColor" fill-opacity="0.55">{{ $labels[$li] }}</text>
                    @endif
                @endforeach
                @if($idealPts !== [])
                    <polyline points="{{ implode(' ', $idealPts) }}" fill="none" stroke="#a3a3a3" stroke-width="1.8" />
                @endif
                @if($actualPath !== '')
                    <path d="{{ $actualPath }}" fill="none" stroke="#1d82f5" stroke-width="1.8" stroke-dasharray="5 4" />
                @endif
            </svg>
        </div>
        <script type="application/json" class="ls-chart-data" data-handle="{{ $rtab['handle'] }}">{!! json_encode(['labels' => $labels, 'ideal' => $ideal, 'actual' => $actual, 'y_max' => $data['y_max'], 'total' => $data['total']]) !!}</script>
    </div>
@endif
