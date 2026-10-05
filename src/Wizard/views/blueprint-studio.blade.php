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
    activeSliceIdx: @js($activeSliceIdx ?? 0)
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
            <p class="mt-0.5 text-sm text-muted-foreground">
                Dual Visual Designer & YAML Modeler with October-style DB introspection, Statamic field widths, and durable slice.yaml persistence.
            </p>
        </div>

        <!-- Controls: Template selector, Slice selector, View mode -->
        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Template Quick Load -->
            <div class="relative">
                <select @change="loadStudioPreset($event.target.value); $event.target.value = '';" class="h-9 rounded-lg border border-input bg-card px-3 text-xs font-medium text-foreground shadow-sm hover:bg-accent/50 focus:outline-none">
                    <option value="" disabled selected>Load Example Template / Suite...</option>
                    <optgroup label="📦 Multi-Slice Domain Suites">
                        <option value="crm_suite">💼 CRM Suite (3 Slices: Companies, Contacts, Deals)</option>
                        <option value="ecommerce_suite">🛒 E-Commerce Suite (4 Slices: Products, Orders, Customers, Categories)</option>
                        <option value="billing_suite">💳 Billing Suite (3 Slices: Invoices, Payments, Subscriptions)</option>
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

            <!-- Installed Slices -->
            @if (!empty($installedSlices))
                <div class="relative">
                    <select @change="loadSlice($event.target.value)" class="h-9 rounded-lg border border-input bg-card px-3 text-xs font-medium text-foreground shadow-sm hover:bg-accent/50 focus:outline-none">
                        <option value="" disabled {{ empty($currentSlice) ? 'selected' : '' }}>Installed Slices...</option>
                        @foreach ($installedSlices as $s)
                            <option value="{{ $s['name'] }}" @selected(($currentSlice ?? '') === $s['name'])>
                                {{ $s['name'] }} {{ $s['has_yaml'] ? '(yaml)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <!-- + New Slice Modal Trigger -->
            <button type="button" @click="showNewSliceModal = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-primary/40 bg-primary/10 px-3 text-xs font-semibold text-primary hover:bg-primary/20 transition cursor-pointer" title="Create a new vertical slice blueprint">
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>+ New Slice</span>
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
    <form method="POST" action="{{ route('laraslice.wizard.blueprint.plan') }}" id="plan-form" @submit="syncToYaml()">
        @csrf
        <input type="hidden" name="format" :value="format">
        <input type="hidden" name="source" :value="source">
        <input type="hidden" name="slices_json" :value="JSON.stringify(slices)">
        <input type="hidden" name="active_slice_idx" :value="activeSliceIdx">
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
                    <div class="flex items-center justify-between border-b border-border px-5 py-3 bg-muted/20">
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full bg-emerald-500"></span>
                            <h2 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Declarative Blueprint (slice.yaml)</h2>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="formatYaml()" class="text-xs text-muted-foreground hover:text-foreground">
                                Beautify
                            </button>
                        </div>
                    </div>
                    <div class="p-4 flex-1">
                        <textarea id="blueprint-source" x-model="source" @input="syncFromYaml()" rows="32" spellcheck="false" autocapitalize="off" autocomplete="off" class="min-h-[580px] w-full resize-y rounded-xl border border-input bg-slate-950 p-4 font-mono text-[13px] leading-6 text-slate-100 shadow-inner focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
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
                    <button type="submit" form="plan-form" class="w-full inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-primary-foreground shadow hover:opacity-90 transition">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Validate & Plan Generation
                    </button>
                </div>

                @if (isset($plan))
                    <div class="rounded-2xl border border-border bg-card shadow-sm overflow-hidden">
                        <div class="flex flex-col gap-2 border-b border-border px-5 py-4 bg-muted/10 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="font-bold text-foreground">Generation Plan</h3>
                                <p class="text-xs text-muted-foreground font-mono mt-0.5">{{ $plan['target'] }}</p>
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
                                <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Compiled Entities</h4>
                                <div class="overflow-x-auto rounded-lg border border-border">
                                    <table class="w-full text-left text-xs">
                                        <thead class="bg-muted/50 uppercase tracking-wider text-muted-foreground">
                                            <tr>
                                                <th class="px-3 py-2">Model</th>
                                                <th class="px-3 py-2">Table</th>
                                                <th class="px-3 py-2">Fields</th>
                                                <th class="px-3 py-2">Relations</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-border">
                                            @foreach ($plan['models'] as $model)
                                                <tr>
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
                                    <span>Database Operations ({{ count($plan['database_operations']) }})</span>
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
                                    <span>Generated Files ({{ count($plan['files']) }})</span>
                                    <span class="text-muted-foreground group-open:rotate-180 transition">▼</span>
                                </summary>
                                <ul class="max-h-56 space-y-1 overflow-auto border-t border-border p-3 font-mono text-[11px] text-muted-foreground">
                                    @foreach ($plan['files'] as $f)
                                        <li class="break-all">{{ $f }}</li>
                                    @endforeach
                                </ul>
                            </details>

                            <!-- Apply Button -->
                            @if ($supported && ! $plan['conflict'] && $existingTables === [])
                                <form method="POST" action="{{ route('laraslice.wizard.blueprint.apply') }}"
                                      x-data="{ submitting: false }"
                                      @submit="submitting = true"
                                      class="pt-4 border-t border-border space-y-3">
                                    @csrf
                                    <input type="hidden" name="source" value="{{ $source }}">
                                    <input type="hidden" name="format" value="{{ $format }}">
                                    <input type="hidden" name="plan_hash" value="{{ $plan['plan_hash'] }}">
                                    <input type="hidden" name="slices_json" :value="JSON.stringify(slices)">
                                    <input type="hidden" name="active_slice_idx" :value="activeSliceIdx">
                                    <div class="space-y-2">
                                        <label class="flex items-start gap-2.5 text-xs text-foreground cursor-pointer select-none">
                                            <input type="checkbox" name="confirm_apply" value="1" required class="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary">
                                            <span class="font-medium">Confirm and generate vertical slice files & slice.yaml</span>
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
                                            :disabled="submitting"
                                            class="w-full inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-xs font-bold text-primary-foreground hover:bg-primary/90 transition shadow-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                                        <span x-show="!submitting" class="flex items-center gap-2">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                            🚀 Generate Slice Files
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
                                            @if ($plan['conflict'])
                                                <li>Target directory already exists at <span class="font-mono text-foreground">{{ $plan['target'] }}</span>.</li>
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
                            Click 'Validate & Plan Generation' to inspect model structure, database migrations, and Jev AI safety judgments.
                        </p>
                    </div>
                @endif
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
const studioPresets = {
    ecommerce_suite: [
        {
            name: 'Products',
            handle: 'products',
            domain: 'E-Commerce',
            description: 'Catalog products with variants, pricing, inventory, and category linking',
            permissions: ['product.view', 'product.create', 'product.edit', 'product.delete'],
            models: [
                {
                    handle: 'product',
                    table: 'products',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    description: 'Primary product records',
                    fields: [
                        { handle: 'name', label: 'Product Name', type: 'string', required: true, nullable: false, width: 50, length: '255', default: '' },
                        { handle: 'sku', label: 'SKU', type: 'string', required: true, nullable: false, width: 50, length: '100', default: '' },
                        { handle: 'price', label: 'Price ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '10,2', default: '0.00' },
                        { handle: 'stock_quantity', label: 'Stock Qty', type: 'integer', required: true, nullable: false, width: 50, length: '', default: '0' },
                        { handle: 'is_active', label: 'Is Active', type: 'boolean', required: false, nullable: false, width: 50, default: true }
                    ],
                    relations: [
                        { source_model: 'product', name: 'variants', type: 'hasMany', model: 'product_variant', foreign_key: 'product_id' }
                    ]
                },
                {
                    handle: 'product_variant',
                    table: 'product_variants',
                    root: false,
                    timestamps: true,
                    soft_deletes: false,
                    description: 'SKU product options and variants',
                    fields: [
                        { handle: 'product_id', label: 'Product', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'sku', label: 'Variant SKU', type: 'string', required: true, nullable: false, width: 50, length: '100' },
                        { handle: 'option_name', label: 'Option Name', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'price_override', label: 'Price Override', type: 'decimal', required: false, nullable: true, width: 50, length: '10,2' }
                    ],
                    relations: [
                        { source_model: 'product_variant', name: 'product', type: 'belongsTo', model: 'product', foreign_key: 'product_id' }
                    ]
                }
            ]
        },
        {
            name: 'Orders',
            handle: 'orders',
            domain: 'E-Commerce',
            description: 'Customer checkout orders and itemized line purchases',
            permissions: ['order.view', 'order.create', 'order.edit', 'order.delete'],
            models: [
                {
                    handle: 'order',
                    table: 'orders',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    description: 'Customer sales orders',
                    fields: [
                        { handle: 'order_number', label: 'Order Number', type: 'string', required: true, nullable: false, width: 50, length: '50' },
                        { handle: 'customer_id', label: 'Customer ID', type: 'integer', required: true, nullable: false, width: 50 },
                        { handle: 'total_amount', label: 'Total Amount ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '12,2', default: '0.00' },
                        { handle: 'order_status', label: 'Fulfillment Status', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'pending' }
                    ],
                    relations: [
                        { source_model: 'order', name: 'items', type: 'hasMany', model: 'order_item', foreign_key: 'order_id' }
                    ]
                },
                {
                    handle: 'order_item',
                    table: 'order_items',
                    root: false,
                    timestamps: true,
                    soft_deletes: false,
                    fields: [
                        { handle: 'order_id', label: 'Order', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'product_id', label: 'Product', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'quantity', label: 'Qty', type: 'integer', required: true, nullable: false, width: 50, default: '1' },
                        { handle: 'unit_price', label: 'Unit Price', type: 'decimal', required: true, nullable: false, width: 50, length: '10,2' }
                    ],
                    relations: [
                        { source_model: 'order_item', name: 'order', type: 'belongsTo', model: 'order', foreign_key: 'order_id' }
                    ]
                }
            ]
        },
        {
            name: 'Customers',
            handle: 'customers',
            domain: 'E-Commerce',
            description: 'Registered shopper profiles, shipping contacts, and preferences',
            permissions: ['customer.view', 'customer.create', 'customer.edit', 'customer.delete'],
            models: [
                {
                    handle: 'customer',
                    table: 'customers',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'name', label: 'Full Name', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'email', label: 'Customer Email', type: 'email', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'phone', label: 'Phone Number', type: 'string', required: false, nullable: true, width: 50, length: '50' }
                    ],
                    relations: []
                }
            ]
        },
        {
            name: 'Categories',
            handle: 'categories',
            domain: 'E-Commerce',
            description: 'Catalog taxonomy and navigation categories',
            permissions: ['category.view', 'category.create', 'category.edit', 'category.delete'],
            models: [
                {
                    handle: 'category',
                    table: 'categories',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'name', label: 'Category Name', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'slug', label: 'URL Slug', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'summary', label: 'Summary Overview', type: 'text', required: false, nullable: true, width: 100 }
                    ],
                    relations: []
                }
            ]
        }
    ],
    crm_suite: [
        {
            name: 'Companies',
            handle: 'companies',
            domain: 'CRM',
            description: 'B2B Account companies, industry sectors, and client relationships',
            permissions: ['company.view', 'company.create', 'company.edit', 'company.delete'],
            models: [
                {
                    handle: 'company',
                    table: 'companies',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'name', label: 'Company Name', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'industry', label: 'Industry', type: 'string', required: false, nullable: true, width: 50, length: '100' },
                        { handle: 'website', label: 'Website URL', type: 'string', required: false, nullable: true, width: 50, length: '255' },
                        { handle: 'phone', label: 'Phone', type: 'string', required: false, nullable: true, width: 50, length: '50' },
                        { handle: 'annual_revenue', label: 'Annual Revenue', type: 'decimal', required: false, nullable: true, width: 50, length: '14,2', default: '0.00' },
                        { handle: 'tier', label: 'Account Tier', type: 'string', required: false, nullable: true, width: 50, length: '50', default: 'prospect' }
                    ],
                    relations: [
                        { source_model: 'company', name: 'contacts', type: 'hasMany', model: 'contact', foreign_key: 'company_id' }
                    ]
                }
            ]
        },
        {
            name: 'Contacts',
            handle: 'contacts',
            domain: 'CRM',
            description: 'People contacts, business titles, and company affiliations',
            permissions: ['contact.view', 'contact.create', 'contact.edit', 'contact.delete'],
            models: [
                {
                    handle: 'contact',
                    table: 'contacts',
                    root: true,
                    timestamps: true,
                    soft_deletes: false,
                    fields: [
                        { handle: 'first_name', label: 'First Name', type: 'string', required: true, nullable: false, width: 50, length: '100' },
                        { handle: 'last_name', label: 'Last Name', type: 'string', required: true, nullable: false, width: 50, length: '100' },
                        { handle: 'email', label: 'Email', type: 'email', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'phone', label: 'Direct Phone', type: 'string', required: false, nullable: true, width: 50, length: '50' },
                        { handle: 'job_title', label: 'Job Title', type: 'string', required: false, nullable: true, width: 50, length: '150' },
                        { handle: 'company_id', label: 'Company ID', type: 'integer', required: false, nullable: true, width: 50 }
                    ],
                    relations: []
                }
            ]
        },
        {
            name: 'Deals',
            handle: 'deals',
            domain: 'CRM',
            description: 'Sales pipeline deals, opportunity tracking, and closing forecasts',
            permissions: ['deal.view', 'deal.create', 'deal.edit', 'deal.delete'],
            models: [
                {
                    handle: 'deal',
                    table: 'deals',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'deal_name', label: 'Deal Name', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'amount', label: 'Deal Amount ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '14,2', default: '0.00' },
                        { handle: 'stage', label: 'Pipeline Stage', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'lead' },
                        { handle: 'close_date', label: 'Expected Close Date', type: 'date', required: false, nullable: true, width: 50 },
                        { handle: 'company_id', label: 'Company ID', type: 'integer', required: false, nullable: true, width: 50 }
                    ],
                    relations: []
                }
            ]
        }
    ],
    billing_suite: [
        {
            name: 'Invoices',
            handle: 'invoices',
            domain: 'Billing',
            description: 'Customer invoices, payable balances, due dates, and delivery tracking',
            permissions: ['invoice.view', 'invoice.create', 'invoice.edit', 'invoice.delete'],
            models: [
                {
                    handle: 'invoice',
                    table: 'invoices',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'invoice_number', label: 'Invoice #', type: 'string', required: true, nullable: false, width: 50, length: '50' },
                        { handle: 'amount', label: 'Total Amount ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '14,2', default: '0.00' },
                        { handle: 'due_date', label: 'Due Date', type: 'date', required: true, nullable: false, width: 50 },
                        { handle: 'invoice_status', label: 'Invoice Status', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'draft' }
                    ],
                    relations: []
                }
            ]
        },
        {
            name: 'Payments',
            handle: 'payments',
            domain: 'Billing',
            description: 'Financial transactions, receipts, and multi-gateway payment entries',
            permissions: ['payment.view', 'payment.create', 'payment.edit', 'payment.delete'],
            models: [
                {
                    handle: 'payment',
                    table: 'payments',
                    root: true,
                    timestamps: true,
                    soft_deletes: false,
                    fields: [
                        { handle: 'reference', label: 'Payment Ref #', type: 'string', required: true, nullable: false, width: 50, length: '100' },
                        { handle: 'invoice_id', label: 'Invoice ID', type: 'integer', required: false, nullable: true, width: 50 },
                        { handle: 'amount', label: 'Paid Amount ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '14,2', default: '0.00' },
                        { handle: 'method', label: 'Payment Method', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'card' },
                        { handle: 'payment_status', label: 'Payment Status', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'succeeded' }
                    ],
                    relations: []
                }
            ]
        },
        {
            name: 'Subscriptions',
            handle: 'subscriptions',
            domain: 'Billing',
            description: 'Recurring customer subscriptions, tiers, renewal dates, and billing periods',
            permissions: ['subscription.view', 'subscription.create', 'subscription.edit', 'subscription.delete'],
            models: [
                {
                    handle: 'subscription',
                    table: 'subscriptions',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'plan_name', label: 'Plan Name', type: 'string', required: true, nullable: false, width: 50, length: '150', default: 'Pro Plan' },
                        { handle: 'billing_cycle', label: 'Billing Cycle', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'monthly' },
                        { handle: 'price', label: 'Price ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '10,2', default: '29.00' },
                        { handle: 'plan_status', label: 'Plan Status', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'active' }
                    ],
                    relations: []
                }
            ]
        }
    ],
    blog: [
        {
            name: 'Articles',
            handle: 'articles',
            domain: 'Content',
            description: 'Editorial blog articles, publishing lifecycle, and discussion comments',
            permissions: ['article.view', 'article.create', 'article.edit', 'article.delete'],
            models: [
                {
                    handle: 'article',
                    table: 'articles',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'headline', label: 'Article Headline', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'slug', label: 'URL Slug', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'excerpt', label: 'Excerpt', type: 'text', required: false, nullable: true, width: 100 },
                        { handle: 'visibility', label: 'Visibility', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'draft' }
                    ],
                    relations: [
                        { source_model: 'article', name: 'comments', type: 'hasMany', model: 'comment', foreign_key: 'article_id' }
                    ]
                },
                {
                    handle: 'comment',
                    table: 'comments',
                    root: false,
                    timestamps: true,
                    soft_deletes: false,
                    fields: [
                        { handle: 'article_id', label: 'Article', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'author_name', label: 'Author Name', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'author_email', label: 'Email', type: 'email', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'comment_body', label: 'Comment', type: 'text', required: true, nullable: false, width: 100 }
                    ],
                    relations: [
                        { source_model: 'comment', name: 'article', type: 'belongsTo', model: 'article', foreign_key: 'article_id' }
                    ]
                }
            ]
        }
    ],
    'service-desk': [
        {
            name: 'Service Desk',
            handle: 'service_desk',
            domain: 'Support',
            description: 'Helpdesk ticket management with conversation replies and status tracking',
            permissions: ['ticket.view', 'ticket.create', 'ticket.edit', 'ticket.delete'],
            models: [
                {
                    handle: 'ticket',
                    table: 'tickets',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    description: 'Support tickets submitted by users',
                    fields: [
                        { handle: 'title', label: 'Ticket Subject', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'priority', label: 'Priority', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'medium' },
                        { handle: 'status', label: 'Ticket Status', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'open' },
                        { handle: 'description', label: 'Description', type: 'text', required: true, nullable: false, width: 100 }
                    ],
                    relations: [
                        { source_model: 'ticket', name: 'replies', type: 'hasMany', model: 'ticket_reply', foreign_key: 'ticket_id' }
                    ]
                },
                {
                    handle: 'ticket_reply',
                    table: 'ticket_replies',
                    root: false,
                    timestamps: true,
                    soft_deletes: false,
                    description: 'Conversation threads and messages on a ticket',
                    fields: [
                        { handle: 'ticket_id', label: 'Ticket', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'user_name', label: 'Author', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'message', label: 'Reply Message', type: 'text', required: true, nullable: false, width: 100 }
                    ],
                    relations: [
                        { source_model: 'ticket_reply', name: 'ticket', type: 'belongsTo', model: 'ticket', foreign_key: 'ticket_id' }
                    ]
                }
            ]
        }
    ],
    'hr-module': [
        {
            name: 'Departments',
            handle: 'departments',
            domain: 'HR',
            description: 'Company organization departments and cost centers',
            permissions: ['department.view', 'department.create', 'department.edit', 'department.delete'],
            models: [
                {
                    handle: 'department',
                    table: 'departments',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'name', label: 'Department Name', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'code', label: 'Dept Code', type: 'string', required: true, nullable: false, width: 50, length: '50' },
                        { handle: 'budget', label: 'Annual Budget ($)', type: 'decimal', required: false, nullable: true, width: 50, length: '14,2', default: '0.00' },
                        { handle: 'description', label: 'Overview', type: 'text', required: false, nullable: true, width: 100 }
                    ],
                    relations: [
                        { source_model: 'department', name: 'employees', type: 'hasMany', model: 'employee', foreign_key: 'department_id' }
                    ]
                },
                {
                    handle: 'employee',
                    table: 'employees',
                    root: false,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'department_id', label: 'Department', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'full_name', label: 'Full Name', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'email', label: 'Work Email', type: 'email', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'job_title', label: 'Job Title', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'salary', label: 'Salary ($)', type: 'decimal', required: false, nullable: true, width: 50, length: '12,2', default: '0.00' }
                    ],
                    relations: [
                        { source_model: 'employee', name: 'department', type: 'belongsTo', model: 'department', foreign_key: 'department_id' }
                    ]
                }
            ]
        }
    ],
    shop: [
        {
            name: 'Products',
            handle: 'products',
            domain: 'E-Commerce',
            description: 'Catalog products with variants and inventory',
            permissions: ['product.view', 'product.create', 'product.edit', 'product.delete'],
            models: [
                {
                    handle: 'product',
                    table: 'products',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'name', label: 'Product Name', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'sku', label: 'SKU', type: 'string', required: true, nullable: false, width: 50, length: '100' },
                        { handle: 'price', label: 'Price ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '10,2', default: '0.00' },
                        { handle: 'stock_quantity', label: 'Stock Qty', type: 'integer', required: true, nullable: false, width: 50, default: '0' },
                        { handle: 'status', label: 'Status', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'published' }
                    ],
                    relations: [
                        { source_model: 'product', name: 'variants', type: 'hasMany', model: 'product_variant', foreign_key: 'product_id' }
                    ]
                },
                {
                    handle: 'product_variant',
                    table: 'product_variants',
                    root: false,
                    timestamps: true,
                    soft_deletes: false,
                    fields: [
                        { handle: 'product_id', label: 'Product', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'sku', label: 'Variant SKU', type: 'string', required: true, nullable: false, width: 50, length: '100' },
                        { handle: 'option_name', label: 'Option Name', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'price_override', label: 'Price Override', type: 'decimal', required: false, nullable: true, width: 50, length: '10,2' }
                    ],
                    relations: [
                        { source_model: 'product_variant', name: 'product', type: 'belongsTo', model: 'product', foreign_key: 'product_id' }
                    ]
                }
            ]
        }
    ],
    crm: [
        {
            name: 'Companies',
            handle: 'companies',
            domain: 'CRM',
            description: 'Companies, enterprise accounts and client organizations',
            permissions: ['company.view', 'company.create', 'company.edit', 'company.delete'],
            models: [
                {
                    handle: 'company',
                    table: 'companies',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'name', label: 'Company Name', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'industry', label: 'Industry', type: 'string', required: false, nullable: true, width: 50, length: '100' },
                        { handle: 'website', label: 'Website URL', type: 'string', required: false, nullable: true, width: 50, length: '255' },
                        { handle: 'phone', label: 'Phone', type: 'string', required: false, nullable: true, width: 50, length: '50' }
                    ],
                    relations: [
                        { source_model: 'company', name: 'contacts', type: 'hasMany', model: 'contact', foreign_key: 'company_id' }
                    ]
                },
                {
                    handle: 'contact',
                    table: 'contacts',
                    root: false,
                    timestamps: true,
                    soft_deletes: false,
                    fields: [
                        { handle: 'company_id', label: 'Company', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'first_name', label: 'First Name', type: 'string', required: true, nullable: false, width: 50, length: '100' },
                        { handle: 'last_name', label: 'Last Name', type: 'string', required: true, nullable: false, width: 50, length: '100' },
                        { handle: 'email', label: 'Email', type: 'email', required: true, nullable: false, width: 50, length: '255' }
                    ],
                    relations: [
                        { source_model: 'contact', name: 'company', type: 'belongsTo', model: 'company', foreign_key: 'company_id' }
                    ]
                }
            ]
        }
    ],
    'shop-orders': [
        {
            name: 'Orders',
            handle: 'orders',
            domain: 'E-Commerce',
            description: 'Customer checkout orders and line items',
            permissions: ['order.view', 'order.create', 'order.edit', 'order.delete'],
            models: [
                {
                    handle: 'order',
                    table: 'orders',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'order_number', label: 'Order Number', type: 'string', required: true, nullable: false, width: 50, length: '50' },
                        { handle: 'customer_id', label: 'Customer ID', type: 'integer', required: true, nullable: false, width: 50 },
                        { handle: 'total_amount', label: 'Total Amount ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '12,2', default: '0.00' },
                        { handle: 'status', label: 'Status', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'pending' }
                    ],
                    relations: [
                        { source_model: 'order', name: 'items', type: 'hasMany', model: 'order_item', foreign_key: 'order_id' }
                    ]
                },
                {
                    handle: 'order_item',
                    table: 'order_items',
                    root: false,
                    timestamps: true,
                    soft_deletes: false,
                    fields: [
                        { handle: 'order_id', label: 'Order', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'product_id', label: 'Product ID', type: 'foreign_id', required: true, nullable: false, width: 50 },
                        { handle: 'quantity', label: 'Quantity', type: 'integer', required: true, nullable: false, width: 50, default: '1' },
                        { handle: 'unit_price', label: 'Unit Price ($)', type: 'decimal', required: true, nullable: false, width: 50, length: '10,2', default: '0.00' }
                    ],
                    relations: [
                        { source_model: 'order_item', name: 'order', type: 'belongsTo', model: 'order', foreign_key: 'order_id' }
                    ]
                }
            ]
        }
    ],
    'shop-categories': [
        {
            name: 'Categories',
            handle: 'categories',
            domain: 'E-Commerce',
            description: 'Shop catalog categories and taxonomies',
            permissions: ['category.view', 'category.create', 'category.edit', 'category.delete'],
            models: [
                {
                    handle: 'category',
                    table: 'categories',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'name', label: 'Category Name', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'slug', label: 'URL Slug', type: 'string', required: true, nullable: false, width: 50, length: '150' },
                        { handle: 'description', label: 'Description', type: 'text', required: false, nullable: true, width: 100 }
                    ],
                    relations: []
                }
            ]
        }
    ],
    helpdesk: [
        {
            name: 'Helpdesk',
            handle: 'helpdesk',
            domain: 'Support',
            description: 'Support tickets with SLA priority, status lifecycle, and user assignment',
            permissions: ['helpdesk.view', 'helpdesk.create', 'helpdesk.edit', 'helpdesk.delete'],
            models: [
                {
                    handle: 'ticket',
                    table: 'tickets',
                    root: true,
                    timestamps: true,
                    soft_deletes: true,
                    fields: [
                        { handle: 'subject', label: 'Subject', type: 'string', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'requester_email', label: 'Requester Email', type: 'email', required: true, nullable: false, width: 50, length: '255' },
                        { handle: 'priority', label: 'Priority', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'medium' },
                        { handle: 'status', label: 'Ticket Status', type: 'string', required: true, nullable: false, width: 50, length: '50', default: 'open' },
                        { handle: 'issue_details', label: 'Issue Details', type: 'text', required: true, nullable: false, width: 100 }
                    ],
                    relations: []
                }
            ]
        }
    ]
};

document.addEventListener('alpine:init', () => {
    Alpine.data('blueprintStudio', ({ initialSource, initialFormat, initialBlueprint, csrfToken, introspectUrl, studioUrl, allSlicesJson, activeSliceIdx }) => ({
        mode: 'visual', // 'visual', 'preview', 'split', 'yaml'
        activeModelIndex: 0,
        source: initialSource || '',
        format: initialFormat || 'yaml',
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
                    const bp = {
                        schema_version: 1,
                        name: s.name,
                        handle: s.handle,
                        domain: s.domain,
                        description: s.description,
                        author: 'LaraSlice Team',
                        version: '1.0.0',
                        permissions: s.permissions || [],
                        models: s.models || []
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
                this.syncToYaml();
                first.source = this.source;
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
            // 1. Restore all slices if passed from backend (e.g. after form validation / plan review)
            if (allSlicesJson) {
                try {
                    const parsed = typeof allSlicesJson === 'string' ? JSON.parse(allSlicesJson) : allSlicesJson;
                    if (Array.isArray(parsed) && parsed.length > 0) {
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

            const urlParams = new URLSearchParams(window.location.search);
            const tplParam = urlParams.get('template');
            if (tplParam && studioPresets[tplParam]) {
                this.loadStudioPreset(tplParam);
                return;
            }

            // Restore from sessionStorage if available
            try {
                const stored = sessionStorage.getItem('laraslice_studio_slices');
                if (stored) {
                    const parsed = JSON.parse(stored);
                    if (Array.isArray(parsed) && parsed.length > 0) {
                        this.slices = parsed;
                        const storedIdx = parseInt(sessionStorage.getItem('laraslice_active_slice_idx') || '0');
                        this.activeSliceIdx = (storedIdx >= 0 && storedIdx < this.slices.length) ? storedIdx : 0;
                        const activeSl = this.slices[this.activeSliceIdx];
                        if (activeSl && activeSl.blueprint) {
                            this.loadParsedBlueprint(activeSl.blueprint);
                            this.allRelations = JSON.parse(JSON.stringify(activeSl.allRelations || []));
                            this.source = activeSl.source || this.source;
                            this.syncToYaml();
                            return;
                        }
                    }
                }
            } catch(e) {}

            if (initialBlueprint && (Array.isArray(initialBlueprint.models) && initialBlueprint.models.length > 0 || initialBlueprint.name)) {
                this.loadParsedBlueprint(initialBlueprint);
            } else {
                this.parseYamlToBlueprint();
            }

            // Initialize slices list with initial slice
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
            this.blueprint.handle = data.handle || '';
            this.blueprint.domain = data.domain || '';
            this.blueprint.description = data.description || '';
            this.blueprint.author = data.author || '';
            this.blueprint.version = data.version || '1.0.0';
            this.blueprint.permissions = Array.isArray(data.permissions) ? data.permissions : [];
            if (this.blueprint.permissions.length === 0 && this.blueprint.handle) {
                let base = this.blueprint.handle.replace(/s$/, '');
                this.blueprint.permissions = [`${base}.view`, `${base}.create`, `${base}.edit`, `${base}.delete`];
            }
            this.blueprint.models = [];
            this.allRelations = [];

            const rawModels = Array.isArray(data.models) ? data.models : [];
            rawModels.forEach(m => {
                const modelObj = {
                    handle: m.handle || '',
                    table: m.table || ((m.handle || '') + 's'),
                    root: !!m.root,
                    timestamps: m.timestamps !== false,
                    soft_deletes: !!m.soft_deletes,
                    description: m.description || '',
                    fields: [],
                    relations: []
                };

                const rawFields = Array.isArray(m.fields) ? m.fields : [];
                rawFields.forEach(f => {
                    const fHandle = f.handle || f.name || '';
                    const fType = f.type || 'string';
                    const fOptions = (f.options && typeof f.options === 'object') ? f.options : {};
                    const fOptionsText = Object.entries(fOptions).map(([k, v]) => `${k}: ${v}`).join(', ');

                    modelObj.fields.push({
                        handle: fHandle,
                        label: f.label || (fHandle ? (fHandle.charAt(0).toUpperCase() + fHandle.slice(1).replace(/_/g, ' ')) : ''),
                        type: fType,
                        required: f.required !== undefined ? !!f.required : true,
                        nullable: f.nullable !== undefined ? !!f.nullable : false,
                        width: parseInt(f.width) || (['text', 'json'].includes(fType) ? 100 : 50),
                        length: f.length !== undefined && f.length !== null ? String(f.length) : '',
                        default: f.default !== undefined && f.default !== null ? String(f.default) : '',
                        options: fOptions,
                        optionsText: fOptionsText
                    });
                });

                const rawRelations = Array.isArray(m.relations) ? m.relations : [];
                rawRelations.forEach(r => {
                    const relObj = {
                        source_model: m.handle || '',
                        name: r.name || '',
                        type: r.type || 'belongsTo',
                        model: r.model || '',
                        foreign_key: r.foreign_key || ''
                    };
                    modelObj.relations.push(relObj);
                    this.allRelations.push(relObj);
                });

                this.blueprint.models.push(modelObj);
            });
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

        parseYamlToBlueprint() {
            try {
                const lines = this.source.split('\n');
                let bp = {
                    schema_version: 1,
                    name: '',
                    handle: '',
                    domain: '',
                    description: '',
                    author: '',
                    version: '1.0.0',
                    permissions: [],
                    models: []
                };

                let currentModel = null;
                let currentField = null;
                let currentRelation = null;
                let section = '';
                const collectedRelations = [];
                let inOptions = false;
                let pendingItemType = null; // 'model', 'field', 'relation'

                const startNewModel = (handleVal) => {
                    currentModel = {
                        handle: handleVal,
                        table: handleVal ? (handleVal + 's') : '',
                        root: false,
                        timestamps: true,
                        soft_deletes: false,
                        description: '',
                        fields: [],
                        relations: []
                    };
                    bp.models.push(currentModel);
                    currentField = null;
                    currentRelation = null;
                    section = 'models';
                };

                const startNewField = (handleVal) => {
                    inOptions = false;
                    currentField = {
                        handle: handleVal,
                        label: handleVal ? (handleVal.charAt(0).toUpperCase() + handleVal.slice(1).replace(/_/g, ' ')) : '',
                        type: 'string',
                        required: true,
                        nullable: false,
                        width: 50,
                        length: '',
                        default: '',
                        options: {},
                        optionsText: ''
                    };
                    if (currentModel) {
                        currentModel.fields.push(currentField);
                    }
                    currentRelation = null;
                };

                const startNewRelation = (relName) => {
                    inOptions = false;
                    currentRelation = {
                        source_model: currentModel ? currentModel.handle : '',
                        name: relName,
                        type: 'belongsTo',
                        model: '',
                        foreign_key: ''
                    };
                    if (currentModel) {
                        if (!currentModel.relations) currentModel.relations = [];
                        currentModel.relations.push(currentRelation);
                    }
                    collectedRelations.push(currentRelation);
                    currentField = null;
                };

                for (let rawLine of lines) {
                    const line = rawLine.trimEnd();
                    const trimmed = line.trim();
                    if (!trimmed || trimmed.startsWith('#')) continue;

                    const indent = rawLine.length - rawLine.trimStart().length;

                    // Hyphen on its own line: list item start
                    if (trimmed === '-') {
                        if (section === 'models' || (section === '' && indent <= 4)) {
                            startNewModel('');
                            pendingItemType = 'model';
                            continue;
                        } else if (section === 'fields') {
                            startNewField('');
                            pendingItemType = 'field';
                            continue;
                        } else if (section === 'relations') {
                            startNewRelation('');
                            pendingItemType = 'relation';
                            continue;
                        }
                    }

                    // Model list item on same line: "- handle: xyz"
                    if ((trimmed.startsWith('- handle:') || trimmed.startsWith('-  handle:')) && indent <= 4) {
                        const h = trimmed.replace(/^-+\s*handle:/, '').trim().replace(/^['"]|['"]$/g, '');
                        startNewModel(h);
                        pendingItemType = null;
                        continue;
                    }

                    // Field list item on same line: "- handle: xyz"
                    if ((trimmed.startsWith('- handle:') || trimmed.startsWith('-  handle:')) && (section === 'fields' || indent > 4)) {
                        const h = trimmed.replace(/^-+\s*handle:/, '').trim().replace(/^['"]|['"]$/g, '');
                        startNewField(h);
                        pendingItemType = null;
                        continue;
                    }

                    // Relation list item on same line: "- name: xyz"
                    if ((trimmed.startsWith('- name:') || trimmed.startsWith('-  name:')) && (section === 'relations' || indent > 4)) {
                        const n = trimmed.replace(/^-+\s*name:/, '').trim().replace(/^['"]|['"]$/g, '');
                        startNewRelation(n);
                        pendingItemType = null;
                        continue;
                    }

                    // Handling properties of pending items
                    if (pendingItemType === 'model' && trimmed.startsWith('handle:')) {
                        const h = trimmed.replace('handle:', '').trim().replace(/^['"]|['"]$/g, '');
                        currentModel.handle = h;
                        currentModel.table = h + 's';
                        pendingItemType = null;
                        continue;
                    }
                    if (pendingItemType === 'field' && trimmed.startsWith('handle:')) {
                        const h = trimmed.replace('handle:', '').trim().replace(/^['"]|['"]$/g, '');
                        currentField.handle = h;
                        currentField.label = h.charAt(0).toUpperCase() + h.slice(1).replace(/_/g, ' ');
                        pendingItemType = null;
                        continue;
                    }
                    if (pendingItemType === 'relation' && trimmed.startsWith('name:')) {
                        const n = trimmed.replace('name:', '').trim().replace(/^['"]|['"]$/g, '');
                        currentRelation.name = n;
                        pendingItemType = null;
                        continue;
                    }

                    // Section transitions
                    if (trimmed.startsWith('models:')) {
                        section = 'models';
                        continue;
                    } else if (trimmed.startsWith('permissions:')) {
                        section = 'permissions';
                        inOptions = false;
                        continue;
                    } else if (trimmed.startsWith('fields:')) {
                        section = 'fields';
                        inOptions = false;
                        continue;
                    } else if (trimmed.startsWith('relations:')) {
                        section = 'relations';
                        inOptions = false;
                        continue;
                    }

                    // Permissions list items: "- something.view"
                    if (section === 'permissions' && trimmed.startsWith('-')) {
                        const pSlug = trimmed.replace(/^-+\s*/, '').trim().replace(/^['"]|['"]$/g, '');
                        if (pSlug && !bp.permissions.includes(pSlug)) {
                            bp.permissions.push(pSlug);
                        }
                        continue;
                    }

                    // Top-level metadata
                    if (trimmed.startsWith('schema_version:') && !currentModel) {
                        bp.schema_version = parseInt(trimmed.replace('schema_version:', '').trim()) || 1;
                    } else if (trimmed.startsWith('name:') && !currentModel && section !== 'relations') {
                        bp.name = trimmed.replace('name:', '').trim().replace(/^['"]|['"]$/g, '');
                    } else if (trimmed.startsWith('handle:') && !currentModel) {
                        bp.handle = trimmed.replace('handle:', '').trim().replace(/^['"]|['"]$/g, '');
                    } else if (trimmed.startsWith('domain:') && !currentModel) {
                        bp.domain = trimmed.replace('domain:', '').trim().replace(/^['"]|['"]$/g, '');
                    } else if (trimmed.startsWith('description:') && !currentModel) {
                        bp.description = trimmed.replace('description:', '').trim().replace(/^['"]|['"]$/g, '');
                    } else if (trimmed.startsWith('author:') && !currentModel) {
                        bp.author = trimmed.replace('author:', '').trim().replace(/^['"]|['"]$/g, '');
                    } else if (trimmed.startsWith('version:') && !currentModel) {
                        bp.version = trimmed.replace('version:', '').trim().replace(/^['"]|['"]$/g, '');
                    } else if (section === 'models' && currentModel && !currentField && !currentRelation) {
                        if (trimmed.startsWith('table:')) {
                            currentModel.table = trimmed.replace('table:', '').trim().replace(/^['"]|['"]$/g, '');
                        } else if (trimmed.startsWith('root:')) {
                            currentModel.root = trimmed.replace('root:', '').trim() === 'true';
                        } else if (trimmed.startsWith('timestamps:')) {
                            currentModel.timestamps = trimmed.replace('timestamps:', '').trim() !== 'false';
                        } else if (trimmed.startsWith('soft_deletes:')) {
                            currentModel.soft_deletes = trimmed.replace('soft_deletes:', '').trim() === 'true';
                        } else if (trimmed.startsWith('description:')) {
                            currentModel.description = trimmed.replace('description:', '').trim().replace(/^['"]|['"]$/g, '');
                        }
                    } else if (section === 'fields' && currentField) {
                        if (trimmed.startsWith('options:')) {
                            inOptions = true;
                            currentField.options = {};
                        } else if (inOptions && trimmed.includes(':') && !trimmed.startsWith('-') && !['label:', 'type:', 'required:', 'nullable:', 'width:', 'length:', 'default:'].some(k => trimmed.startsWith(k))) {
                            const optParts = trimmed.split(':');
                            const optKey = optParts[0].trim().replace(/^['"]|['"]$/g, '');
                            const optVal = optParts.slice(1).join(':').trim().replace(/^['"]|['"]$/g, '');
                            if (optKey) {
                                currentField.options[optKey] = optVal || optKey;
                                currentField.optionsText = Object.entries(currentField.options).map(([k, v]) => `${k}: ${v}`).join(', ');
                            }
                        } else {
                            inOptions = false;
                            if (trimmed.startsWith('label:')) {
                                currentField.label = trimmed.replace('label:', '').trim().replace(/^['"]|['"]$/g, '');
                            } else if (trimmed.startsWith('type:')) {
                                currentField.type = trimmed.replace('type:', '').trim().replace(/^['"]|['"]$/g, '');
                                if (currentField.type === 'text' || currentField.type === 'json') {
                                    currentField.width = 100;
                                }
                            } else if (trimmed.startsWith('required:')) {
                                currentField.required = trimmed.replace('required:', '').trim() === 'true';
                            } else if (trimmed.startsWith('nullable:')) {
                                currentField.nullable = trimmed.replace('nullable:', '').trim() === 'true';
                        currentField.hidden = trimmed.replace('hidden:', '').trim() === 'true';
                            } else if (trimmed.startsWith('width:')) {
                                currentField.width = parseInt(trimmed.replace('width:', '').trim()) || 50;
                            } else if (trimmed.startsWith('length:')) {
                                currentField.length = trimmed.replace('length:', '').trim().replace(/^['"]|['"]$/g, '');
                            } else if (trimmed.startsWith('default:')) {
                                currentField.default = trimmed.replace('default:', '').trim().replace(/^['"]|['"]$/g, '');
                            }
                        }
                    } else if (section === 'relations' && currentRelation) {
                        if (trimmed.startsWith('type:')) {
                            currentRelation.type = trimmed.replace('type:', '').trim().replace(/^['"]|['"]$/g, '');
                        } else if (trimmed.startsWith('model:')) {
                            currentRelation.model = trimmed.replace('model:', '').trim().replace(/^['"]|['"]$/g, '');
                        } else if (trimmed.startsWith('foreign_key:')) {
                            currentRelation.foreign_key = trimmed.replace('foreign_key:', '').trim().replace(/^['"]|['"]$/g, '');
                        }
                    }
                }

                if (bp.models.length > 0 || bp.name) {
                    this.blueprint = bp;
                    this.allRelations = collectedRelations;
                }
            } catch (e) {
                console.warn('YAML parse note:', e);
            }
        },

        syncToYaml() {
            let out = [];
            out.push('schema_version: 1');
            out.push(`name: ${this.blueprint.name || 'Untitled Slice'}`);
            out.push(`handle: ${this.blueprint.handle || 'untitled_slice'}`);
            if (this.blueprint.domain) {
                out.push(`domain: ${this.blueprint.domain}`);
            }
            if (this.blueprint.author) {
                out.push(`author: ${this.blueprint.author}`);
            }
            out.push(`version: ${this.blueprint.version || '1.0.0'}`);
            out.push(`description: ${this.blueprint.description || ''}`);
            if (this.blueprint.permissions && this.blueprint.permissions.length > 0) {
                out.push('permissions:');
                for (let p of this.blueprint.permissions) {
                    out.push(`  - ${p}`);
                }
            }
            out.push('models:');

            for (let m of this.blueprint.models) {
                out.push(`  - handle: ${m.handle}`);
                out.push(`    table: ${m.table || (m.handle + 's')}`);
                out.push(`    root: ${m.root ? 'true' : 'false'}`);
                out.push(`    timestamps: ${m.timestamps !== false ? 'true' : 'false'}`);
                if (m.soft_deletes) {
                    out.push('    soft_deletes: true');
                }
                if (m.description) {
                    out.push(`    description: ${m.description}`);
                }
                if (m.fields && m.fields.length > 0) {
                    out.push('    fields:');
                    for (let f of m.fields) {
                        out.push(`      - handle: ${f.handle}`);
                        out.push(`        label: ${f.label || f.handle}`);
                        out.push(`        type: ${f.type || 'string'}`);
                        out.push(`        required: ${f.required ? 'true' : 'false'}`);
                        if (f.nullable) {
                            out.push('        nullable: true');
                        }
                        if (f.hidden) {
                            out.push('        hidden: true');
                        }
                        if (f.width && f.width !== 50) {
                            out.push(`        width: ${f.width}`);
                        }
                        if (f.length !== undefined && f.length !== null && f.length !== '') {
                            out.push(`        length: ${f.length}`);
                        }
                        if (f.default !== undefined && f.default !== null && f.default !== '') {
                            out.push(`        default: ${f.default}`);
                        }
                        if (f.type === 'enum') {
                            out.push('        options:');
                            let opts = f.options;
                            if ((!opts || Object.keys(opts).length === 0) && f.optionsText) {
                                opts = {};
                                f.optionsText.split(',').forEach(item => {
                                    const p = item.split(':');
                                    if (p.length >= 2) {
                                        const k = p[0].trim();
                                        const v = p.slice(1).join(':').trim();
                                        if (k) opts[k] = v || k;
                                    } else if (item.trim()) {
                                        const k = item.trim();
                                        opts[k] = k.charAt(0).toUpperCase() + k.slice(1);
                                    }
                                });
                            }
                            if (!opts || Object.keys(opts).length === 0) {
                                opts = { pending: 'Pending', active: 'Active' };
                            }
                            for (let [ok, ov] of Object.entries(opts)) {
                                out.push(`          ${ok}: ${ov}`);
                            }
                        }
                    }
                }

                // Output relations whose source_model matches this model
                const modelRels = this.allRelations.filter(r => r.source_model === m.handle);
                if (modelRels.length > 0) {
                    out.push('    relations:');
                    for (let r of modelRels) {
                        out.push(`      - name: ${r.name}`);
                        out.push(`        type: ${r.type}`);
                        out.push(`        model: ${r.model || ''}`);
                        if (r.foreign_key) {
                            out.push(`        foreign_key: ${r.foreign_key}`);
                        }
                    }
                }
            }

            this.source = out.join('\n');
            if (this.slices && this.slices[this.activeSliceIdx]) {
                this.slices[this.activeSliceIdx].name = this.blueprint.name || ('Slice ' + (this.activeSliceIdx + 1));
                this.slices[this.activeSliceIdx].handle = this.blueprint.handle || ('slice_' + (this.activeSliceIdx + 1));
                this.slices[this.activeSliceIdx].domain = this.blueprint.domain || '';
                this.slices[this.activeSliceIdx].source = this.source;
                this.slices[this.activeSliceIdx].blueprint = JSON.parse(JSON.stringify(this.blueprint));
                this.slices[this.activeSliceIdx].allRelations = JSON.parse(JSON.stringify(this.allRelations || []));
                try {
                    sessionStorage.setItem('laraslice_studio_slices', JSON.stringify(this.slices));
                    sessionStorage.setItem('laraslice_active_slice_idx', String(this.activeSliceIdx));
                } catch(e) {}
            }
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
    }));
});
</script>
@endsection
