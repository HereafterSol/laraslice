@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('title', 'Blueprint Studio · Low-Code Slice Modeler')

@section('content')
<main class="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8" x-data="blueprintStudio({
    initialSource: @js(old('source', $source ?? '')),
    initialFormat: @js(old('format', $format ?? 'yaml')),
    initialBlueprint: @js($blueprintData ?? null),
    csrfToken: '{{ csrf_token() }}',
    introspectUrl: '{{ route('laraslice.wizard.blueprint.introspect') }}',
    studioUrl: '{{ route('laraslice.wizard.blueprint') }}',
    allSlicesJson: @js($allSlicesJson ?? null),
    activeSliceIdx: @js($activeSliceIdx ?? 0),
    initialPlanScope: @js($planScope ?? null),
    initialPortPlan: @js($portPlan ?? null)
})">

    <!-- Top Action & Navigation Bar -->
    <header class="flex flex-col gap-4 border-b border-border pb-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <a href="{{ route('laraslice.wizard') }}" class="hover:text-foreground font-medium">← Scaffold Wizard</a>
                <span>/</span>
                <a href="{{ route('laraslice.wizard.studio') }}" class="hover:text-foreground font-medium">Slice Studio</a>
                <span>/</span>
                <span class="text-foreground font-medium">Blueprint Low-Code Studio</span>
                <span>/</span>
                <a href="{{ route('laraslice.wizard.schema_studio') }}" class="hover:text-foreground font-medium">Schema Studio</a>
                <span class="text-muted-foreground/30">•</span>
                <a href="https://hereaftersol.com" target="_blank" class="text-primary hover:underline font-semibold">Hereafter Solutions</a>
                <span class="text-muted-foreground/30">•</span>
                <a href="https://brandup247.com" target="_blank" class="hover:text-foreground">BrandUp</a>
            </div>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-foreground flex items-center gap-3">
                Blueprint Studio
                <span class="inline-flex items-center rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-500">
                    Declarative YAML & Visual
                </span>
            </h1>
        <p class="text-xs text-muted-foreground mt-2 max-w-3xl">Blueprint Studio is for plan/apply of new domains. Day-to-day edits, history, and YAML↔DB sync live in <a href="{{ route('laraslice.wizard.studio') }}" class="text-primary font-semibold underline">Slice Studio</a>.</p>
            <p class="mt-0.5 text-sm text-muted-foreground">
                Dual Visual Designer & YAML Modeler with October-style DB introspection, Statamic field widths, and durable slice.yaml persistence.
            </p>
        </div>

        <!-- Controls: Preset selector, Template selector, Slice selector, View mode -->
        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Preset & Archetype Chooser (Matching Porting Studio) -->
            <div class="relative">
                <select x-model="selectedPreset" @change="choosePreset($event.target.value)" 
                        class="h-9 rounded-lg border border-indigo-500/40 bg-indigo-500/10 px-3 text-xs font-semibold text-indigo-400 shadow-sm hover:bg-indigo-500/20 focus:outline-none cursor-pointer">
                    <option value="" disabled selected>⚡ Choose Preset / Archetype...</option>
                    <optgroup label="⚖️ Statutory & Dispute Adjudication">
                        <option value="adjudication">⚖️ Dispute Adjudication (7 Slices: Complaints, Hearings, Notices, etc.)</option>
                        <option value="legal_dispute_adjudication">⚖️ Dispute Adjudication Standard Suite (6 Slices)</option>
                    </optgroup>
                    <optgroup label="🏢 Enterprise & Commercial Archetypes">
                        <option value="erp">🏢 ERP Enterprise Operations (23 Slices)</option>
                        <option value="healthcare">🏥 Healthcare & Clinical EMR</option>
                        <option value="ecommerce">🛒 E-Commerce & Retail</option>
                        <option value="edtech">🎓 EdTech & Learning (LMS)</option>
                        <option value="fintech">💳 FinTech & Core Banking</option>
                    </optgroup>
                    <template x-if="savedPresets && savedPresets.length > 0">
                        <optgroup label="💾 Saved Custom Presets">
                            <template x-for="p in savedPresets" :key="p.slug">
                                <option :value="'saved:' + p.slug" x-text="'💾 ' + (p.name || p.slug)"></option>
                            </template>
                        </optgroup>
                    </template>
                </select>
            </div>

            <!-- Template Quick Load -->
            <div class="relative">
                <select @change="loadStudioPreset($event.target.value); $event.target.value = '';" class="h-9 rounded-lg border border-input bg-card px-3 text-xs font-medium text-foreground shadow-sm hover:bg-accent/50 focus:outline-none">
                    <option value="" disabled selected>Load Example Template / Suite...</option>
                    <optgroup label="⚖️ Enterprise Judicial & Workflow Suites">
                        <option value="legal_dispute_adjudication">⚖️ Dispute Adjudication Suite (6 Slices: Complaints, Hearings, Notices, Orders, Inquiries, MasterData)</option>
                        <option value="grant_subsidy_pipeline">💰 Grant & Capital Disbursement (2 Slices: Grants, Disbursements)</option>
                        <option value="executive_procurement_approval">📋 Executive Procurement & PO (2 Slices: Requisitions, PurchaseOrders)</option>
                        <option value="support_service_ticket">🎫 Support Service & Escalation (2 Slices: Tickets, Escalations)</option>
                    </optgroup>
                    <optgroup label="📦 Multi-Slice Domain Suites">
                        <option value="crm_suite">💼 CRM Suite (3 Slices: Companies, Contacts, Deals)</option>
                        <option value="ecommerce_suite">🛒 E-Commerce Suite (4 Slices: Products, Orders, Customers, Categories)</option>
                        <option value="billing_suite">💳 Billing Suite (3 Slices: Invoices, Payments, Subscriptions)</option>
                        <option value="asset_inventory">📦 Asset & Inventory Suite (2 Slices: Assets, Maintenance)</option>
                    </optgroup>
                    <optgroup label="📄 Single Slice Blueprints">
                        <option value="service-desk">🎫 Service Desk (Tickets & Replies)</option>
                        <option value="hr-module">👥 HR & Staff (Departments & Employees)</option>
                        <option value="blog">📝 Articles & Comments</option>
                        <option value="shop">🛍️ Products & Variants</option>
                        <option value="shop-orders">📦 Orders & Items</option>
                        <option value="shop-categories">🏷️ Shop Categories</option>
                        <option value="crm">🏢 Companies & Contacts</option>
                        <option value="helpdesk">🎫 Helpdesk Support Ticket</option>
                    </optgroup>
                </select>
            </div>

            <!-- Installed Domains & Slices -->
            @if (!empty($installedSlices))
                @php
                    $groupedSlices = collect($installedSlices)->groupBy(fn($s) => !empty($s['domain']) ? $s['domain'] : 'General');
                @endphp
                <div class="relative">
                    <select @change="loadSlice($event.target.value)" class="h-9 rounded-lg border border-input bg-card px-3 text-xs font-medium text-foreground shadow-sm hover:bg-accent/50 focus:outline-none">
                        <option value="" disabled {{ empty($currentSlice) ? 'selected' : '' }}>Installed Domain / Slice...</option>
                        @foreach ($groupedSlices as $domainName => $domainSlices)
                            <optgroup label="📁 {{ $domainName }}">
                                @foreach ($domainSlices as $s)
                                    <option value="{{ $s['name'] }}" @selected(($currentSlice ?? '') === $s['name'])>
                                        {{ $s['name'] }} {{ $s['has_yaml'] ? '(yaml)' : '' }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
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
            <button type="button" @click="loadPortedToStudio()" x-show="portPlanData || portInspectionData || hasStoredPortPlan()" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 text-xs font-semibold text-emerald-400 hover:bg-emerald-500/20 transition cursor-pointer" title="Load entire ported suite directly into Blueprint Studio canvas">
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

                        <!-- Import YAML Button -->
            <button type="button" @click="showImportYamlModal = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground hover:bg-accent transition shadow-xs cursor-pointer" title="Import or paste full Domain or Slice YAML">
                <svg class="size-3.5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                <span>📥 Import YAML</span>
            </button>

            <!-- Introspect DB Button -->
            <button type="button" @click="showIntrospectModal = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/10 px-3 text-xs font-semibold text-primary hover:bg-primary/20 transition">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7h16m-8 4v6m-3-3h6"/></svg>
                ✨ Add from DB
            </button>

            <!-- 1-Click Run Migrations Button -->
            <form method="POST" action="{{ route('laraslice.wizard.blueprint.migrate') }}" class="inline">
                @csrf
                <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 text-xs font-semibold text-amber-600 hover:bg-amber-500/20 dark:text-amber-400 transition" title="Run php artisan migrate immediately">
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    ⚡ Run Migrations
                </button>
            </form>
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
                    <h2 class="text-sm font-bold uppercase tracking-wider text-muted-foreground mb-4">Slice Definition</h2>
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
                            <h2 class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
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
                                :class="{
                                    'border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-400': perm.endsWith('.view'),
                                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400': perm.endsWith('.create'),
                                    'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400': perm.endsWith('.edit'),
                                    'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400': perm.endsWith('.delete'),
                                    'border-purple-500/30 bg-purple-500/10 text-purple-600 dark:text-purple-400': !perm.endsWith('.view') && !perm.endsWith('.create') && !perm.endsWith('.edit') && !perm.endsWith('.delete')
                                }">
                                <span class="size-1.5 rounded-full"
                                    :class="{
                                        'bg-blue-500': perm.endsWith('.view'),
                                        'bg-emerald-500': perm.endsWith('.create'),
                                        'bg-amber-500': perm.endsWith('.edit'),
                                        'bg-rose-500': perm.endsWith('.delete'),
                                        'bg-purple-500': !perm.endsWith('.view') && !perm.endsWith('.create') && !perm.endsWith('.edit') && !perm.endsWith('.delete')
                                    }"></span>
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
                            <h2 class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
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

                                <!-- Fields Header -->
                                <div class="px-5 pt-4 pb-2">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Fields</h4>
                                </div>

                            <!-- Fields Container: High-Density Filament / October Table -->
                            <div class="px-4 sm:px-5 pb-4 sm:pb-5 space-y-3">
                                <div class="overflow-x-auto rounded-xl border border-border">
                                    <table class="w-full text-left text-xs">
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
                                                <tr class="hover:bg-muted/20 transition-colors group">
                                                    <!-- Move buttons -->
                                                    <td class="px-2 py-2 text-center text-muted-foreground">
                                                        <div class="flex items-center justify-center gap-0.5">
                                                            <button type="button" @click="moveFieldUp(mIdx, fIdx)" :disabled="fIdx === 0" title="Move Up" class="p-0.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition">↑</button>
                                                            <button type="button" @click="moveFieldDown(mIdx, fIdx)" :disabled="fIdx === model.fields.length - 1" title="Move Down" class="p-0.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-20 transition">↓</button>
                                                        </div>
                                                    </td>
                                                    <!-- Handle -->
                                                    <td class="px-3 py-2">
                                                        <input type="text" x-model="field.handle" @input="syncToYaml()" placeholder="e.g. first_name" class="h-7 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 font-mono text-xs font-semibold text-foreground focus:bg-background focus:outline-none">
                                                    </td>
                                                    <!-- Label -->
                                                    <td class="px-3 py-2">
                                                        <input type="text" x-model="field.label" @input="syncToYaml()" placeholder="e.g. First Name" class="h-7 w-full rounded border border-transparent hover:border-input focus:border-input bg-transparent px-1.5 text-xs text-foreground focus:bg-background focus:outline-none">
                                                    </td>
                                                    <!-- Type -->
                                                    <td class="px-3 py-2">
                                                        <select x-model="field.type" @change="syncToYaml()" class="h-7 w-full rounded border border-input bg-card px-2 text-xs font-medium text-foreground focus:ring-1 focus:ring-primary">
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
                                <h2 class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
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
                                                            @foreach ($installedSlices as $s)
                                                            <option value="{{ Str::snake($s['name']) }}">{{ $s['name'] }}</option>
                                                            @endforeach
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
                                <h2 class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
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
                                <h2 class="text-sm font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
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
                                <h2 class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                                    <span class="flex size-7 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-sm">📄</span>
                                    <span>Page & UI Architecture Plan</span>
                                    <span class="rounded-full bg-indigo-500/10 px-2 py-0.5 text-[10px] font-bold text-indigo-400">BlatUI 4-Step Methodology</span>
                                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400" x-text="(blueprint.name || 'Slice') + ' Workspace'"></span>
                                </h2>
                                <p class="text-xs text-muted-foreground mt-0.5">
                                    Canonical screen blueprints for porting legacy views into modern, high-density BlatUI templates (Blade + Alpine.js + Tailwind CSS v4 + Lucide icons).
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
                                    <span class="text-[10px] font-mono text-indigo-400">BlatUI MetricCards</span>
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
                                                    <option value="code_badge">🏷️ Code Badge (#ID)</option>
                                                    <option value="composite_user">👤 Composite User (Avatar + Name)</option>
                                                    <option value="status_pill">🟢 Status Pill (Dot + Badge)</option>
                                                    <option value="money_breakdown">💰 Money Breakdown</option>
                                                    <option value="progress_bar">📊 Progress Bar (%)</option>
                                                    <option value="date_time">📅 Date & Time</option>
                                                    <option value="text">📄 Plain Text</option>
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

            <!-- YAML SOURCE EDITOR PANEL -->
            <div x-show="mode === 'yaml' || mode === 'split'" class="space-y-4 min-w-0">
                <div class="rounded-2xl border border-border bg-card shadow-sm overflow-hidden flex flex-col h-full">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-3">
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-emerald-500"></span>
                            <h2 class="text-xs font-bold uppercase tracking-wider text-muted-foreground" x-text="yamlViewMode === 'domain' ? 'Unified Domain Blueprint (' + (blueprint.domain || portDomain || 'domain') + '.yaml)' : 'Declarative Blueprint (' + (blueprint.handle || 'slice') + '.yaml)'"></h2>
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
                        <h3 class="mt-4 font-bold text-foreground">Generation Plan Ready</h3>
                        <p class="mx-auto mt-1 max-w-xs text-xs text-muted-foreground leading-relaxed">
                            Click 'Validate & Plan Generation' to inspect model structure, database migrations, and required file changes.
                        </p>
                    </div>
                @endif
            </div>
        </div>

        @include('laraslice::partials.porting-modal')

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
                    @foreach ($dbTables as $tbl)
                        <option value="{{ $tbl }}">{{ $tbl }}</option>
                    @endforeach
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
</main>

<script>
/*! js-yaml 4.1.0 https://github.com/nodeca/js-yaml @license MIT */
!function(e,t){"object"==typeof exports&&"undefined"!=typeof module?t(exports):"function"==typeof define&&define.amd?define(["exports"],t):t((e="undefined"!=typeof globalThis?globalThis:e||self).jsyaml={})}(this,(function(e){"use strict";function t(e){return null==e}var n={isNothing:t,isObject:function(e){return"object"==typeof e&&null!==e},toArray:function(e){return Array.isArray(e)?e:t(e)?[]:[e]},repeat:function(e,t){var n,i="";for(n=0;n<t;n+=1)i+=e;return i},isNegativeZero:function(e){return 0===e&&Number.NEGATIVE_INFINITY===1/e},extend:function(e,t){var n,i,r,o;if(t)for(n=0,i=(o=Object.keys(t)).length;n<i;n+=1)e[r=o[n]]=t[r];return e}};function i(e,t){var n="",i=e.reason||"(unknown reason)";return e.mark?(e.mark.name&&(n+='in "'+e.mark.name+'" '),n+="("+(e.mark.line+1)+":"+(e.mark.column+1)+")",!t&&e.mark.snippet&&(n+="\n\n"+e.mark.snippet),i+" "+n):i}function r(e,t){Error.call(this),this.name="YAMLException",this.reason=e,this.mark=t,this.message=i(this,!1),Error.captureStackTrace?Error.captureStackTrace(this,this.constructor):this.stack=(new Error).stack||""}r.prototype=Object.create(Error.prototype),r.prototype.constructor=r,r.prototype.toString=function(e){return this.name+": "+i(this,e)};var o=r;function a(e,t,n,i,r){var o="",a="",l=Math.floor(r/2)-1;return i-t>l&&(t=i-l+(o=" ... ").length),n-i>l&&(n=i+l-(a=" ...").length),{str:o+e.slice(t,n).replace(/\t/g,"→")+a,pos:i-t+o.length}}function l(e,t){return n.repeat(" ",t-e.length)+e}var c=function(e,t){if(t=Object.create(t||null),!e.buffer)return null;t.maxLength||(t.maxLength=79),"number"!=typeof t.indent&&(t.indent=1),"number"!=typeof t.linesBefore&&(t.linesBefore=3),"number"!=typeof t.linesAfter&&(t.linesAfter=2);for(var i,r=/\r?\n|\r|\0/g,o=[0],c=[],s=-1;i=r.exec(e.buffer);)c.push(i.index),o.push(i.index+i[0].length),e.position<=i.index&&s<0&&(s=o.length-2);s<0&&(s=o.length-1);var u,p,f="",d=Math.min(e.line+t.linesAfter,c.length).toString().length,h=t.maxLength-(t.indent+d+3);for(u=1;u<=t.linesBefore&&!(s-u<0);u++)p=a(e.buffer,o[s-u],c[s-u],e.position-(o[s]-o[s-u]),h),f=n.repeat(" ",t.indent)+l((e.line-u+1).toString(),d)+" | "+p.str+"\n"+f;for(p=a(e.buffer,o[s],c[s],e.position,h),f+=n.repeat(" ",t.indent)+l((e.line+1).toString(),d)+" | "+p.str+"\n",f+=n.repeat("-",t.indent+d+3+p.pos)+"^\n",u=1;u<=t.linesAfter&&!(s+u>=c.length);u++)p=a(e.buffer,o[s+u],c[s+u],e.position-(o[s]-o[s+u]),h),f+=n.repeat(" ",t.indent)+l((e.line+u+1).toString(),d)+" | "+p.str+"\n";return f.replace(/\n$/,"")},s=["kind","multi","resolve","construct","instanceOf","predicate","represent","representName","defaultStyle","styleAliases"],u=["scalar","sequence","mapping"];var p=function(e,t){if(t=t||{},Object.keys(t).forEach((function(t){if(-1===s.indexOf(t))throw new o('Unknown option "'+t+'" is met in definition of "'+e+'" YAML type.')})),this.options=t,this.tag=e,this.kind=t.kind||null,this.resolve=t.resolve||function(){return!0},this.construct=t.construct||function(e){return e},this.instanceOf=t.instanceOf||null,this.predicate=t.predicate||null,this.represent=t.represent||null,this.representName=t.representName||null,this.defaultStyle=t.defaultStyle||null,this.multi=t.multi||!1,this.styleAliases=function(e){var t={};return null!==e&&Object.keys(e).forEach((function(n){e[n].forEach((function(e){t[String(e)]=n}))})),t}(t.styleAliases||null),-1===u.indexOf(this.kind))throw new o('Unknown kind "'+this.kind+'" is specified for "'+e+'" YAML type.')};function f(e,t){var n=[];return e[t].forEach((function(e){var t=n.length;n.forEach((function(n,i){n.tag===e.tag&&n.kind===e.kind&&n.multi===e.multi&&(t=i)})),n[t]=e})),n}function d(e){return this.extend(e)}d.prototype.extend=function(e){var t=[],n=[];if(e instanceof p)n.push(e);else if(Array.isArray(e))n=n.concat(e);else{if(!e||!Array.isArray(e.implicit)&&!Array.isArray(e.explicit))throw new o("Schema.extend argument should be a Type, [ Type ], or a schema definition ({ implicit: [...], explicit: [...] })");e.implicit&&(t=t.concat(e.implicit)),e.explicit&&(n=n.concat(e.explicit))}t.forEach((function(e){if(!(e instanceof p))throw new o("Specified list of YAML types (or a single Type object) contains a non-Type object.");if(e.loadKind&&"scalar"!==e.loadKind)throw new o("There is a non-scalar type in the implicit list of a schema. Implicit resolving of such types is not supported.");if(e.multi)throw new o("There is a multi type in the implicit list of a schema. Multi tags can only be listed as explicit.")})),n.forEach((function(e){if(!(e instanceof p))throw new o("Specified list of YAML types (or a single Type object) contains a non-Type object.")}));var i=Object.create(d.prototype);return i.implicit=(this.implicit||[]).concat(t),i.explicit=(this.explicit||[]).concat(n),i.compiledImplicit=f(i,"implicit"),i.compiledExplicit=f(i,"explicit"),i.compiledTypeMap=function(){var e,t,n={scalar:{},sequence:{},mapping:{},fallback:{},multi:{scalar:[],sequence:[],mapping:[],fallback:[]}};function i(e){e.multi?(n.multi[e.kind].push(e),n.multi.fallback.push(e)):n[e.kind][e.tag]=n.fallback[e.tag]=e}for(e=0,t=arguments.length;e<t;e+=1)arguments[e].forEach(i);return n}(i.compiledImplicit,i.compiledExplicit),i};var h=d,g=new p("tag:yaml.org,2002:str",{kind:"scalar",construct:function(e){return null!==e?e:""}}),m=new p("tag:yaml.org,2002:seq",{kind:"sequence",construct:function(e){return null!==e?e:[]}}),y=new p("tag:yaml.org,2002:map",{kind:"mapping",construct:function(e){return null!==e?e:{}}}),b=new h({explicit:[g,m,y]});var A=new p("tag:yaml.org,2002:null",{kind:"scalar",resolve:function(e){if(null===e)return!0;var t=e.length;return 1===t&&"~"===e||4===t&&("null"===e||"Null"===e||"NULL"===e)},construct:function(){return null},predicate:function(e){return null===e},represent:{canonical:function(){return"~"},lowercase:function(){return"null"},uppercase:function(){return"NULL"},camelcase:function(){return"Null"},empty:function(){return""}},defaultStyle:"lowercase"});var v=new p("tag:yaml.org,2002:bool",{kind:"scalar",resolve:function(e){if(null===e)return!1;var t=e.length;return 4===t&&("true"===e||"True"===e||"TRUE"===e)||5===t&&("false"===e||"False"===e||"FALSE"===e)},construct:function(e){return"true"===e||"True"===e||"TRUE"===e},predicate:function(e){return"[object Boolean]"===Object.prototype.toString.call(e)},represent:{lowercase:function(e){return e?"true":"false"},uppercase:function(e){return e?"TRUE":"FALSE"},camelcase:function(e){return e?"True":"False"}},defaultStyle:"lowercase"});function w(e){return 48<=e&&e<=55}function k(e){return 48<=e&&e<=57}var C=new p("tag:yaml.org,2002:int",{kind:"scalar",resolve:function(e){if(null===e)return!1;var t,n,i=e.length,r=0,o=!1;if(!i)return!1;if("-"!==(t=e[r])&&"+"!==t||(t=e[++r]),"0"===t){if(r+1===i)return!0;if("b"===(t=e[++r])){for(r++;r<i;r++)if("_"!==(t=e[r])){if("0"!==t&&"1"!==t)return!1;o=!0}return o&&"_"!==t}if("x"===t){for(r++;r<i;r++)if("_"!==(t=e[r])){if(!(48<=(n=e.charCodeAt(r))&&n<=57||65<=n&&n<=70||97<=n&&n<=102))return!1;o=!0}return o&&"_"!==t}if("o"===t){for(r++;r<i;r++)if("_"!==(t=e[r])){if(!w(e.charCodeAt(r)))return!1;o=!0}return o&&"_"!==t}}if("_"===t)return!1;for(;r<i;r++)if("_"!==(t=e[r])){if(!k(e.charCodeAt(r)))return!1;o=!0}return!(!o||"_"===t)},construct:function(e){var t,n=e,i=1;if(-1!==n.indexOf("_")&&(n=n.replace(/_/g,"")),"-"!==(t=n[0])&&"+"!==t||("-"===t&&(i=-1),t=(n=n.slice(1))[0]),"0"===n)return 0;if("0"===t){if("b"===n[1])return i*parseInt(n.slice(2),2);if("x"===n[1])return i*parseInt(n.slice(2),16);if("o"===n[1])return i*parseInt(n.slice(2),8)}return i*parseInt(n,10)},predicate:function(e){return"[object Number]"===Object.prototype.toString.call(e)&&e%1==0&&!n.isNegativeZero(e)},represent:{binary:function(e){return e>=0?"0b"+e.toString(2):"-0b"+e.toString(2).slice(1)},octal:function(e){return e>=0?"0o"+e.toString(8):"-0o"+e.toString(8).slice(1)},decimal:function(e){return e.toString(10)},hexadecimal:function(e){return e>=0?"0x"+e.toString(16).toUpperCase():"-0x"+e.toString(16).toUpperCase().slice(1)}},defaultStyle:"decimal",styleAliases:{binary:[2,"bin"],octal:[8,"oct"],decimal:[10,"dec"],hexadecimal:[16,"hex"]}}),x=new RegExp("^(?:[-+]?(?:[0-9][0-9_]*)(?:\\.[0-9_]*)?(?:[eE][-+]?[0-9]+)?|\\.[0-9_]+(?:[eE][-+]?[0-9]+)?|[-+]?\\.(?:inf|Inf|INF)|\\.(?:nan|NaN|NAN))$");var I=/^[-+]?[0-9]+e/;var S=new p("tag:yaml.org,2002:float",{kind:"scalar",resolve:function(e){return null!==e&&!(!x.test(e)||"_"===e[e.length-1])},construct:function(e){var t,n;return n="-"===(t=e.replace(/_/g,"").toLowerCase())[0]?-1:1,"+-".indexOf(t[0])>=0&&(t=t.slice(1)),".inf"===t?1===n?Number.POSITIVE_INFINITY:Number.NEGATIVE_INFINITY:".nan"===t?NaN:n*parseFloat(t,10)},predicate:function(e){return"[object Number]"===Object.prototype.toString.call(e)&&(e%1!=0||n.isNegativeZero(e))},represent:function(e,t){var i;if(isNaN(e))switch(t){case"lowercase":return".nan";case"uppercase":return".NAN";case"camelcase":return".NaN"}else if(Number.POSITIVE_INFINITY===e)switch(t){case"lowercase":return".inf";case"uppercase":return".INF";case"camelcase":return".Inf"}else if(Number.NEGATIVE_INFINITY===e)switch(t){case"lowercase":return"-.inf";case"uppercase":return"-.INF";case"camelcase":return"-.Inf"}else if(n.isNegativeZero(e))return"-0.0";return i=e.toString(10),I.test(i)?i.replace("e",".e"):i},defaultStyle:"lowercase"}),O=b.extend({implicit:[A,v,C,S]}),j=O,T=new RegExp("^([0-9][0-9][0-9][0-9])-([0-9][0-9])-([0-9][0-9])$"),N=new RegExp("^([0-9][0-9][0-9][0-9])-([0-9][0-9]?)-([0-9][0-9]?)(?:[Tt]|[ \\t]+)([0-9][0-9]?):([0-9][0-9]):([0-9][0-9])(?:\\.([0-9]*))?(?:[ \\t]*(Z|([-+])([0-9][0-9]?)(?::([0-9][0-9]))?))?$");var F=new p("tag:yaml.org,2002:timestamp",{kind:"scalar",resolve:function(e){return null!==e&&(null!==T.exec(e)||null!==N.exec(e))},construct:function(e){var t,n,i,r,o,a,l,c,s=0,u=null;if(null===(t=T.exec(e))&&(t=N.exec(e)),null===t)throw new Error("Date resolve error");if(n=+t[1],i=+t[2]-1,r=+t[3],!t[4])return new Date(Date.UTC(n,i,r));if(o=+t[4],a=+t[5],l=+t[6],t[7]){for(s=t[7].slice(0,3);s.length<3;)s+="0";s=+s}return t[9]&&(u=6e4*(60*+t[10]+ +(t[11]||0)),"-"===t[9]&&(u=-u)),c=new Date(Date.UTC(n,i,r,o,a,l,s)),u&&c.setTime(c.getTime()-u),c},instanceOf:Date,represent:function(e){return e.toISOString()}});var E=new p("tag:yaml.org,2002:merge",{kind:"scalar",resolve:function(e){return"<<"===e||null===e}}),M="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=\n\r";var L=new p("tag:yaml.org,2002:binary",{kind:"scalar",resolve:function(e){if(null===e)return!1;var t,n,i=0,r=e.length,o=M;for(n=0;n<r;n++)if(!((t=o.indexOf(e.charAt(n)))>64)){if(t<0)return!1;i+=6}return i%8==0},construct:function(e){var t,n,i=e.replace(/[\r\n=]/g,""),r=i.length,o=M,a=0,l=[];for(t=0;t<r;t++)t%4==0&&t&&(l.push(a>>16&255),l.push(a>>8&255),l.push(255&a)),a=a<<6|o.indexOf(i.charAt(t));return 0===(n=r%4*6)?(l.push(a>>16&255),l.push(a>>8&255),l.push(255&a)):18===n?(l.push(a>>10&255),l.push(a>>2&255)):12===n&&l.push(a>>4&255),new Uint8Array(l)},predicate:function(e){return"[object Uint8Array]"===Object.prototype.toString.call(e)},represent:function(e){var t,n,i="",r=0,o=e.length,a=M;for(t=0;t<o;t++)t%3==0&&t&&(i+=a[r>>18&63],i+=a[r>>12&63],i+=a[r>>6&63],i+=a[63&r]),r=(r<<8)+e[t];return 0===(n=o%3)?(i+=a[r>>18&63],i+=a[r>>12&63],i+=a[r>>6&63],i+=a[63&r]):2===n?(i+=a[r>>10&63],i+=a[r>>4&63],i+=a[r<<2&63],i+=a[64]):1===n&&(i+=a[r>>2&63],i+=a[r<<4&63],i+=a[64],i+=a[64]),i}}),_=Object.prototype.hasOwnProperty,D=Object.prototype.toString;var U=new p("tag:yaml.org,2002:omap",{kind:"sequence",resolve:function(e){if(null===e)return!0;var t,n,i,r,o,a=[],l=e;for(t=0,n=l.length;t<n;t+=1){if(i=l[t],o=!1,"[object Object]"!==D.call(i))return!1;for(r in i)if(_.call(i,r)){if(o)return!1;o=!0}if(!o)return!1;if(-1!==a.indexOf(r))return!1;a.push(r)}return!0},construct:function(e){return null!==e?e:[]}}),q=Object.prototype.toString;var Y=new p("tag:yaml.org,2002:pairs",{kind:"sequence",resolve:function(e){if(null===e)return!0;var t,n,i,r,o,a=e;for(o=new Array(a.length),t=0,n=a.length;t<n;t+=1){if(i=a[t],"[object Object]"!==q.call(i))return!1;if(1!==(r=Object.keys(i)).length)return!1;o[t]=[r[0],i[r[0]]]}return!0},construct:function(e){if(null===e)return[];var t,n,i,r,o,a=e;for(o=new Array(a.length),t=0,n=a.length;t<n;t+=1)i=a[t],r=Object.keys(i),o[t]=[r[0],i[r[0]]];return o}}),R=Object.prototype.hasOwnProperty;var B=new p("tag:yaml.org,2002:set",{kind:"mapping",resolve:function(e){if(null===e)return!0;var t,n=e;for(t in n)if(R.call(n,t)&&null!==n[t])return!1;return!0},construct:function(e){return null!==e?e:{}}}),K=j.extend({implicit:[F,E],explicit:[L,U,Y,B]}),P=Object.prototype.hasOwnProperty,W=/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x84\x86-\x9F\uFFFE\uFFFF]|[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?:[^\uD800-\uDBFF]|^)[\uDC00-\uDFFF]/,H=/[\x85\u2028\u2029]/,$=/[,\[\]\{\}]/,G=/^(?:!|!!|![a-z\-]+!)$/i,V=/^(?:!|[^,\[\]\{\}])(?:%[0-9a-f]{2}|[0-9a-z\-#;\/\?:@&=\+\$,_\.!~\*'\(\)\[\]])*$/i;function Z(e){return Object.prototype.toString.call(e)}function J(e){return 10===e||13===e}function Q(e){return 9===e||32===e}function z(e){return 9===e||32===e||10===e||13===e}function X(e){return 44===e||91===e||93===e||123===e||125===e}function ee(e){var t;return 48<=e&&e<=57?e-48:97<=(t=32|e)&&t<=102?t-97+10:-1}function te(e){return 48===e?"\0":97===e?"":98===e?"\b":116===e||9===e?"\t":110===e?"\n":118===e?"\v":102===e?"\f":114===e?"\r":101===e?"":32===e?" ":34===e?'"':47===e?"/":92===e?"\\":78===e?"":95===e?" ":76===e?"\u2028":80===e?"\u2029":""}function ne(e){return e<=65535?String.fromCharCode(e):String.fromCharCode(55296+(e-65536>>10),56320+(e-65536&1023))}for(var ie=new Array(256),re=new Array(256),oe=0;oe<256;oe++)ie[oe]=te(oe)?1:0,re[oe]=te(oe);function ae(e,t){this.input=e,this.filename=t.filename||null,this.schema=t.schema||K,this.onWarning=t.onWarning||null,this.legacy=t.legacy||!1,this.json=t.json||!1,this.listener=t.listener||null,this.implicitTypes=this.schema.compiledImplicit,this.typeMap=this.schema.compiledTypeMap,this.length=e.length,this.position=0,this.line=0,this.lineStart=0,this.lineIndent=0,this.firstTabInLine=-1,this.documents=[]}function le(e,t){var n={name:e.filename,buffer:e.input.slice(0,-1),position:e.position,line:e.line,column:e.position-e.lineStart};return n.snippet=c(n),new o(t,n)}function ce(e,t){throw le(e,t)}function se(e,t){e.onWarning&&e.onWarning.call(null,le(e,t))}var ue={YAML:function(e,t,n){var i,r,o;null!==e.version&&ce(e,"duplication of %YAML directive"),1!==n.length&&ce(e,"YAML directive accepts exactly one argument"),null===(i=/^([0-9]+)\.([0-9]+)$/.exec(n[0]))&&ce(e,"ill-formed argument of the YAML directive"),r=parseInt(i[1],10),o=parseInt(i[2],10),1!==r&&ce(e,"unacceptable YAML version of the document"),e.version=n[0],e.checkLineBreaks=o<2,1!==o&&2!==o&&se(e,"unsupported YAML version of the document")},TAG:function(e,t,n){var i,r;2!==n.length&&ce(e,"TAG directive accepts exactly two arguments"),i=n[0],r=n[1],G.test(i)||ce(e,"ill-formed tag handle (first argument) of the TAG directive"),P.call(e.tagMap,i)&&ce(e,'there is a previously declared suffix for "'+i+'" tag handle'),V.test(r)||ce(e,"ill-formed tag prefix (second argument) of the TAG directive");try{r=decodeURIComponent(r)}catch(t){ce(e,"tag prefix is malformed: "+r)}e.tagMap[i]=r}};function pe(e,t,n,i){var r,o,a,l;if(t<n){if(l=e.input.slice(t,n),i)for(r=0,o=l.length;r<o;r+=1)9===(a=l.charCodeAt(r))||32<=a&&a<=1114111||ce(e,"expected valid JSON character");else W.test(l)&&ce(e,"the stream contains non-printable characters");e.result+=l}}function fe(e,t,i,r){var o,a,l,c;for(n.isObject(i)||ce(e,"cannot merge mappings; the provided source object is unacceptable"),l=0,c=(o=Object.keys(i)).length;l<c;l+=1)a=o[l],P.call(t,a)||(t[a]=i[a],r[a]=!0)}function de(e,t,n,i,r,o,a,l,c){var s,u;if(Array.isArray(r))for(s=0,u=(r=Array.prototype.slice.call(r)).length;s<u;s+=1)Array.isArray(r[s])&&ce(e,"nested arrays are not supported inside keys"),"object"==typeof r&&"[object Object]"===Z(r[s])&&(r[s]="[object Object]");if("object"==typeof r&&"[object Object]"===Z(r)&&(r="[object Object]"),r=String(r),null===t&&(t={}),"tag:yaml.org,2002:merge"===i)if(Array.isArray(o))for(s=0,u=o.length;s<u;s+=1)fe(e,t,o[s],n);else fe(e,t,o,n);else e.json||P.call(n,r)||!P.call(t,r)||(e.line=a||e.line,e.lineStart=l||e.lineStart,e.position=c||e.position,ce(e,"duplicated mapping key")),"__proto__"===r?Object.defineProperty(t,r,{configurable:!0,enumerable:!0,writable:!0,value:o}):t[r]=o,delete n[r];return t}function he(e){var t;10===(t=e.input.charCodeAt(e.position))?e.position++:13===t?(e.position++,10===e.input.charCodeAt(e.position)&&e.position++):ce(e,"a line break is expected"),e.line+=1,e.lineStart=e.position,e.firstTabInLine=-1}function ge(e,t,n){for(var i=0,r=e.input.charCodeAt(e.position);0!==r;){for(;Q(r);)9===r&&-1===e.firstTabInLine&&(e.firstTabInLine=e.position),r=e.input.charCodeAt(++e.position);if(t&&35===r)do{r=e.input.charCodeAt(++e.position)}while(10!==r&&13!==r&&0!==r);if(!J(r))break;for(he(e),r=e.input.charCodeAt(e.position),i++,e.lineIndent=0;32===r;)e.lineIndent++,r=e.input.charCodeAt(++e.position)}return-1!==n&&0!==i&&e.lineIndent<n&&se(e,"deficient indentation"),i}function me(e){var t,n=e.position;return!(45!==(t=e.input.charCodeAt(n))&&46!==t||t!==e.input.charCodeAt(n+1)||t!==e.input.charCodeAt(n+2)||(n+=3,0!==(t=e.input.charCodeAt(n))&&!z(t)))}function ye(e,t){1===t?e.result+=" ":t>1&&(e.result+=n.repeat("\n",t-1))}function be(e,t){var n,i,r=e.tag,o=e.anchor,a=[],l=!1;if(-1!==e.firstTabInLine)return!1;for(null!==e.anchor&&(e.anchorMap[e.anchor]=a),i=e.input.charCodeAt(e.position);0!==i&&(-1!==e.firstTabInLine&&(e.position=e.firstTabInLine,ce(e,"tab characters must not be used in indentation")),45===i)&&z(e.input.charCodeAt(e.position+1));)if(l=!0,e.position++,ge(e,!0,-1)&&e.lineIndent<=t)a.push(null),i=e.input.charCodeAt(e.position);else if(n=e.line,we(e,t,3,!1,!0),a.push(e.result),ge(e,!0,-1),i=e.input.charCodeAt(e.position),(e.line===n||e.lineIndent>t)&&0!==i)ce(e,"bad indentation of a sequence entry");else if(e.lineIndent<t)break;return!!l&&(e.tag=r,e.anchor=o,e.kind="sequence",e.result=a,!0)}function Ae(e){var t,n,i,r,o=!1,a=!1;if(33!==(r=e.input.charCodeAt(e.position)))return!1;if(null!==e.tag&&ce(e,"duplication of a tag property"),60===(r=e.input.charCodeAt(++e.position))?(o=!0,r=e.input.charCodeAt(++e.position)):33===r?(a=!0,n="!!",r=e.input.charCodeAt(++e.position)):n="!",t=e.position,o){do{r=e.input.charCodeAt(++e.position)}while(0!==r&&62!==r);e.position<e.length?(i=e.input.slice(t,e.position),r=e.input.charCodeAt(++e.position)):ce(e,"unexpected end of the stream within a verbatim tag")}else{for(;0!==r&&!z(r);)33===r&&(a?ce(e,"tag suffix cannot contain exclamation marks"):(n=e.input.slice(t-1,e.position+1),G.test(n)||ce(e,"named tag handle cannot contain such characters"),a=!0,t=e.position+1)),r=e.input.charCodeAt(++e.position);i=e.input.slice(t,e.position),$.test(i)&&ce(e,"tag suffix cannot contain flow indicator characters")}i&&!V.test(i)&&ce(e,"tag name cannot contain such characters: "+i);try{i=decodeURIComponent(i)}catch(t){ce(e,"tag name is malformed: "+i)}return o?e.tag=i:P.call(e.tagMap,n)?e.tag=e.tagMap[n]+i:"!"===n?e.tag="!"+i:"!!"===n?e.tag="tag:yaml.org,2002:"+i:ce(e,'undeclared tag handle "'+n+'"'),!0}function ve(e){var t,n;if(38!==(n=e.input.charCodeAt(e.position)))return!1;for(null!==e.anchor&&ce(e,"duplication of an anchor property"),n=e.input.charCodeAt(++e.position),t=e.position;0!==n&&!z(n)&&!X(n);)n=e.input.charCodeAt(++e.position);return e.position===t&&ce(e,"name of an anchor node must contain at least one character"),e.anchor=e.input.slice(t,e.position),!0}function we(e,t,i,r,o){var a,l,c,s,u,p,f,d,h,g=1,m=!1,y=!1;if(null!==e.listener&&e.listener("open",e),e.tag=null,e.anchor=null,e.kind=null,e.result=null,a=l=c=4===i||3===i,r&&ge(e,!0,-1)&&(m=!0,e.lineIndent>t?g=1:e.lineIndent===t?g=0:e.lineIndent<t&&(g=-1)),1===g)for(;Ae(e)||ve(e);)ge(e,!0,-1)?(m=!0,c=a,e.lineIndent>t?g=1:e.lineIndent===t?g=0:e.lineIndent<t&&(g=-1)):c=!1;if(c&&(c=m||o),1!==g&&4!==i||(d=1===i||2===i?t:t+1,h=e.position-e.lineStart,1===g?c&&(be(e,h)||function(e,t,n){var i,r,o,a,l,c,s,u=e.tag,p=e.anchor,f={},d=Object.create(null),h=null,g=null,m=null,y=!1,b=!1;if(-1!==e.firstTabInLine)return!1;for(null!==e.anchor&&(e.anchorMap[e.anchor]=f),s=e.input.charCodeAt(e.position);0!==s;){if(y||-1===e.firstTabInLine||(e.position=e.firstTabInLine,ce(e,"tab characters must not be used in indentation")),i=e.input.charCodeAt(e.position+1),o=e.line,63!==s&&58!==s||!z(i)){if(a=e.line,l=e.lineStart,c=e.position,!we(e,n,2,!1,!0))break;if(e.line===o){for(s=e.input.charCodeAt(e.position);Q(s);)s=e.input.charCodeAt(++e.position);if(58===s)z(s=e.input.charCodeAt(++e.position))||ce(e,"a whitespace character is expected after the key-value separator within a block mapping"),y&&(de(e,f,d,h,g,null,a,l,c),h=g=m=null),b=!0,y=!1,r=!1,h=e.tag,g=e.result;else{if(!b)return e.tag=u,e.anchor=p,!0;ce(e,"can not read an implicit mapping pair; a colon is missed")}}else{if(!b)return e.tag=u,e.anchor=p,!0;ce(e,"can not read a block mapping entry; a multiline key may not be an implicit key")}}else 63===s?(y&&(de(e,f,d,h,g,null,a,l,c),h=g=m=null),b=!0,y=!0,r=!0):y?(y=!1,r=!0):ce(e,"incomplete explicit mapping pair; a key node is missed; or followed by a non-tabulated empty line"),e.position+=1,s=i;if((e.line===o||e.lineIndent>t)&&(y&&(a=e.line,l=e.lineStart,c=e.position),we(e,t,4,!0,r)&&(y?g=e.result:m=e.result),y||(de(e,f,d,h,g,m,a,l,c),h=g=m=null),ge(e,!0,-1),s=e.input.charCodeAt(e.position)),(e.line===o||e.lineIndent>t)&&0!==s)ce(e,"bad indentation of a mapping entry");else if(e.lineIndent<t)break}return y&&de(e,f,d,h,g,null,a,l,c),b&&(e.tag=u,e.anchor=p,e.kind="mapping",e.result=f),b}(e,h,d))||function(e,t){var n,i,r,o,a,l,c,s,u,p,f,d,h=!0,g=e.tag,m=e.anchor,y=Object.create(null);if(91===(d=e.input.charCodeAt(e.position)))a=93,s=!1,o=[];else{if(123!==d)return!1;a=125,s=!0,o={}}for(null!==e.anchor&&(e.anchorMap[e.anchor]=o),d=e.input.charCodeAt(++e.position);0!==d;){if(ge(e,!0,t),(d=e.input.charCodeAt(e.position))===a)return e.position++,e.tag=g,e.anchor=m,e.kind=s?"mapping":"sequence",e.result=o,!0;h?44===d&&ce(e,"expected the node content, but found ','"):ce(e,"missed comma between flow collection entries"),f=null,l=c=!1,63===d&&z(e.input.charCodeAt(e.position+1))&&(l=c=!0,e.position++,ge(e,!0,t)),n=e.line,i=e.lineStart,r=e.position,we(e,t,1,!1,!0),p=e.tag,u=e.result,ge(e,!0,t),d=e.input.charCodeAt(e.position),!c&&e.line!==n||58!==d||(l=!0,d=e.input.charCodeAt(++e.position),ge(e,!0,t),we(e,t,1,!1,!0),f=e.result),s?de(e,o,y,p,u,f,n,i,r):l?o.push(de(e,null,y,p,u,f,n,i,r)):o.push(u),ge(e,!0,t),44===(d=e.input.charCodeAt(e.position))?(h=!0,d=e.input.charCodeAt(++e.position)):h=!1}ce(e,"unexpected end of the stream within a flow collection")}(e,d)?y=!0:(l&&function(e,t){var i,r,o,a,l,c=1,s=!1,u=!1,p=t,f=0,d=!1;if(124===(a=e.input.charCodeAt(e.position)))r=!1;else{if(62!==a)return!1;r=!0}for(e.kind="scalar",e.result="";0!==a;)if(43===(a=e.input.charCodeAt(++e.position))||45===a)1===c?c=43===a?3:2:ce(e,"repeat of a chomping mode identifier");else{if(!((o=48<=(l=a)&&l<=57?l-48:-1)>=0))break;0===o?ce(e,"bad explicit indentation width of a block scalar; it cannot be less than one"):u?ce(e,"repeat of an indentation width identifier"):(p=t+o-1,u=!0)}if(Q(a)){do{a=e.input.charCodeAt(++e.position)}while(Q(a));if(35===a)do{a=e.input.charCodeAt(++e.position)}while(!J(a)&&0!==a)}for(;0!==a;){for(he(e),e.lineIndent=0,a=e.input.charCodeAt(e.position);(!u||e.lineIndent<p)&&32===a;)e.lineIndent++,a=e.input.charCodeAt(++e.position);if(!u&&e.lineIndent>p&&(p=e.lineIndent),J(a))f++;else{if(e.lineIndent<p){3===c?e.result+=n.repeat("\n",s?1+f:f):1===c&&s&&(e.result+="\n");break}for(r?Q(a)?(d=!0,e.result+=n.repeat("\n",s?1+f:f)):d?(d=!1,e.result+=n.repeat("\n",f+1)):0===f?s&&(e.result+=" "):e.result+=n.repeat("\n",f):e.result+=n.repeat("\n",s?1+f:f),s=!0,u=!0,f=0,i=e.position;!J(a)&&0!==a;)a=e.input.charCodeAt(++e.position);pe(e,i,e.position,!1)}}return!0}(e,d)||function(e,t){var n,i,r;if(39!==(n=e.input.charCodeAt(e.position)))return!1;for(e.kind="scalar",e.result="",e.position++,i=r=e.position;0!==(n=e.input.charCodeAt(e.position));)if(39===n){if(pe(e,i,e.position,!0),39!==(n=e.input.charCodeAt(++e.position)))return!0;i=e.position,e.position++,r=e.position}else J(n)?(pe(e,i,r,!0),ye(e,ge(e,!1,t)),i=r=e.position):e.position===e.lineStart&&me(e)?ce(e,"unexpected end of the document within a single quoted scalar"):(e.position++,r=e.position);ce(e,"unexpected end of the stream within a single quoted scalar")}(e,d)||function(e,t){var n,i,r,o,a,l,c;if(34!==(l=e.input.charCodeAt(e.position)))return!1;for(e.kind="scalar",e.result="",e.position++,n=i=e.position;0!==(l=e.input.charCodeAt(e.position));){if(34===l)return pe(e,n,e.position,!0),e.position++,!0;if(92===l){if(pe(e,n,e.position,!0),J(l=e.input.charCodeAt(++e.position)))ge(e,!1,t);else if(l<256&&ie[l])e.result+=re[l],e.position++;else if((a=120===(c=l)?2:117===c?4:85===c?8:0)>0){for(r=a,o=0;r>0;r--)(a=ee(l=e.input.charCodeAt(++e.position)))>=0?o=(o<<4)+a:ce(e,"expected hexadecimal character");e.result+=ne(o),e.position++}else ce(e,"unknown escape sequence");n=i=e.position}else J(l)?(pe(e,n,i,!0),ye(e,ge(e,!1,t)),n=i=e.position):e.position===e.lineStart&&me(e)?ce(e,"unexpected end of the document within a double quoted scalar"):(e.position++,i=e.position)}ce(e,"unexpected end of the stream within a double quoted scalar")}(e,d)?y=!0:!function(e){var t,n,i;if(42!==(i=e.input.charCodeAt(e.position)))return!1;for(i=e.input.charCodeAt(++e.position),t=e.position;0!==i&&!z(i)&&!X(i);)i=e.input.charCodeAt(++e.position);return e.position===t&&ce(e,"name of an alias node must contain at least one character"),n=e.input.slice(t,e.position),P.call(e.anchorMap,n)||ce(e,'unidentified alias "'+n+'"'),e.result=e.anchorMap[n],ge(e,!0,-1),!0}(e)?function(e,t,n){var i,r,o,a,l,c,s,u,p=e.kind,f=e.result;if(z(u=e.input.charCodeAt(e.position))||X(u)||35===u||38===u||42===u||33===u||124===u||62===u||39===u||34===u||37===u||64===u||96===u)return!1;if((63===u||45===u)&&(z(i=e.input.charCodeAt(e.position+1))||n&&X(i)))return!1;for(e.kind="scalar",e.result="",r=o=e.position,a=!1;0!==u;){if(58===u){if(z(i=e.input.charCodeAt(e.position+1))||n&&X(i))break}else if(35===u){if(z(e.input.charCodeAt(e.position-1)))break}else{if(e.position===e.lineStart&&me(e)||n&&X(u))break;if(J(u)){if(l=e.line,c=e.lineStart,s=e.lineIndent,ge(e,!1,-1),e.lineIndent>=t){a=!0,u=e.input.charCodeAt(e.position);continue}e.position=o,e.line=l,e.lineStart=c,e.lineIndent=s;break}}a&&(pe(e,r,o,!1),ye(e,e.line-l),r=o=e.position,a=!1),Q(u)||(o=e.position+1),u=e.input.charCodeAt(++e.position)}return pe(e,r,o,!1),!!e.result||(e.kind=p,e.result=f,!1)}(e,d,1===i)&&(y=!0,null===e.tag&&(e.tag="?")):(y=!0,null===e.tag&&null===e.anchor||ce(e,"alias node should not have any properties")),null!==e.anchor&&(e.anchorMap[e.anchor]=e.result)):0===g&&(y=c&&be(e,h))),null===e.tag)null!==e.anchor&&(e.anchorMap[e.anchor]=e.result);else if("?"===e.tag){for(null!==e.result&&"scalar"!==e.kind&&ce(e,'unacceptable node kind for !<?> tag; it should be "scalar", not "'+e.kind+'"'),s=0,u=e.implicitTypes.length;s<u;s+=1)if((f=e.implicitTypes[s]).resolve(e.result)){e.result=f.construct(e.result),e.tag=f.tag,null!==e.anchor&&(e.anchorMap[e.anchor]=e.result);break}}else if("!"!==e.tag){if(P.call(e.typeMap[e.kind||"fallback"],e.tag))f=e.typeMap[e.kind||"fallback"][e.tag];else for(f=null,s=0,u=(p=e.typeMap.multi[e.kind||"fallback"]).length;s<u;s+=1)if(e.tag.slice(0,p[s].tag.length)===p[s].tag){f=p[s];break}f||ce(e,"unknown tag !<"+e.tag+">"),null!==e.result&&f.kind!==e.kind&&ce(e,"unacceptable node kind for !<"+e.tag+'> tag; it should be "'+f.kind+'", not "'+e.kind+'"'),f.resolve(e.result,e.tag)?(e.result=f.construct(e.result,e.tag),null!==e.anchor&&(e.anchorMap[e.anchor]=e.result)):ce(e,"cannot resolve a node with !<"+e.tag+"> explicit tag")}return null!==e.listener&&e.listener("close",e),null!==e.tag||null!==e.anchor||y}function ke(e){var t,n,i,r,o=e.position,a=!1;for(e.version=null,e.checkLineBreaks=e.legacy,e.tagMap=Object.create(null),e.anchorMap=Object.create(null);0!==(r=e.input.charCodeAt(e.position))&&(ge(e,!0,-1),r=e.input.charCodeAt(e.position),!(e.lineIndent>0||37!==r));){for(a=!0,r=e.input.charCodeAt(++e.position),t=e.position;0!==r&&!z(r);)r=e.input.charCodeAt(++e.position);for(i=[],(n=e.input.slice(t,e.position)).length<1&&ce(e,"directive name must not be less than one character in length");0!==r;){for(;Q(r);)r=e.input.charCodeAt(++e.position);if(35===r){do{r=e.input.charCodeAt(++e.position)}while(0!==r&&!J(r));break}if(J(r))break;for(t=e.position;0!==r&&!z(r);)r=e.input.charCodeAt(++e.position);i.push(e.input.slice(t,e.position))}0!==r&&he(e),P.call(ue,n)?ue[n](e,n,i):se(e,'unknown document directive "'+n+'"')}ge(e,!0,-1),0===e.lineIndent&&45===e.input.charCodeAt(e.position)&&45===e.input.charCodeAt(e.position+1)&&45===e.input.charCodeAt(e.position+2)?(e.position+=3,ge(e,!0,-1)):a&&ce(e,"directives end mark is expected"),we(e,e.lineIndent-1,4,!1,!0),ge(e,!0,-1),e.checkLineBreaks&&H.test(e.input.slice(o,e.position))&&se(e,"non-ASCII line breaks are interpreted as content"),e.documents.push(e.result),e.position===e.lineStart&&me(e)?46===e.input.charCodeAt(e.position)&&(e.position+=3,ge(e,!0,-1)):e.position<e.length-1&&ce(e,"end of the stream or a document separator is expected")}function Ce(e,t){t=t||{},0!==(e=String(e)).length&&(10!==e.charCodeAt(e.length-1)&&13!==e.charCodeAt(e.length-1)&&(e+="\n"),65279===e.charCodeAt(0)&&(e=e.slice(1)));var n=new ae(e,t),i=e.indexOf("\0");for(-1!==i&&(n.position=i,ce(n,"null byte is not allowed in input")),n.input+="\0";32===n.input.charCodeAt(n.position);)n.lineIndent+=1,n.position+=1;for(;n.position<n.length-1;)ke(n);return n.documents}var xe={loadAll:function(e,t,n){null!==t&&"object"==typeof t&&void 0===n&&(n=t,t=null);var i=Ce(e,n);if("function"!=typeof t)return i;for(var r=0,o=i.length;r<o;r+=1)t(i[r])},load:function(e,t){var n=Ce(e,t);if(0!==n.length){if(1===n.length)return n[0];throw new o("expected a single document in the stream, but found more")}}},Ie=Object.prototype.toString,Se=Object.prototype.hasOwnProperty,Oe=65279,je={0:"\\0",7:"\\a",8:"\\b",9:"\\t",10:"\\n",11:"\\v",12:"\\f",13:"\\r",27:"\\e",34:'\\"',92:"\\\\",133:"\\N",160:"\\_",8232:"\\L",8233:"\\P"},Te=["y","Y","yes","Yes","YES","on","On","ON","n","N","no","No","NO","off","Off","OFF"],Ne=/^[-+]?[0-9_]+(?::[0-9_]+)+(?:\.[0-9_]*)?$/;function Fe(e){var t,i,r;if(t=e.toString(16).toUpperCase(),e<=255)i="x",r=2;else if(e<=65535)i="u",r=4;else{if(!(e<=4294967295))throw new o("code point within a string may not be greater than 0xFFFFFFFF");i="U",r=8}return"\\"+i+n.repeat("0",r-t.length)+t}function Ee(e){this.schema=e.schema||K,this.indent=Math.max(1,e.indent||2),this.noArrayIndent=e.noArrayIndent||!1,this.skipInvalid=e.skipInvalid||!1,this.flowLevel=n.isNothing(e.flowLevel)?-1:e.flowLevel,this.styleMap=function(e,t){var n,i,r,o,a,l,c;if(null===t)return{};for(n={},r=0,o=(i=Object.keys(t)).length;r<o;r+=1)a=i[r],l=String(t[a]),"!!"===a.slice(0,2)&&(a="tag:yaml.org,2002:"+a.slice(2)),(c=e.compiledTypeMap.fallback[a])&&Se.call(c.styleAliases,l)&&(l=c.styleAliases[l]),n[a]=l;return n}(this.schema,e.styles||null),this.sortKeys=e.sortKeys||!1,this.lineWidth=e.lineWidth||80,this.noRefs=e.noRefs||!1,this.noCompatMode=e.noCompatMode||!1,this.condenseFlow=e.condenseFlow||!1,this.quotingType='"'===e.quotingType?2:1,this.forceQuotes=e.forceQuotes||!1,this.replacer="function"==typeof e.replacer?e.replacer:null,this.implicitTypes=this.schema.compiledImplicit,this.explicitTypes=this.schema.compiledExplicit,this.tag=null,this.result="",this.duplicates=[],this.usedDuplicates=null}function Me(e,t){for(var i,r=n.repeat(" ",t),o=0,a=-1,l="",c=e.length;o<c;)-1===(a=e.indexOf("\n",o))?(i=e.slice(o),o=c):(i=e.slice(o,a+1),o=a+1),i.length&&"\n"!==i&&(l+=r),l+=i;return l}function Le(e,t){return"\n"+n.repeat(" ",e.indent*t)}function _e(e){return 32===e||9===e}function De(e){return 32<=e&&e<=126||161<=e&&e<=55295&&8232!==e&&8233!==e||57344<=e&&e<=65533&&e!==Oe||65536<=e&&e<=1114111}function Ue(e){return De(e)&&e!==Oe&&13!==e&&10!==e}function qe(e,t,n){var i=Ue(e),r=i&&!_e(e);return(n?i:i&&44!==e&&91!==e&&93!==e&&123!==e&&125!==e)&&35!==e&&!(58===t&&!r)||Ue(t)&&!_e(t)&&35===e||58===t&&r}function Ye(e,t){var n,i=e.charCodeAt(t);return i>=55296&&i<=56319&&t+1<e.length&&(n=e.charCodeAt(t+1))>=56320&&n<=57343?1024*(i-55296)+n-56320+65536:i}function Re(e){return/^\n* /.test(e)}function Be(e,t,n,i,r,o,a,l){var c,s,u=0,p=null,f=!1,d=!1,h=-1!==i,g=-1,m=De(s=Ye(e,0))&&s!==Oe&&!_e(s)&&45!==s&&63!==s&&58!==s&&44!==s&&91!==s&&93!==s&&123!==s&&125!==s&&35!==s&&38!==s&&42!==s&&33!==s&&124!==s&&61!==s&&62!==s&&39!==s&&34!==s&&37!==s&&64!==s&&96!==s&&function(e){return!_e(e)&&58!==e}(Ye(e,e.length-1));if(t||a)for(c=0;c<e.length;u>=65536?c+=2:c++){if(!De(u=Ye(e,c)))return 5;m=m&&qe(u,p,l),p=u}else{for(c=0;c<e.length;u>=65536?c+=2:c++){if(10===(u=Ye(e,c)))f=!0,h&&(d=d||c-g-1>i&&" "!==e[g+1],g=c);else if(!De(u))return 5;m=m&&qe(u,p,l),p=u}d=d||h&&c-g-1>i&&" "!==e[g+1]}return f||d?n>9&&Re(e)?5:a?2===o?5:2:d?4:3:!m||a||r(e)?2===o?5:2:1}function Ke(e,t,n,i,r){e.dump=function(){if(0===t.length)return 2===e.quotingType?'""':"''";if(!e.noCompatMode&&(-1!==Te.indexOf(t)||Ne.test(t)))return 2===e.quotingType?'"'+t+'"':"'"+t+"'";var a=e.indent*Math.max(1,n),l=-1===e.lineWidth?-1:Math.max(Math.min(e.lineWidth,40),e.lineWidth-a),c=i||e.flowLevel>-1&&n>=e.flowLevel;switch(Be(t,c,e.indent,l,(function(t){return function(e,t){var n,i;for(n=0,i=e.implicitTypes.length;n<i;n+=1)if(e.implicitTypes[n].resolve(t))return!0;return!1}(e,t)}),e.quotingType,e.forceQuotes&&!i,r)){case 1:return t;case 2:return"'"+t.replace(/'/g,"''")+"'";case 3:return"|"+Pe(t,e.indent)+We(Me(t,a));case 4:return">"+Pe(t,e.indent)+We(Me(function(e,t){var n,i,r=/(\n+)([^\n]*)/g,o=(l=e.indexOf("\n"),l=-1!==l?l:e.length,r.lastIndex=l,He(e.slice(0,l),t)),a="\n"===e[0]||" "===e[0];var l;for(;i=r.exec(e);){var c=i[1],s=i[2];n=" "===s[0],o+=c+(a||n||""===s?"":"\n")+He(s,t),a=n}return o}(t,l),a));case 5:return'"'+function(e){for(var t,n="",i=0,r=0;r<e.length;i>=65536?r+=2:r++)i=Ye(e,r),!(t=je[i])&&De(i)?(n+=e[r],i>=65536&&(n+=e[r+1])):n+=t||Fe(i);return n}(t)+'"';default:throw new o("impossible error: invalid scalar style")}}()}function Pe(e,t){var n=Re(e)?String(t):"",i="\n"===e[e.length-1];return n+(i&&("\n"===e[e.length-2]||"\n"===e)?"+":i?"":"-")+"\n"}function We(e){return"\n"===e[e.length-1]?e.slice(0,-1):e}function He(e,t){if(""===e||" "===e[0])return e;for(var n,i,r=/ [^ ]/g,o=0,a=0,l=0,c="";n=r.exec(e);)(l=n.index)-o>t&&(i=a>o?a:l,c+="\n"+e.slice(o,i),o=i+1),a=l;return c+="\n",e.length-o>t&&a>o?c+=e.slice(o,a)+"\n"+e.slice(a+1):c+=e.slice(o),c.slice(1)}function $e(e,t,n,i){var r,o,a,l="",c=e.tag;for(r=0,o=n.length;r<o;r+=1)a=n[r],e.replacer&&(a=e.replacer.call(n,String(r),a)),(Ve(e,t+1,a,!0,!0,!1,!0)||void 0===a&&Ve(e,t+1,null,!0,!0,!1,!0))&&(i&&""===l||(l+=Le(e,t)),e.dump&&10===e.dump.charCodeAt(0)?l+="-":l+="- ",l+=e.dump);e.tag=c,e.dump=l||"[]"}function Ge(e,t,n){var i,r,a,l,c,s;for(a=0,l=(r=n?e.explicitTypes:e.implicitTypes).length;a<l;a+=1)if(((c=r[a]).instanceOf||c.predicate)&&(!c.instanceOf||"object"==typeof t&&t instanceof c.instanceOf)&&(!c.predicate||c.predicate(t))){if(n?c.multi&&c.representName?e.tag=c.representName(t):e.tag=c.tag:e.tag="?",c.represent){if(s=e.styleMap[c.tag]||c.defaultStyle,"[object Function]"===Ie.call(c.represent))i=c.represent(t,s);else{if(!Se.call(c.represent,s))throw new o("!<"+c.tag+'> tag resolver accepts not "'+s+'" style');i=c.represent[s](t,s)}e.dump=i}return!0}return!1}function Ve(e,t,n,i,r,a,l){e.tag=null,e.dump=n,Ge(e,n,!1)||Ge(e,n,!0);var c,s=Ie.call(e.dump),u=i;i&&(i=e.flowLevel<0||e.flowLevel>t);var p,f,d="[object Object]"===s||"[object Array]"===s;if(d&&(f=-1!==(p=e.duplicates.indexOf(n))),(null!==e.tag&&"?"!==e.tag||f||2!==e.indent&&t>0)&&(r=!1),f&&e.usedDuplicates[p])e.dump="*ref_"+p;else{if(d&&f&&!e.usedDuplicates[p]&&(e.usedDuplicates[p]=!0),"[object Object]"===s)i&&0!==Object.keys(e.dump).length?(!function(e,t,n,i){var r,a,l,c,s,u,p="",f=e.tag,d=Object.keys(n);if(!0===e.sortKeys)d.sort();else if("function"==typeof e.sortKeys)d.sort(e.sortKeys);else if(e.sortKeys)throw new o("sortKeys must be a boolean or a function");for(r=0,a=d.length;r<a;r+=1)u="",i&&""===p||(u+=Le(e,t)),c=n[l=d[r]],e.replacer&&(c=e.replacer.call(n,l,c)),Ve(e,t+1,l,!0,!0,!0)&&((s=null!==e.tag&&"?"!==e.tag||e.dump&&e.dump.length>1024)&&(e.dump&&10===e.dump.charCodeAt(0)?u+="?":u+="? "),u+=e.dump,s&&(u+=Le(e,t)),Ve(e,t+1,c,!0,s)&&(e.dump&&10===e.dump.charCodeAt(0)?u+=":":u+=": ",p+=u+=e.dump));e.tag=f,e.dump=p||"{}"}(e,t,e.dump,r),f&&(e.dump="&ref_"+p+e.dump)):(!function(e,t,n){var i,r,o,a,l,c="",s=e.tag,u=Object.keys(n);for(i=0,r=u.length;i<r;i+=1)l="",""!==c&&(l+=", "),e.condenseFlow&&(l+='"'),a=n[o=u[i]],e.replacer&&(a=e.replacer.call(n,o,a)),Ve(e,t,o,!1,!1)&&(e.dump.length>1024&&(l+="? "),l+=e.dump+(e.condenseFlow?'"':"")+":"+(e.condenseFlow?"":" "),Ve(e,t,a,!1,!1)&&(c+=l+=e.dump));e.tag=s,e.dump="{"+c+"}"}(e,t,e.dump),f&&(e.dump="&ref_"+p+" "+e.dump));else if("[object Array]"===s)i&&0!==e.dump.length?(e.noArrayIndent&&!l&&t>0?$e(e,t-1,e.dump,r):$e(e,t,e.dump,r),f&&(e.dump="&ref_"+p+e.dump)):(!function(e,t,n){var i,r,o,a="",l=e.tag;for(i=0,r=n.length;i<r;i+=1)o=n[i],e.replacer&&(o=e.replacer.call(n,String(i),o)),(Ve(e,t,o,!1,!1)||void 0===o&&Ve(e,t,null,!1,!1))&&(""!==a&&(a+=","+(e.condenseFlow?"":" ")),a+=e.dump);e.tag=l,e.dump="["+a+"]"}(e,t,e.dump),f&&(e.dump="&ref_"+p+" "+e.dump));else{if("[object String]"!==s){if("[object Undefined]"===s)return!1;if(e.skipInvalid)return!1;throw new o("unacceptable kind of an object to dump "+s)}"?"!==e.tag&&Ke(e,e.dump,t,a,u)}null!==e.tag&&"?"!==e.tag&&(c=encodeURI("!"===e.tag[0]?e.tag.slice(1):e.tag).replace(/!/g,"%21"),c="!"===e.tag[0]?"!"+c:"tag:yaml.org,2002:"===c.slice(0,18)?"!!"+c.slice(18):"!<"+c+">",e.dump=c+" "+e.dump)}return!0}function Ze(e,t){var n,i,r=[],o=[];for(Je(e,r,o),n=0,i=o.length;n<i;n+=1)t.duplicates.push(r[o[n]]);t.usedDuplicates=new Array(i)}function Je(e,t,n){var i,r,o;if(null!==e&&"object"==typeof e)if(-1!==(r=t.indexOf(e)))-1===n.indexOf(r)&&n.push(r);else if(t.push(e),Array.isArray(e))for(r=0,o=e.length;r<o;r+=1)Je(e[r],t,n);else for(r=0,o=(i=Object.keys(e)).length;r<o;r+=1)Je(e[i[r]],t,n)}function Qe(e,t){return function(){throw new Error("Function yaml."+e+" is removed in js-yaml 4. Use yaml."+t+" instead, which is now safe by default.")}}var ze=p,Xe=h,et=b,tt=O,nt=j,it=K,rt=xe.load,ot=xe.loadAll,at={dump:function(e,t){var n=new Ee(t=t||{});n.noRefs||Ze(e,n);var i=e;return n.replacer&&(i=n.replacer.call({"":i},"",i)),Ve(n,0,i,!0,!0)?n.dump+"\n":""}}.dump,lt=o,ct={binary:L,float:S,map:y,null:A,pairs:Y,set:B,timestamp:F,bool:v,int:C,merge:E,omap:U,seq:m,str:g},st=Qe("safeLoad","load"),ut=Qe("safeLoadAll","loadAll"),pt=Qe("safeDump","dump"),ft={Type:ze,Schema:Xe,FAILSAFE_SCHEMA:et,JSON_SCHEMA:tt,CORE_SCHEMA:nt,DEFAULT_SCHEMA:it,load:rt,loadAll:ot,dump:at,YAMLException:lt,types:ct,safeLoad:st,safeLoadAll:ut,safeDump:pt};e.CORE_SCHEMA=nt,e.DEFAULT_SCHEMA=it,e.FAILSAFE_SCHEMA=et,e.JSON_SCHEMA=tt,e.Schema=Xe,e.Type=ze,e.YAMLException=lt,e.default=ft,e.dump=at,e.load=rt,e.loadAll=ot,e.safeDump=pt,e.safeLoad=st,e.safeLoadAll=ut,e.types=ct,Object.defineProperty(e,"__esModule",{value:!0})}));

</script>
<script>
const studioPresets = {
    legal_dispute_adjudication: [
    {
        "name": "Complaints",
        "handle": "complaints",
        "domain": "Adjudication",
        "blueprint": {
            "schema_version": 1,
            "name": "Complaints",
            "handle": "complaints",
            "domain": "Adjudication",
            "description": "Manages complaints records and associated child items",
            "permissions": [
                "complaints.view",
                "complaints.create",
                "complaints.edit",
                "complaints.delete",
                "complaints.hear",
                "complaints.forward",
                "complaints.decide"
            ],
            "models": [
                {
                    "name": "Complaint",
                    "handle": "complaint",
                    "table": "complaints",
                    "root": true,
                    "timestamps": true,
                    "soft_deletes": true,
                    "description": "Primary aggregate root for Complaints",
                    "fields": [
                        {
                            "handle": "from_role_id",
                            "label": "From Role Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "to_role_id",
                            "label": "To Role Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "from_user_id",
                            "label": "From User Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "to_user_id",
                            "label": "To User Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_status_id",
                            "label": "Complaint Status Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_date",
                            "label": "Complaint Date",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_type_id",
                            "label": "Complaint Type Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_subtype_id",
                            "label": "Complaint Subtype Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_details",
                            "label": "Complaint Details",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_agree",
                            "label": "Is Agree",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "harassment_nature_id",
                            "label": "Harassment Nature Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "employment_nature_id",
                            "label": "Employment Nature Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "organization_type_id",
                            "label": "Organization Type Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complainant_posting_place",
                            "label": "Complainant Posting Place",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "accused_posting_place",
                            "label": "Accused Posting Place",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "region_id",
                            "label": "Region Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_no",
                            "label": "Complaint No",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "cr_complaint_no",
                            "label": "Cr Complaint No",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "diary_date_time",
                            "label": "Diary Date Time",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complainant_name",
                            "label": "Complainant Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complainant_cnic",
                            "label": "Complainant Cnic",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complainant_mobile",
                            "label": "Complainant Mobile",
                            "type": "string",
                            "input_type": "tel",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "receiving_mode",
                            "label": "Receiving Mode",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "courier_postal_name",
                            "label": "Courier Postal Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "courier_postal_number",
                            "label": "Courier Postal Number",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "received_by",
                            "label": "Received By",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "received_by_id",
                            "label": "Received By Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_short_order",
                            "label": "Is Short Order",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_deficient",
                            "label": "Is Deficient",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "status_report_soughts",
                            "label": "Status Report Soughts",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": [
                        {
                            "name": "accused",
                            "type": "hasMany",
                            "model": "accused",
                            "foreign_key": "complaint_id"
                        },
                        {
                            "name": "complaint_properties",
                            "type": "hasMany",
                            "model": "complaint_property",
                            "foreign_key": "complaint_id"
                        },
                        {
                            "name": "complaint_approvals",
                            "type": "hasMany",
                            "model": "complaint_approval",
                            "foreign_key": "complaint_id"
                        },
                        {
                            "name": "complaint_attributes_harassments",
                            "type": "hasMany",
                            "model": "complaint_attributes_harassment",
                            "foreign_key": "complaint_id"
                        },
                        {
                            "name": "complaint_attributes_properties",
                            "type": "hasMany",
                            "model": "complaint_attributes_property",
                            "foreign_key": "complaint_id"
                        },
                        {
                            "name": "complaint_feedbacks",
                            "type": "hasMany",
                            "model": "complaint_feedback",
                            "foreign_key": "complaint_id"
                        }
                    ]
                },
                {
                    "name": "Accused",
                    "handle": "accused",
                    "table": "accused",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of Complaints",
                    "fields": [
                        {
                            "handle": "complaint_id",
                            "label": "Complaint Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_unknown",
                            "label": "Is Unknown",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "father_name",
                            "label": "Father Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "gender_id",
                            "label": "Gender Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "cnic",
                            "label": "Cnic",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "company_name",
                            "label": "Company Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "designation",
                            "label": "Designation",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "department",
                            "label": "Department",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "email",
                            "label": "Email",
                            "type": "string",
                            "input_type": "email",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "mobile_no",
                            "label": "Mobile No",
                            "type": "string",
                            "input_type": "tel",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "landline_no",
                            "label": "Landline No",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "address",
                            "label": "Address",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "province_id",
                            "label": "Province Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "district_id",
                            "label": "District Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "tehsil_id",
                            "label": "Tehsil Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": [
                        {
                            "name": "complaint",
                            "type": "belongsTo",
                            "model": "complaint",
                            "foreign_key": "complaint_id"
                        }
                    ]
                },
                {
                    "name": "ComplaintProperty",
                    "handle": "complaint_property",
                    "table": "complaint_properties",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of Complaints",
                    "fields": [
                        {
                            "handle": "complaint_id",
                            "label": "Complaint Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "district_id",
                            "label": "District Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "acquisition_mode_id",
                            "label": "Acquisition Mode Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "concerned_organization_id",
                            "label": "Concerned Organization Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "property_nature_id",
                            "label": "Property Nature Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "region_id",
                            "label": "Region Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "property_details",
                            "label": "Property Details",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        }
                    ],
                    "relations": [
                        {
                            "name": "complaint",
                            "type": "belongsTo",
                            "model": "complaint",
                            "foreign_key": "complaint_id"
                        }
                    ]
                },
                {
                    "name": "ComplaintApproval",
                    "handle": "complaint_approval",
                    "table": "complaint_approvals",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of Complaints",
                    "fields": [
                        {
                            "handle": "complaint_id",
                            "label": "Complaint Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "from_role_id",
                            "label": "From Role Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "to_role_id",
                            "label": "To Role Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "from_user_id",
                            "label": "From User Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "to_user_id",
                            "label": "To User Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_status_id",
                            "label": "Complaint Status Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "remarks",
                            "label": "Remarks",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_active",
                            "label": "Is Active",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": [
                        {
                            "name": "complaint",
                            "type": "belongsTo",
                            "model": "complaint",
                            "foreign_key": "complaint_id"
                        }
                    ]
                },
                {
                    "name": "ComplaintAttributesHarassment",
                    "handle": "complaint_attributes_harassment",
                    "table": "complaint_attributes_harassments",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of Complaints",
                    "fields": [
                        {
                            "handle": "complaint_id",
                            "label": "Complaint Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_cross_organization",
                            "label": "Is Cross Organization",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "institution_date",
                            "label": "Institution Date",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "decision_date",
                            "label": "Decision Date",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "days_resolved",
                            "label": "Days Resolved",
                            "type": "integer",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "provice_id",
                            "label": "Provice Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "district_id",
                            "label": "District Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "representation_id",
                            "label": "Representation Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "penalty_nature_id",
                            "label": "Penalty Nature Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_status_implemented",
                            "label": "Is Status Implemented",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "implementation_date",
                            "label": "Implementation Date",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "remarks",
                            "label": "Remarks",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "created_by_id",
                            "label": "Created By Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": [
                        {
                            "name": "complaint",
                            "type": "belongsTo",
                            "model": "complaint",
                            "foreign_key": "complaint_id"
                        }
                    ]
                },
                {
                    "name": "ComplaintAttributesProperty",
                    "handle": "complaint_attributes_property",
                    "table": "complaint_attributes_properties",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of Complaints",
                    "fields": [
                        {
                            "handle": "complaint_id",
                            "label": "Complaint Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "micro_category_id",
                            "label": "Micro Category Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "institution_date",
                            "label": "Institution Date",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "decision_date",
                            "label": "Decision Date",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "days_resolved",
                            "label": "Days Resolved",
                            "type": "integer",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "decision_type_id",
                            "label": "Decision Type Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "provice_id",
                            "label": "Provice Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "islamabad_zone",
                            "label": "Islamabad Zone",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "property_valuation",
                            "label": "Property Valuation",
                            "type": "decimal",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_settlement",
                            "label": "Is Settlement",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "property_quantum",
                            "label": "Property Quantum",
                            "type": "decimal",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "representation_id",
                            "label": "Representation Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_status_implemented",
                            "label": "Is Status Implemented",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "implementation_date",
                            "label": "Implementation Date",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "remarks",
                            "label": "Remarks",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "created_by_id",
                            "label": "Created By Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": [
                        {
                            "name": "complaint",
                            "type": "belongsTo",
                            "model": "complaint",
                            "foreign_key": "complaint_id"
                        }
                    ]
                },
                {
                    "name": "ComplaintFeedback",
                    "handle": "complaint_feedback",
                    "table": "complaint_feedbacks",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of Complaints",
                    "fields": [
                        {
                            "handle": "complaint_id",
                            "label": "Complaint Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "star",
                            "label": "Star",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "remarks",
                            "label": "Remarks",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        }
                    ],
                    "relations": [
                        {
                            "name": "complaint",
                            "type": "belongsTo",
                            "model": "complaint",
                            "foreign_key": "complaint_id"
                        }
                    ]
                }
            ],
            "workflow": {
                "enabled": true,
                "state_column": "complaint_status_id",
                "states": [
                    {
                        "slug": "pending",
                        "label": "Pending",
                        "color": "amber"
                    },
                    {
                        "slug": "deficient",
                        "label": "Deficient",
                        "color": "rose"
                    },
                    {
                        "slug": "maintainability",
                        "label": "Maintainability",
                        "color": "blue"
                    },
                    {
                        "slug": "preliminary_hearing",
                        "label": "Preliminary Hearing",
                        "color": "purple"
                    },
                    {
                        "slug": "notice_to_respondent",
                        "label": "Notice to Respondent",
                        "color": "indigo"
                    },
                    {
                        "slug": "reply",
                        "label": "Reply",
                        "color": "cyan"
                    },
                    {
                        "slug": "regular_hearing",
                        "label": "Regular Hearing",
                        "color": "teal"
                    },
                    {
                        "slug": "evidence_of_parties",
                        "label": "Evidence of parties",
                        "color": "blue"
                    },
                    {
                        "slug": "cross_examination",
                        "label": "Cross Examination",
                        "color": "purple"
                    },
                    {
                        "slug": "reports",
                        "label": "Reports / For Report",
                        "color": "cyan"
                    },
                    {
                        "slug": "arguments",
                        "label": "Final Arguments",
                        "color": "teal"
                    },
                    {
                        "slug": "orders",
                        "label": "Orders",
                        "color": "orange"
                    },
                    {
                        "slug": "final_judgement",
                        "label": "Final judgement",
                        "color": "emerald"
                    },
                    {
                        "slug": "notice_for_implementation",
                        "label": "Notice for implementation",
                        "color": "indigo"
                    },
                    {
                        "slug": "order_implemented",
                        "label": "Order Implemented",
                        "color": "emerald"
                    },
                    {
                        "slug": "contempt",
                        "label": "Contempt",
                        "color": "rose"
                    },
                    {
                        "slug": "rejected",
                        "label": "Rejected",
                        "color": "slate"
                    }
                ],
                "transitions": [
                    {
                        "slug": "mark_deficient",
                        "name": "Mark Deficient",
                        "from": [
                            "pending",
                            "maintainability"
                        ],
                        "to": "deficient",
                        "target_role": "assistant_registrar",
                        "requires_remarks": true
                    },
                    {
                        "slug": "resubmit_petition",
                        "name": "Resubmit Petition",
                        "from": [
                            "deficient"
                        ],
                        "to": "pending",
                        "target_role": "complainant"
                    },
                    {
                        "slug": "scrutinize_maintainability",
                        "name": "Verify Maintainability",
                        "from": [
                            "pending"
                        ],
                        "to": "maintainability",
                        "target_role": "assistant_registrar"
                    },
                    {
                        "slug": "reject_petition",
                        "name": "Reject Petition",
                        "from": [
                            "maintainability",
                            "preliminary_hearing"
                        ],
                        "to": "rejected",
                        "target_role": "registrar",
                        "requires_remarks": true
                    },
                    {
                        "slug": "schedule_preliminary",
                        "name": "Schedule Preliminary Hearing",
                        "from": [
                            "maintainability"
                        ],
                        "to": "preliminary_hearing",
                        "target_role": "registrar"
                    },
                    {
                        "slug": "issue_notice",
                        "name": "Issue Notice to Respondent",
                        "from": [
                            "maintainability",
                            "preliminary_hearing"
                        ],
                        "to": "notice_to_respondent",
                        "target_role": "registrar"
                    },
                    {
                        "slug": "submit_reply",
                        "name": "Submit Reply",
                        "from": [
                            "notice_to_respondent"
                        ],
                        "to": "reply",
                        "target_role": "assistant_registrar"
                    },
                    {
                        "slug": "conduct_regular_hearing",
                        "name": "Conduct Regular Hearing",
                        "from": [
                            "reply",
                            "preliminary_hearing"
                        ],
                        "to": "regular_hearing",
                        "target_role": "ombudsperson"
                    },
                    {
                        "slug": "record_evidence",
                        "name": "Record Evidence",
                        "from": [
                            "regular_hearing"
                        ],
                        "to": "evidence_of_parties",
                        "target_role": "ombudsperson"
                    },
                    {
                        "slug": "cross_examine",
                        "name": "Conduct Cross Examination",
                        "from": [
                            "evidence_of_parties"
                        ],
                        "to": "cross_examination",
                        "target_role": "advisor"
                    },
                    {
                        "slug": "seek_report",
                        "name": "Seek Inquiry Report",
                        "from": [
                            "regular_hearing",
                            "evidence_of_parties"
                        ],
                        "to": "reports",
                        "target_role": "ombudsperson"
                    },
                    {
                        "slug": "hear_arguments",
                        "name": "Hear Final Arguments",
                        "from": [
                            "cross_examination",
                            "reports",
                            "regular_hearing"
                        ],
                        "to": "arguments",
                        "target_role": "ombudsperson"
                    },
                    {
                        "slug": "reserve_orders",
                        "name": "Reserve Orders",
                        "from": [
                            "arguments"
                        ],
                        "to": "orders",
                        "target_role": "ombudsperson"
                    },
                    {
                        "slug": "pronounce_judgment",
                        "name": "Pronounce Final Judgement",
                        "from": [
                            "orders",
                            "arguments"
                        ],
                        "to": "final_judgement",
                        "target_role": "ombudsperson"
                    },
                    {
                        "slug": "issue_implementation_notice",
                        "name": "Notice for Implementation",
                        "from": [
                            "final_judgement"
                        ],
                        "to": "notice_for_implementation",
                        "target_role": "law_officer"
                    },
                    {
                        "slug": "mark_implemented",
                        "name": "Mark Order Implemented",
                        "from": [
                            "notice_for_implementation"
                        ],
                        "to": "order_implemented",
                        "target_role": "law_officer"
                    },
                    {
                        "slug": "initiate_contempt",
                        "name": "Initiate Contempt",
                        "from": [
                            "notice_for_implementation"
                        ],
                        "to": "contempt",
                        "target_role": "law_officer",
                        "requires_remarks": true
                    }
                ]
            },
            "roles": [
                {
                    "slug": "super_admin",
                    "name": "Super Admin",
                    "description": "Apex system administration, security configurations, role assignment, and audit logs",
                    "seed": true,
                    "seedUser": true,
                    "permissions": [
                        "complaints.view",
                        "complaints.create",
                        "complaints.edit",
                        "complaints.delete",
                        "complaints.hear",
                        "complaints.forward",
                        "complaints.decide"
                    ]
                },
                {
                    "slug": "admin",
                    "name": "Admin",
                    "description": "Operational case oversight, staff allocation, reporting dossiers, and institutional analytics",
                    "seed": true,
                    "seedUser": true,
                    "permissions": [
                        "complaints.view",
                        "complaints.create",
                        "complaints.edit",
                        "complaints.forward"
                    ]
                },
                {
                    "slug": "complainant",
                    "name": "Complainant",
                    "description": "Aggrieved citizen registering grievance petition, submitting documents, and tracking case progress",
                    "seed": true,
                    "seedUser": false,
                    "permissions": [
                        "complaints.view",
                        "complaints.create"
                    ]
                },
                {
                    "slug": "assistant_registrar",
                    "name": "Assistant Registrar",
                    "description": "Initial petition scrutiny, deficiency notices, diary numbering, and case dossier compilation",
                    "seed": true,
                    "seedUser": true,
                    "permissions": [
                        "complaints.view",
                        "complaints.create",
                        "complaints.edit",
                        "complaints.forward"
                    ]
                },
                {
                    "slug": "registrar",
                    "name": "Registrar",
                    "description": "Maintainability determination, bench scheduling, summons & notice dispatch to respondents",
                    "seed": true,
                    "seedUser": true,
                    "permissions": [
                        "complaints.view",
                        "complaints.edit",
                        "complaints.forward"
                    ]
                },
                {
                    "slug": "ombudsperson",
                    "name": "Ombudsperson",
                    "description": "Federal Ombudsperson presiding over judicial hearings, interim injunctions, and final decrees",
                    "seed": true,
                    "seedUser": true,
                    "permissions": [
                        "complaints.view",
                        "complaints.hear",
                        "complaints.decide"
                    ]
                },
                {
                    "slug": "advisor",
                    "name": "Advisor",
                    "description": "Senior judicial advisory, legal research briefs, hearing assistance, and case analysis",
                    "seed": true,
                    "seedUser": true,
                    "permissions": [
                        "complaints.view",
                        "complaints.hear",
                        "complaints.forward"
                    ]
                },
                {
                    "slug": "law_officer",
                    "name": "Law Officer",
                    "description": "Legal scrutiny, court compliance tracking, judgment implementation enforcement, and contempt review",
                    "seed": true,
                    "seedUser": true,
                    "permissions": [
                        "complaints.view",
                        "complaints.forward",
                        "complaints.decide"
                    ]
                }
            ],
            "navigation": {
                "label": "Complaints",
                "icon": "scale",
                "group": "Adjudication",
                "route": "adjudication/complaints"
            }
        },
        "allRelations": [
            {
                "type": "hasMany",
                "source": "complaints",
                "target": "accused",
                "source_model": "complaint",
                "model": "accused",
                "foreign_key": "complaint_id"
            },
            {
                "type": "hasMany",
                "source": "complaints",
                "target": "complaint_properties",
                "source_model": "complaint",
                "model": "complaint_property",
                "foreign_key": "complaint_id"
            },
            {
                "type": "hasMany",
                "source": "complaints",
                "target": "complaint_approvals",
                "source_model": "complaint",
                "model": "complaint_approval",
                "foreign_key": "complaint_id"
            },
            {
                "type": "hasMany",
                "source": "complaints",
                "target": "complaint_attributes_harassments",
                "source_model": "complaint",
                "model": "complaint_attributes_harassment",
                "foreign_key": "complaint_id"
            },
            {
                "type": "hasMany",
                "source": "complaints",
                "target": "complaint_attributes_properties",
                "source_model": "complaint",
                "model": "complaint_attributes_property",
                "foreign_key": "complaint_id"
            },
            {
                "type": "hasMany",
                "source": "complaints",
                "target": "complaint_feedbacks",
                "source_model": "complaint",
                "model": "complaint_feedback",
                "foreign_key": "complaint_id"
            }
        ],
        "source": "schema_version: 1\nname: Complaints\nhandle: complaints\ndomain: Adjudication\ndescription: 'Manages complaints records and associated child items'\npermissions:\n  - complaints.view\n  - complaints.create\n  - complaints.edit\n  - complaints.delete\n  - complaints.hear\n  - complaints.forward\n  - complaints.decide\nmodels:\n  -\n    name: Complaint\n    handle: complaint\n    table: complaints\n    root: true\n    timestamps: true\n    soft_deletes: true\n    description: 'Primary aggregate root for Complaints'\n    fields:\n      -\n        handle: from_role_id\n        label: 'From Role Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: to_role_id\n        label: 'To Role Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: from_user_id\n        label: 'From User Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: to_user_id\n        label: 'To User Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_status_id\n        label: 'Complaint Status Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_date\n        label: 'Complaint Date'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_type_id\n        label: 'Complaint Type Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_subtype_id\n        label: 'Complaint Subtype Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_details\n        label: 'Complaint Details'\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: is_agree\n        label: 'Is Agree'\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: harassment_nature_id\n        label: 'Harassment Nature Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: employment_nature_id\n        label: 'Employment Nature Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: organization_type_id\n        label: 'Organization Type Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complainant_posting_place\n        label: 'Complainant Posting Place'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: accused_posting_place\n        label: 'Accused Posting Place'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: region_id\n        label: 'Region Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_no\n        label: 'Complaint No'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: cr_complaint_no\n        label: 'Cr Complaint No'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: diary_date_time\n        label: 'Diary Date Time'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complainant_name\n        label: 'Complainant Name'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complainant_cnic\n        label: 'Complainant Cnic'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complainant_mobile\n        label: 'Complainant Mobile'\n        type: string\n        input_type: tel\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: receiving_mode\n        label: 'Receiving Mode'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: courier_postal_name\n        label: 'Courier Postal Name'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: courier_postal_number\n        label: 'Courier Postal Number'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: received_by\n        label: 'Received By'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: received_by_id\n        label: 'Received By Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_short_order\n        label: 'Is Short Order'\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_deficient\n        label: 'Is Deficient'\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: status_report_soughts\n        label: 'Status Report Soughts'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations:\n      -\n        name: accused\n        type: hasMany\n        model: accused\n        foreign_key: complaint_id\n      -\n        name: complaint_properties\n        type: hasMany\n        model: complaint_property\n        foreign_key: complaint_id\n      -\n        name: complaint_approvals\n        type: hasMany\n        model: complaint_approval\n        foreign_key: complaint_id\n      -\n        name: complaint_attributes_harassments\n        type: hasMany\n        model: complaint_attributes_harassment\n        foreign_key: complaint_id\n      -\n        name: complaint_attributes_properties\n        type: hasMany\n        model: complaint_attributes_property\n        foreign_key: complaint_id\n      -\n        name: complaint_feedbacks\n        type: hasMany\n        model: complaint_feedback\n        foreign_key: complaint_id\n  -\n    name: Accused\n    handle: accused\n    table: accused\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of Complaints'\n    fields:\n      -\n        handle: complaint_id\n        label: 'Complaint Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_unknown\n        label: 'Is Unknown'\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: father_name\n        label: 'Father Name'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: gender_id\n        label: 'Gender Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: cnic\n        label: Cnic\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: company_name\n        label: 'Company Name'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: designation\n        label: Designation\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: department\n        label: Department\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: email\n        label: Email\n        type: string\n        input_type: email\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: mobile_no\n        label: 'Mobile No'\n        type: string\n        input_type: tel\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: landline_no\n        label: 'Landline No'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: address\n        label: Address\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: province_id\n        label: 'Province Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: district_id\n        label: 'District Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: tehsil_id\n        label: 'Tehsil Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations:\n      -\n        name: complaint\n        type: belongsTo\n        model: complaint\n        foreign_key: complaint_id\n  -\n    name: ComplaintProperty\n    handle: complaint_property\n    table: complaint_properties\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of Complaints'\n    fields:\n      -\n        handle: complaint_id\n        label: 'Complaint Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: district_id\n        label: 'District Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: acquisition_mode_id\n        label: 'Acquisition Mode Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: concerned_organization_id\n        label: 'Concerned Organization Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: property_nature_id\n        label: 'Property Nature Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: region_id\n        label: 'Region Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: property_details\n        label: 'Property Details'\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n    relations:\n      -\n        name: complaint\n        type: belongsTo\n        model: complaint\n        foreign_key: complaint_id\n  -\n    name: ComplaintApproval\n    handle: complaint_approval\n    table: complaint_approvals\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of Complaints'\n    fields:\n      -\n        handle: complaint_id\n        label: 'Complaint Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: from_role_id\n        label: 'From Role Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: to_role_id\n        label: 'To Role Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: from_user_id\n        label: 'From User Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: to_user_id\n        label: 'To User Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_status_id\n        label: 'Complaint Status Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: remarks\n        label: Remarks\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: is_active\n        label: 'Is Active'\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations:\n      -\n        name: complaint\n        type: belongsTo\n        model: complaint\n        foreign_key: complaint_id\n  -\n    name: ComplaintAttributesHarassment\n    handle: complaint_attributes_harassment\n    table: complaint_attributes_harassments\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of Complaints'\n    fields:\n      -\n        handle: complaint_id\n        label: 'Complaint Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_cross_organization\n        label: 'Is Cross Organization'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: institution_date\n        label: 'Institution Date'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: decision_date\n        label: 'Decision Date'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: days_resolved\n        label: 'Days Resolved'\n        type: integer\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: provice_id\n        label: 'Provice Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: district_id\n        label: 'District Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: representation_id\n        label: 'Representation Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: penalty_nature_id\n        label: 'Penalty Nature Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_status_implemented\n        label: 'Is Status Implemented'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: implementation_date\n        label: 'Implementation Date'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: remarks\n        label: Remarks\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: created_by_id\n        label: 'Created By Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations:\n      -\n        name: complaint\n        type: belongsTo\n        model: complaint\n        foreign_key: complaint_id\n  -\n    name: ComplaintAttributesProperty\n    handle: complaint_attributes_property\n    table: complaint_attributes_properties\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of Complaints'\n    fields:\n      -\n        handle: complaint_id\n        label: 'Complaint Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: micro_category_id\n        label: 'Micro Category Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: institution_date\n        label: 'Institution Date'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: decision_date\n        label: 'Decision Date'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: days_resolved\n        label: 'Days Resolved'\n        type: integer\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: decision_type_id\n        label: 'Decision Type Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: provice_id\n        label: 'Provice Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: islamabad_zone\n        label: 'Islamabad Zone'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: property_valuation\n        label: 'Property Valuation'\n        type: decimal\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_settlement\n        label: 'Is Settlement'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: property_quantum\n        label: 'Property Quantum'\n        type: decimal\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: representation_id\n        label: 'Representation Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_status_implemented\n        label: 'Is Status Implemented'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: implementation_date\n        label: 'Implementation Date'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: remarks\n        label: Remarks\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: created_by_id\n        label: 'Created By Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations:\n      -\n        name: complaint\n        type: belongsTo\n        model: complaint\n        foreign_key: complaint_id\n  -\n    name: ComplaintFeedback\n    handle: complaint_feedback\n    table: complaint_feedbacks\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of Complaints'\n    fields:\n      -\n        handle: complaint_id\n        label: 'Complaint Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: star\n        label: Star\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: remarks\n        label: Remarks\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n    relations:\n      -\n        name: complaint\n        type: belongsTo\n        model: complaint\n        foreign_key: complaint_id\nworkflow:\n  enabled: true\n  state_column: complaint_status_id\n  states:\n    -\n      slug: pending\n      label: Pending\n      color: amber\n    -\n      slug: deficient\n      label: Deficient\n      color: rose\n    -\n      slug: maintainability\n      label: Maintainability\n      color: blue\n    -\n      slug: preliminary_hearing\n      label: 'Preliminary Hearing'\n      color: purple\n    -\n      slug: notice_to_respondent\n      label: 'Notice to Respondent'\n      color: indigo\n    -\n      slug: reply\n      label: Reply\n      color: cyan\n    -\n      slug: regular_hearing\n      label: 'Regular Hearing'\n      color: teal\n    -\n      slug: evidence_of_parties\n      label: 'Evidence of parties'\n      color: blue\n    -\n      slug: cross_examination\n      label: 'Cross Examination'\n      color: purple\n    -\n      slug: reports\n      label: 'Reports / For Report'\n      color: cyan\n    -\n      slug: arguments\n      label: 'Final Arguments'\n      color: teal\n    -\n      slug: orders\n      label: Orders\n      color: orange\n    -\n      slug: final_judgement\n      label: 'Final judgement'\n      color: emerald\n    -\n      slug: notice_for_implementation\n      label: 'Notice for implementation'\n      color: indigo\n    -\n      slug: order_implemented\n      label: 'Order Implemented'\n      color: emerald\n    -\n      slug: contempt\n      label: Contempt\n      color: rose\n    -\n      slug: rejected\n      label: Rejected\n      color: slate\n  transitions:\n    -\n      slug: mark_deficient\n      name: 'Mark Deficient'\n      from:\n        - pending\n        - maintainability\n      to: deficient\n      target_role: assistant_registrar\n      requires_remarks: true\n    -\n      slug: resubmit_petition\n      name: 'Resubmit Petition'\n      from:\n        - deficient\n      to: pending\n      target_role: complainant\n    -\n      slug: scrutinize_maintainability\n      name: 'Verify Maintainability'\n      from:\n        - pending\n      to: maintainability\n      target_role: assistant_registrar\n    -\n      slug: reject_petition\n      name: 'Reject Petition'\n      from:\n        - maintainability\n        - preliminary_hearing\n      to: rejected\n      target_role: registrar\n      requires_remarks: true\n    -\n      slug: schedule_preliminary\n      name: 'Schedule Preliminary Hearing'\n      from:\n        - maintainability\n      to: preliminary_hearing\n      target_role: registrar\n    -\n      slug: issue_notice\n      name: 'Issue Notice to Respondent'\n      from:\n        - maintainability\n        - preliminary_hearing\n      to: notice_to_respondent\n      target_role: registrar\n    -\n      slug: submit_reply\n      name: 'Submit Reply'\n      from:\n        - notice_to_respondent\n      to: reply\n      target_role: assistant_registrar\n    -\n      slug: conduct_regular_hearing\n      name: 'Conduct Regular Hearing'\n      from:\n        - reply\n        - preliminary_hearing\n      to: regular_hearing\n      target_role: ombudsperson\n    -\n      slug: record_evidence\n      name: 'Record Evidence'\n      from:\n        - regular_hearing\n      to: evidence_of_parties\n      target_role: ombudsperson\n    -\n      slug: cross_examine\n      name: 'Conduct Cross Examination'\n      from:\n        - evidence_of_parties\n      to: cross_examination\n      target_role: advisor\n    -\n      slug: seek_report\n      name: 'Seek Inquiry Report'\n      from:\n        - regular_hearing\n        - evidence_of_parties\n      to: reports\n      target_role: ombudsperson\n    -\n      slug: hear_arguments\n      name: 'Hear Final Arguments'\n      from:\n        - cross_examination\n        - reports\n        - regular_hearing\n      to: arguments\n      target_role: ombudsperson\n    -\n      slug: reserve_orders\n      name: 'Reserve Orders'\n      from:\n        - arguments\n      to: orders\n      target_role: ombudsperson\n    -\n      slug: pronounce_judgment\n      name: 'Pronounce Final Judgement'\n      from:\n        - orders\n        - arguments\n      to: final_judgement\n      target_role: ombudsperson\n    -\n      slug: issue_implementation_notice\n      name: 'Notice for Implementation'\n      from:\n        - final_judgement\n      to: notice_for_implementation\n      target_role: law_officer\n    -\n      slug: mark_implemented\n      name: 'Mark Order Implemented'\n      from:\n        - notice_for_implementation\n      to: order_implemented\n      target_role: law_officer\n    -\n      slug: initiate_contempt\n      name: 'Initiate Contempt'\n      from:\n        - notice_for_implementation\n      to: contempt\n      target_role: law_officer\n      requires_remarks: true\nroles:\n  -\n    slug: super_admin\n    name: 'Super Admin'\n    description: 'Apex system administration, security configurations, role assignment, and audit logs'\n    seed: true\n    seedUser: true\n    permissions:\n      - complaints.view\n      - complaints.create\n      - complaints.edit\n      - complaints.delete\n      - complaints.hear\n      - complaints.forward\n      - complaints.decide\n  -\n    slug: admin\n    name: Admin\n    description: 'Operational case oversight, staff allocation, reporting dossiers, and institutional analytics'\n    seed: true\n    seedUser: true\n    permissions:\n      - complaints.view\n      - complaints.create\n      - complaints.edit\n      - complaints.forward\n  -\n    slug: complainant\n    name: Complainant\n    description: 'Aggrieved citizen registering grievance petition, submitting documents, and tracking case progress'\n    seed: true\n    seedUser: false\n    permissions:\n      - complaints.view\n      - complaints.create\n  -\n    slug: assistant_registrar\n    name: 'Assistant Registrar'\n    description: 'Initial petition scrutiny, deficiency notices, diary numbering, and case dossier compilation'\n    seed: true\n    seedUser: true\n    permissions:\n      - complaints.view\n      - complaints.create\n      - complaints.edit\n      - complaints.forward\n  -\n    slug: registrar\n    name: Registrar\n    description: 'Maintainability determination, bench scheduling, summons & notice dispatch to respondents'\n    seed: true\n    seedUser: true\n    permissions:\n      - complaints.view\n      - complaints.edit\n      - complaints.forward\n  -\n    slug: ombudsperson\n    name: Ombudsperson\n    description: 'Federal Ombudsperson presiding over judicial hearings, interim injunctions, and final decrees'\n    seed: true\n    seedUser: true\n    permissions:\n      - complaints.view\n      - complaints.hear\n      - complaints.decide\n  -\n    slug: advisor\n    name: Advisor\n    description: 'Senior judicial advisory, legal research briefs, hearing assistance, and case analysis'\n    seed: true\n    seedUser: true\n    permissions:\n      - complaints.view\n      - complaints.hear\n      - complaints.forward\n  -\n    slug: law_officer\n    name: 'Law Officer'\n    description: 'Legal scrutiny, court compliance tracking, judgment implementation enforcement, and contempt review'\n    seed: true\n    seedUser: true\n    permissions:\n      - complaints.view\n      - complaints.forward\n      - complaints.decide\nnavigation:\n  label: Complaints\n  icon: scale\n  group: Adjudication\n  route: complaints.index\n  children:\n    -\n      label: 'All Complaints'\n      url: /adjudication/complaints\n      route: complaints.index\n      icon: scale\n    -\n      label: 'Admin Docket Intake'\n      url: /adjudication/complaints/create?flow=admin\n      icon: file-plus\n    -\n      label: 'Citizen Grievance Portal'\n      url: /adjudication/complaints/create?flow=complainant\n      icon: user-plus\n"
    },
    {
        "name": "Attachments",
        "handle": "attachments",
        "domain": "Adjudication",
        "blueprint": {
            "schema_version": 1,
            "name": "Attachments",
            "handle": "attachments",
            "domain": "Adjudication",
            "description": "Manages attachments records and associated child items",
            "permissions": [
                "attachments.view",
                "attachments.create",
                "attachments.edit",
                "attachments.delete"
            ],
            "models": [
                {
                    "name": "Attachment",
                    "handle": "attachment",
                    "table": "attachments",
                    "root": true,
                    "timestamps": true,
                    "soft_deletes": true,
                    "description": "Primary aggregate root for Attachments",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "path",
                            "label": "Path",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "attachable_id",
                            "label": "Attachable Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "attachable_type",
                            "label": "Attachable Type",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_additional",
                            "label": "Is Additional",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_approval_id",
                            "label": "Complaint Approval Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                }
            ],
            "workflow": null,
            "roles": [],
            "navigation": {
                "label": "Attachments",
                "icon": "paperclip",
                "group": "Adjudication",
                "route": "adjudication/attachments"
            }
        },
        "allRelations": [],
        "source": "schema_version: 1\nname: Attachments\nhandle: attachments\ndomain: Adjudication\ndescription: 'Manages attachments records and associated child items'\npermissions:\n  - attachments.view\n  - attachments.create\n  - attachments.edit\n  - attachments.delete\nmodels:\n  -\n    name: Attachment\n    handle: attachment\n    table: attachments\n    root: true\n    timestamps: true\n    soft_deletes: true\n    description: 'Primary aggregate root for Attachments'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: path\n        label: Path\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: attachable_id\n        label: 'Attachable Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: attachable_type\n        label: 'Attachable Type'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_additional\n        label: 'Is Additional'\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_approval_id\n        label: 'Complaint Approval Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\nworkflow: null\nroles: {  }\nnavigation:\n  label: Attachments\n  icon: paperclip\n  group: Adjudication\n  route: adjudication/attachments\n"
    },
    {
        "name": "Representations",
        "handle": "representations",
        "domain": "Adjudication",
        "blueprint": {
            "schema_version": 1,
            "name": "Representations",
            "handle": "representations",
            "domain": "Adjudication",
            "description": "Manages representations records and associated child items",
            "permissions": [
                "representations.view",
                "representations.create",
                "representations.edit",
                "representations.delete"
            ],
            "models": [
                {
                    "name": "Representation",
                    "handle": "representation",
                    "table": "representations",
                    "root": true,
                    "timestamps": true,
                    "soft_deletes": true,
                    "description": "Primary aggregate root for Representations",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                }
            ],
            "workflow": null,
            "roles": [],
            "navigation": {
                "label": "Representations",
                "icon": "file-text",
                "group": "Adjudication",
                "route": "adjudication/representations"
            }
        },
        "allRelations": [],
        "source": "schema_version: 1\nname: Representations\nhandle: representations\ndomain: Adjudication\ndescription: 'Manages representations records and associated child items'\npermissions:\n  - representations.view\n  - representations.create\n  - representations.edit\n  - representations.delete\nmodels:\n  -\n    name: Representation\n    handle: representation\n    table: representations\n    root: true\n    timestamps: true\n    soft_deletes: true\n    description: 'Primary aggregate root for Representations'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\nworkflow: null\nroles: {  }\nnavigation:\n  label: Representations\n  icon: file-text\n  group: Adjudication\n  route: adjudication/representations\n"
    },
    {
        "name": "ReportSoughts",
        "handle": "report_soughts",
        "domain": "Adjudication",
        "blueprint": {
            "schema_version": 1,
            "name": "ReportSoughts",
            "handle": "report_soughts",
            "domain": "Adjudication",
            "description": "Manages report_soughts records and associated child items",
            "permissions": [
                "report_soughts.view",
                "report_soughts.create",
                "report_soughts.edit",
                "report_soughts.delete"
            ],
            "models": [
                {
                    "name": "ReportSought",
                    "handle": "report_sought",
                    "table": "report_soughts",
                    "root": true,
                    "timestamps": true,
                    "soft_deletes": true,
                    "description": "Primary aggregate root for ReportSoughts",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                }
            ],
            "workflow": null,
            "roles": [],
            "navigation": {
                "label": "ReportSoughts",
                "icon": "search",
                "group": "Adjudication",
                "route": "adjudication/report-soughts"
            }
        },
        "allRelations": [],
        "source": "schema_version: 1\nname: ReportSoughts\nhandle: report_soughts\ndomain: Adjudication\ndescription: 'Manages report_soughts records and associated child items'\npermissions:\n  - report_soughts.view\n  - report_soughts.create\n  - report_soughts.edit\n  - report_soughts.delete\nmodels:\n  -\n    name: ReportSought\n    handle: report_sought\n    table: report_soughts\n    root: true\n    timestamps: true\n    soft_deletes: true\n    description: 'Primary aggregate root for ReportSoughts'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\nworkflow: null\nroles: {  }\nnavigation:\n  label: ReportSoughts\n  icon: search\n  group: Adjudication\n  route: adjudication/report-soughts\n"
    },
    {
        "name": "NoticeTitles",
        "handle": "notice_titles",
        "domain": "Adjudication",
        "blueprint": {
            "schema_version": 1,
            "name": "NoticeTitles",
            "handle": "notice_titles",
            "domain": "Adjudication",
            "description": "Manages notice_titles records and associated child items",
            "permissions": [
                "notice_titles.view",
                "notice_titles.create",
                "notice_titles.edit",
                "notice_titles.delete"
            ],
            "models": [
                {
                    "name": "NoticeTitle",
                    "handle": "notice_title",
                    "table": "notice_titles",
                    "root": true,
                    "timestamps": true,
                    "soft_deletes": true,
                    "description": "Primary aggregate root for NoticeTitles",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                }
            ],
            "workflow": null,
            "roles": [],
            "navigation": {
                "label": "NoticeTitles",
                "icon": "bell",
                "group": "Adjudication",
                "route": "adjudication/notice-titles"
            }
        },
        "allRelations": [],
        "source": "schema_version: 1\nname: NoticeTitles\nhandle: notice_titles\ndomain: Adjudication\ndescription: 'Manages notice_titles records and associated child items'\npermissions:\n  - notice_titles.view\n  - notice_titles.create\n  - notice_titles.edit\n  - notice_titles.delete\nmodels:\n  -\n    name: NoticeTitle\n    handle: notice_title\n    table: notice_titles\n    root: true\n    timestamps: true\n    soft_deletes: true\n    description: 'Primary aggregate root for NoticeTitles'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\nworkflow: null\nroles: {  }\nnavigation:\n  label: NoticeTitles\n  icon: bell\n  group: Adjudication\n  route: adjudication/notice-titles\n"
    },
    {
        "name": "NoticeTemplates",
        "handle": "notice_templates",
        "domain": "Adjudication",
        "blueprint": {
            "schema_version": 1,
            "name": "NoticeTemplates",
            "handle": "notice_templates",
            "domain": "Adjudication",
            "description": "Manages notice_templates records and associated child items",
            "permissions": [
                "notice_templates.view",
                "notice_templates.create",
                "notice_templates.edit",
                "notice_templates.delete"
            ],
            "models": [
                {
                    "name": "NoticeTemplate",
                    "handle": "notice_template",
                    "table": "notice_templates",
                    "root": true,
                    "timestamps": true,
                    "soft_deletes": true,
                    "description": "Primary aggregate root for NoticeTemplates",
                    "fields": [
                        {
                            "handle": "notice_title_id",
                            "label": "Notice Title Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "purpose",
                            "label": "Purpose",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_type_id",
                            "label": "Complaint Type Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "template_html",
                            "label": "Template Html",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "required_fields",
                            "label": "Required Fields",
                            "type": "json",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                }
            ],
            "workflow": null,
            "roles": [],
            "navigation": {
                "label": "NoticeTemplates",
                "icon": "bell",
                "group": "Adjudication",
                "route": "adjudication/notice-templates"
            }
        },
        "allRelations": [],
        "source": "schema_version: 1\nname: NoticeTemplates\nhandle: notice_templates\ndomain: Adjudication\ndescription: 'Manages notice_templates records and associated child items'\npermissions:\n  - notice_templates.view\n  - notice_templates.create\n  - notice_templates.edit\n  - notice_templates.delete\nmodels:\n  -\n    name: NoticeTemplate\n    handle: notice_template\n    table: notice_templates\n    root: true\n    timestamps: true\n    soft_deletes: true\n    description: 'Primary aggregate root for NoticeTemplates'\n    fields:\n      -\n        handle: notice_title_id\n        label: 'Notice Title Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: purpose\n        label: Purpose\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_type_id\n        label: 'Complaint Type Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: template_html\n        label: 'Template Html'\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: required_fields\n        label: 'Required Fields'\n        type: json\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\nworkflow: null\nroles: {  }\nnavigation:\n  label: NoticeTemplates\n  icon: bell\n  group: Adjudication\n  route: adjudication/notice-templates\n"
    },
    {
        "name": "Notices",
        "handle": "notices",
        "domain": "Adjudication",
        "blueprint": {
            "schema_version": 1,
            "name": "Notices",
            "handle": "notices",
            "domain": "Adjudication",
            "description": "Manages notices records and associated child items",
            "permissions": [
                "notices.view",
                "notices.create",
                "notices.edit",
                "notices.delete"
            ],
            "models": [
                {
                    "name": "Notice",
                    "handle": "notice",
                    "table": "notices",
                    "root": true,
                    "timestamps": true,
                    "soft_deletes": true,
                    "description": "Primary aggregate root for Notices",
                    "fields": [
                        {
                            "handle": "complaint_id",
                            "label": "Complaint Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "notice_template_id",
                            "label": "Notice Template Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "diary_no",
                            "label": "Diary No",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "notice_order_date",
                            "label": "Notice Order Date",
                            "type": "datetime",
                            "input_type": "datetime",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "notice_order_time",
                            "label": "Notice Order Time",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "final_content",
                            "label": "Final Content",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "signature_file_name",
                            "label": "Signature File Name",
                            "type": "string",
                            "input_type": "file",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "signature_file_path",
                            "label": "Signature File Path",
                            "type": "string",
                            "input_type": "file",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "created_by",
                            "label": "Created By",
                            "type": "integer",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                }
            ],
            "workflow": null,
            "roles": [],
            "navigation": {
                "label": "Notices",
                "icon": "bell",
                "group": "Adjudication",
                "route": "adjudication/notices"
            }
        },
        "allRelations": [],
        "source": "schema_version: 1\nname: Notices\nhandle: notices\ndomain: Adjudication\ndescription: 'Manages notices records and associated child items'\npermissions:\n  - notices.view\n  - notices.create\n  - notices.edit\n  - notices.delete\nmodels:\n  -\n    name: Notice\n    handle: notice\n    table: notices\n    root: true\n    timestamps: true\n    soft_deletes: true\n    description: 'Primary aggregate root for Notices'\n    fields:\n      -\n        handle: complaint_id\n        label: 'Complaint Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: notice_template_id\n        label: 'Notice Template Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: diary_no\n        label: 'Diary No'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: notice_order_date\n        label: 'Notice Order Date'\n        type: datetime\n        input_type: datetime\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: notice_order_time\n        label: 'Notice Order Time'\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: final_content\n        label: 'Final Content'\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: signature_file_name\n        label: 'Signature File Name'\n        type: string\n        input_type: file\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: signature_file_path\n        label: 'Signature File Path'\n        type: string\n        input_type: file\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: created_by\n        label: 'Created By'\n        type: integer\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\nworkflow: null\nroles: {  }\nnavigation:\n  label: Notices\n  icon: bell\n  group: Adjudication\n  route: adjudication/notices\n"
    },
    {
        "name": "MasterData",
        "handle": "master_data",
        "domain": "Adjudication",
        "blueprint": {
            "schema_version": 1,
            "name": "MasterData",
            "handle": "master_data",
            "domain": "Adjudication",
            "description": "Shared statutory taxonomies, lookup registries, and regional catalogs",
            "permissions": [
                "master_data.view",
                "master_data.create",
                "master_data.edit",
                "master_data.delete"
            ],
            "models": [
                {
                    "name": "Gender",
                    "handle": "gender",
                    "table": "genders",
                    "root": true,
                    "timestamps": true,
                    "soft_deletes": true,
                    "description": "Primary aggregate root for MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "Province",
                    "handle": "province",
                    "table": "provinces",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "District",
                    "handle": "district",
                    "table": "districts",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "province_id",
                            "label": "Province Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "Tehsil",
                    "handle": "tehsil",
                    "table": "tehsils",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "district_id",
                            "label": "District Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "Region",
                    "handle": "region",
                    "table": "regions",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "ComplaintType",
                    "handle": "complaint_type",
                    "table": "complaint_types",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "ComplaintSubtype",
                    "handle": "complaint_subtype",
                    "table": "complaint_subtypes",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_type_id",
                            "label": "Complaint Type Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "ComplaintStatus",
                    "handle": "complaint_status",
                    "table": "complaint_statuses",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_subtype_id",
                            "label": "Complaint Subtype Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "EmploymentNature",
                    "handle": "employment_nature",
                    "table": "employment_natures",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "AcquisitionMode",
                    "handle": "acquisition_mode",
                    "table": "acquisition_modes",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "HarassmentNature",
                    "handle": "harassment_nature",
                    "table": "harassment_natures",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "OrganizationType",
                    "handle": "organization_type",
                    "table": "organization_types",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "PropertyType",
                    "handle": "property_type",
                    "table": "property_types",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "PropertyNature",
                    "handle": "property_nature",
                    "table": "property_natures",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "HearingType",
                    "handle": "hearing_type",
                    "table": "hearing_types",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "PenaltyNature",
                    "handle": "penalty_nature",
                    "table": "penalty_natures",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "MicroCategory",
                    "handle": "micro_category",
                    "table": "micro_categories",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "DecisionType",
                    "handle": "decision_type",
                    "table": "decision_types",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "complaint_type_id",
                            "label": "Complaint Type Id",
                            "type": "integer",
                            "input_type": "select",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "PenaltyType",
                    "handle": "penalty_type",
                    "table": "penalty_types",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "name",
                            "label": "Name",
                            "type": "string",
                            "input_type": "text",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                },
                {
                    "name": "Faq",
                    "handle": "faq",
                    "table": "faqs",
                    "root": false,
                    "timestamps": true,
                    "soft_deletes": false,
                    "description": "Child entity of MasterData",
                    "fields": [
                        {
                            "handle": "question_en",
                            "label": "Question En",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "answer_en",
                            "label": "Answer En",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "question_ur",
                            "label": "Question Ur",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "answer_ur",
                            "label": "Answer Ur",
                            "type": "text",
                            "input_type": "textarea",
                            "required": false,
                            "nullable": true,
                            "width": 100,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_active",
                            "label": "Is Active",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        },
                        {
                            "handle": "is_property_faq",
                            "label": "Is Property Faq",
                            "type": "boolean",
                            "input_type": "switch",
                            "required": false,
                            "nullable": true,
                            "width": 50,
                            "optionsText": ""
                        }
                    ],
                    "relations": []
                }
            ],
            "workflow": null,
            "roles": [],
            "navigation": {
                "label": "MasterData",
                "icon": "database",
                "group": "Adjudication",
                "route": "adjudication/master-data"
            }
        },
        "allRelations": [],
        "source": "schema_version: 1\nname: MasterData\nhandle: master_data\ndomain: Adjudication\ndescription: 'Shared statutory taxonomies, lookup registries, and regional catalogs'\npermissions:\n  - master_data.view\n  - master_data.create\n  - master_data.edit\n  - master_data.delete\nmodels:\n  -\n    name: Gender\n    handle: gender\n    table: genders\n    root: true\n    timestamps: true\n    soft_deletes: true\n    description: 'Primary aggregate root for MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: Province\n    handle: province\n    table: provinces\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: District\n    handle: district\n    table: districts\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: province_id\n        label: 'Province Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: Tehsil\n    handle: tehsil\n    table: tehsils\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: district_id\n        label: 'District Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: Region\n    handle: region\n    table: regions\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: ComplaintType\n    handle: complaint_type\n    table: complaint_types\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: ComplaintSubtype\n    handle: complaint_subtype\n    table: complaint_subtypes\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_type_id\n        label: 'Complaint Type Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: ComplaintStatus\n    handle: complaint_status\n    table: complaint_statuses\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_subtype_id\n        label: 'Complaint Subtype Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: EmploymentNature\n    handle: employment_nature\n    table: employment_natures\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: AcquisitionMode\n    handle: acquisition_mode\n    table: acquisition_modes\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: HarassmentNature\n    handle: harassment_nature\n    table: harassment_natures\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: OrganizationType\n    handle: organization_type\n    table: organization_types\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: PropertyType\n    handle: property_type\n    table: property_types\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: PropertyNature\n    handle: property_nature\n    table: property_natures\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: HearingType\n    handle: hearing_type\n    table: hearing_types\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: PenaltyNature\n    handle: penalty_nature\n    table: penalty_natures\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: MicroCategory\n    handle: micro_category\n    table: micro_categories\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: DecisionType\n    handle: decision_type\n    table: decision_types\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: complaint_type_id\n        label: 'Complaint Type Id'\n        type: integer\n        input_type: select\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: PenaltyType\n    handle: penalty_type\n    table: penalty_types\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: name\n        label: Name\n        type: string\n        input_type: text\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\n  -\n    name: Faq\n    handle: faq\n    table: faqs\n    root: false\n    timestamps: true\n    soft_deletes: false\n    description: 'Child entity of MasterData'\n    fields:\n      -\n        handle: question_en\n        label: 'Question En'\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: answer_en\n        label: 'Answer En'\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: question_ur\n        label: 'Question Ur'\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: answer_ur\n        label: 'Answer Ur'\n        type: text\n        input_type: textarea\n        required: false\n        nullable: true\n        width: 100\n        optionsText: ''\n      -\n        handle: is_active\n        label: 'Is Active'\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n      -\n        handle: is_property_faq\n        label: 'Is Property Faq'\n        type: boolean\n        input_type: switch\n        required: false\n        nullable: true\n        width: 50\n        optionsText: ''\n    relations: {  }\nworkflow: null\nroles: {  }\nnavigation:\n  label: MasterData\n  icon: database\n  group: Adjudication\n  route: adjudication/master-data\n"
    }
]
};

window.blueprintStudio = ({ initialSource, initialFormat, initialBlueprint, csrfToken, introspectUrl, studioUrl, allSlicesJson, activeSliceIdx, initialPlanScope, initialPortPlan }) => ({
        mode: 'visual', // 'visual', 'preview', 'split', 'yaml'
        selectedPreset: '',
        savedPresets: [],
        presetLoading: false,
        activeModelIndex: 0,
        source: initialSource || '',
        format: initialFormat || 'yaml',
        showPortModal: false,
        portPreset: 'erp',
        portSourcePath: 'e:/laragon/www/erp',
        portDomain: 'ERP',
        portMode: 'system-base',
        portImportData: true,
        portWithFiles: false,
        portInspecting: false,
        portExecuting: false,
        portError: '',
        portInspectionData: null,
        portPlanData: initialPortPlan || null,
        portPlanJsonText: initialPortPlan ? JSON.stringify(initialPortPlan, null, 2) : '',
        portActiveTab: 'sidebar',
        portExecutionLogs: [],
        pageArchTab: 'index',
        showImportYamlModal: false,
        importYamlText: '',
        planScope: initialPlanScope || 'domain',
        yamlViewMode: 'slice',
        domainYamlSource: '',
        selectedUiSlice: 'Projects',
        selectedUiPage: 'index',
        selectedUiSubTab: 'overview',
        uiSliceSearch: '',

        getSelectedUiSliceObj() {
            if (!this.portPlanData || !this.portPlanData.modules) return null;
            for (let m of this.portPlanData.modules) {
                if (m.slices) {
                    for (let s of m.slices) {
                        if (s.name === this.selectedUiSlice) {
                            return Object.assign({}, s, { moduleName: m.name });
                        }
                    }
                }
            }
            const first = this.portPlanData.modules[0]?.slices?.[0];
            return first ? Object.assign({}, first, { moduleName: this.portPlanData.modules[0].name }) : null;
        },

        getAllUiSlices() {
            let res = [];
            if (!this.portPlanData || !this.portPlanData.modules) return res;
            const q = (this.uiSliceSearch || '').toLowerCase().trim();
            for (let m of this.portPlanData.modules) {
                if (m.slices) {
                    for (let s of m.slices) {
                        if (!q || s.name.toLowerCase().includes(q) || (m.name && m.name.toLowerCase().includes(q)) || (s.root_table && s.root_table.toLowerCase().includes(q))) {
                            res.push(Object.assign({}, s, { moduleName: m.name }));
                        }
                    }
                }
            }
            return res;
        },

        selectPortPreset(val) {
            this.portPreset = val;
            // Changing the preset only prefills the fields and clears stale plan data.
            // The developer clicks Analyze explicitly — no auto-fire.
            this.portPlanData = null;
            this.portPlanJsonText = '';
            this.portInspectionData = null;
            this.portError = '';
            if (val === 'erp') {
                this.portSourcePath = 'e:/laragon/www/erp';
                this.portDomain = 'ERP';
            } else if (val === 'adjudication') {
                this.portSourcePath = 'e:/laragon/www/wwf-old';
                this.portDomain = 'Legal';
            } else if (val === 'custom') {
                this.portSourcePath = '';
                this.portDomain = 'Core';
            }
        },

        fetchPortPlan() {
            this.portError = '';
            // Client-side sanity check: a source is required unless a canned preset/JSON is used.
            if ((this.portPreset === 'custom' || !this.portPreset) && !this.portPlanJsonText && !(this.portSourcePath || '').trim()) {
                this.portError = 'Enter a Source Application Path (root directory of the legacy Laravel app) or switch to the "Custom JSON Architecture Plan" preset.';
                return;
            }
            if (!(this.portDomain || '').trim()) {
                this.portError = 'Enter a Target Domain Suite name (e.g. ERP).';
                return;
            }
            this.portInspecting = true;
            fetch('/laraslice/wizard/blueprint/port-plan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    preset: this.portPreset,
                    source: this.portSourcePath,
                    domain: this.portDomain,
                    plan_json: this.portPreset === 'json' ? this.portPlanJsonText : ''
                })
            })
            .then(async (r) => {
                const text = await r.text();
                let res;
                try {
                    res = JSON.parse(text);
                } catch (parseErr) {
                    throw new Error(`Server returned non-JSON response (${r.status} ${r.statusText}): ${text.substring(0, 250)}`);
                }
                if (!r.ok && !res.message) {
                    throw new Error(`Server error ${r.status}: ${text.substring(0, 250)}`);
                }
                return res;
            })
            .then(res => {
                this.portInspecting = false;
                if (res.success && res.plan) {
                    this.portError = '';
                    this.portPlanData = res.plan;
                    this.portPlanJsonText = JSON.stringify(res.plan, null, 2);
                    if (res.inspection) this.portInspectionData = res.inspection;
                    if (!this.selectedUiSlice && res.plan.modules && res.plan.modules[0] && res.plan.modules[0].slices && res.plan.modules[0].slices[0]) {
                        this.selectedUiSlice = res.plan.modules[0].slices[0].name;
                    }
                } else {
                    // Clear stale plan so a failed re-Analyze never masquerades as current.
                    this.portPlanData = null;
                    this.portPlanJsonText = '';
                    this.portInspectionData = null;
                    this.portError = 'Plan generation failed: ' + (res.message || 'Unknown error');
                }
            })
            .catch(e => {
                this.portInspecting = false;
                this.portPlanData = null;
                this.portPlanJsonText = '';
                this.portInspectionData = null;
                this.portError = 'Plan generation error: ' + e.message;
            });
        },

        downloadPlanJson() {
            if (!this.portPlanJsonText) return;
            const blob = new Blob([this.portPlanJsonText], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `porting-architecture-plan-${(this.portDomain || 'app').toLowerCase()}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        executePortPlan() {
            this.portError = '';
            if (!this.portPlanData && !this.portPlanJsonText) {
                this.portError = 'Please inspect the project or load an architecture plan first.';
                return;
            }
            // Validate hand-edited JSON before executing (mirrors the server's modules check).
            let planPayload = this.portPlanData;
            try {
                if (this.portPlanJsonText) {
                    planPayload = JSON.parse(this.portPlanJsonText);
                }
            } catch (e) {
                this.portError = 'The JSON Architecture Plan contains invalid JSON: ' + e.message;
                return;
            }
            if (!planPayload || !Array.isArray(planPayload.modules) || planPayload.modules.length === 0) {
                this.portError = 'The architecture plan is invalid: it must contain a non-empty "modules" array.';
                return;
            }
            if (!confirm('Execute the porting plan? This will scaffold slices, models, and migrations into app/Slices.')) {
                return;
            }
            this.portExecuting = true;
            this.portActiveTab = 'log';
            this.portExecutionLogs = [
                `[${new Date().toLocaleTimeString()}] Initializing LaraSlice VSA Porting Engine...`,
                `[${new Date().toLocaleTimeString()}] Target Domain: ${this.portDomain}`,
                `[${new Date().toLocaleTimeString()}] Mode: Canonical Enterprise Business Aggregates`,
                `[${new Date().toLocaleTimeString()}] Multi-tenancy: ${this.portPlanData?.summary?.multi_tenancy?.enabled ? 'Enabled (' + this.portPlanData.summary.multi_tenancy.tenant_key + ')' : 'Disabled'}`,
                `[${new Date().toLocaleTimeString()}] Scaffolding ${this.portPlanData?.modules?.length || 0} Modules and ${this.portPlanData?.summary?.total_canonical_slices || 0} Canonical Slices...`
            ];

            fetch('/laraslice/wizard/blueprint/port-execute', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    plan: planPayload,
                    source: this.portSourcePath,
                    domain: this.portDomain,
                    import_data: this.portImportData,
                    with_files: this.portWithFiles
                })
            })
            .then(async (r) => {
                const text = await r.text();
                let res;
                try {
                    res = JSON.parse(text);
                } catch (parseErr) {
                    throw new Error(`Server returned non-JSON response (${r.status} ${r.statusText}): ${text.substring(0, 250)}`);
                }
                if (!r.ok && !res.message) {
                    throw new Error(`Server error ${r.status}: ${text.substring(0, 250)}`);
                }
                return res;
            })
            .then(res => {
                this.portExecuting = false;
                if (res.success) {
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ✓ Slices and Child Tables successfully created.`);
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ✓ Database migrations executed.`);
                    if (res.result?.data_import) {
                        const imp = res.result.data_import;
                        this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ✓ ETL Data Stream: ${imp.total_rows_imported || 0} records streamed across ${imp.tables_imported || 0} tables.`);
                    }
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] 🚀 100% Usable Porting Completed Successfully!`);
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] Reloading Studio...`);
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ❌ Error: ${res.message || 'Execution failed'}`);
                    this.portError = 'Porting execution error: ' + (res.message || 'Unknown error');
                }
            })
            .catch(e => {
                this.portExecuting = false;
                this.portExecutionLogs.push(`[${new Date().toLocaleTimeString()}] ❌ Network Error: ${e.message}`);
                this.portError = 'Port execution error: ' + e.message;
            });
        },

        getActiveSlicePages() {
            if (this.blueprint && this.blueprint.pages) {
                return this.blueprint.pages;
            }
            if (this.slices && this.slices[this.activeSliceIdx] && this.slices[this.activeSliceIdx].blueprint?.pages) {
                return this.slices[this.activeSliceIdx].blueprint.pages;
            }
            const sName = this.blueprint?.name;
            if (this.portPlanData && this.portPlanData.modules) {
                for (let m of this.portPlanData.modules) {
                    if (m.slices) {
                        for (let s of m.slices) {
                            if (s.name === sName && s.pages) return s.pages;
                        }
                    }
                }
            }
            const rootModel = (this.blueprint?.models && this.blueprint.models[0]) || { name: sName || 'Record', fields: [] };
            const childModels = (this.blueprint?.models && this.blueprint.models.slice(1)) || [];
            return {
                index: {
                    title: `${sName || 'Record'} Directory`,
                    description: `Unified BlatUI directory for managing ${sName || 'entity'} records.`,
                    stats_ribbon: [
                        { label: `Total ${sName || 'Records'}`, value: '120', change: '+12% this month', icon: 'layers', color: 'primary' },
                        { label: 'Active', value: '95', change: '79% operational', icon: 'check-circle', color: 'emerald' },
                        { label: 'Pending Review', value: '18', change: 'Action required', icon: 'clock', color: 'amber' },
                        { label: 'Archived', value: '7', change: 'Historical records', icon: 'archive', color: 'slate' }
                    ],
                    filter_bar: {
                        search_placeholder: `Search ${(sName || 'record').toLowerCase()} records...`,
                        filters: [
                            { name: 'status', label: 'All Statuses', type: 'select', options: ['Active', 'Pending', 'Archived'] }
                        ]
                    },
                    columns: rootModel.fields ? rootModel.fields.slice(0, 6).map(f => ({ key: f.handle, label: f.label, type: f.type, sortable: true })) : []
                },
                show: {
                    layout: 'split-workspace',
                    sub_tabs: [
                        { name: 'overview', label: 'Overview', icon: 'info' },
                        ...childModels.map(cm => ({ name: cm.handle, label: cm.name, icon: 'list', count: 0 }))
                    ]
                },
                create_edit: {
                    create: {
                        title: `Create ${rootModel.name}`,
                        sections: [
                            { name: 'Primary Information', fields: (rootModel.fields || []).slice(0, 6).map(f => f.handle) },
                            { name: 'Additional Details', fields: (rootModel.fields || []).slice(6, 12).map(f => f.handle) }
                        ]
                    }
                },
                workflow: this.blueprint?.workflow || {
                    state_column: 'status',
                    states: ['Draft', 'Submitted', 'Under Review', 'Approved', 'Finished']
                }
            };
        },

        convertModulesToStudioSlices(modules) {
            const out = [];
            if (!Array.isArray(modules)) return out;
            for (let m of modules) {
                const modName = m.name || 'Core';
                if (!Array.isArray(m.slices)) continue;
                for (let s of m.slices) {
                    const sName = s.name;
                    const sHandle = s.handle || this.toSnakeCase(sName);
                    const rootTable = s.root_table || this.toSnakeCase(sName);
                    const rootModelHandle = this.toSnakeCase(rootTable.replace(/s$/, ''));
                    
                    const models = [];
                    const relations = [];
                    
                    if (Array.isArray(s.child_tables)) {
                        for (let ct of s.child_tables) {
                            const cTable = typeof ct === 'string' ? ct : ct.table;
                            const cModel = (typeof ct === 'object' && ct.model) ? ct.model : this.toStudlyCase(cTable.replace(/s$/, ''));
                            const cHandle = this.toSnakeCase(cModel);
                            const fk = rootModelHandle + '_id';
                            models.push({
                                name: cModel,
                                handle: cHandle,
                                table: cTable,
                                root: false,
                                timestamps: true,
                                soft_deletes: false,
                                description: `Child entity of ${sName}`,
                                fields: [
                                    { handle: 'id', label: 'ID', type: 'integer', input_type: 'number', required: true, nullable: false, width: 25 },
                                    { handle: fk, label: this.toStudlyCase(fk), type: 'integer', input_type: 'select', required: true, nullable: false, width: 50 },
                                    { handle: 'name', label: 'Name', type: 'string', input_type: 'text', required: false, nullable: true, width: 50 }
                                ],
                                relations: [{ name: rootModelHandle, type: 'belongsTo', model: rootModelHandle, foreign_key: fk }]
                            });
                            relations.push({ type: 'hasMany', source: rootTable, target: cTable, source_model: rootModelHandle, target_model: cHandle, foreign_key: fk });
                        }
                    }
                    
                    models.unshift({
                        name: this.toStudlyCase(rootModelHandle),
                        handle: rootModelHandle,
                        table: rootTable,
                        root: true,
                        timestamps: true,
                        soft_deletes: true,
                        description: `Primary aggregate root for ${sName}`,
                        fields: [
                            { handle: 'id', label: 'ID', type: 'integer', input_type: 'number', required: true, nullable: false, width: 25 },
                            { handle: 'name', label: 'Name', type: 'string', input_type: 'text', required: true, nullable: false, width: 50 },
                            { handle: 'status', label: 'Status', type: 'string', input_type: 'select', required: false, nullable: true, width: 50 }
                        ],
                        relations: relations.map(r => ({ name: r.target, type: 'hasMany', model: r.target_model, foreign_key: r.foreign_key }))
                    });
                    
                    let uiConfig = null;
                    if (s.ui) {
                        uiConfig = JSON.parse(JSON.stringify(s.ui));
                    } else if (s.pages) {
                        let uiCols = [];
                        if (s.pages.index && Array.isArray(s.pages.index.columns)) {
                            uiCols = s.pages.index.columns.map(c => ({
                                handle: c.field || c.key,
                                label: c.label || this.toStudlyCase(c.field || c.key),
                                type: c.type || 'text',
                                formatter: c.type || 'text',
                                sortable: c.sortable !== false,
                                searchable: true
                            }));
                        }
                        let quickFilters = [];
                        if (s.pages.index?.filter_bar?.filters) {
                            quickFilters = s.pages.index.filter_bar.filters.map(f => ({
                                field: f.name || f.field || 'status',
                                handle: f.name || f.field || 'status',
                                label: f.label || 'Status',
                                type: 'select',
                                searchable: true,
                                is_searchable: true
                            }));
                        }
                        let sections = [];
                        if (s.pages.forms?.create?.sections) {
                            sections = s.pages.forms.create.sections.map(sec => ({
                                name: sec.name || 'General Information',
                                grid: sec.columns || 2,
                                columns: sec.columns || 2,
                                fields: (sec.fields || []).map(f => {
                                    const h = typeof f === 'string' ? f : (f.handle || f.name);
                                    return {
                                        handle: h,
                                        span: 1,
                                        searchable: (typeof f === 'object' && f.searchable !== undefined) ? f.searchable : (h.endsWith('_id') || h.includes('status'))
                                    };
                                })
                            }));
                        }
                        uiConfig = {
                            presentation: { create: 'drawer', edit: 'drawer' },
                            directory: {
                                presentation: 'table',
                                columns: uiCols.length ? uiCols : (models[0]?.fields?.map(f => ({ handle: f.handle, label: f.label, type: 'text', sortable: true })) || []),
                                quick_filters: quickFilters,
                                sidebar_filters: []
                            },
                            forms: {
                                mode: s.pages.forms?.presentation || 'drawer',
                                sections: sections
                            }
                        };
                    }

                    const bp = {
                        schema_version: 1,
                        name: sName,
                        handle: sHandle,
                        domain: this.portDomain || 'ERP',
                        module: modName,
                        description: `Canonical ${sName} slice in ${modName}`,
                        permissions: s.permissions || [`${sHandle}.view`, `${sHandle}.create`, `${sHandle}.edit`, `${sHandle}.delete`],
                        workflow: s.workflow || null,
                        roles: [],
                        navigation: {
                            label: sName,
                            icon: s.icon || 'briefcase',
                            group: modName,
                            route: `erp/${this.toSnakeCase(modName)}/${sHandle}`
                        },
                        ui: uiConfig,
                        pages: s.pages || null,
                        models: models
                    };
                    
                    out.push({
                        name: sName,
                        handle: sHandle,
                        domain: this.portDomain || 'ERP',
                        module: modName,
                        blueprint: bp,
                        allRelations: relations,
                        source: ''
                    });
                }
            }
            return out;
        },

        loadPortedToStudio() {
            if (!this.portPlanData || !this.portPlanData.studio_slices) {
                try {
                    const raw = sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan');
                    if (raw) {
                        this.portPlanData = JSON.parse(raw);
                    }
                } catch(e) {}
            }

            let targetSlices = null;
            if (this.portPlanData && Array.isArray(this.portPlanData.studio_slices) && this.portPlanData.studio_slices.length > 0) {
                targetSlices = this.portPlanData.studio_slices;
            } else if (this.portInspectionData && Array.isArray(this.portInspectionData.studio_slices) && this.portInspectionData.studio_slices.length > 0) {
                targetSlices = this.portInspectionData.studio_slices;
            } else if (this.portPlanData && Array.isArray(this.portPlanData.modules)) {
                targetSlices = this.convertModulesToStudioSlices(this.portPlanData.modules);
            } else {
                try {
                    const rawSlices = sessionStorage.getItem('laraslice_studio_slices') || localStorage.getItem('laraslice_studio_slices');
                    if (rawSlices) {
                        const parsed = JSON.parse(rawSlices);
                        if (Array.isArray(parsed) && parsed.length > 0) {
                            targetSlices = parsed;
                        }
                    }
                } catch(e) {}
            }

            if (!targetSlices || !targetSlices.length) {
                if (studioPresets['legal_dispute_adjudication']) {
                    this.loadStudioPreset('legal_dispute_adjudication');
                    return;
                }
                this.portError = 'Please inspect the project schema or load an architecture plan first.';
                return;
            }

            this.slices = JSON.parse(JSON.stringify(targetSlices));
            this.domainName = this.portPlanData?.domain || this.portInspectionData?.domain || this.portDomain || 'Adjudication';
            this.blueprint.domain = this.domainName;
            this.activeSliceIdx = 0;
            if (this.slices.length > 0 && this.slices[0].blueprint) {
                this.loadParsedBlueprint(JSON.parse(JSON.stringify(this.slices[0].blueprint)));
                this.allRelations = JSON.parse(JSON.stringify(this.slices[0].allRelations || []));
                this.source = this.slices[0].source || '';
                this.syncToYaml();
            }
            if (this.portPlanData?.domain_blueprint_yaml) {
                this.domainYamlSource = this.portPlanData.domain_blueprint_yaml;
            } else if (this.portInspectionData?.domain_blueprint_yaml) {
                this.domainYamlSource = this.portInspectionData.domain_blueprint_yaml;
            } else {
                this.generateDomainYaml();
            }
            this.showPortModal = false;
            this.mode = 'visual';
            try {
                sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                sessionStorage.setItem('laraslice_active_slice_idx', '0');
            } catch(e) {}
        },

        async choosePreset(presetKey) {
            if (!presetKey) return;
            this.presetLoading = true;
            try {
                if (presetKey.startsWith('saved:')) {
                    const slug = presetKey.replace('saved:', '');
                    const res = await fetch('/laraslice/wizard/port-saved-presets/' + encodeURIComponent(slug), { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (data.success && data.preset?.plan) {
                        this.portPlanData = data.preset.plan;
                        sessionStorage.setItem('laraslice_port_plan', JSON.stringify(data.preset.plan));
                        localStorage.setItem('laraslice_port_plan', JSON.stringify(data.preset.plan));
                        this.loadPortedToStudio();
                        return;
                    }
                }

                if (presetKey === 'legal_dispute_adjudication') {
                    this.loadStudioPreset('legal_dispute_adjudication');
                    return;
                }

                // If template preset exists in studioPresets
                if (studioPresets[presetKey]) {
                    this.loadStudioPreset(presetKey);
                    return;
                }

                // Fetch authentic plan from backend
                const domain = presetKey === 'adjudication' ? 'Adjudication' : (presetKey === 'erp' ? 'ERP' : presetKey.charAt(0).toUpperCase() + presetKey.slice(1));
                const res = await fetch('/laraslice/wizard/blueprint/port-plan', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ preset: presetKey, domain: domain, source_type: 'local' })
                });
                const data = await res.json();
                if (data.success && data.plan) {
                    this.portPlanData = data.plan;
                    sessionStorage.setItem('laraslice_port_plan', JSON.stringify(data.plan));
                    localStorage.setItem('laraslice_port_plan', JSON.stringify(data.plan));
                    this.loadPortedToStudio();
                    return;
                }

                // Fallback for adjudication
                if (presetKey === 'adjudication' && studioPresets['legal_dispute_adjudication']) {
                    this.loadStudioPreset('legal_dispute_adjudication');
                }
            } catch (e) {
                console.error('Error loading preset:', e);
                if (presetKey === 'adjudication' && studioPresets['legal_dispute_adjudication']) {
                    this.loadStudioPreset('legal_dispute_adjudication');
                }
            } finally {
                this.presetLoading = false;
                this.selectedPreset = '';
            }
        },

        hasStoredPortPlan() {
            try {
                return !!(sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan'));
            } catch(e) { return false; }
        },

        getStoredSliceCount() {
            try {
                const raw = sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan');
                if (raw) {
                    const p = JSON.parse(raw);
                    return p.studio_slices?.length || p.summary?.total_canonical_slices || 0;
                }
            } catch(e) {}
            return 0;
        },

        getStoredDomain() {
            try {
                const raw = sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan');
                if (raw) {
                    const p = JSON.parse(raw);
                    return p.domain || '';
                }
            } catch(e) {}
            return '';
        },

        generateDomainYaml() {
            this.saveActiveSliceState();
            if (this.portInspectionData && this.portInspectionData.domain_blueprint_yaml) {
                this.domainYamlSource = this.portInspectionData.domain_blueprint_yaml;
                return;
            }
            let lines = [];
            lines.push('schema_version: 1');
            lines.push(`domain: ${this.blueprint.domain || this.portDomain || 'DomainSuite'}`);
            lines.push(`generated_at: "${new Date().toISOString()}"`);
            lines.push(`total_slices: ${this.slices.length}`);
            lines.push('slices:');
            for (let s of this.slices) {
                const bp = s.blueprint || {};
                lines.push(`  - name: ${bp.name || s.name}`);
                lines.push(`    handle: ${bp.handle || s.handle}`);
                lines.push(`    domain: ${bp.domain || this.blueprint.domain || ''}`);
                if (bp.description) lines.push(`    description: "${bp.description}"`);
                if (bp.permissions && bp.permissions.length) {
                    lines.push('    permissions:');
                    for (let p of bp.permissions) lines.push(`      - ${p}`);
                }
                if (bp.models && bp.models.length) {
                    lines.push('    models:');
                    for (let m of bp.models) {
                        lines.push(`      - handle: ${m.handle}`);
                        lines.push(`        table: ${m.table}`);
                        lines.push(`        root: ${m.root ? 'true' : 'false'}`);
                        if (m.fields && m.fields.length) {
                            lines.push('        fields:');
                            for (let f of m.fields) {
                                lines.push(`          - handle: ${f.handle}`);
                                lines.push(`            type: ${f.type}`);
                                lines.push(`            required: ${f.required ? 'true' : 'false'}`);
                                if (f.input_type) lines.push(`            input_type: ${f.input_type}`);
                            }
                        }
                    }
                }
            }
            this.domainYamlSource = lines.join('\n');
        },
        showIntrospectModal: false,
        showNewSliceModal: false,
        newSliceName: '',
        newSliceDomain: '',
        newSliceDescription: '',
        selectedTable: '',
        introspecting: false,
        allRelations: [],
        modelCollapsed: {},
        slices: [],
        activeSliceIdx: 0,

        saveActiveSliceState() {
            if (!this.slices || this.slices.length === 0) return;
            if (this.activeSliceIdx >= this.slices.length) this.activeSliceIdx = 0;
            this.syncToYaml();
            this.slices[this.activeSliceIdx] = {
                name: this.blueprint.name || ('Slice ' + (this.activeSliceIdx + 1)),
                handle: this.blueprint.handle || ('slice_' + (this.activeSliceIdx + 1)),
                blueprint: JSON.parse(JSON.stringify(this.blueprint)),
                allRelations: JSON.parse(JSON.stringify(this.allRelations || [])),
                source: this.source
            };
            try {
                sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
            } catch(e) {}
        },

        switchSlice(idx) {
            if (idx === this.activeSliceIdx || idx < 0 || idx >= this.slices.length) return;
            this.saveActiveSliceState();
            this.activeSliceIdx = idx;
            const targetSlice = this.slices[idx];
            if (targetSlice && targetSlice.blueprint) {
                this.loadParsedBlueprint(JSON.parse(JSON.stringify(targetSlice.blueprint)));
                this.allRelations = JSON.parse(JSON.stringify(targetSlice.allRelations || []));
                this.source = targetSlice.source || '';
                this.syncToYaml();
            }
            this.activeModelIndex = 0;
            if (targetSlice) {
                this.notifyCopilotOfSlice(targetSlice);
            }
            try {
                sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
            } catch(e) {}
        },

        notifyCopilotOfSlice(slice) {
            if (!slice) return;
            try {
                const bp = slice.blueprint || this.blueprint || {};
                const models = Array.isArray(bp.models) ? bp.models : [];
                const childModels = models.slice(1).map(m => m.table || m.handle || m.name);
                const tablesData = models.map(m => ({
                    name: m.table || m.handle || m.name,
                    columns_count: (m.fields || []).length
                }));
                window.dispatchEvent(new CustomEvent('laraslice-slice-selected', {
                    detail: {
                        name: slice.name || slice.handle || bp.name,
                        title: slice.name || bp.name || slice.handle,
                        domain: slice.domain || bp.domain || 'Blueprint',
                        version: bp.version || 'Blueprint Low-Code',
                        description: bp.description || 'Declarative YAML/Visual Slice Blueprint',
                        tables_data: tablesData,
                        child_tables: childModels,
                        permissions: bp.permissions || [],
                        is_blueprint: true
                    }
                }));
            } catch (e) {}
        },

        createNewSlice() {
            const name = (this.newSliceName || '').trim();
            if (!name) return alert('Please enter a Slice Name');
            
            const handle = name.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/^_+|_+$/g, '');
            const baseSlug = handle.replace(/s$/, '') || 'item';
            const modelHandle = baseSlug;
            const domain = (this.newSliceDomain || this.blueprint.domain || '').trim();
            const desc = (this.newSliceDescription || '').trim() || `Manage ${name.toLowerCase()} records`;

            const freshBlueprint = {
                schema_version: 1,
                name: name,
                handle: handle,
                domain: domain,
                description: desc,
                author: this.blueprint.author || 'LaraSlice Team',
                version: '1.0.0',
                permissions: [
                    `${baseSlug}.view`,
                    `${baseSlug}.create`,
                    `${baseSlug}.edit`,
                    `${baseSlug}.delete`
                ],
                models: [
                    {
                        handle: modelHandle,
                        table: handle.endsWith('s') ? handle : handle + 's',
                        root: true,
                        timestamps: true,
                        soft_deletes: false,
                        description: `Primary ${modelHandle} records`,
                        fields: [
                            {
                                handle: 'name',
                                label: 'Name',
                                type: 'string',
                                required: true,
                                nullable: false,
                                width: 50,
                                length: '255',
                                default: '',
                                options: {},
                                optionsText: ''
                            }
                        ],
                        relations: []
                    }
                ]
            };

            // 1. Save currently active slice state so it is NOT overwritten!
            this.saveActiveSliceState();

            // 2. Append new slice to this.slices
            const newSliceObj = {
                name: name,
                handle: handle,
                blueprint: freshBlueprint,
                allRelations: [],
                source: ''
            };
            this.slices.push(newSliceObj);

            // 3. Switch active index to the new slice
            this.activeSliceIdx = this.slices.length - 1;

            // 4. Load the new slice
            this.loadParsedBlueprint(freshBlueprint);
            this.allRelations = [];
            this.syncToYaml();
            newSliceObj.source = this.source;

            // 5. Reset modal & close
            this.showNewSliceModal = false;
            this.newSliceName = '';
            this.newSliceDomain = '';
            this.newSliceDescription = '';
            this.mode = 'visual';
        },

        removeSlice(idx) {
            if (this.slices.length <= 1) return;
            const toRemove = this.slices[idx]?.name || ('Slice ' + (idx + 1));
            if (!confirm(`Are you sure you want to remove slice "${toRemove}"?`)) return;
            this.slices.splice(idx, 1);
            if (this.activeSliceIdx >= this.slices.length) {
                this.activeSliceIdx = this.slices.length - 1;
            } else if (this.activeSliceIdx === idx) {
                this.activeSliceIdx = Math.max(0, idx - 1);
            }
            const current = this.slices[this.activeSliceIdx];
            if (current && current.blueprint) {
                this.loadParsedBlueprint(JSON.parse(JSON.stringify(current.blueprint)));
                this.allRelations = JSON.parse(JSON.stringify(current.allRelations || []));
                this.source = current.source || '';
                this.syncToYaml();
            }
        },

        getStudioSliceFieldsCount(sl) {
            if (!sl || !sl.blueprint || !sl.blueprint.models) return 0;
            return sl.blueprint.models.reduce((acc, m) => acc + ((m.fields && m.fields.length) ? m.fields.length : 0), 0);
        },

        updateActiveSliceName() {
            if (this.slices && this.slices[this.activeSliceIdx]) {
                this.slices[this.activeSliceIdx].name = this.blueprint.name || ('Slice ' + (this.activeSliceIdx + 1));
                this.slices[this.activeSliceIdx].handle = this.blueprint.handle;
            }
        },

        loadStudioPreset(presetKey) {
            if (!presetKey) return;
            if (studioPresets[presetKey]) {
                const presetSlices = JSON.parse(JSON.stringify(studioPresets[presetKey]));
                this.slices = presetSlices.map(s => {
                    if (s.blueprint) {
                        return {
                            name: s.name,
                            handle: s.handle,
                            blueprint: JSON.parse(JSON.stringify(s.blueprint)),
                            allRelations: JSON.parse(JSON.stringify(s.allRelations || (s.blueprint.models || []).flatMap(m => m.relations || []))),
                            source: s.source || ''
                        };
                    }
                    const bp = {
                        schema_version: 1,
                        name: s.name,
                        handle: s.handle,
                        domain: s.domain,
                        description: s.description,
                        author: 'LaraSlice Team',
                        version: '1.0.0',
                        permissions: s.permissions || [],
                        models: s.models || [],
                        workflow: s.workflow || null,
                        roles: s.roles || []
                    };
                    return {
                        name: s.name,
                        handle: s.handle,
                        blueprint: bp,
                        allRelations: (s.models || []).flatMap(m => m.relations || []),
                        source: ''
                    };
                });
                this.activeSliceIdx = 0;
                const first = this.slices[0];
                this.loadParsedBlueprint(first.blueprint);
                this.allRelations = JSON.parse(JSON.stringify(first.allRelations || []));
                if (first.source) {
                    this.source = first.source;
                } else {
                    this.syncToYaml();
                    first.source = this.source;
                }
                this.generateDomainYaml();
                return;
            }
            this.switchTemplate(presetKey);
        },

        switchTemplate(tpl) {
            if (!tpl) return;
            const url = studioUrl || window.location.pathname;
            window.location.href = `${url}?template=${encodeURIComponent(tpl)}`;
        },

        loadSlice(slice) {
            if (!slice) return;
            const url = studioUrl || window.location.pathname;
            window.location.href = `${url}?slice=${encodeURIComponent(slice)}`;
        },
        newPermInput: '',
        blueprint: {
            schema_version: 1,
            name: '',
            handle: '',
            domain: '',
            description: '',
            author: '',
            version: '1.0.0',
            permissions: [],
            models: []
        },

        generateStandardPermissions() {
            let base = this.blueprint.handle || 'slice';
            if (base.endsWith('s')) {
                base = base.replace(/s$/, '');
            }
            const standard = [
                `${base}.view`,
                `${base}.create`,
                `${base}.edit`,
                `${base}.delete`
            ];
            if (!Array.isArray(this.blueprint.permissions)) {
                this.blueprint.permissions = [];
            }
            standard.forEach(p => {
                if (!this.blueprint.permissions.includes(p)) {
                    this.blueprint.permissions.push(p);
                }
            });
            this.syncToYaml();
        },

        addCustomPermission() {
            let val = (this.newPermInput || '').trim();
            if (!val) return;
            let base = this.blueprint.handle || 'slice';
            if (base.endsWith('s')) {
                base = base.replace(/s$/, '');
            }
            if (!val.includes('.')) {
                val = `${base}.${val}`;
            }
            if (!Array.isArray(this.blueprint.permissions)) {
                this.blueprint.permissions = [];
            }
            if (!this.blueprint.permissions.includes(val)) {
                this.blueprint.permissions.push(val);
            }
            this.newPermInput = '';
            this.syncToYaml();
        },

        removePermission(idx) {
            if (Array.isArray(this.blueprint.permissions)) {
                this.blueprint.permissions.splice(idx, 1);
                this.syncToYaml();
            }
        },

        getGridSpan(width) {
            const w = parseInt(width) || 50;
            if (w >= 100) return 12;
            if (w >= 75) return 9;
            if (w >= 66) return 8;
            if (w >= 50) return 6;
            if (w >= 33) return 4;
            if (w >= 25) return 3;
            return 6;
        },

        init() {
            // Restore active ported plan from Porting Studio (sessionStorage or localStorage)
            try {
                const rawPlan = sessionStorage.getItem('laraslice_port_plan') || localStorage.getItem('laraslice_port_plan');
                if (rawPlan) {
                    const parsedPlan = JSON.parse(rawPlan);
                    if (parsedPlan && (parsedPlan.studio_slices || parsedPlan.modules)) {
                        this.portPlanData = parsedPlan;
                    }
                }
            } catch(e) {}

            // Load saved presets from backend
            fetch('/laraslice/wizard/port-saved-presets', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => { if (d.success) this.savedPresets = d.presets || []; })
                .catch(() => {});

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('port') === '1') {
                this.showPortModal = true;
            }
            if (urlParams.get('load_port') === '1' || urlParams.get('auto_load_port') === '1') {
                this.loadPortedToStudio();
                return;
            }
            if (urlParams.get('preset')) {
                this.choosePreset(urlParams.get('preset'));
                return;
            }
            // NOTE: Porting Analyze is intentionally NOT auto-fired here.
            // The developer opens the Porting Studio dialog and clicks Analyze explicitly.
            // 1. Restore all slices if passed from backend (e.g. after form validation / plan review)
            if (allSlicesJson) {
                try {
                    const parsed = typeof allSlicesJson === 'string' ? JSON.parse(allSlicesJson) : allSlicesJson;
                    const isCorrupt = Array.isArray(parsed) && parsed[0]?.blueprint?.models?.[0]?.fields?.some((f, idx, arr) => arr.findIndex(x => x.handle === f.handle) !== idx);
                    if (!isCorrupt && Array.isArray(parsed) && parsed.length > 0) {
                        this.slices = parsed;
                        this.activeSliceIdx = parseInt(activeSliceIdx) || 0;
                        if (this.activeSliceIdx >= this.slices.length) this.activeSliceIdx = 0;
                        const activeSl = this.slices[this.activeSliceIdx];
                        if (activeSl && activeSl.blueprint) {
                            this.loadParsedBlueprint(activeSl.blueprint);
                            this.allRelations = JSON.parse(JSON.stringify(activeSl.allRelations || []));
                            this.source = activeSl.source || this.source;
                            this.syncToYaml();
                        }
                        return;
                    }
                } catch (e) {}
            }

            const tplParam = urlParams.get('template');
            if (tplParam && studioPresets[tplParam]) {
                this.loadStudioPreset(tplParam);
                return;
            }

            const sliceParam = urlParams.get('slice');
            if (sliceParam && initialBlueprint) {
                // Restore existing studio slices collection from sessionStorage if present so the domain tabs remain available
                try {
                    const stored = sessionStorage.getItem('laraslice_studio_slices');
                    if (stored) {
                        const parsed = JSON.parse(stored);
                        if (Array.isArray(parsed) && parsed.length > 0) {
                            this.slices = parsed;
                        }
                    }
                } catch(e) {}

                // Load the live requested blueprint directly from disk/controller
                this.loadParsedBlueprint(initialBlueprint);
                this.source = initialSource || this.source;

                // Sync into this.slices collection
                if (Array.isArray(this.slices) && this.slices.length > 0) {
                    const foundIdx = this.slices.findIndex(s => 
                        (s.name && s.name.toLowerCase() === sliceParam.toLowerCase()) || 
                        (s.handle && s.handle.toLowerCase() === sliceParam.toLowerCase())
                    );
                    if (foundIdx !== -1) {
                        this.activeSliceIdx = foundIdx;
                        this.slices[foundIdx].blueprint = JSON.parse(JSON.stringify(this.blueprint));
                        this.slices[foundIdx].source = this.source;
                        this.slices[foundIdx].name = this.blueprint.name;
                    } else {
                        this.slices.push({
                            name: this.blueprint.name || sliceParam,
                            handle: this.blueprint.handle || sliceParam.toLowerCase(),
                            domain: this.blueprint.domain || '',
                            blueprint: JSON.parse(JSON.stringify(this.blueprint)),
                            allRelations: JSON.parse(JSON.stringify(this.allRelations || [])),
                            source: this.source
                        });
                        this.activeSliceIdx = this.slices.length - 1;
                    }
                } else {
                    this.slices = [{
                        name: this.blueprint.name || sliceParam,
                        handle: this.blueprint.handle || sliceParam.toLowerCase(),
                        domain: this.blueprint.domain || '',
                        blueprint: JSON.parse(JSON.stringify(this.blueprint)),
                        allRelations: JSON.parse(JSON.stringify(this.allRelations || [])),
                        source: this.source
                    }];
                    this.activeSliceIdx = 0;
                }

                sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
                sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                this.syncToYaml();
                return;
            }

            // Restore from sessionStorage if available
            try {
                const stored = sessionStorage.getItem('laraslice_studio_slices');
                if (stored) {
                    const parsed = JSON.parse(stored);
                    const isCorrupt = Array.isArray(parsed) && parsed[0]?.blueprint?.models?.[0]?.fields?.some((f, idx, arr) => arr.findIndex(x => x.handle === f.handle) !== idx);
                    if (isCorrupt) {
                        sessionStorage.removeItem('laraslice_studio_slices');
                        sessionStorage.removeItem('laraslice_active_slice_idx');
                    } else if (Array.isArray(parsed) && parsed.length > 0) {
                        this.slices = parsed;
                        const storedIdx = parseInt(sessionStorage.getItem('laraslice_active_slice_idx') || '0');
                        this.activeSliceIdx = (storedIdx >= 0 && storedIdx < this.slices.length) ? storedIdx : 0;
                        const activeSl = this.slices[this.activeSliceIdx];
                        if (activeSl && activeSl.blueprint) {
                            this.loadParsedBlueprint(activeSl.blueprint);
                            this.allRelations = JSON.parse(JSON.stringify(activeSl.allRelations || []));
                            this.source = activeSl.source || this.source;
                            this.syncToYaml();
                        }
                        return;
                    }
                }
            } catch (e) {}

            this.slices = [{
                name: this.blueprint.name || 'Primary Slice',
                handle: this.blueprint.handle || 'primary_slice',
                blueprint: JSON.parse(JSON.stringify(this.blueprint)),
                allRelations: JSON.parse(JSON.stringify(this.allRelations || [])),
                source: this.source
            }];
            this.activeSliceIdx = 0;

            // Initialize collapse state - expand all by default
            this.blueprint.models.forEach((_, idx) => {
                this.modelCollapsed[idx] = false;
            });

            if (this.slices && this.slices[this.activeSliceIdx]) {
                this.notifyCopilotOfSlice(this.slices[this.activeSliceIdx]);
            }
        },

        loadParsedBlueprint(data) {
            if (!data || typeof data !== 'object') return;
            this.blueprint.schema_version = data.schema_version || 1;
            this.blueprint.name = data.name || '';
            this.blueprint.handle = this.toSnakeCase(data.handle || data.name || '');
            this.blueprint.domain = data.domain || '';
            this.blueprint.module = data.module || '';
            this.blueprint.description = data.description || '';
            this.blueprint.author = data.author || '';
            this.blueprint.version = data.version || '1.0.0';
            this.blueprint.permissions = Array.isArray(data.permissions) ? data.permissions : [];
            this.blueprint.workflow = data.workflow || null;
            this.blueprint.roles = Array.isArray(data.roles) ? data.roles : [];
            this.blueprint.navigation = data.navigation || null;
            this.blueprint.pages = data.pages || null;
            if (this.blueprint.permissions.length === 0 && this.blueprint.handle) {
                let base = this.blueprint.handle.replace(/s$/, '');
                this.blueprint.permissions = [`${base}.view`, `${base}.create`, `${base}.edit`, `${base}.delete`];
            }
            if (data.ui && typeof data.ui === 'object') {
                this.blueprint.ui = JSON.parse(JSON.stringify(data.ui));
            } else {
                this.blueprint.ui = null;
            }
            this.blueprint.models = [];
            this.allRelations = [];

            const rawModels = Array.isArray(data.models) ? data.models : [];
            rawModels.forEach(m => {
                const mHandle = this.toSnakeCase(m.handle || m.name || 'model');
                const modelObj = {
                    handle: mHandle,
                    name: m.name || this.toStudlyCase(mHandle),
                    table: m.table || (mHandle + 's'),
                    root: !!m.root,
                    timestamps: m.timestamps !== false,
                    soft_deletes: !!m.soft_deletes,
                    description: m.description || '',
                    fields: [],
                    relations: []
                };

                const rawFields = Array.isArray(m.fields) ? m.fields : [];
                const seenFields = new Set();
                const reservedHandles = ['id', 'created_at', 'updated_at', 'deleted_at'];
                rawFields.forEach(f => {
                    const fHandle = this.toSnakeCase(f.handle || f.name || '');
                    if (!fHandle || reservedHandles.includes(fHandle) || seenFields.has(fHandle)) return;
                    seenFields.add(fHandle);

                    const fType = f.type || 'string';
                    const fOptions = (f.options && typeof f.options === 'object') ? f.options : {};
                    const fOptionsText = Object.entries(fOptions).map(([k, v]) => `${k}: ${v}`).join(', ');

                    const isNullable = f.nullable !== undefined ? !!f.nullable : (f.required !== undefined ? !f.required : true);
                    const isRequired = !isNullable;

                    modelObj.fields.push({
                        handle: fHandle,
                        label: f.label || (fHandle ? (fHandle.charAt(0).toUpperCase() + fHandle.slice(1).replace(/_/g, ' ')) : ''),
                        type: fType,
                        input_type: f.input_type || (fType === 'enum' ? 'select' : (['text', 'json'].includes(fType) ? 'textarea' : (fType === 'boolean' ? 'checkbox' : (fType === 'date' ? 'date' : 'text')))),
                        required: isRequired,
                        nullable: isNullable,
                        hidden: !!f.hidden,
                        width: parseInt(f.width) || (['text', 'json'].includes(fType) ? 100 : 50),
                        length: f.length !== undefined && f.length !== null ? String(f.length) : '',
                        default: f.default !== undefined && f.default !== null ? String(f.default) : '',
                        options: fOptions,
                        optionsText: fOptionsText
                    });
                });

                const rawRelations = Array.isArray(m.relations) ? m.relations : [];
                const seenRelations = new Set();
                rawRelations.forEach(r => {
                    const rName = this.toSnakeCase(r.name || '');
                    if (!rName || seenRelations.has(rName)) return;
                    seenRelations.add(rName);

                    const relObj = {
                        source_model: mHandle,
                        name: rName,
                        type: r.type || 'belongsTo',
                        model: this.toSnakeCase(r.model || ''),
                        foreign_key: r.foreign_key || ''
                    };
                    modelObj.relations.push(relObj);
                    this.allRelations.push(relObj);
                });

                this.blueprint.models.push(modelObj);
            });
            this.ensureUiConfig();
            this.syncToYaml();
        },

        toggleModelCollapse(idx) {
            this.modelCollapsed[idx] = !this.modelCollapsed[idx];
        },

        collapseAllModels() {
            this.blueprint.models.forEach((_, idx) => {
                this.modelCollapsed[idx] = true;
            });
        },

        expandAllModels() {
            this.blueprint.models.forEach((_, idx) => {
                this.modelCollapsed[idx] = false;
            });
        },

        setRootModel(modelIndex) {
            this.blueprint.models.forEach((m, idx) => {
                m.root = (idx === modelIndex);
            });
            this.syncToYaml();
        },

        onRequiredToggle(field) {
            if (field.required) {
                field.nullable = false;
            } else {
                field.nullable = true;
            }
        },

        onNullableToggle(field) {
            if (field.nullable) {
                field.required = false;
            } else {
                field.required = true;
            }
        },

        addRelationship() {
            const src = this.blueprint.models[0]?.handle || 'model';
            const otherModel = this.blueprint.models.find(m => m.handle !== src);
            const tgt = otherModel ? otherModel.handle : 'user';
            const rCount = this.allRelations.length + 1;
            this.allRelations.push({
                source_model: src,
                name: tgt ? (tgt.endsWith('s') ? tgt : tgt + 's') : ('relation_' + rCount),
                type: 'hasMany',
                model: tgt,
                foreign_key: src ? (src + '_id') : ''
            });
            this.syncToYaml();
        },

        removeRelationship(index) {
            this.allRelations.splice(index, 1);
            this.syncToYaml();
        },

        toSnakeCase(str) {
            if (!str) return '';
            return str.replace(/([a-z])([A-Z])/g, '$1_$2')
                      .replace(/[\s\-]+/g, '_')
                      .toLowerCase()
                      .replace(/^[^a-z]+/, '')
                      .replace(/_+/g, '_');
        },

        toStudlyCase(str) {
            if (!str) return '';
            return str.replace(/[\_\-\s]+(.)?/g, (_, c) => c ? c.toUpperCase() : '')
                      .replace(/^(.)/, c => c.toUpperCase());
        },

        parseYamlToBlueprint(yamlText) {
            const raw = yamlText || this.source;
            if (!raw || !raw.trim()) return;

            try {
                let doc = null;
                if (typeof jsyaml !== 'undefined') {
                    doc = jsyaml.load(raw);
                }
                if (!doc || typeof doc !== 'object') return;

                // 1. Check if this is a Domain Suite YAML (contains 'slices' array)
                if (doc.slices && Array.isArray(doc.slices) && doc.slices.length > 0) {
                    this.domainName = doc.domain || this.domainName;
                    this.blueprint.domain = this.domainName;
                    this.slices = doc.slices.map((s, idx) => {
                        const sName = s.name || ('Slice ' + (idx + 1));
                        const sHandle = this.toSnakeCase(s.handle || sName);
                        const sSource = typeof jsyaml !== 'undefined' ? jsyaml.dump(s, { indent: 2, lineWidth: -1, noRefs: true }) : '';
                        return {
                            name: sName,
                            handle: sHandle,
                            domain: s.domain || this.domainName,
                            blueprint: JSON.parse(JSON.stringify(s)),
                            allRelations: (s.models || []).flatMap(m => (m.relations || []).map(r => ({
                                source_model: this.toSnakeCase(m.handle || ''),
                                name: this.toSnakeCase(r.name || ''),
                                type: r.type || 'belongsTo',
                                model: this.toSnakeCase(r.model || ''),
                                foreign_key: r.foreign_key || ''
                            }))),
                            source: sSource
                        };
                    });
                    this.activeSliceIdx = 0;
                    if (this.slices.length > 0) {
                        this.loadParsedBlueprint(this.slices[0].blueprint);
                        this.allRelations = JSON.parse(JSON.stringify(this.slices[0].allRelations || []));
                        this.source = this.slices[0].source || '';
                    }
                    this.domainYamlSource = raw;
                    return;
                }

                // 2. Otherwise it is a Single Slice Blueprint
                this.loadParsedBlueprint(doc);
                this.syncToYaml();
            } catch (e) {
                console.warn('YAML Parse Warning:', e);
            }
        },

        syncToYaml() {
            const cleanModels = (this.blueprint.models || []).map(m => {
                const mHandle = this.toSnakeCase(m.handle || m.name || 'model');
                const mObj = {
                    handle: mHandle,
                    name: m.name || this.toStudlyCase(mHandle),
                    table: m.table || (mHandle + 's'),
                    root: !!m.root,
                    timestamps: m.timestamps !== false,
                    soft_deletes: !!m.soft_deletes,
                    description: m.description || '',
                    fields: (m.fields || [])
                        .filter(f => !['id', 'created_at', 'updated_at', 'deleted_at'].includes(this.toSnakeCase(f.handle || '')))
                        .map(f => {
                            const fHandle = this.toSnakeCase(f.handle || 'field');
                            const isReq = !!f.required;
                            const isNll = f.nullable !== undefined ? !!f.nullable : !isReq;
                            const finalReq = isNll ? false : true;
                            const finalNll = !finalReq;
                            const fObj = {
                                handle: fHandle,
                                label: f.label || (fHandle.charAt(0).toUpperCase() + fHandle.slice(1).replace(/_/g, ' ')),
                                type: f.type || 'string',
                                input_type: f.input_type || 'text',
                                required: finalReq,
                                nullable: finalNll,
                                width: parseInt(f.width) || 50,
                            };
                            if (f.hidden) fObj.hidden = true;
                            if (f.length) fObj.length = f.length;
                            if (f.default) fObj.default = f.default;
                            if (f.type === 'enum' && f.options && Object.keys(f.options).length) {
                                fObj.options = f.options;
                            }
                            return fObj;
                        }),
                    relations: (this.allRelations && this.allRelations.length > 0
                        ? this.allRelations.filter(r => r.source_model === mHandle)
                        : (m.relations || [])
                    ).map(r => ({
                        name: this.toSnakeCase(r.name || 'relation'),
                        type: r.type || 'belongsTo',
                        model: this.toSnakeCase(r.model || ''),
                        foreign_key: r.foreign_key || ''
                    }))
                };
                return mObj;
            });

            // Auto-link child models to root aggregate if missing and not a catalog
            const rootModel = cleanModels.find(m => m.root) || cleanModels[0];
            const isCatalogSlice = !!this.blueprint.taxonomy || /setting|master|catalog|reference/i.test(this.blueprint.name || '');
            if (rootModel && cleanModels.length > 1 && !isCatalogSlice) {
                cleanModels.forEach(cm => {
                    if (!cm.root) {
                        const childFields = (cm.fields || []).map(f => f.handle);
                        let bestFk = childFields.includes(rootModel.handle + '_id') ? (rootModel.handle + '_id') : null;
                        if (!bestFk) {
                            bestFk = rootModel.handle + '_id';
                            if (!childFields.includes(bestFk)) {
                                cm.fields.push({
                                    handle: bestFk,
                                    label: rootModel.name || (rootModel.handle.charAt(0).toUpperCase() + rootModel.handle.slice(1)),
                                    type: 'foreign_id',
                                    input_type: 'select',
                                    required: false,
                                    nullable: true,
                                    width: 50
                                });
                            }
                        }
                        const hasRel = (rootModel.relations || []).some(r => r.model === cm.handle);
                        if (!hasRel) {
                            rootModel.relations.push({
                                name: cm.handle.endsWith('s') ? cm.handle : (cm.handle + 's'),
                                type: 'hasMany',
                                model: cm.handle,
                                foreign_key: bestFk
                            });
                        }
                        const childHasRel = (cm.relations || []).some(r => r.model === rootModel.handle);
                        if (!childHasRel) {
                            cm.relations.push({
                                name: rootModel.handle,
                                type: 'belongsTo',
                                model: rootModel.handle,
                                foreign_key: bestFk
                            });
                        }
                    }
                });
            }

            const bpObj = {
                schema_version: 1,
                name: this.blueprint.name || 'Untitled Slice',
                handle: this.toSnakeCase(this.blueprint.handle || this.blueprint.name || 'untitled_slice'),
                domain: this.blueprint.domain || '',
                description: this.blueprint.description || '',
                author: this.blueprint.author || '',
                version: this.blueprint.version || '1.0.0',
                permissions: this.blueprint.permissions || [],
                models: cleanModels
            };

            if (this.blueprint.workflow && this.blueprint.workflow.states && this.blueprint.workflow.states.length) {
                bpObj.workflow = JSON.parse(JSON.stringify(this.blueprint.workflow));
            }
            if (this.blueprint.roles && this.blueprint.roles.length) {
                bpObj.roles = JSON.parse(JSON.stringify(this.blueprint.roles));
            }
            if (this.blueprint.ui) {
                const rawUi = this.blueprint.ui;
                const cleanUi = {
                    presentation: rawUi.presentation || { create: 'drawer', edit: 'drawer' },
                    directory: {
                        columns: (rawUi.directory?.columns || []).map(c => ({
                            handle: c.handle || c.field || c.key,
                            label: c.label || c.handle,
                            formatter: c.formatter || c.type || 'text',
                            sortable: c.sortable !== false
                        })),
                        quick_filters: (rawUi.directory?.quick_filters || []).map(qf => ({
                            handle: qf.handle || qf.field || qf.key,
                            label: qf.label || qf.handle,
                            is_searchable: qf.is_searchable !== false && qf.searchable !== false
                        })),
                        sidebar_filters: (rawUi.directory?.sidebar_filters || []).map(sf => ({
                            handle: sf.handle || sf.field || sf.key,
                            label: sf.label || sf.handle,
                            type: sf.type || 'select'
                        }))
                    },
                    forms: {
                        presentation: rawUi.forms?.presentation || rawUi.presentation || { create: 'drawer', edit: 'drawer' },
                        sections: (rawUi.forms?.sections || []).map((sec, idx) => ({
                            key: sec.key || this.toSnakeCase(sec.title || ('section_' + (idx + 1))),
                            title: sec.title || ('Section ' + (idx + 1)),
                            grid: parseInt(sec.grid || sec.columns || 3),
                            fields: (sec.fields || []).map(fld => typeof fld === 'string' ? fld : ({
                                handle: fld.handle || fld.field || fld.key,
                                span: parseInt(fld.span || 1),
                                is_searchable: fld.is_searchable !== false && (fld.searchable === true || (fld.handle || '').endsWith('_id'))
                            }))
                        }))
                    }
                };
                if (rawUi.directory?.row_actions) {
                    cleanUi.directory.row_actions = rawUi.directory.row_actions;
                }
                bpObj.ui = cleanUi;
            }


            if (typeof jsyaml !== 'undefined') {
                this.source = jsyaml.dump(bpObj, { indent: 2, lineWidth: -1, noRefs: true });
            }

            if (this.slices && this.slices[this.activeSliceIdx]) {
                this.slices[this.activeSliceIdx].name = bpObj.name;
                this.slices[this.activeSliceIdx].handle = bpObj.handle;
                this.slices[this.activeSliceIdx].domain = bpObj.domain;
                this.slices[this.activeSliceIdx].source = this.source;
                this.slices[this.activeSliceIdx].blueprint = JSON.parse(JSON.stringify(bpObj));
                this.slices[this.activeSliceIdx].allRelations = JSON.parse(JSON.stringify(this.allRelations || []));
                try {
                    sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                    sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
                } catch(e) {}
            }
        },

        syncFromDomainYaml() {
            if (!this.domainYamlSource || !this.domainYamlSource.trim()) return;
            this.parseYamlToBlueprint(this.domainYamlSource);
        },

        syncFromYaml() {
            this.parseYamlToBlueprint();
        },

        formatYaml() {
            this.syncToYaml();
        },

        moveFieldUp(mIdx, fIdx) {
            if (fIdx <= 0) return;
            const fields = this.blueprint.models[mIdx].fields;
            const temp = fields[fIdx];
            fields[fIdx] = fields[fIdx - 1];
            fields[fIdx - 1] = temp;
            this.syncToYaml();
        },

        moveFieldDown(mIdx, fIdx) {
            const fields = this.blueprint.models[mIdx].fields;
            if (fIdx >= fields.length - 1) return;
            const temp = fields[fIdx];
            fields[fIdx] = fields[fIdx + 1];
            fields[fIdx + 1] = temp;
            this.syncToYaml();
        },

        moveModelUp(mIdx) {
            if (mIdx <= 0) return;
            const models = this.blueprint.models;
            const temp = models[mIdx];
            models[mIdx] = models[mIdx - 1];
            models[mIdx - 1] = temp;
            this.syncToYaml();
        },

        moveModelDown(mIdx) {
            const models = this.blueprint.models;
            if (mIdx >= models.length - 1) return;
            const temp = models[mIdx];
            models[mIdx] = models[mIdx + 1];
            models[mIdx + 1] = temp;
            this.syncToYaml();
        },

        addModel() {
            const count = this.blueprint.models.length + 1;
            const newHandle = `entity_${count}`;
            this.blueprint.models.push({
                handle: newHandle,
                table: `${newHandle}s`,
                root: count === 1,
                timestamps: true,
                soft_deletes: false,
                description: '',
                fields: [
                    { handle: 'name', label: 'Name', type: 'string', required: true, nullable: false, width: 50, length: '255', default: '' },
                    { handle: 'status', label: 'Status', type: 'string', required: true, nullable: false, width: 50, length: '30', default: 'active' }
                ],
                relations: []
            });
            // Expand the newly added model
            this.modelCollapsed[this.blueprint.models.length - 1] = false;
            this.syncToYaml();
        },

        removeModel(index) {
            const removed = this.blueprint.models[index];
            if (removed) {
                // remove relations associated with this model
                this.allRelations = this.allRelations.filter(r => r.source_model !== removed.handle && r.model !== removed.handle);
            }
            this.blueprint.models.splice(index, 1);
            if (this.blueprint.models.length > 0 && !this.blueprint.models.some(m => m.root)) {
                this.blueprint.models[0].root = true;
            }
            this.syncToYaml();
        },

        updateOptionsFromText(field) {
            if (!field.optionsText) {
                field.options = {};
            } else {
                const map = {};
                field.optionsText.split(',').forEach(item => {
                    const p = item.split(':');
                    if (p.length >= 2) {
                        const k = p[0].trim();
                        const v = p.slice(1).join(':').trim();
                        if (k) map[k] = v || k;
                    } else if (item.trim()) {
                        const k = item.trim();
                        map[k] = k.charAt(0).toUpperCase() + k.slice(1);
                    }
                });
                field.options = map;
            }
            this.syncToYaml();
        },

        ensureUiConfig() {
            if (!this.blueprint) return;
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const fields = rootModel.fields || [];

            if (!this.blueprint.ui || typeof this.blueprint.ui !== 'object') {
                this.blueprint.ui = {
                    presentation: { create: 'drawer', edit: 'drawer' },
                    directory: {
                        columns: [],
                        quick_filters: [],
                        sidebar_filters: []
                    },
                    forms: {
                        mode: 'drawer',
                        drawer_width: 'max-w-3xl',
                        sections: []
                    }
                };
            }

            const ui = this.blueprint.ui;
            ui.presentation = ui.presentation || { create: 'drawer', edit: 'drawer' };
            ui.directory = ui.directory || {};
            ui.directory.row_actions = ui.directory.row_actions || {
                display: 'dropdown',
                items: [
                    { handle: 'view', label: 'View Details', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                    { handle: 'edit', label: 'Edit Record', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'delete', label: 'Delete Record', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ]
            };
            // Bulk Actions Default (index table multi-select)
            if (!Array.isArray(ui.directory.bulk_actions)) {
                ui.directory.bulk_actions = [
                    { handle: 'export', label: 'Export Selected', type: 'native', icon: 'download' },
                    { handle: 'delete', label: 'Delete Selected', type: 'native', icon: 'trash-2', danger: true },
                ];
            } else {
                ui.directory.bulk_actions = ui.directory.bulk_actions.map(ba => {
                    if (typeof ba === 'string') {
                        return { handle: ba, label: ba.replace(/_/g, ' '), type: 'native', icon: 'zap' };
                    }
                    return ba;
                });
            }
            ui.forms = ui.forms || {};
            ui.forms.mode = ui.presentation?.create || ui.forms.mode || 'drawer';

            // Normalize existing columns
            if (Array.isArray(ui.directory.columns) && ui.directory.columns.length > 0) {
                ui.directory.columns.forEach(col => {
                    col.handle = col.handle || col.key || 'col';
                    col.type = col.type || col.formatter || 'text';
                    col.formatter = col.type;
                    col.sortable = col.sortable !== false;
                });
            }

            // Normalize quick filters
            if (Array.isArray(ui.directory.quick_filters) && ui.directory.quick_filters.length > 0) {
                ui.directory.quick_filters.forEach(qf => {
                    qf.handle = qf.handle || qf.field || '';
                    qf.field = qf.handle;
                    qf.searchable = qf.searchable !== false && (qf.is_searchable !== false);
                    qf.is_searchable = qf.searchable;
                });
            }

            // Normalize sidebar filters
            if (Array.isArray(ui.directory.sidebar_filters) && ui.directory.sidebar_filters.length > 0) {
                ui.directory.sidebar_filters.forEach(sf => {
                    sf.handle = sf.handle || sf.field || '';
                    sf.field = sf.handle;
                    sf.type = sf.type || 'select';
                });
            }

            // Normalize form sections
            if (Array.isArray(ui.forms.sections) && ui.forms.sections.length > 0) {
                ui.forms.sections.forEach(sec => {
                    sec.grid = parseInt(sec.grid || sec.columns || 3);
                    sec.columns = sec.grid;
                    if (Array.isArray(sec.fields)) {
                        sec.fields.forEach(fld => {
                            fld.span = parseInt(fld.span || 1);
                            fld.searchable = fld.searchable !== false && (fld.is_searchable === true || (fld.handle || '').endsWith('_id'));
                            fld.is_searchable = fld.searchable;
                        });
                    }
                });
            }



            if (!Array.isArray(ui.directory.columns) || ui.directory.columns.length === 0) {
                const blacklist = ['id', 'company_id', 'added_by', 'last_updated_by', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
                const eligible = fields.filter(f => !blacklist.includes(f.handle));
                ui.directory.columns = [
                    { handle: 'code', label: 'Code', type: 'code_badge', width: 'w-24', sortable: true },
                    ...eligible.slice(0, 5).map(f => {
                        let type = 'text';
                        const h = (f.handle || '').toLowerCase();
                        if (h.includes('user') || h.includes('employee') || h.includes('client')) type = 'composite_user';
                        else if (h.includes('status')) type = 'status_pill';
                        else if (h.includes('amount') || h.includes('price') || h.includes('total') || h.includes('budget')) type = 'money_breakdown';
                        else if (h.includes('progress') || h.includes('percent')) type = 'progress_bar';
                        else if (f.type === 'date' || f.type === 'datetime' || f.type === 'timestamp') type = 'date_time';
                        return {
                            handle: f.handle,
                            label: f.label || (f.handle.charAt(0).toUpperCase() + f.handle.slice(1).replace(/_/g, ' ')),
                            type: type,
                            sortable: true
                        };
                    })
                ];
            }

            if (!Array.isArray(ui.directory.quick_filters) || ui.directory.quick_filters.length === 0) {
                const statusField = fields.find(f => (f.handle || '').includes('status'));
                const relField = fields.find(f => (f.handle || '').endsWith('_id') && !['company_id', 'created_by', 'updated_by'].includes(f.handle));
                ui.directory.quick_filters = [];
                if (relField) {
                    ui.directory.quick_filters.push({
                        field: relField.handle,
                        label: relField.label || 'Filter',
                        type: 'searchable_select',
                        searchable: true
                    });
                }
                if (statusField) {
                    ui.directory.quick_filters.push({
                        field: statusField.handle,
                        label: 'Status',
                        type: 'select',
                        searchable: false
                    });
                }
            }

            if (!Array.isArray(ui.forms.sections) || ui.forms.sections.length === 0) {
                const blacklist = ['id', 'company_id', 'added_by', 'last_updated_by', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
                const formFields = fields.filter(f => !blacklist.includes(f.handle));
                const wideKeywords = ['address', 'description', 'notes', 'memo', 'summary', 'about', 'bio', 'instructions', 'content', 'body', 'remarks'];

                if (formFields.length <= 6) {
                    ui.forms.sections = [
                        {
                            title: 'General Information',
                            description: 'Core parameters and record identification',
                            icon: 'info',
                            columns: 3,
                            fields: formFields.map(f => ({
                                handle: f.handle,
                                label: f.label || (f.handle.charAt(0).toUpperCase() + f.handle.slice(1).replace(/_/g, ' ')),
                                span: wideKeywords.some(k => (f.handle || '').includes(k)) ? 3 : 1,
                                searchable: (f.handle || '').endsWith('_id') || f.type === 'enum',
                                required: !!f.required
                            }))
                        }
                    ];
                } else {
                    const primary = formFields.slice(0, 6);
                    const secondary = formFields.slice(6);
                    ui.forms.sections = [
                        {
                            title: 'Primary Identification & Details',
                            description: 'Core identity, organizational assignment, and relations',
                            icon: 'user',
                            columns: 3,
                            fields: primary.map(f => ({
                                handle: f.handle,
                                label: f.label || (f.handle.charAt(0).toUpperCase() + f.handle.slice(1).replace(/_/g, ' ')),
                                span: wideKeywords.some(k => (f.handle || '').includes(k)) ? 3 : 1,
                                searchable: (f.handle || '').endsWith('_id') || f.type === 'enum',
                                required: !!f.required
                            }))
                        },
                        {
                            title: 'Operational & Extended Terms',
                            description: 'Extended parameters, terms, and custom notes',
                            icon: 'sliders',
                            columns: 3,
                            fields: secondary.map(f => ({
                                handle: f.handle,
                                label: f.label || (f.handle.charAt(0).toUpperCase() + f.handle.slice(1).replace(/_/g, ' ')),
                                span: wideKeywords.some(k => (f.handle || '').includes(k)) ? 3 : 1,
                                searchable: (f.handle || '').endsWith('_id') || f.type === 'enum',
                                required: !!f.required
                            }))
                        }
                    ];
                }
            }
        },

        syncUiToBlueprintAndYaml() {
            this.syncToYaml();
        },

        addDirectoryColumn(handle) {
            this.ensureUiConfig();
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const f = (rootModel.fields || []).find(x => x.handle === handle);
            const label = f ? (f.label || handle) : handle;
            let type = 'text';
            const h = handle.toLowerCase();
            if (h.includes('user') || h.includes('employee') || h.includes('client')) type = 'composite_user';
            else if (h.includes('status')) type = 'status_pill';
            else if (h.includes('amount') || h.includes('price') || h.includes('total') || h.includes('budget')) type = 'money_breakdown';
            else if (h.includes('progress') || h.includes('percent')) type = 'progress_bar';
            else if (f && (f.type === 'date' || f.type === 'datetime' || f.type === 'timestamp')) type = 'date_time';

            this.blueprint.ui.directory.columns.push({
                handle: handle,
                label: label,
                type: type,
                sortable: true
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeDirectoryColumn(idx) {
            this.ensureUiConfig();
            this.blueprint.ui.directory.columns.splice(idx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveDirectoryColumn(idx, dir) {
            this.ensureUiConfig();
            const cols = this.blueprint.ui.directory.columns;
            const target = idx + dir;
            if (target < 0 || target >= cols.length) return;
            const temp = cols[idx];
            cols[idx] = cols[target];
            cols[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        addQuickFilter(handle) {
            this.ensureUiConfig();
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const f = (rootModel.fields || []).find(x => x.handle === handle);
            this.blueprint.ui.directory.quick_filters.push({
                field: handle,
                label: f?.label || handle,
                type: handle.endsWith('_id') ? 'searchable_select' : 'select',
                searchable: handle.endsWith('_id')
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeQuickFilter(idx) {
            this.ensureUiConfig();
            this.blueprint.ui.directory.quick_filters.splice(idx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveQuickFilter(idx, dir) {
            this.ensureUiConfig();
            const list = this.blueprint.ui.directory.quick_filters;
            const target = idx + dir;
            if (target < 0 || target >= list.length) return;
            const temp = list[idx];
            list[idx] = list[target];
            list[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        moveSidebarFilter(idx, dir) {
            this.ensureUiConfig();
            const list = this.blueprint.ui.directory.sidebar_filters;
            const target = idx + dir;
            if (target < 0 || target >= list.length) return;
            const temp = list[idx];
            list[idx] = list[target];
            list[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        addSidebarFilter(handle) {
            this.ensureUiConfig();
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const f = (rootModel.fields || []).find(x => x.handle === handle);
            this.blueprint.ui.directory.sidebar_filters.push({
                field: handle,
                label: f?.label || handle,
                type: f && ['date', 'datetime', 'timestamp'].includes(f.type) ? 'date_range' : 'select',
                searchable: true
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeSidebarFilter(idx) {
            this.ensureUiConfig();
            this.blueprint.ui.directory.sidebar_filters.splice(idx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        addFormSection() {
            this.ensureUiConfig();
            const num = (this.blueprint.ui.forms.sections.length || 0) + 1;
            this.blueprint.ui.forms.sections.push({
                title: 'Section ' + num + ' Details',
                description: 'Additional custom configuration parameters',
                icon: 'sliders',
                columns: 3,
                fields: []
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeFormSection(secIdx) {
            this.ensureUiConfig();
            this.blueprint.ui.forms.sections.splice(secIdx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveFormSection(secIdx, dir) {
            this.ensureUiConfig();
            const secs = this.blueprint.ui.forms.sections;
            const target = secIdx + dir;
            if (target < 0 || target >= secs.length) return;
            const temp = secs[secIdx];
            secs[secIdx] = secs[target];
            secs[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        addFieldToSection(secIdx, handle) {
            this.ensureUiConfig();
            const rootModel = (this.blueprint.models && this.blueprint.models[0]) || { fields: [] };
            const f = (rootModel.fields || []).find(x => x.handle === handle);
            const wideKeywords = ['address', 'description', 'notes', 'memo', 'summary', 'about', 'bio', 'instructions', 'content', 'body', 'remarks'];

            this.blueprint.ui.forms.sections[secIdx].fields.push({
                handle: handle,
                label: f?.label || handle,
                span: wideKeywords.some(k => handle.includes(k)) ? 3 : 1,
                searchable: handle.endsWith('_id') || f?.type === 'enum',
                required: !!f?.required
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeFieldFromSection(secIdx, fldIdx) {
            this.ensureUiConfig();
            this.blueprint.ui.forms.sections[secIdx].fields.splice(fldIdx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveFieldInSection(secIdx, fldIdx, dir) {
            this.ensureUiConfig();
            const fields = this.blueprint.ui.forms.sections[secIdx].fields;
            const target = fldIdx + dir;
            if (target < 0 || target >= fields.length) return;
            const temp = fields[fIdx];
            fields[fldIdx] = fields[target];
            fields[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },


        addBulkAction() {
            this.ensureUiConfig();
            if (!Array.isArray(this.blueprint.ui.directory.bulk_actions)) {
                this.blueprint.ui.directory.bulk_actions = [];
            }
            this.blueprint.ui.directory.bulk_actions.push({
                handle: 'custom_' + Date.now(),
                label: 'Custom action',
                type: 'native',
                icon: 'zap',
            });
            this.syncUiToBlueprintAndYaml();
        },
        addBulkActionPreset(kind) {
            this.ensureUiConfig();
            const list = this.blueprint.ui.directory.bulk_actions || [];
            if (kind === 'standard') {
                const extras = [
                    { handle: 'export', label: 'Export Selected', type: 'native', icon: 'download' },
                    { handle: 'delete', label: 'Delete Selected', type: 'native', icon: 'trash-2', danger: true },
                ];
                extras.forEach(e => { if (!list.find(x => x.handle === e.handle)) list.push(e); });
            } else if (kind === 'workflow') {
                const extras = [
                    { handle: 'submit', label: 'Submit', type: 'workflow', transition: 'submit', icon: 'send' },
                    { handle: 'approve', label: 'Approve', type: 'workflow', transition: 'approve', icon: 'check', requires_remarks: false },
                    { handle: 'reject', label: 'Reject', type: 'workflow', transition: 'reject', icon: 'x', requires_remarks: true, danger: true },
                ];
                extras.forEach(e => { if (!list.find(x => x.handle === e.handle)) list.push(e); });
            }
            this.blueprint.ui.directory.bulk_actions = list;
            this.syncUiToBlueprintAndYaml();
        },
        setRowActionsPreset(preset) {
            this.ensureUiConfig();
            const ra = this.blueprint.ui.directory.row_actions;
            if (preset === 'standard_crud') {
                ra.display = 'dropdown';
                ra.items = [
                    { handle: 'view', label: 'View Details', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                    { handle: 'edit', label: 'Edit Record', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'delete', label: 'Delete Record', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ];
            } else if (preset === 'hybrid_view_outside') {
                ra.display = 'hybrid';
                ra.items = [
                    { handle: 'view', label: 'View', icon: 'eye', placement: 'inline', type: 'drawer' },
                    { handle: 'edit', label: 'Edit', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ];
            } else if (preset === 'task_suite') {
                ra.display = 'hybrid';
                ra.items = [
                    { handle: 'timer', label: 'Timer', icon: 'play', placement: 'inline', type: 'event' },
                    { handle: 'view', label: 'View', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                    { handle: 'edit', label: 'Edit', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'duplicate', label: 'Duplicate', icon: 'copy', placement: 'dropdown', type: 'route' },
                    { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ];
            } else if (preset === 'invoice_suite') {
                ra.display = 'dropdown';
                ra.items = [
                    { handle: 'view', label: 'View Details', icon: 'eye', placement: 'dropdown', type: 'drawer' },
                    { handle: 'download_pdf', label: 'Download PDF', icon: 'download', placement: 'dropdown', type: 'route' },
                    { handle: 'send', label: 'Send Email', icon: 'send', placement: 'dropdown', type: 'event' },
                    { handle: 'edit', label: 'Edit Invoice', icon: 'pencil', placement: 'dropdown', type: 'drawer' },
                    { handle: 'add_payment', label: 'Add Payment', icon: 'credit-card', placement: 'dropdown', type: 'drawer' },
                    { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'dropdown', type: 'delete', danger: true }
                ];
            } else if (preset === 'inline_compact') {
                ra.display = 'inline';
                ra.items = [
                    { handle: 'view', label: 'View', icon: 'eye', placement: 'inline', type: 'drawer' },
                    { handle: 'edit', label: 'Edit', icon: 'pencil', placement: 'inline', type: 'drawer' },
                    { handle: 'delete', label: 'Delete', icon: 'trash-2', placement: 'inline', type: 'delete', danger: true }
                ];
            }
            this.syncUiToBlueprintAndYaml();
        },

        addRowAction() {
            this.ensureUiConfig();
            const count = (this.blueprint.ui.directory.row_actions.items || []).length + 1;
            this.blueprint.ui.directory.row_actions.items.push({
                handle: `custom_action_${count}`,
                label: `Action ${count}`,
                icon: 'external-link',
                placement: 'dropdown',
                type: 'route',
                danger: false
            });
            this.syncUiToBlueprintAndYaml();
        },

        removeRowAction(idx) {
            this.ensureUiConfig();
            this.blueprint.ui.directory.row_actions.items.splice(idx, 1);
            this.syncUiToBlueprintAndYaml();
        },

        moveRowAction(idx, dir) {
            this.ensureUiConfig();
            const items = this.blueprint.ui.directory.row_actions.items;
            const target = idx + dir;
            if (target < 0 || target >= items.length) return;
            const temp = items[idx];
            items[idx] = items[target];
            items[target] = temp;
            this.syncUiToBlueprintAndYaml();
        },

        quickAddField(modelIndex) {
            const m = this.blueprint.models[modelIndex];
            if (!m) return;
            const fCount = m.fields.length + 1;
            m.fields.push({
                handle: `field_${fCount}`,
                label: `Field ${fCount}`,
                type: 'string',
                required: false,
                nullable: true,
                width: 50,
                length: '255',
                default: ''
            });
            this.syncToYaml();
        },

        removeField(modelIndex, fieldIndex) {
            const m = this.blueprint.models[modelIndex];
            if (!m) return;
            m.fields.splice(fieldIndex, 1);
            this.syncToYaml();
        },

        async introspectTable() {
            if (!this.selectedTable) return;
            this.introspecting = true;

            try {
                const res = await fetch(introspectUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ table: this.selectedTable })
                });

                const data = await res.json();
                if (data.fields) {
                    const modelHandle = this.selectedTable.replace(/s$/, '').toLowerCase();
                    const newModel = {
                        handle: modelHandle,
                        table: this.selectedTable,
                        root: this.blueprint.models.length === 0,
                        timestamps: true,
                        soft_deletes: false,
                        fields: data.fields,
                        relations: []
                    };
                    this.blueprint.models.push(newModel);
                    if (!this.blueprint.name) {
                        this.blueprint.name = data.model + ' Module';
                        this.blueprint.handle = modelHandle + '_module';
                    }
                    this.syncToYaml();
                    this.showIntrospectModal = false;
                    this.selectedTable = '';
                } else if (data.error) {
                    alert('Introspection error: ' + data.error);
                }
            } catch (err) {
                alert('Failed to introspect table: ' + err.message);
            } finally {
                this.introspecting = false;
            }
        }
});

if (window.Alpine) {
    window.Alpine.data('blueprintStudio', window.blueprintStudio);
} else {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('blueprintStudio', window.blueprintStudio);
    });
}
</script>
@endsection
