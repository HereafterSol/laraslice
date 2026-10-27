{{--
    Related-tab GANTT render mode (Body v4 detail pattern):
    the tab's records as horizontal bars over a shared date
    axis, positioned from the declared start_field/end_field
    (defaults start_date/due_date). Records without both
    dates are counted and noted, never silently dropped.
    Pure kit rendering — the declaration carries field NAMES
    only, never geometry.
--}}
@php
    $records = $rtab['records'] ?? collect();
    $startField = (string) ($rtab['start_field'] ?? 'start_date');
    $endField = (string) ($rtab['end_field'] ?? 'due_date');
    $labelField = (string) ($rtab['label_field'] ?? '');
    $bars = [];
    $undated = 0;
    $min = null;
    $max = null;
    foreach ($records as $rec) {
        $s = data_get($rec, $startField);
        $e = data_get($rec, $endField);
        if (empty($s) || empty($e)) {
            $undated++;
            continue;
        }
        try {
            $s = \Illuminate\Support\Carbon::parse($s)->startOfDay();
            $e = \Illuminate\Support\Carbon::parse($e)->endOfDay();
        } catch (\Throwable $ex) {
            $undated++;
            continue;
        }
        if ($e->lt($s)) {
            [$s, $e] = [$e, $s];
        }
        $min = $min === null || $s->lt($min) ? $s->copy() : $min;
        $max = $max === null || $e->gt($max) ? $e->copy() : $max;
        $bars[] = ['rec' => $rec, 'start' => $s, 'end' => $e];
    }
    $span = ($min !== null && $max !== null) ? max(1, (int) $min->diffInDays($max)) : 1;
@endphp
@if(count($bars) === 0)
    <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
        No dated records to chart yet.
    </div>
@else
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="flex items-center justify-between px-4 py-2 border-b border-border/60 bg-muted/30 text-[11px] font-semibold text-muted-foreground">
            <span>{{ $min->format('M j, Y') }}</span>
            <span>{{ count($bars) }} item(s) · {{ $span }} day span</span>
            <span>{{ $max->format('M j, Y') }}</span>
        </div>
        <div class="divide-y divide-border/50">
            @foreach($bars as $bar)
                @php
                    $left = (int) round($min->diffInDays($bar['start']) / $span * 100);
                    $width = max(2, (int) round(max(1, (int) $bar['start']->diffInDays($bar['end'])) / $span * 100));
                    $rec = $bar['rec'];
                    $label = $labelField !== '' ? data_get($rec, $labelField) : null;
                    $label = $label ?? $rec->heading ?? $rec->title ?? $rec->name ?? $rec->subject ?? ('#'.$rec->id);
                @endphp
                <div class="flex items-center gap-3 px-4 py-2">
                    <span class="w-44 shrink-0 truncate text-[12px] font-medium text-foreground" title="{{ $label }}">{{ $label }}</span>
                    <div class="relative flex-1 h-5 rounded bg-muted/40">
                        <div class="absolute top-0.5 h-4 rounded bg-primary/80" style="left: {{ $left }}%; width: {{ $width }}%;"></div>
                    </div>
                    <span class="w-28 shrink-0 text-right text-[11px] text-muted-foreground">{{ $bar['start']->format('M j') }} – {{ $bar['end']->format('M j') }}</span>
                </div>
            @endforeach
        </div>
    </div>
    @if($undated > 0)
        <p class="text-[11px] text-muted-foreground">{{ $undated }} record(s) without both dates are not charted.</p>
    @endif
@endif
