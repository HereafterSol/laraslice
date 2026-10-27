{{--
    External filter bar (source composition, 25 Oct 2026): on ERP
    pages the data-table component pushes this bar into the layout's
    'filterbar' stack, so the facets + search + Filters sit directly
    under the topbar — full width, fused with the chrome — instead
    of inside the content below the page title.

    The table lives in the content area, so every control here talks
    to it through window events: the facet selects already dispatch
    'filter-changed'; search dispatches 'table-search'; Clear
    dispatches 'table-clear-filters'; the Filters button dispatches
    'open-sheet-table-filter-drawer'. The component broadcasts its
    filter counts back ('table-filters-count' /
    'table-sidebar-count') for the Clear / badge states.
--}}
<style>
    /* The Filters button (emitted into the quick-filters slot by
       the generator) closes the strip at its right edge, as in the
       source composition. */
    [data-ls-filterbar] [data-ls-filters-btn] { margin-left: auto; }
</style>
<div data-ls-filterbar x-data="{ qv: '', fcount: 0, scount: 0 }"
     @table-filters-count.window="fcount = $event.detail"
     @table-sidebar-count.window="scount = $event.detail"
     class="flex flex-col gap-3 border-b border-border bg-background/95 px-4 py-2 backdrop-blur-md md:flex-row md:items-center md:justify-between lg:px-6">
    <div class="flex flex-1 flex-wrap items-center gap-2.5">
        @isset($toolbarLead)
            {{ $toolbarLead }}
        @endisset

        @isset($quickFilters)
            {{ $quickFilters }}
        @endisset

        {{-- Strip-placed segments (e.g. a declared date range)
             fuse into this same band — layout kit step 2. --}}
        @stack('filterbar-extra')

        @if ($searchable)
            <div class="relative w-full max-w-xs">
                <x-lucide-search class="size-3.5 pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground/70" />
                <input type="text" x-model="qv" @input="$dispatch('table-search', qv)"
                    placeholder="{{ $searchPlaceholder }}"
                    class="w-full rounded-xl border border-border/80 bg-background py-1.5 pl-8.5 pr-7 text-xs shadow-2xs placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-primary" />
                <button type="button" x-show="qv" x-cloak
                    @click="qv = ''; $dispatch('table-search', '')"
                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                    title="Clear search">
                    <x-lucide-x class="size-3" />
                </button>
            </div>
        @endif

        <button type="button" x-show="fcount > 0" x-cloak
            @click="$dispatch('table-clear-filters'); qv = ''; $dispatch('table-search', '')"
            class="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-border bg-card px-2.5 py-1 text-xs font-semibold text-muted-foreground shadow-2xs transition-colors hover:text-foreground">
            <x-lucide-x-circle class="size-3.5 text-rose-500" />
            <span>Clear</span>
        </button>
    </div>
</div>
