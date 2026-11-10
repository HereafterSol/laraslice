@if(!empty($rtab['lazy']))
    @include('laraslice::partials.related-tab-lazy', ['rtab' => $rtab])
@else
{{--
    Related-tab CALENDAR render mode (declared render:
    calendar on a relation tab): the tab's records on a
    month grid — the roster surface. Each record lands on the
    day of its declared start_field (default `date`); its
    chip label and colour ride the record's `shift` relation
    (name / short code / colour) when it carries one, else the
    record's own label conventions. Month navigation is plain
    GET (?tab=…&cal=YYYY-MM) — zero JS. Records without a
    parseable date are counted and noted, never dropped
    silently.
--}}
@php
    $records = $rtab['records'] ?? collect();
    $dateField = (string) ($rtab['start_field'] ?? 'date');
    $calParam = (string) request()->query('cal', '');
    try {
        $month = preg_match('/^\d{4}-\d{2}$/', $calParam) === 1
            ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $calParam)->startOfMonth()
            : now()->startOfMonth();
    } catch (\Throwable $e) {
        $month = now()->startOfMonth();
    }
    $byDay = [];
    $undated = 0;
    foreach ($records as $rec) {
        $raw = data_get($rec, $dateField);
        if (empty($raw)) {
            $undated++;
            continue;
        }
        try {
            $day = \Illuminate\Support\Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable $e) {
            $undated++;
            continue;
        }
        $shift = null;
        try {
            $shift = $rec->shift ?? $rec->employeeShift ?? null;
        } catch (\Throwable $e) {
            $shift = null;
        }
        $title = $shift !== null
            ? (string) ($shift->shift_name ?? $shift->name ?? 'Shift')
            : (string) (data_get($rec, 'title') ?? data_get($rec, 'name') ?? data_get($rec, 'subject') ?? 'Record');
        $short = $shift !== null ? (string) ($shift->shift_short_code ?? $title) : $title;
        $color = $shift !== null ? (string) ($shift->color ?? '') : '';
        $byDay[$day][] = ['title' => $title, 'short' => $short, 'color' => $color];
    }
    $daysInMonth = $month->daysInMonth;
    $leadBlanks = $month->copy()->startOfMonth()->dayOfWeekIso - 1;
    $prevMonth = $month->copy()->subMonth()->format('Y-m');
    $nextMonth = $month->copy()->addMonth()->format('Y-m');
    $baseUrl = request()->url().'?tab='.urlencode((string) $rtab['handle']);
    $todayStr = now()->format('Y-m-d');
@endphp
<div class="rounded-xl border border-border bg-card overflow-hidden">
    <div class="flex items-center justify-between px-4 py-2.5 border-b border-border/60 bg-muted/30">
        <div class="text-sm font-semibold text-foreground">{{ $month->format('F Y') }}</div>
        <div class="flex items-center gap-2 text-xs font-semibold">
            <a href="{{ $baseUrl }}&cal={{ $prevMonth }}" class="rounded-md border border-border px-2.5 py-1 text-muted-foreground">‹ Prev</a>
            <a href="{{ $baseUrl }}" class="rounded-md border border-border px-2.5 py-1 text-muted-foreground">This month</a>
            <a href="{{ $baseUrl }}&cal={{ $nextMonth }}" class="rounded-md border border-border px-2.5 py-1 text-muted-foreground">Next ›</a>
        </div>
    </div>
    <div class="grid grid-cols-7 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground border-b border-border/60">
        @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)
            <div class="py-1.5">{{ $dow }}</div>
        @endforeach
    </div>
    <div class="grid grid-cols-7">
        @for($b = 0; $b < $leadBlanks; $b++)
            <div class="min-h-[76px] border-b border-r border-border/40 bg-muted/20"></div>
        @endfor
        @for($d = 1; $d <= $daysInMonth; $d++)
            @php
                $dateStr = $month->copy()->day($d)->format('Y-m-d');
                $events = $byDay[$dateStr] ?? [];
            @endphp
            <div class="min-h-[76px] border-b border-r border-border/40 p-1.5 {{ $dateStr === $todayStr ? 'bg-primary/5' : '' }}">
                <div class="text-[11px] font-semibold {{ $dateStr === $todayStr ? 'text-primary' : 'text-muted-foreground' }}">{{ $d }}</div>
                @foreach($events as $ev)
                    <div class="mt-1 truncate rounded px-1.5 py-0.5 text-[11px] font-medium text-white" style="background:{{ $ev['color'] !== '' ? $ev['color'] : '#64748b' }}" title="{{ $ev['title'] }}">{{ $ev['short'] }}</div>
                @endforeach
            </div>
        @endfor
    </div>
    @if($undated > 0)
        <div class="px-4 py-2 text-[11px] text-muted-foreground border-t border-border/60">{{ $undated }} record(s) without a date are not shown on the calendar.</div>
    @endif
</div>

@endif
