{{--
    Filter chips (Seat 3) — the applied-filter chips
    (operator glyphs, individually removable) shown above
    the directory when builder conditions are active.
    The Sort / List View / Filter tools themselves live
    in the list-tools partial, inside the table's one
    action row (the data-table's toolbar-tools slot).
--}}
@php
    $lsConditions = $filterConditions ?? [];
    $lsFieldsByName = [];
    foreach (($filterFields ?? []) as $lsF) { $lsFieldsByName[$lsF['name']] = $lsF; }
    $lsBaseQuery = request()->query();
    $lsChipUrl = function (array $keep) {
        $qs = request()->query();
        if ($keep === []) { unset($qs['ff']); } else { $qs['ff'] = $keep; }
        return request()->url().($qs ? '?'.http_build_query($qs) : '');
    };
    $lsRawConditions = array_map(fn ($c) => ['field' => $c['field'], 'op' => $c['op'], 'value' => is_array($c['value']) ? implode(',', $c['value']) : $c['value']], $lsConditions);
    $lsSortBy = (string) (request()->query('sortBy', request()->query('sort_by', 'id')));
    $lsSortDesc = filter_var(request()->query('sortDesc', '1'), FILTER_VALIDATE_BOOLEAN);
    $lsSortLabel = $lsFieldsByName[$lsSortBy]['label'] ?? \Illuminate\Support\Str::headline($lsSortBy);
    $lsSortOptions = [];
    foreach (['created_at', 'updated_at'] as $lsPseudo) {
        if (isset($lsFieldsByName[$lsPseudo])) { $lsSortOptions[] = $lsFieldsByName[$lsPseudo]; }
    }
    foreach (($filterFields ?? []) as $lsF) {
        if (!empty($lsF['in_list_view']) && !in_array($lsF['name'], ['created_at', 'updated_at'], true)) { $lsSortOptions[] = $lsF; }
    }
    $lsDeclaredActive = count(array_filter($activeFilters ?? []));
@endphp

@if($lsConditions !== [])
    <div class="mb-3 flex flex-wrap items-center gap-1.5">
        @foreach($lsConditions as $lsIdx => $lsCond)
            @php $lsKeep = $lsRawConditions; unset($lsKeep[$lsIdx]); $lsKeep = array_values($lsKeep); @endphp
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border bg-card py-1 pl-3 pr-1.5 text-xs font-medium text-foreground shadow-2xs">
                {{ $lsCond['label'] }} <span class="text-muted-foreground">{{ $lsCond['glyph'] }}</span> {{ $lsCond['value_label'] }}
                <a href="{{ $lsChipUrl($lsKeep) }}" class="flex size-4 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-destructive" title="Remove filter">×</a>
            </span>
        @endforeach
        <a href="{{ $lsChipUrl([]) }}" class="text-xs font-medium text-muted-foreground hover:text-destructive hover:underline">Clear all</a>
    </div>
@endif
