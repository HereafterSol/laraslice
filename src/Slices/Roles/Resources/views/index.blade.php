@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <!-- Header with Breadcrumbs & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-border/40">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:text-primary transition-colors">Dashboard</a>
                <span>/</span>
                <span class="text-foreground font-medium">Roles & Permissions</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-3">
                Roles & Permissions (RBAC)
                <x-ui.badge variant="secondary" class="font-mono text-xs">v1.0.0</x-ui.badge>
            </h1>
            <p class="text-sm text-muted-foreground mt-0.5">Configure role authorization matrix and user privileges across all slices</p>
        </div>
        <div class="flex items-center gap-3">
            <x-ui.button href="{{ route('users.index') }}" as="a" variant="outline" class="gap-1.5 shadow-xs">
                <x-lucide-users class="size-4" />
                <span>Users List</span>
            </x-ui.button>
            <x-ui.button href="{{ route('roles.create') }}" as="a" class="gap-1.5 shadow-xs">
                <x-lucide-plus class="size-4" />
                <span>Create New Role</span>
            </x-ui.button>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-success/30 bg-success/10 text-success text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Roles Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @forelse ($pagedList->items as $role)
            <x-ui.card class="bg-card border border-border shadow-xs hover:border-primary/40 transition flex flex-col justify-between">
                <x-ui.card-header class="pb-3 px-5 pt-5">
                    @php
                        $privilegedRoles = class_exists(\LaraSlice\Slices\Users\Services\SecurityPolicyService::class) 
                            ? \LaraSlice\Slices\Users\Services\SecurityPolicyService::getPrivilegedRoles() 
                            : [];
                        $isMfaRole = in_array($role->slug, $privilegedRoles);
                    @endphp
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-1.5">
                            <span class="px-2 py-0.5 text-xs font-mono font-bold rounded-md {{ $role->slug === 'super-admin' ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20' : 'bg-primary/10 text-primary border border-primary/20' }}">
                                {{ $role->slug }}
                            </span>
                            @if($isMfaRole)
                                <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20 flex items-center gap-1" title="MFA Enforced for this role">
                                    <x-lucide-shield-alert class="size-2.5" />
                                    <span>MFA</span>
                                </span>
                            @endif
                        </div>
                        <span class="text-xs text-muted-foreground font-medium">{{ $role->usersCount }} Users</span>
                    </div>
                    <x-ui.card-title class="text-base font-bold text-foreground">{{ $role->name }}</x-ui.card-title>
                    <x-ui.card-description class="text-xs line-clamp-2 mt-1">{{ $role->description ?? 'No description provided.' }}</x-ui.card-description>
                </x-ui.card-header>

                <div class="px-5 py-4 mt-auto border-t border-border/50 flex items-center justify-between bg-muted/10">
                    <span class="text-xs text-muted-foreground font-medium flex items-center gap-1.5">
                        <x-lucide-shield-check class="size-3.5 text-primary" />
                        <span>{{ $role->permissionsCount }} Permissions</span>
                    </span>
                    <div class="flex items-center gap-2">
                        <x-ui.button href="{{ route('roles.edit', $role->id) }}" as="a" variant="ghost" size="sm" class="size-8 p-0">
                            <x-lucide-pencil class="size-3.5 text-muted-foreground" />
                        </x-ui.button>
                        @if ($role->slug !== 'super-admin')
                            <form action="{{ route('roles.destroy', $role->id) }}" method="POST" class="inline" onsubmit="return confirm('Delete this role?')">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="ghost" size="sm" aria-label="Delete role" class="size-8 p-0 hover:text-destructive">
                                    <x-lucide-trash-2 class="size-3.5" />
                                </x-ui.button>
                            </form>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        @empty
            <div class="col-span-3 text-center py-12 text-muted-foreground bg-card rounded-2xl border border-border">
                No roles found. Click "+ Create New Role" to begin.
            </div>
        @endforelse
    </div>
</div>
@endsection