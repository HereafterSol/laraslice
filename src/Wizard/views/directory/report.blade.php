{{--
    Standard report template (G2): grouped aggregates for any slice
    declaring ui.directory.report. A slice overrides it with its own
    Resources/views/report.blade.php.
--}}
@extends('layouts.app')

@section('content')
<div class="space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-2 border-b border-border/40">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold tracking-tight text-foreground">{{ $report['title'] }}</h1>
            <span class="text-sm text-muted-foreground">{{ number_format($report['count']) }} records</span>
        </div>
        <div class="flex items-center gap-2">
            @if($hasPeriod)
            <form method="GET" class="flex items-center gap-2">
                <select name="month" class="h-8 rounded-lg border border-border bg-background px-2 text-xs">
                    @for($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" @selected($m === $month)>{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>
                    @endfor
                </select>
                <select name="year" class="h-8 rounded-lg border border-border bg-background px-2 text-xs">
                    @for($y = $year - 3; $y <= $year + 1; $y++)
                    <option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>
                    @endfor
                </select>
                <button type="submit" class="h-8 px-3 rounded-lg bg-primary text-primary-foreground text-xs font-semibold">Show</button>
            </form>
            @endif
            <a href="{{ $indexUrl }}" class="h-8 px-3 inline-flex items-center rounded-lg border border-border text-xs font-semibold">List view</a>
        </div>
    </div>

    @if(!empty($report['cards']))
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        @foreach($report['cards'] as $card)
        <div class="rounded-xl border border-border/60 px-4 py-3">
            <div class="text-xs text-muted-foreground">{{ $card['label'] }}</div>
            <div class="text-lg font-bold tabular-nums">{{ $card['value'] }}</div>
        </div>
        @endforeach
    </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-border/60">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-muted/50">
                    <th class="text-left font-semibold px-4 py-2.5">{{ $report['groupLabel'] }}</th>
                    @foreach($report['columns'] as $column)
                    <th class="font-semibold px-4 py-2.5 text-right">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($report['rows'] as $row)
                <tr class="border-t border-border/50">
                    <td class="px-4 py-2.5 font-medium">{{ $row['label'] }}</td>
                    @foreach($row['values'] as $value)
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $value }}</td>
                    @endforeach
                </tr>
                @empty
                <tr><td colspan="{{ count($report['columns']) + 1 }}" class="px-4 py-8 text-center text-muted-foreground">No records.</td></tr>
                @endforelse
            </tbody>
            @if($report['rows'] !== [])
            <tfoot>
                <tr class="border-t-2 border-border bg-muted/30 font-bold">
                    <td class="px-4 py-2.5">Total</td>
                    @foreach($report['totals'] as $total)
                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $total }}</td>
                    @endforeach
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
