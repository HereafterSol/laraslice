{{--
    Table-controls cluster (bulk strip, view switcher, columns
    menu, table actions). These stay with the table — they are
    bound to the data-table component's Alpine state — whether the
    filter bar renders inline (platform pages) or is pushed under
    the topbar (ERP pages, source composition).
--}}
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

<!-- Column visibility: sensible defaults, user-adjustable, persisted per page -->
@if ($columnsMenu ?? true)
<div class="relative" @click.outside="colMenuOpen = false">
    <button type="button" @click="colMenuOpen = !colMenuOpen"
        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl border border-border bg-card text-[11px] font-semibold text-muted-foreground hover:text-foreground shadow-2xs transition-colors cursor-pointer">
        <x-lucide-sliders-horizontal class="size-3.5" />
        <span>Columns</span>
        <span x-show="hiddenCols.length" x-cloak class="size-4 rounded-full bg-primary text-primary-foreground text-[10px] font-bold flex items-center justify-center" x-text="hiddenCols.length"></span>
    </button>
    <div x-show="colMenuOpen" x-cloak class="absolute right-0 mt-1.5 w-52 rounded-xl border border-border bg-card shadow-lg p-1.5 z-30 max-h-72 overflow-y-auto">
        <template x-for="c in cols" :key="c.key">
            <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-muted/60 cursor-pointer text-xs">
                <input type="checkbox" class="rounded border-border" :checked="colVisible(c.key)" @change="toggleCol(c.key)" />
                <span x-text="c.label"></span>
            </label>
        </template>
    </div>
</div>
@endif

@isset($tableActions)
    {{ $tableActions }}
@endisset
