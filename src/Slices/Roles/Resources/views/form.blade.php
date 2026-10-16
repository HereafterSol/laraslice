@extends('layouts.app')

@section('content')
@php
    $roleId = $form->id ?? null;
    $isSuperAdmin = ($form->slug ?? '') === 'super-admin';

    // Load active permission scopes from permission_role table
    $assignedScopes = [];
    if (!empty($roleId) && \Illuminate\Support\Facades\Schema::hasTable('permission_role')) {
        $assignedScopes = \Illuminate\Support\Facades\DB::table('permission_role')
            ->where('role_id', $roleId)
            ->pluck('permission_type_id', 'permission_id')
            ->toArray();
    }

    // Helper to get current scope
    $getScope = function($permId) use ($assignedScopes, $isSuperAdmin) {
        if ($isSuperAdmin) return 4; // 'all'
        return $assignedScopes[$permId] ?? 5; // default 'none'
    };

    // Load all permissions from database
    $allPerms = \Illuminate\Support\Facades\DB::table('permissions')->get();

    // Group permissions by Domain => Module
    $domains = [];
    foreach ($allPerms as $p) {
        $dom = $p->domain ?: 'System';
        $mod = $p->group ?: ($p->module_name ? ucwords($p->module_name) : 'General');
        
        if (!isset($domains[$dom])) {
            $domains[$dom] = [];
        }
        if (!isset($domains[$dom][$mod])) {
            $domains[$dom][$mod] = [
                'crud' => ['view' => null, 'create' => null, 'update' => null, 'delete' => null],
                'custom' => []
            ];
        }

        $slug = strtolower($p->slug);
        if (!$p->is_custom && (str_contains($slug, 'view_') || str_contains($slug, '.view') || str_contains($slug, 'show_'))) {
            $domains[$dom][$mod]['crud']['view'] = $p;
        } elseif (!$p->is_custom && (str_contains($slug, 'add_') || str_contains($slug, '.create') || str_contains($slug, 'create_'))) {
            $domains[$dom][$mod]['crud']['create'] = $p;
        } elseif (!$p->is_custom && (str_contains($slug, 'edit_') || str_contains($slug, '.edit') || str_contains($slug, 'update_'))) {
            $domains[$dom][$mod]['crud']['update'] = $p;
        } elseif (!$p->is_custom && (str_contains($slug, 'delete_') || str_contains($slug, '.delete') || str_contains($slug, 'destroy_'))) {
            $domains[$dom][$mod]['crud']['delete'] = $p;
        } else {
            $domains[$dom][$mod]['custom'][] = $p;
        }
    }
@endphp

<div class="max-w-6xl mx-auto space-y-6" x-data="{
    setAllScopes(targetVal) {
        document.querySelectorAll('select[data-scope-select]').forEach(el => {
            el.value = targetVal;
        });
    },
    setDomainScopes(btn, targetVal) {
        btn.closest('.domain-card').querySelectorAll('select[data-scope-select]').forEach(el => {
            el.value = targetVal;
        });
    },
    setRowScopes(btn, targetVal) {
        btn.closest('tr').querySelectorAll('select[data-scope-select]').forEach(el => {
            el.value = targetVal;
        });
    }
}">
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('roles.index') }}" class="hover:text-primary transition-colors flex items-center gap-1">
            <x-lucide-arrow-left class="size-3" />
            <span>Roles Catalog</span>
        </a>
        <span>/</span>
        <span class="text-foreground font-medium">{{ $isNew ? 'Create Role' : 'Edit Role #' . $form->id }}</span>
    </div>

    @if (session('error'))
        <div class="p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-alert-circle class="size-4 shrink-0" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <form action="{{ $isNew ? route('roles.store') : route('roles.update', $form->id) }}" method="POST" class="space-y-6">
        @csrf
        @if(!$isNew) @method('PUT') @endif

        <!-- Role Metadata & Core Configuration -->
        <x-ui.card variant="sectioned" class="shadow-xs bg-card border border-border">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <x-ui.card-title class="text-lg font-bold text-foreground">{{ $isNew ? 'Create New Role' : 'Edit Role Definition' }}</x-ui.card-title>
                <x-ui.card-description>Configure role identifiers, security posture, and authorization scope</x-ui.card-description>
            </x-ui.card-header>

            <x-ui.card-content class="p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="name">Role Name *</x-ui.label>
                        <x-ui.input id="name" name="name" value="{{ old('name', $form->name) }}" required placeholder="e.g. Finance Auditor" />
                        @error('name')
                            <p class="text-xs text-destructive font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="slug">Slug (Identifier)</x-ui.label>
                        <x-ui.input id="slug" name="slug" value="{{ old('slug', $form->slug) }}" placeholder="finance-auditor" class="font-mono text-xs" />
                        @error('slug')
                            <p class="text-xs text-destructive font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="space-y-1.5">
                    <x-ui.label for="description">Description & Responsibilities</x-ui.label>
                    <x-ui.textarea id="description" name="description" rows="2" placeholder="Describe role operational scope and boundaries...">{{ old('description', $form->description) }}</x-ui.textarea>
                </div>

                <!-- MFA Enforcement Policy Toggle -->
                <div class="p-4 rounded-xl border border-border/80 bg-muted/20 flex items-center justify-between">
                    <div class="space-y-0.5 pr-4">
                        <label for="enforce_mfa" class="text-xs font-bold uppercase tracking-wider text-foreground cursor-pointer flex items-center gap-1.5">
                            <x-lucide-shield-alert class="size-3.5 text-purple-600" />
                            <span>Enforce Multi-Factor Authentication (MFA)</span>
                        </label>
                        <p class="text-xs text-muted-foreground">When security policy is active, all users assigned to this role are required to verify 2FA TOTP credentials.</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                        <input type="checkbox" id="enforce_mfa" name="enforce_mfa" value="1" {{ !empty($isMfaEnforced) ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-slate-300 dark:bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                    </label>
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <!-- Enterprise Scoped Permissions Matrix (Exact ERP Screenshot 4 Architecture) -->
        <x-ui.card variant="sectioned" class="shadow-xs bg-card border border-border">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <x-ui.card-title class="text-base font-bold text-foreground flex items-center gap-2">
                            <x-lucide-shield-check class="size-5 text-primary" />
                            <span>Scoped Module Authorization Matrix</span>
                        </x-ui.card-title>
                        <x-ui.card-description>5-level scope matrix (All, Added, Owned, Both, None) with inline sub-permissions accordion</x-ui.card-description>
                    </div>

                    <div class="flex items-center gap-2 flex-wrap">
                        <button 
                            type="button" 
                            @click="setAllScopes(4)" 
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 border border-emerald-500/20 transition-colors cursor-pointer"
                        >
                            Set All to "All"
                        </button>
                        <button 
                            type="button" 
                            @click="setAllScopes(2)" 
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-500/10 text-amber-600 hover:bg-amber-500/20 border border-amber-500/20 transition-colors cursor-pointer"
                        >
                            Set All to "Owned"
                        </button>
                        <button 
                            type="button" 
                            @click="setAllScopes(5)" 
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-muted text-muted-foreground hover:text-foreground border border-border transition-colors cursor-pointer"
                        >
                            Revoke All (None)
                        </button>
                    </div>
                </div>
            </x-ui.card-header>

            <x-ui.card-content class="p-6 space-y-6">
                <div class="space-y-6">
                    @forelse ($domains as $domainName => $modules)
                        <div class="domain-card rounded-2xl border border-border bg-card overflow-hidden shadow-xs">
                            <!-- Domain Header -->
                            <div class="bg-muted/40 px-5 py-3 border-b border-border flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <div class="size-7 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold">
                                        @if (str_contains(strtolower($domainName), 'commerce') || str_contains(strtolower($domainName), 'finance'))
                                            <x-lucide-receipt class="size-3.5" />
                                        @elseif (str_contains(strtolower($domainName), 'human') || str_contains(strtolower($domainName), 'staff'))
                                            <x-lucide-users class="size-3.5" />
                                        @elseif (str_contains(strtolower($domainName), 'work') || str_contains(strtolower($domainName), 'project'))
                                            <x-lucide-folder-git-2 class="size-3.5" />
                                        @else
                                            <x-lucide-shield-check class="size-3.5" />
                                        @endif
                                    </div>
                                    <div>
                                        <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">{{ $domainName }}</h3>
                                        <span class="text-[10px] text-muted-foreground">{{ count($modules) }} Modules</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button 
                                        type="button" 
                                        @click="setDomainScopes($el, 4)" 
                                        class="text-[11px] font-semibold text-emerald-600 hover:underline cursor-pointer"
                                    >
                                        All
                                    </button>
                                    <span class="text-muted-foreground/30">•</span>
                                    <button 
                                        type="button" 
                                        @click="setDomainScopes($el, 2)" 
                                        class="text-[11px] font-semibold text-amber-600 hover:underline cursor-pointer"
                                    >
                                        Owned
                                    </button>
                                    <span class="text-muted-foreground/30">•</span>
                                    <button 
                                        type="button" 
                                        @click="setDomainScopes($el, 5)" 
                                        class="text-[11px] font-semibold text-muted-foreground hover:text-foreground cursor-pointer"
                                    >
                                        None
                                    </button>
                                </div>
                            </div>

                            <!-- ERP Scoped Matrix Table (Matches Screenshot 4) -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs text-left">
                                    <thead class="bg-muted/20 border-b border-border text-[11px] font-bold text-muted-foreground uppercase tracking-wider">
                                        <tr>
                                            <th class="py-3 px-5 w-1/4">Module</th>
                                            <th class="py-3 px-3 text-center w-32">Add</th>
                                            <th class="py-3 px-3 text-center w-32">View</th>
                                            <th class="py-3 px-3 text-center w-32">Update</th>
                                            <th class="py-3 px-3 text-center w-32">Delete</th>
                                            <th class="py-3 px-4 text-center w-28">Sub-Permissions</th>
                                        </tr>
                                    </thead>
                                    @foreach ($modules as $modName => $data)
                                        @php
                                            $viewP = $data['crud']['view'];
                                            $createP = $data['crud']['create'];
                                            $editP = $data['crud']['update'];
                                            $deleteP = $data['crud']['delete'];
                                            $customPerms = $data['custom'];
                                            $hasCustom = count($customPerms) > 0;
                                        @endphp
                                        <tbody x-data="{ expanded: false }" class="divide-y divide-border/40">
                                            <tr class="hover:bg-muted/10 transition-colors">
                                                <!-- Module Identity -->
                                                <td class="py-3 px-5">
                                                    <div class="flex items-center gap-2">
                                                        <x-lucide-box class="size-3.5 text-muted-foreground/70 shrink-0" />
                                                        <span class="font-bold text-foreground text-xs">{{ $modName }}</span>
                                                    </div>
                                                </td>

                                                <!-- Add Scoped Select -->
                                                <td class="py-2.5 px-3 text-center">
                                                    @if($createP)
                                                        @php $sVal = $getScope($createP->id); @endphp
                                                        <select 
                                                            name="permission_scopes[{{ $createP->id }}]" 
                                                            data-scope-select
                                                            class="w-full px-2 py-1 rounded-lg border border-border/80 bg-background text-xs font-semibold focus:outline-none focus:ring-1 focus:ring-primary cursor-pointer shadow-2xs"
                                                        >
                                                            <option value="4" {{ $sVal == 4 ? 'selected' : '' }}>All</option>
                                                            <option value="1" {{ $sVal == 1 ? 'selected' : '' }}>Added</option>
                                                            <option value="5" {{ $sVal == 5 ? 'selected' : '' }}>None</option>
                                                        </select>
                                                    @else
                                                        <span class="text-muted-foreground/40 font-mono text-xs">—</span>
                                                    @endif
                                                </td>

                                                <!-- View Scoped Select -->
                                                <td class="py-2.5 px-3 text-center">
                                                    @if($viewP)
                                                        @php $sVal = $getScope($viewP->id); @endphp
                                                        <select 
                                                            name="permission_scopes[{{ $viewP->id }}]" 
                                                            data-scope-select
                                                            class="w-full px-2 py-1 rounded-lg border border-border/80 bg-background text-xs font-semibold focus:outline-none focus:ring-1 focus:ring-primary cursor-pointer shadow-2xs"
                                                        >
                                                            <option value="4" {{ $sVal == 4 ? 'selected' : '' }}>All</option>
                                                            <option value="1" {{ $sVal == 1 ? 'selected' : '' }}>Added</option>
                                                            <option value="2" {{ $sVal == 2 ? 'selected' : '' }}>Owned</option>
                                                            <option value="3" {{ $sVal == 3 ? 'selected' : '' }}>Both</option>
                                                            <option value="5" {{ $sVal == 5 ? 'selected' : '' }}>None</option>
                                                        </select>
                                                    @else
                                                        <span class="text-muted-foreground/40 font-mono text-xs">—</span>
                                                    @endif
                                                </td>

                                                <!-- Update Scoped Select -->
                                                <td class="py-2.5 px-3 text-center">
                                                    @if($editP)
                                                        @php $sVal = $getScope($editP->id); @endphp
                                                        <select 
                                                            name="permission_scopes[{{ $editP->id }}]" 
                                                            data-scope-select
                                                            class="w-full px-2 py-1 rounded-lg border border-border/80 bg-background text-xs font-semibold focus:outline-none focus:ring-1 focus:ring-primary cursor-pointer shadow-2xs"
                                                        >
                                                            <option value="4" {{ $sVal == 4 ? 'selected' : '' }}>All</option>
                                                            <option value="1" {{ $sVal == 1 ? 'selected' : '' }}>Added</option>
                                                            <option value="2" {{ $sVal == 2 ? 'selected' : '' }}>Owned</option>
                                                            <option value="3" {{ $sVal == 3 ? 'selected' : '' }}>Both</option>
                                                            <option value="5" {{ $sVal == 5 ? 'selected' : '' }}>None</option>
                                                        </select>
                                                    @else
                                                        <span class="text-muted-foreground/40 font-mono text-xs">—</span>
                                                    @endif
                                                </td>

                                                <!-- Delete Scoped Select -->
                                                <td class="py-2.5 px-3 text-center">
                                                    @if($deleteP)
                                                        @php $sVal = $getScope($deleteP->id); @endphp
                                                        <select 
                                                            name="permission_scopes[{{ $deleteP->id }}]" 
                                                            data-scope-select
                                                            class="w-full px-2 py-1 rounded-lg border border-border/80 bg-background text-xs font-semibold focus:outline-none focus:ring-1 focus:ring-primary cursor-pointer shadow-2xs"
                                                        >
                                                            <option value="4" {{ $sVal == 4 ? 'selected' : '' }}>All</option>
                                                            <option value="1" {{ $sVal == 1 ? 'selected' : '' }}>Added</option>
                                                            <option value="2" {{ $sVal == 2 ? 'selected' : '' }}>Owned</option>
                                                            <option value="3" {{ $sVal == 3 ? 'selected' : '' }}>Both</option>
                                                            <option value="5" {{ $sVal == 5 ? 'selected' : '' }}>None</option>
                                                        </select>
                                                    @else
                                                        <span class="text-muted-foreground/40 font-mono text-xs">—</span>
                                                    @endif
                                                </td>

                                                <!-- More Accordion Trigger (Matches Screenshot 4) -->
                                                <td class="py-2.5 px-4 text-center">
                                                    @if($hasCustom)
                                                        <button 
                                                            type="button" 
                                                            @click.prevent.stop="expanded = !expanded"
                                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-muted/60 hover:bg-muted text-foreground transition-colors cursor-pointer border border-border/60"
                                                        >
                                                            <span>More ({{ count($customPerms) }})</span>
                                                            <x-lucide-chevron-down class="size-3 transition-transform" ::class="expanded ? 'rotate-180' : ''" />
                                                        </button>
                                                    @else
                                                        <span class="text-muted-foreground/30 text-xs">—</span>
                                                    @endif
                                                </td>
                                            </tr>

                                            <!-- Inline Sub-Permissions Accordion Row (Matches Screenshot 4) -->
                                            @if($hasCustom)
                                                <tr x-show="expanded" x-cloak class="bg-muted/15 border-b border-border/60">
                                                    <td colspan="6" class="p-4 pl-10">
                                                        <div class="rounded-xl border border-border/80 bg-card p-4 space-y-3 shadow-2xs">
                                                            <div class="flex items-center justify-between pb-2 border-b border-border/60">
                                                                <span class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                                                                    {{ $modName }} &bull; Granular Sub-Capabilities
                                                                </span>
                                                                <span class="text-[11px] text-muted-foreground">
                                                                    {{ count($customPerms) }} custom actions
                                                                </span>
                                                            </div>
                                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                                                @foreach ($customPerms as $cp)
                                                                    @php $cpVal = $getScope($cp->id); @endphp
                                                                    <div class="flex items-center justify-between gap-3 p-2.5 rounded-lg border border-border/60 bg-muted/10">
                                                                        <div class="min-w-0 pr-2">
                                                                            <span class="text-xs font-medium text-foreground block truncate" title="{{ $cp->display_name ?: $cp->name }}">
                                                                                {{ $cp->display_name ?: ucwords(str_replace('_', ' ', $cp->name)) }}
                                                                            </span>
                                                                            <span class="text-[10px] font-mono text-muted-foreground block truncate">
                                                                                {{ $cp->name }}
                                                                            </span>
                                                                        </div>
                                                                        <select 
                                                                            name="permission_scopes[{{ $cp->id }}]" 
                                                                            data-scope-select
                                                                            class="px-2 py-1 rounded-lg border border-border/80 bg-background text-xs font-semibold focus:outline-none focus:ring-1 focus:ring-primary cursor-pointer shrink-0 shadow-2xs"
                                                                        >
                                                                            <option value="4" {{ $cpVal == 4 ? 'selected' : '' }}>All</option>
                                                                            <option value="1" {{ $cpVal == 1 ? 'selected' : '' }}>Added</option>
                                                                            <option value="2" {{ $cpVal == 2 ? 'selected' : '' }}>Owned</option>
                                                                            <option value="3" {{ $cpVal == 3 ? 'selected' : '' }}>Both</option>
                                                                            <option value="5" {{ $cpVal == 5 ? 'selected' : '' }}>None</option>
                                                                        </select>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    </td>
                                            @endif
                                        </tbody>
                                    @endforeach
                                </table>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-muted-foreground p-4 bg-muted/20 rounded-xl">No permissions registered in system.</p>
                    @endforelse
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <!-- Form Submission Actions -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
            <x-ui.button href="{{ route('roles.index') }}" as="a" variant="outline">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit" name="action" value="save_continue" variant="secondary">
                Save & Continue
            </x-ui.button>
            <x-ui.button type="submit" name="action" value="save_close">
                {{ $isNew ? 'Create Role' : 'Save Changes' }}
            </x-ui.button>
        </div>
    </form>
</div>
@endsection