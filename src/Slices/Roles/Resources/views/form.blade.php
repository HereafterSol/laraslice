@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('roles.index') }}" class="hover:text-primary transition-colors">Roles</a>
        <span>/</span>
        <span class="text-foreground font-medium">{{ $isNew ? 'Create Role' : 'Edit Role #' . $form->id }}</span>
    </div>

    @if (session('error'))
        <div class="p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-alert-circle class="size-4 shrink-0" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
        <x-ui.card-header class="border-b pb-4 px-6 pt-6">
            <x-ui.card-title class="text-lg font-bold text-foreground">{{ $isNew ? 'Create New Role' : 'Edit Role & Permissions' }}</x-ui.card-title>
            <x-ui.card-description>Configure role identifiers and grant granular slice permissions</x-ui.card-description>
        </x-ui.card-header>

        <x-ui.card-content class="p-6">
            <form action="{{ $isNew ? route('roles.store') : route('roles.update', $form->id) }}" method="POST" class="space-y-6">
                @csrf
                @if(!$isNew) @method('PUT') @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="name">Role Name *</x-ui.label>
                        <x-ui.input id="name" name="name" value="{{ old('name', $form->name) }}" required placeholder="e.g. Sales Manager" />
                        @error('name')
                            <p class="text-xs text-destructive font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="slug">Slug (Identifier)</x-ui.label>
                        <x-ui.input id="slug" name="slug" value="{{ old('slug', $form->slug) }}" placeholder="sales-manager" class="font-mono text-xs" />
                        @error('slug')
                            <p class="text-xs text-destructive font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="space-y-1.5">
                    <x-ui.label for="description">Description</x-ui.label>
                    <x-ui.textarea id="description" name="description" rows="2" placeholder="Describe role responsibilities...">{{ old('description', $form->description) }}</x-ui.textarea>
                </div>

                <!-- MFA Enforcement Policy Toggle -->
                <div class="p-4 rounded-xl border border-border/80 bg-muted/20 flex items-center justify-between">
                    <div class="space-y-0.5 pr-4">
                        <label for="enforce_mfa" class="text-xs font-bold uppercase tracking-wider text-foreground cursor-pointer flex items-center gap-1.5">
                            <x-lucide-shield-alert class="size-3.5 text-purple-600" />
                            <span>Enforce Multi-Factor Authentication (MFA)</span>
                        </label>
                        <p class="text-xs text-muted-foreground">When global policy is set to "Privileged Roles Only", all users assigned to this role are strictly required to enroll and pass MFA.</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                        <input type="checkbox" id="enforce_mfa" name="enforce_mfa" value="1" {{ !empty($isMfaEnforced) ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-slate-300 dark:bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                    </label>
                </div>

                <!-- Granular Permissions Matrix -->
                <div class="space-y-3 pt-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <x-ui.label class="text-base font-bold">Permissions Matrix</x-ui.label>
                            <p class="text-xs text-muted-foreground mt-0.5">Toggle granular permissions discovered across installed slices</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="document.querySelectorAll('input[name=\'permissions[]\']').forEach(el => el.checked = true)" class="text-xs font-medium text-primary hover:underline cursor-pointer">Select All</button>
                            <span class="text-muted-foreground/40">|</span>
                            <button type="button" onclick="document.querySelectorAll('input[name=\'permissions[]\']').forEach(el => el.checked = false)" class="text-xs font-medium text-muted-foreground hover:text-foreground cursor-pointer">Deselect All</button>
                        </div>
                    </div>

                    @php
                        $sliceManager = app(\LaraSlice\Core\Discovery\SliceManager::class);
                        $allPerms = \Illuminate\Support\Facades\DB::table('permissions')->get();
                        $rawGranted = !empty($form->permissions) ? $form->permissions : (!empty($form->permissionIds) ? $form->permissionIds : []);
                        $grantedIds = is_array($rawGranted) ? $rawGranted : (method_exists($rawGranted, 'toArray') ? $rawGranted->toArray() : (array) $rawGranted);
                        if (($form->slug ?? '') === 'super-admin' && empty($grantedIds)) {
                            $grantedIds = \Illuminate\Support\Facades\DB::table('permissions')->pluck('id')->toArray();
                        }

                        // Map slices from SliceManager
                        $slices = $sliceManager->getSlices();
                        $metaMap = [];
                        foreach ($slices as $sl) {
                            $cleanTitle = !empty($sl->title) ? $sl->title : ucwords(str_replace(['_', '-'], ' ', $sl->name));
                            $domainName = !empty($sl->domain) ? $sl->domain : ($sl->navigation['group'] ?? 'Vertical Slices');
                            
                            $metaMap[strtolower($sl->name)] = ['domain' => $domainName, 'title' => $cleanTitle];
                            $metaMap[strtolower(str_replace([' ', '_', '-'], '', $sl->name))] = ['domain' => $domainName, 'title' => $cleanTitle];
                            $metaMap[strtolower($cleanTitle)] = ['domain' => $domainName, 'title' => $cleanTitle];
                        }

                        // Build grouped hierarchy: Domain => [ Slice Title => [ perms ] ]
                        $domainGroups = [];
                        foreach ($allPerms as $item) {
                            $rawGrp = strtolower($item->group ?? 'general');
                            $normGrp = strtolower(str_replace([' ', '_', '-'], '', $item->group ?? ''));
                            $meta = $metaMap[$rawGrp] ?? ($metaMap[$normGrp] ?? null);

                            $domain = !empty($item->domain) ? $item->domain : ($meta['domain'] ?? 'Vertical Slices');
                            $sliceTitle = $meta['title'] ?? ucwords(strtolower(str_replace(['_', '-'], ' ', $item->group ?? 'General')));

                            // Ensure clean Title Case (not all-caps like SHOPCATEGORIES)
                            if (strtoupper($sliceTitle) === $sliceTitle || str_contains($sliceTitle, '_')) {
                                $sliceTitle = ucwords(strtolower(str_replace(['_', '-'], ' ', $sliceTitle)));
                            }

                            if (!isset($domainGroups[$domain])) {
                                $domainGroups[$domain] = [];
                            }
                            if (!isset($domainGroups[$domain][$sliceTitle])) {
                                $domainGroups[$domain][$sliceTitle] = [];
                            }
                            $domainGroups[$domain][$sliceTitle][] = $item;
                        }

                        // Sort domains so E-Commerce / Business domains appear first, Vertical Slices after
                        uksort($domainGroups, function($a, $b) {
                            if ($a === 'E-Commerce') return -1;
                            if ($b === 'E-Commerce') return 1;
                            return strcasecmp($a, $b);
                        });
                    @endphp

                    <div class="space-y-6">
                        @forelse ($domainGroups as $domainName => $slicesInDomain)
                            @php
                                $domainTotalPerms = collect($slicesInDomain)->flatten(1)->count();
                            @endphp
                            <div class="rounded-2xl border border-border/80 bg-card/60 p-5 space-y-4 shadow-sm">
                                <!-- Domain Header -->
                                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 pb-3">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold">
                                            @if (str_contains(strtolower($domainName), 'commerce') || str_contains(strtolower($domainName), 'shop'))
                                                <x-lucide-shopping-cart class="size-4" />
                                            @elseif (str_contains(strtolower($domainName), 'user') || str_contains(strtolower($domainName), 'role'))
                                                <x-lucide-shield-check class="size-4" />
                                            @else
                                                <x-lucide-folder-tree class="size-4" />
                                            @endif
                                        </div>
                                        <div>
                                            <h3 class="text-sm font-bold text-foreground">{{ $domainName }}</h3>
                                            <p class="text-[11px] text-muted-foreground">{{ count($slicesInDomain) }} slices &bull; {{ $domainTotalPerms }} capabilities</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" 
                                            onclick="this.closest('.space-y-4').querySelectorAll('input[name=\'permissions[]\']').forEach(el => el.checked = true)" 
                                            class="text-[11px] font-medium text-primary hover:underline px-2.5 py-1 rounded bg-primary/5 hover:bg-primary/10 transition-colors cursor-pointer">
                                            Select Domain
                                        </button>
                                        <button type="button" 
                                            onclick="this.closest('.space-y-4').querySelectorAll('input[name=\'permissions[]\']').forEach(el => el.checked = false)" 
                                            class="text-[11px] font-medium text-muted-foreground hover:text-foreground px-2.5 py-1 rounded hover:bg-muted transition-colors cursor-pointer">
                                            Clear
                                        </button>
                                    </div>
                                </div>

                                <!-- Slices inside this Domain -->
                                <div class="space-y-3">
                                    @foreach ($slicesInDomain as $sliceTitle => $items)
                                        <div class="rounded-xl border border-border/60 bg-muted/10 p-3.5 space-y-2.5">
                                            <div class="flex items-center justify-between border-b border-border/40 pb-2">
                                                <div class="flex items-center gap-2">
                                                    <x-lucide-box class="size-3.5 text-muted-foreground" />
                                                    <span class="text-xs font-bold text-foreground tracking-wide">{{ $sliceTitle }}</span>
                                                    <span class="text-[10px] font-medium text-muted-foreground bg-muted/60 px-1.5 py-0.5 rounded-md">{{ count($items) }} permissions</span>
                                                </div>
                                                <button type="button" 
                                                    onclick="this.closest('.space-y-2.5').querySelectorAll('input[name=\'permissions[]\']').forEach(el => el.checked = !el.checked)" 
                                                    class="text-[11px] font-medium text-primary hover:underline transition-colors cursor-pointer">
                                                    Toggle Slice
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
                                                @foreach ($items as $item)
                                                    <label class="flex items-start gap-2 p-2 rounded-lg hover:bg-muted/40 cursor-pointer transition-colors border border-transparent hover:border-border/50">
                                                        <input type="checkbox" name="permissions[]" value="{{ $item->id }}" {{ in_array($item->id, $grantedIds) ? 'checked' : '' }} class="blat-checkbox mt-0.5">
                                                        <div class="flex flex-col min-w-0">
                                                            <span class="text-xs font-semibold text-foreground truncate">{{ $item->name }}</span>
                                                            <span class="text-[10px] font-mono text-muted-foreground truncate">{{ $item->slug }}</span>
                                                        </div>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-muted-foreground p-4 bg-muted/20 rounded-xl">No granular permissions found.</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-5 border-t border-border/50">
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
        </x-ui.card-content>
    </x-ui.card>
</div>
@endsection