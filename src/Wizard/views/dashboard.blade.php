@extends('layouts.app')

@section('content')
<div class="space-y-4">
    @php
        $portal = ($composition ?? 'standard') === 'portal';
        $clockWidget = $portal ? collect($widgets ?? [])->firstWhere('type', 'clock') : null;
    @endphp
    @if($portal)
    {{-- Portal composition (layout kit step 4): the source
         dashboard's header — a personal welcome, a live clock,
         and the attendance punch (consuming the declared clock
         widget; its panel is not repeated in the grid). --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold tracking-tight text-foreground">Welcome {{ auth()->user()->name ?? '' }}</h1>
        <div class="flex items-center gap-4">
            <div class="text-right" x-data="{ t: '', d: '' }" x-init="const f = () => { const n = new Date(); t = n.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'}); d = n.toLocaleDateString([], {weekday: 'long'}); }; f(); setInterval(f, 20000)">
                <p class="text-xl font-semibold leading-none text-foreground" x-text="t">{{ now()->format('g:i a') }}</p>
                <p class="mt-1 text-xs text-muted-foreground" x-text="d">{{ now()->format('l') }}</p>
            </div>
            @if($clockWidget && ! empty($clockWidget['punch_url']))
            <form method="POST" action="{{ $clockWidget['punch_url'] }}">
                @csrf
                <x-ui.button type="submit" size="lg" variant="{{ ($clockWidget['state'] ?? '') === 'in' ? 'outline' : 'default' }}" class="gap-2">
                    <x-lucide-log-in class="size-4" />
                    {{ ($clockWidget['state'] ?? '') === 'in' ? 'Clock Out' : 'Clock In' }}
                </x-ui.button>
            </form>
            @endif
        </div>
    </div>
    @else
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-base font-medium text-foreground">Dashboard</h1>
            <p class="text-xs text-muted-foreground mt-0.5">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>
    @endif

    @php
        $widgets = $widgets ?? [];
        $counters = array_values(array_filter($widgets, fn ($w) => ($w['type'] ?? '') === 'counter'));
        $panels = array_values(array_filter($widgets, fn ($w) => ($w['type'] ?? '') !== 'counter'));
    @endphp

    @if($widgets === [])
        <x-ui.card class="p-8 bg-card border shadow-xs text-center">
            <p class="text-sm font-semibold text-foreground">No dashboard widgets declared yet</p>
            <p class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                Slices declare dashboard widgets in their manifest under
                <code class="rounded bg-muted px-1 py-0.5">ui.dashboard.widgets</code>
                — counters, recent lists, week strips, charts and personal
                widgets (today, clock, own-scoped) aggregate here automatically.
            </p>
        </x-ui.card>
    @else
        @if($counters !== [])
            @if($portal)
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach($counters as $widget)
                    <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-semibold text-foreground">{{ $widget['title'] }}</p>
                            @if(! empty($widget['icon']))
                                <span class="text-muted-foreground/50">@svg('lucide-'.$widget['icon'], 'size-7')</span>
                            @endif
                        </div>
                        <div class="mt-3 flex items-end gap-8">
                            <div>
                                <p class="text-[26px] font-bold leading-none text-primary">{{ $widget['value'] }}</p>
                                @if(! empty($widget['label']))
                                    <p class="mt-1.5 text-xs text-muted-foreground">{{ $widget['label'] }}</p>
                                @endif
                            </div>
                            @isset($widget['secondary_value'])
                            <div>
                                <p class="text-[26px] font-bold leading-none {{ ($widget['secondary_tone'] ?? '') === 'danger' ? 'text-rose-600' : 'text-primary' }}">{{ $widget['secondary_value'] }}</p>
                                <p class="mt-1.5 text-xs text-muted-foreground">{{ $widget['secondary_label'] ?? '' }}</p>
                            </div>
                            @endisset
                        </div>
                    </div>
                @endforeach
            </div>
            @else
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach($counters as $widget)
                    <x-ui.widget-card :title="$widget['title']" :icon="$widget['icon'] ?? null" :href="$widget['link'] ?? null" action-label="Open">
                        <p class="text-2xl font-semibold text-foreground">{{ $widget['value'] }}</p>
                    </x-ui.widget-card>
                @endforeach
            </div>
            @endif
        @endif

        @if($panels !== [])
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach($panels as $widget)
                    @if($portal && ($widget['type'] ?? '') === 'clock')
                        @continue
                    @endif
                    @if(($widget['type'] ?? '') === 'table')
                        <x-ui.widget-card :title="$widget['title']" :icon="$widget['icon'] ?? null" :href="$widget['link'] ?? null">
                            @if(($widget['rows'] ?? []) === [])
                                <p class="py-6 text-center text-[13px] text-muted-foreground">{{ $widget['empty'] ?? '- No record found. -' }}</p>
                            @else
                            <div class="overflow-x-auto">
                                <table class="w-full text-[13px]">
                                    <thead>
                                        <tr class="border-b border-border/70 text-left">
                                            @foreach($widget['columns'] as $col)
                                                <th class="py-2 pr-3 font-medium text-muted-foreground">{{ $col }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60">
                                        @foreach($widget['rows'] as $row)
                                            <tr>
                                                @foreach($row['cells'] as $cell)
                                                    <td class="py-2 pr-3 text-foreground">{{ $cell }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @endif
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'calendar')
                        <x-ui.widget-card :title="$widget['title']" :icon="$widget['icon'] ?? null" :href="$widget['link'] ?? null">
                            <p class="pb-2 text-center text-[13px] font-semibold text-foreground">{{ $widget['month_label'] }}</p>
                            <div class="grid grid-cols-7 gap-1 text-center">
                                @foreach(['Mo','Tu','We','Th','Fr','Sa','Su'] as $dow)
                                    <p class="py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ $dow }}</p>
                                @endforeach
                                @foreach($widget['weeks'] as $week)
                                    @foreach($week as $day)
                                        <div class="min-h-9 rounded-md px-0.5 py-1 {{ $day['today'] ? 'bg-primary/10 ring-1 ring-primary/40' : '' }} {{ $day['in_month'] ? '' : 'opacity-35' }}" title="{{ implode(', ', $day['events']) }}">
                                            <p class="text-[11px] font-medium {{ $day['today'] ? 'text-primary' : 'text-foreground' }}">{{ $day['day'] }}</p>
                                            @foreach($day['events'] as $ev)
                                                <p class="truncate text-[9px] leading-tight text-muted-foreground">{{ $ev }}</p>
                                            @endforeach
                                            @if($day['more'] > 0)
                                                <p class="text-[9px] leading-tight text-muted-foreground">+{{ $day['more'] }} more</p>
                                            @endif
                                        </div>
                                    @endforeach
                                @endforeach
                            </div>
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'list')
                        <x-ui.widget-card :title="$widget['title']" :href="$widget['link'] ?? null">
                            <ul class="divide-y divide-border/60">
                                @foreach($widget['rows'] as $row)
                                    <li class="py-2 first:pt-0 last:pb-0">
                                        @if($row['url'])
                                            <a href="{{ $row['url'] }}" class="text-[13px] font-medium text-foreground hover:text-primary">{{ $row['label'] }}</a>
                                        @else
                                            <span class="text-[13px] font-medium text-foreground">{{ $row['label'] }}</span>
                                        @endif
                                        @if(($row['meta'] ?? '') !== '')
                                            <span class="ml-2 text-xs font-medium text-muted-foreground">{{ $row['meta'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'today')
                        <x-ui.widget-card :title="$widget['title']" :href="$widget['link'] ?? null">
                            @if(($widget['rows'] ?? []) === [])
                                <p class="py-2 text-[13px] text-muted-foreground">{{ $widget['empty'] ?? 'Nothing scheduled for today.' }}</p>
                            @else
                                <ul class="divide-y divide-border/60">
                                    @foreach($widget['rows'] as $row)
                                        <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                            @if($row['url'])
                                                <a href="{{ $row['url'] }}" class="text-[13px] font-medium text-foreground hover:text-primary">{{ $row['label'] }}</a>
                                            @else
                                                <span class="text-[13px] font-medium text-foreground">{{ $row['label'] }}</span>
                                            @endif
                                            @if(($row['meta'] ?? '') !== '')
                                                <span class="text-xs font-medium text-muted-foreground">{{ $row['meta'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'people_dates')
                        <x-ui.widget-card :title="$widget['title']" :href="$widget['link'] ?? null">
                            @if(($widget['rows'] ?? []) === [])
                                <p class="py-2 text-[13px] text-muted-foreground">{{ $widget['empty'] ?? 'Nothing coming up.' }}</p>
                            @else
                                <ul class="divide-y divide-border/60">
                                    @foreach($widget['rows'] as $row)
                                        <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                            @if($row['url'])
                                                <a href="{{ $row['url'] }}" class="text-[13px] font-medium text-foreground hover:text-primary">{{ $row['label'] }}</a>
                                            @else
                                                <span class="text-[13px] font-medium text-foreground">{{ $row['label'] }}</span>
                                            @endif
                                            @if(($row['meta'] ?? '') !== '')
                                                <span class="text-xs font-medium text-muted-foreground">{{ $row['meta'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'profile')
                        <x-ui.widget-card :title="$widget['title']" :href="$widget['link'] ?? null">
                            <div class="flex items-center gap-3 py-1">
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">{{ collect(explode(' ', trim($widget['name'] ?? '')))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                                <div class="min-w-0">
                                    @if(($widget['url'] ?? null))
                                        <a href="{{ $widget['url'] }}" class="block truncate text-sm font-semibold text-foreground hover:text-primary">{{ $widget['name'] }}</a>
                                    @else
                                        <p class="truncate text-sm font-semibold text-foreground">{{ $widget['name'] }}</p>
                                    @endif
                                    @if(($widget['subtitle'] ?? '') !== '')
                                        <p class="truncate text-xs text-muted-foreground">{{ $widget['subtitle'] }}</p>
                                    @endif
                                </div>
                            </div>
                            @if(($widget['lines'] ?? []) !== [])
                                <dl class="mt-2 space-y-1.5 border-t border-border/60 pt-2.5">
                                    @foreach($widget['lines'] as $line)
                                        <div class="flex items-center justify-between gap-3 text-xs">
                                            <dt class="text-muted-foreground">{{ $line['label'] }}</dt>
                                            <dd class="font-medium text-foreground">{{ $line['value'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'schedule')
                        <x-ui.widget-card :title="$widget['title']" :href="$widget['link'] ?? null">
                            <div class="grid grid-cols-7 gap-1.5">
                                @foreach($widget['days'] as $day)
                                    <div class="rounded-lg border px-1.5 py-2 text-center {{ ($day['today'] ?? false) ? 'border-primary/50 bg-primary/5' : 'border-border/60' }}">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ $day['label'] }}</p>
                                        <p class="mt-0.5 truncate text-[11px] font-semibold text-foreground" title="{{ $day['text'] ?: 'Off' }}">{{ $day['text'] ?: '·' }}</p>
                                        <p class="text-[10px] text-muted-foreground">{{ $day['date'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'clock')
                        <x-ui.widget-card :title="$widget['title']" :href="$widget['link'] ?? null">
                            <div class="flex items-center gap-2.5 py-1">
                                <span class="size-2.5 shrink-0 rounded-full {{ ($widget['state'] ?? '') === 'in' ? 'bg-emerald-500' : (($widget['state'] ?? '') === 'done' ? 'bg-sky-500' : 'bg-muted-foreground/40') }}"></span>
                                <p class="text-[13px] font-medium text-foreground">{{ $widget['headline'] ?? '' }}</p>
                                @if(($widget['detail'] ?? '') !== '')
                                    <span class="text-xs text-muted-foreground">{{ $widget['detail'] }}</span>
                                @endif
                                @if (! empty($widget['punch_url']))
                                    <form method="POST" action="{{ $widget['punch_url'] }}" class="ml-auto">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="{{ ($widget['state'] ?? '') === 'in' ? 'outline' : 'default' }}">
                                            {{ ($widget['state'] ?? '') === 'in' ? 'Clock Out' : 'Clock In' }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            </div>
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'week')
                        <x-ui.widget-card :title="$widget['title']" :href="$widget['link'] ?? null">
                            <x-ui.week-strip :days="$widget['days']" />
                        </x-ui.widget-card>
                    @elseif(($widget['type'] ?? '') === 'chart')
                        <x-ui.widget-card :title="$widget['title']">
                            <x-ui.chart :type="$widget['chart']" :labels="$widget['labels']" :series="$widget['series']" :height="200" />
                        </x-ui.widget-card>
                    @endif
                @endforeach
            </div>
        @endif
    @endif
</div>
@endsection
