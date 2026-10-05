@extends('layouts.app')

@section('content')
<div class="w-full max-w-7xl mx-auto space-y-6">
    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">User Management</h1>
            <p class="text-sm text-muted-foreground">Manage user accounts, demographic profiles, employment roles, and access credentials</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button href="{{ route('users.create') }}" as="a" variant="default" class="shadow-xs">
                <x-lucide-user-plus class="size-4 mr-1.5" />
                <span>Create User</span>
            </x-ui.button>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle-2 class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Filter Card -->
    <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
        <x-ui.card-content class="p-4">
            <form action="{{ route('users.index') }}" method="GET" class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                <div class="flex flex-1 items-center gap-3">
                    <div class="relative flex-1 max-w-md">
                        <x-lucide-search class="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                        <x-ui.input
                            name="search"
                            value="{{ $filter->search ?? '' }}"
                            placeholder="Search by name, email, CNIC, employee ID..."
                            class="pl-9"
                        />
                    </div>
                    <div class="w-full sm:w-48">
                        <select name="status" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                            <option value="">All Statuses</option>
                            <option value="active" {{ ($filter->status ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="suspended" {{ ($filter->status ?? '') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                            <option value="pending" {{ ($filter->status ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.button type="submit" variant="secondary" size="sm">
                        <x-lucide-filter class="size-3.5 mr-1" />
                        <span>Filter</span>
                    </x-ui.button>
                    @if (!empty($filter->search) || !empty($filter->status))
                        <x-ui.button href="{{ route('users.index') }}" as="a" variant="ghost" size="sm">
                            Reset
                        </x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card-content>
    </x-ui.card>

    <!-- Users Table Card -->
    <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
        <x-ui.card-header class="border-b pb-4 px-6 pt-6">
            <div class="flex items-center justify-between">
                <div>
                    <x-ui.card-title class="text-base font-semibold">User Directory</x-ui.card-title>
                    <x-ui.card-description>All enterprise identities, access statuses, and assigned RBAC permissions</x-ui.card-description>
                </div>
                <div class="text-xs text-muted-foreground font-medium">
                    Total: {{ count($pagedList->items) }} users
                </div>
            </div>
        </x-ui.card-header>

        <x-ui.card-content class="p-0">
            <x-ui.table>
                <x-ui.table-header class="bg-muted/40">
                    <x-ui.table-row>
                        <x-ui.table-head>User Identity</x-ui.table-head>
                        <x-ui.table-head>Gender</x-ui.table-head>
                        <x-ui.table-head>Status</x-ui.table-head>
                        <x-ui.table-head>Employment</x-ui.table-head>
                        <x-ui.table-head>Roles & Permissions</x-ui.table-head>
                        <x-ui.table-head>Contact / CNIC</x-ui.table-head>
                        <x-ui.table-head class="text-right">Actions</x-ui.table-head>
                    </x-ui.table-row>
                </x-ui.table-header>
                <x-ui.table-body>
                    @forelse ($pagedList->items as $user)
                        <x-ui.table-row class="hover:bg-muted/30 transition">
                            <!-- User Identity -->
                            <x-ui.table-cell>
                                <div class="flex items-center gap-3">
                                    <div class="size-9 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-xs shrink-0 border border-primary/20">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                    <div class="flex flex-col">
                                        <a href="{{ route('users.edit', $user->id) }}" class="font-semibold text-foreground hover:text-primary transition">
                                            {{ $user->name }}
                                        </a>
                                        <span class="text-xs text-muted-foreground">{{ $user->email }}</span>
                                    </div>
                                </div>
                            </x-ui.table-cell>

                            <!-- Gender Badge -->
                            <x-ui.table-cell>
                                @php $g = strtolower($user->gender ?? ''); @endphp
                                @if ($g === 'male')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-blue-500/10 text-blue-600 border border-blue-500/20">
                                        Male
                                    </span>
                                @elseif ($g === 'female')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-pink-500/10 text-pink-600 border border-pink-500/20">
                                        Female
                                    </span>
                                @elseif ($g === 'other')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-purple-500/10 text-purple-600 border border-purple-500/20">
                                        Other
                                    </span>
                                @else
                                    <span class="text-xs text-muted-foreground/60">—</span>
                                @endif
                            </x-ui.table-cell>

                            <!-- Status Badge -->
                            <x-ui.table-cell>
                                @if ($user->status === 'active')
                                    <x-ui.badge variant="outline" class="text-emerald-600 border-emerald-500/30 bg-emerald-500/10">Active</x-ui.badge>
                                @elseif ($user->status === 'suspended')
                                    <x-ui.badge variant="outline" class="text-rose-600 border-rose-500/30 bg-rose-500/10">Suspended</x-ui.badge>
                                @else
                                    <x-ui.badge variant="outline" class="text-amber-600 border-amber-500/30 bg-amber-500/10">{{ ucfirst($user->status ?? 'pending') }}</x-ui.badge>
                                @endif
                            </x-ui.table-cell>

                            <!-- Employment -->
                            <x-ui.table-cell>
                                <div class="flex flex-col text-xs">
                                    @if (!empty($user->employeeId))
                                        <span class="font-mono font-medium text-foreground">{{ $user->employeeId }}</span>
                                    @else
                                        <span class="text-muted-foreground/60">—</span>
                                    @endif

                                    @if (!empty($user->designation) || !empty($user->department))
                                        <span class="text-[11px] text-muted-foreground truncate max-w-[140px]">
                                            {{ $user->designation ?? $user->department }}
                                        </span>
                                    @endif
                                </div>
                            </x-ui.table-cell>

                            <!-- Assigned Roles & Permissions Count -->
                            <x-ui.table-cell>
                                <div class="flex flex-col gap-1 items-start">
                                    <div class="flex flex-wrap gap-1">
                                        @if (!empty($user->roles))
                                            @foreach ($user->roles as $roleName)
                                                <x-ui.badge variant="secondary" class="text-[11px]">{{ $roleName }}</x-ui.badge>
                                            @endforeach
                                        @else
                                            <span class="text-xs text-muted-foreground">Standard User</span>
                                        @endif
                                    </div>
                                    @if (!empty($user->permissionsCount) && $user->permissionsCount > 0)
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20" title="Effective active permissions">
                                            <x-lucide-shield-check class="size-3" />
                                            {{ $user->permissionsCount }} permissions
                                        </span>
                                    @endif
                                </div>
                            </x-ui.table-cell>

                            <!-- Contact / CNIC -->
                            <x-ui.table-cell>
                                <div class="flex flex-col text-xs font-mono text-muted-foreground">
                                    <span>{{ $user->cnic ?? '—' }}</span>
                                    @if(!empty($user->phone))
                                        <span class="text-[11px] text-muted-foreground/80">{{ $user->phone }}</span>
                                    @endif
                                </div>
                            </x-ui.table-cell>

                            <!-- Actions -->
                            <x-ui.table-cell class="text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <x-ui.button href="{{ route('users.edit', $user->id) }}" as="a" variant="ghost" size="sm" class="size-8 p-0" title="Edit User">
                                        <x-lucide-pencil class="size-4 text-muted-foreground" />
                                    </x-ui.button>
                                    <form action="{{ route('users.destroy', $user->id) }}" method="POST" class="inline" onsubmit="return confirm('Delete user account?')">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" size="sm" class="size-8 p-0 hover:text-destructive" title="Delete User">
                                            <x-lucide-trash-2 class="size-4" />
                                        </x-ui.button>
                                    </form>
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @empty
                        <x-ui.table-row>
                            <x-ui.table-cell colspan="7" class="h-32 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-lucide-inbox class="size-8 text-muted-foreground/50" />
                                    <p class="text-sm font-medium">No users found matching your criteria</p>
                                    <x-ui.button href="{{ route('users.create') }}" as="a" variant="outline" size="sm">
                                        Create new user
                                    </x-ui.button>
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforelse
                </x-ui.table-body>
            </x-ui.table>
        </x-ui.card-content>
    </x-ui.card>
</div>
@endsection
