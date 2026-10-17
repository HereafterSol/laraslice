@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('title', 'Porting Studio')

@section('content')
<div x-data="portingStudio()" class="min-h-screen bg-background">
    <!-- Mobile-first header -->
    <div class="sticky top-0 z-30 bg-card/95 backdrop-blur border-b border-border">
        <div class="px-4 py-3 md:px-6">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <a href="{{ route('laraslice.wizard') }}" class="p-2 -ml-2 rounded-lg hover:bg-muted text-muted-foreground" title="Back to Wizard">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                    <div class="min-w-0">
                        <h1 class="text-base md:text-xl font-bold text-foreground truncate">🔄 Porting Studio</h1>
                        <p class="text-[11px] md:text-xs text-muted-foreground truncate hidden sm:block">Reverse-engineer legacy apps into LaraSlice vertical slices</p>
                    </div>
                </div>
                <button @click="analyze()" :disabled="analyzing" class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 md:px-4 text-xs md:text-sm font-bold text-primary-foreground hover:opacity-90 disabled:opacity-50">
                    <span x-show="!analyzing">🔍 Analyze</span>
                    <span x-show="analyzing" class="animate-pulse">Analyzing...</span>
                </button>
            </div>
        </div>
        <!-- Mobile tab selector (dropdown) / Desktop tabs -->
        <div class="px-4 pb-2 md:px-6">
            <!-- Mobile: select dropdown -->
            <select x-model="activeTab" class="w-full md:hidden h-10 rounded-lg border border-input bg-background px-3 text-sm font-semibold text-foreground">
                <option value="guide">🧭 Workflow Guide</option>
                <option value="journeys">👥 User Journeys</option>
                <option value="sidebar">🖥️ Sidebar & Navigation</option>
                <option value="vsa">🏗️ VSA Architecture</option>
                <option value="pages">📄 Page & UI</option>
                <option value="datasources">💾 Data Sources</option>
                <option value="etl">⚡ ETL Pipeline</option>
                <option value="json">📋 JSON Plan</option>
                <option value="log">📟 Execution Log</option>
            </select>
            <!-- Desktop: tab bar -->
            <div class="hidden md:flex gap-1 overflow-x-auto">
                <template x-for="tab in tabs" :key="tab.id">
                    <button @click="activeTab = tab.id" :class="activeTab === tab.id ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground hover:text-foreground hover:bg-muted'" class="shrink-0 px-3 py-1.5 rounded-md text-xs transition whitespace-nowrap" x-text="tab.label"></button>
                </template>
            </div>
        </div>
    </div>

    <div class="px-4 py-4 md:px-6 md:py-6 max-w-7xl mx-auto space-y-4">
        <!-- Source selection card -->
        <div class="rounded-xl border border-border bg-card p-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Source Preset</label>
                    <select x-model="preset" class="w-full h-10 rounded-lg border border-input bg-background px-3 text-sm text-foreground">
                        <option value="erp">🏢 ERP Enterprise Suite</option>
                        <option value="custom">🔍 Custom Local Project</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-foreground mb-1">Source Type</label>
                    <div class="flex rounded-lg border border-input overflow-hidden h-10">
                        <button type="button" @click="sourceType = 'local'"
                            :class="sourceType === 'local' ? 'bg-primary text-primary-foreground' : 'bg-background text-muted-foreground'"
                            class="flex-1 text-xs font-semibold px-3 transition-colors">📁 Local Path</button>
                        <button type="button" @click="sourceType = 'git'"
                            :class="sourceType === 'git' ? 'bg-primary text-primary-foreground' : 'bg-background text-muted-foreground'"
                            class="flex-1 text-xs font-semibold px-3 transition-colors">🔗 Git Repository</button>
                    </div>
                </div>
            </div>
            <!-- Local path input -->
            <div x-show="sourceType === 'local'" class="mt-3">
                <label class="block text-xs font-semibold text-foreground mb-1">Source Path / Database</label>
                <input x-model="sourcePath" type="text" placeholder="/path/to/legacy-app or database name" class="w-full h-10 rounded-lg border border-input bg-background px-3 text-sm font-mono text-foreground">
            </div>
            <!-- Git repo inputs -->
            <div x-show="sourceType === 'git'" class="mt-3 space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">Repository URL</label>
                    <input x-model="gitUrl" type="url" placeholder="https://github.com/acme/legacy-erp.git" class="w-full h-10 rounded-lg border border-input bg-background px-3 text-sm font-mono text-foreground">
                    <p class="mt-1 text-[11px] text-muted-foreground">Public GitHub / GitLab repos work as-is. Private repos need a personal access token below.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">Branch / Tag <span class="font-normal text-muted-foreground">(optional)</span></label>
                        <input x-model="gitBranch" type="text" placeholder="main (default branch if empty)" class="w-full h-10 rounded-lg border border-input bg-background px-3 text-sm font-mono text-foreground">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">Access Token <span class="font-normal text-muted-foreground">(private repos)</span></label>
                        <input x-model="gitToken" type="password" placeholder="ghp_… / glpat-…" autocomplete="off" class="w-full h-10 rounded-lg border border-input bg-background px-3 text-sm font-mono text-foreground">
                    </div>
                </div>
                <p class="text-[11px] text-muted-foreground">🔒 The token is used only for cloning and is never stored or logged.</p>
                <label class="flex items-center gap-2 text-xs text-foreground cursor-pointer">
                    <input x-model="gitKeep" type="checkbox" class="size-4 rounded border-input">
                    <span>📌 Keep cloned repository <span class="text-muted-foreground">(skip 24h auto-cleanup; manage manually below)</span></span>
                </label>
            </div>
            <div x-show="error" class="mt-3 rounded-lg bg-red-500/10 border border-red-500/20 p-3 text-xs text-red-400" x-text="error"></div>

            <!-- Saved presets + cached sources -->
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" @click="savePreset()" :disabled="!planData"
                    class="h-9 px-3 rounded-lg border border-input text-xs font-semibold text-foreground disabled:opacity-40 hover:bg-muted transition-colors">
                    💾 Save as Preset
                </button>
                <select x-model="selectedSavedPreset" @change="loadPreset()" class="h-9 rounded-lg border border-input bg-background px-2 text-xs text-foreground max-w-[220px]">
                    <option value="">📂 Load saved preset…</option>
                    <template x-for="p in savedPresets" :key="p.slug">
                        <option :value="p.slug" x-text="p.name"></option>
                    </template>
                </select>
                <button type="button" @click="deletePreset()" x-show="selectedSavedPreset"
                    class="h-9 px-3 rounded-lg border border-red-500/30 text-xs font-semibold text-red-400 hover:bg-red-500/10 transition-colors">
                    🗑️ Delete
                </button>
                <button type="button" @click="toggleSourceManager(); loadCachedSources()"
                    class="h-9 px-3 rounded-lg border border-input text-xs font-semibold text-foreground hover:bg-muted transition-colors">
                    📦 Cached Repos <span x-show="cachedSources.length" class="ml-1 rounded-full bg-primary/15 px-1.5 text-[10px] text-primary" x-text="cachedSources.length"></span>
                </button>
            </div>

            <!-- Cached sources manager -->
            <div x-show="showSourceManager" class="mt-3 rounded-lg border border-border bg-muted/30 p-3">
                <h4 class="text-xs font-bold text-foreground mb-2">📦 Cached Git Repositories</h4>
                <div x-show="!cachedSources.length" class="text-xs text-muted-foreground">No cached repositories. Clone a repo above to see it here.</div>
                <div class="space-y-2">
                    <template x-for="s in cachedSources" :key="s.key">
                        <div class="flex items-center gap-2 rounded-lg border border-border bg-card p-2">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-xs font-mono font-semibold text-foreground" x-text="s.repo_url"></div>
                                <div class="text-[10px] text-muted-foreground">
                                    <span x-text="s.branch || 'default branch'"></span> ·
                                    <span x-text="s.size_mb + ' MB'"></span> ·
                                    <span x-show="s.keep" class="text-amber-400 font-semibold">📌 kept</span>
                                    <span x-show="!s.keep" class="text-muted-foreground">auto-expires 24h</span>
                                </div>
                            </div>
                            <button type="button" @click="toggleKeep(s)" :title="s.keep ? 'Allow auto-cleanup' : 'Keep permanently'"
                                :class="s.keep ? 'text-amber-400' : 'text-muted-foreground'"
                                class="shrink-0 h-8 w-8 rounded-lg border border-input text-sm hover:bg-muted">📌</button>
                            <button type="button" @click="deleteCachedSource(s.key)" title="Delete clone"
                                class="shrink-0 h-8 w-8 rounded-lg border border-red-500/30 text-sm text-red-400 hover:bg-red-500/10">🗑️</button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Tab: Workflow Guide -->
        <div x-show="activeTab === 'guide'" class="rounded-xl border border-border bg-card p-4 md:p-6">
            <h2 class="font-bold text-base md:text-lg text-foreground mb-3">🧭 Recommended Porting Workflow</h2>
            <ol class="space-y-3">
                <template x-for="(step, idx) in workflowSteps" :key="idx">
                    <li class="flex gap-3">
                        <span class="shrink-0 size-7 rounded-full bg-primary/10 text-primary font-bold text-xs flex items-center justify-center" x-text="idx + 1"></span>
                        <div class="min-w-0">
                            <p class="font-semibold text-sm text-foreground" x-text="step.title"></p>
                            <p class="text-xs text-muted-foreground" x-text="step.desc"></p>
                            <button @click="activeTab = step.tab" class="mt-1 text-xs font-bold text-primary hover:underline" x-text="'Go to ' + step.tabLabel + ' →'"></button>
                        </div>
                    </li>
                </template>
            </ol>
        </div>

        <!-- Tab: User Journeys -->
        <div x-show="activeTab === 'journeys'" class="space-y-3">
            <div x-show="!planData" class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                Click <strong>🔍 Analyze</strong> to detect user journeys from the source system.
            </div>
            <template x-for="(journey, key) in (planData?.journeys || {})" :key="key">
                <div class="rounded-xl border border-border bg-card p-4">
                    <div class="flex items-center gap-2 mb-2 flex-wrap">
                        <span class="text-lg" x-text="journey.icon || '🧭'"></span>
                        <h3 class="font-bold text-sm md:text-base text-foreground" x-text="journey.name || journey.title"></h3>
                        <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400" x-text="journey.persona"></span>
                    </div>
                    <p class="text-xs text-muted-foreground mb-3" x-text="journey.description"></p>
                    <div class="space-y-1.5">
                        <template x-for="(step, sIdx) in (journey.steps || [])" :key="sIdx">
                            <div class="flex items-start gap-2 text-xs">
                                <span class="shrink-0 size-5 rounded-full bg-muted text-muted-foreground font-bold text-[10px] flex items-center justify-center" x-text="sIdx + 1"></span>
                                <div class="min-w-0">
                                    <span class="font-semibold text-foreground" x-text="step.step || step.label || (typeof step === 'string' ? step : JSON.stringify(step))"></span>
                                    <span x-show="step.controller" class="ml-1 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground" x-text="step.controller"></span>
                                    <span x-show="step.slice" class="ml-1 rounded bg-primary/10 px-1.5 py-0.5 font-mono text-[10px] text-primary" x-text="step.slice"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <!-- Tab: Sidebar & Navigation -->
        <div x-show="activeTab === 'sidebar'" class="space-y-3">
            <div x-show="!planData" class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                Click <strong>🔍 Analyze</strong> to generate the navigation preview.
            </div>
            <div x-show="planData">
                <!-- Source navigation comparison -->
                <div x-show="planData?.sidebar_preview?.source_navigation?.groups?.length" class="rounded-xl border border-indigo-500/20 bg-indigo-500/5 p-4">
                    <p class="font-bold text-sm text-foreground mb-1">🔍 Source Navigation Detected</p>
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <template x-for="(nav, nIdx) in (planData.sidebar_preview.source_navigation.groups || [])" :key="nIdx">
                            <span class="rounded-full bg-indigo-500/10 px-2.5 py-1 text-[11px] font-semibold text-indigo-300 border border-indigo-500/20" x-text="nav.label"></span>
                        </template>
                    </div>
                </div>
                <!-- Proposed groups (reorderable) -->
                <div class="rounded-xl border border-border bg-slate-950 p-4">
                    <p class="font-bold text-xs text-slate-200 mb-3">Proposed Navigation (tap ▲▼ to reorder)</p>
                    <div class="space-y-3">
                        <template x-for="(group, gIdx) in (planData?.sidebar_preview?.groups || [])" :key="gIdx">
                            <div class="rounded-lg border border-slate-800 p-2">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400" x-text="group.name"></span>
                                    <div class="flex gap-1">
                                        <button @click="moveGroup(gIdx, -1)" :disabled="gIdx === 0" class="p-1.5 rounded bg-slate-800 text-slate-300 disabled:opacity-30 text-xs min-w-[32px]" title="Move up">▲</button>
                                        <button @click="moveGroup(gIdx, 1)" :disabled="gIdx === (planData.sidebar_preview.groups.length - 1)" class="p-1.5 rounded bg-slate-800 text-slate-300 disabled:opacity-30 text-xs min-w-[32px]" title="Move down">▼</button>
                                    </div>
                                </div>
                                <div class="space-y-0.5">
                                    <template x-for="(item, iIdx) in group.items" :key="iIdx">
                                        <div class="flex items-center justify-between rounded px-2 py-1.5 text-slate-300 text-xs">
                                            <span class="truncate" x-text="item.label"></span>
                                            <span x-show="item.badge" class="rounded-full bg-indigo-500/20 px-2 text-[10px] font-bold text-indigo-300" x-text="item.badge"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: VSA Architecture -->
        <div x-show="activeTab === 'vsa'" class="space-y-3">
            <div x-show="!planData" class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                Click <strong>🔍 Analyze</strong> to map tables to slices.
            </div>
            <div x-show="planData" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-for="(slice, sIdx) in (planData?.studio_slices || [])" :key="sIdx">
                    <div class="rounded-xl border border-border bg-card p-4 space-y-2">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-bold text-sm text-foreground" x-text="slice.name"></h3>
                            <span class="shrink-0 rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400"
                                  x-show="slice.module" x-text="slice.module"></span>
                        </div>
                        <p class="text-[11px] font-mono text-muted-foreground" x-text="'Table: ' + (slice.root_table || '—')"></p>
                        <div class="flex flex-wrap gap-2 text-[11px]">
                            <span class="rounded bg-primary/10 px-2 py-0.5 font-bold text-primary" x-text="(slice.models?.length || 0) + ' models'"></span>
                            <span class="rounded bg-muted px-2 py-0.5 text-muted-foreground" x-text="(slice.child_tables?.length || 0) + ' children'"></span>
                            <span class="rounded bg-emerald-500/10 px-2 py-0.5 text-emerald-400" x-show="slice.records_count" x-text="(slice.records_count || 0) + ' rows'"></span>
                        </div>
                        <ul class="text-[10px] text-muted-foreground space-y-0.5" x-show="(slice.child_tables || []).length">
                            <template x-for="(ch, cIdx) in (slice.child_tables || []).slice(0, 6)" :key="cIdx">
                                <li class="flex items-center gap-1.5 font-mono">
                                    <span x-text="typeof ch === 'string' ? ch : (ch.table || ch)"></span>
                                    <span class="text-amber-500/90" x-show="ch.confidence" x-text="'⚡ ' + Math.round((ch.confidence || 0) * 100) + '%'"></span>
                                    <span class="opacity-60" x-show="ch.reason" x-text="ch.reason"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </div>

        <!-- Tab: Page & UI Architecture -->
        <div x-show="activeTab === 'pages'" class="space-y-3">
            <div x-show="!planData" class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                Click <strong>🔍 Analyze</strong> to preview page UI architecture.
            </div>
            <div x-show="planData" class="space-y-4">
                <!-- Slice selector -->
                <div class="rounded-xl border border-border bg-card p-4">
                    <label class="text-xs font-bold text-muted-foreground mb-2 block">SELECT SLICE TO PREVIEW</label>
                    <select x-model="selectedSliceIdx" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm">
                        <template x-for="(slice, sIdx) in (planData?.studio_slices || [])" :key="sIdx">
                            <option :value="sIdx" x-text="slice.name"></option>
                        </template>
                    </select>
                </div>

                <!-- UI Config Preview -->
                <template x-if="planData?.studio_slices?.[selectedSliceIdx]">
                    <div class="space-y-4">
                        <!-- Summary Cards -->
                        <div class="rounded-xl border border-border bg-card p-4">
                            <h4 class="font-bold text-sm mb-2">📊 Summary Cards</h4>
                            <div class="text-xs text-muted-foreground" x-show="!(planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.summary_cards?.length)">
                                No summary cards detected. Money/decimal fields generate these automatically.
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="(card, cIdx) in (planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.summary_cards || [])" :key="cIdx">
                                    <span class="rounded-lg bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary" x-text="card.label + ' (' + card.aggregate + ')'"></span>
                                </template>
                            </div>
                        </div>

                        <!-- Quick Filters -->
                        <div class="rounded-xl border border-border bg-card p-4">
                            <h4 class="font-bold text-sm mb-2">🔖 Quick Filters (Tabs)</h4>
                            <div class="text-xs text-muted-foreground" x-show="!(planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.quick_filters?.length)">
                                No quick filters detected. Status/enum fields generate these automatically.
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="(qf, qIdx) in (planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.quick_filters || [])" :key="qIdx">
                                    <span class="rounded-lg bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-600" x-text="qf.label"></span>
                                </template>
                            </div>
                        </div>

                        <!-- Row Actions (NEW) -->
                        <div class="rounded-xl border border-border bg-card p-4">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="font-bold text-sm">⚡ Row Actions</h4>
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold text-primary" x-text="(planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.row_actions?.items?.length || 0) + ' actions'"></span>
                            </div>
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-xs text-muted-foreground">Display:</span>
                                <button type="button" @click="planData.studio_slices[selectedSliceIdx].blueprint.ui.directory.row_actions.display = 'dropdown'"
                                    :class="(planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.row_actions?.display || 'dropdown') === 'dropdown' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
                                    class="rounded-lg px-3 py-1 text-xs font-bold">Dropdown (⋯)</button>
                                <button type="button" @click="planData.studio_slices[selectedSliceIdx].blueprint.ui.directory.row_actions.display = 'inline'"
                                    :class="planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.row_actions?.display === 'inline' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
                                    class="rounded-lg px-3 py-1 text-xs font-bold">Inline</button>
                                <button type="button" @click="planData.studio_slices[selectedSliceIdx].blueprint.ui.directory.row_actions.display = 'hybrid'"
                                    :class="planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.row_actions?.display === 'hybrid' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
                                    class="rounded-lg px-3 py-1 text-xs font-bold">Hybrid</button>
                            </div>
                            <div class="space-y-2">
                                <template x-for="(action, aIdx) in (planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.row_actions?.items || [])" :key="aIdx">
                                    <div class="flex items-center gap-2 rounded-lg border border-border/50 bg-muted/20 px-3 py-2">
                                        <span class="text-xs font-bold" x-text="action.label"></span>
                                        <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-mono text-muted-foreground" x-text="action.handle"></span>
                                        <select x-model="action.placement" class="ml-auto rounded border border-border bg-background px-2 py-1 text-xs">
                                            <option value="inline">Inline</option>
                                            <option value="dropdown">Dropdown</option>
                                        </select>
                                        <button type="button" @click="planData.studio_slices[selectedSliceIdx].blueprint.ui.directory.row_actions.items.splice(aIdx, 1)"
                                            class="rounded p-1 text-destructive hover:bg-destructive/10 text-xs">✕</button>
                                    </div>
                                </template>
                            </div>
                            <button type="button" @click="planData.studio_slices[selectedSliceIdx].blueprint.ui.directory.row_actions.items.push({handle: 'custom_' + Date.now(), label: 'Custom', icon: 'plus', placement: 'dropdown'})"
                                class="mt-2 rounded-lg border border-dashed border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground hover:border-primary">+ Add Action</button>
                        </div>

                        <!-- Bulk Actions (editable — same shape as Blueprint Studio) -->
                        <div class="rounded-xl border border-emerald-500/30 bg-card p-4 space-y-3">
                            <div class="flex items-center justify-between gap-2">
                                <h4 class="font-bold text-sm">📦 Table Bulk Actions</h4>
                                <div class="flex gap-1.5">
                                    <button type="button" class="rounded-lg border border-border px-2 py-1 text-[10px] font-semibold"
                                        @click="
                                            const s = planData.studio_slices[selectedSliceIdx];
                                            if (!s.blueprint) s.blueprint = {};
                                            if (!s.blueprint.ui) s.blueprint.ui = {};
                                            if (!s.blueprint.ui.directory) s.blueprint.ui.directory = {};
                                            if (!Array.isArray(s.blueprint.ui.directory.bulk_actions)) s.blueprint.ui.directory.bulk_actions = [];
                                            s.blueprint.ui.directory.bulk_actions.push({ handle: 'export', label: 'Export', type: 'native' });
                                            s.blueprint.ui.directory.bulk_actions.push({ handle: 'approve', label: 'Approve', type: 'workflow', transition: 'approve' });
                                            s.blueprint.ui.directory.bulk_actions.push({ handle: 'reject', label: 'Reject', type: 'workflow', transition: 'reject', requires_remarks: true });
                                        ">+ Pack</button>
                                    <button type="button" class="rounded-lg bg-primary px-2 py-1 text-[10px] font-bold text-primary-foreground"
                                        @click="
                                            const s = planData.studio_slices[selectedSliceIdx];
                                            if (!s.blueprint) s.blueprint = {};
                                            if (!s.blueprint.ui) s.blueprint.ui = {};
                                            if (!s.blueprint.ui.directory) s.blueprint.ui.directory = {};
                                            if (!Array.isArray(s.blueprint.ui.directory.bulk_actions)) s.blueprint.ui.directory.bulk_actions = [];
                                            s.blueprint.ui.directory.bulk_actions.push({ handle: 'custom_' + Date.now(), label: 'Custom', type: 'native' });
                                        ">+ Add</button>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <template x-for="(ba, bIdx) in (planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.bulk_actions || [])" :key="bIdx">
                                    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-border/60 bg-muted/10 p-2 text-xs">
                                        <template x-if="typeof ba === 'string'">
                                            <span class="rounded-lg bg-emerald-500/10 px-2 py-1 font-semibold text-emerald-600" x-text="ba"></span>
                                        </template>
                                        <template x-if="typeof ba === 'object'">
                                            <div class="flex flex-wrap items-center gap-2 w-full">
                                                <input type="text" x-model="ba.handle" class="w-24 rounded border border-border bg-background px-1.5 py-1 font-mono text-[11px]" placeholder="handle">
                                                <input type="text" x-model="ba.label" class="w-28 rounded border border-border bg-background px-1.5 py-1 text-[11px]" placeholder="Label">
                                                <select x-model="ba.type" class="rounded border border-border bg-background px-1.5 py-1 text-[11px]">
                                                    <option value="native">Native</option>
                                                    <option value="workflow">Workflow</option>
                                                </select>
                                                <input type="text" x-show="ba.type === 'workflow'" x-model="ba.transition" class="w-28 rounded border border-border bg-background px-1.5 py-1 font-mono text-[11px]" placeholder="transition">
                                                <label class="flex items-center gap-1" x-show="ba.type === 'workflow'">
                                                    <input type="checkbox" x-model="ba.requires_remarks" class="rounded"> Remarks
                                                </label>
                                                <button type="button" class="ml-auto text-rose-500"
                                                    @click="planData.studio_slices[selectedSliceIdx].blueprint.ui.directory.bulk_actions.splice(bIdx, 1)">✕</button>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                            <div class="text-xs text-muted-foreground" x-show="!(planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.directory?.bulk_actions?.length)">
                                No bulk actions — add native (export/delete) or workflow transitions for the index table.
                            </div>
                        </div>

                        <!-- Step 3: Create & Edit Form Sections -->
                        <div class="rounded-xl border border-indigo-500/30 bg-card p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="font-bold text-sm">📝 Step 3: Create & Edit Form</h4>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] text-muted-foreground">Mode:</span>
                                    <span class="rounded-lg bg-primary/10 px-2.5 py-1 text-[11px] font-bold text-primary"
                                        x-text="planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.forms?.mode || planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.presentation?.create || 'drawer'"></span>
                                </div>
                            </div>
                            <div class="space-y-2.5">
                                <template x-for="(sec, sIdx) in (planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.forms?.sections || [])" :key="sIdx">
                                    <div class="rounded-lg border border-border/60 bg-muted/10 p-3">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="size-5 rounded bg-primary/10 text-primary flex items-center justify-center font-bold text-[10px]" x-text="'S' + (sIdx + 1)"></span>
                                            <span class="text-xs font-bold" x-text="sec.title || sec.name || 'Section ' + (sIdx + 1)"></span>
                                            <span class="ml-auto text-[10px] text-muted-foreground" x-text="(sec.fields?.length || 0) + ' fields'"></span>
                                        </div>
                                        <div class="flex flex-wrap gap-1.5">
                                            <template x-for="(fld, fIdx) in (sec.fields || [])" :key="fIdx">
                                                <span class="rounded-md border border-border/50 bg-card px-2 py-0.5 text-[11px] font-mono" x-text="fld.handle"></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                                <div class="text-xs text-muted-foreground"
                                    x-show="!(planData.studio_slices[selectedSliceIdx]?.blueprint?.ui?.forms?.sections?.length)">
                                    No form sections in port plan. Sections auto-generate from fields on port.
                                </div>
                            </div>
                            <p class="text-[11px] text-muted-foreground">💡 Edit sections in Slice Studio after porting — full section editor with field assignment.</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Tab: Data Sources -->
        <div x-show="activeTab === 'datasources'" class="space-y-3">
            <div x-show="!planData" class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                Click <strong>🔍 Analyze</strong> to detect data sources.
            </div>
            <div x-show="planData" class="space-y-3">
                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="font-bold text-sm text-foreground mb-1">💡 Recommendation</p>
                    <p class="text-xs text-muted-foreground" x-text="planData?.data_sources?.recommendation"></p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div class="rounded-xl border border-border bg-card p-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="font-bold text-sm">🗄️ Real Data</p>
                            <span x-show="planData?.data_sources?.real_data?.available" class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-500">Available</span>
                            <span x-show="!planData?.data_sources?.real_data?.available" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-500">Not configured</span>
                        </div>
                        <p class="text-xs text-muted-foreground" x-text="planData?.data_sources?.real_data?.note"></p>
                        <p class="text-[11px] text-emerald-400 mt-2">✅ Always up-to-date</p>
                    </div>
                    <div class="rounded-xl border border-border bg-card p-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="font-bold text-sm">🌱 Seeders</p>
                            <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400" x-text="(planData?.data_sources?.seeders?.length || 0) + ' found'"></span>
                        </div>
                        <p class="text-[11px] text-amber-400 mb-2">⚠️ May be outdated</p>
                        <div class="space-y-1.5 max-h-[200px] overflow-y-auto">
                            <template x-for="(s, idx) in (planData?.data_sources?.seeders || [])" :key="idx">
                                <div class="rounded-lg bg-muted/20 p-2 text-[11px]">
                                    <span class="font-mono font-bold" x-text="s.class"></span>
                                    <span x-show="s.is_reference_data" class="ml-1 rounded-full bg-emerald-500/10 px-1.5 text-[10px] font-bold text-emerald-500">reference</span>
                                    <div class="text-muted-foreground" x-text="'→ ' + (s.slices || []).join(', ')"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
                <!-- Data source selector -->
                <div class="rounded-xl border border-primary/20 bg-primary/5 p-4">
                    <label class="block text-xs font-bold text-foreground mb-2">Select data source for porting:</label>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <template x-for="opt in dataSourceOptions" :key="opt.value">
                            <button @click="dataSource = opt.value" :class="dataSource === opt.value ? 'bg-primary text-primary-foreground border-primary' : 'bg-card text-foreground border-border'" class="rounded-lg border p-3 text-left transition">
                                <p class="font-bold text-xs" x-text="opt.label"></p>
                                <p class="text-[10px] opacity-70 mt-0.5" x-text="opt.desc"></p>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: ETL Pipeline -->
        <div x-show="activeTab === 'etl'" class="space-y-3">
            <!-- RBAC protection notice -->
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4">
                <div class="flex items-start gap-3">
                    <span class="text-lg">🛡️</span>
                    <div class="flex-1">
                        <p class="text-xs font-bold text-foreground">Framework auth tables are protected</p>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            <code class="font-mono bg-muted px-1 rounded">roles</code>,
                            <code class="font-mono bg-muted px-1 rounded">permissions</code>,
                            <code class="font-mono bg-muted px-1 rounded">permission_role</code>,
                            <code class="font-mono bg-muted px-1 rounded">role_user</code>
                            are <strong>never wiped</strong> by the ETL — your
                            <code class="font-mono bg-muted px-1 rounded">super-admin</code> /
                            <code class="font-mono bg-muted px-1 rounded">super-dev</code> roles and
                            <code class="font-mono bg-muted px-1 rounded">studio.access</code> survive every port.
                            <code class="font-mono bg-muted px-1 rounded">users</code> are merged by email (existing accounts never deleted).
                        </p>
                        <label class="mt-2 flex items-center gap-2 text-[11px] text-muted-foreground cursor-pointer select-none">
                            <input type="checkbox" x-model="importAuth" class="rounded border-border">
                            <span>Also import legacy <strong>roles/permissions</strong> via normalized merge <span class="text-muted-foreground/70">(opt-in: legacy <code class="font-mono">admin</code> becomes <code class="font-mono">legacy-admin</code>; framework roles are never overwritten)</span></span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div x-show="!planData" class="p-6 text-center text-sm text-muted-foreground">Click <strong>🔍 Analyze</strong> first.</div>
            <div x-show="planData" class="overflow-x-auto">
                <table class="w-full text-left text-xs min-w-[500px]">
                    <thead class="bg-muted/50 text-[10px] uppercase font-bold text-muted-foreground">
                        <tr><th class="p-3">Table</th><th class="p-3 text-right">Rows</th><th class="p-3">Mode</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="(row, idx) in (planData?.etl_data_pipeline?.top_tables_to_migrate || [])" :key="idx">
                            <tr>
                                <td class="p-3 font-mono font-semibold" x-text="row.table"></td>
                                <td class="p-3 text-right font-mono font-bold text-emerald-400" x-text="row.rows"></td>
                                <td class="p-3 text-muted-foreground">Chunked streaming</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
        </div>

        <!-- Tab: JSON Plan -->
        <div x-show="activeTab === 'json'" class="rounded-xl border border-border bg-slate-950 p-4 overflow-x-auto">
            <pre class="text-[11px] font-mono text-emerald-400 whitespace-pre-wrap break-words" x-text="planJsonText || 'No plan yet. Click Analyze.'"></pre>
        </div>

        <!-- Tab: Execution Log -->
        <div x-show="activeTab === 'log'" class="rounded-xl border border-slate-800 bg-slate-950 p-4 font-mono text-[11px] text-emerald-400 h-[300px] overflow-y-auto">
            <template x-for="(log, idx) in logs" :key="idx"><div x-text="log"></div></template>
            <div x-show="executing" class="animate-pulse text-indigo-400">Processing...</div>
            <div x-show="!logs.length && !executing" class="text-slate-500">No execution yet.</div>
        </div>

        <!-- Action bar (sticky bottom on mobile) -->
        <div x-show="planData" class="sticky bottom-0 -mx-4 px-4 py-3 bg-card/95 backdrop-blur border-t border-border md:static md:mx-0 md:px-0 md:bg-transparent md:border-0">
            <div class="flex gap-2">
                <button @click="loadIntoBlueprint()" class="flex-1 rounded-lg bg-indigo-600 px-4 py-3 text-sm font-bold text-white hover:bg-indigo-500">📥 Load into Blueprint Studio</button>
                <button @click="execute()" :disabled="executing" class="flex-1 rounded-lg bg-emerald-600 px-4 py-3 text-sm font-bold text-white hover:bg-emerald-500 disabled:opacity-50">⚡ Execute Porting</button>
            </div>
        </div>
    </div>
</div>

<script>
function portingStudio() {
    return {
        activeTab: 'guide',
        tabs: [
            { id: 'guide', label: '🧭 Workflow Guide' },
            { id: 'journeys', label: '👥 User Journeys' },
            { id: 'sidebar', label: '🖥️ Sidebar & Navigation' },
            { id: 'vsa', label: '🏗️ VSA Architecture' },
            { id: 'pages', label: '📄 Page & UI' },
            { id: 'datasources', label: '💾 Data Sources' },
            { id: 'etl', label: '⚡ ETL Pipeline' },
            { id: 'json', label: '📋 JSON Plan' },
            { id: 'log', label: '📟 Execution Log' },
        ],
        workflowSteps: [
            { title: 'Review User Journeys', desc: 'Understand how end users, admins, and clients flow through the system. This is the most important part.', tab: 'journeys', tabLabel: 'Journeys' },
            { title: 'Inspect Navigation', desc: 'Compare source navigation grouping with proposed VSA grouping. Reorder as needed.', tab: 'sidebar', tabLabel: 'Sidebar' },
            { title: 'Review VSA Architecture', desc: 'Check table-to-slice mapping. Look for dead tables that should be skipped.', tab: 'vsa', tabLabel: 'VSA' },
            { title: 'Choose Data Source', desc: 'Real data (always current) vs seeders (may be outdated). Pick per your needs.', tab: 'datasources', tabLabel: 'Data Sources' },
            { title: 'Execute & Load', desc: 'Run the porting, then load into Blueprint Studio for fine-tuning.', tab: 'log', tabLabel: 'Execution' },
        ],
        dataSourceOptions: [
            { value: 'real', label: '🗄️ Real Data', desc: 'Live DB, always current' },
            { value: 'seeders', label: '🌱 Seeders', desc: 'May be outdated' },
            { value: 'both', label: '🔀 Both', desc: 'Real + reference seeders' },
            { value: 'none', label: '🚫 None', desc: 'Schema only' },
        ],
        preset: 'erp',
        sourceType: 'local',
        sourcePath: '',
        gitUrl: '',
        gitBranch: '',
        gitToken: '',
        gitKeep: false,
        savedPresets: [],
        selectedSavedPreset: '',
        cachedSources: [],
        showSourceManager: false,
        dataSource: 'real',
        analyzing: false,
        executing: false,
        importAuth: false,
        error: '',
        planData: null,
        planJsonText: '',
        logs: [],

        async analyze() {
            this.analyzing = true;
            this.error = '';
            try {
                const payload = { preset: this.preset, domain: 'ERP', source_type: this.sourceType };
                if (this.sourceType === 'git') {
                    payload.source = this.gitUrl;
                    payload.git_branch = this.gitBranch;
                    payload.git_token = this.gitToken;
                    payload.git_keep = this.gitKeep;
                    if (!this.gitUrl) throw new Error('Enter a Git repository URL.');
                } else {
                    payload.source = this.sourcePath;
                }
                const res = await fetch('/laraslice/wizard/port-plan', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Analysis failed');
                this.planData = data.plan || data;
                this.planJsonText = JSON.stringify(this.planData, null, 2);
                if (data.cloned_from) {
                    this.log('📥 Cloned from ' + data.cloned_from + (this.gitBranch ? ' @ ' + this.gitBranch : ''));
                }
                this.activeTab = 'journeys';
            } catch (e) {
                this.error = e.message;
            } finally {
                this.analyzing = false;
            }
        },

        moveGroup(gIdx, direction) {
            const groups = this.planData?.sidebar_preview?.groups;
            if (!groups) return;
            const newIdx = gIdx + direction;
            if (newIdx < 0 || newIdx >= groups.length) return;
            const [moved] = groups.splice(gIdx, 1);
            groups.splice(newIdx, 0, moved);
        },

        loadIntoBlueprint() {
            if (!this.planData) return;
            sessionStorage.setItem('laraslice_port_plan', JSON.stringify(this.planData));
            window.location.href = '/laraslice/wizard/blueprint';
        },

        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        async init() {
            // Load saved presets on page init
            try {
                const res = await fetch('/laraslice/wizard/port-saved-presets', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.success) this.savedPresets = data.presets || [];
            } catch (e) { /* silent */ }
        },

        async savePreset() {
            if (!this.planData) return;
            const name = prompt('Preset name:', (this.sourceType === 'git' ? this.gitUrl.split('/').pop().replace(/\.git$/, '') : 'Custom') + ' — ' + new Date().toLocaleDateString());
            if (!name) return;
            try {
                const res = await fetch('/laraslice/wizard/port-saved-presets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    body: JSON.stringify({
                        name,
                        plan: this.planData,
                        source_type: this.sourceType,
                        source: this.sourceType === 'git' ? this.gitUrl : this.sourcePath,
                        git_branch: this.gitBranch,
                        domain: 'ERP',
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Save failed');
                this.log('💾 Preset saved: ' + data.name);
                // Refresh list
                const lr = await fetch('/laraslice/wizard/port-saved-presets', { headers: { 'Accept': 'application/json' } });
                const ld = await lr.json();
                if (ld.success) this.savedPresets = ld.presets || [];
            } catch (e) {
                this.error = e.message;
            }
        },

        async loadPreset() {
            if (!this.selectedSavedPreset) return;
            try {
                const res = await fetch('/laraslice/wizard/port-saved-presets/' + encodeURIComponent(this.selectedSavedPreset), { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Load failed');
                const p = data.preset;
                this.planData = p.plan;
                this.planJsonText = JSON.stringify(this.planData, null, 2);
                this.sourceType = p.source_type || 'local';
                if (this.sourceType === 'git') { this.gitUrl = p.source || ''; this.gitBranch = p.git_branch || ''; }
                else { this.sourcePath = p.source || ''; }
                this.log('📂 Preset loaded: ' + p.name);
                this.activeTab = 'journeys';
            } catch (e) {
                this.error = e.message;
            }
        },

        async deletePreset() {
            if (!this.selectedSavedPreset || !confirm('Delete this preset?')) return;
            try {
                await fetch('/laraslice/wizard/port-saved-presets/' + encodeURIComponent(this.selectedSavedPreset), {
                    method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf() },
                });
                this.savedPresets = this.savedPresets.filter(p => p.slug !== this.selectedSavedPreset);
                this.selectedSavedPreset = '';
            } catch (e) {
                this.error = e.message;
            }
        },

        toggleSourceManager() {
            this.showSourceManager = !this.showSourceManager;
        },

        async loadCachedSources() {
            try {
                const res = await fetch('/laraslice/wizard/port-cached-sources', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.success) this.cachedSources = data.sources || [];
            } catch (e) { /* silent */ }
        },

        async toggleKeep(s) {
            try {
                const res = await fetch('/laraslice/wizard/port-cached-sources/' + encodeURIComponent(s.key) + '/keep', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
                    body: JSON.stringify({ keep: !s.keep }),
                });
                const data = await res.json();
                if (data.success) s.keep = data.keep;
            } catch (e) {
                this.error = e.message;
            }
        },

        async deleteCachedSource(key) {
            if (!confirm('Delete this cached repository?')) return;
            try {
                await fetch('/laraslice/wizard/port-cached-sources/' + encodeURIComponent(key), {
                    method: 'DELETE', headers: { 'X-CSRF-TOKEN': this.csrf() },
                });
                this.cachedSources = this.cachedSources.filter(s => s.key !== key);
            } catch (e) {
                this.error = e.message;
            }
        },

        async execute() {
            this.executing = true;
            this.activeTab = 'log';
            this.logs.push('→ Starting porting execution (data source: ' + this.dataSource + ')...');
            try {
                const res = await fetch('/laraslice/wizard/port-execute', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ plan: this.planData, data_source: this.dataSource, import_auth: this.importAuth }),
                });
                const data = await res.json();
                this.logs.push('✓ ' + (data.message || 'Porting completed'));
            } catch (e) {
                this.logs.push('✗ Error: ' + e.message);
            } finally {
                this.executing = false;
            }
        },
    };
}
</script>
@endsection
