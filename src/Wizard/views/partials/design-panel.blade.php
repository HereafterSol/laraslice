@php
    // The installed-slice catalog ships ONCE as compact data;
    // the slice picker and the relation target list render
    // their options from it client-side (the server used to
    // repeat ~160 <option> elements in two places per load).
    // 's' is the server-computed Str::snake of the name — the
    // relation values must match it exactly, so the client
    // never re-derives it.
    $installedCatalog = [
        'current' => $currentSlice ?? '',
        'groups' => collect($installedSlices ?? [])
            ->groupBy(fn($s) => !empty($s['domain']) ? $s['domain'] : 'General')
            ->map(fn($items, $domain) => [
                'label' => '📁 '.$domain,
                'slices' => collect($items)->map(fn($s) => [
                    'n' => $s['name'],
                    'y' => !empty($s['has_yaml']),
                    's' => Str::snake($s['name']),
                ])->values()->all(),
            ])->values()->all(),
    ];
@endphp
<div class="w-full max-w-[100vw] overflow-x-hidden space-y-6" x-data="blueprintStudio({
    initialSource: @js(old('source', $source ?? '')),
    initialFormat: @js(old('format', $format ?? 'yaml')),
    initialBlueprint: @js($blueprintData ?? null),
    csrfToken: '{{ csrf_token() }}',
    introspectUrl: '{{ route('laraslice.wizard.blueprint.introspect') }}',
    studioUrl: '{{ route('laraslice.wizard.blueprint') }}',
    allSlicesJson: @js($allSlicesJson ?? null),
    activeSliceIdx: @js($activeSliceIdx ?? 0),
    initialPlanScope: @js($planScope ?? null),
    initialPortPlan: @js($portPlan ?? null),
    installedCatalog: @js($installedCatalog),
    introspectTables: @js($dbTables ?? [])
})">

    <header class="sticky top-0 z-30 -mx-1 px-1 py-2 bg-background/95 backdrop-blur flex flex-wrap items-center gap-2.5 border-b border-border">
        <!-- Controls: single Start-from picker + overflow for secondary actions -->
        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Unified Start from… picker (Presets + Examples) -->
            <div class="relative">
                <select @change="handleStartFrom($event.target.value); $event.target.value = '';"
                        class="h-9 rounded-lg border border-indigo-500/40 bg-indigo-500/10 px-3 text-xs font-semibold text-indigo-400 shadow-sm hover:bg-indigo-500/20 focus:outline-none cursor-pointer max-w-[220px] sm:max-w-none">
                    <option value="" disabled selected>Start from…</option>
                    <optgroup label="⚡ Presets / Archetypes">
                        <option value="preset:adjudication">⚖️ Dispute Adjudication (7 Slices)</option>
                        <option value="preset:legal_dispute_adjudication">⚖️ Dispute Adjudication Standard Suite</option>
                        <option value="preset:erp">🏢 ERP Enterprise Operations</option>
                        <option value="preset:healthcare">🏥 Healthcare & Clinical EMR</option>
                        <option value="preset:ecommerce">🛒 E-Commerce & Retail</option>
                        <option value="preset:edtech">🎓 EdTech & Learning (LMS)</option>
                        <option value="preset:fintech">💳 FinTech & Core Banking</option>
                    </optgroup>
                    <template x-if="savedPresets && savedPresets.length > 0">
                        <optgroup label="💾 Saved Custom Presets">
                            <template x-for="p in savedPresets" :key="p.slug">
                                <option :value="'preset:saved:' + p.slug" x-text="'💾 ' + (p.name || p.slug)"></option>
                            </template>
                        </optgroup>
                    </template>
                    <template x-if="libraryItems && libraryItems.length > 0">
                        <optgroup label="📚 Team Library">
                            <template x-for="item in libraryItems" :key="item.slug">
                                <option :value="'library:' + item.slug" x-text="'📚 ' + item.name + (item.slices ? ' (' + item.slices + ' slices)' : '')"></option>
                            </template>
                        </optgroup>
                    </template>
                    <optgroup label="🧪 Examples (v0.1)">
                        <option value="example:kitchen-sink">🧪 Kitchen Sink (all formatters)</option>
                        <option value="example:crm-companies">💼 CRM · Companies</option>
                        <option value="example:crm-contacts">💼 CRM · Contacts</option>
                        <option value="example:crm-deals">💼 CRM · Deals</option>
                        <option value="example:hr-employees">👥 HR · Employees</option>
                        <option value="example:hr-leave-requests">👥 HR · Leave</option>
                        <option value="example:shop-products-example">🛒 Shop · Products</option>
                        <option value="example:shop-orders-example">🛒 Shop · Orders</option>
                    </optgroup>
                    <optgroup label="📄 Single Slice Blueprints">
                        <option value="example:service-desk">🎫 Service Desk</option>
                        <option value="example:hr-module">👥 HR & Staff</option>
                        <option value="example:blog">📝 Articles & Comments</option>
                        <option value="example:shop">🛍️ Products & Variants</option>
                        <option value="example:shop-orders">📦 Orders & Items</option>
                        <option value="example:shop-categories">🏷️ Shop Categories</option>
                        <option value="example:crm">🏢 Companies & Contacts</option>
                    </optgroup>
                </select>
            </div>

            <!-- Installed Domains & Slices (options render
                 client-side from the one shipped catalog) -->
            @if (!empty($installedSlices))
                <div class="relative">
                    <select @change="loadSlice($event.target.value)" class="h-9 rounded-lg border border-input bg-card px-3 text-xs font-medium text-foreground shadow-sm hover:bg-accent/50 focus:outline-none">
                        <option value="" disabled :selected="!currentSliceName">Installed Domain / Slice...</option>
                        <template x-for="g in catalogGroups()" :key="g.label">
                            <optgroup :label="g.label">
                                <template x-for="s in g.slices" :key="s.n">
                                    <option :value="s.n" :selected="currentSliceName === s.n" x-text="s.n + (s.y ? ' (yaml)' : '')"></option>
                                </template>
                            </optgroup>
                        </template>
                    </select>
                </div>
            @endif

            <!-- + New Slice Modal Trigger -->
            <button type="button" @click="showNewSliceModal = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-primary/40 bg-primary/10 px-3 text-xs font-semibold text-primary hover:bg-primary/20 transition cursor-pointer" title="Create a new vertical slice blueprint">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>+ New Slice</span>
            </button>

            <!-- Port Legacy App Button -->
            <a href="/laraslice/wizard/porting" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-indigo-500/40 bg-indigo-500/10 px-3 text-xs font-semibold text-indigo-400 hover:bg-indigo-500/20 transition cursor-pointer" title="Open full Porting Studio workspace">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                <span>🚀 Porting Studio</span>
            </a>

            <!-- Load Ported Suite into Blueprint Studio Trigger -->
            <button type="button" @click="loadPortedToStudio()" x-show="portPlanData || portInspectionData || hasStoredPortPlan()" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground hover:bg-accent transition cursor-pointer" title="Load entire ported suite directly into Blueprint Studio canvas">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                <span>📥 Load Ported Suite (<span x-text="(portPlanData?.studio_slices?.length || portPlanData?.summary?.total_canonical_slices || getStoredSliceCount() || 7) + ' Slices: ' + (portPlanData?.domain || getStoredDomain() || 'Suite')"></span>)</span>
            </button>

            <!-- Mode Switcher -->
            <div class="inline-flex rounded-lg border border-input bg-muted/50 p-0.5">
                <button type="button" @click="mode = 'visual'" :class="mode === 'visual' ? 'bg-background text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'" class="rounded-md px-3 py-1.5 text-xs transition">
                    🎨 Visual
                </button>
                <button type="button" @click="mode = 'preview'" :class="mode === 'preview' ? 'bg-background text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'" class="rounded-md px-3 py-1.5 text-xs transition">
                    👁️ Preview
                </button>
                <button type="button" @click="mode = 'split'" :class="mode === 'split' ? 'bg-background text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'" class="rounded-md px-3 py-1.5 text-xs transition">
                    ⚡ Split
                </button>
                <button type="button" @click="mode = 'yaml'" :class="mode === 'yaml' ? 'bg-background text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'" class="rounded-md px-3 py-1.5 text-xs transition">
                    📝 YAML
                </button>
            </div>

            <!-- Validation chip: live js-yaml parse + shape check of the current
                 source, PLUS the engine's own verdict (BlueprintValidator via
                 the validate endpoint — the same code slice:blueprint:plan
                 runs). The chip never claims plain "valid" while the engine
                 rejects the blueprint (Day-2 drill F8). -->
            <span x-show="source && source.trim()" x-cloak class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-bold"
                  :class="validityStatus.ok ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600' : 'border-rose-500/30 bg-rose-500/10 text-rose-500'"
                  :title="validityStatus.message">
                <span class="size-1.5 rounded-full" :class="validityStatus.ok ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                <span x-text="validityStatus.label"></span>
            </span>

            <!-- Unsaved-draft badge: visual edits change the
                 draft only until the slice is generated/applied -->
            <span x-show="blueprintDirty" x-cloak class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-[11px] font-bold text-amber-600" title="Your edits are a draft. They reach the installed slice only when it is generated or applied.">
                <span class="size-1.5 rounded-full bg-amber-500"></span>
                Unsaved draft
            </span>

            <!-- Secondary actions: inline on md+, overflow menu on small screens -->
            <div class="relative" x-data="{ headerOverflow: false }">
                <!-- Mobile overflow trigger -->
                <button type="button" @click="headerOverflow = !headerOverflow" class="md:hidden inline-flex h-9 items-center gap-1 rounded-lg border border-border bg-card px-2.5 text-xs font-semibold text-foreground hover:bg-accent" aria-label="More actions">
                    ⋯
                </button>
                <!-- Mobile menu -->
                <div x-show="headerOverflow" x-cloak @click.outside="headerOverflow = false"
                     class="md:hidden absolute right-0 top-full mt-1 z-40 min-w-[180px] rounded-lg border border-border bg-card shadow-lg py-1 text-xs">
                    <button type="button" @click="showImportYamlModal = true; headerOverflow = false" class="w-full text-left px-3 py-2 hover:bg-accent">📥 Import YAML</button>
                    <button type="button" @click="showIntrospectModal = true; headerOverflow = false" class="w-full text-left px-3 py-2 hover:bg-accent">✨ Add from DB</button>
                    <form method="POST" action="{{ route('laraslice.wizard.blueprint.migrate') }}" class="block">
                        @csrf
                        <button type="submit" class="w-full text-left px-3 py-2 hover:bg-accent text-amber-600 dark:text-amber-400">⚡ Run Migrations</button>
                    </form>
                </div>
                <!-- Desktop inline -->
                <div class="hidden md:flex items-center gap-2.5">
                    <button type="button" @click="showImportYamlModal = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground hover:bg-accent transition shadow-xs cursor-pointer" title="Import or paste full Domain or Slice YAML">
                        <svg class="size-3.5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        <span>Import YAML</span>
                    </button>
                    <button type="button" @click="showIntrospectModal = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/10 px-3 text-xs font-semibold text-primary hover:bg-primary/20 transition">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7h16m-8 4v6m-3-3h6"/></svg>
                        Add from DB
                    </button>
                    <form method="POST" action="{{ route('laraslice.wizard.blueprint.migrate') }}" class="inline">
                        @csrf
                        <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 text-xs font-semibold text-amber-600 hover:bg-amber-500/20 dark:text-amber-400 transition" title="Run php artisan migrate immediately">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Run Migrations
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    @if (session('error') || isset($error))
        <div role="alert" class="rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <span>{{ session('error') ?? $error }}</span>
            </div>
        </div>
    @endif

    @if (session('success') || isset($success))
        <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-800 dark:text-emerald-300 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <svg class="size-5 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>{{ session('success') ?? $success }}</span>
            </div>
            @if (isset($sliceUrl))
                <div class="flex items-center gap-2 shrink-0">
                    <a href="{{ $sliceUrl }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-emerald-500 transition">
                        Open Generated Slice →
                    </a>
                </div>
            @endif
        </div>
    @endif

    <!-- Hidden Standalone Plan Form (avoiding invalid HTML5 nested forms) -->
    <form method="POST" action="{{ route('laraslice.wizard.blueprint.plan') }}" id="plan-form" @submit="saveActiveSliceState(); syncToYaml()">
        @csrf
        <input type="hidden" name="format" :value="format">
        <input type="hidden" name="source" :value="source">
        <input type="hidden" name="slices_json" :value="JSON.stringify(slices)">
        <input type="hidden" name="active_slice_idx" :value="activeSliceIdx">
        <input type="hidden" name="plan_scope" :value="planScope">
    </form>

    <!-- Domain Suite Multi-Slice Tabs (When slices are configured) -->
    <div x-show="slices.length > 0" class="rounded-2xl border border-primary/30 bg-primary/5 p-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-primary uppercase tracking-wider">📦 Domain Suite Slices</span>
                <span class="rounded-full bg-primary/20 px-2 py-0.5 text-[10px] font-bold text-primary" x-text="slices.length + (slices.length === 1 ? ' Slice' : ' Slices') + (blueprint.domain ? ' in [' + blueprint.domain + ']' : '')"></span>
            </div>
            <p class="text-[11px] text-muted-foreground">Select a slice below to switch and design its independent models, fields & YAML on the same page:</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <template x-for="(sl, sIdx) in slices" :key="sIdx">
                <div class="inline-flex items-center rounded-lg border transition shadow-xs"
                     :class="activeSliceIdx === sIdx ? 'bg-primary border-primary text-primary-foreground font-bold ring-2 ring-primary/40' : 'bg-card border-border text-foreground hover:bg-muted'">
                    <button type="button" @click="switchSlice(sIdx)"
                            class="px-3.5 py-2 text-xs flex items-center gap-2 cursor-pointer">
                        <span class="size-2 rounded-full" :class="activeSliceIdx === sIdx ? 'bg-white' : 'bg-primary'"></span>
                        <span x-text="sl.name || ('Slice ' + (sIdx + 1))"></span>
                        <span class="rounded-md bg-black/10 dark:bg-white/10 px-1.5 py-0.5 text-[10px]" x-text="getStudioSliceFieldsCount(sl) + ' fields'"></span>
                    </button>
                    <template x-if="slices.length > 1">
                        <button type="button" @click.stop="removeSlice(sIdx)" class="pr-2.5 pl-0.5 py-2 text-xs opacity-60 hover:opacity-100 hover:text-destructive cursor-pointer" title="Remove slice">
                            ×
                        </button>
                    </template>
                </div>
            </template>
            <button type="button" @click="showNewSliceModal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-primary/50 bg-primary/10 hover:bg-primary/20 text-primary px-3 py-2 text-xs font-bold transition shadow-xs cursor-pointer" title="Add another slice to this domain suite">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>+ Add Slice</span>
            </button>
        </div>
    </div>

    @if (!empty($currentSlice))
        <!-- Role signpost: Design drafts; Manage edits the
             installed slice day-to-day. Shown exactly when an
             installed slice is loaded, so nobody loses an
             edit believing Design had saved it. -->
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-xs text-foreground">
            <p class="min-w-0 flex-1 leading-relaxed">
                <strong>Installed slice — {{ $currentSlice }}.</strong>
                Design <em>drafts</em> blueprint changes; they take effect when the slice is generated or applied.
                For day-to-day edits (fields, UI, workflow, navigation), Manage is the editor of record.
            </p>
            <button type="button" @click="window.__oneStudioSetMode && window.__oneStudioSetMode('manage')" class="shrink-0 rounded-lg bg-primary px-3 py-1.5 font-bold text-primary-foreground hover:opacity-90">Open in Manage</button>
        </div>
    @endif

    <!-- Saved-draft banner: a previous Design session left a
         draft for this blueprint — offer it, never impose it. -->
    <div x-show="draftAvailable" x-cloak class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-violet-500/30 bg-violet-500/10 px-4 py-3 text-xs text-foreground">
        <p class="min-w-0 flex-1 leading-relaxed">
            <strong>Saved draft available</strong> (<span x-text="draftAvailable ? draftAvailable.saved_at : ''"></span>) — an earlier Design session for this blueprint was saved as a draft and never generated.
        </p>
        <button type="button" @click="loadDraft()" class="shrink-0 rounded-lg bg-violet-600 px-3 py-1.5 font-bold text-white hover:opacity-90">Load draft</button>
        <button type="button" @click="draftAvailable = null" class="shrink-0 rounded-lg border border-border px-3 py-1.5 font-semibold text-muted-foreground hover:text-foreground">Dismiss</button>
    </div>

    <!-- Section quick-nav: Design is one long workspace —
         jump, don't scroll. -->
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="px-1 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Jump to:</span>
        <template x-for="sec in [{ id: 'design-sec-definition', label: 'Definition' }, { id: 'design-sec-rbac', label: 'Access' }, { id: 'design-sec-models', label: 'Models & Fields' }, { id: 'design-sec-relations', label: 'Relations' }, { id: 'design-sec-workflow', label: 'Workflow' }, { id: 'design-sec-roles', label: 'Roles' }, { id: 'design-sec-uiplan', label: 'UI Plan' }]" :key="sec.id">
            <button type="button" x-show="chipShown(sec)" @click="document.getElementById(sec.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                    class="rounded-full border border-border bg-card px-3 py-1 text-[11px] font-semibold text-muted-foreground transition hover:text-foreground hover:border-primary/50 cursor-pointer"
                    x-text="sec.label"></button>
        </template>
    </div>

    <!-- Start-here checklist: a new blueprint opens on the
         full machinery; this gives a first-run path in plain
         language and retires itself once the basics exist. -->
    <div x-show="!((blueprint.name || '').trim() && blueprint.models.length > 0 && (blueprint.models[0].handle || '').trim() && blueprint.models.reduce((n, m) => n + (m.fields ? m.fields.length : 0), 0) >= 2)" class="rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-5 space-y-3">
        <h3 class="text-sm font-bold text-foreground">👋 Start here — your first slice in four steps</h3>
        <ol class="space-y-2 text-xs text-foreground">
            <li class="flex items-center gap-2">
                <span x-text="(blueprint.name || '').trim() ? '✅' : '⬜'"></span>
                <span><strong>Name your slice</strong> below (e.g. Invoices) — one thing your system manages.</span>
            </li>
            <li class="flex items-center gap-2">
                <span x-text="blueprint.models.length > 0 && (blueprint.models[0].handle || '').trim() ? '✅' : '⬜'"></span>
                <span><strong>Add a model</strong> — the main record the slice stores (e.g. Invoice).</span>
            </li>
            <li class="flex items-center gap-2">
                <span x-text="blueprint.models.reduce((n, m) => n + (m.fields ? m.fields.length : 0), 0) >= 2 ? '✅' : '⬜'"></span>
                <span><strong>Add its fields</strong> — the columns on that record (name, date, amount…).</span>
            </li>
            <li class="flex items-center gap-2">
                <span>⬜</span>
                <span><strong>Validate &amp; generate</strong> — check the plan, then create the working slice.</span>
                <button type="button" @click="mode = 'split'; $nextTick(() => document.getElementById('design-sec-yaml')?.scrollIntoView({ behavior: 'smooth' }))" class="ml-1 rounded-lg bg-emerald-600 px-2.5 py-1 font-bold text-white hover:opacity-90 cursor-pointer">Go to Validate &amp; Plan →</button>
            </li>
        </ol>
        <p class="text-[11px] text-muted-foreground">Everything else (relations, workflow, roles, page layout) can be refined afterwards — in Design, or later in Manage.</p>
    </div>

    <!-- Main Workspace Layout -->
    <div class="space-y-6">
        <div class="grid gap-6" :class="{
            'grid-cols-1': (mode === 'visual' || mode === 'preview') && !{{ isset($plan) ? 'true' : 'false' }},
            'xl:grid-cols-[1fr_440px]': (mode === 'visual' || mode === 'yaml' || mode === 'preview') && {{ isset($plan) ? 'true' : 'false' }},
            'grid-cols-1 xl:grid-cols-2': mode === 'split' && !{{ isset($plan) ? 'true' : 'false' }},
            'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_440px]': mode === 'split' && {{ isset($plan) ? 'true' : 'false' }}
        }">

            <!-- VISUAL DESIGNER PANEL -->
            <div x-show="mode === 'visual' || mode === 'split'" class="space-y-6 min-w-0">
                <!-- Slice Metadata Card -->
                <div class="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <h2 id="design-sec-definition" class="text-sm font-bold uppercase tracking-wider text-muted-foreground mb-4">Slice Definition</h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">Module Name</label>
                            <input type="text" x-model="blueprint.name" @input="syncToYaml(); updateActiveSliceName();" placeholder="e.g. Shop Products" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs text-foreground focus:ring-1 focus:ring-primary">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1" title="Used for routes, URLs, file paths, and namespace resolution">
                                Handle (Snake Case)
                                <span class="ml-1 cursor-help text-muted-foreground" title="Used for: URL routes (/shop_products), file paths (app/Slices/shop_products), and namespace resolution">ⓘ</span>
                            </label>
                            <input type="text" x-model="blueprint.handle" @input="syncToYaml(); updateActiveSliceName();" placeholder="e.g. shop_products" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs font-mono text-foreground focus:ring-1 focus:ring-primary">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1" title="Group this slice under a sidebar domain (e.g. E-Commerce, HR, CRM)">
                                Domain / Group
                                <span class="ml-1 cursor-help text-muted-foreground" title="Slices with the same Domain appear together under a collapsible sidebar dropdown">ⓘ</span>
                            </label>
                            <input type="text" x-model="blueprint.domain" @input="syncToYaml()" placeholder="e.g. E-Commerce" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs text-foreground focus:ring-1 focus:ring-primary">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">Version</label>
                            <input type="text" x-model="blueprint.version" @input="syncToYaml()" placeholder="1.0.0" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs font-mono text-foreground focus:ring-1 focus:ring-primary">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">Author</label>
                            <input type="text" x-model="blueprint.author" @input="syncToYaml()" placeholder="e.g. LaraSlice Team" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs text-foreground focus:ring-1 focus:ring-primary">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1">Description</label>
                            <input type="text" x-model="blueprint.description" @input="syncToYaml()" placeholder="Domain context and business purpose..." class="h-9 w-full rounded-lg border border-input bg-background px-3 text-xs text-foreground focus:ring-1 focus:ring-primary">
                        </div>
                    </div>
                </div>

                <!-- RBAC & Security Capabilities Card -->
                <div class="rounded-2xl border border-border bg-card p-5 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-border pb-3">
                        <div>
                            <h2 id="design-sec-rbac" class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                                <svg class="inline-block size-4 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
                                <span>RBAC & Security Capabilities</span>
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="(blueprint.permissions || []).length"></span>
                            </h2>
                            <p class="text-xs text-muted-foreground mt-0.5">Granular slice capabilities synced to database permissions and enforced by Gate & BaseSliceWebController</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="generateStandardPermissions()" class="inline-flex items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary/20 transition cursor-pointer">
                                <svg class="inline-block size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>
                                Generate CRUD
                            </button>
                        </div>
                    </div>

                    <!-- Permission Pills Roster -->
                    <div class="flex flex-wrap items-center gap-2">
                        <template x-for="(perm, pIdx) in (blueprint.permissions || [])" :key="pIdx">
                            <div class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-mono transition"
                                :class="perm.endsWith('.delete')
                                    ? 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400'
                                    : 'border-border bg-muted/50 text-foreground'">
                                <span class="size-1.5 rounded-full"
                                    :class="perm.endsWith('.delete') ? 'bg-rose-500' : 'bg-muted-foreground'"></span>
                                <span x-text="perm"></span>
                                <button type="button" @click="removePermission(pIdx)" class="ml-0.5 text-muted-foreground hover:text-destructive transition cursor-pointer" title="Remove capability">
                                    <svg class="inline-block size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                </button>
                            </div>
                        </template>

                        <div x-show="!(blueprint.permissions || []).length" class="text-xs text-muted-foreground italic py-1">
                            No capabilities declared. Click "Generate CRUD" or add custom capability slugs below.
                        </div>
                    </div>

                    <!-- Add Custom Capability Bar -->
                    <div class="flex items-center gap-2 pt-1">
                        <div class="relative flex-1 max-w-sm">
                            <input type="text" x-model="newPermInput" @keydown.enter.prevent="addCustomPermission()" placeholder="e.g. publish, export, approve" class="h-8 w-full rounded-lg border border-input bg-background px-3 text-xs font-mono text-foreground focus:ring-1 focus:ring-primary">
                        </div>
                        <button type="button" @click="addCustomPermission()" class="inline-flex h-8 items-center gap-1 rounded-lg border border-border bg-muted/40 px-3 text-xs font-semibold text-foreground hover:bg-muted transition cursor-pointer">
                            <svg class="inline-block size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            Add Capability
                        </button>
                    </div>
                </div>

                <!-- Models & Field Sets -->
                <div class="space-y-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 id="design-sec-models" class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                                Models & Tables
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="blueprint.models.length"></span>
                            </h2>
                            <div class="flex items-center gap-3 mt-1">
                                <button type="button" @click="collapseAllModels()" class="text-[11px] text-muted-foreground hover:text-foreground transition cursor-pointer">Collapse all</button>
                                <button type="button" @click="expandAllModels()" class="text-[11px] text-muted-foreground hover:text-foreground transition cursor-pointer">Expand all</button>
                            </div>
                        </div>
                        <button type="button" @click="addModel()" class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground hover:bg-muted transition shadow-sm">
                            ➕ Add Model
                        </button>
                    </div>

                    <template x-for="(model, mIdx) in blueprint.models" :key="mIdx">
                        <div class="rounded-2xl border border-border bg-card shadow-sm overflow-hidden transition-all">
                            <!-- Filament-style Collapsible Model Header -->
                            <div class="flex items-center justify-between gap-3 bg-muted/20 px-5 py-3 cursor-pointer select-none" @click="toggleModelCollapse(mIdx)">
                                <div class="flex items-center gap-3">
                                    <!-- Reorder + collapse controls -->
                                    <div class="flex items-center gap-1" @click.stop>
                                        <button type="button" @click="moveModelUp(mIdx); moveModelDown(mIdx); moveModelUp(mIdx)" title="Drag to reorder" class="p-0.5 text-muted-foreground/40 hover:text-foreground cursor-grab">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                                        </button>
                                        <button type="button" @click="moveModelUp(mIdx)" :disabled="mIdx === 0" class="p-0.5 text-muted-foreground hover:text-foreground disabled:opacity-20 transition" title="Move Up">↑</button>
                                        <button type="button" @click="moveModelDown(mIdx)" :disabled="mIdx === blueprint.models.length - 1" class="p-0.5 text-muted-foreground hover:text-foreground disabled:opacity-20 transition" title="Move Down">↓</button>
                                    </div>

                                    <!-- Model title -->
                                    <h3 class="font-bold text-sm text-foreground flex items-center gap-2" x-text="(model.handle || 'untitled').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())"></h3>

                                    <!-- Root badge -->
                                    <template x-if="model.root">
                                        <span class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-600 dark:text-emerald-400">
                                            👑 Root
                                        </span>
                                    </template>
                                    <template x-if="!model.root">
                                        <button type="button" @click.stop="setRootModel(mIdx)" class="rounded-full border border-dashed border-input px-2 py-0.5 text-[10px] font-medium text-muted-foreground hover:text-foreground hover:border-primary transition">
                                            Set as Root
                                        </button>
                                    </template>

                                    <!-- Field count badge -->
                                    <span class="text-[10px] text-muted-foreground font-mono" x-text="model.fields.length + ' fields'"></span>
                                </div>

                                <div class="flex items-center gap-2" @click.stop>
                                    <!-- Delete button -->
                                    <button type="button" @click="removeModel(mIdx)" class="p-1.5 rounded-lg text-destructive/60 hover:text-destructive hover:bg-destructive/10 transition" title="Delete Model">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                    <!-- Collapse chevron -->
                                    <svg class="size-5 text-muted-foreground transition-transform duration-200" :class="modelCollapsed[mIdx] ? '' : 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                            </div>

                            <!-- Collapsible Body -->
                            <div x-show="!modelCollapsed[mIdx]" x-collapse>
                                <!-- Model Name / Table Name / Description (Filament-style) -->
                                <div class="border-b border-border px-5 py-4 space-y-4 bg-card">
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label class="block text-xs font-semibold text-foreground mb-1">
                                                Model Name<span class="text-destructive">*</span>
                                            </label>
                                            <input type="text" x-model="model.handle" @input="if(!model._tableTouched){model.table = model.handle + 's'}; syncToYaml()" placeholder="e.g. product" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm font-semibold text-foreground focus:ring-1 focus:ring-primary focus:outline-none">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-foreground mb-1">
                                                Table Name<span class="text-destructive">*</span>
                                            </label>
                                            <input type="text" x-model="model.table" @input="model._tableTouched = true; syncToYaml()" :placeholder="model.handle + 's'" class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm font-mono text-foreground focus:ring-1 focus:ring-primary focus:outline-none">
                                            <p class="text-[10px] text-muted-foreground mt-0.5">Edit to customize the table name</p>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-foreground mb-1">Model Description</label>
                                        <textarea x-model="model.description" @input="syncToYaml()" rows="2" placeholder="Brief description of what this model does" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary focus:outline-none resize-y"></textarea>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-4">
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground cursor-pointer" title="Automatic created_at and updated_at timestamp columns">
                                            <input type="checkbox" x-model="model.timestamps" @change="syncToYaml()" class="rounded border-input text-primary focus:ring-primary">
                                            <span>Timestamps</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground cursor-pointer" title="Enables soft deleting via deleted_at timestamp column">
                                            <input type="checkbox" x-model="model.soft_deletes" @change="syncToYaml()" class="rounded border-input text-primary focus:ring-primary">
                                            <span>Soft Delete</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Fields Header (with filter: a model
                                     can carry 40+ fields — find, don't scroll) -->
                                <div class="px-5 pt-4 pb-2 flex flex-wrap items-center gap-3">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Fields</h4>
                                    <input type="text" x-model="fieldFilters[mIdx]" placeholder="Filter fields by name or label…"
                                           class="h-7 w-60 rounded-lg border border-input bg-background px-2.5 text-[11px] text-foreground focus:ring-1 focus:ring-primary">
                                    <span x-show="fieldFilters[mIdx]" class="text-[11px] text-muted-foreground"
                                          x-text="model.fields.filter(f => (f.handle + ' ' + (f.label || '')).toLowerCase().includes((fieldFilters[mIdx] || '').toLowerCase())).length + ' of ' + model.fields.length + ' shown'"></span>
                                </div>

                            <!-- Fields Container: High-Density Filament / October Table -->
                            <div class="px-4 sm:px-5 pb-4 sm:pb-5 space-y-3">
                                <div class="overflow-x-auto rounded-xl border border-border">
                                    {{-- min-w keeps the columns legible: on
                                         small screens the table scrolls
                                         instead of crushing the field
                                         name and type into truncation. --}}
                                    <table class="w-full min-w-[880px] text-left text-xs">
                                        <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border">
                                            <tr>
                                                <th class="w-12 px-2.5 py-2.5 text-center">#</th>
                                                <th class="w-44 px-3 py-2.5">Field Handle (Column)</th>
                                                <th class="w-44 px-3 py-2.5">Display Label</th>
                                                <th class="w-36 px-3 py-2.5">Data Type</th>
                                                <th class="w-28 px-2.5 py-2.5 text-center">Width</th>
                                                <th class="w-14 px-2 py-2.5 text-center" title="NOT NULL Constraint">Req</th>
                                                <th class="w-14 px-2 py-2.5 text-center" title="Allow NULL Values">Null</th>
                                                <th class="w-14 px-2 py-2.5 text-center" title="Hide field from forms and table views">Hide</th>
                                                <th class="w-32 px-2.5 py-2.5">Length / Options</th>
                                                <th class="w-28 px-2.5 py-2.5">Default Value</th>
                                                <th class="w-12 px-2.5 py-2.5 text-right"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-border/60 bg-card">
                                            <template x-for="(field, fIdx) in model.fields" :key="fIdx">
                                                <tr x-show="!fieldFilters[mIdx] || (field.handle + ' ' + (field.label || '')).toLowerCase().includes((fieldFilters[mIdx] || '').toLowerCase())" class="hover:bg-muted/20 transition-colors group">
                                                    <!-- Move buttons -->
                                                    <td class="px-2 py-2 text-center text-muted-foreground">
                                                        <div class="flex items-center justify-center gap-0.5">
                                                            <button type="button" @click="moveFieldUp(mIdx, fIdx)" :disabled="fIdx === 0" title="Move Up" class="p-1.5 text-sm leading-none rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition">↑</button>
                                                            <button type="button" @click="moveFieldDown(mIdx, fIdx)" :disabled="fIdx === model.fields.length - 1" title="Move Down" class="p-1.5 text-sm leading-none rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition">↓</button>
                                                        </div>
                                                    </td>
                                                    <!-- Handle -->
                                                    <td class="px-3 py-2">
                                                        <input type="text" x-model="field.handle" @input="syncToYaml()" placeholder="e.g. first_name" class="h-8 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 font-mono text-xs font-semibold text-foreground focus:bg-background focus:outline-none">
                                                    </td>
                                                    <!-- Label -->
                                                    <td class="px-3 py-2">
                                                        <input type="text" x-model="field.label" @input="syncToYaml()" placeholder="e.g. First Name" class="h-8 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 text-xs text-foreground focus:bg-background focus:outline-none">
                                                    </td>
                                                    <!-- Type -->
                                                    <td class="px-3 py-2">
                                                        <select x-model="field.type" @change="syncToYaml()" class="h-8 w-full rounded border border-input bg-card px-2 text-xs font-medium text-foreground focus:ring-1 focus:ring-primary">
                                                            <option value="string">string (varchar)</option>
                                                            <option value="text">text (longtext)</option>
                                                            <option value="integer">integer</option>
                                                            <option value="bigInteger">bigInteger</option>
                                                            <option value="decimal">decimal</option>
                                                            <option value="float">float</option>
                                                            <option value="boolean">boolean</option>
                                                            <option value="date">date</option>
                                                            <option value="datetime">datetime</option>
                                                            <option value="timestamp">timestamp</option>
                                                            <option value="foreign_id">foreign_id (FK)</option>
                                                            <option value="email">email</option>
                                                            <option value="url">url</option>
                                                            <option value="enum">enum</option>
                                                            <option value="json">json</option>
                                                        </select>
                                                    </td>
                                                    <!-- Width (Statamic pills) -->
                                                    <td class="px-2.5 py-2 text-center">
                                                        <div class="inline-flex rounded-md border border-input bg-card p-0.5 text-[10px] font-mono">
                                                            <button type="button" @click="field.width = 33; syncToYaml()" :class="field.width === 33 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1 py-0.5 transition" title="33% Width">33%</button>
                                                            <button type="button" @click="field.width = 50; syncToYaml()" :class="(field.width === 50 || !field.width) ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1 py-0.5 transition" title="50% Width">50%</button>
                                                            <button type="button" @click="field.width = 100; syncToYaml()" :class="field.width === 100 ? 'bg-primary text-primary-foreground font-bold rounded' : 'text-muted-foreground hover:text-foreground'" class="px-1 py-0.5 transition" title="100% Width">100%</button>
                                                        </div>
                                                    </td>
                                                    <!-- Required -->
                                                    <td class="px-2 py-2 text-center">
                                                        <input type="checkbox" x-model="field.required" @change="onRequiredToggle(field); syncToYaml()" class="rounded border-input text-primary focus:ring-primary h-3.5 w-3.5 cursor-pointer" title="Required NOT NULL constraint">
                                                    </td>
                                                    <!-- Nullable -->
                                                    <td class="px-2 py-2 text-center">
                                                        <input type="checkbox" x-model="field.nullable" @change="onNullableToggle(field); syncToYaml()" class="rounded border-input text-amber-500 focus:ring-amber-500 h-3.5 w-3.5 cursor-pointer" title="Allow NULL values">
                                                    </td>
                                                    <!-- Hide in UI views -->
                                                    <td class="px-2 py-2 text-center">
                                                        <button type="button" @click="field.hidden = !field.hidden; syncToYaml()" 
                                                                :title="field.hidden ? 'Field is hidden from UI views' : 'Field is visible in UI views'"
                                                                class="p-1 rounded hover:bg-muted transition-colors inline-flex items-center justify-center">
                                                            <span x-show="field.hidden" class="text-amber-500 font-bold text-[10px] uppercase tracking-wider bg-amber-500/10 px-1.5 py-0.5 rounded border border-amber-500/20">Hide</span>
                                                            <span x-show="!field.hidden" class="text-emerald-500 font-medium text-[10px] uppercase tracking-wider bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20">Show</span>
                                                        </button>
                                                    </td>
                                                    <!-- Length / Options -->
                                                    <td class="px-2.5 py-2">
                                                        <template x-if="field.type !== 'enum'">
                                                            <input type="text" x-model="field.length" @input="syncToYaml()" placeholder="255" class="h-7 w-full rounded border border-input bg-card px-2 font-mono text-xs text-foreground focus:ring-1 focus:ring-primary placeholder:text-muted-foreground/40" title="Column length (e.g. 255 or 12,2)">
                                                        </template>
                                                        <template x-if="field.type === 'enum'">
                                                            <input type="text" x-model="field.optionsText" @input="updateOptionsFromText(field)" placeholder="k: Val, k2: Val2" class="h-7 w-full rounded border border-indigo-500/40 bg-indigo-500/5 px-2 font-mono text-[11px] text-foreground focus:ring-1 focus:ring-indigo-500 placeholder:text-muted-foreground/40" title="Enum Options (key: Label, key2: Label2)">
                                                        </template>
                                                    </td>
                                                    <!-- Default -->
                                                    <td class="px-2.5 py-2">
                                                        <input type="text" x-model="field.default" @input="syncToYaml()" placeholder="NULL" class="h-7 w-full rounded border border-input bg-card px-2 font-mono text-xs text-foreground focus:ring-1 focus:ring-primary placeholder:text-muted-foreground/40" title="Default column value">
                                                    </td>
                                                    <!-- Action -->
                                                    <td class="px-2.5 py-2 text-right">
                                                        <button type="button" @click="removeField(mIdx, fIdx)" title="Delete Field" class="p-1 rounded text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition">
                                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>

                                    <!-- Table Footer -->
                                    <div class="border-t border-border bg-muted/20 px-4 py-2.5 flex items-center justify-center">
                                        <button type="button" @click="quickAddField(mIdx)" class="inline-flex items-center gap-1.5 rounded-lg border border-input bg-background px-4 py-1.5 text-xs font-semibold text-foreground hover:bg-muted transition shadow-xs">
                                            Add Field
                                        </button>
                                    </div>
                                </div>
                            </div>
                            </div> <!-- end x-collapse collapsible body -->
                        </div>
                    </template>

                    <!-- DEDICATED SLICE RELATIONSHIPS SECTION (Filament Style at Bottom) -->
                    <div class="rounded-2xl border border-border bg-card p-5 shadow-sm space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                            <div>
                                <h2 id="design-sec-relations" class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                                    <svg class="size-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                    Slice Relationships & Foreign Keys
                                    <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="allRelations.length"></span>
                                </h2>
                                <p class="text-xs text-muted-foreground mt-0.5">
                                    Configure Eloquent associations across models in this slice (hasMany, belongsTo, hasOne, belongsToMany) or with external models.
                                </p>
                            </div>
                            <button type="button" @click="addRelationship()" class="inline-flex items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/20 transition shadow-xs">
                                ➕ Add Relationship
                            </button>
                        </div>

                        <template x-if="allRelations.length === 0">
                            <div class="rounded-xl border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                                No cross-model relationships configured yet. Click <span class="font-semibold text-primary">Add Relationship</span> above to connect models with foreign keys.
                            </div>
                        </template>

                        <template x-if="allRelations.length > 0">
                            <div class="overflow-x-auto rounded-xl border border-border">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-muted/50 text-[11px] font-bold uppercase tracking-wider text-muted-foreground border-b border-border">
                                        <tr>
                                            <th class="w-10 px-3 py-2.5 text-center">#</th>
                                            <th class="w-48 px-3 py-2.5">Source Model (From)</th>
                                            <th class="w-36 px-3 py-2.5">Relationship Type</th>
                                            <th class="w-48 px-3 py-2.5">Target Model (To)</th>
                                            <th class="w-44 px-3 py-2.5">Foreign Key Column</th>
                                            <th class="w-40 px-3 py-2.5">Method Name</th>
                                            <th class="w-14 px-3 py-2.5 text-right"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border/60 bg-card">
                                        <template x-for="(rel, rIdx) in allRelations" :key="rIdx">
                                            <tr class="hover:bg-muted/20 transition-colors">
                                                <td class="px-2 py-2 text-center text-muted-foreground font-mono" x-text="rIdx + 1"></td>
                                                <!-- Source Model -->
                                                <td class="px-3 py-2">
                                                    <select x-model="rel.source_model" @change="syncToYaml()" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-semibold text-foreground focus:ring-1 focus:ring-primary">
                                                        <template x-for="m in blueprint.models" :key="'src-' + m.handle">
                                                            <option :value="m.handle" x-text="m.handle + (m.root ? ' (Root)' : '')" :selected="rel.source_model === m.handle"></option>
                                                        </template>
                                                    </select>
                                                </td>
                                                <!-- Relation Type -->
                                                <td class="px-3 py-2">
                                                    <select x-model="rel.type" @change="syncToYaml()" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-medium text-foreground focus:ring-1 focus:ring-primary">
                                                        <option value="hasMany">hasMany</option>
                                                        <option value="belongsTo">belongsTo</option>
                                                        <option value="hasOne">hasOne</option>
                                                        <option value="belongsToMany">belongsToMany</option>
                                                    </select>
                                                </td>
                                                <!-- Target Model -->
                                                <td class="px-3 py-2">
                                                    <select x-model="rel.model" @change="syncToYaml()" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-mono text-foreground focus:ring-1 focus:ring-primary">
                                                        <option value="" disabled>Select target model...</option>
                                                        <optgroup label="Current Slice Models">
                                                            <template x-for="m in blueprint.models" :key="'tgt-' + m.handle">
                                                                <option :value="m.handle" x-text="m.handle + ' (' + (m.table || m.handle + 's') + ')'" :selected="rel.model === m.handle"></option>
                                                            </template>
                                                        </optgroup>
                                                        <optgroup label="External Models">
                                                            <option value="user">user (users)</option>
                                                            <option value="role">role (roles)</option>
                                                            <option value="permission">permission (permissions)</option>
                                                            <option value="team">team (teams)</option>
                                                        </optgroup>
                                                        @if (!empty($installedSlices))
                                                        <optgroup label="Installed Slices">
                                                            <template x-for="s in catalogSlices()" :key="'rel-' + s.n">
                                                                <option :value="s.s" :selected="rel.model === s.s" x-text="s.n"></option>
                                                            </template>
                                                        </optgroup>
                                                        @endif
                                                    </select>
                                                </td>
                                                <!-- Foreign Key -->
                                                <td class="px-3 py-2">
                                                    <input type="text" x-model="rel.foreign_key" @input="syncToYaml()" placeholder="e.g. department_id" class="h-7 w-full rounded border border-input bg-card px-2 font-mono text-xs text-foreground focus:ring-1 focus:ring-primary">
                                                </td>
                                                <!-- Method Name -->
                                                <td class="px-3 py-2">
                                                    <input type="text" x-model="rel.name" @input="syncToYaml()" placeholder="e.g. employees" class="h-7 w-full rounded border border-input bg-card px-2 font-mono text-xs font-semibold text-foreground focus:ring-1 focus:ring-primary">
                                                </td>
                                                <!-- Delete Action -->
                                                <td class="px-3 py-2 text-right">
                                                    <button type="button" @click="removeRelationship(rIdx)" title="Delete Relationship" class="p-1 rounded text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition">
                                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            
                    <!-- LIFECYCLE WORKFLOW & STATE MACHINE ENGINE (Visual Card) -->
                    <div class="rounded-2xl border border-border bg-card p-5 shadow-sm space-y-4" x-show="blueprint.workflow && blueprint.workflow.states && blueprint.workflow.states.length">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                            <div>
                                <h2 id="design-sec-workflow" class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                                    <svg class="size-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Lifecycle Workflow & State Machine
                                    <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="(blueprint.workflow?.states?.length ?? 0) + ' Stages'"></span>
                                </h2>
                                <p class="text-xs text-muted-foreground mt-0.5">
                                    Statutory progression milestones, judicial hearing stages, and transition action guards governing this slice.
                                </p>
                            </div>
                            <span class="text-[11px] font-mono text-muted-foreground bg-muted/50 px-2.5 py-1 rounded-md" x-text="'Column: ' + (blueprint.workflow?.state_column || 'status')"></span>
                        </div>

                        <!-- Stages Grid -->
                        <div class="space-y-2">
                            <span class="text-xs font-bold text-foreground">Pipeline Stages & Judicial Milestones:</span>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5 pt-1">
                                <template x-for="(st, sIdx) in (blueprint.workflow?.states ?? [])" :key="sIdx">
                                    <div class="flex items-center justify-between p-3 rounded-xl border border-border/80 bg-muted/20 hover:bg-muted/40 transition gap-2">
                                        <div class="flex items-center gap-2.5 min-w-0">
                                            <span class="size-2.5 rounded-full shrink-0"
                                                :class="{
                                                    'bg-amber-500 shadow-xs shadow-amber-500/50': st.color === 'amber',
                                                    'bg-sky-500 shadow-xs shadow-sky-500/50': st.color === 'sky',
                                                    'bg-indigo-500 shadow-xs shadow-indigo-500/50': st.color === 'indigo',
                                                    'bg-teal-500 shadow-xs shadow-teal-500/50': st.color === 'teal',
                                                    'bg-blue-500 shadow-xs shadow-blue-500/50': st.color === 'blue',
                                                    'bg-cyan-500 shadow-xs shadow-cyan-500/50': st.color === 'cyan',
                                                    'bg-emerald-500 shadow-xs shadow-emerald-500/50': st.color === 'emerald',
                                                    'bg-rose-500 shadow-xs shadow-rose-500/50': st.color === 'rose',
                                                    'bg-slate-400': !st.color || st.color === 'slate'
                                                }"></span>
                                            <div class="min-w-0">
                                                <div class="text-xs font-semibold text-foreground truncate" x-text="st.label"></div>
                                                <div class="text-[10px] font-mono text-muted-foreground truncate" x-text="st.slug"></div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Transitions Grid -->
                        <div class="space-y-2 pt-2 border-t border-border/40" x-show="blueprint.workflow?.transitions?.length">
                            <span class="text-xs font-bold text-foreground">Guarded Lifecycle Transitions & Action Buttons:</span>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                                <template x-for="(tr, tIdx) in (blueprint.workflow?.transitions ?? [])" :key="tIdx">
                                    <div class="p-3 rounded-xl border border-border/80 bg-muted/10 space-y-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-xs font-bold text-foreground" x-text="tr.name || tr.slug"></span>
                                            <template x-if="tr.target_role">
                                                <span class="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded bg-primary/10 text-primary" x-text="'Role: ' + tr.target_role"></span>
                                            </template>
                                        </div>
                                        <div class="flex items-center gap-1.5 text-[11px] font-mono text-muted-foreground flex-wrap">
                                            <span class="px-1.5 py-0.5 rounded bg-muted text-foreground" x-text="Array.isArray(tr.from) ? tr.from.join(', ') : tr.from"></span>
                                            <span>&rarr;</span>
                                            <span class="px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400 font-bold" x-text="tr.to"></span>
                                            <template x-if="tr.requires_remarks">
                                                <span class="text-[9px] text-amber-400 bg-amber-500/10 px-1 rounded ml-auto">Requires Remarks</span>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- SCOPED ROLES & RBAC MATRIX (Visual Card) -->
                    <div class="rounded-2xl border border-border bg-card p-5 shadow-sm space-y-4" x-show="blueprint.roles && blueprint.roles.length">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                            <div>
                                <h2 id="design-sec-roles" class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                                    <svg class="size-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                    Scoped Operational Roles & Access Control
                                    <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary" x-text="(blueprint.roles?.length ?? 0) + ' Roles'"></span>
                                </h2>
                                <p class="text-xs text-muted-foreground mt-0.5">
                                    User personas, judicial authorities, and permission gates assigned to this domain slice.
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <template x-for="(r, rIdx) in (blueprint.roles ?? [])" :key="rIdx">
                                <div class="p-3.5 rounded-xl border border-border bg-muted/20 space-y-2 flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between gap-1 mb-1">
                                            <span class="text-xs font-bold text-foreground" x-text="r.name"></span>
                                            <span class="text-[9px] font-mono px-1.5 py-0.5 rounded bg-muted text-muted-foreground" x-text="r.slug"></span>
                                        </div>
                                        <p class="text-[11px] text-muted-foreground line-clamp-2" x-text="r.description"></p>
                                    </div>
                                    <div class="pt-2 border-t border-border/40 space-y-1">
                                        <span class="text-[10px] font-semibold text-foreground">Capabilities:</span>
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="(p, pIdx) in (r.permissions ?? []).slice(0, 4)" :key="pIdx">
                                                <span class="text-[9px] font-mono px-1.5 py-0.5 rounded bg-primary/10 text-primary" x-text="p"></span>
                                            </template>
                                            <template x-if="(r.permissions ?? []).length > 4">
                                                <span class="text-[9px] font-mono px-1 py-0.5 rounded bg-muted text-muted-foreground" x-text="'+' + ((r.permissions.length) - 4)"></span>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- PAGE & UI ARCHITECTURE PLAN (BlatUI 4-Step Methodology) -->
                    <div class="rounded-2xl border border-indigo-500/30 bg-card p-5 shadow-sm space-y-4" x-data="{ activePageStep: 'index' }">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3.5">
                            <div>
                                <h2 id="design-sec-uiplan" class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                                    <span class="flex size-7 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-sm">📄</span>
                                    <span>Page & UI Architecture Plan</span>
                                    <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400">4-Step Method</span>
                                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400" x-text="(blueprint.name || 'Slice') + ' Workspace'"></span>
                                </h2>
                                <p class="text-xs text-muted-foreground mt-0.5">
                                    Canonical screen blueprints for porting legacy views into modern, high-density templates (Blade + Alpine.js + Tailwind CSS v4 + Lucide icons).
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-mono rounded-md bg-muted px-2.5 py-1 text-muted-foreground border border-border/60">
                                    php artisan laraslice:ui <span class="text-indigo-400 font-semibold" x-text="(blueprint.domain || 'ERP') + '.' + (blueprint.module || 'Work') + '.' + (blueprint.name || 'Slice')"></span> --with-tabs
                                </span>
                            </div>
                        </div>

                        <!-- 4 Step Navigation Tabs -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <button type="button" @click="activePageStep = 'index'" :class="activePageStep === 'index' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground'" class="rounded-lg p-2.5 text-left text-xs transition cursor-pointer flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase font-bold opacity-75">Step 1</span>
                                <span class="font-semibold text-xs flex items-center gap-1.5">
                                    <span>📋</span> Directory (Index)
                                </span>
                            </button>
                            <button type="button" @click="activePageStep = 'show'" :class="activePageStep === 'show' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground'" class="rounded-lg p-2.5 text-left text-xs transition cursor-pointer flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase font-bold opacity-75">Step 2</span>
                                <span class="font-semibold text-xs flex items-center gap-1.5">
                                    <span>📑</span> Show Workspace
                                </span>
                            </button>
                            <button type="button" @click="activePageStep = 'create_edit'" :class="activePageStep === 'create_edit' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground'" class="rounded-lg p-2.5 text-left text-xs transition cursor-pointer flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase font-bold opacity-75">Step 3</span>
                                <span class="font-semibold text-xs flex items-center gap-1.5">
                                    <span>📝</span> Create & Edit Form
                                </span>
                            </button>
                            <button type="button" @click="activePageStep = 'workflow'" :class="activePageStep === 'workflow' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground'" class="rounded-lg p-2.5 text-left text-xs transition cursor-pointer flex flex-col gap-0.5">
                                <span class="text-[10px] uppercase font-bold opacity-75">Step 4</span>
                                <span class="font-semibold text-xs flex items-center gap-1.5">
                                    <span>⚡</span> Finite State FSM
                                </span>
                            </button>
                        </div>

                        <!-- Step 1: Directory / Index View Plan (Interactive Statamic/Filament Directory Builder) -->
                        <div x-show="activePageStep === 'index'" class="space-y-4 pt-1" x-init="ensureUiConfig()">
                            <!-- Stats Ribbon Live Mockup -->
                            <div class="rounded-xl border border-border/80 bg-muted/10 p-3.5 space-y-2.5">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-foreground">📊 Stats Ribbon KPIs</span>
                                        <span class="text-[10px] text-muted-foreground">High-level executive status counters atop index screen</span>
                                    </div>
                                    <span class="text-[10px] font-mono text-indigo-400">Metric Cards</span>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <template x-for="(st, stIdx) in (getActiveSlicePages().index?.stats_ribbon || [])" :key="stIdx">
                                        <div class="p-2.5 rounded-lg border border-border bg-card flex flex-col justify-between">
                                            <span class="text-[10px] text-muted-foreground font-medium" x-text="st.label"></span>
                                            <div class="flex items-baseline gap-1.5 mt-1">
                                                <span class="text-base font-extrabold text-foreground" x-text="st.value"></span>
                                                <span class="text-[10px] text-emerald-400" x-text="st.change"></span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Interactive Directory Column Builder -->
                            <div class="rounded-xl border border-indigo-500/30 bg-card p-4 space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/50 pb-2.5">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-foreground">📋 Curated Semantic Directory Columns</span>
                                            <span class="rounded-full bg-primary/10 px-2 py-0.2 text-[10px] font-bold text-primary" x-text="(blueprint.ui?.directory?.columns?.length || 0) + ' Columns'"></span>
                                        </div>
                                        <p class="text-[11px] text-muted-foreground">Customize high-signal directory columns, labels, sortability, and composite semantic formatters.</p>
                                    </div>

                                    <!-- Add Column Dropdown -->
                                    <div class="flex items-center gap-2" x-data="{ addColField: '' }">
                                        <select x-model="addColField" class="h-8 rounded-lg border border-border bg-background px-2.5 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-primary">
                                            <option value="">+ Add Column from Fields...</option>
                                            <template x-for="f in (blueprint.models?.[0]?.fields || [])" :key="f.handle">
                                                <option :value="f.handle" x-text="f.label || f.handle"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="if(addColField) { addDirectoryColumn(addColField); addColField = ''; }" class="h-8 rounded-lg bg-indigo-600 px-3 text-xs font-semibold text-white hover:bg-indigo-700 transition cursor-pointer">
                                            Add
                                        </button>
                                    </div>
                                </div>

                                <!-- Columns List (Interactive Cards) -->
                                <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                                    <template x-for="(col, cIdx) in (blueprint.ui?.directory?.columns || [])" :key="cIdx">
                                        <div class="flex flex-wrap sm:flex-nowrap items-center justify-between gap-2.5 rounded-lg border border-border bg-muted/20 p-2.5 text-xs">
                                            <!-- Handle & Reorder -->
                                            <div class="flex items-center gap-2 min-w-[140px]">
                                                <div class="flex flex-col gap-0.5">
                                                    <button type="button" @click="moveDirectoryColumn(cIdx, -1)" class="text-[10px] text-muted-foreground hover:text-foreground cursor-pointer" :disabled="cIdx === 0">▲</button>
                                                    <button type="button" @click="moveDirectoryColumn(cIdx, 1)" class="text-[10px] text-muted-foreground hover:text-foreground cursor-pointer" :disabled="cIdx === (blueprint.ui.directory.columns.length - 1)">▼</button>
                                                </div>
                                                <div>
                                                    <span class="font-mono text-[11px] font-bold text-indigo-400" x-text="col.handle || col.key || 'col'"></span>
                                                    <div class="text-[9px] text-muted-foreground font-mono" x-text="'#' + (cIdx + 1)"></div>
                                                </div>
                                            </div>

                                            <!-- Label Input -->
                                            <div class="flex-1 min-w-[120px]">
                                                <input type="text" x-model="col.label" @input="syncUiToBlueprintAndYaml()" placeholder="Column Label" class="w-full h-7 rounded border border-input bg-card px-2 text-xs font-semibold text-foreground focus:outline-none focus:ring-1 focus:ring-primary">
                                            </div>

                                            <!-- Semantic Formatter Dropdown -->
                                            <div class="w-44">
                                                <select x-model="col.type" @change="col.formatter = col.type; syncUiToBlueprintAndYaml()" class="w-full h-7 rounded border border-input bg-card px-2 text-xs text-foreground font-medium focus:outline-none focus:ring-1 focus:ring-primary">
                                                    @foreach(\LaraSlice\Support\DataTableRows::FORMATTERS as $fmt)
                                                        <option value="{{ $fmt }}">{{ ['text' => '📄 Plain Text', 'number' => '🔢 Number', 'code_badge' => '🏷️ Code Badge (#ID)', 'composite_user' => '👤 Composite User (Avatar + Name)', 'user_stack' => '👥 User Stack', 'status_pill' => '🟢 Status Pill (Dot + Badge)', 'money' => '💵 Money', 'money_breakdown' => '💰 Money Breakdown', 'text_with_badge' => '📛 Text with Badge', 'badge' => '🏷️ Badge', 'icon_text' => '🎨 Icon + Text', 'progress_bar' => '📊 Progress Bar (%)', 'timer' => '⏱️ Timer', 'lines' => '📋 Lines', 'duration' => '⏳ Duration', 'date' => '📅 Date', 'date_weekday' => '📅 Date + Weekday', 'date_time' => '📅 Date & Time', 'date_overdue' => '⚠️ Date (overdue-aware)'][$fmt] ?? $fmt }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <!-- Sortable Toggle -->
                                            <label class="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer select-none">
                                                <input type="checkbox" x-model="col.sortable" @change="syncUiToBlueprintAndYaml()" class="rounded border-border text-primary focus:ring-primary">
                                                <span>Sortable</span>
                                            </label>

                                            <!-- Remove Column -->
                                            <button type="button" @click="removeDirectoryColumn(cIdx)" class="text-rose-500 hover:text-rose-400 p-1 cursor-pointer" title="Remove column">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Interactive Multi-Dimensional Filter Bar Builder -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                                <!-- Quick Filters (Horizontal Bar) -->
                                <div class="rounded-xl border border-border bg-card p-3.5 space-y-2.5">
                                    <div class="flex items-center justify-between border-b border-border/50 pb-2">
                                        <div>
                                            <span class="text-xs font-bold text-foreground">🔍 Quick Filter Bar</span>
                                            <p class="text-[10px] text-muted-foreground">Sticky select dropdowns above table</p>
                                        </div>
                                        <div class="flex items-center gap-1.5" x-data="{ addFltField: '' }">
                                            <select x-model="addFltField" class="h-7 rounded border border-border bg-background px-2 text-[11px] text-foreground">
                                                <option value="">+ Add Quick Filter...</option>
                                                <template x-for="f in (blueprint.models?.[0]?.fields || [])" :key="f.handle">
                                                    <option :value="f.handle" x-text="f.label || f.handle"></option>
                                                </template>
                                            </select>
                                            <button type="button" @click="if(addFltField) { addQuickFilter(addFltField); addFltField = ''; }" class="h-7 rounded bg-primary/10 text-primary px-2 text-xs font-semibold hover:bg-primary/20 cursor-pointer">Add</button>
                                        </div>
                                    </div>

                                    <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                        <template x-for="(qf, qIdx) in (blueprint.ui?.directory?.quick_filters || [])" :key="qIdx">
                                            <div class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 p-2 text-xs">
                                                <div class="flex items-center gap-2">
                                                    <!-- Reorder buttons -->
                                                    <div class="flex items-center gap-0.5">
                                                        <button type="button" @click="moveQuickFilter(qIdx, -1)" :disabled="qIdx === 0" class="size-5 rounded flex items-center justify-center hover:bg-muted text-muted-foreground hover:text-foreground disabled:opacity-20 text-[10px]" title="Move Up">▲</button>
                                                        <button type="button" @click="moveQuickFilter(qIdx, 1)" :disabled="qIdx === (blueprint.ui?.directory?.quick_filters?.length - 1)" class="size-5 rounded flex items-center justify-center hover:bg-muted text-muted-foreground hover:text-foreground disabled:opacity-20 text-[10px]" title="Move Down">▼</button>
                                                    </div>
                                                    <span class="font-bold font-mono text-[11px] text-indigo-400" x-text="qf.handle || qf.field"></span>
                                                    <input type="text" x-model="qf.label" @input="syncUiToBlueprintAndYaml()" placeholder="Label" class="h-6 w-24 rounded border border-input bg-card px-1.5 text-[11px]">
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <label class="flex items-center gap-1 text-[11px] text-muted-foreground cursor-pointer">
                                                        <input type="checkbox" x-model="qf.is_searchable" @change="qf.searchable = qf.is_searchable; syncUiToBlueprintAndYaml()">
                                                        <span>Searchable</span>
                                                    </label>
                                                    <button type="button" @click="removeQuickFilter(qIdx)" class="text-rose-500 hover:text-rose-400 text-xs cursor-pointer p-0.5" title="Remove filter">✕</button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <!-- Off-Canvas Filter Drawer -->
                                <div class="rounded-xl border border-border bg-card p-3.5 space-y-2.5">
                                    <div class="flex items-center justify-between border-b border-border/50 pb-2">
                                        <div>
                                            <span class="text-xs font-bold text-foreground">🗂️ Off-Canvas Filter Drawer</span>
                                            <p class="text-[10px] text-muted-foreground">Slide-over drawer for deep filter queries</p>
                                        </div>
                                        <div class="flex items-center gap-1.5" x-data="{ addSideField: '' }">
                                            <select x-model="addSideField" class="h-7 rounded border border-border bg-background px-2 text-[11px] text-foreground">
                                                <option value="">+ Add Drawer Filter...</option>
                                                <template x-for="f in (blueprint.models?.[0]?.fields || [])" :key="f.handle">
                                                    <option :value="f.handle" x-text="f.label || f.handle"></option>
                                                </template>
                                            </select>
                                            <button type="button" @click="if(addSideField) { addSidebarFilter(addSideField); addSideField = ''; }" class="h-7 rounded bg-primary/10 text-primary px-2 text-xs font-semibold hover:bg-primary/20 cursor-pointer">Add</button>
                                        </div>
                                    </div>

                                    <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                        <template x-for="(sf, sIdx) in (blueprint.ui?.directory?.sidebar_filters || [])" :key="sIdx">
                                            <div class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 p-2 text-xs">
                                                <span class="font-bold font-mono text-[11px] text-foreground" x-text="sf.handle || sf.field"></span>
                                                <div class="flex items-center gap-2">
                                                    <select x-model="sf.type" @change="syncUiToBlueprintAndYaml()" class="h-6 rounded border border-input bg-card px-1 text-[11px]">
                                                        <option value="select">Select Dropdown</option>
                                                        <option value="date_range">Date Range</option>
                                                        <option value="text">Text Search</option>
                                                    </select>
                                                    <button type="button" @click="removeSidebarFilter(sIdx)" class="text-rose-500 hover:text-rose-400 text-xs">✕</button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <!-- Interactive Row Actions Builder (ERP Matched & Declarative) -->
                            <div class="rounded-xl border border-indigo-500/30 bg-card p-4 space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/50 pb-2.5">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-foreground">⚡ Table Row Actions & Drawer Deep-Linking</span>
                                            <span class="rounded-full bg-primary/10 px-2 py-0.2 text-[10px] font-bold text-primary" x-text="((blueprint.ui?.directory?.row_actions?.items || []).length) + ' Actions'"></span>
                                        </div>
                                        <p class="text-[11px] text-muted-foreground">Configure inline buttons vs 3-dots dropdown menu, slide-over drawer triggers, and custom actions.</p>
                                    </div>

                                    <!-- Presets & Add Action -->
                                    <div class="flex flex-wrap items-center gap-2">
                                        <!-- Layout Style -->
                                        <div class="flex items-center gap-1.5 bg-muted/40 p-1 rounded-lg border border-border/60 text-xs">
                                            <span class="text-[10px] text-muted-foreground font-semibold px-1">Layout:</span>
                                            <button type="button" @click="blueprint.ui.directory.row_actions.display = 'dropdown'; syncUiToBlueprintAndYaml();" 
                                                class="px-2 py-0.5 rounded text-[11px] font-semibold transition cursor-pointer"
                                                :class="blueprint.ui?.directory?.row_actions?.display === 'dropdown' ? 'bg-primary text-primary-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground'">
                                                3-Dots Menu
                                            </button>
                                            <button type="button" @click="blueprint.ui.directory.row_actions.display = 'hybrid'; syncUiToBlueprintAndYaml();" 
                                                class="px-2 py-0.5 rounded text-[11px] font-semibold transition cursor-pointer"
                                                :class="blueprint.ui?.directory?.row_actions?.display === 'hybrid' ? 'bg-primary text-primary-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground'">
                                                Hybrid (ERP View Outside)
                                            </button>
                                            <button type="button" @click="blueprint.ui.directory.row_actions.display = 'inline'; syncUiToBlueprintAndYaml();" 
                                                class="px-2 py-0.5 rounded text-[11px] font-semibold transition cursor-pointer"
                                                :class="blueprint.ui?.directory?.row_actions?.display === 'inline' ? 'bg-primary text-primary-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground'">
                                                Inline Buttons
                                            </button>
                                        </div>

                                        <!-- Preset Selector -->
                                        <select @change="if($event.target.value) { setRowActionsPreset($event.target.value); $event.target.value = ''; }" class="h-8 rounded-lg border border-border bg-background px-2.5 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-primary">
                                            <option value="">⚡ Load Preset...</option>
                                            <option value="standard_crud">Standard CRUD (3-Dots)</option>
                                            <option value="hybrid_view_outside">ERP Style (View Outside + 3-Dots)</option>
                                            <option value="task_suite">Task & Timer Suite (Timer Outside + 3-Dots)</option>
                                            <option value="invoice_suite">Invoice Financial Suite (12 Actions)</option>
                                            <option value="inline_compact">Compact Inline Buttons</option>
                                        </select>

                                        <button type="button" @click="addRowAction()" class="h-8 rounded-lg bg-indigo-600 px-3 text-xs font-semibold text-white hover:bg-indigo-700 transition cursor-pointer flex items-center gap-1.5">
                                            <span>+ Add Action</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Action Items Roster -->
                                <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                                    <template x-for="(act, aIdx) in (blueprint.ui?.directory?.row_actions?.items || [])" :key="aIdx">
                                        <div class="flex flex-wrap sm:flex-nowrap items-center justify-between gap-2.5 rounded-lg border border-border bg-muted/20 p-2.5 text-xs">
                                            <!-- Handle & Reorder -->
                                            <div class="flex items-center gap-2 min-w-[120px]">
                                                <div class="flex flex-col gap-0.5">
                                                    <button type="button" @click="moveRowAction(aIdx, -1)" class="text-[10px] text-muted-foreground hover:text-foreground cursor-pointer" :disabled="aIdx === 0">▲</button>
                                                    <button type="button" @click="moveRowAction(aIdx, 1)" class="text-[10px] text-muted-foreground hover:text-foreground cursor-pointer" :disabled="aIdx === (blueprint.ui.directory.row_actions.items.length - 1)">▼</button>
                                                </div>
                                                <div>
                                                    <input type="text" x-model="act.handle" @input="syncUiToBlueprintAndYaml()" placeholder="handle" class="w-24 h-7 rounded border border-input bg-card px-2 text-xs font-mono font-bold text-indigo-400 focus:outline-none focus:ring-1 focus:ring-primary">
                                                </div>
                                            </div>

                                            <!-- Label Input -->
                                            <div class="flex-1 min-w-[100px]">
                                                <input type="text" x-model="act.label" @input="syncUiToBlueprintAndYaml()" placeholder="Label (e.g. View Details)" class="w-full h-7 rounded border border-input bg-card px-2 text-xs font-semibold text-foreground focus:outline-none focus:ring-1 focus:ring-primary">
                                            </div>

                                            <!-- Placement Toggle (Inline vs Dropdown) -->
                                            <div class="w-36">
                                                <select x-model="act.placement" @change="syncUiToBlueprintAndYaml()" class="w-full h-7 rounded border border-input bg-card px-2 text-xs font-medium text-foreground focus:outline-none focus:ring-1 focus:ring-primary">
                                                    <option value="dropdown">Inside 3-Dots Menu</option>
                                                    <option value="inline">Inline Button (Outside)</option>
                                                </select>
                                            </div>

                                            <!-- Action Type Dropdown -->
                                            <div class="w-36">
                                                <select x-model="act.type" @change="syncUiToBlueprintAndYaml()" class="w-full h-7 rounded border border-input bg-card px-2 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-primary">
                                                    <option value="drawer">Slide-Over Drawer</option>
                                                    <option value="route">Full-Page Route</option>
                                                    <option value="modal">Confirm Modal</option>
                                                    <option value="delete">Delete Record</option>
                                                    <option value="event">Alpine Event</option>
                                                </select>
                                            </div>

                                            <!-- Lucide Icon Picker -->
                                            <div class="w-28">
                                                <select x-model="act.icon" @change="syncUiToBlueprintAndYaml()" class="w-full h-7 rounded border border-input bg-card px-2 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-primary font-mono">
                                                    <option value="eye">eye</option>
                                                    <option value="pencil">pencil</option>
                                                    <option value="trash-2">trash-2</option>
                                                    <option value="copy">copy</option>
                                                    <option value="download">download</option>
                                                    <option value="play">play</option>
                                                    <option value="send">send</option>
                                                    <option value="plus">plus</option>
                                                    <option value="archive">archive</option>
                                                    <option value="check">check</option>
                                                    <option value="x">x</option>
                                                    <option value="credit-card">credit-card</option>
                                                    <option value="external-link">external-link</option>
                                                </select>
                                            </div>

                                            <!-- Danger Toggle -->
                                            <label class="flex items-center gap-1.5 text-xs text-rose-500 cursor-pointer select-none">
                                                <input type="checkbox" x-model="act.danger" @change="syncUiToBlueprintAndYaml()" class="rounded border-border text-rose-500 focus:ring-rose-500">
                                                <span>Danger</span>
                                            </label>

                                            <!-- Remove Action -->
                                            <button type="button" @click="removeRowAction(aIdx)" class="text-rose-500 hover:text-rose-400 p-1 cursor-pointer" title="Remove action">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Show Workspace (Multi-Tab Consolidation) -->
                                                    <!-- Table / Bulk Actions Builder (index multi-select) -->
                            <div class="rounded-xl border border-emerald-500/30 bg-card p-4 space-y-3" x-init="ensureUiConfig()">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/50 pb-2.5">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-foreground">📦 Table Bulk Actions</span>
                                            <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-600" x-text="(blueprint.ui?.directory?.bulk_actions || []).length + ' Actions'"></span>
                                        </div>
                                        <p class="text-[11px] text-muted-foreground">When rows are selected on the index table: native export/delete or workflow forward (remarks, status).</p>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button type="button" @click="addBulkActionPreset('standard')" class="rounded-lg border border-border px-2 py-1 text-[10px] font-semibold hover:bg-muted cursor-pointer">+ Standard</button>
                                        <button type="button" @click="addBulkActionPreset('workflow')" class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 text-[10px] font-semibold text-emerald-700 dark:text-emerald-400 cursor-pointer">+ Workflow pack</button>
                                        <button type="button" @click="addBulkAction()" class="rounded-lg bg-primary px-2 py-1 text-[10px] font-bold text-primary-foreground cursor-pointer">+ Custom</button>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <template x-for="(ba, bIdx) in (blueprint.ui?.directory?.bulk_actions || [])" :key="bIdx">
                                        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-border/60 bg-muted/10 p-2.5">
                                            <input type="text" x-model="ba.handle" @change="syncUiToBlueprintAndYaml()" placeholder="handle" class="w-28 rounded border border-border bg-background px-2 py-1 text-[11px] font-mono">
                                            <input type="text" x-model="ba.label" @change="syncUiToBlueprintAndYaml()" placeholder="Label" class="w-36 rounded border border-border bg-background px-2 py-1 text-[11px]">
                                            <select x-model="ba.type" @change="if (ba.type === 'workflow') { ba.transition = ba.transition || ba.handle; } syncUiToBlueprintAndYaml()" class="rounded border border-border bg-background px-2 py-1 text-[11px]">
                                                <option value="native">Native</option>
                                                <option value="workflow">Workflow</option>
                                            </select>
                                            <input type="text" x-show="ba.type === 'workflow'" x-model="ba.transition" @change="syncUiToBlueprintAndYaml()" placeholder="transition slug" class="w-32 rounded border border-border bg-background px-2 py-1 text-[11px] font-mono">
                                            <label class="flex items-center gap-1 text-[11px] cursor-pointer" x-show="ba.type === 'workflow'">
                                                <input type="checkbox" x-model="ba.requires_remarks" @change="syncUiToBlueprintAndYaml()" class="rounded border-border">
                                                Remarks
                                            </label>
                                            <label class="flex items-center gap-1 text-[11px] text-rose-500 cursor-pointer">
                                                <input type="checkbox" x-model="ba.danger" @change="syncUiToBlueprintAndYaml()" class="rounded border-border text-rose-500">
                                                Danger
                                            </label>
                                            <button type="button" @click="blueprint.ui.directory.bulk_actions.splice(bIdx, 1); syncUiToBlueprintAndYaml()" class="ml-auto text-rose-500 text-xs cursor-pointer" title="Remove">✕</button>
                                        </div>
                                    </template>
                                    <p class="text-[10px] text-muted-foreground" x-show="!(blueprint.ui?.directory?.bulk_actions || []).length">No bulk actions — index multi-select will only show defaults if the generator falls back.</p>
                                </div>
                            </div>
<div x-show="activePageStep === 'show'" class="space-y-3.5 pt-1">
                            <div class="rounded-xl border border-border/80 bg-muted/10 p-4 space-y-3">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <span class="text-xs font-bold text-foreground">📑 High-Density Workspace Sub-Tabs</span>
                                        <p class="text-[11px] text-muted-foreground mt-0.5">
                                            Replaces legacy screen clutter by consolidating child entity relations into responsive workspace tabs.
                                        </p>
                                    </div>
                                    <span class="text-[10px] font-mono text-emerald-400 font-bold">1 Master View : N Sub-Entities</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5 border-b border-border pb-2">
                                    <template x-for="(tb, tbIdx) in (getActiveSlicePages().show?.sub_tabs || [])" :key="tbIdx">
                                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-xs">
                                            <span x-text="tb.label || tb.name"></span>
                                            <template x-if="tb.count !== undefined">
                                                <span class="rounded bg-primary/10 px-1 text-[10px] font-mono font-bold text-primary" x-text="tb.count"></span>
                                            </template>
                                        </span>
                                    </template>
                                </div>
                                <div class="rounded-lg border border-dashed border-border bg-card/60 p-3 text-xs text-muted-foreground">
                                    💡 <strong>Architecture Note:</strong> In legacy systems, each child table had its own raw sidebar link. In LaraSlice VSA, these tables are managed within this master aggregate slice's Show Workspace with full CRUD modals and inline data streams.
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Create & Edit Form Sections (Statamic / Filament Section Builder) -->
                        <div x-show="activePageStep === 'create_edit'" class="space-y-4 pt-1" x-init="ensureUiConfig()">
                            <!-- Form Mode & Presentation Bar -->
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-500/30 bg-muted/20 p-3.5 text-xs">
                                <div>
                                    <span class="font-bold text-foreground">🗂️ Form Presentation Architecture</span>
                                    <p class="text-[11px] text-muted-foreground">Select how Create & Edit forms are presented to the end user.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="inline-flex rounded-lg border border-input bg-card p-0.5">
                                        <button type="button" @click="blueprint.ui.forms.mode = 'drawer'; syncUiToBlueprintAndYaml()" :class="blueprint.ui?.forms?.mode === 'drawer' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="rounded-md px-3 py-1.5 transition cursor-pointer">
                                            🗂️ Instant Slide-Over Drawer (?drawer=create)
                                        </button>
                                        <button type="button" @click="blueprint.ui.forms.mode = 'page'; syncUiToBlueprintAndYaml()" :class="blueprint.ui?.forms?.mode === 'page' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="rounded-md px-3 py-1.5 transition cursor-pointer">
                                            📄 Standalone Full Page
                                        </button>
                                    </div>
                                    <button type="button" @click="addFormSection()" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-700 transition cursor-pointer shadow-xs">
                                        <span>+ Add Section Card</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Section Cards List (Statamic Blueprint Style) -->
                            <div class="space-y-4">
                                <template x-for="(sec, secIdx) in (blueprint.ui?.forms?.sections || [])" :key="secIdx">
                                    <div class="rounded-xl border border-border bg-card p-4 space-y-3.5 shadow-sm">
                                        <!-- Card Header: Title, Description, Grid Cols, Actions -->
                                        <div class="flex flex-wrap sm:flex-nowrap items-center justify-between gap-3 border-b border-border/50 pb-3">
                                            <div class="flex-1 min-w-[220px] space-y-1">
                                                <div class="flex items-center gap-2">
                                                    <span class="size-6 rounded-md bg-primary/10 text-primary flex items-center justify-center font-bold text-xs" x-text="'C' + (secIdx + 1)"></span>
                                                    <input type="text" x-model="sec.title" @input="syncUiToBlueprintAndYaml()" placeholder="Section Title (e.g. Account & Identity Details)" class="h-8 w-full max-w-sm rounded-lg border border-input bg-background px-2.5 text-xs font-bold text-foreground focus:outline-none focus:ring-1 focus:ring-primary">
                                                </div>
                                                <input type="text" x-model="sec.description" @input="syncUiToBlueprintAndYaml()" placeholder="Optional subtitle or instructions..." class="h-6 w-full max-w-md rounded border border-transparent bg-transparent px-2 text-[11px] text-muted-foreground focus:border-input focus:bg-background">
                                            </div>

                                            <div class="flex items-center gap-2">
                                                <!-- Grid Columns Selector -->
                                                <div class="flex items-center gap-1 text-xs">
                                                    <span class="text-muted-foreground text-[11px]">Grid:</span>
                                                    <select x-model="sec.columns" @change="syncUiToBlueprintAndYaml()" class="h-8 rounded-lg border border-input bg-background px-2 text-xs font-semibold text-foreground focus:outline-none">
                                                        <option value="1">1 Col (Stack)</option>
                                                        <option value="2">2 Columns</option>
                                                        <option value="3">3 Columns (ERP Standard)</option>
                                                        <option value="4">4 Columns</option>
                                                    </select>
                                                </div>

                                                <!-- Move Section -->
                                                <div class="flex items-center gap-1">
                                                    <button type="button" @click="moveFormSection(secIdx, -1)" class="p-1 rounded hover:bg-muted text-muted-foreground" :disabled="secIdx === 0">▲</button>
                                                    <button type="button" @click="moveFormSection(secIdx, 1)" class="p-1 rounded hover:bg-muted text-muted-foreground" :disabled="secIdx === (blueprint.ui.forms.sections.length - 1)">▼</button>
                                                </div>

                                                <!-- Delete Section -->
                                                <button type="button" @click="removeFormSection(secIdx)" class="text-rose-500 hover:text-rose-400 p-1 cursor-pointer" title="Delete section card">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Fields in this Section (Statamic Field Pills) -->
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between text-[11px] text-muted-foreground">
                                                <span>Fields in Card (<span x-text="sec.fields?.length || 0"></span>)</span>
                                                <div class="flex items-center gap-1.5" x-data="{ addFieldSelect: '' }">
                                                    <select x-model="addFieldSelect" class="h-7 rounded border border-border bg-background px-2 text-[11px] text-foreground">
                                                        <option value="">+ Add Field to this Card...</option>
                                                        <template x-for="f in (blueprint.models?.[0]?.fields || [])" :key="f.handle">
                                                            <option :value="f.handle" x-text="f.label || f.handle"></option>
                                                        </template>
                                                    </select>
                                                    <button type="button" @click="if(addFieldSelect) { addFieldToSection(secIdx, addFieldSelect); addFieldSelect = ''; }" class="h-7 rounded bg-primary/10 text-primary px-2 font-semibold hover:bg-primary/20 cursor-pointer">Add</button>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                                                <template x-for="(fld, fIdx) in (sec.fields || [])" :key="fIdx">
                                                    <div class="rounded-lg border border-border/70 bg-muted/20 p-2.5 space-y-2 text-xs">
                                                        <div class="flex items-center justify-between">
                                                            <span class="font-mono font-bold text-foreground" x-text="fld.handle"></span>
                                                            <div class="flex items-center gap-1">
                                                                <button type="button" @click="moveFieldInSection(secIdx, fIdx, -1)" class="text-[10px] text-muted-foreground hover:text-foreground">◀</button>
                                                                <button type="button" @click="moveFieldInSection(secIdx, fIdx, 1)" class="text-[10px] text-muted-foreground hover:text-foreground">▶</button>
                                                                <button type="button" @click="removeFieldFromSection(secIdx, fIdx)" class="text-rose-500 hover:text-rose-400 text-xs ml-1">✕</button>
                                                            </div>
                                                        </div>

                                                        <input type="text" x-model="fld.label" @input="syncUiToBlueprintAndYaml()" placeholder="Field Label" class="h-6 w-full rounded border border-input bg-card px-2 text-[11px] font-semibold text-foreground">

                                                        <!-- Width / Col-Span & Searchable Controls (Statamic Style) -->
                                                        <div class="flex flex-wrap items-center justify-between gap-1 pt-1 border-t border-border/40">
                                                            <!-- Col-Span -->
                                                            <div class="flex items-center gap-1 text-[11px]">
                                                                <span class="text-muted-foreground">Span:</span>
                                                                <select x-model="fld.span" @change="syncUiToBlueprintAndYaml()" class="h-6 rounded border border-input bg-card px-1 text-[10px] font-mono">
                                                                    <option value="1">1 Col (33%)</option>
                                                                    <option value="2">2 Cols (66%)</option>
                                                                    <option value="3">3 Cols (100% Full)</option>
                                                                </select>
                                                            </div>

                                                            <!-- Searchable Combobox Toggle -->
                                                            <label class="flex items-center gap-1 text-[10px] text-muted-foreground cursor-pointer" title="Enable searchable combobox dropdown">
                                                                <input type="checkbox" x-model="fld.searchable" @change="syncUiToBlueprintAndYaml()" class="rounded size-3 text-primary">
                                                                <span>🔍 Searchable</span>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Step 4: Workflow Finite State Machine -->
                        <div x-show="activePageStep === 'workflow'" class="space-y-3.5 pt-1">
                            <div class="rounded-xl border border-border/80 bg-muted/10 p-4 space-y-3">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <span class="text-xs font-bold text-foreground">⚡ Finite State Machine Lifecycle</span>
                                        <p class="text-[11px] text-muted-foreground mt-0.5">
                                            State machine column: <code class="text-indigo-400 font-mono text-xs" x-text="getActiveSlicePages().workflow?.state_column || 'status'"></code>
                                        </p>
                                    </div>
                                    <span class="text-[10px] font-mono text-emerald-400 font-bold">FSM + RBAC Guard</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <template x-for="(st, stIdx) in (getActiveSlicePages().workflow?.states || [])" :key="stIdx">
                                        <div class="flex items-center gap-1.5">
                                            <span class="inline-flex items-center rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground shadow-xs capitalize" x-text="st"></span>
                                            <template x-if="stIdx < (getActiveSlicePages().workflow?.states?.length - 1)">
                                                <span class="text-muted-foreground text-xs">→</span>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    ✓ Every state transition is gated via <code class="text-indigo-400 font-mono">&commat;can('<span x-text="blueprint.handle"></span>.transition')</code> and logged immutably in activity audits.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- LIVE UI PREVIEW MOCKUP PANEL (Jev instant_preview_mock Priority) -->
            <div x-show="mode === 'preview'" class="space-y-6 min-w-0">
                <!-- Model Switcher Tabs if multiple models -->
                <template x-if="blueprint.models.length > 1">
                    <div class="flex items-center gap-2 border-b border-border pb-2 overflow-x-auto">
                        <template x-for="(m, idx) in blueprint.models" :key="idx">
                            <button type="button" @click="activeModelIndex = idx" :class="activeModelIndex === idx ? 'bg-primary text-primary-foreground font-bold shadow-sm' : 'bg-muted/50 text-muted-foreground hover:text-foreground'" class="rounded-lg px-3 py-1.5 text-xs transition flex items-center gap-1.5">
                                <span x-text="m.handle"></span>
                                <template x-if="m.root">
                                    <span class="size-1.5 rounded-full bg-emerald-400"></span>
                                </template>
                            </button>
                        </template>
                    </div>
                </template>

                <!-- Simulated Form Card -->
                <div class="rounded-2xl border border-border bg-card shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between border-b border-border px-6 py-4 bg-muted/20">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-primary">Live Form Component Mockup</span>
                            <h3 class="text-base font-bold text-foreground" x-text="'Create New ' + (blueprint.models[activeModelIndex]?.handle || 'Item').replace(/_/g, ' ')"></h3>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-muted px-2 py-0.5 font-mono text-[11px] text-muted-foreground" x-text="blueprint.models[activeModelIndex]?.table"></span>
                        </div>
                    </div>

                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-12 gap-4">
                            <template x-for="(field, fIdx) in (blueprint.models[activeModelIndex]?.fields || [])" :key="fIdx">
                                <div :style="{ gridColumn: 'span ' + getGridSpan(field.width) + ' / span ' + getGridSpan(field.width) }" class="space-y-1.5">
                                    <label class="block text-xs font-semibold text-foreground">
                                        <span x-text="field.label || field.handle"></span>
                                        <template x-if="field.required"><span class="text-destructive">*</span></template>
                                    </label>

                                    <!-- Component Variants -->
                                    <template x-if="field.type === 'text'">
                                        <textarea rows="3" disabled :placeholder="'Enter ' + (field.label || field.handle).toLowerCase() + '...'" class="w-full rounded-xl border border-input bg-muted/30 px-3 py-2 text-xs text-muted-foreground"></textarea>
                                    </template>
                                    <template x-if="field.type === 'boolean'">
                                        <div class="flex items-center gap-3 pt-1">
                                            <div class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent bg-primary transition-colors">
                                                <span class="translate-x-4 pointer-events-none inline-block size-4 rounded-full bg-background shadow-lg transition-transform"></span>
                                            </div>
                                            <span class="text-xs text-muted-foreground">Enabled</span>
                                        </div>
                                    </template>
                                    <template x-if="field.type === 'date' || field.type === 'datetime'">
                                        <div class="relative">
                                            <input type="text" disabled placeholder="YYYY-MM-DD" class="h-9 w-full rounded-xl border border-input bg-muted/30 px-3 text-xs text-muted-foreground">
                                            <span class="absolute right-3 top-2.5 text-xs text-muted-foreground">📅</span>
                                        </div>
                                    </template>
                                    <template x-if="field.type === 'decimal'">
                                        <div class="relative">
                                            <span class="absolute left-3 top-2.5 text-xs text-muted-foreground font-mono">$</span>
                                            <input type="text" disabled placeholder="0.00" class="h-9 w-full rounded-xl border border-input bg-muted/30 pl-7 pr-3 text-xs text-muted-foreground font-mono">
                                        </div>
                                    </template>
                                    <template x-if="field.type === 'foreign_id'">
                                        <div class="relative">
                                            <input type="text" disabled :placeholder="'Select ' + (field.label || field.handle) + '...'" class="h-9 w-full rounded-xl border border-input bg-muted/30 px-3 text-xs text-muted-foreground">
                                            <span class="absolute right-3 top-2.5 text-xs text-muted-foreground">🔍</span>
                                        </div>
                                    </template>
                                    <template x-if="field.type === 'enum'">
                                        <div class="relative">
                                            <select disabled class="h-9 w-full appearance-none rounded-xl border border-input bg-muted/30 px-3 text-xs font-medium text-foreground pr-8">
                                                <template x-for="(label, val) in (field.options || { '': 'Select ' + (field.label || field.handle) + '...' })" :key="val">
                                                    <option :value="val" x-text="label" :selected="val === field.default"></option>
                                                </template>
                                            </select>
                                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-muted-foreground">
                                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="field.type === 'email'">
                                        <div class="relative">
                                            <input type="email" disabled placeholder="name@domain.com" class="h-9 w-full rounded-xl border border-input bg-muted/30 pl-8 pr-3 text-xs text-muted-foreground font-mono">
                                            <span class="absolute left-2.5 top-2.5 text-xs text-muted-foreground">@</span>
                                        </div>
                                    </template>
                                    <template x-if="!['text', 'boolean', 'date', 'datetime', 'decimal', 'foreign_id', 'enum', 'email'].includes(field.type)">
                                        <input type="text" disabled :placeholder="'Enter ' + (field.label || field.handle).toLowerCase() + '...'" class="h-9 w-full rounded-xl border border-input bg-muted/30 px-3 text-xs text-muted-foreground">
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                            <button type="button" disabled class="rounded-xl border border-border px-4 py-2 text-xs font-semibold text-muted-foreground">Cancel</button>
                            <button type="button" disabled class="rounded-xl bg-primary px-5 py-2 text-xs font-bold text-primary-foreground opacity-90 shadow-sm">Save Record</button>
                        </div>
                    </div>
                </div>

                <!-- Simulated Data Table Card -->
                <div class="rounded-2xl border border-border bg-card shadow-sm overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between bg-muted/20">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Live Data Table Mockup</span>
                            <h3 class="text-sm font-bold text-foreground" x-text="(blueprint.models[activeModelIndex]?.handle || 'Entity').replace(/_/g, ' ') + ' Listing'"></h3>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="text" disabled placeholder="Search records..." class="h-8 w-44 rounded-lg border border-input bg-background px-2.5 text-xs text-muted-foreground">
                            <button type="button" disabled class="h-8 rounded-lg bg-primary px-3 text-xs font-bold text-primary-foreground">+ New</button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-muted/50 uppercase tracking-wider text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2.5 font-bold">ID</th>
                                    <template x-for="(f, fIdx) in (blueprint.models[activeModelIndex]?.fields || []).slice(0, 5)" :key="fIdx">
                                        <th class="px-4 py-2.5 font-bold" x-text="f.label || f.handle"></th>
                                    </template>
                                    <th class="px-4 py-2.5 font-bold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border text-foreground">
                                <tr>
                                    <td class="px-4 py-3 font-mono text-muted-foreground">#1</td>
                                    <template x-for="(f, fIdx) in (blueprint.models[activeModelIndex]?.fields || []).slice(0, 5)" :key="fIdx">
                                        <td class="px-4 py-3">
                                            <template x-if="f.type === 'boolean'"><span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-600">Active</span></template>
                                            <template x-if="f.type === 'enum'"><span class="rounded-full bg-indigo-500/10 px-2.5 py-0.5 text-[10px] font-bold text-indigo-600 dark:text-indigo-400" x-text="Object.values(f.options || {})[0] || f.default || 'Active'"></span></template>
                                            <template x-if="f.type === 'decimal'"><span class="font-mono font-medium">$25,000.00</span></template>
                                            <template x-if="f.type === 'date'"><span class="font-mono text-muted-foreground">2026-09-25</span></template>
                                            <template x-if="f.type === 'email'"><span class="font-mono text-primary text-[11px]">customer@example.com</span></template>
                                            <template x-if="!['boolean', 'enum', 'decimal', 'date', 'email'].includes(f.type)"><span class="font-medium" x-text="'Sample ' + (f.label || f.handle)"></span></template>
                                        </td>
                                    </template>
                                    <td class="px-4 py-3 text-right">
                                        <span class="rounded bg-muted px-2 py-1 text-[11px] text-muted-foreground font-semibold">View</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 font-mono text-muted-foreground">#2</td>
                                    <template x-for="(f, fIdx) in (blueprint.models[activeModelIndex]?.fields || []).slice(0, 5)" :key="fIdx">
                                        <td class="px-4 py-3">
                                            <template x-if="f.type === 'boolean'"><span class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-600">Pending</span></template>
                                            <template x-if="f.type === 'enum'"><span class="rounded-full bg-indigo-500/10 px-2.5 py-0.5 text-[10px] font-bold text-indigo-600 dark:text-indigo-400" x-text="Object.values(f.options || {})[1] || Object.values(f.options || {})[0] || 'Pending'"></span></template>
                                            <template x-if="f.type === 'decimal'"><span class="font-mono font-medium">$18,500.00</span></template>
                                            <template x-if="f.type === 'date'"><span class="font-mono text-muted-foreground">2026-09-26</span></template>
                                            <template x-if="f.type === 'email'"><span class="font-mono text-primary text-[11px]">enterprise@domain.com</span></template>
                                            <template x-if="!['boolean', 'enum', 'decimal', 'date', 'email'].includes(f.type)"><span class="font-medium" x-text="'Enterprise ' + (f.label || f.handle)"></span></template>
                                        </td>
                                    </template>
                                    <td class="px-4 py-3 text-right">
                                        <span class="rounded bg-muted px-2 py-1 text-[11px] text-muted-foreground font-semibold">View</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- YAML SOURCE EDITOR PANEL (sticky in split mode:
                 the declaration stays visible while the visual
                 editor scrolls, so every edit's YAML consequence
                 is on screen) -->
            <div x-show="mode === 'yaml' || mode === 'split'" class="space-y-4 min-w-0 xl:sticky xl:top-24 xl:self-start xl:max-h-[calc(100vh-8rem)] xl:overflow-y-auto">
                <div class="rounded-2xl border border-border bg-card shadow-sm overflow-hidden flex flex-col h-full">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-3">
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-emerald-500"></span>
                            <h2 id="design-sec-yaml" class="text-xs font-bold uppercase tracking-wider text-muted-foreground" x-text="yamlViewMode === 'domain' ? 'Unified Domain Blueprint (' + (blueprint.domain || portDomain || 'domain') + '.yaml)' : 'Declarative Blueprint (' + (blueprint.handle || 'slice') + '.yaml)'"></h2>
                        </div>
                        <div class="flex items-center gap-2">
                            <!-- Toggle Slice YAML vs Complete Domain YAML -->
                            <div class="inline-flex rounded-lg p-0.5 bg-muted/60 border border-border text-[11px]" x-show="slices.length > 0">
                                <button type="button" @click="yamlViewMode = 'slice'" :class="yamlViewMode === 'slice' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-2.5 py-1 rounded-md transition cursor-pointer">
                                    📄 Current Slice YAML
                                </button>
                                <button type="button" @click="yamlViewMode = 'domain'; generateDomainYaml()" :class="yamlViewMode === 'domain' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground'" class="px-2.5 py-1 rounded-md transition cursor-pointer flex items-center gap-1">
                                    <span>📦 Complete Domain YAML</span>
                                    <span class="text-[9px] px-1 py-0.2 rounded-full bg-primary/20" x-text="slices.length + ' slices'"></span>
                                </button>
                            </div>
                            <button type="button" x-show="yamlViewMode === 'slice'" @click="formatYaml()" class="text-xs text-muted-foreground hover:text-foreground cursor-pointer">
                                Beautify
                            </button>
                            <button type="button" x-show="yamlViewMode === 'slice'" @click="saveDraft()" class="text-xs font-semibold text-violet-500 hover:text-violet-400 cursor-pointer" title="Save this blueprint as a server-side draft and resume later">
                                💾 Save draft
                            </button>
                            <button type="button" x-show="yamlViewMode === 'slice'" @click="saveToLibrary()" class="text-xs font-semibold text-emerald-500 hover:text-emerald-400 cursor-pointer" title="Shelve this blueprint in the team library (porting/blueprints/) so the whole team shares it through Git">
                                📚 Save to library
                            </button>
                            <button type="button" x-show="yamlViewMode === 'slice' && librarySlug" @click="deleteLibraryItem()" class="text-xs font-semibold text-destructive hover:text-destructive/80 cursor-pointer" title="Remove the loaded blueprint from the team library">
                                🗑 Library
                            </button>
                            <span x-show="draftStatus" class="text-[11px] text-muted-foreground" x-text="draftStatus"></span>
                            <button type="button" x-show="yamlViewMode === 'domain'" @click="navigator.clipboard.writeText(domainYamlSource); alert('Complete Domain Blueprint YAML copied to clipboard!')" class="text-xs text-primary hover:underline flex items-center gap-1 cursor-pointer">
                                📋 Copy YAML
                            </button>
                        </div>
                    </div>
                    <div class="p-4 flex-1">
                        <!-- Single Slice YAML (Editable) -->
                        <textarea x-show="yamlViewMode === 'slice'" id="blueprint-source" x-model="source" @input="syncFromYaml()" rows="32" spellcheck="false" autocapitalize="off" autocomplete="off" class="min-h-[580px] w-full resize-y rounded-xl border border-input bg-slate-950 p-4 font-mono text-[13px] leading-6 text-slate-100 shadow-inner focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>

                        <!-- Complete Domain YAML (Read-Only Unified Architecture View) -->
                        <textarea x-show="yamlViewMode === 'domain'" id="domain-blueprint-source" x-model="domainYamlSource" @input.debounce.500ms="syncFromDomainYaml()" rows="32" spellcheck="false" autocapitalize="off" autocomplete="off" class="min-h-[580px] w-full resize-y rounded-xl border border-input bg-slate-950 p-4 font-mono text-[13px] leading-6 text-emerald-300 shadow-inner focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
                    </div>
                </div>
            </div>

            <!-- REVIEW & PLAN PANEL (October & Filament Style Insights) -->
            <div class="space-y-5 min-w-0">
                <!-- Action Card -->
                <div class="rounded-2xl border border-border bg-card p-5 shadow-sm space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold text-foreground">Blueprint Engine</h2>
                        <span class="rounded bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">v1.0</span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Validates declarative schema, parses parent-child aggregates, evaluates migration safety, and generates complete vertical slices.
                    </p>
                    <template x-if="slices.length > 1">
                        <div class="space-y-1.5 rounded-xl border border-primary/20 bg-primary/5 p-2.5">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-primary">Planning Scope</span>
                                <span class="text-[10px] font-mono font-semibold text-muted-foreground" x-text="slices.length + ' Slices in [' + (blueprint.domain || 'Domain') + ']'"></span>
                            </div>
                            <div class="grid grid-cols-2 gap-1.5">
                                <button type="button" @click="planScope = 'domain'" :class="planScope === 'domain' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'bg-card text-foreground hover:bg-muted'" class="cursor-pointer rounded-lg px-2.5 py-1.5 text-xs transition text-center flex items-center justify-center gap-1.5 border border-border">
                                    <span>🌐 Whole Domain</span>
                                    <span class="rounded-full bg-black/20 dark:bg-white/20 px-1 py-0.2 text-[9px]" x-text="slices.length"></span>
                                </button>
                                <button type="button" @click="planScope = 'slice'" :class="planScope === 'slice' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'bg-card text-foreground hover:bg-muted'" class="cursor-pointer rounded-lg px-2.5 py-1.5 text-xs transition text-center flex items-center justify-center gap-1.5 border border-border">
                                    <span>📄 Active Slice</span>
                                    <span class="truncate max-w-[80px] text-[9px] opacity-80" x-text="'(' + (blueprint.name || 'Slice') + ')'"></span>
                                </button>
                            </div>
                        </div>
                    </template>
                    <button type="submit" form="plan-form" class="w-full inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-primary-foreground shadow hover:opacity-90 transition cursor-pointer">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span x-show="slices.length > 1 && planScope === 'domain'" x-text="'Validate & Plan Whole Domain (' + slices.length + ' Slices in [' + (blueprint.domain || 'Domain') + '])'"></span>
                        <span x-show="!(slices.length > 1 && planScope === 'domain')" x-text="'Validate & Plan ' + (blueprint.name ? blueprint.name + ' Slice' : 'Generation')"></span>
                    </button>
                </div>

                @if (isset($plan))
                    <div class="rounded-2xl border border-border bg-card shadow-sm overflow-hidden">
                        <div class="flex flex-col gap-2 border-b border-border px-5 py-4 bg-muted/10 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                @if ($plan['is_domain_plan'] ?? false)
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-bold text-foreground">Domain Suite Generation Plan</h3>
                                        <span class="rounded-full bg-primary/20 px-2 py-0.5 text-[10px] font-bold text-primary">{{ $plan['slices_count'] }} Slices in [{{ $plan['domain_name'] }}]</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground font-mono mt-0.5">{{ $plan['target'] }}</p>
                                @else
                                    <h3 class="font-bold text-foreground">Generation Plan</h3>
                                    <p class="text-xs text-muted-foreground font-mono mt-0.5">{{ $plan['target'] }}</p>
                                @endif
                            </div>
                            @if ($plan['conflict'])
                                <span class="rounded-full bg-destructive/10 px-3 py-1 text-xs font-bold text-destructive">Destination Exists</span>
                            @elseif (! $supported || $existingTables !== [])
                                <span class="rounded-full bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-600">Review Required</span>
                            @else
                                <span class="rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-600">Ready to Apply</span>
                            @endif
                        </div>

                        <div class="space-y-5 p-5">
                            <!-- Domain Slices Breakdown Grid (When Domain Plan) -->
                            @if (($plan['is_domain_plan'] ?? false) && isset($plan['slice_plans']))
                                <div class="rounded-xl border border-primary/20 bg-primary/5 p-3.5 space-y-2.5">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-primary uppercase tracking-wider">📦 Domain Slices in [{{ $plan['domain_name'] }}] ({{ count($plan['slice_plans']) }})</span>
                                        <span class="text-[11px] font-mono text-muted-foreground">{{ count($plan['files']) }} total files across suite</span>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                        @foreach ($plan['slice_plans'] as $sp)
                                            <div class="rounded-lg border border-border bg-card p-2.5 flex items-center justify-between shadow-xs">
                                                <div>
                                                    <span class="font-bold text-foreground">{{ $sp['name'] }}</span>
                                                    <p class="text-[10px] text-muted-foreground font-mono">{{ $sp['models_count'] }} {{ str('model')->plural($sp['models_count']) }} · {{ $sp['files_count'] }} files · {{ $sp['database_operations_count'] }} DB ops</p>
                                                </div>
                                                <span class="rounded px-1.5 py-0.5 text-[10px] font-bold {{ $sp['conflict'] ? 'bg-destructive/10 text-destructive' : 'bg-emerald-500/10 text-emerald-600' }}">
                                                    {{ $sp['conflict'] ? 'Conflict' : 'Ready' }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <!-- TypeSafe AI / Jev Architectural Judgments -->
                            @if (isset($archetypeDecision))
                                <div class="rounded-xl border border-indigo-500/30 bg-indigo-500/5 p-3.5 space-y-1.5">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold uppercase tracking-wider text-indigo-500">Jev Archetype Analysis</span>
                                        </div>
                                        <span class="rounded bg-indigo-500/10 px-2 py-0.5 text-[11px] font-mono font-bold text-indigo-400">
                                            Confidence: {{ round(($archetypeDecision['confidence'] ?? 0) * 100) }}%
                                        </span>
                                    </div>
                                    <div class="text-xs font-semibold text-foreground">
                                        {{ str($archetypeDecision['archetype'])->replace('_', ' ')->title() }}
                                    </div>
                                    <p class="text-[11px] text-muted-foreground leading-relaxed">
                                        {{ $archetypeDecision['rationale'] }}
                                    </p>
                                </div>
                            @endif

                            @if (isset($riskDecision))
                                <div class="rounded-xl border {{ $riskDecision['data_loss_risk'] ? 'border-amber-500/40 bg-amber-500/10' : 'border-emerald-500/30 bg-emerald-500/5' }} p-3.5 space-y-1.5">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold uppercase tracking-wider {{ $riskDecision['data_loss_risk'] ? 'text-amber-500' : 'text-emerald-500' }}">Migration Safety Judgment</span>
                                        <span class="text-[11px] font-mono font-bold">
                                            Risk Prob: {{ $riskDecision['probability'] }}
                                        </span>
                                    </div>
                                    @if ($riskDecision['warnings'] !== [])
                                        <ul class="text-[11px] text-amber-700 dark:text-amber-300 space-y-1">
                                            @foreach ($riskDecision['warnings'] as $w)
                                                <li>⚠️ {{ $w }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="text-[11px] text-muted-foreground">Zero destructive operations detected. Clean additive schema.</p>
                                    @endif
                                </div>
                            @endif

                            @if (! $supported)
                                <div role="alert" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">{{ $supportMessage }}</div>
                            @endif
                            @if ($existingTables !== [])
                                <div role="alert" class="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">Existing DB Tables: {{ implode(', ', $existingTables) }}.</div>
                            @endif

                            <!-- Models Breakdown -->
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Compiled Entities ({{ count($plan['models']) }})</h4>
                                    @if ($plan['is_domain_plan'] ?? false)
                                        <span class="text-[10px] text-primary font-bold">Across {{ $plan['slices_count'] }} Slices</span>
                                    @endif
                                </div>
                                <div class="overflow-x-auto rounded-lg border border-border">
                                    <table class="w-full text-left text-xs">
                                        <thead class="bg-muted/50 uppercase tracking-wider text-muted-foreground">
                                            <tr>
                                                @if ($plan['is_domain_plan'] ?? false)
                                                    <th class="px-3 py-2">Slice</th>
                                                @endif
                                                <th class="px-3 py-2">Model</th>
                                                <th class="px-3 py-2">Table</th>
                                                <th class="px-3 py-2">Fields</th>
                                                <th class="px-3 py-2">Relations</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-border">
                                            @foreach ($plan['models'] as $model)
                                                <tr>
                                                    @if ($plan['is_domain_plan'] ?? false)
                                                        <td class="px-3 py-2 font-mono text-[11px] font-bold text-primary">{{ $model['slice_name'] ?? '' }}</td>
                                                    @endif
                                                    <td class="px-3 py-2 font-bold text-foreground">{{ $model['handle'] }}</td>
                                                    <td class="px-3 py-2 font-mono text-muted-foreground">{{ $model['table'] }}</td>
                                                    <td class="px-3 py-2">{{ $model['field_count'] }}</td>
                                                    <td class="px-3 py-2">{{ $model['relation_count'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Database Operations -->
                            <details class="group rounded-xl border border-border">
                                <summary class="cursor-pointer px-3.5 py-2.5 text-xs font-bold text-foreground flex items-center justify-between">
                                    <span>Database Operations ({{ count($plan['database_operations']) }}){{ ($plan['is_domain_plan'] ?? false) ? ' across '.$plan['slices_count'].' Slices' : '' }}</span>
                                    <span class="text-muted-foreground group-open:rotate-180 transition">▼</span>
                                </summary>
                                <div class="space-y-2 border-t border-border p-3 text-xs bg-muted/20">
                                    @foreach ($plan['database_operations'] as $operation)
                                        <div class="rounded-lg border border-border bg-card p-2.5">
                                            <p class="font-bold text-foreground">{{ str($operation['kind'])->replace('_', ' ')->title() }}: <span class="font-mono text-primary">{{ $operation['table'] }}</span></p>
                                            @if ($operation['kind'] === 'create_table')
                                                <ul class="mt-1.5 space-y-0.5 text-[11px] text-muted-foreground">
                                                    @foreach ($operation['columns'] as $col)
                                                        <li><span class="font-mono text-foreground">{{ $col['column'] }}</span> ({{ $col['type'] }}){{ $col['nullable'] ? ' · null' : '' }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </details>

                            <!-- Target Files Generated -->
                            <details class="group rounded-xl border border-border">
                                <summary class="cursor-pointer px-3.5 py-2.5 text-xs font-bold text-foreground flex items-center justify-between">
                                    <span>Generated Files ({{ count($plan['files']) }}){{ ($plan['is_domain_plan'] ?? false) ? ' across '.$plan['slices_count'].' Slices' : '' }}</span>
                                    <span class="text-muted-foreground group-open:rotate-180 transition">▼</span>
                                </summary>
                                <ul class="max-h-56 space-y-1 overflow-auto border-t border-border p-3 font-mono text-[11px] text-muted-foreground">
                                    @foreach ($plan['files'] as $f)
                                        <li class="break-all">{{ $f }}</li>
                                    @endforeach
                                </ul>
                            </details>

                            <!-- Apply Button -->
                            @if ($supported && $existingTables === [])
                                <form method="POST" action="{{ route('laraslice.wizard.blueprint.apply') }}"
                                      x-data="{ submitting: false, confirmedConflict: {{ ! $plan['conflict'] ? 'true' : 'false' }} }"
                                      @submit="submitting = true"
                                      class="pt-4 border-t border-border space-y-3">
                                    @csrf
                                    <input type="hidden" name="source" value="{{ $source }}">
                                    <input type="hidden" name="format" value="{{ $format }}">
                                    <input type="hidden" name="plan_hash" value="{{ $plan['plan_hash'] }}">
                                    <input type="hidden" name="slices_json" :value="JSON.stringify(slices)">
                                    <input type="hidden" name="active_slice_idx" :value="activeSliceIdx">
                                    <input type="hidden" name="plan_scope" value="{{ $planScope ?? 'domain' }}">

                                    @if ($plan['conflict'])
                                        <div class="rounded-xl border border-destructive/30 bg-destructive/10 p-3 space-y-2 text-xs">
                                            <div class="flex items-center gap-1.5 font-bold text-destructive">
                                                <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                <span>Existing Slice Destination Detected</span>
                                            </div>
                                            <p class="text-muted-foreground text-[11px] leading-relaxed">
                                                One or more target directories already exist on disk (e.g. MasterData). Enable overwrite to update existing slice files.
                                            </p>
                                            <label class="flex items-center gap-2 cursor-pointer font-medium text-destructive pt-1 select-none">
                                                <input type="checkbox" name="overwrite_existing" value="1" x-model="confirmedConflict" class="rounded border-destructive/40 text-destructive focus:ring-destructive size-4">
                                                <span>Force overwrite / update existing slice files in destination</span>
                                            </label>
                                        </div>
                                    @endif

                                    <div class="space-y-2">
                                        <label class="flex items-start gap-2.5 text-xs text-foreground cursor-pointer select-none">
                                            <input type="checkbox" name="confirm_apply" value="1" required class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary">
                                            @if ($plan['is_domain_plan'] ?? false)
                                                <span class="font-medium">Confirm and generate all {{ count($plan['slice_plans']) }} vertical slices & slice.yaml manifests in [{{ $plan['domain_name'] }}]</span>
                                            @else
                                                <span class="font-medium">Confirm and generate vertical slice files & slice.yaml</span>
                                            @endif
                                        </label>
                                        <label class="flex items-start gap-2.5 text-xs text-foreground cursor-pointer select-none bg-muted/30 p-2.5 rounded-xl border border-border/60">
                                            <input type="checkbox" name="auto_migrate" value="1" checked class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary">
                                            <span class="font-medium flex flex-wrap items-center gap-1.5">
                                                <span>⚡ Automatically run database migrations after generation</span>
                                                <span class="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-bold text-emerald-600 dark:text-emerald-400">Recommended</span>
                                            </span>
                                        </label>
                                    </div>
                                    <button type="submit"
                                            :disabled="submitting || !confirmedConflict"
                                            class="w-full inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-xs font-bold text-primary-foreground hover:bg-primary/90 transition shadow-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                                        <span x-show="!submitting" class="flex items-center gap-2">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                            @if ($plan['is_domain_plan'] ?? false)
                                                <span>⚡ Generate Entire Domain Suite ({{ count($plan['slice_plans']) }} Slices)</span>
                                            @else
                                                <span>🚀 Generate Slice Files</span>
                                            @endif
                                        </span>
                                        <span x-show="submitting" x-cloak class="flex items-center gap-2">
                                            <svg class="size-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                            Generating Slice Files & Schemas...
                                        </span>
                                    </button>
                                </form>
                            @else
                                <div class="pt-3 border-t border-border space-y-2">
                                    <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 space-y-1.5 text-xs">
                                        <div class="flex items-center gap-1.5 font-bold text-amber-700 dark:text-amber-400">
                                            <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                            <span>Generation Locked (Review Required)</span>
                                        </div>
                                        <ul class="list-disc list-inside space-y-1 text-muted-foreground text-[11px] leading-relaxed">
                                            @if (! $supported)
                                                <li>{{ $supportMessage ?? 'Blueprint structure is not currently supported for 1-click generator execution.' }}</li>
                                            @endif
                                            @if ($existingTables !== [])
                                                <li>Existing database tables detected: <span class="font-mono text-foreground">{{ implode(', ', $existingTables) }}</span>.</li>
                                            @endif
                                        </ul>
                                    </div>
                                    <button type="button" disabled class="w-full inline-flex min-h-10 items-center justify-center rounded-xl bg-muted px-4 py-2.5 text-xs font-bold text-muted-foreground cursor-not-allowed opacity-75">
                                        🔒 Generation Locked
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-border bg-card/60 p-8 text-center">
                        <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-primary/10 text-xl text-primary">⌘</div>
                        <h3 class="mt-4 font-bold text-foreground">No generation plan yet</h3>
                        <p class="mx-auto mt-1 max-w-xs text-xs text-muted-foreground leading-relaxed">
                            Click 'Validate & Plan Generation' to inspect model structure, database migrations, and required file changes.
                        </p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Porting Studio modal: lazy-loaded on first open by
             the factory (ensurePortingModal) from
             /laraslice/studio/blueprint/porting-modal — its
             ~100 KB of review markup no longer rides in every
             Design fragment. The slot sits exactly where the
             include used to render, inside this component. --}}
        <div id="design-porting-modal-slot"></div>

        <!-- Import YAML Modal -->
        <div x-show="showImportYamlModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs" @keydown.escape.window="showImportYamlModal = false" style="display: none;">
            <div class="w-full max-w-2xl rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto" @click.outside="showImportYamlModal = false">
                <div class="flex items-center justify-between border-b border-border/40 pb-3">
                    <div class="flex items-center gap-2.5">
                        <span class="text-xl">📄</span>
                        <div>
                            <h3 class="text-base font-bold text-foreground">Import or Paste YAML Blueprint</h3>
                            <p class="text-xs text-muted-foreground">Paste a complete multi-slice domain YAML or a single slice YAML to instantly load into Studio.</p>
                        </div>
                    </div>
                    <button type="button" @click="showImportYamlModal = false" class="text-muted-foreground hover:text-foreground cursor-pointer">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div>
                    <textarea x-model="importYamlText" placeholder="Paste your domain.yaml or slice.yaml here..." rows="16" spellcheck="false" class="w-full rounded-xl border border-input bg-slate-950 p-4 font-mono text-[12px] leading-5 text-emerald-300 shadow-inner focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
                </div>
                <div class="flex items-center justify-between border-t border-border/40 pt-3">
                    <span class="text-[11px] text-muted-foreground">Auto-detects multi-slice domain suites vs. single slice blueprints</span>
                    <div class="flex gap-2">
                        <button type="button" @click="showImportYamlModal = false" class="rounded-lg border border-border px-3.5 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground cursor-pointer">Cancel</button>
                        <button type="button" @click="applyImportedYaml()" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-xs font-bold text-primary-foreground hover:bg-primary/90 transition shadow-xs cursor-pointer">
                            <span>⚡ Parse & Load into Studio</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- INTROSPECT DB TABLE MODAL (October CMS Style) -->
    <div x-show="showIntrospectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" style="display: none;" @keydown.escape.window="showIntrospectModal = false">
        <div class="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-4" @click.outside="showIntrospectModal = false">
            <div class="flex items-center justify-between border-b border-border pb-3">
                <h3 class="font-bold text-foreground text-sm flex items-center gap-2">
                    <svg class="size-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7h16m-8 4v6m-3-3h6"/></svg>
                    Introspect Database Table
                </h3>
                <button type="button" @click="showIntrospectModal = false" class="text-muted-foreground hover:text-foreground">✕</button>
            </div>
            <p class="text-xs text-muted-foreground">
                Select an existing MySQL/PostgreSQL table to auto-extract columns, types, and defaults directly into your visual blueprint.
            </p>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1.5">Select Table</label>
                <select x-model="selectedTable" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs text-foreground focus:ring-1 focus:ring-primary">
                    <option value="" disabled>Choose existing table...</option>
                    <template x-for="tb in introspectTables" :key="tb">
                        <option :value="tb" x-text="tb"></option>
                    </template>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" @click="showIntrospectModal = false" class="rounded-lg border border-border px-3 py-2 text-xs font-semibold text-foreground hover:bg-muted">
                    Cancel
                </button>
                <button type="button" @click="introspectTable()" :disabled="!selectedTable || introspecting" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-xs font-bold text-primary-foreground hover:opacity-90 disabled:opacity-50">
                    <span x-show="!introspecting">Import Schema</span>
                    <span x-show="introspecting">Reading columns...</span>
                </button>
            </div>
        </div>
    </div>

    <!-- CREATE NEW SLICE MODAL -->
    <div x-show="showNewSliceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" style="display: none;" @keydown.escape.window="showNewSliceModal = false">
        <div class="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-4" @click.outside="showNewSliceModal = false">
            <div class="flex items-center justify-between border-b border-border pb-3">
                <h3 class="font-bold text-foreground text-sm flex items-center gap-2">
                    <svg class="size-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Create New Slice Blueprint
                </h3>
                <button type="button" @click="showNewSliceModal = false" class="text-muted-foreground hover:text-foreground">✕</button>
            </div>
            <p class="text-xs text-muted-foreground">
                Initialize a brand new declarative slice modeler workspace. You can define models, fields, and permissions visually or in YAML.
            </p>

            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1.5">Slice / Module Name *</label>
                    <input type="text" x-model="newSliceName" placeholder="e.g. Deals, Invoices, Tasks, Tickets" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs text-foreground focus:ring-1 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1.5">Domain / Group (Optional)</label>
                    <input type="text" x-model="newSliceDomain" placeholder="e.g. CRM, Billing, Helpdesk, Operations" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-xs text-foreground focus:ring-1 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1.5">Description (Optional)</label>
                    <textarea x-model="newSliceDescription" rows="2" placeholder="Brief summary of what this slice manages..." class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                <button type="button" @click="showNewSliceModal = false" class="rounded-lg border border-border px-3 py-2 text-xs font-semibold text-foreground hover:bg-muted cursor-pointer">
                    Cancel
                </button>
                <button type="button" @click="createNewSlice()" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-xs font-bold text-primary-foreground hover:opacity-90 cursor-pointer">
                    Initialize Slice →
                </button>
            </div>
        </div>
    </div>
</div>

<script>
/* js-yaml is a shared package asset now (GET /laraslice/studio/blueprint/js-yaml),
   lazy-loaded once per page instead of inlined into this panel (~39 KB per load).
   Every jsyaml call site in this panel already guards on typeof jsyaml, and the
   parse/sync funnels re-run themselves when the parser arrives. */
window.__loadJsYaml = window.__loadJsYaml || function () {
    if (window.jsyaml) return Promise.resolve(window.jsyaml);
    if (window.__jsYamlPromise) return window.__jsYamlPromise;
    window.__jsYamlPromise = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = '/laraslice/studio/blueprint/js-yaml';
        s.onload = function () { window.jsyaml ? resolve(window.jsyaml) : reject(new Error('js-yaml asset did not define the parser')); };
        s.onerror = function () { reject(new Error('js-yaml failed to load')); };
        document.head.appendChild(s);
    });
    return window.__jsYamlPromise;
};
window.__loadJsYaml();
</script>
{{-- The blueprintStudio component factory and the presets loader live in
     the shared package asset resources/assets/design-factory.js, served
     cacheable at /laraslice/studio/blueprint/design-factory. The Studio
     shell loads it before this panel initializes (mountDesign awaits
     window.__loadDesignFactory), so the fragment ships markup + data only. --}}
