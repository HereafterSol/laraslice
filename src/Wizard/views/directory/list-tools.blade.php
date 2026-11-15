{{--
    List tools (Seat 3) — the directory's Sort menu, List
    View switcher (saved views) and Filter button. These
    render INSIDE the data-table's one action row (the
    toolbar-tools slot), beside the view switcher and
    Columns menu — one coherent toolbar, never a separate
    floating row. Applied-filter chips live in the sibling
    list-controls partial (index flow).
--}}
@php
    $lsConditions = $filterConditions ?? [];
    $lsFieldsByName = [];
    foreach (($filterFields ?? []) as $lsF) { $lsFieldsByName[$lsF['name']] = $lsF; }
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

<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" @click="open = !open" class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs transition-colors hover:bg-muted cursor-pointer">
        <x-lucide-arrow-down-wide-narrow class="size-3.5" />
        <span>Sort: {{ $lsSortLabel }}</span>
        <x-lucide-chevron-down class="size-3.5 text-muted-foreground" />
    </button>
    <div x-show="open" x-cloak class="absolute right-0 z-30 mt-1.5 max-h-72 w-52 overflow-y-auto rounded-xl border border-border bg-card p-1 shadow-lg">
        @foreach($lsSortOptions as $lsOpt)
            <a href="{{ request()->fullUrlWithQuery(['sortBy' => $lsOpt['name'], 'sortDesc' => in_array($lsOpt['type'], ['date', 'datetime', 'number'], true) || in_array($lsOpt['name'], ['created_at', 'updated_at', 'id'], true) ? '1' : '0']) }}"
               class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium {{ $lsSortBy === $lsOpt['name'] ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                {{ $lsOpt['label'] }}
                @if($lsSortBy === $lsOpt['name'])<span class="text-[10px]">{{ $lsSortDesc ? '↓' : '↑' }}</span>@endif
            </a>
        @endforeach
        <div class="my-1 border-t border-border/60"></div>
        <a href="{{ request()->fullUrlWithQuery(['sortBy' => $lsSortBy, 'sortDesc' => $lsSortDesc ? '0' : '1']) }}" class="block rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">{{ $lsSortDesc ? '↑ Ascending' : '↓ Descending' }}</a>
    </div>
</div>

<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" @click="open = !open" class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs transition-colors hover:bg-muted cursor-pointer">
        <x-lucide-list class="size-3.5" />
        <span>List View</span>
        <x-lucide-chevron-down class="size-3.5 text-muted-foreground" />
    </button>
    <div x-show="open" x-cloak class="absolute right-0 z-30 mt-1.5 w-60 rounded-xl border border-border bg-card p-1 shadow-lg">
        <a href="{{ request()->url() }}" class="block rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">Default view</a>
        @foreach(($listViews ?? []) as $lsView)
            <div class="flex items-center gap-1">
                <a href="{{ route('laraslice.list_views.apply', $lsView['id']) }}" class="min-w-0 flex-1 truncate rounded-lg px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted">{{ $lsView['name'] }}</a>
                <form method="POST" action="{{ route('laraslice.list_views.destroy', $lsView['id']) }}" onsubmit="return confirm('Remove this list view?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-1.5 text-xs text-muted-foreground hover:text-destructive" title="Remove view">×</button>
                </form>
            </div>
        @endforeach
        <div class="my-1 border-t border-border/60"></div>
        <form method="POST" action="{{ route('laraslice.list_views.store') }}" class="flex items-center gap-1.5 p-1" data-ls-view-save>
            @csrf
            <input type="hidden" name="slice" value="{{ $sliceName ?? '' }}">
            <input type="hidden" name="state" value="{{ json_encode(request()->query()) }}" data-ls-view-state>
            <input type="text" name="name" required maxlength="120" placeholder="Save current as…" class="h-8 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-xs">
            <button type="submit" class="h-8 shrink-0 rounded-md border border-input bg-background px-2.5 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Save</button>
        </form>
    </div>
</div>

<button type="button" data-ls-filters-btn @click="$dispatch('open-ls-filters')" class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-2xs transition-colors hover:bg-muted cursor-pointer">
    <x-lucide-filter class="size-3.5" />
    <span>Filter</span>
    @if(count($lsConditions) + $lsDeclaredActive > 0)
        <span class="inline-flex size-4 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground">{{ count($lsConditions) + $lsDeclaredActive }}</span>
    @endif
</button>

<script>
    // Save-view state is captured at SUBMIT time from the
    // live address bar (the drawer's AJAX swaps pushState
    // new filter params long after the page rendered), and
    // bracket keys are re-nested so the stored state can
    // rebuild ff[0][field]-style params on apply.
    document.addEventListener('submit', function (e) {
        const form = e.target.closest('form[data-ls-view-save]');
        if (!form) return;
        const state = {};
        new URLSearchParams(window.location.search).forEach(function (value, key) {
            const parts = key.replace(/]/g, '').split('[');
            let node = state;
            parts.forEach(function (part, i) {
                if (i === parts.length - 1) { node[part] = value; }
                else { node[part] = node[part] || (parts[i + 1] === '' || /^\d+$/.test(parts[i + 1]) ? [] : {}); node = node[part]; }
            });
        });
        const input = form.querySelector('[data-ls-view-state]');
        if (input) input.value = JSON.stringify(state);
    }, true);
</script>
