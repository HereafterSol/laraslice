@props([
    'columns' => [],      // [['key'=>'name','label'=>'Name','sortable'=>true,'class'=>''], ...]
    'rows' => [],         // [['name'=>'…','email'=>'…'], ...]
    'searchable' => true,
    'searchKey' => null,  // column key to search; defaults to all column keys
    'searchPlaceholder' => null,
    'selectable' => true,
    'pageSize' => 10,
    'rowKey' => 'id',     // row-data key used for stable :keys and passed to the actions slot (item.r[rowKey])
    'actionsLabel' => null,        // header label for the row-actions column (kept sr-only)
    'stickyActions' => false,      // freeze the actions column to the right edge on horizontal scroll
    'exportFilename' => 'export',
    'workflowTransitions' => [], // [['slug'=>'approve','label'=>'Approve','requires_remarks'=>true], ...]
    'workflowBulkUrl' => null,   // route for POST bulk transition
    'workflowSlice' => null,
    'views' => ['table'],        // YAML ui.directory.views: table | board | calendar
    'defaultView' => 'table',    // YAML ui.directory.default_view
    'statusKey' => 'status',     // column key used for board columns
    'inlineEdit' => [],          // YAML ui.directory.inline_edit e.g. ['status']
    'dateKey' => null,           // preferred date column for calendar view
    'density' => 'comfortable',  // comfortable | compact (ERP high-density grids)
])

@php
    $searchPlaceholder ??= __('Start typing to search...');
    $actionsLabel ??= __('Action');

    $cols = collect($columns)->map(fn ($c) => [
        'key' => $c['key'] ?? '',
        'label' => $c['label'] ?? ucfirst($c['key'] ?? ''),
        'sortable' => $c['sortable'] ?? true,
        'class' => $c['class'] ?? '',
        'html' => (bool) ($c['html'] ?? in_array($c['formatter'] ?? '', ['composite_user', 'code_badge', 'status_pill', 'money_breakdown', 'progress_bar', 'text_with_badge', 'date_overdue'], true)),
    ])->values();
    $searchKeys = $searchKey ? [$searchKey] : $cols->pluck('key')->all();
@endphp

<div
    data-slot="data-table"
    x-data="blatDataTable({
        pageSize: @js((int) $pageSize),
        rows: @js(array_values($rows)),
        cols: @js($cols),
        searchKeys: @js($searchKeys),
        exportFilename: @js($exportFilename),
        workflowTransitions: @js($workflowTransitions ?? []),
        workflowBulkUrl: @js($workflowBulkUrl),
        workflowSlice: @js($workflowSlice),
        csrf: @js(csrf_token()),
        views: @js(array_values($views ?: ['table'])),
        defaultView: @js($defaultView ?: 'table'),
        statusKey: @js($statusKey ?: 'status'),
        inlineEdit: @js(array_values($inlineEdit ?: [])),
        dateKey: @js($dateKey),
        workflowTransitionUrlTemplate: @js(
            \Illuminate\Support\Facades\Route::has('workflows.transition')
                ? url('/admin/workflows/transition/'.($workflowSlice ?: '__SLICE__').'/__ID__')
                : null
        ),
    })"
    x-init="$watch('q', () => page = 1)"
    @export-table.window="exportCsv()"
    @filter-changed.window="setFilter($event.detail.key, $event.detail.val)"
    @filter-reset.window="clearFilters()"
    {{ $attributes->twMerge('w-full space-y-3') }}
>
    <!-- Sticky Top Filter Bar (Matches ERP Screenshot 1, 3 & 4) -->
    <div class="sticky top-0 z-20 bg-background/95 backdrop-blur-md py-2 border-b border-border/60 transition-all flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <!-- Left: Quick Filters & Search -->
        <div class="flex items-center gap-2.5 flex-1 flex-wrap">
            @isset($quickFilters)
                {{ $quickFilters }}
            @endisset

            @if ($searchable)
                <div class="relative w-full max-w-xs">
                    <x-lucide-search class="size-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground/70 pointer-events-none" />
                    <input 
                        type="text" 
                        x-model="q" 
                        placeholder="{{ $searchPlaceholder }}" 
                        class="w-full pl-8.5 pr-7 py-1.5 rounded-xl border border-border/80 bg-background text-xs placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs" 
                    />
                    <button 
                        type="button" 
                        x-show="q" 
                        @click="q = ''" 
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground p-0.5 rounded cursor-pointer"
                        title="Clear search"
                    >
                        <x-lucide-x class="size-3" />
                    </button>
                </div>
            @endif

            <!-- Inline Clear Filter Button (Matches Screenshot 4) -->
            <button 
                type="button" 
                x-show="activeFiltersCount > 0" 
                x-cloak
                @click="clearFilters()" 
                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border border-border bg-card text-muted-foreground hover:text-foreground text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
            >
                <x-lucide-x-circle class="size-3.5 text-rose-500" />
                <span>Clear</span>
            </button>
        </div>

        <!-- Right: Bulk Actions OR Y Filters Button & Table Actions -->
        <div class="flex items-center gap-2">
            <!-- Reactive Bulk Actions Strip -->
            <template x-if="selected.length > 0">
                <div class="flex items-center gap-2 px-3 py-1 rounded-xl bg-primary/10 border border-primary/20 text-xs transition-all animate-in fade-in">
                    <span class="font-bold text-primary" x-text="selected.length + ' selected'"></span>
                    <select 
                        x-model="bulkAction" 
                        class="px-2 py-1 rounded-lg border border-border bg-background text-foreground text-xs font-medium focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="none">No Action</option>
                        <option value="delete">Delete Selected</option>
                        <option value="export">Export Selected</option>
                        <template x-for="wt in workflowTransitions" :key="wt.slug">
                            <option :value="'wf:' + wt.slug" x-text="(wt.label || wt.slug)"></option>
                        </template>
                    </select>
                    <button 
                        type="button" 
                        @click="applyBulkAction()" 
                        class="px-2.5 py-1 rounded-lg bg-primary text-primary-foreground font-semibold text-xs hover:opacity-90 transition-opacity cursor-pointer shadow-2xs"
                    >
                        Apply
                    </button>
                    <button 
                        type="button" 
                        @click="selected = []" 
                        class="text-muted-foreground hover:text-foreground text-xs underline cursor-pointer ml-1"
                    >
                        Clear
                    </button>
                </div>
            </template>

            <!-- Y Filters Button (Matches Screenshot 1 & 3) -->
            @isset($sidebarFilters)
                <button 
                    type="button" 
                    @click="$dispatch('open-sheet-table-filter-drawer')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors cursor-pointer"
                    :class="sidebarFilterCount > 0 ? 'border-primary/50 text-primary font-semibold' : ''"
                >
                    <x-lucide-filter class="size-3.5" ::class="sidebarFilterCount > 0 ? 'text-primary' : 'text-muted-foreground'" />
                    <span>Filters</span>
                    <span 
                        x-show="sidebarFilterCount > 0" 
                        x-cloak 
                        class="size-4 rounded-full bg-primary text-primary-foreground text-[10px] font-bold flex items-center justify-center ml-0.5"
                        x-text="sidebarFilterCount"
                    ></span>
                </button>
            @endisset


            <!-- YAML-driven view switcher (table / board / calendar) -->
            <template x-if="views && views.length > 1">
                <div class="inline-flex items-center rounded-xl border border-border bg-card p-0.5 shadow-2xs">
                    <template x-for="v in views" :key="v">
                        <button type="button" @click="viewMode = v"
                            class="px-2.5 py-1 rounded-lg text-[11px] font-semibold capitalize transition-colors cursor-pointer"
                            :class="viewMode === v ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                            x-text="v"></button>
                    </template>
                </div>
            </template>

            @isset($tableActions)
                {{ $tableActions }}
            @endisset
        </div>
    </div>

    <!-- Board view (YAML ui.directory.views includes board) -->
    <div x-show="viewMode === 'board'" x-cloak class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 pt-2">
        <template x-for="col in boardColumns" :key="col.key">
            <div class="rounded-2xl border border-border bg-card/80 min-h-[200px] flex flex-col">
                <div class="px-3 py-2 border-b border-border flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wide text-foreground" x-text="col.label"></span>
                    <span class="text-[10px] font-mono text-muted-foreground" x-text="col.rows.length"></span>
                </div>
                <div class="p-2 space-y-2 flex-1 overflow-y-auto max-h-[28rem]">
                    <template x-for="row in col.rows" :key="row[rowKey] || JSON.stringify(row)">
                        <div class="rounded-xl border border-border/80 bg-background p-2.5 shadow-2xs text-xs space-y-1">
                            <template x-for="c in cols.slice(0, 3)" :key="c.key">
                                <div class="truncate" x-html="(c.html && row[c.key]) ? row[c.key] : (row[c.key] ?? '—')"></div>
                            </template>
                        </div>
                    </template>
                    <p x-show="col.rows.length === 0" class="text-[11px] text-muted-foreground text-center py-6">Empty</p>
                </div>
            </div>
        </template>
    </div>

    <!-- Calendar placeholder (hook for full calendar component) -->
    <div x-show="viewMode === 'calendar'" x-cloak class="space-y-3 pt-2">
        <div class="flex items-center justify-between gap-2">
            <button type="button" @click="calPrevMonth()" class="rounded-lg border border-border px-2.5 py-1 text-xs font-semibold hover:bg-muted cursor-pointer">←</button>
            <span class="text-sm font-bold text-foreground" x-text="calMonthLabel"></span>
            <button type="button" @click="calNextMonth()" class="rounded-lg border border-border px-2.5 py-1 text-xs font-semibold hover:bg-muted cursor-pointer">→</button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-[10px] font-bold uppercase tracking-wide text-muted-foreground text-center">
            <template x-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d"><div class="py-1" x-text="d"></div></template>
        </div>
        <div class="grid grid-cols-7 gap-1">
            <template x-for="cell in calCells" :key="cell.key">
                <div class="min-h-[5.5rem] rounded-xl border border-border/70 bg-card p-1.5 flex flex-col gap-1"
                     :class="cell.inMonth ? '' : 'opacity-40'">
                    <span class="text-[11px] font-bold text-muted-foreground" x-text="cell.day"></span>
                    <template x-for="row in cell.rows.slice(0, 3)" :key="row[rowKey] || JSON.stringify(row)">
                        <div class="rounded-md bg-primary/10 text-primary text-[10px] px-1 py-0.5 truncate font-medium"
                             x-text="calendarLabel(row)"></div>
                    </template>
                    <span x-show="cell.rows.length > 3" class="text-[10px] text-muted-foreground" x-text="'+' + (cell.rows.length - 3) + ' more'"></span>
                </div>
            </template>
        </div>
        <p class="text-[11px] text-muted-foreground" x-show="!resolvedDateKey">No date column detected — set a date field in Slice Studio (due_date, start_date, …).</p>
    </div>

    <!-- Off-Canvas Right Filter Sidebar Drawer (Matches ERP Screenshot 3 & 4) -->
    @isset($sidebarFilters)
        <x-ui.sheet id="table-filter-drawer">
            <x-ui.sheet-content :show-close="false" side="right" class="w-80 sm:w-96 p-0 flex flex-col justify-between h-full bg-card">
                <!-- Drawer Header -->
                <div class="px-5 py-4 border-b border-border flex items-center justify-between bg-muted/20">
                    <div class="flex items-center gap-2">
                        <x-lucide-sliders-horizontal class="size-4 text-primary" />
                        <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">Filters</h3>
                    </div>
                    <button type="button" @click="open = false; $dispatch('close-sheet-table-filter-drawer')" class="size-7 rounded-lg hover:bg-muted flex items-center justify-center text-muted-foreground hover:text-foreground cursor-pointer" title="Close">
                        <x-lucide-x class="size-4" />
                    </button>
                </div>

                <!-- Drawer Content -->
                <div class="p-5 space-y-4 flex-1 overflow-y-auto text-xs">
                    {{ $sidebarFilters }}
                </div>

                <!-- Drawer Footer -->
                <div class="p-4 border-t border-border flex items-center justify-between bg-muted/20">
                    <button 
                        type="button" 
                        @click="$dispatch('filter-reset')" 
                        class="px-3 py-1.5 rounded-lg border border-border bg-card text-muted-foreground hover:text-foreground text-xs font-semibold cursor-pointer"
                    >
                        Clear All
                    </button>
                    <x-ui.button size="sm" @click="$dispatch('close-sheet-table-filter-drawer')" class="font-semibold shadow-xs">
                        Apply Filters
                    </x-ui.button>
                </div>
            </x-ui.sheet-content>
        </x-ui.sheet>
    @endisset

    <!-- High-Density Data Grid -->
    <div x-show="viewMode === 'table' || !views || views.length <= 1" x-cloak>
    <div class="relative w-full overflow-x-auto rounded-xl border border-border bg-card shadow-2xs">
        <table data-slot="table" class="w-full caption-bottom text-xs">
            <thead data-slot="table-header" class="bg-muted/30 border-b border-border">
                <tr class="hover:bg-muted/50 border-b transition-colors">
                    @if ($selectable)
                        <th scope="col" class="h-9 w-10 px-3 text-start align-middle">
                            <span class="sr-only">{{ __('Select') }}</span>
                            <button type="button" role="checkbox" aria-label="{{ __('Select all rows') }}" @click="toggleAll()" :aria-checked="allPageSelected" :data-state="allPageSelected ? 'checked' : 'unchecked'"
                                class="border-input data-[state=checked]:bg-primary data-[state=checked]:border-primary data-[state=checked]:text-primary-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex size-4 items-center justify-center rounded-[4px] border shadow-xs outline-none focus-visible:ring-[3px]">
                                <x-lucide-check class="size-3.5" x-show="allPageSelected" x-cloak aria-hidden="true" />
                            </button>
                        </th>
                    @endif
                    @foreach ($cols as $col)
                        <th scope="col" @if ($col['sortable']) :aria-sort="sortKey === '{{ $col['key'] }}' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'" @endif
                            class="text-muted-foreground h-9 px-3 text-start align-middle font-semibold whitespace-nowrap uppercase text-[11px] tracking-wider {{ $col['class'] }}">
                            @if ($col['sortable'])
                                <button type="button" @click="toggleSort('{{ $col['key'] }}')" class="hover:text-foreground focus-visible:border-ring focus-visible:ring-ring/50 -ms-1 inline-flex items-center gap-1 rounded px-1 py-0.5 transition-colors outline-none cursor-pointer">
                                    <span>{{ $col['label'] }}</span>
                                    <span class="text-muted-foreground/70 inline-flex" aria-hidden="true">
                                        <span x-show="sortKey === '{{ $col['key'] }}' && sortDir === 'asc'" x-cloak><x-lucide-chevron-up class="size-3" /></span>
                                        <span x-show="sortKey === '{{ $col['key'] }}' && sortDir === 'desc'" x-cloak><x-lucide-chevron-down class="size-3" /></span>
                                        <span x-show="sortKey !== '{{ $col['key'] }}'"><x-lucide-chevrons-up-down class="size-3 opacity-40" /></span>
                                    </span>
                                </button>
                            @else
                                {{ $col['label'] }}
                            @endif
                        </th>
                    @endforeach
                    @isset($actions)
                        <th scope="col" @class([
                            'text-muted-foreground h-9 px-3 text-end align-middle font-semibold whitespace-nowrap uppercase text-[11px] tracking-wider w-14',
                            'sticky end-0 border-s bg-card' => $stickyActions,
                        ])>
                            <span>{{ $actionsLabel }}</span>
                        </th>
                    @endisset
                </tr>
            </thead>
            <tbody data-slot="table-body" class="divide-y divide-border/50">
                <template x-for="item in paged" :key="item.r['{{ $rowKey }}'] ?? item.i">
                    <tr class="hover:bg-muted/40 data-[state=selected]:bg-muted/30 border-b border-border/40 transition-colors" :data-state="selected.includes(item.i) ? 'selected' : null">
                        @if ($selectable)
                            <td class="w-10 px-3 py-2.5 align-middle">
                                <button type="button" role="checkbox" aria-label="{{ __('Select row') }}" @click="toggleRow(item.i)" :aria-checked="selected.includes(item.i)" :data-state="selected.includes(item.i) ? 'checked' : 'unchecked'"
                                    class="border-input data-[state=checked]:bg-primary data-[state=checked]:border-primary data-[state=checked]:text-primary-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex size-4 items-center justify-center rounded-[4px] border shadow-xs outline-none focus-visible:ring-[3px]">
                                    <x-lucide-check class="size-3.5" x-show="selected.includes(item.i)" x-cloak aria-hidden="true" />
                                </button>
                            </td>
                        @endif
                        @foreach ($cols as $col)
                            @if (($col['key'] ?? '') === ($statusKey ?? 'status') && in_array($statusKey ?? 'status', $inlineEdit ?: [], true))
                                <td class="{{ ($density ?? 'comfortable') === 'compact' ? 'px-2 py-1.5' : 'px-3 py-2.5' }} align-middle whitespace-nowrap {{ $col['class'] }}">
                                    <select
                                        class="h-8 min-w-[7.5rem] rounded-lg border border-border bg-background px-2 text-[11px] font-semibold text-foreground focus:outline-none focus:ring-1 focus:ring-primary cursor-pointer"
                                        :value="item.r['{{ $col['key'] }}__raw'] || ''"
                                        @change="applyInlineTransition(item.r, $event.target.value)"
                                    >
                                        <option value="" x-text="item.r['{{ $col['key'] }}__raw'] || '—'"></option>
                                        <template x-for="wt in workflowTransitions" :key="wt.slug">
                                            <option :value="wt.slug" x-text="wt.label || wt.slug"></option>
                                        </template>
                                    </select>
                                </td>
                            @elseif (!empty($col['html']))
                                <td class="{{ ($density ?? 'comfortable') === 'compact' ? 'px-2 py-1.5' : 'px-3 py-2.5' }} align-middle whitespace-nowrap {{ $col['class'] }}" x-html="item.r['{{ $col['key'] }}']"></td>
                            @else
                                <td class="{{ ($density ?? 'comfortable') === 'compact' ? 'px-2 py-1.5' : 'px-3 py-2.5' }} align-middle whitespace-nowrap text-foreground {{ $col['class'] }}" x-text="item.r['{{ $col['key'] }}']"></td>
                            @endif
                        @endforeach
                        @isset($actions)
                            <td @class([
                                'px-3 py-2.5 align-middle whitespace-nowrap text-end w-14',
                                'sticky end-0 border-s bg-card' => $stickyActions,
                            ])>
                                <div class="flex items-center justify-end">{{ $actions }}</div>
                            </td>
                        @endisset
                    </tr>
                </template>
                <tr x-show="paged.length === 0">
                    <td colspan="{{ $cols->count() + ($selectable ? 1 : 0) + (isset($actions) ? 1 : 0) }}" class="text-muted-foreground h-24 text-center align-middle">{{ __('No records found.') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Compact Pagination Strip -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2 text-xs">
        <p class="text-muted-foreground">
            @if ($selectable)
                <span x-text="@js(__(':selected of :total row(s) selected.')).replace(':selected', selected.length).replace(':total', rows.length)"></span>
            @else
                <span x-text="@js(__(':count total record(s).')).replace(':count', sorted.length)"></span>
            @endif
            <span class="text-muted-foreground/60 ml-1.5" x-show="filtered.length !== rows.length" x-cloak x-text="'(' + filtered.length + ' matching current filter)'"></span>
        </p>
        <div class="flex items-center gap-2">
            <span class="font-medium text-muted-foreground" x-text="@js(__('Page :page of :pages')).replace(':pages', pageCount).replace(':page', page)"></span>
            <div class="flex items-center gap-1">
                <x-ui.button variant="outline" size="sm" class="h-7 px-2.5 text-xs" x-bind:disabled="page === 1" @click="prev()">{{ __('Previous') }}</x-ui.button>
                <x-ui.button variant="outline" size="sm" class="h-7 px-2.5 text-xs" x-bind:disabled="page === pageCount" @click="next()">{{ __('Next') }}</x-ui.button>
            </div>
        </div>
    </div>
    </div><!-- /table viewMode -->
</div>

<script>
if (typeof window.blatDataTable === 'undefined') {
    window.blatDataTable = function(cfg) {
        return {
            q: '',
            sortKey: null,
            sortDir: 'asc',
            page: 1,
            pageSize: cfg.pageSize || 10,
            rows: cfg.rows || [],
            cols: cfg.cols || [],
            searchKeys: cfg.searchKeys || [],
            exportFilename: cfg.exportFilename || 'export',
            selected: [],
            bulkAction: 'none',
            filterDrawerOpen: false,
            filters: {},
            workflowTransitions: cfg.workflowTransitions || [],
            workflowBulkUrl: cfg.workflowBulkUrl || null,
            workflowSlice: cfg.workflowSlice || null,
            csrf: cfg.csrf || '',
            rowKey: cfg.rowKey || 'id',
            views: (cfg.views && cfg.views.length) ? cfg.views : ['table'],
            viewMode: cfg.defaultView || (cfg.views && cfg.views[0]) || 'table',
            statusKey: cfg.statusKey || 'status',
            get boardColumns() {
                const key = this.statusKey;
                const filtered = this.filtered.map(({ r }) => r);
                const groups = {};
                for (const row of filtered) {
                    const raw = String(row[key + '__raw'] ?? row[key] ?? 'unknown').replace(/<[^>]*>/g, '').trim() || 'unknown';
                    if (!groups[raw]) groups[raw] = [];
                    groups[raw].push(row);
                }
                return Object.keys(groups).sort().map(k => ({
                    key: k,
                    label: k.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
                    rows: groups[k],
                }));
            },
            inlineEdit: cfg.inlineEdit || [],
            dateKey: cfg.dateKey || null,
            workflowTransitionUrlTemplate: cfg.workflowTransitionUrlTemplate || null,
            calCursor: new Date(),
            get resolvedDateKey() {
                if (this.dateKey) return this.dateKey;
                const candidates = ['due_date', 'start_date', 'date', 'scheduled_at', 'created_at', 'issue_date'];
                for (const c of candidates) {
                    if (this.cols.some(col => col.key === c)) return c;
                }
                for (const col of this.cols) {
                    if ((col.key || '').includes('date') || (col.key || '').endsWith('_at')) return col.key;
                }
                return null;
            },
            get calMonthLabel() {
                return this.calCursor.toLocaleString(undefined, { month: 'long', year: 'numeric' });
            },
            calPrevMonth() { this.calCursor = new Date(this.calCursor.getFullYear(), this.calCursor.getMonth() - 1, 1); },
            calNextMonth() { this.calCursor = new Date(this.calCursor.getFullYear(), this.calCursor.getMonth() + 1, 1); },
            calendarLabel(row) {
                const keys = this.cols.map(c => c.key);
                for (const k of ['title', 'name', 'heading', 'invoice_number', 'subject']) {
                    if (keys.includes(k) && row[k]) return String(row[k]).replace(/<[^>]*>/g, '').slice(0, 40);
                }
                return String(row[keys[0]] || 'Item').replace(/<[^>]*>/g, '').slice(0, 40);
            },
            get calCells() {
                const y = this.calCursor.getFullYear();
                const m = this.calCursor.getMonth();
                const first = new Date(y, m, 1);
                const startPad = first.getDay();
                const daysInMonth = new Date(y, m + 1, 0).getDate();
                const key = this.resolvedDateKey;
                const filtered = this.filtered.map(({ r }) => r);
                const byDay = {};
                if (key) {
                    for (const row of filtered) {
                        const raw = String(row[key + '__raw'] ?? row[key] ?? '').replace(/<[^>]*>/g, '').trim();
                        if (!raw) continue;
                        const d = new Date(raw);
                        if (isNaN(d.getTime())) continue;
                        if (d.getFullYear() !== y || d.getMonth() !== m) continue;
                        const day = d.getDate();
                        if (!byDay[day]) byDay[day] = [];
                        byDay[day].push(row);
                    }
                }
                const cells = [];
                const total = Math.ceil((startPad + daysInMonth) / 7) * 7;
                for (let i = 0; i < total; i++) {
                    const dayNum = i - startPad + 1;
                    const inMonth = dayNum >= 1 && dayNum <= daysInMonth;
                    cells.push({
                        key: y + '-' + m + '-' + i,
                        day: inMonth ? dayNum : '',
                        inMonth,
                        rows: inMonth ? (byDay[dayNum] || []) : [],
                    });
                }
                return cells;
            },
            async applyInlineTransition(row, transitionSlug) {
                if (!transitionSlug || !this.workflowSlice) return;
                const id = row[this.rowKey] ?? row.id;
                if (!id) { alert('Missing record id'); return; }
                let url = this.workflowTransitionUrlTemplate;
                if (!url && this.workflowBulkUrl) {
                    // fallback: single via bulk endpoint
                }
                if (!url) {
                    url = '/admin/workflows/transition/' + encodeURIComponent(this.workflowSlice) + '/' + encodeURIComponent(id);
                } else {
                    url = url.replace('__SLICE__', encodeURIComponent(this.workflowSlice)).replace('__ID__', encodeURIComponent(id));
                }
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ transition: transitionSlug }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.success === false) {
                        alert(data.message || 'Transition failed');
                        return;
                    }
                    // optimistic: update raw status from transition label if present
                    const wt = (this.workflowTransitions || []).find(t => t.slug === transitionSlug);
                    if (wt && this.statusKey) {
                        row[this.statusKey + '__raw'] = wt.label || transitionSlug;
                    }
                    window.location.reload();
                } catch (e) {
                    alert(e.message || String(e));
                }
            },
            get sidebarFilterCount() {
                let count = 0;
                for (const [k, v] of Object.entries(this.filters)) {
                    if (v && v !== 'all' && v !== '') count++;
                }
                return count;
            },
            get activeFiltersCount() {
                return this.sidebarFilterCount + (this.q ? 1 : 0);
            },
            get filtered() {
                const rows = this.rows.map((r, i) => ({ r, i }));
                const stripHtml = (val) => String(val ?? '').replace(/<[^>]*>/g, '').toLowerCase().trim();
                const q = this.q.toLowerCase().trim();

                return rows.filter(({ r }) => {
                    // 1. Text Search across keys
                    if (q && !this.searchKeys.some(k => stripHtml(r[k]).includes(q))) {
                        return false;
                    }

                    // 2. Advanced / Sidebar / Quick Filters
                    for (const [fKey, fVal] of Object.entries(this.filters)) {
                        if (fVal !== undefined && fVal !== null && fVal !== 'all' && fVal !== '') {
                            const rawTarget = String(r[fKey + '__raw'] ?? '').toLowerCase().trim();
                            const target = stripHtml(r[fKey]);
                            const query = String(fVal).toLowerCase().trim();
                            if (rawTarget !== query && !target.includes(query)) {
                                return false;
                            }
                        }
                    }

                    return true;
                });
            },
            get sorted() {
                const arr = [...this.filtered];
                if (this.sortKey) {
                    arr.sort((a, b) => {
                        let x = a.r[this.sortKey] ?? '', y = b.r[this.sortKey] ?? '';
                        const num = x !== '' && y !== '' && !isNaN(parseFloat(x)) && !isNaN(parseFloat(y));
                        const c = num ? parseFloat(x) - parseFloat(y) : String(x).localeCompare(String(y));
                        return this.sortDir === 'asc' ? c : -c;
                    });
                }
                return arr;
            },
            get pageCount() { return Math.max(1, Math.ceil(this.sorted.length / this.pageSize)) },
            get paged() { const s = (this.page - 1) * this.pageSize; return this.sorted.slice(s, s + this.pageSize) },
            get pageIndices() { return this.paged.map(p => p.i) },
            get allPageSelected() { const idx = this.pageIndices; return idx.length > 0 && idx.every(i => this.selected.includes(i)) },
            toggleSort(key) { this.sortKey === key ? (this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc') : (this.sortKey = key, this.sortDir = 'asc'); this.page = 1 },
            toggleRow(i) { this.selected.includes(i) ? this.selected = this.selected.filter(x => x !== i) : this.selected.push(i); this.emitSelection(); },
            toggleAll() { const idx = this.pageIndices; this.allPageSelected ? this.selected = this.selected.filter(i => !idx.includes(i)) : idx.forEach(i => this.selected.includes(i) || this.selected.push(i)); this.emitSelection(); },
            selectedRecordIds() {
                const key = this.rowKey || 'id';
                return this.selected.map(i => {
                    const row = this.rows.find(r => r.i === i);
                    if (!row) return null;
                    return (row.r && (row.r[key] ?? row.r.id)) ?? row[key] ?? row.id ?? null;
                }).filter(id => id !== null && id !== undefined && id !== '');
            },
            emitSelection() {
                const ids = this.selectedRecordIds();
                window.dispatchEvent(new CustomEvent('laraslice-selection-changed', { detail: { count: ids.length, ids } }));
            },
            next() { if (this.page < this.pageCount) this.page++ },
            prev() { if (this.page > 1) this.page-- },
            clearFilters() {
                this.q = '';
                this.filters = {};
                this.page = 1;
                this.$dispatch('filters-reset');
            },
            setFilter(key, val) {
                this.filters[key] = val;
                this.page = 1;
            },
            applyBulkAction() {
                if (this.bulkAction === 'none') {
                    alert('Please select an action from the dropdown.');
                    return;
                }
                if (this.selected.length === 0) {
                    alert('No rows selected.');
                    return;
                }
                if (this.bulkAction === 'delete') {
                    if (confirm('Are you sure you want to delete ' + this.selected.length + ' selected record(s)?')) {
                        this.$dispatch('bulk-delete', { indices: this.selected, ids: this.selectedRecordIds() });
                        alert('Bulk deletion applied for ' + this.selected.length + ' record(s).');
                        this.selected = [];
                        this.emitSelection();
                    }
                } else if (this.bulkAction === 'export') {
                    this.exportCsv('selected_' + this.exportFilename);
                    this.selected = [];
                    this.emitSelection();
                } else if (String(this.bulkAction).startsWith('wf:')) {
                    const slug = String(this.bulkAction).slice(3);
                    const meta = (this.workflowTransitions || []).find(t => t.slug === slug) || { slug, label: slug };
                    this.runWorkflowBulk(meta);
                }
            },
            async runWorkflowBulk(meta) {
                const ids = this.selectedRecordIds();
                if (!ids.length) { alert('Could not resolve record ids.'); return; }
                let remarks = '';
                if (meta.requires_remarks) {
                    remarks = prompt((meta.label || meta.slug) + ' — remarks (required):', '') || '';
                    if (!remarks.trim()) { alert('Remarks are required for this transition.'); return; }
                }
                const url = this.workflowBulkUrl || (this.workflowSlice ? ('/admin/workflows/bulk-transition/' + this.workflowSlice) : null);
                if (!url) { alert('Workflow bulk URL not configured.'); return; }
                const body = new FormData();
                body.append('_token', this.csrf);
                body.append('transition', meta.slug);
                body.append('remarks', remarks);
                ids.forEach(id => body.append('ids[]', id));
                try {
                    const res = await fetch(url, { method: 'POST', body, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.success === false) {
                        alert(data.message || 'Bulk workflow transition failed');
                        return;
                    }
                    window.location.reload();
                } catch (e) {
                    alert(e.message || 'Network error');
                }
            },
            exportCsv(customName) {
                const rowsToExport = this.selected.length > 0 
                    ? this.rows.filter((_, idx) => this.selected.includes(idx)).map(r => ({ r }))
                    : this.sorted;

                if (rowsToExport.length === 0) {
                    alert('No records available to export.');
                    return;
                }

                const stripHtml = (val) => String(val ?? '').replace(/<[^>]*>/g, '').replace(/(\r\n|\n|\r)/gm, ' ').trim();
                const cols = this.cols;
                const headers = cols.map(c => '"' + String(c.label).replace(/"/g, '""') + '"');
                const csvRows = [headers.join(',')];

                for (const item of rowsToExport) {
                    const line = cols.map(c => {
                        const cell = stripHtml(item.r[c.key]);
                        return '"' + cell.replace(/"/g, '""') + '"';
                    });
                    csvRows.push(line.join(','));
                }

                const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(csvRows.join('\r\n'));
                const a = document.createElement('a');
                a.setAttribute('href', csvContent);
                const fname = (customName || this.exportFilename) + '_' + new Date().toISOString().slice(0, 10) + '.csv';
                a.setAttribute('download', fname);
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        };
    };
}
</script>
