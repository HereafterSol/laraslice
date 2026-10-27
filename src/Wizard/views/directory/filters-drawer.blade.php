        <!-- Declared Filters (ui.directory.sidebar_filters) — standard list pattern P1:
             a Filters button in the strip opens the full declared set in a right
             drawer; filters apply server-side (G5). Mirrors the reference ERP. -->
        @if(!empty($sidebarFilters ?? []))
        <div x-data="{ filtersOpen: false }" @keydown.escape.window="filtersOpen = false" @open-ls-filters.window="filtersOpen = true">
            <div class="flex items-center justify-end border-b border-border/60 pb-3" data-ls-filters-trigger>
                <button type="button" @click="filtersOpen = true" class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground shadow-2xs transition-colors hover:bg-muted cursor-pointer">
                    <x-lucide-filter class="size-3.5" /> Filters
                    @if(!empty($activeFilters ?? []))
                    <span class="inline-flex items-center justify-center size-4 rounded-full bg-primary text-[10px] font-bold text-primary-foreground">{{ count(array_filter($activeFilters)) }}</span>
                    @endif
                </button>
            </div>
            <div x-show="filtersOpen" x-cloak class="fixed inset-0 z-40 bg-black/40" @click="filtersOpen = false"></div>
            <aside x-show="filtersOpen" x-cloak x-transition:enter="transition transform duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" class="fixed inset-y-0 right-0 z-50 flex w-80 max-w-[90vw] flex-col border-l border-border bg-card shadow-xl" role="dialog" aria-label="Filters">
                <div class="flex items-center justify-between border-b border-border px-4 py-3">
                    <h3 class="text-sm font-semibold text-foreground">Filters</h3>
                    <button type="button" @click="filtersOpen = false" class="size-8 rounded-lg hover:bg-muted/80 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors cursor-pointer" title="Close">
                        <x-lucide-x class="size-4" />
                    </button>
                </div>
                <form method="GET" class="flex flex-1 flex-col overflow-y-auto">
                    <div class="flex flex-1 flex-col gap-4 px-4 py-4">
                @foreach($sidebarFilters as $sf)
                @if(($sf['ui'] ?? true) === false) @continue @endif
                @if(($sf['placement'] ?? 'drawer') === 'strip') @continue @endif
                @php $sfHandle = $sf['handle'] ?? ''; $sfLabel = $sf['label'] ?? \Illuminate\Support\Str::headline($sfHandle); @endphp
                @if(($sf['type'] ?? 'select') === 'number_range')
                <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {{ $sfLabel }} from
                    <input type="number" name="{{ $sfHandle }}_from" value="{{ $activeFilters[$sfHandle . '_from'] ?? '' }}" class="h-9 w-full rounded-lg border border-border bg-background px-2 text-xs font-normal normal-case tracking-normal text-foreground" />
                </label>
                <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {{ $sfLabel }} to
                    <input type="number" name="{{ $sfHandle }}_to" value="{{ $activeFilters[$sfHandle . '_to'] ?? '' }}" class="h-9 w-full rounded-lg border border-border bg-background px-2 text-xs font-normal normal-case tracking-normal text-foreground" />
                </label>
                @elseif(($sf['type'] ?? 'select') === 'date_range')
                <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {{ $sfLabel }} from
                    <input type="date" name="{{ $sfHandle }}_from" value="{{ $activeFilters[$sfHandle . '_from'] ?? '' }}" class="h-9 w-full rounded-lg border border-border bg-background px-2 text-xs font-normal normal-case tracking-normal text-foreground" />
                </label>
                <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {{ $sfLabel }} to
                    <input type="date" name="{{ $sfHandle }}_to" value="{{ $activeFilters[$sfHandle . '_to'] ?? '' }}" class="h-9 w-full rounded-lg border border-border bg-background px-2 text-xs font-normal normal-case tracking-normal text-foreground" />
                </label>
                @else
                <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {{ $sfLabel }}
                    <select name="{{ $sfHandle }}" class="h-9 w-full rounded-lg border border-border bg-background px-2 text-xs font-normal normal-case tracking-normal text-foreground">
                        <option value="">All</option>
                        @foreach($sf['options'] ?? [] as $opt)
                        <option value="{{ $opt['value'] }}" @selected((string) ($activeFilters[$sfHandle] ?? '') === (string) $opt['value'])>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                @endif
                @endforeach
                    </div>
                    <div class="flex items-center gap-2 border-t border-border px-4 py-3">
                        <x-ui.button type="submit" size="sm">Apply Filters</x-ui.button>
                        @if(!empty($activeFilters ?? []))
                        <x-ui.button href="{{ request()->url() }}" as="a" variant="outline" size="sm">Clear</x-ui.button>
                        @endif
                    </div>
                </form>
            </aside>
        </div>
        @endif

<script>
    // List contract v3: when the page's data table carries the
    // toolbar Filters button (data-ls-filters-btn), this standalone
    // trigger row is a duplicate — remove it. Pages whose blades
    // predate the slot keep the trigger as their only opener.
    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('[data-ls-filters-btn]')) {
            document.querySelectorAll('[data-ls-filters-trigger]').forEach(function (e) { e.remove(); });
        }
    });
</script>
