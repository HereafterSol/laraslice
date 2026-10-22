@extends('layouts.app')

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-base font-medium text-foreground">Dashboard</h1>
            <p class="text-xs text-muted-foreground mt-0.5">{{ now()->format('l, F j, Y') }}</p>
        </div>
    </div>

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
                — counters, recent lists, week strips and charts aggregate here automatically.
            </p>
        </x-ui.card>
    @else
        @if($counters !== [])
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach($counters as $widget)
                    <x-ui.widget-card :title="$widget['title']" :icon="$widget['icon'] ?? null" :href="$widget['link'] ?? null" action-label="Open">
                        <p class="text-2xl font-semibold text-foreground">{{ $widget['value'] }}</p>
                    </x-ui.widget-card>
                @endforeach
            </div>
        @endif

        @if($panels !== [])
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach($panels as $widget)
                    @if(($widget['type'] ?? '') === 'list')
                        <x-ui.widget-card :title="$widget['title']" :href="$widget['link'] ?? null">
                            <ul class="divide-y divide-border/60">
                                @foreach($widget['rows'] as $row)
                                    <li class="py-2 first:pt-0 last:pb-0">
                                        @if($row['url'])
                                            <a href="{{ $row['url'] }}" class="text-[13px] font-medium text-foreground hover:text-primary">{{ $row['label'] }}</a>
                                        @else
                                            <span class="text-[13px] font-medium text-foreground">{{ $row['label'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
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
