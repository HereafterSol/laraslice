{{--
    Standard matrix template (G2): rows x days-of-month grid for any
    slice declaring ui.directory.matrix. A slice overrides it with its
    own Resources/views/matrix.blade.php.

    Composition (25 Oct 2026, matched against the source's
    attendance page): the declared sidebar filters fuse with the
    month/year period into one GET bar; the declared bulk-entry
    (Mark Attendance) and import actions sit in their own row with
    the List-view switch at the right; a declared `legend:` renders
    above the grid; `roster: true` renders every member of the
    row-key label map (elapsed days read absent, future days dash)
    with a Total column of present / days-in-month.
--}}
@extends('layouts.app')

@section('content')
@php
    $initials = function (string $name): string {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first.$last);
    };
    $today = date('Y-m-d');
@endphp
<div class="space-y-4">
    <div class="flex flex-col gap-3 pb-2 border-b border-border/40">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            {{-- No in-page title: on ERP pages the shell topbar
                 carries the section title (source composition). --}}
            <span class="text-sm text-muted-foreground">{{ date('F Y', mktime(0, 0, 0, $matrix['month'], 1, $matrix['year'])) }}</span>
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @foreach($matrixFilters ?? [] as $mf)
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="font-medium">{{ $mf['label'] }}</span>
                    <select name="{{ $mf['handle'] }}" class="h-8 rounded-lg border border-border bg-background px-2 text-xs text-foreground">
                        <option value="">All</option>
                        @foreach($mf['options'] as $opt)
                        <option value="{{ $opt['value'] }}" @selected((string) ($mf['active'] ?? '') === (string) $opt['value'])>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                @endforeach
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="font-medium">Month</span>
                    <select name="month" class="h-8 rounded-lg border border-border bg-background px-2 text-xs text-foreground">
                        @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" @selected($m === $matrix['month'])>{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                        @endfor
                    </select>
                </label>
                <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="font-medium">Year</span>
                    <select name="year" class="h-8 rounded-lg border border-border bg-background px-2 text-xs text-foreground">
                        @for($y = $matrix['year'] - 3; $y <= $matrix['year'] + 1; $y++)
                        <option value="{{ $y }}" @selected($y === $matrix['year'])>{{ $y }}</option>
                        @endfor
                    </select>
                </label>
                <button type="submit" class="h-8 px-3 rounded-lg bg-primary text-primary-foreground text-xs font-semibold">Show</button>
            </form>
        </div>
        @if(($bulkEntryUrl ?? null) || ($importUrl ?? null))
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                @if($bulkEntryUrl ?? null)
                <x-ui.button href="{{ $bulkEntryUrl }}" as="a" size="sm" class="gap-1.5 shadow-xs font-semibold">
                    <x-lucide-plus class="size-3.5" />
                    <span>{{ $bulkEntryLabel ?? 'Bulk Entry' }}</span>
                </x-ui.button>
                @endif
                @if($importUrl ?? null)
                <x-ui.button href="{{ $importUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                    <x-lucide-upload class="size-3.5" />
                    <span>Import</span>
                </x-ui.button>
                @endif
            </div>
            <a href="{{ $indexUrl }}" class="h-8 px-3 inline-flex items-center gap-1.5 rounded-lg border border-border text-xs font-semibold hover:bg-muted transition-colors">
                <x-lucide-list class="size-3.5" /> List view
            </a>
        </div>
        @else
        <div class="flex items-center justify-end">
            <a href="{{ $indexUrl }}" class="h-8 px-3 inline-flex items-center gap-1.5 rounded-lg border border-border text-xs font-semibold hover:bg-muted transition-colors">
                <x-lucide-list class="size-3.5" /> List view
            </a>
        </div>
        @endif
    </div>

    @if(!empty($matrix['legend']))
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
        <span class="font-semibold text-foreground">Note:</span>
        @foreach($matrix['legend'] as $item)
        <span class="inline-flex items-center gap-1.5">
            @if(!empty($item['icon']))
            <x-dynamic-component :component="'lucide-'.($item['icon'])" class="size-3.5 {{ $item['class'] ?? 'text-muted-foreground' }}" />
            @endif
            <span aria-hidden="true">→</span> {{ $item['label'] ?? '' }}
        </span>
        @endforeach
    </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-border/60">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-muted/50">
                    <th class="text-left font-semibold px-3 py-2 sticky left-0 bg-muted/50 min-w-44">{{ $matrix['row_label'] ?? 'Employee' }}</th>
                    @foreach($matrix['days'] as $day)
                    <th class="font-medium px-1 py-2 text-center {{ $day['weekend'] ? 'text-muted-foreground/60' : 'text-muted-foreground' }}">
                        <div>{{ $day['n'] }}</div>
                        <div class="text-[9px] uppercase">{{ $day['dow'] }}</div>
                    </th>
                    @endforeach
                    <th class="font-semibold px-3 py-2 text-center">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($matrix['rows'] as $row)
                <tr class="border-t border-border/50">
                    <td class="px-3 py-2 sticky left-0 bg-background">
                        <span class="flex items-center gap-2.5">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-[11px] font-bold">{{ $initials($row['label']) }}</span>
                            <span class="font-medium">{{ $row['label'] }}</span>
                        </span>
                    </td>
                    @foreach($matrix['days'] as $day)
                    @php $cellDate = sprintf('%04d-%02d-%02d', $matrix['year'], $matrix['month'], $day['n']); @endphp
                    <td class="px-1 py-2 text-center {{ $day['weekend'] ? 'bg-muted/30' : '' }}">
                        @isset($row['cells'][$day['n']])
                        <span class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 font-bold">{{ $row['cells'][$day['n']] }}</span>
                        @elseif(($matrix['roster'] ?? false) && $cellDate < $today)
                        <x-lucide-x class="size-3.5 inline text-muted-foreground/50" />
                        @elseif(($matrix['roster'] ?? false) && $cellDate > $today)
                        <span class="text-muted-foreground/40">-</span>
                        @else
                        <span class="text-muted-foreground/40">·</span>
                        @endisset
                    </td>
                    @endforeach
                    <td class="px-3 py-2 text-center font-bold whitespace-nowrap">{{ $row['present'] }} / {{ $matrix['days_in_month'] }}</td>
                </tr>
                @empty
                <tr><td colspan="{{ count($matrix['days']) + 2 }}" class="px-3 py-8 text-center text-muted-foreground">No records in this month.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
