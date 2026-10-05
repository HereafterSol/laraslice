@extends('laraslice::layout')

@section('title', 'Schema Studio - 2-Column Visual Slice Builder')

@section('content')
<div class="space-y-6" x-data="schemaStudio()">
    <!-- Top Action & Navigation Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-4 border-b border-border">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ route('laraslice.wizard') }}" class="hover:text-primary transition">Scaffold Wizard</a>
                <span>/</span>
                <a href="{{ route('laraslice.wizard.studio') }}" class="hover:text-primary transition">Slice Studio</a>
                <span>/</span>
                <a href="{{ route('laraslice.wizard.blueprint') }}" class="hover:text-primary transition">Blueprint Studio</a>
                <span>/</span>
                <span class="text-foreground font-semibold">Schema Studio</span>
                <span class="px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 text-[10px] font-mono">2-Column Canvas</span>
            </div>
            <h1 class="text-xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-lucide-layout-grid class="size-5 text-primary" />
                <span>Visual Schema Designer & Slice Builder</span>
            </h1>
        </div>

        <div class="flex items-center gap-2.5">
            <!-- Mode / Existing Slice Picker -->
            <select x-model="selectedSliceName" @change="loadSlicePreset($event.target.value)" 
                    class="h-9 px-3 text-xs rounded-xl bg-card border border-border text-foreground font-medium focus:ring-1 focus:ring-primary shadow-xs">
                <option value="new">+ Create New Slice</option>
                <template x-for="s in slices" :key="s.name">
                    <option :value="s.name" x-text="s.title + ' (' + s.name + ')'"></option>
                </template>
            </select>

            <button type="button" @click="showCodePreview = true" 
                    class="px-3.5 py-2 rounded-xl bg-muted hover:bg-muted/80 text-foreground text-xs font-semibold flex items-center gap-1.5 transition shadow-xs">
                <x-lucide-code class="size-4" />
                <span>Preview Blueprint</span>
            </button>

            <button type="button" @click="saveAndScaffold()" :disabled="isSaving"
                    class="px-4 py-2 rounded-xl bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-bold flex items-center gap-2 transition shadow-md disabled:opacity-50">
                <template x-if="!isSaving">
                    <div class="flex items-center gap-1.5">
                        <x-lucide-save class="size-4" />
                        <span>Save & Scaffold Slice</span>
                    </div>
                </template>
                <template x-if="isSaving">
                    <div class="flex items-center gap-1.5">
                        <span class="size-3.5 rounded-full border-2 border-white border-t-transparent animate-spin"></span>
                        <span>Generating...</span>
                    </div>
                </template>
            </button>
        </div>
    </div>

    <!-- Alert / Status Banner -->
    <div x-show="statusMessage" x-transition class="p-3.5 rounded-xl border text-xs flex items-center justify-between shadow-xs"
         :class="statusSuccess ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-destructive/10 border-destructive/30 text-destructive'">
        <div class="flex items-center gap-2">
            <x-lucide-info class="size-4 shrink-0" />
            <span x-text="statusMessage"></span>
        </div>
        <button type="button" @click="statusMessage = ''" class="text-muted-foreground hover:text-foreground">
            <x-lucide-x class="size-4" />
        </button>
    </div>

    <!-- 2-COLUMN ASYMMETRIC CANVAS -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- LEFT COLUMN: SLICE SETTINGS & NAVIGATION (5 Cols / ~40%) -->
        <div class="lg:col-span-5 space-y-6">
            <div class="p-5 rounded-2xl bg-card border border-border shadow-xs space-y-5">
                <div class="border-b border-border/80 pb-3 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-foreground">Slice Configuration</h3>
                        <p class="text-[11px] text-muted-foreground">General identity, routing, and sidebar controls</p>
                    </div>
                    <span class="px-2 py-0.5 rounded bg-muted text-[10px] font-mono text-muted-foreground">ADR-001</span>
                </div>

                <!-- Labels -->
                <div class="grid grid-cols-2 gap-3.5">
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-foreground">Label (Singular) *</label>
                        <input type="text" x-model="sliceForm.labelSingular" @input="updateSlug()" placeholder="e.g. Lead"
                               class="w-full px-3 py-2 text-xs rounded-xl bg-background border border-input text-foreground focus:ring-1 focus:ring-primary shadow-xs" />
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-foreground">Label (Plural) *</label>
                        <input type="text" x-model="sliceForm.labelPlural" placeholder="e.g. Leads"
                               class="w-full px-3 py-2 text-xs rounded-xl bg-background border border-input text-foreground focus:ring-1 focus:ring-primary shadow-xs" />
                    </div>
                </div>

                <!-- Slice Name & Slug -->
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">Slice Name (PascalCase) *</label>
                    <input type="text" x-model="sliceForm.sliceName" placeholder="e.g. Leads"
                           class="w-full px-3 py-2 text-xs rounded-xl bg-background border border-input text-foreground font-mono focus:ring-1 focus:ring-primary shadow-xs" />
                </div>

                <!-- Domain Group -->
                <div class="space-y-2">
                    <label class="text-xs font-semibold text-foreground">Domain Group</label>
                    <input type="text" x-model="sliceForm.domain" placeholder="e.g. CRM, Commerce, HR"
                           class="w-full px-3 py-2 text-xs rounded-xl bg-background border border-input text-foreground focus:ring-1 focus:ring-primary shadow-xs" />
                    <div class="flex flex-wrap gap-1.5 pt-1">
                        <template x-for="d in ['CRM', 'Commerce', 'HR', 'Finance', 'Support', 'General']" :key="d">
                            <button type="button" @click="sliceForm.domain = d"
                                    class="px-2 py-0.5 rounded-md bg-muted hover:bg-muted/80 text-[10px] font-medium text-muted-foreground transition"
                                    :class="sliceForm.domain === d ? 'border border-primary text-primary font-bold' : ''"
                                    x-text="d">
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Description -->
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground">Description</label>
                    <textarea x-model="sliceForm.description" rows="2" placeholder="Brief summary of domain capabilities..."
                              class="w-full px-3 py-2 text-xs rounded-xl bg-background border border-input text-foreground focus:ring-1 focus:ring-primary shadow-xs"></textarea>
                </div>

                <!-- ROUTING & CONCURRENCY -->
                <div class="pt-3 border-t border-border/60 space-y-3">
                    <h4 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Routing & Concurrency</h4>
                    
                    <label class="flex items-center justify-between p-2.5 rounded-xl border border-border/80 bg-background/50 hover:bg-muted/20 cursor-pointer">
                        <div>
                            <span class="text-xs font-bold text-foreground block">Routable</span>
                            <span class="text-[10px] text-muted-foreground">Expose Web UI & REST API routes</span>
                        </div>
                        <input type="checkbox" x-model="sliceForm.routable" class="rounded border-input text-primary" />
                    </label>

                    <label class="flex items-center justify-between p-2.5 rounded-xl border border-border/80 bg-background/50 hover:bg-muted/20 cursor-pointer">
                        <div>
                            <span class="text-xs font-bold text-foreground block">Edit Locking</span>
                            <span class="text-[10px] text-muted-foreground">Prevent concurrent overwrite collisions</span>
                        </div>
                        <input type="checkbox" x-model="sliceForm.editLocking" class="rounded border-input text-primary" />
                    </label>

                    <div class="space-y-1">
                        <label class="text-[11px] font-semibold text-muted-foreground">URL Permalink Pattern</label>
                        <input type="text" x-model="sliceForm.urlPattern" placeholder="/leads/{id}"
                               class="w-full px-3 py-1.5 text-xs rounded-xl bg-background border border-input text-foreground font-mono" />
                    </div>
                </div>

                <!-- NAVIGATION & DASHBOARD CONTROLS -->
                <div class="pt-3 border-t border-border/60 space-y-3">
                    <h4 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Sidebar & Dashboard Navigation</h4>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-[11px] font-semibold text-muted-foreground">Navigation Group</label>
                            <input type="text" x-model="sliceForm.navGroup" placeholder="e.g. Sales"
                                   class="w-full px-3 py-1.5 text-xs rounded-xl bg-background border border-input text-foreground" />
                        </div>
                        <div class="space-y-1">
                            <label class="text-[11px] font-semibold text-muted-foreground">Lucide Icon</label>
                            <div class="flex items-center gap-2">
                                <input type="text" x-model="sliceForm.icon" placeholder="briefcase"
                                       class="flex-1 px-3 py-1.5 text-xs rounded-xl bg-background border border-input text-foreground font-mono" />
                                <div class="size-8 rounded-lg bg-muted flex items-center justify-center shrink-0 text-foreground">
                                    <x-lucide-box class="size-4" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <label class="flex items-center justify-between p-2.5 rounded-xl border border-border/80 bg-background/50 hover:bg-muted/20 cursor-pointer">
                        <div>
                            <span class="text-xs font-bold text-foreground block">Quick Action on Dashboard</span>
                            <span class="text-[10px] text-muted-foreground">Render a + New button on dashboard header</span>
                        </div>
                        <input type="checkbox" x-model="sliceForm.quickAction" class="rounded border-input text-primary" />
                    </label>

                    <label class="flex items-center justify-between p-2.5 rounded-xl border border-border/80 bg-background/50 hover:bg-muted/20 cursor-pointer">
                        <div>
                            <span class="text-xs font-bold text-foreground block">Hide from Navigation</span>
                            <span class="text-[10px] text-muted-foreground">Keep routes active but hide sidebar link</span>
                        </div>
                        <input type="checkbox" x-model="sliceForm.hideNav" class="rounded border-input text-primary" />
                    </label>
                </div>

                <!-- ENTERPRISE FEATURES -->
                <div class="pt-3 border-t border-border/60 space-y-2">
                    <h4 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Enterprise Capabilities</h4>

                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border/60 bg-background/40 cursor-pointer">
                            <input type="checkbox" x-model="sliceForm.features.workflow" class="rounded border-input text-primary" />
                            <span>State Workflow</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border/60 bg-background/40 cursor-pointer">
                            <input type="checkbox" x-model="sliceForm.features.softDeletes" class="rounded border-input text-primary" />
                            <span>Soft Deletes</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border/60 bg-background/40 cursor-pointer">
                            <input type="checkbox" x-model="sliceForm.features.userstamps" class="rounded border-input text-primary" />
                            <span>Audit Userstamps</span>
                        </label>
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border/60 bg-background/40 cursor-pointer">
                            <input type="checkbox" x-model="sliceForm.features.flutter" class="rounded border-input text-primary" />
                            <span>Flutter Client</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: FIELDS & AGGREGATES (7 Cols / ~60%) -->
        <div class="lg:col-span-7 space-y-6">

            <!-- 1. SYSTEM FIELDS CARD (READ-ONLY) -->
            <div class="p-5 rounded-2xl bg-card border border-border shadow-xs space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-border/80">
                    <div>
                        <h3 class="text-sm font-bold text-foreground flex items-center gap-1.5">
                            <x-lucide-lock class="size-3.5 text-muted-foreground" />
                            <span>System Fields</span>
                        </h3>
                        <p class="text-[11px] text-muted-foreground">Managed automatically by LaraSlice lifecycle engine</p>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-muted text-muted-foreground">6 Intrinsic Columns</span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 text-xs">
                    <div class="p-2.5 rounded-xl border border-border/60 bg-muted/20">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-foreground">id</span>
                            <span class="text-[9px] px-1.5 py-0.2 rounded bg-muted font-mono">ULID/ID</span>
                        </div>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">Primary Key Identifier</span>
                    </div>

                    <div class="p-2.5 rounded-xl border border-border/60 bg-muted/20">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-foreground">status</span>
                            <span class="text-[9px] px-1.5 py-0.2 rounded bg-muted font-mono">Enum</span>
                        </div>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">Draft, Active, Archived</span>
                    </div>

                    <div class="p-2.5 rounded-xl border border-border/60 bg-muted/20">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-foreground">created_at</span>
                            <span class="text-[9px] px-1.5 py-0.2 rounded bg-muted font-mono">Timestamp</span>
                        </div>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">Record Timestamp</span>
                    </div>

                    <div class="p-2.5 rounded-xl border border-border/60 bg-muted/20">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-foreground">updated_at</span>
                            <span class="text-[9px] px-1.5 py-0.2 rounded bg-muted font-mono">Timestamp</span>
                        </div>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">Update Timestamp</span>
                    </div>

                    <div class="p-2.5 rounded-xl border border-border/60 bg-muted/20">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-foreground">created_by</span>
                            <span class="text-[9px] px-1.5 py-0.2 rounded bg-muted font-mono">Userstamp</span>
                        </div>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">Audit Author ID</span>
                    </div>

                    <div class="p-2.5 rounded-xl border border-border/60 bg-muted/20">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-foreground">deleted_at</span>
                            <span class="text-[9px] px-1.5 py-0.2 rounded bg-muted font-mono">SoftDelete</span>
                        </div>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">Trash Recovery</span>
                    </div>
                </div>
            </div>

            <!-- 2. CUSTOM FIELDS BUILDER -->
            <div class="p-5 rounded-2xl bg-card border border-border shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-border/80">
                    <div>
                        <h3 class="text-sm font-bold text-foreground flex items-center gap-2">
                            <x-lucide-list class="size-4 text-primary" />
                            <span>Custom Slice Fields</span>
                        </h3>
                        <p class="text-[11px] text-muted-foreground">Domain model attributes, validation rules, and indexes</p>
                    </div>

                    <button type="button" @click="openAddFieldModal()"
                            class="px-3 py-1.5 rounded-xl bg-primary text-primary-foreground text-xs font-bold flex items-center gap-1.5 hover:bg-primary/90 transition shadow-xs">
                        <x-lucide-plus class="size-3.5" />
                        <span>Add Field</span>
                    </button>
                </div>

                <!-- Field List -->
                <div class="space-y-2.5">
                    <template x-for="(field, index) in sliceForm.fields" :key="index">
                        <div class="flex items-center justify-between p-3 rounded-xl border border-border bg-background hover:border-border/80 transition group shadow-2xs">
                            <div class="flex items-center gap-3">
                                <span class="text-muted-foreground cursor-grab active:cursor-grabbing font-mono text-xs">⋮⋮</span>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-xs text-foreground font-mono" x-text="field.name"></span>
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-mono font-semibold"
                                              :class="getFieldBadgeClass(field.type)" x-text="field.type"></span>
                                    </div>
                                    <div class="flex items-center gap-1.5 mt-0.5 text-[10px] text-muted-foreground">
                                        <template x-if="field.required">
                                            <span class="px-1.5 py-0.2 rounded bg-destructive/10 text-destructive font-semibold">Required</span>
                                        </template>
                                        <template x-if="field.unique">
                                            <span class="px-1.5 py-0.2 rounded bg-purple-500/10 text-purple-400 font-semibold">Unique</span>
                                        </template>
                                        <template x-if="field.index">
                                            <span class="px-1.5 py-0.2 rounded bg-blue-500/10 text-blue-400 font-semibold">Indexed</span>
                                        </template>
                                        <span x-text="field.label || field.name"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-1">
                                <button type="button" @click="editField(index)" class="p-1.5 text-muted-foreground hover:text-foreground rounded-lg hover:bg-muted transition">
                                    <x-lucide-edit-2 class="size-3.5" />
                                </button>
                                <button type="button" @click="removeField(index)" class="p-1.5 text-muted-foreground hover:text-destructive rounded-lg hover:bg-destructive/10 transition">
                                    <x-lucide-trash-2 class="size-3.5" />
                                </button>
                            </div>
                        </div>
                    </template>

                    <template x-if="sliceForm.fields.length === 0">
                        <div class="p-8 text-center border-2 border-dashed border-border rounded-xl text-muted-foreground text-xs space-y-2">
                            <x-lucide-layers class="size-6 mx-auto text-muted-foreground/60" />
                            <p class="font-semibold text-foreground">No custom fields defined yet</p>
                            <p class="text-[11px]">Click "Add Field" to define your domain attributes</p>
                        </div>
                    </template>
                </div>
            </div>

            <!-- 3. AGGREGATE CHILD TABLES (1:N RELATIONS) -->
            <div class="p-5 rounded-2xl bg-card border border-border shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-border/80">
                    <div>
                        <h3 class="text-sm font-bold text-foreground flex items-center gap-2">
                            <x-lucide-git-branch class="size-4 text-purple-500" />
                            <span>Aggregate Child Tables (1:N Relations)</span>
                        </h3>
                        <p class="text-[11px] text-muted-foreground">Line items, variants, sub-tasks, or nested domain models</p>
                    </div>

                    <button type="button" @click="openAddChildTableModal()"
                            class="px-3 py-1.5 rounded-xl bg-secondary text-secondary-foreground text-xs font-bold flex items-center gap-1.5 hover:bg-secondary/80 transition shadow-xs">
                        <x-lucide-plus class="size-3.5" />
                        <span>Add Child Table</span>
                    </button>
                </div>

                <!-- Child Tables List -->
                <div class="space-y-3">
                    <template x-for="(ct, cIdx) in sliceForm.childTables" :key="cIdx">
                        <div class="p-3.5 rounded-xl border border-border bg-background space-y-2 shadow-2xs">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-xs text-foreground font-mono" x-text="ct.name"></span>
                                    <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-purple-400 font-mono text-[10px]">hasMany</span>
                                    <span class="text-[10px] text-muted-foreground font-mono" x-text="'FK: ' + (ct.foreignKey || (sliceForm.sliceName.toLowerCase() + '_id'))"></span>
                                </div>
                                <button type="button" @click="removeChildTable(cIdx)" class="text-muted-foreground hover:text-destructive p-1 rounded transition">
                                    <x-lucide-trash-2 class="size-3.5" />
                                </button>
                            </div>
                            <div class="text-[11px] text-muted-foreground" x-text="ct.fields ? ct.fields.length + ' nested fields defined' : 'No nested fields'"></div>
                        </div>
                    </template>

                    <template x-if="sliceForm.childTables.length === 0">
                        <div class="p-6 text-center border border-dashed border-border rounded-xl text-muted-foreground text-xs">
                            <p class="font-medium">No child aggregate tables defined</p>
                            <p class="text-[11px] text-muted-foreground/80 mt-0.5">Use child tables for items like order lines, milestones, or attachments</p>
                        </div>
                    </template>
                </div>
            </div>

        </div>
    </div>

    <!-- ADD FIELD MODAL / DRAWER -->
    <div x-show="showFieldModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
        <div @click.away="showFieldModal = false" class="w-full max-w-lg p-6 rounded-2xl bg-card border border-border shadow-2xl space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <h3 class="text-sm font-bold text-foreground" x-text="editingFieldIndex !== null ? 'Edit Field' : 'Add Custom Field'"></h3>
                <button type="button" @click="showFieldModal = false" class="text-muted-foreground hover:text-foreground">
                    <x-lucide-x class="size-4" />
                </button>
            </div>

            <div class="space-y-3.5">
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-foreground">Column Name (snake_case) *</label>
                        <input type="text" x-model="modalField.name" placeholder="e.g. price"
                               class="w-full px-3 py-2 text-xs rounded-xl bg-background border border-input font-mono" />
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-foreground">Label</label>
                        <input type="text" x-model="modalField.label" placeholder="e.g. Price"
                               class="w-full px-3 py-2 text-xs rounded-xl bg-background border border-input" />
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">Data Type *</label>
                    <select x-model="modalField.type" class="w-full h-9 px-3 text-xs rounded-xl bg-background border border-input font-mono">
                        <option value="string">string (VARCHAR 255)</option>
                        <option value="text">text (TEXT)</option>
                        <option value="decimal">decimal (10,2)</option>
                        <option value="integer">integer (INT)</option>
                        <option value="boolean">boolean (TINYINT 1)</option>
                        <option value="date">date (DATE)</option>
                        <option value="datetime">datetime (DATETIME)</option>
                        <option value="json">json (JSON)</option>
                        <option value="select">select (Enum Dropdown)</option>
                    </select>
                </div>

                <div class="grid grid-cols-3 gap-3 pt-2">
                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-border bg-background cursor-pointer">
                        <input type="checkbox" x-model="modalField.required" class="rounded border-input text-primary" />
                        <span class="text-xs font-semibold">Required</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-border bg-background cursor-pointer">
                        <input type="checkbox" x-model="modalField.unique" class="rounded border-input text-primary" />
                        <span class="text-xs font-semibold">Unique</span>
                    </label>
                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-border bg-background cursor-pointer">
                        <input type="checkbox" x-model="modalField.index" class="rounded border-input text-primary" />
                        <span class="text-xs font-semibold">Indexed</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <button type="button" @click="showFieldModal = false" class="px-4 py-2 rounded-xl bg-muted text-xs font-medium">Cancel</button>
                <button type="button" @click="saveField()" class="px-4 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-bold">Apply Field</button>
            </div>
        </div>
    </div>

    <!-- CODE PREVIEW MODAL -->
    <div x-show="showCodePreview" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
        <div @click.away="showCodePreview = false" class="w-full max-w-2xl p-6 rounded-2xl bg-card border border-border shadow-2xl space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-border">
                <h3 class="text-sm font-bold text-foreground">Declarative Blueprint Preview</h3>
                <button type="button" @click="showCodePreview = false" class="text-muted-foreground hover:text-foreground">
                    <x-lucide-x class="size-4" />
                </button>
            </div>

            <pre class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-[11px] font-mono text-emerald-300 overflow-x-auto max-h-[60vh]"><code x-text="generateBlueprintYaml()"></code></pre>

            <div class="flex justify-end pt-2">
                <button type="button" @click="showCodePreview = false" class="px-4 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-bold">Close Preview</button>
            </div>
        </div>
    </div>
</div>

<script>
function schemaStudio() {
    return {
        slices: @json($slicesList ?? []),
        selectedSliceName: 'new',
        isSaving: false,
        statusMessage: '',
        statusSuccess: true,
        showFieldModal: false,
        showCodePreview: false,
        editingFieldIndex: null,

        sliceForm: {
            sliceName: 'Lead',
            labelSingular: 'Lead',
            labelPlural: 'Leads',
            domain: 'CRM',
            description: 'Customer inquiries, prospect qualification, and CRM pipeline tracking',
            routable: true,
            editLocking: true,
            urlPattern: '/crm/leads/{id}',
            navGroup: 'CRM Pipeline',
            icon: 'user-check',
            quickAction: true,
            hideNav: false,
            features: {
                workflow: true,
                softDeletes: true,
                userstamps: true,
                flutter: false
            },
            fields: [
                { name: 'full_name', label: 'Full Name', type: 'string', required: true, unique: false, index: true },
                { name: 'email', label: 'Email Address', type: 'string', required: true, unique: true, index: true },
                { name: 'phone', label: 'Phone Number', type: 'string', required: false, unique: false, index: false },
                { name: 'company', label: 'Company Name', type: 'string', required: false, unique: false, index: false },
                { name: 'estimated_budget', label: 'Estimated Budget', type: 'decimal', required: false, unique: false, index: false },
                { name: 'notes', label: 'Qualification Notes', type: 'text', required: false, unique: false, index: false },
            ],
            childTables: [
                {
                    name: 'lead_activities',
                    foreignKey: 'lead_id',
                    fields: [
                        { name: 'activity_type', type: 'string', required: true },
                        { name: 'summary', type: 'text', required: true }
                    ]
                }
            ]
        },

        modalField: {
            name: '',
            label: '',
            type: 'string',
            required: false,
            unique: false,
            index: false
        },

        updateSlug() {
            if (this.sliceForm.labelSingular) {
                const clean = this.sliceForm.labelSingular.replace(/[^A-Za-z0-9]/g, '');
                this.sliceForm.sliceName = clean.charAt(0).toUpperCase() + clean.slice(1);
                this.sliceForm.urlPattern = '/' + this.sliceForm.labelSingular.toLowerCase() + 's/{id}';
            }
        },

        loadSlicePreset(sliceName) {
            if (sliceName === 'new') {
                this.sliceForm.sliceName = 'Project';
                this.sliceForm.labelSingular = 'Project';
                this.sliceForm.labelPlural = 'Projects';
                this.sliceForm.domain = 'Projects';
                this.sliceForm.fields = [
                    { name: 'title', label: 'Project Title', type: 'string', required: true, unique: false, index: true },
                    { name: 'budget', label: 'Total Budget', type: 'decimal', required: false, unique: false, index: false }
                ];
                this.sliceForm.childTables = [];
                return;
            }

            const found = this.slices.find(s => s.name === sliceName);
            if (found) {
                this.sliceForm.sliceName = found.name;
                this.sliceForm.labelSingular = found.title;
                this.sliceForm.labelPlural = found.title + 's';
                this.sliceForm.domain = found.domain || 'General';
                this.sliceForm.description = found.description || '';
                try {
                    window.dispatchEvent(new CustomEvent('laraslice-slice-selected', {
                        detail: {
                            name: found.name,
                            title: found.title,
                            domain: found.domain || 'General',
                            version: found.version || '1.0.0',
                            description: found.description || '',
                            tables_data: [{ name: (found.name || '').toLowerCase(), columns_count: (this.sliceForm.fields || []).length }],
                            child_tables: this.sliceForm.childTables || [],
                            fields: this.sliceForm.fields || [],
                            is_schema_studio: true
                        }
                    }));
                } catch(e) {}
            }
        },

        openAddFieldModal() {
            this.editingFieldIndex = null;
            this.modalField = { name: '', label: '', type: 'string', required: false, unique: false, index: false };
            this.showFieldModal = true;
        },

        editField(index) {
            this.editingFieldIndex = index;
            this.modalField = { ...this.sliceForm.fields[index] };
            this.showFieldModal = true;
        },

        saveField() {
            if (!this.modalField.name.trim()) return;
            this.modalField.name = this.modalField.name.toLowerCase().replace(/[^a-z0-9_]/g, '_');
            
            if (this.editingFieldIndex !== null) {
                this.sliceForm.fields[this.editingFieldIndex] = { ...this.modalField };
            } else {
                this.sliceForm.fields.push({ ...this.modalField });
            }
            this.showFieldModal = false;
        },

        removeField(index) {
            this.sliceForm.fields.splice(index, 1);
        },

        openAddChildTableModal() {
            const tableName = prompt('Enter child table name (e.g. lead_notes, order_items):');
            if (tableName) {
                this.sliceForm.childTables.push({
                    name: tableName.toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                    foreignKey: this.sliceForm.sliceName.toLowerCase() + '_id',
                    fields: [
                        { name: 'title', type: 'string', required: true },
                        { name: 'content', type: 'text', required: false }
                    ]
                });
            }
        },

        removeChildTable(index) {
            this.sliceForm.childTables.splice(index, 1);
        },

        getFieldBadgeClass(type) {
            switch(type) {
                case 'string': return 'bg-blue-500/10 text-blue-400';
                case 'decimal': case 'integer': return 'bg-emerald-500/10 text-emerald-400';
                case 'boolean': return 'bg-amber-500/10 text-amber-400';
                case 'date': case 'datetime': return 'bg-purple-500/10 text-purple-400';
                case 'json': return 'bg-pink-500/10 text-pink-400';
                default: return 'bg-muted text-muted-foreground';
            }
        },

        generateBlueprintYaml() {
            return `schema_version: "1.0"
slice:
  name: "${this.sliceForm.sliceName}"
  domain: "${this.sliceForm.domain}"
  description: "${this.sliceForm.description}"
  navigation:
    group: "${this.sliceForm.navGroup}"
    icon: "${this.sliceForm.icon}"
    quick_action: ${this.sliceForm.quickAction}
    hidden: ${this.sliceForm.hideNav}
  concurrency:
    edit_locking: ${this.sliceForm.editLocking}
  features:
    workflow: ${this.sliceForm.features.workflow}
    soft_deletes: ${this.sliceForm.features.softDeletes}
    userstamps: ${this.sliceForm.features.userstamps}
fields:
` + this.sliceForm.fields.map(f => `  - name: "${f.name}"\n    type: "${f.type}"\n    required: ${f.required}\n    unique: ${f.unique}\n    index: ${f.index}`).join('\n') +
`\nchild_tables:
` + this.sliceForm.childTables.map(c => `  - name: "${c.name}"\n    relation: "hasMany"\n    foreign_key: "${c.foreignKey}"`).join('\n');
        },

        async saveAndScaffold() {
            this.isSaving = true;
            this.statusMessage = '';
            
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const response = await fetch("{{ route('laraslice.wizard.generate') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        projectName: this.sliceForm.sliceName,
                        domain: this.sliceForm.domain,
                        description: this.sliceForm.description,
                        workflow: this.sliceForm.features.workflow,
                        flutter: this.sliceForm.features.flutter,
                        runMigration: true,
                        fields: this.sliceForm.fields.map(f => ({
                            name: f.name,
                            type: f.type,
                            required: f.required
                        })),
                        childTables: this.sliceForm.childTables
                    })
                });

                const data = await response.json();
                if (data.success) {
                    this.statusSuccess = true;
                    this.statusMessage = `🎉 Slice [${this.sliceForm.sliceName}] successfully created and migrated!`;
                } else {
                    this.statusSuccess = false;
                    this.statusMessage = `⚠️ Error: ${data.message || 'Generation failed'}`;
                }
            } catch (e) {
                this.statusSuccess = false;
                this.statusMessage = `⚠️ Server error during slice generation: ${e.message}`;
            } finally {
                this.isSaving = false;
            }
        }
    }
}
</script>
@endsection
