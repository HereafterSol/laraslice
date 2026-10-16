@props([
    'ui' => [],           // The ui.directory config: columns, quick_filters, summary_cards, etc.
    'sliceName' => 'Slice',
    'domain' => 'ERP',
    'module' => 'Work',
    'editable' => true,   // false = preview only
    'id' => 'page-plan',
])
{{--
    Shared Page & UI Architecture Plan component (BlatUI 4-Step Methodology).
    
    Used in: Blueprint Studio, Porting Studio, Slice Studio
    Provides consistent 4-step UI config experience:
      Step 1: Directory (Index) - Stats Ribbon + Columns + Filters + Actions
      Step 2: Show Workspace
      Step 3: Create & Edit Form  
      Step 4: Finite State FSM
    
    Data interface (standardized):
      $ui = [
        'directory' => [
          'columns' => [['handle', 'label', 'formatter', 'sortable'], ...],
          'quick_filters' => [['handle', 'label', 'options_from'], ...],
          'sidebar_filters' => [...],
          'summary_cards' => [['handle', 'label', 'aggregate', 'formatter'], ...],
          'bulk_actions' => ['delete', 'export'],
          'row_actions' => ['display' => 'dropdown', 'items' => [...]],
          'searchable' => [...],
        ],
        'presentation' => ['create' => 'drawer|page|modal', 'edit' => '...'],
      ]
--}}
<div class="rounded-2xl border border-indigo-500/30 bg-card p-5 shadow-sm space-y-4" 
     x-data="{ 
         activePageStep: 'index',
         ui: @js($ui),
         get directory() { return this.ui.directory || {}; },
         get columns() { return this.directory.columns || []; },
         get quickFilters() { return this.directory.quick_filters || []; },
         get sidebarFilters() { return this.directory.sidebar_filters || []; },
         get summaryCards() { return this.directory.summary_cards || []; },
         get bulkActions() { return this.directory.bulk_actions || []; },
         get rowActions() { return this.directory.row_actions || {display: 'dropdown', items: []}; },
     }"
     id="{{ $id }}">
    
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
        <div>
            <h2 class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                <span class="flex size-7 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-sm">📄</span>
                <span>Page & UI Architecture Plan</span>
                <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400">BlatUI 4-Step Methodology</span>
                <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400">{{ $sliceName }} Workspace</span>
            </h2>
            <p class="text-xs text-muted-foreground mt-0.5">
                Canonical screen blueprints for porting legacy views into modern, high-density BlatUI templates (Blade + Alpine.js + Tailwind CSS v4 + Lucide icons).
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-[11px] font-mono rounded-md bg-muted px-2.5 py-1 text-muted-foreground border border-border/60">
                php artisan laraslice:ui <span class="text-indigo-400 font-semibold">{{ $domain }}.{{ $module }}.{{ $sliceName }}</span> --with-tabs
            </span>
        </div>
    </div>

    <!-- 4 Step Navigation -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <button type="button" @click="activePageStep = 'index'" 
            :class="activePageStep === 'index' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground'" 
            class="rounded-lg p-2.5 text-left text-xs transition cursor-pointer flex flex-col gap-0.5">
            <span class="text-[10px] uppercase font-bold opacity-75">Step 1</span>
            <span class="font-semibold text-xs flex items-center gap-1.5"><span>📋</span> Directory (Index)</span>
        </button>
        <button type="button" @click="activePageStep = 'show'" 
            :class="activePageStep === 'show' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground'" 
            class="rounded-lg p-2.5 text-left text-xs transition cursor-pointer flex flex-col gap-0.5">
            <span class="text-[10px] uppercase font-bold opacity-75">Step 2</span>
            <span class="font-semibold text-xs flex items-center gap-1.5"><span>📑</span> Show Workspace</span>
        </button>
        <button type="button" @click="activePageStep = 'create_edit'" 
            :class="activePageStep === 'create_edit' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground'" 
            class="rounded-lg p-2.5 text-left text-xs transition cursor-pointer flex flex-col gap-0.5">
            <span class="text-[10px] uppercase font-bold opacity-75">Step 3</span>
            <span class="font-semibold text-xs flex items-center gap-1.5"><span>📝</span> Create & Edit Form</span>
        </button>
        <button type="button" @click="activePageStep = 'workflow'" 
            :class="activePageStep === 'workflow' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground'" 
            class="rounded-lg p-2.5 text-left text-xs transition cursor-pointer flex flex-col gap-0.5">
            <span class="text-[10px] uppercase font-bold opacity-75">Step 4</span>
            <span class="font-semibold text-xs flex items-center gap-1.5"><span>⚡</span> Finite State FSM</span>
        </button>
    </div>

    <!-- Step 1: Directory (Index) -->
    <div x-show="activePageStep === 'index'" class="space-y-4 pt-1">
        
        <!-- Stats Ribbon KPIs -->
        <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5 space-y-2.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-foreground">📊 Stats Ribbon KPIs</span>
                    <span class="text-[10px] text-muted-foreground">High-level executive status counters atop index screen</span>
                </div>
                <span class="text-[10px] font-mono text-indigo-400">BlatUI MetricCards</span>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5">
                <template x-for="(card, cIdx) in summaryCards" :key="cIdx">
                    <div class="rounded-lg border border-border bg-card p-3">
                        <div class="text-[11px] font-medium text-muted-foreground" x-text="card.label"></div>
                        <div class="text-lg font-bold text-foreground mt-0.5" x-text="'<' + card.aggregate + ': ' + card.handle + '>'"></div>
                        <div class="text-[10px] text-muted-foreground mt-0.5" x-text="card.formatter || 'number'"></div>
                        @if($editable)
                        <button type="button" @click="summaryCards.splice(cIdx, 1)" class="mt-1 text-[10px] text-destructive hover:underline">Remove</button>
                        @endif
                    </div>
                </template>
                <div x-show="!summaryCards.length" class="col-span-full rounded-lg border border-dashed border-border p-3 text-center text-[11px] text-muted-foreground">
                    No summary cards. Money/decimal fields auto-generate these.
                </div>
            </div>
            @if($editable)
            <button type="button" @click="summaryCards.push({handle: 'amount', label: 'Total Amount', aggregate: 'sum', formatter: 'money'})"
                class="rounded-lg border border-dashed border-border px-3 py-1.5 text-[11px] font-semibold text-muted-foreground hover:text-foreground hover:border-primary">+ Add Summary Card</button>
            @endif
        </div>

        <!-- Directory Columns -->
        <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5 space-y-2.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-foreground">📋 Curated Semantic Directory Columns</span>
                    <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400" x-text="columns.length + ' Columns'"></span>
                </div>
            </div>
            <p class="text-[11px] text-muted-foreground">Customize high-signal directory columns, labels, sortability, and composite semantic formatters.</p>
            <div class="space-y-2">
                <template x-for="(col, colIdx) in columns" :key="colIdx">
                    <div class="flex items-center gap-2 rounded-lg border border-border/60 bg-card p-2.5">
                        <span class="text-[10px] font-mono text-indigo-400 w-16 truncate" x-text="col.handle"></span>
                        <input type="text" x-model="col.label" placeholder="Label" 
                            class="flex-1 rounded-md border border-border bg-background px-2.5 py-1.5 text-xs"
                            :disabled="!@js($editable)">
                        <select x-model="col.formatter" class="rounded-md border border-border bg-background px-2 py-1.5 text-xs" :disabled="!@js($editable)">
                            <option value="text">Plain Text</option>
                            <option value="code_badge">Code Badge (#ID)</option>
                            <option value="status_pill">Status Pill</option>
                            <option value="money_breakdown">Money</option>
                            <option value="date_time">Date Time</option>
                            <option value="progress_bar">Progress Bar</option>
                        </select>
                        <label class="flex items-center gap-1 text-[11px] text-muted-foreground">
                            <input type="checkbox" x-model="col.sortable" :disabled="!@js($editable)" class="rounded"> Sortable
                        </label>
                        @if($editable)
                        <button type="button" @click="columns.splice(colIdx, 1)" class="rounded p-1 text-destructive hover:bg-destructive/10">🗑️</button>
                        @endif
                    </div>
                </template>
            </div>
        </div>

        <!-- Quick Filters -->
        <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5 space-y-2.5">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-foreground">🔖 Quick Filters (Tab Bar)</span>
                <span class="text-[10px] text-muted-foreground">Status tabs above the table</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <template x-for="(qf, qIdx) in quickFilters" :key="qIdx">
                    <div class="flex items-center gap-1.5 rounded-lg bg-amber-500/10 border border-amber-500/20 px-2.5 py-1.5">
                        <span class="text-xs font-semibold text-amber-700" x-text="qf.label"></span>
                        <span class="text-[10px] font-mono text-muted-foreground" x-text="qf.handle"></span>
                        @if($editable)
                        <button type="button" @click="quickFilters.splice(qIdx, 1)" class="text-destructive hover:text-destructive/80 text-xs">✕</button>
                        @endif
                    </div>
                </template>
                <span x-show="!quickFilters.length" class="text-[11px] text-muted-foreground">No quick filters. Status fields auto-generate these.</span>
            </div>
        </div>

        <!-- Sidebar Filters -->
        <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5 space-y-2.5">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-foreground">🎛️ Sidebar Filters</span>
                <span class="text-[10px] text-muted-foreground">Date ranges & FK dropdowns in filter panel</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <template x-for="(sf, sIdx) in sidebarFilters" :key="sIdx">
                    <div class="flex items-center gap-1.5 rounded-lg bg-blue-500/10 border border-blue-500/20 px-2.5 py-1.5">
                        <span class="text-xs font-semibold text-blue-700" x-text="sf.label"></span>
                        <span class="text-[10px] font-mono text-muted-foreground" x-text="sf.type"></span>
                        @if($editable)
                        <button type="button" @click="sidebarFilters.splice(sIdx, 1)" class="text-destructive hover:text-destructive/80 text-xs">✕</button>
                        @endif
                    </div>
                </template>
                <span x-show="!sidebarFilters.length" class="text-[11px] text-muted-foreground">No sidebar filters.</span>
            </div>
        </div>

        <!-- Row Actions -->
        <div class="rounded-xl border border-indigo-500/30 bg-card p-4 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-foreground">⚡ Table Row Actions</span>
                    <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary" x-text="(rowActions.items || []).length + ' Actions'"></span>
                </div>
                @if($editable)
                <div class="flex items-center gap-1 bg-muted/40 p-1 rounded-lg border border-border/60 text-xs">
                    <button type="button" @click="rowActions.display = 'dropdown'"
                        :class="(rowActions.display || 'dropdown') === 'dropdown' ? 'bg-primary text-primary-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                        class="px-2 py-0.5 rounded text-[11px] font-semibold transition cursor-pointer">3-Dots Menu</button>
                    <button type="button" @click="rowActions.display = 'inline'"
                        :class="rowActions.display === 'inline' ? 'bg-primary text-primary-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                        class="px-2 py-0.5 rounded text-[11px] font-semibold transition cursor-pointer">Inline</button>
                    <button type="button" @click="rowActions.display = 'hybrid'"
                        :class="rowActions.display === 'hybrid' ? 'bg-primary text-primary-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground'"
                        class="px-2 py-0.5 rounded text-[11px] font-semibold transition cursor-pointer">Hybrid</button>
                </div>
                @endif
            </div>
            <div class="space-y-2">
                <template x-for="(action, aIdx) in (rowActions.items || [])" :key="aIdx">
                    <div class="flex items-center gap-2 rounded-lg border border-border/50 bg-muted/20 px-3 py-2">
                        <span class="text-xs font-bold" x-text="action.label"></span>
                        <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-mono text-muted-foreground" x-text="action.handle"></span>
                        @if($editable)
                        <select x-model="action.placement" class="ml-auto rounded border border-border bg-background px-2 py-1 text-xs">
                            <option value="inline">Inline</option>
                            <option value="dropdown">Dropdown</option>
                        </select>
                        <button type="button" @click="rowActions.items.splice(aIdx, 1)" class="rounded p-1 text-destructive hover:bg-destructive/10 text-xs">✕</button>
                        @else
                        <span class="ml-auto text-[10px] text-muted-foreground" x-text="action.placement"></span>
                        @endif
                    </div>
                </template>
            </div>
            @if($editable)
            <button type="button" @click="rowActions.items.push({handle: 'custom_' + Date.now(), label: 'Custom Action', icon: 'plus', placement: 'dropdown'})"
                class="rounded-lg border border-dashed border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground hover:border-primary">+ Add Action</button>
            @endif
        </div>

        <!-- Bulk Actions -->
        <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xs font-bold text-foreground">📦 Bulk Actions</span>
                <span class="text-[10px] text-muted-foreground">Multi-select operations</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <template x-for="(ba, bIdx) in bulkActions" :key="bIdx">
                    <span class="rounded-lg bg-emerald-500/10 border border-emerald-500/20 px-3 py-1.5 text-xs font-semibold text-emerald-700" x-text="ba"></span>
                </template>
                <span x-show="!bulkActions.length" class="text-[11px] text-muted-foreground">No bulk actions.</span>
            </div>
        </div>
    </div>

    <!-- Step 2: Show Workspace -->
    <div x-show="activePageStep === 'show'" class="space-y-4 pt-1">
        <div class="rounded-xl border border-border/80 bg-muted/10 p-4 text-center">
            <p class="text-xs font-bold text-foreground mb-1">📑 Show Workspace</p>
            <p class="text-[11px] text-muted-foreground">Entity header ribbon + tabbed detail sections. Configured via <code class="font-mono bg-muted px-1 rounded">ui.show</code> block.</p>
        </div>
    </div>

    <!-- Step 3: Create & Edit Form -->
    <div x-show="activePageStep === 'create_edit'" class="space-y-4 pt-1"
         x-data="{ formSections: (@js($ui['forms']['sections'] ?? [])), formMode: '{{ $ui['forms']['mode'] ?? $ui['presentation']['create'] ?? 'drawer' }}' }">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-500/30 bg-muted/20 p-3.5">
            <div>
                <span class="font-bold text-foreground text-xs">🗂️ Form Presentation</span>
                <p class="text-[11px] text-muted-foreground">How Create & Edit forms appear to end users.</p>
            </div>
            @if($editable)
            <div class="inline-flex rounded-lg border border-input bg-card p-0.5">
                <button type="button" @click="formMode = 'drawer'" :class="formMode === 'drawer' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground'" class="rounded-md px-3 py-1.5 text-xs transition cursor-pointer">🗂️ Drawer</button>
                <button type="button" @click="formMode = 'page'" :class="formMode === 'page' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground'" class="rounded-md px-3 py-1.5 text-xs transition cursor-pointer">📄 Page</button>
                <button type="button" @click="formMode = 'modal'" :class="formMode === 'modal' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground'" class="rounded-md px-3 py-1.5 text-xs transition cursor-pointer">◻️ Modal</button>
            </div>
            @else
            <span class="rounded-lg bg-primary/10 px-3 py-1.5 text-xs font-bold text-primary" x-text="formMode"></span>
            @endif
        </div>
        <div class="space-y-3">
            <template x-for="(sec, sIdx) in formSections" :key="sIdx">
                <div class="rounded-xl border border-border bg-card p-4 space-y-2.5">
                    <div class="flex items-center gap-2 border-b border-border/50 pb-2">
                        <span class="size-6 rounded-md bg-primary/10 text-primary flex items-center justify-center font-bold text-xs" x-text="'S' + (sIdx + 1)"></span>
                        <span class="text-xs font-bold" x-text="sec.title || sec.name || 'Section ' + (sIdx + 1)"></span>
                        <span class="ml-auto text-[10px] text-muted-foreground" x-text="(sec.fields?.length || 0) + ' fields · ' + (sec.columns || 2) + ' cols'"></span>
                        @if($editable)
                        <button type="button" @click="formSections.splice(sIdx, 1)" class="text-rose-500 text-xs">🗑️</button>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="(fld, fIdx) in (sec.fields || [])" :key="fIdx">
                            <span class="rounded-lg border border-border/60 bg-muted/20 px-2 py-1 text-[11px] font-mono" x-text="fld.handle + (fld.span > 1 ? ' (' + fld.span + 'c)' : '')"></span>
                        </template>
                    </div>
                </div>
            </template>
            <div x-show="!formSections.length" class="rounded-xl border border-dashed border-border p-4 text-center text-[11px] text-muted-foreground">
                No form sections defined. Sections are generated from fields.
            </div>
        </div>
        @if($editable)
        <button type="button" @click="formSections.push({title: 'New Section', columns: 2, fields: []})"
            class="w-full rounded-xl border border-dashed border-border py-2.5 text-xs font-semibold text-muted-foreground hover:text-foreground hover:border-primary">+ Add Section Card</button>
        @endif
    </div>

    <!-- Step 4: Workflow FSM -->
    <div x-show="activePageStep === 'workflow'" class="space-y-4 pt-1">
        <div class="rounded-xl border border-border/80 bg-muted/10 p-4 text-center">
            <p class="text-xs font-bold text-foreground mb-1">⚡ Finite State FSM</p>
            <p class="text-[11px] text-muted-foreground">Status pill + transition action menu. Configured via <code class="font-mono bg-muted px-1 rounded">workflow</code> block.</p>
        </div>
    </div>
</div>
