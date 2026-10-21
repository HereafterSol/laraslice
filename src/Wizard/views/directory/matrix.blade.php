{{--
    Standard matrix template (G2): rows x days-of-month grid for any
    slice declaring ui.directory.matrix. A slice overrides it with its
    own Resources/views/matrix.blade.php.
--}}
@extends('layouts.app')

@section('content')
<div class="space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-2 border-b border-border/40">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold tracking-tight text-foreground">{{ $matrix['title'] }}</h1>
            <span class="text-sm text-muted-foreground">{{ date('F Y', mktime(0, 0, 0, $matrix['month'], 1, $matrix['year'])) }}</span>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <select name="month" class="h-8 rounded-lg border border-border bg-background px-2 text-xs">
                @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" @selected($m === $matrix['month'])>{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                @endfor
            </select>
            <select name="year" class="h-8 rounded-lg border border-border bg-background px-2 text-xs">
                @for($y = $matrix['year'] - 3; $y <= $matrix['year'] + 1; $y++)
                <option value="{{ $y }}" @selected($y === $matrix['year'])>{{ $y }}</option>
                @endfor
            </select>
            <button type="submit" class="h-8 px-3 rounded-lg bg-primary text-primary-foreground text-xs font-semibold">Show</button>
            <a href="{{ $indexUrl }}" class="h-8 px-3 inline-flex items-center rounded-lg border border-border text-xs font-semibold">List view</a>
        </form>
    </div>

    <div class="overflow-x-auto rounded-xl border border-border/60">
        <table class="w-full text-xs">
            <thead>
                <tr class="bg-muted/50">
                    <th class="text-left font-semibold px-3 py-2 sticky left-0 bg-muted/50 min-w-44">Member</th>
                    @foreach($matrix['days'] as $day)
                    <th class="font-medium px-1 py-2 text-center {{ $day['weekend'] ? 'text-muted-foreground/60' : 'text-muted-foreground' }}">
                        <div>{{ $day['n'] }}</div>
                        <div class="text-[9px] uppercase">{{ substr($day['dow'], 0, 1) }}</div>
                    </th>
                    @endforeach
                    <th class="font-semibold px-3 py-2 text-center">Present</th>
                </tr>
            </thead>
            <tbody>
                @forelse($matrix['rows'] as $row)
                <tr class="border-t border-border/50">
                    <td class="px-3 py-2 font-medium sticky left-0 bg-background">{{ $row['label'] }}</td>
                    @foreach($matrix['days'] as $day)
                    <td class="px-1 py-2 text-center {{ $day['weekend'] ? 'bg-muted/30' : '' }}">
                        @isset($row['cells'][$day['n']])
                        <span class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 font-bold">{{ $row['cells'][$day['n']] }}</span>
                        @else
                        <span class="text-muted-foreground/40">·</span>
                        @endisset
                    </td>
                    @endforeach
                    <td class="px-3 py-2 text-center font-bold">{{ $row['present'] }}</td>
                </tr>
                @empty
                <tr><td colspan="{{ count($matrix['days']) + 2 }}" class="px-3 py-8 text-center text-muted-foreground">No records in this month.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
