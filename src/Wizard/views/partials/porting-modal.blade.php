<!-- Port Legacy Application Studio Modal -->
<div x-show="showPortModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm" @keydown.escape.window="showPortModal = false" style="display: none;">
    <div class="w-full max-w-5xl rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-5 max-h-[92vh] overflow-y-auto" @click.outside="showPortModal = false">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-border/40 pb-4">
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-xl">
                    🚀
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-bold text-foreground">LaraSlice Porting Studio</h3>
                        <span class="rounded-full bg-indigo-500/20 px-2 py-0.5 text-[10px] font-bold text-indigo-400">VSA Reverse-Engineering</span>
                    </div>
                    <p class="text-xs text-muted-foreground">Study legacy schema, preview canonical VSA architecture & sidebar, inspect JSON plan, and migrate data.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="loadPortedToStudio()" :disabled="!portPlanData && !portInspectionData" :class="(!portPlanData && !portInspectionData) ? 'opacity-40 cursor-not-allowed' : ''" class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-1.5 text-xs font-bold text-emerald-400 hover:bg-emerald-500/20 transition cursor-pointer shadow-xs disabled:hover:bg-emerald-500/10" title="Load entire ported suite directly into Studio canvas (requires an analyzed plan)">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    <span>📥 Load into Studio</span>
                </button>
                <button type="button" @click="showPortModal = false" class="rounded-lg p-1 text-muted-foreground hover:bg-muted hover:text-foreground cursor-pointer">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Inline error banner (replaces blocking alert() popups) -->
        <div x-show="portError" x-transition class="flex items-start gap-2.5 rounded-xl border border-rose-500/40 bg-rose-500/10 px-3.5 py-2.5 text-xs text-rose-600 dark:text-rose-400">
            <span class="text-sm leading-none mt-0.5">⚠️</span>
            <div class="flex-1">
                <p class="font-bold">Porting action failed</p>
                <p x-text="portError" class="mt-0.5 opacity-90"></p>
            </div>
            <button type="button" @click="portError = ''" class="rounded-md p-1 hover:bg-rose-500/20 transition cursor-pointer" title="Dismiss">
                <span class="text-sm leading-none">✕</span>
            </button>
        </div>

        <!-- Step 1: Preset & Source Selection -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5 bg-muted/20 p-3.5 rounded-xl border border-border/60">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">Architecture Preset / Example *</label>
                <select x-model="portPreset" @change="selectPortPreset($event.target.value)" class="w-full rounded-lg border border-input bg-card px-3 py-2 text-xs font-medium text-foreground focus:outline-none focus:ring-1 focus:ring-primary shadow-xs">
                    <option value="erp">🏢 ERP Enterprise Suite (358 tables → 23 Slices, 15k records)</option>
                    <option value="fospah">⚖️ FOSPAH Judicial Case System (Adjudication & Hearings)</option>
                    <option value="custom">🔍 Inspect Custom Local Project / Database</option>
                    <option value="json">📋 Custom JSON Architecture Plan</option>
                </select>
                <span class="text-[10px] text-muted-foreground">Pre-configured canonical blueprint or dynamic introspection</span>
            </div>
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">Source Application Path</label>
                <input type="text" x-model="portSourcePath" placeholder="e.g. e:/laragon/www/erp" class="w-full rounded-lg border border-input bg-card px-3 py-2 text-xs font-mono text-foreground focus:outline-none focus:ring-1 focus:ring-primary shadow-xs">
                <span class="text-[10px] text-muted-foreground">Root directory of existing Laravel application</span>
            </div>
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">Target Domain Suite *</label>
                <div class="flex gap-2">
                    <input type="text" x-model="portDomain" placeholder="e.g. ERP" class="w-full rounded-lg border border-input bg-card px-3 py-2 text-xs font-semibold text-foreground focus:outline-none focus:ring-1 focus:ring-primary shadow-xs">
                    <button type="button" @click="fetchPortPlan()" :disabled="portInspecting" class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700 transition shadow-xs cursor-pointer disabled:opacity-50">
                        <span x-show="!portInspecting">🔍 Analyze</span>
                        <span x-show="portInspecting">Analyzing...</span>
                    </button>
                </div>
                <span class="text-[10px] text-muted-foreground">Super-domain namespace for vertical slices</span>
            </div>
        </div>

        <!-- Step 2: Interactive Tabs Header -->
        <div class="flex items-center justify-between border-b border-border/60 pb-2">
            <div class="inline-flex rounded-lg p-0.5 bg-muted/60 border border-border text-xs">
                <button type="button" @click="portActiveTab = 'sidebar'" :class="portActiveTab === 'sidebar' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                    <span>🖥️ Sidebar & Navigation Live Preview</span>
                </button>
                <button type="button" @click="portActiveTab = 'vsa'" :class="portActiveTab === 'vsa' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                    <span>🏗️ VSA Domain Architecture</span>
                </button>
                <button type="button" @click="portActiveTab = 'journeys'" :class="portActiveTab === 'journeys' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                    <span>🧭 User Journeys</span>
                </button>
                <button type="button" @click="portActiveTab = 'pages'" :class="portActiveTab === 'pages' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                    <span>📄 Page & UI Architecture</span>
                </button>
                <button type="button" @click="portActiveTab = 'json'" :class="portActiveTab === 'json' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                    <span>📋 JSON Architecture Plan</span>
                </button>
                <button type="button" @click="portActiveTab = 'etl'" :class="portActiveTab === 'etl' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                    <span>⚡ ETL Data Pipeline</span>
                </button>
                <button type="button" @click="portActiveTab = 'datasources'" :class="portActiveTab === 'datasources' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                    <span>💾 Data Sources</span>
                </button>
                <button type="button" @click="portActiveTab = 'log'" :class="portActiveTab === 'log' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1.5 rounded-md transition cursor-pointer flex items-center gap-1.5">
                    <span>📜 Execution Terminal</span>
                </button>
            </div>

            <div x-show="portPlanData" class="flex items-center gap-2 text-xs">
                <span class="text-muted-foreground">Detected:</span>
                <span class="font-bold text-foreground" x-text="portPlanData?.summary?.total_canonical_slices || 0"></span>
                <span class="text-muted-foreground">Slices across</span>
                <span class="font-bold text-foreground" x-text="portPlanData?.summary?.total_modules || 0"></span>
                <span class="text-muted-foreground">Modules</span>
            </div>
        </div>

        <!-- Step-by-step Developer Guidance -->
        <div x-show="portPlanData" class="rounded-xl border border-blue-500/20 bg-blue-500/5 p-4">
            <p class="font-bold text-sm text-foreground mb-2">🧭 Recommended Porting Workflow</p>
            <ol class="space-y-1.5 text-xs text-muted-foreground">
                <li class="flex gap-2">
                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-500/20 text-blue-600 text-[10px] font-bold flex items-center justify-center">1</span>
                    <span><strong class="text-foreground">Review User Journeys</strong> — Check the 🧭 User Journeys tab to understand how end users, admins, and clients flow through the system. This is the most important part.</span>
                </li>
                <li class="flex gap-2">
                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-500/20 text-blue-600 text-[10px] font-bold flex items-center justify-center">2</span>
                    <span><strong class="text-foreground">Inspect VSA Architecture</strong> — Review the 🏗️ tab to see how tables map to slices. Check for dead tables (v1 legacy) that should be skipped.</span>
                </li>
                <li class="flex gap-2">
                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-500/20 text-blue-600 text-[10px] font-bold flex items-center justify-center">3</span>
                    <span><strong class="text-foreground">Review Page & UI</strong> — Check the 📄 tab to see the generated <code class="bg-muted px-1 rounded">ui:</code> schema with real columns and formatters.</span>
                </li>
                <li class="flex gap-2">
                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-500/20 text-blue-600 text-[10px] font-bold flex items-center justify-center">4</span>
                    <span><strong class="text-foreground">Load into Blueprint Studio</strong> — Click "Load into Studio" to visualize and customize the slices before scaffolding.</span>
                </li>
                <li class="flex gap-2">
                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-500/20 text-blue-600 text-[10px] font-bold flex items-center justify-center">5</span>
                    <span><strong class="text-foreground">Execute Porting</strong> — Run the scaffold, then check <code class="bg-muted px-1 rounded">porting_notes</code> in each slice.yaml for manual tasks (PDF, email, gateways).</span>
                </li>
            </ol>
        </div>

        <!-- Tab 1: Live Sidebar & Navigation Preview -->
        <div x-show="portActiveTab === 'sidebar'" class="space-y-4">
            <!-- Source Navigation Comparison -->
            <div x-show="portPlanData?.sidebar_preview?.source_navigation?.groups?.length" class="rounded-xl border border-indigo-500/20 bg-indigo-500/5 p-4">
                <p class="font-bold text-sm text-foreground mb-1">🔍 Source Navigation Detected</p>
                <p class="text-xs text-muted-foreground mb-2">
                    Found in: <code class="font-mono text-indigo-400" x-text="portPlanData.sidebar_preview.source_navigation.source"></code>.
                    Compare how the source groups navigation vs our proposed VSA grouping below.
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="(nav, nIdx) in (portPlanData.sidebar_preview.source_navigation.groups || [])" :key="nIdx">
                        <span class="rounded-full bg-indigo-500/10 px-2.5 py-1 text-[11px] font-semibold text-indigo-300 border border-indigo-500/20" x-text="nav.label + ' (' + nav.order + ')'"></span>
                    </template>
                </div>
                <p class="text-[11px] text-muted-foreground mt-2 italic" x-text="portPlanData?.sidebar_preview?.navigation_note"></p>
            </div>
            <div x-show="portPlanData && !(portPlanData?.sidebar_preview?.source_navigation?.groups?.length)" class="rounded-xl border border-dashed border-border p-3 text-center text-[11px] text-muted-foreground">
                Source navigation not detected. Proposed grouping below is auto-generated from slice mapping. You can reorder groups in Slice Studio after scaffolding.
            </div>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                <!-- Left Pane: Rendered BlatUI Sidebar Shell -->
                <div class="md:col-span-5 rounded-xl border border-border bg-slate-950 p-4 text-slate-100 shadow-inner flex flex-col h-[420px]">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3 mb-3">
                        <div class="flex items-center gap-2">
                            <div class="size-6 rounded-md bg-indigo-600 flex items-center justify-center font-bold text-white text-xs">LS</div>
                            <span class="font-bold text-xs tracking-wide" x-text="portPlanData?.sidebar_preview?.title || 'ERP Navigation'"></span>
                        </div>
                        <span class="rounded bg-slate-800 px-1.5 py-0.5 text-[9px] font-mono text-slate-400">BlatUI Shell</span>
                    </div>

                    <!-- Scrollable Menu Groups (reorderable) -->
                    <div class="space-y-3.5 overflow-y-auto pr-1 text-xs">
                        <template x-for="(group, gIdx) in (portPlanData?.sidebar_preview?.groups || [])" :key="gIdx">
                            <div class="space-y-1 rounded-lg border border-transparent hover:border-slate-700/50 p-1">
                                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 px-2 flex items-center justify-between">
                                    <span class="flex items-center gap-1.5">
                                        <button type="button" @click="moveSidebarGroup(gIdx, -1)" :disabled="gIdx === 0" class="text-slate-500 hover:text-white disabled:opacity-30 text-xs" title="Move group up">▲</button>
                                        <button type="button" @click="moveSidebarGroup(gIdx, 1)" :disabled="gIdx === (portPlanData.sidebar_preview.groups.length - 1)" class="text-slate-500 hover:text-white disabled:opacity-30 text-xs" title="Move group down">▼</button>
                                        <span x-text="group.name"></span>
                                    </span>
                                    <span x-show="group.collapsible" class="text-[9px] text-slate-600">▾</span>
                                </div>
                                <div class="space-y-0.5">
                                    <template x-for="(item, iIdx) in group.items" :key="iIdx">
                                        <div class="group flex items-center justify-between rounded-lg px-2.5 py-1.5 text-slate-300 hover:bg-slate-800/80 hover:text-white transition cursor-pointer">
                                            <div class="flex items-center gap-2 truncate">
                                                <span class="text-slate-400 group-hover:text-indigo-400">●</span>
                                                <span class="font-medium truncate" x-text="item.label"></span>
                                            </div>
                                            <span x-show="item.badge" class="rounded-full bg-indigo-500/20 px-2 py-0.2 text-[10px] font-bold text-indigo-300" x-text="item.badge"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="border-t border-slate-800 pt-2 mt-2">
                        <p class="text-[10px] text-slate-500">💡 Use ▲▼ to reorder groups. Changes apply when you load into Blueprint Studio.</p>
                    </div>
                </div>

                <!-- Right Pane: Architecture Explanation & Tab Details -->
                <div class="md:col-span-7 rounded-xl border border-border bg-card p-4 space-y-3 h-[420px] overflow-y-auto">
                    <h4 class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-2">
                        <span>Clean Navigation Architecture vs. Legacy Clutter</span>
                        <span x-show="!portPlanData" class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[9px] font-bold text-amber-600 dark:text-amber-400">EXAMPLE PREVIEW</span>
                        <span x-show="portPlanData" class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[9px] font-bold text-emerald-500">LIVE ANALYSIS</span>
                    </h4>
                    <p x-show="!portPlanData" class="text-xs text-muted-foreground leading-relaxed">
                        <span class="font-bold text-amber-600 dark:text-amber-400">Example — not your data.</span>
                        In the legacy system, 358 tables created 101 raw sidebar items with 23 isolated settings tables under Administration. In LaraSlice VSA, all entities are grouped into bounded business contexts with tabbed views. Run <span class="font-bold">🔍 Analyze</span> against your source to see your real architecture here.
                    </p>
                    <p x-show="portPlanData" class="text-xs text-muted-foreground leading-relaxed" x-text="'Analyzed ' + (portPlanData.summary?.total_legacy_tables || 0) + ' legacy tables → ' + (portPlanData.summary?.total_canonical_slices || 0) + ' canonical slices across ' + (portPlanData.summary?.total_modules || 0) + ' modules.'">
                    </p>

                    <!-- Feature Highlights Grid -->
                    <div class="grid grid-cols-2 gap-2.5 pt-2">
                        <div class="rounded-lg border border-border/60 bg-muted/30 p-2.5 space-y-1">
                            <div class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <span>💼 Work (Projects & Tasks)</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground">Multi-tab Projects view: Overview, Milestones (100), Tasks (50), Members (50), Time Logs (19).</p>
                        </div>
                        <div class="rounded-lg border border-border/60 bg-muted/30 p-2.5 space-y-1">
                            <div class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <span>💳 Financial Accounting</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground">Invoices (19) with line items (38), linked payments (14), bank accounts (16), and credit notes.</p>
                        </div>
                        <div class="rounded-lg border border-border/60 bg-muted/30 p-2.5 space-y-1">
                            <div class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <span>👥 Human Capital (HR)</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground">Employees (12), Shift Roster (240), Attendances (19), Leaves (10), Teams & Designations.</p>
                        </div>
                        <div class="rounded-lg border border-border/60 bg-muted/30 p-2.5 space-y-1">
                            <div class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <span>⚙️ Unified Settings Hub</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground">Consolidates 73 configuration tables into 1 single Settings slice with tabbed management.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: VSA Domain Architecture -->
        <div x-show="portActiveTab === 'vsa'" class="space-y-3">
            <div x-show="!portPlanData" class="rounded-xl border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                <p class="font-semibold text-foreground text-sm">No domain architecture yet</p>
                <p class="mt-1">Click <span class="font-bold">🔍 Analyze</span> to reverse-engineer the legacy schema into bounded modules and slices.</p>
            </div>
            <div class="max-h-[420px] overflow-y-auto space-y-3 pr-1">
                <template x-for="(mod, mIdx) in (portPlanData?.modules || [])" :key="mIdx">
                    <div class="rounded-xl border border-border bg-card p-3.5 space-y-2.5">
                        <div class="flex items-center justify-between border-b border-border/40 pb-2">
                            <div class="flex items-center gap-2">
                                <span class="size-6 rounded-md bg-primary/10 flex items-center justify-center text-primary text-xs font-bold" x-text="mod.name.charAt(0)"></span>
                                <h4 class="text-xs font-bold text-foreground" x-text="mod.name"></h4>
                                <span class="text-[10px] text-muted-foreground" x-text="'(' + (mod.slices ? mod.slices.length : 0) + ' Slices)'"></span>
                            </div>
                            <span class="text-[10px] text-muted-foreground font-mono" x-text="mod.slug"></span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                            <template x-for="(sl, sIdx) in mod.slices" :key="sIdx">
                                <div class="rounded-lg border border-border/60 bg-muted/20 p-2.5 space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-foreground" x-text="sl.name"></span>
                                        <span class="rounded bg-primary/10 px-1.5 py-0.2 text-[9px] font-bold text-primary" x-text="(sl.records_count || 0) + ' rows'"></span>
                                    </div>
                                    <div class="text-[10px] font-mono text-muted-foreground">Root: <span class="text-foreground" x-text="sl.root_table"></span></div>
                                    <div class="text-[10px] text-muted-foreground">
                                        Children: <span class="font-semibold" x-text="(sl.child_tables && sl.child_tables.length) ? sl.child_tables.map(c => typeof c === 'object' ? c.table : c).slice(0, 3).join(', ') + (sl.child_tables.length > 3 ? '...' : '') : 'None'"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Tab: User Journeys (Persona-based Workflow Analysis) -->
        <div x-show="portActiveTab === 'journeys'" class="space-y-4">
            <div x-show="!portPlanData" class="rounded-xl border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                <p class="font-semibold text-foreground mb-1">No analysis yet</p>
                <p>Click <span class="font-bold text-primary">Analyze</span> to detect user journeys from the source system.</p>
                <p class="mt-2 text-[11px]">Journeys show how different personas (end users, admins, clients) flow through the system — the most important part of porting.</p>
            </div>
            <div x-show="portPlanData?.journeys" class="space-y-3">
                <template x-for="(journey, key) in (portPlanData.journeys || {})" :key="key">
                    <div class="rounded-xl border border-border bg-card p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-lg" x-text="journey.persona === 'admin' ? '👑' : journey.persona === 'client' ? '🤝' : '👤'"></span>
                            <div>
                                <p class="font-bold text-sm text-foreground" x-text="journey.name"></p>
                                <p class="text-[11px] text-muted-foreground" x-text="journey.description"></p>
                            </div>
                            <span class="ml-auto rounded-full bg-primary/10 text-primary text-[10px] font-bold px-2 py-0.5" x-text="journey.persona"></span>
                        </div>
                        <template x-if="journey.steps">
                            <div class="flex items-center gap-1 flex-wrap mt-2">
                                <template x-for="(step, idx) in journey.steps" :key="idx">
                                    <div class="flex items-center gap-1">
                                        <span class="rounded-lg bg-muted px-2 py-1 text-[11px] font-medium" x-text="step.step"></span>
                                        <span x-show="idx < journey.steps.length - 1" class="text-muted-foreground">→</span>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="journey.flows">
                            <div class="space-y-2 mt-2">
                                <template x-for="flow in journey.flows" :key="flow.name">
                                    <div class="rounded-lg bg-muted/50 p-2">
                                        <p class="text-[11px] font-bold text-foreground mb-1" x-text="flow.name"></p>
                                        <div class="flex items-center gap-1 flex-wrap">
                                            <template x-for="(step, idx) in flow.steps" :key="idx">
                                                <div class="flex items-center gap-1">
                                                    <span class="rounded bg-background border border-border px-1.5 py-0.5 text-[10px]" x-text="step.step"></span>
                                                    <span x-show="idx < flow.steps.length - 1" class="text-muted-foreground text-[10px]">→</span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <div x-show="portPlanData && !portPlanData.journeys" class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-xs">
                <p class="font-semibold text-amber-600 mb-1">No journeys detected</p>
                <p class="text-muted-foreground">The source system may not have a Wizard or Portal structure. Journeys help developers understand user workflows before porting.</p>
            </div>
        </div>

        <!-- Tab 3: Page & UI Architecture (Step-by-Step UI Porting) -->
        <div x-show="portActiveTab === 'pages'" class="space-y-4">
            <div x-show="!portPlanData" class="rounded-xl border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                <p class="font-semibold text-foreground text-sm">No page architecture yet</p>
                <p class="mt-1">Click <span class="font-bold">🔍 Analyze</span> to generate the per-slice page specifications (index, forms, workflows).</p>
            </div>
            <!-- Top Bar: Methodology Step Indicator -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-2.5">
                <template x-for="st in (portPlanData?.page_architecture_guide?.steps || [
                    {step: 1, name: 'Index Listing Page', pattern: 'KPI Ribbon + Filter Bar + Table'},
                    {step: 2, name: 'Show Detail Workspace', pattern: 'Header + Alpine Sub-Tabs + Panels'},
                    {step: 3, name: 'Create & Edit Forms', pattern: 'Fieldsets + Searchable Selects'},
                    {step: 4, name: 'Workflow Transitions', pattern: 'State Machine + Triggers + Audit'}
                ])" :key="st.step">
                    <div class="rounded-xl border border-border/60 bg-muted/20 p-2.5 flex items-start gap-2.5 shadow-xs">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/20 text-xs font-bold text-primary" x-text="st.step"></span>
                        <div class="min-w-0">
                            <h5 class="text-xs font-bold text-foreground truncate" x-text="st.name"></h5>
                            <p class="text-[10px] text-muted-foreground truncate" x-text="st.pattern"></p>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Main 2-Column Split: Slice Explorer + Interactive Page Preview -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
                <!-- Left Column: Slice Directory Selector (4 cols) -->
                <div class="lg:col-span-4 rounded-xl border border-border bg-card p-3 flex flex-col h-[520px]">
                    <div class="flex items-center justify-between border-b border-border/40 pb-2 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Select Slice to Port</span>
                        <span class="rounded bg-primary/10 px-1.5 py-0.5 text-[9px] font-bold text-primary" x-text="getAllUiSlices().length + ' Slices'"></span>
                    </div>
                    <!-- Search Filter -->
                    <div class="mb-2">
                        <input type="text" x-model="uiSliceSearch" placeholder="Filter slices (e.g. Projects, Invoices)..." class="w-full rounded-lg border border-input bg-background px-2.5 py-1.5 text-xs text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                    <!-- Scrollable Slices List -->
                    <div class="space-y-1 overflow-y-auto pr-1 text-xs flex-1">
                        <template x-for="sl in getAllUiSlices()" :key="sl.name">
                            <div @click="selectedUiSlice = sl.name; selectedUiSubTab = 'overview'" 
                                 :class="selectedUiSlice === sl.name ? 'border-primary/50 bg-primary/10 text-primary font-bold shadow-xs' : 'border-border/40 hover:bg-muted/40 text-foreground'"
                                 class="group flex items-center justify-between rounded-lg border p-2 cursor-pointer transition">
                                <div class="flex items-center gap-2 truncate">
                                    <span class="size-2 rounded-full" :class="selectedUiSlice === sl.name ? 'bg-primary' : 'bg-muted-foreground/40'"></span>
                                    <div class="truncate">
                                        <div class="truncate text-xs font-semibold" x-text="sl.name"></div>
                                        <div class="text-[10px] text-muted-foreground font-mono" x-text="sl.moduleName || sl.root_table"></div>
                                    </div>
                                </div>
                                <span class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-mono text-muted-foreground" x-text="(sl.records_count || 0) + ' rows'"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Right Column: Interactive Page Architecture & Preview (8 cols) -->
                <div class="lg:col-span-8 rounded-xl border border-border bg-card p-4 flex flex-col h-[520px] overflow-y-auto space-y-3.5">
                    <template x-if="getSelectedUiSliceObj()">
                        <div class="space-y-3.5">
                            <!-- Slice Header & Active View Switcher -->
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-border/40 pb-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-bold text-foreground" x-text="getSelectedUiSliceObj()?.name + ' UI Architecture'"></h3>
                                        <span class="rounded bg-indigo-500/10 text-indigo-400 font-mono text-[10px] px-2 py-0.5" x-text="'Table: ' + getSelectedUiSliceObj()?.root_table"></span>
                                    </div>
                                    <p class="text-[11px] text-muted-foreground" x-text="'Bounded Context: ' + (getSelectedUiSliceObj()?.moduleName || 'Domain Module')"></p>
                                </div>

                                <!-- 4-Step Page Switcher Buttons -->
                                <div class="inline-flex rounded-lg p-0.5 bg-muted/60 border border-border text-xs shrink-0">
                                    <button type="button" @click="selectedUiPage = 'index'" :class="selectedUiPage === 'index' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-2.5 py-1 rounded-md transition cursor-pointer">
                                        📋 Listing (Index)
                                    </button>
                                    <button type="button" @click="selectedUiPage = 'show'" :class="selectedUiPage === 'show' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-2.5 py-1 rounded-md transition cursor-pointer">
                                        🔍 Workspace (Show)
                                    </button>
                                    <button type="button" @click="selectedUiPage = 'forms'" :class="selectedUiPage === 'forms' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-2.5 py-1 rounded-md transition cursor-pointer">
                                        ✏️ Forms
                                    </button>
                                    <button type="button" @click="selectedUiPage = 'workflow'" :class="selectedUiPage === 'workflow' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-2.5 py-1 rounded-md transition cursor-pointer">
                                        ⚡ Workflow
                                    </button>
                                </div>
                            </div>

                                                        <!-- VIEW 1: INDEX LISTING PAGE -->
                            <div x-show="selectedUiPage === 'index'" class="space-y-3" x-data="{ portIndexView: 'columns' }">
                                <!-- Mode Switcher & Presentation Ribbon -->
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-muted/30 p-2.5 rounded-xl border border-border/60">
                                    <div class="inline-flex rounded-lg p-0.5 bg-muted/80 border border-border text-xs">
                                        <button type="button" @click="portIndexView = 'columns'" :class="portIndexView === 'columns' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-2.5 py-1 rounded-md transition cursor-pointer flex items-center gap-1.5">
                                            <span>⚙️ Semantic Columns & Formatters</span>
                                        </button>
                                        <button type="button" @click="portIndexView = 'preview'" :class="portIndexView === 'preview' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-2.5 py-1 rounded-md transition cursor-pointer flex items-center gap-1.5">
                                            <span>👁️ Table Preview</span>
                                        </button>
                                    </div>

                                    <!-- Form Presentation Mode Selector -->
                                    <div class="flex items-center gap-2">
                                        <span class="text-[11px] font-semibold text-muted-foreground">Form Mode:</span>
                                        <div class="inline-flex rounded-lg p-0.5 bg-background border border-border text-xs">
                                            <button type="button" 
                                                @click="if(!getSelectedUiSliceObj().ui) getSelectedUiSliceObj().ui = {}; if(!getSelectedUiSliceObj().ui.forms) getSelectedUiSliceObj().ui.forms = {}; getSelectedUiSliceObj().ui.forms.presentation = 'drawer'"
                                                :class="(getSelectedUiSliceObj()?.ui?.forms?.presentation !== 'page') ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                                class="px-2 py-0.5 rounded text-[10px] transition cursor-pointer flex items-center gap-1">
                                                <span>⚡ Slide-Over Drawer (?drawer=create)</span>
                                            </button>
                                            <button type="button" 
                                                @click="if(!getSelectedUiSliceObj().ui) getSelectedUiSliceObj().ui = {}; if(!getSelectedUiSliceObj().ui.forms) getSelectedUiSliceObj().ui.forms = {}; getSelectedUiSliceObj().ui.forms.presentation = 'page'"
                                                :class="(getSelectedUiSliceObj()?.ui?.forms?.presentation === 'page') ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'"
                                                class="px-2 py-0.5 rounded text-[10px] transition cursor-pointer flex items-center gap-1">
                                                <span>📄 Standalone Page</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- SUB-VIEW A: SEMANTIC COLUMNS & FILTERS BUILDER -->
                                <div x-show="portIndexView === 'columns'" class="space-y-3">
                                    <!-- Filter Bar Configuration & Searchable Combobox Toggle -->
                                    <div class="rounded-xl border border-border bg-card p-3 space-y-2">
                                        <div class="flex items-center justify-between border-b border-border/40 pb-1.5">
                                            <span class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                                <span>🔍</span> Multi-Dimensional Filters & Searchable Comboboxes
                                            </span>
                                            <span class="text-[10px] text-emerald-400 font-semibold bg-emerald-500/10 px-2 py-0.5 rounded-full">ERP Searchable Select Standard</span>
                                        </div>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2 pt-1 text-xs">
                                            <div class="p-2 rounded-lg border border-border/60 bg-muted/20 space-y-1">
                                                <div class="font-bold text-foreground text-[11px] flex items-center justify-between">
                                                    <span>Department / Role Filter</span>
                                                    <span class="text-[9px] font-mono text-primary">Single Select</span>
                                                </div>
                                                <label class="flex items-center gap-1.5 text-[10px] text-muted-foreground cursor-pointer">
                                                    <input type="checkbox" checked class="rounded border-border text-primary size-3 cursor-pointer">
                                                    <span>Enable Searchable Dropdown (<code class="font-mono text-primary">&lt;x-ui.searchable-select&gt;</code>)</span>
                                                </label>
                                            </div>

                                            <div class="p-2 rounded-lg border border-border/60 bg-muted/20 space-y-1">
                                                <div class="font-bold text-foreground text-[11px] flex items-center justify-between">
                                                    <span>Status Dimension</span>
                                                    <span class="text-[9px] font-mono text-primary">State Filter</span>
                                                </div>
                                                <label class="flex items-center gap-1.5 text-[10px] text-muted-foreground cursor-pointer">
                                                    <input type="checkbox" checked class="rounded border-border text-primary size-3 cursor-pointer">
                                                    <span>Status Pills with Color Badges</span>
                                                </label>
                                            </div>

                                            <div class="p-2 rounded-lg border border-border/60 bg-muted/20 space-y-1">
                                                <div class="font-bold text-foreground text-[11px] flex items-center justify-between">
                                                    <span>Off-Canvas Drawer Filters</span>
                                                    <span class="text-[9px] font-mono text-primary">Deep Query</span>
                                                </div>
                                                <label class="flex items-center gap-1.5 text-[10px] text-muted-foreground cursor-pointer">
                                                    <input type="checkbox" checked class="rounded border-border text-primary size-3 cursor-pointer">
                                                    <span>Secondary & Date Range Filter Drawer</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Columns Table -->
                                    <div class="rounded-xl border border-border overflow-hidden text-xs">
                                        <div class="px-3 py-2 bg-muted/40 border-b border-border flex items-center justify-between">
                                            <span class="font-bold text-foreground text-xs">Composite Semantic Directory Columns</span>
                                            <span class="text-[10px] text-muted-foreground">Adjust display label, formatters, and sortability</span>
                                        </div>
                                        <table class="w-full text-left">
                                            <thead class="bg-muted/50 text-[10px] uppercase font-bold text-muted-foreground border-b border-border">
                                                <tr>
                                                    <th class="p-2 text-center w-12">Order</th>
                                                    <th class="p-2 text-center w-10">Show</th>
                                                    <th class="p-2 w-36">Field / Key</th>
                                                    <th class="p-2 w-44">Display Label</th>
                                                    <th class="p-2">Composite Semantic Formatter</th>
                                                    <th class="p-2 text-center w-16">Sortable</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-border bg-card">
                                                <template x-for="(col, cIdx) in (getSelectedUiSliceObj()?.pages?.index?.columns || [])" :key="cIdx">
                                                    <tr class="hover:bg-muted/20 transition-colors">
                                                        <td class="p-2 text-center">
                                                            <div class="flex items-center justify-center gap-0.5">
                                                                <button type="button" 
                                                                    @click="
                                                                        const cols = getSelectedUiSliceObj()?.pages?.index?.columns;
                                                                        if (cols && cIdx > 0) {
                                                                            const temp = cols[cIdx];
                                                                            cols[cIdx] = cols[cIdx - 1];
                                                                            cols[cIdx - 1] = temp;
                                                                        }
                                                                    "
                                                                    :disabled="cIdx === 0" 
                                                                    class="p-0.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition text-[10px]" 
                                                                    title="Move Up">▲</button>
                                                                <button type="button" 
                                                                    @click="
                                                                        const cols = getSelectedUiSliceObj()?.pages?.index?.columns;
                                                                        if (cols && cIdx < cols.length - 1) {
                                                                            const temp = cols[cIdx];
                                                                            cols[cIdx] = cols[cIdx + 1];
                                                                            cols[cIdx + 1] = temp;
                                                                        }
                                                                    "
                                                                    :disabled="cIdx === (getSelectedUiSliceObj()?.pages?.index?.columns?.length - 1)" 
                                                                    class="p-0.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition text-[10px]" 
                                                                    title="Move Down">▼</button>
                                                            </div>
                                                        </td>
                                                        <td class="p-2 text-center">
                                                            <input type="checkbox" :checked="col.show !== false" @change="col.show = $event.target.checked" class="rounded border-border text-primary size-3.5 cursor-pointer">
                                                        </td>
                                                        <td class="p-2 font-mono text-[11px] font-bold text-foreground" x-text="col.field || col.key"></td>
                                                        <td class="p-2">
                                                            <input type="text" x-model="col.label" class="w-full h-7 px-2 bg-background border border-border rounded text-xs text-foreground focus:outline-none focus:border-primary">
                                                        </td>
                                                        <td class="p-2">
                                                            <select x-model="col.type" class="w-full h-7 px-2 bg-background border border-border rounded text-xs text-foreground focus:outline-none focus:border-primary">
                                                                <option value="text">text (Standard Raw Output)</option>
                                                                <option value="code_badge">code_badge (Mono Badge EMP-01 / INV-2024)</option>
                                                                <option value="composite_user">composite_user (Avatar + Name + Subtitle)</option>
                                                                <option value="status_pill">status_pill (Color-coded Active / Inactive pill)</option>
                                                                <option value="money_breakdown">money_breakdown (Amount + Currency Pill)</option>
                                                                <option value="progress_bar">progress_bar (0-100% Visual Progress)</option>
                                                                <option value="date_time">date_time (Formatted Timestamp)</option>
                                                            </select>
                                                        </td>
                                                        <td class="p-2 text-center">
                                                            <input type="checkbox" x-model="col.sortable" class="rounded border-border text-primary size-3.5 cursor-pointer">
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Table Row Actions & Drawer Deep-Linking in Porting Dialog -->
                                    <div class="rounded-xl border border-border overflow-hidden text-xs space-y-2 p-3 bg-card" x-init="
                                        if (getSelectedUiSliceObj()) {
                                            if (!getSelectedUiSliceObj().ui) getSelectedUiSliceObj().ui = {};
                                            if (!getSelectedUiSliceObj().ui.directory) getSelectedUiSliceObj().ui.directory = {};
                                            if (!getSelectedUiSliceObj().ui.directory.row_actions) {
                                                getSelectedUiSliceObj().ui.directory.row_actions = {
                                                    display: 'dropdown',
                                                    items: [
                                                        { handle: 'view', label: 'View Details', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                                                        { handle: 'edit', label: 'Edit Record', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                                                        { handle: 'delete', label: 'Delete Record', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                                                    ]
                                                };
                                            }
                                        }
                                    ">
                                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/40 pb-2">
                                            <div>
                                                <span class="font-bold text-foreground text-xs flex items-center gap-1.5">
                                                    <span>⚡</span> Table Row Actions & Deep-Linking Layout
                                                </span>
                                                <p class="text-[10px] text-muted-foreground">Select presentation format for row-level operations</p>
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <button type="button" @click="if(getSelectedUiSliceObj()?.ui?.directory?.row_actions) getSelectedUiSliceObj().ui.directory.row_actions.display = 'dropdown'"
                                                    :class="getSelectedUiSliceObj()?.ui?.directory?.row_actions?.display === 'dropdown' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground hover:text-foreground'"
                                                    class="px-2 py-0.5 rounded text-[10px] border border-border cursor-pointer">
                                                    3-Dots Menu
                                                </button>
                                                <button type="button" @click="if(getSelectedUiSliceObj()?.ui?.directory?.row_actions) {
                                                    getSelectedUiSliceObj().ui.directory.row_actions.display = 'hybrid';
                                                    if(getSelectedUiSliceObj().ui.directory.row_actions.items?.[0]) getSelectedUiSliceObj().ui.directory.row_actions.items[0].placement = 'inline';
                                                }"
                                                    :class="getSelectedUiSliceObj()?.ui?.directory?.row_actions?.display === 'hybrid' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground hover:text-foreground'"
                                                    class="px-2 py-0.5 rounded text-[10px] border border-border cursor-pointer">
                                                    Hybrid (ERP View Outside)
                                                </button>
                                                <button type="button" @click="if(getSelectedUiSliceObj()?.ui?.directory?.row_actions) {
                                                    getSelectedUiSliceObj().ui.directory.row_actions.display = 'inline';
                                                    getSelectedUiSliceObj().ui.directory.row_actions.items?.forEach(i => i.placement = 'inline');
                                                }"
                                                    :class="getSelectedUiSliceObj()?.ui?.directory?.row_actions?.display === 'inline' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground hover:text-foreground'"
                                                    class="px-2 py-0.5 rounded text-[10px] border border-border cursor-pointer">
                                                    Inline Buttons
                                                </button>
                                            </div>
                                        </div>

                                        <div class="space-y-1.5 max-h-36 overflow-y-auto pr-1">
                                            <template x-for="(act, aIdx) in (getSelectedUiSliceObj()?.ui?.directory?.row_actions?.items || [])" :key="aIdx">
                                                <div class="flex items-center justify-between gap-2 p-1.5 rounded-lg border border-border/60 bg-muted/20 text-xs">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-mono text-[10px] font-bold text-indigo-400" x-text="act.handle"></span>
                                                        <input type="text" x-model="act.label" class="h-6 w-28 px-1.5 text-[11px] rounded border border-border bg-card">
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <select x-model="act.placement" class="h-6 text-[10px] rounded border border-border bg-card px-1">
                                                            <option value="dropdown">3-Dots Menu</option>
                                                            <option value="inline">Inline (Outside)</option>
                                                        </select>
                                                        <select x-model="act.type" class="h-6 text-[10px] rounded border border-border bg-card px-1">
                                                            <option value="drawer">Drawer</option>
                                                            <option value="route">Route</option>
                                                            <option value="delete">Delete</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <!-- SUB-VIEW B: LIVE TABLE PREVIEW -->
                                <div x-show="portIndexView === 'preview'" class="space-y-3">
                                    <!-- Stats Ribbon Live Mockup -->
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                        <template x-for="(st, sIdx) in (getSelectedUiSliceObj()?.pages?.index?.stats_ribbon || [])" :key="sIdx">
                                            <div class="rounded-lg border border-border/60 bg-muted/30 p-2.5 space-y-1 shadow-xs">
                                                <div class="text-[10px] text-muted-foreground font-medium uppercase tracking-wider" x-text="st.label"></div>
                                                <div class="text-base font-extrabold text-foreground" x-text="st.value"></div>
                                                <div class="text-[9px] text-emerald-400 flex items-center gap-1">
                                                    <span>●</span>
                                                    <span x-text="st.change"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Filter Bar Mockup -->
                                    <div class="rounded-lg border border-border/60 bg-muted/20 p-2.5 flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <div class="flex items-center gap-2 flex-1 min-w-[200px]">
                                            <input type="text" disabled :placeholder="getSelectedUiSliceObj()?.pages?.index?.filter_bar?.search_placeholder || 'Search records...'" class="w-full rounded-md border border-input bg-card px-2.5 py-1 text-xs text-muted-foreground">
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <template x-for="(flt, fIdx) in (getSelectedUiSliceObj()?.pages?.index?.filter_bar?.filters || []).slice(0, 3)" :key="fIdx">
                                                <span class="rounded-md border border-border bg-card px-2 py-1 text-[10px] text-muted-foreground flex items-center gap-1">
                                                    <span x-text="flt.label"></span>
                                                    <span class="text-slate-500">▾</span>
                                                </span>
                                            </template>
                                            <span class="rounded-md bg-primary px-2.5 py-1 text-[11px] font-bold text-primary-foreground flex items-center gap-1">
                                                <span>+ Add Item</span>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Data Table Mockup -->
                                    <div class="rounded-lg border border-border overflow-hidden text-xs">
                                        <table class="w-full text-left">
                                            <thead class="bg-muted/50 text-[10px] uppercase font-bold text-muted-foreground border-b border-border">
                                                <tr>
                                                    <template x-for="(col, cIdx) in (getSelectedUiSliceObj()?.pages?.index?.columns || [])" :key="cIdx">
                                                        <th class="p-2.5" x-text="col.label"></th>
                                                    </template>
                                                    <th class="p-2.5 text-right">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-border">
                                                <tr class="hover:bg-muted/20">
                                                    <td class="p-2.5 font-bold text-foreground">Sample Operational Item #1</td>
                                                    <td class="p-2.5 text-muted-foreground">Enterprise Client A</td>
                                                    <td class="p-2.5">
                                                        <span class="inline-flex size-5 rounded-full bg-indigo-600 text-white font-bold text-[9px] items-center justify-center">JD</span>
                                                        <span class="inline-flex size-5 rounded-full bg-emerald-600 text-white font-bold text-[9px] items-center justify-center -ml-1.5">AS</span>
                                                    </td>
                                                    <td class="p-2.5 text-muted-foreground">Oct 24, 2026</td>
                                                    <td class="p-2.5">
                                                        <div class="w-16 h-1.5 bg-muted rounded-full overflow-hidden">
                                                            <div class="h-full bg-primary rounded-full" style="width: 75%"></div>
                                                        </div>
                                                    </td>
                                                    <td class="p-2.5">
                                                        <span class="rounded-full bg-emerald-500/10 text-emerald-400 text-[10px] font-semibold px-2 py-0.5">In Progress</span>
                                                    </td>
                                                    <td class="p-2.5 text-right text-muted-foreground">•••</td>
                                                </tr>
                                                <tr class="hover:bg-muted/20">
                                                    <td class="p-2.5 font-bold text-foreground">Sample Deliverable Alpha</td>
                                                    <td class="p-2.5 text-muted-foreground">Global Partner B</td>
                                                    <td class="p-2.5">
                                                        <span class="inline-flex size-5 rounded-full bg-amber-600 text-white font-bold text-[9px] items-center justify-center">RK</span>
                                                    </td>
                                                    <td class="p-2.5 text-muted-foreground">Nov 02, 2026</td>
                                                    <td class="p-2.5">
                                                        <div class="w-16 h-1.5 bg-muted rounded-full overflow-hidden">
                                                            <div class="h-full bg-amber-500 rounded-full" style="width: 40%"></div>
                                                        </div>
                                                    </td>
                                                    <td class="p-2.5">
                                                        <span class="rounded-full bg-amber-500/10 text-amber-400 text-[10px] font-semibold px-2 py-0.5">Pending</span>
                                                    </td>
                                                    <td class="p-2.5 text-right text-muted-foreground">•••</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- VIEW 2: SHOW DETAIL WORKSPACE (MULTI-TABS) -->
                            <div x-show="selectedUiPage === 'show'" class="space-y-3">
                                <!-- Entity Header Ribbon -->
                                <div class="rounded-xl border border-border/80 bg-muted/20 p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="size-10 rounded-lg bg-primary/20 flex items-center justify-center text-primary font-extrabold text-base">
                                            <span x-text="getSelectedUiSliceObj()?.name?.charAt(0)"></span>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <h4 class="text-sm font-bold text-foreground" x-text="getSelectedUiSliceObj()?.name + ' Aggregate Root #101'"></h4>
                                                <span class="rounded-full bg-emerald-500/10 text-emerald-400 text-[10px] font-bold px-2 py-0.5">Active</span>
                                            </div>
                                            <div class="text-[11px] text-muted-foreground">Root Table: <code class="font-mono text-primary" x-text="getSelectedUiSliceObj()?.root_table"></code></div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <template x-for="act in (getSelectedUiSliceObj()?.pages?.show?.header?.actions || [])" :key="act.label">
                                            <span class="rounded-lg border border-border bg-card px-2.5 py-1 text-xs font-semibold text-foreground flex items-center gap-1 shadow-xs cursor-pointer">
                                                <span x-text="act.label"></span>
                                            </span>
                                        </template>
                                    </div>
                                </div>

                                <!-- Horizontal Sub-Tabs Switcher -->
                                <div class="flex items-center gap-1 border-b border-border overflow-x-auto pb-1 text-xs">
                                    <template x-for="tab in (getSelectedUiSliceObj()?.pages?.show?.sub_tabs || [])" :key="tab.id">
                                        <button type="button" @click="selectedUiSubTab = tab.id"
                                                :class="selectedUiSubTab === tab.id ? 'border-primary text-primary font-bold bg-primary/10' : 'border-transparent text-muted-foreground hover:text-foreground'"
                                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 transition cursor-pointer whitespace-nowrap">
                                            <span x-text="tab.label"></span>
                                            <span x-show="tab.badge" class="rounded-full bg-primary/20 text-primary text-[9px] font-bold px-1.5 py-0.2" x-text="tab.badge"></span>
                                        </button>
                                    </template>
                                </div>

                                <!-- Active Sub-Tab Panel Details -->
                                <div class="rounded-xl border border-border/80 bg-slate-950 p-4 font-sans text-xs text-slate-200 min-h-[140px] space-y-2">
                                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                        <span class="font-bold text-indigo-400 uppercase tracking-wider text-[11px]" x-text="'Embedded Aggregate Component: ' + (getSelectedUiSliceObj()?.pages?.show?.sub_tabs?.find(t => t.id === selectedUiSubTab)?.component || 'overview-panel')"></span>
                                        <span class="rounded bg-slate-800 px-2 py-0.5 text-[10px] font-mono text-slate-400" x-text="'Child Table: ' + (getSelectedUiSliceObj()?.pages?.show?.sub_tabs?.find(t => t.id === selectedUiSubTab)?.child_table || getSelectedUiSliceObj()?.root_table)"></span>
                                    </div>
                                    <p class="text-slate-300 leading-relaxed text-[11px]" x-text="getSelectedUiSliceObj()?.pages?.show?.sub_tabs?.find(t => t.id === selectedUiSubTab)?.description || 'Primary view details and operational specifications.'"></p>
                                    
                                    <div class="rounded-lg border border-slate-800/80 bg-slate-900/60 p-2.5 mt-2 text-[11px] text-slate-400">
                                        💡 <span class="text-slate-300 font-semibold">Alpine.js Aggregate Rendering:</span> Child records stream asynchronously or via embedded Blade components with isolated pagination and quick-actions without triggering a full page reload.
                                    </div>
                                </div>
                            </div>

                                                        <!-- VIEW 3: REACTIVE FORMS (CREATE / EDIT) -->
                            <div x-show="selectedUiPage === 'forms'" class="space-y-3">
                                <!-- Top Bar: Form Layout Controls -->
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-muted/30 p-2.5 rounded-xl border border-border/60">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-foreground">Custom Section Cards & Responsive Field Layout</span>
                                        <span class="rounded bg-indigo-500/10 text-indigo-400 text-[10px] font-mono px-2 py-0.5">Statamic & Filament Architecture</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" 
                                            @click="if(!getSelectedUiSliceObj().pages.forms.create.sections) getSelectedUiSliceObj().pages.forms.create.sections = []; getSelectedUiSliceObj().pages.forms.create.sections.push({ name: 'Additional Details', columns: 2, fields: ['notes'] })"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-primary/10 border border-primary/30 text-primary hover:bg-primary/20 text-xs font-semibold transition cursor-pointer">
                                            <span>+</span> Add Section Card
                                        </button>
                                    </div>
                                </div>

                                <!-- Section Cards List -->
                                <div class="space-y-3">
                                    <template x-for="(sec, sIdx) in (getSelectedUiSliceObj()?.pages?.forms?.create?.sections || [])" :key="sIdx">
                                        <div class="rounded-xl border border-border bg-card p-3.5 space-y-3 shadow-2xs">
                                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/40 pb-2">
                                                <div class="flex items-center gap-2 flex-1 min-w-[200px]">
                                                    <span class="text-xs">📁</span>
                                                    <input type="text" x-model="sec.name" class="font-bold text-xs text-foreground bg-transparent border-b border-transparent hover:border-input focus:border-primary focus:outline-none px-1">
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-[10px] text-muted-foreground font-semibold">Grid:</span>
                                                    <div class="inline-flex rounded-md border border-border bg-muted/40 p-0.5 text-[10px] font-mono">
                                                        <button type="button" @click="sec.columns = 1" :class="sec.columns === 1 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition">1 Col</button>
                                                        <button type="button" @click="sec.columns = 2" :class="(sec.columns === 2 || !sec.columns) ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition">2 Col</button>
                                                        <button type="button" @click="sec.columns = 3" :class="sec.columns === 3 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition">3 Col</button>
                                                        <button type="button" @click="sec.columns = 4" :class="sec.columns === 4 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1.5 py-0.5 transition">4 Col</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Field Pills Grid within Section -->
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
                                                <template x-for="(f, fIdx) in sec.fields" :key="fIdx">
                                                    <div class="p-2 rounded-lg border border-border/60 bg-muted/20 flex items-center justify-between gap-2">
                                                        <div class="min-w-0">
                                                            <div class="text-[11px] font-bold text-foreground truncate" x-text="typeof f === 'string' ? f.replace(/_/g, ' ') : (f.label || f.handle)"></div>
                                                            <div class="text-[9px] font-mono text-muted-foreground truncate" x-text="typeof f === 'string' ? f : f.handle"></div>
                                                        </div>
                                                        <div class="flex items-center gap-1.5 shrink-0">
                                                            <!-- Searchable Combobox Toggle Button -->
                                                            <button type="button" 
                                                                @click="
                                                                    if (typeof f === 'string') {
                                                                        sec.fields[fIdx] = { handle: f, searchable: true, span: 1 };
                                                                    } else {
                                                                        f.searchable = !f.searchable;
                                                                    }
                                                                "
                                                                :class="(typeof f === 'object' && f.searchable) ? 'bg-indigo-600 text-white font-bold' : 'bg-muted text-muted-foreground hover:text-foreground'"
                                                                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-mono border border-border cursor-pointer transition" 
                                                                title="Toggle Searchable Dropdown (x-ui.searchable-select)">
                                                                <span>🔍 Searchable</span>
                                                            </button>
                                                            <!-- Field Span Toggle -->
                                                            <div class="inline-flex rounded border border-border bg-muted/40 p-0.5 text-[9px] font-mono">
                                                                <button type="button" @click="if (typeof f === 'string') { sec.fields[fIdx] = { handle: f, span: 1, searchable: false }; } else { f.span = 1; }" :class="(typeof f === 'string' || !f.span || f.span === 1) ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground'" class="px-1 py-0.2">50%</button>
                                                                <button type="button" @click="if (typeof f === 'string') { sec.fields[fIdx] = { handle: f, span: 2, searchable: false }; } else { f.span = 2; }" :class="(typeof f === 'object' && f.span === 2) ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground'" class="px-1 py-0.2">100%</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- VIEW 4: WORKFLOW & TRANSITIONS -->
                            <div x-show="selectedUiPage === 'workflow'" class="space-y-3">
                                <div class="rounded-xl border border-border bg-muted/20 p-3 space-y-3">
                                    <div class="flex items-center justify-between border-b border-border/40 pb-2">
                                        <h5 class="text-xs font-bold text-foreground">Finite State Machine Definition</h5>
                                        <span class="text-[10px] font-mono text-muted-foreground" x-text="'State Column: ' + (getSelectedUiSliceObj()?.pages?.workflow?.state_column || 'status')"></span>
                                    </div>

                                    <!-- States Sequence -->
                                    <div class="flex flex-wrap items-center gap-2">
                                        <template x-for="(st, stIdx) in (getSelectedUiSliceObj()?.pages?.workflow?.states || [])" :key="st.id">
                                            <div class="flex items-center gap-2">
                                                <span class="rounded-full border border-border bg-card px-3 py-1 text-xs font-bold flex items-center gap-1.5 shadow-xs"
                                                      :class="st.color === 'emerald' ? 'text-emerald-400 border-emerald-500/30' : (st.color === 'blue' ? 'text-blue-400 border-blue-500/30' : (st.color === 'amber' ? 'text-amber-400 border-amber-500/30' : 'text-slate-300'))">
                                                    <span class="size-1.5 rounded-full bg-current"></span>
                                                    <span x-text="st.label"></span>
                                                </span>
                                                <span x-show="stIdx < (getSelectedUiSliceObj()?.pages?.workflow?.states?.length - 1)" class="text-slate-600 text-xs">→</span>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Allowed Transitions Table -->
                                    <div class="rounded-lg border border-border overflow-hidden text-xs mt-3">
                                        <table class="w-full text-left">
                                            <thead class="bg-muted/50 text-[10px] uppercase font-bold text-muted-foreground border-b border-border">
                                                <tr>
                                                    <th class="p-2">From State</th>
                                                    <th class="p-2">To State</th>
                                                    <th class="p-2">Trigger Action</th>
                                                    <th class="p-2">Guard Condition</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-border">
                                                <template x-for="(tr, tIdx) in (getSelectedUiSliceObj()?.pages?.workflow?.transitions || [])" :key="tIdx">
                                                    <tr class="hover:bg-muted/20">
                                                        <td class="p-2 font-mono text-slate-300 font-semibold" x-text="tr.from"></td>
                                                        <td class="p-2 font-mono text-emerald-400 font-bold" x-text="tr.to"></td>
                                                        <td class="p-2 text-foreground font-semibold" x-text="tr.trigger"></td>
                                                        <td class="p-2 text-[10px] text-muted-foreground" x-text="tr.requires || (tr.prompt_reason ? 'Prompt Reason' : 'None')"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- AI Scaffolding Command Bar -->
                            <div class="rounded-xl border border-indigo-500/30 bg-indigo-950/30 p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="text-indigo-400 font-bold">🤖 AI / CLI Scaffold Command:</span>
                                    <code class="rounded bg-black/40 px-2 py-0.5 font-mono text-[11px] text-indigo-300" x-text="'php artisan slice:page ' + getSelectedUiSliceObj()?.name + ' --view=' + selectedUiPage"></code>
                                </div>
                                <button type="button" @click="navigator.clipboard.writeText('php artisan slice:page ' + getSelectedUiSliceObj()?.name + ' --view=' + selectedUiPage); alert('Command copied to clipboard!')" class="rounded border border-indigo-500/40 bg-indigo-600/20 px-2.5 py-1 text-[11px] font-semibold text-indigo-300 hover:bg-indigo-600/40 transition cursor-pointer shrink-0">
                                    Copy CLI Command
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Tab 4: JSON Architecture Plan -->
        <div x-show="portActiveTab === 'json'" class="space-y-3">
            <div class="flex items-center justify-between text-xs text-muted-foreground">
                <span>Standard LaraSlice VSA Architecture Plan (<code class="text-primary font-mono text-[10px]">porting-architecture-plan.json</code>)</span>
                <div class="flex items-center gap-2">
                    <button type="button" @click="navigator.clipboard.writeText(portPlanJsonText); alert('Architecture Plan JSON copied to clipboard!')" class="rounded border border-border px-2.5 py-1 text-[11px] font-semibold text-foreground hover:bg-accent transition cursor-pointer flex items-center gap-1">
                        <span>📋 Copy JSON</span>
                    </button>
                    <button type="button" @click="downloadPlanJson()" class="rounded border border-border px-2.5 py-1 text-[11px] font-semibold text-foreground hover:bg-accent transition cursor-pointer flex items-center gap-1">
                        <span>💾 Download JSON</span>
                    </button>
                </div>
            </div>
            <textarea x-model="portPlanJsonText" rows="16" spellcheck="false" class="w-full rounded-xl border border-input bg-slate-950 p-4 font-mono text-[11px] leading-5 text-emerald-300 shadow-inner focus:outline-none focus:ring-1 focus:ring-primary"></textarea>
        </div>

        <!-- Tab 5: ETL Data Pipeline -->
        <div x-show="portActiveTab === 'etl'" class="space-y-3">
            <div x-show="!portPlanData" class="rounded-xl border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                <p class="font-semibold text-foreground text-sm">No ETL preview yet</p>
                <p class="mt-1">Choose a preset or source path and click <span class="font-bold">🔍 Analyze</span> to detect record counts and build the migration pipeline.</p>
            </div>
            <div x-show="portPlanData" class="flex items-center justify-between text-xs text-muted-foreground">
                <div>
                    <span>Streaming relational records from legacy <code class="text-primary font-mono text-[11px]" x-text="portPlanData.domain || 'legacy'"></code> database</span>
                    <span class="text-[10px] text-muted-foreground block">Foreign keys toggled, auto-increments preserved, chunked in batches of 250</span>
                </div>
                <span class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-bold text-emerald-500" x-text="(portPlanData.summary?.total_records_detected || 0).toLocaleString() + ' Records Detected'"></span>
            </div>
            <div class="max-h-[380px] overflow-y-auto rounded-xl border border-border text-xs">
                <table class="w-full text-left">
                    <thead class="bg-muted/50 text-[10px] uppercase font-bold text-muted-foreground sticky top-0 backdrop-blur-md">
                        <tr>
                            <th class="p-2.5">Table Name</th>
                            <th class="p-2.5 text-right">Row Count</th>
                            <th class="p-2.5">ETL Mode</th>
                            <th class="p-2.5">Integrity Guard</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template x-for="(row, rIdx) in (portPlanData?.etl_data_pipeline?.top_tables_to_migrate || [])" :key="rIdx">
                            <tr class="hover:bg-muted/20">
                                <td class="p-2.5 font-mono text-foreground font-semibold" x-text="row.table"></td>
                                <td class="p-2.5 text-right font-mono font-bold text-emerald-400" x-text="row.rows"></td>
                                <td class="p-2.5 text-[10px] text-muted-foreground">Direct Streaming (Chunked)</td>
                                <td class="p-2.5 text-[10px] text-indigo-400 font-semibold">FK Checks Guarded</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab: Data Sources (Seeder vs Real Data) -->
        <div x-show="portActiveTab === 'datasources'" class="space-y-3">
            <div x-show="!portPlanData" class="rounded-xl border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                <p class="font-semibold text-foreground text-sm">No data sources analyzed yet</p>
                <p class="mt-1">Choose a preset or source path and click <span class="font-bold">🔍 Analyze</span> to detect seeders and database availability.</p>
            </div>
            <div x-show="portPlanData" class="space-y-3">
                <div class="rounded-xl border border-border p-4 bg-muted/20">
                    <p class="font-bold text-sm text-foreground mb-1">💡 Recommendation</p>
                    <p class="text-xs text-muted-foreground" x-text="portPlanData?.data_sources?.recommendation || 'Analyze to get recommendation.'"></p>
                </div>

                <!-- Real Data Option -->
                <div class="rounded-xl border border-border p-4">
                    <div class="flex items-center justify-between mb-2">
                        <p class="font-bold text-sm text-foreground">🗄️ Real Data (Live Database)</p>
                        <span x-show="portPlanData?.data_sources?.real_data?.available" class="rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-bold text-emerald-500">Available</span>
                        <span x-show="!portPlanData?.data_sources?.real_data?.available" class="rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs font-bold text-amber-500">Not configured</span>
                    </div>
                    <p class="text-xs text-muted-foreground" x-text="portPlanData?.data_sources?.real_data?.note || ''"></p>
                    <p class="text-[11px] text-muted-foreground mt-1">✅ Always up-to-date &nbsp;✅ Real relationships &nbsp;✅ Production data volume</p>
                </div>

                <!-- Seeders Option -->
                <div class="rounded-xl border border-border p-4">
                    <div class="flex items-center justify-between mb-2">
                        <p class="font-bold text-sm text-foreground">🌱 Seeders (Source Seed Files)</p>
                        <span class="rounded-full bg-indigo-500/10 px-2.5 py-0.5 text-xs font-bold text-indigo-400" x-text="(portPlanData?.data_sources?.seeders?.length || 0) + ' found'"></span>
                    </div>
                    <p class="text-[11px] text-amber-400 mb-2">⚠️ Seeders may be outdated. Database is always current.</p>
                    <div class="max-h-[200px] overflow-y-auto space-y-1.5">
                        <template x-for="(seeder, sIdx) in (portPlanData?.data_sources?.seeders || [])" :key="sIdx">
                            <div class="rounded-lg border border-border p-2.5 bg-muted/10">
                                <div class="flex items-center justify-between">
                                    <span class="font-mono text-xs font-bold text-foreground" x-text="seeder.class"></span>
                                    <span x-show="seeder.is_reference_data" class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-500">Reference data</span>
                                    <span x-show="!seeder.is_reference_data" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-500">Review needed</span>
                                </div>
                                <div class="text-[11px] text-muted-foreground mt-1">
                                    Tables: <span class="font-mono" x-text="(seeder.tables || []).join(', ')"></span>
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    Slices: <span class="font-semibold" x-text="(seeder.slices || []).join(', ') || 'Unmapped'"></span>
                                </div>
                                <div class="text-[11px] text-indigo-400 italic" x-text="seeder.recommendation"></div>
                            </div>
                        </template>
                        <div x-show="!(portPlanData?.data_sources?.seeders?.length)" class="text-xs text-muted-foreground text-center py-2">No seeders detected in source.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 6: Execution Terminal -->
        <div x-show="portActiveTab === 'log'" class="space-y-2">
            <div class="rounded-xl border border-slate-800 bg-slate-950 p-4 font-mono text-[11px] leading-6 text-emerald-400 shadow-inner h-[380px] overflow-y-auto space-y-1">
                <template x-for="(log, lIdx) in portExecutionLogs" :key="lIdx">
                    <div x-text="log"></div>
                </template>
                <div x-show="portExecuting" class="flex items-center gap-2 text-indigo-400 animate-pulse pt-2">
                    <span>Processing porting pipeline...</span>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-border/40 pt-4">
            <div class="flex items-center gap-2">
                <input type="checkbox" id="portImportDataCheck" x-model="portImportData" class="rounded border-input text-primary focus:ring-primary size-4">
                <label for="portImportDataCheck" class="text-xs text-foreground font-medium cursor-pointer">
                    Stream existing relational records
                    <span x-show="portPlanData" class="text-emerald-400 font-bold" x-text="'(' + (portPlanData.summary?.total_records_detected || 0).toLocaleString() + ' rows via ETL Data Port Engine)'"></span>
                    <span x-show="!portPlanData" class="text-muted-foreground">(via ETL Data Port Engine)</span>
                </label>
            </div>
            <div class="flex items-center gap-2.5">
                <button type="button" @click="loadPortedToStudio()" :disabled="!portPlanData && !portInspectionData" class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/50 bg-emerald-500/10 px-4 py-2.5 text-xs font-bold text-emerald-400 hover:bg-emerald-500/20 transition cursor-pointer shadow-xs disabled:opacity-50" title="Load entire ported suite into Studio to inspect and edit models & pages">
                    <span>📥 Load into Studio</span>
                </button>
                <button type="button" @click="showPortModal = false" class="rounded-lg border border-border px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground cursor-pointer">
                    Close
                </button>
                <button type="button" @click="executePortPlan()" :disabled="portExecuting || !portPlanData" class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-xs font-bold text-primary-foreground hover:bg-primary/90 transition shadow-md disabled:opacity-50 cursor-pointer">
                    <span x-show="!portExecuting">🚀 Execute 100% Usable Porting</span>
                    <span x-show="portExecuting">Porting in Progress...</span>
                </button>
            </div>
        </div>
    </div>
</div>
