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
                        <select name="status" aria-label="Filter by status" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
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

    @php
        $columns = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'email', 'label' => 'Email'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'gender', 'label' => 'Gender'],
            ['key' => 'employment', 'label' => 'Employment'],
            ['key' => 'roles', 'label' => 'Roles'],
            ['key' => 'permissions', 'label' => 'Permissions'],
            ['key' => 'cnic', 'label' => 'CNIC'],
            ['key' => 'phone', 'label' => 'Phone'],
        ];
        $rows = collect($pagedList->items)->map(fn ($user) => [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'status'      => ucfirst($user->status ?? 'pending'),
            'gender'      => $user->gender ? ucfirst($user->gender) : '—',
            'employment'  => trim(implode(' · ', array_filter([$user->employeeId ?? null, $user->designation ?? $user->department ?? null]))) ?: '—',
            'roles'       => !empty($user->roles) ? implode(', ', (array) $user->roles) : 'Standard User',
            'permissions' => (int) ($user->permissionsCount ?? 0),
            'cnic'        => $user->cnic ?? '—',
            'phone'       => $user->phone ?? '—',
            'edit_url'    => route('users.edit', $user->id),
            'delete_url'  => route('users.destroy', $user->id),
        ])->values()->all();
    @endphp

    <!-- Users Data Table Card -->
    <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
        <x-ui.card-header class="border-b pb-4 px-6 pt-6">
            <div class="flex items-center justify-between">
                <div>
                    <x-ui.card-title class="text-base font-semibold">User Directory</x-ui.card-title>
                    <x-ui.card-description>All enterprise identities, access statuses, and assigned RBAC permissions</x-ui.card-description>
                </div>
                <div class="text-xs text-muted-foreground font-medium">
                    Total: {{ $pagedList->totalCount }} users
                </div>
            </div>
        </x-ui.card-header>

        <x-ui.card-content class="p-6">
            @if ($pagedList->totalCount > count($rows))
                <p class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                    Showing the latest {{ count($rows) }} of {{ $pagedList->totalCount }} users. Raise <code>LARASLICE_DATA_TABLE_MAX_ROWS</code> to load more.
                </p>
            @endif

            @if (count($rows) === 0)
                <div class="flex flex-col items-center justify-center gap-2 py-10 text-center text-muted-foreground">
                    <x-lucide-inbox class="size-8 text-muted-foreground/50" />
                    <p class="text-sm font-medium">No users found matching your criteria</p>
                    <x-ui.button href="{{ route('users.create') }}" as="a" variant="outline" size="sm">
                        Create new user
                    </x-ui.button>
                </div>
            @else
                <x-ui.data-table :columns="$columns" :rows="$rows" :page-size="10" search-placeholder="Filter users..." sticky-actions>
                    <x-slot:actions>
                        <x-ui.button as="a" ::href="item.r.edit_url" variant="ghost" size="sm">
                            <x-lucide-pencil class="size-4" /> Edit
                        </x-ui.button>
                        <form method="POST" :action="item.r.delete_url" class="inline" onsubmit="return confirm('Delete user account?')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm" class="text-destructive hover:text-destructive">
                                <x-lucide-trash-2 class="size-4" /> Delete
                            </x-ui.button>
                        </form>
                    </x-slot:actions>
                </x-ui.data-table>
            @endif
        </x-ui.card-content>
    </x-ui.card>
</div>
@endsection
