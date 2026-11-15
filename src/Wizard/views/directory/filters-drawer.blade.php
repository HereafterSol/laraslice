{{--
    Filters drawer — two filter families in one panel:
    the free-form Filter builder (Seat 3: any field +
    operator + value rows, ff[...] params, validated
    server-side against the Heart) on top, and the
    declared sidebar filters (ui.directory.sidebar_filters)
    below. One GET form applies both; the script at the
    bottom swaps the directory in place via lsSwap.
--}}
@php
    $lsHasBuilder = !empty($filterFields ?? []);
    $lsHasDeclared = !empty($sidebarFilters ?? []);
    $lsConditions = $filterConditions ?? [];
    $lsOperators = \LaraSlice\Support\DirectoryConditions::OPERATORS;
    $lsBuilderRows = $lsConditions;
    $lsBuilderRows[] = ['field' => '', 'op' => 'is', 'value' => ''];
@endphp
@if($lsHasBuilder || $lsHasDeclared)
<div x-data="{ filtersOpen: false }" @keydown.escape.window="filtersOpen = false" @open-ls-filters.window="filtersOpen = true" @close-ls-filters.window="filtersOpen = false">
    <div x-show="filtersOpen" x-cloak class="fixed inset-0 z-40 bg-black/40" @click="filtersOpen = false"></div>
    <aside x-show="filtersOpen" x-cloak x-transition:enter="transition transform duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" class="fixed inset-y-0 right-0 z-50 flex w-[26rem] max-w-[92vw] flex-col border-l border-border bg-card shadow-xl" role="dialog" aria-label="Filters">
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <h3 class="text-sm font-semibold text-foreground">Filters</h3>
            <button type="button" @click="filtersOpen = false" class="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/80 hover:text-foreground cursor-pointer" title="Close">
                <x-lucide-x class="size-4" />
            </button>
        </div>
        <form method="GET" data-ls-drawer-form class="flex flex-1 flex-col overflow-y-auto">
            <div class="flex flex-1 flex-col gap-4 px-4 py-4">
                @if($lsHasBuilder)
                <div data-ls-ff data-ls-ff-spec="{{ json_encode(collect($filterFields)->keyBy('name')) }}">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Filter by any field</p>
                    <div class="mt-2 space-y-2" data-ls-ff-rows>
                        @foreach($lsBuilderRows as $lsIdx => $lsRow)
                        @php
                            $lsRowField = collect($filterFields)->firstWhere('name', $lsRow['field'] ?? '');
                            $lsRowValue = is_array($lsRow['value'] ?? null) ? implode(',', $lsRow['value']) : ($lsRow['value'] ?? '');
                        @endphp
                        <div class="flex items-center gap-1.5" data-ls-ff-row>
                            <select name="ff[{{ $lsIdx }}][field]" data-ls-ff-field class="h-9 w-[7.5rem] shrink-0 rounded-lg border border-border bg-background px-1.5 text-xs text-foreground">
                                @foreach($filterFields as $lsField)
                                <option value="{{ $lsField['name'] }}" @selected(($lsRow['field'] ?? '') === $lsField['name'])>{{ $lsField['label'] }}</option>
                                @endforeach
                            </select>
                            <select name="ff[{{ $lsIdx }}][op]" data-ls-ff-op class="h-9 w-[6.5rem] shrink-0 rounded-lg border border-border bg-background px-1.5 text-xs text-foreground">
                                @foreach($lsOperators as $lsOp => [$lsGlyph, $lsOpLabel])
                                <option value="{{ $lsOp }}" @selected(($lsRow['op'] ?? 'is') === $lsOp)>{{ $lsGlyph }} {{ $lsOpLabel }}</option>
                                @endforeach
                            </select>
                            <span class="min-w-0 flex-1" data-ls-ff-value>
                                @if(!empty($lsRowField['options']) && in_array($lsRow['op'] ?? 'is', ['is', 'is_not'], true))
                                <select name="ff[{{ $lsIdx }}][value]" class="h-9 w-full rounded-lg border border-border bg-background px-1.5 text-xs text-foreground">
                                    <option value="">Any</option>
                                    @foreach($lsRowField['options'] as $lsOpt)
                                    <option value="{{ $lsOpt['value'] }}" @selected((string) $lsRowValue === (string) $lsOpt['value'])>{{ $lsOpt['label'] }}</option>
                                    @endforeach
                                </select>
                                @else
                                <input type="{{ in_array($lsRowField['type'] ?? '', ['date', 'datetime'], true) ? 'date' : 'text' }}" name="ff[{{ $lsIdx }}][value]" value="{{ $lsRowValue }}" placeholder="Value" class="h-9 w-full rounded-lg border border-border bg-background px-2 text-xs text-foreground" />
                                @endif
                            </span>
                            <button type="button" data-ls-ff-remove class="flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-destructive cursor-pointer" title="Remove row">×</button>
                        </div>
                        @endforeach
                    </div>
                    <button type="button" data-ls-ff-add class="mt-2.5 inline-flex items-center gap-1 text-xs font-semibold text-foreground hover:underline cursor-pointer">
                        <x-lucide-plus class="size-3.5" /> Add Filter
                    </button>
                </div>
                @endif

                @if($lsHasDeclared)
                <div class="space-y-4 border-t border-border/60 pt-4">
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
                @endif
            </div>
            <div class="flex items-center gap-2 border-t border-border px-4 py-3">
                <x-ui.button type="submit" size="sm">Apply Filters</x-ui.button>
                @if(!empty($activeFilters ?? []) || !empty($lsConditions))
                <x-ui.button href="{{ request()->url() }}" as="a" variant="outline" size="sm">Clear</x-ui.button>
                @endif
            </div>
        </form>
    </aside>
</div>

<script>
    // Filter builder rows (Seat 3): add/remove rows, and
    // keep each row's value control honest for its field
    // + operator — a select for option fields under
    // is/is_not, a date input for date fields, no input
    // at all for empty/not_empty.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-ls-ff]').forEach(function (builder) {
            let spec = {};
            try { spec = JSON.parse(builder.getAttribute('data-ls-ff-spec') || '{}'); } catch (e) { spec = {}; }
            const rows = builder.querySelector('[data-ls-ff-rows]');

            function syncValue(row) {
                const fieldSel = row.querySelector('[data-ls-ff-field]');
                const opSel = row.querySelector('[data-ls-ff-op]');
                const wrap = row.querySelector('[data-ls-ff-value]');
                if (!fieldSel || !opSel || !wrap) return;
                const idx = fieldSel.name.match(/ff\[(\d+)\]/)[1];
                const current = wrap.querySelector('input, select');
                const value = current ? current.value : '';
                const def = spec[fieldSel.value] || {};
                const op = opSel.value;
                let html = '';
                if (op === 'empty' || op === 'not_empty') {
                    html = '<span class="flex h-9 items-center px-1 text-xs text-muted-foreground">—</span>'
                        + '<input type="hidden" name="ff[' + idx + '][value]" value="" />';
                } else if (def.options && def.options.length && (op === 'is' || op === 'is_not')) {
                    html = '<select name="ff[' + idx + '][value]" class="h-9 w-full rounded-lg border border-border bg-background px-1.5 text-xs text-foreground">'
                        + '<option value="">Any</option>'
                        + def.options.map(function (o) {
                            return '<option value="' + String(o.value).replace(/"/g, '&quot;') + '"' + (String(o.value) === String(value) ? ' selected' : '') + '>' + String(o.label) + '</option>';
                        }).join('') + '</select>';
                } else {
                    const type = (def.type === 'date' || def.type === 'datetime') ? 'date' : 'text';
                    html = '<input type="' + type + '" name="ff[' + idx + '][value]" value="' + String(value).replace(/"/g, '&quot;') + '" placeholder="Value" class="h-9 w-full rounded-lg border border-border bg-background px-2 text-xs text-foreground" />';
                }
                wrap.innerHTML = html;
            }

            function wire(row) {
                row.querySelector('[data-ls-ff-field]')?.addEventListener('change', function () { syncValue(row); });
                row.querySelector('[data-ls-ff-op]')?.addEventListener('change', function () { syncValue(row); });
                row.querySelector('[data-ls-ff-remove]')?.addEventListener('click', function () {
                    if (rows.children.length > 1) { row.remove(); }
                    else {
                        const v = row.querySelector('[data-ls-ff-value] input, [data-ls-ff-value] select');
                        if (v) v.value = '';
                    }
                });
            }

            rows.querySelectorAll('[data-ls-ff-row]').forEach(wire);
            builder.querySelector('[data-ls-ff-add]')?.addEventListener('click', function () {
                const last = rows.querySelector('[data-ls-ff-row]:last-child');
                if (!last) return;
                const clone = last.cloneNode(true);
                const next = rows.children.length;
                clone.querySelectorAll('[name]').forEach(function (el) {
                    el.name = el.name.replace(/ff\[\d+\]/, 'ff[' + next + ']');
                });
                const v = clone.querySelector('[data-ls-ff-value] input, [data-ls-ff-value] select');
                if (v) v.value = '';
                rows.appendChild(clone);
                wire(clone);
                syncValue(clone);
            });
        });
    });

    // AJAX drawer Apply (owner-approved 10 Nov 2026): submitting
    // the filters drawer swaps the directory in place through
    // the data table's lsSwap (the same fragment round-trip the
    // quick filters use) instead of reloading the whole page,
    // then closes the drawer. The target URL starts from the
    // current address (view state and other params survive,
    // exactly like the quick-filter path); the form's own
    // fields are then authoritative — stale values for its
    // handles are removed before the chosen ones are set.
    // When no live data table is present (or anything is
    // missing), the listener steps aside and the form submits
    // natively; a failed swap falls back to full navigation
    // inside lsSwap.
    document.addEventListener('submit', function (e) {
        const form = e.target.closest('form[data-ls-drawer-form]');
        if (!form) return;
        const dirRoot = document.querySelector('[data-ls-dir-root]');
        const data = (window.Alpine && dirRoot) ? Alpine.$data(dirRoot) : null;
        if (!data || typeof data.lsSwap !== 'function') return;
        e.preventDefault();
        const fd = new FormData(form);
        const u = new URL(window.location.href);
        // Remove every ff[...] key first: builder rows are
        // re-added wholesale below, so removed rows and
        // cleared values never linger as stale filters.
        for (const key of [...u.searchParams.keys()]) {
            if (key.startsWith('ff[')) u.searchParams.delete(key);
        }
        fd.forEach(function (v, k) { u.searchParams.delete(k); });
        fd.forEach(function (v, k) { if (v !== '' && v !== null) u.searchParams.set(k, v); });
        Promise.resolve(data.lsSwap(u)).then(function () {
            window.dispatchEvent(new CustomEvent('close-ls-filters'));
        });
    }, true);
</script>
@endif
