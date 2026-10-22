{{--
    Standard bulk-entry template (G8): one-date grid for any slice
    declaring ui.directory.bulk_entry — rows are the row key's
    relationship options, columns the declared fields. A slice
    overrides it with its own Resources/views/bulk-entry.blade.php.
--}}
@extends('layouts.app')

@section('content')
<div class="space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-2 border-b border-border/40">
        <div class="flex items-center gap-3">
            <h1 class="text-base font-medium tracking-tight text-foreground">{{ $bulk['title'] }}</h1>
            <span class="text-sm text-muted-foreground">{{ date('l, F j, Y', strtotime($date)) }}</span>
        </div>
        <div class="flex items-center gap-2">
            <form method="GET" class="flex items-center gap-2">
                <input type="date" name="date" value="{{ $date }}" class="h-8 rounded-lg border border-border bg-background px-2 text-xs">
                <button type="submit" class="h-8 px-3 rounded-lg bg-primary text-primary-foreground text-xs font-semibold">Show</button>
            </form>
            @if (!empty($matrixUrl))
            <a href="{{ $matrixUrl }}" class="h-8 px-3 inline-flex items-center rounded-lg border border-border text-xs font-semibold">Matrix</a>
            @endif
            <a href="{{ $indexUrl }}" class="h-8 px-3 inline-flex items-center rounded-lg border border-border text-xs font-semibold">List view</a>
        </div>
    </div>

    @if (session('success'))
        <div class="p-3.5 rounded-xl border border-success/30 bg-success/10 text-success text-xs font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ $storeUrl }}">
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        @if (!empty($bulk['range']))
        <div class="mb-4 rounded-xl border border-border/60 bg-muted/20 px-4 py-3" x-data="{ bulkMode: 'single' }">
            <div class="flex flex-wrap items-center gap-4">
                <span class="text-xs font-bold text-foreground">Apply to</span>
                <label class="flex items-center gap-1.5 text-xs font-medium text-foreground cursor-pointer">
                    <input type="radio" value="single" x-model="bulkMode" class="text-primary focus:ring-primary">
                    This date only
                </label>
                <label class="flex items-center gap-1.5 text-xs font-medium text-foreground cursor-pointer">
                    <input type="radio" value="range" x-model="bulkMode" class="text-primary focus:ring-primary">
                    Date range
                </label>
                <div class="flex items-center gap-2" x-show="bulkMode === 'range'" x-cloak>
                    <input type="date" name="from" value="{{ $date }}" x-bind:disabled="bulkMode !== 'range'" x-bind:required="bulkMode === 'range'" class="h-8 rounded-lg border border-border bg-background px-2 text-xs">
                    <span class="text-xs text-muted-foreground">to</span>
                    <input type="date" name="to" value="{{ $date }}" x-bind:disabled="bulkMode !== 'range'" x-bind:required="bulkMode === 'range'" class="h-8 rounded-lg border border-border bg-background px-2 text-xs">
                </div>
            </div>
            @if (!empty($bulk['holidays']))
            <p class="mt-2 text-[11px] text-muted-foreground">In range mode the same times apply to every date; declared holidays are skipped automatically.</p>
            @endif
        </div>
        @endif
        <div class="overflow-x-auto rounded-xl border border-border/60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-muted/50">
                        <th class="text-left font-semibold px-3 py-2 min-w-44">{{ \Illuminate\Support\Str::headline(str_replace('_id', '', $bulk['row_key'])) }}</th>
                        @foreach ($bulk['fields'] as $field)
                        <th class="text-left font-semibold px-3 py-2">{{ $field['label'] }}</th>
                        @endforeach
                        <th class="text-left font-semibold px-3 py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                    <tr class="border-t border-border/50">
                        <td class="px-3 py-2 font-medium">{{ $row['label'] }}</td>
                        @foreach ($bulk['fields'] as $field)
                        <td class="px-3 py-1.5">
                            @if ($field['input'] === 'select')
                            <select name="entries[{{ $row['key'] }}][{{ $field['handle'] }}]" class="h-8 rounded-lg border border-border bg-background px-2 text-xs w-full max-w-44">
                                <option value="">—</option>
                                @foreach ($field['options'] as $opt)
                                <option value="{{ $opt }}" @selected(($row['values'][$field['handle']] ?? '') === $opt)>{{ \Illuminate\Support\Str::headline($opt) }}</option>
                                @endforeach
                            </select>
                            @elseif ($field['input'] === 'time' || $field['input'] === 'datetime')
                            <input type="time" name="entries[{{ $row['key'] }}][{{ $field['handle'] }}]" value="{{ $row['values'][$field['handle']] ?? '' }}" class="h-8 rounded-lg border border-border bg-background px-2 text-xs w-full max-w-36">
                            @else
                            <input type="text" name="entries[{{ $row['key'] }}][{{ $field['handle'] }}]" value="{{ $row['values'][$field['handle']] ?? '' }}" class="h-8 rounded-lg border border-border bg-background px-2 text-xs w-full max-w-44">
                            @endif
                        </td>
                        @endforeach
                        <td class="px-3 py-2">
                            @if ($row['exists'])
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-600">Marked</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-muted text-muted-foreground">Not marked</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="{{ count($bulk['fields']) + 2 }}" class="px-3 py-6 text-center text-muted-foreground">No rows available for bulk entry.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="sticky bottom-4 z-10 mt-4 p-4 rounded-2xl border border-border bg-card/95 backdrop-blur-md shadow-lg flex items-center justify-between">
            <span class="text-xs text-muted-foreground">Rows with an empty {{ strtolower($bulk['fields'][0]['label'] ?? 'first field') }} are skipped; existing records are left untouched.</span>
            <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-xs transition-colors">
                <x-lucide-save class="size-3.5" />
                <span>Save {{ $bulk['title'] }}</span>
            </button>
        </div>
    </form>
</div>
@endsection
