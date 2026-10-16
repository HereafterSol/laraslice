@extends('layouts.app')

@section('content')
<div class="w-full mx-auto space-y-4">
    <!-- Compact header: title + actions -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <h1 class="text-xl font-bold tracking-tight text-foreground">User Management</h1>
            <x-ui.badge variant="secondary" class="font-mono text-xs">v1.0.0</x-ui.badge>
        </div>
        <div class="flex items-center gap-2">
            @if($can['create'] ?? true)
            <x-ui.button href="{{ route('users.create') }}" as="a" class="shadow-xs">
                <x-lucide-plus class="size-4 mr-1" />
                <span>Add User</span>
            </x-ui.button>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="p-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2">
            <x-lucide-check-circle-2 class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif
    @if (session('error'))
        <div class="p-3 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm font-medium">
            {{ session('error') }}
        </div>
    @endif

    @php
        $columns = [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'email', 'label' => 'Email', 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'sortable' => true],
            ['key' => 'roles', 'label' => 'Roles', 'sortable' => false],
            ['key' => 'permissions', 'label' => 'Permissions', 'sortable' => false],
            ['key' => 'created_at', 'label' => 'Created', 'sortable' => true],
        ];
        $rows = collect($pagedList->items)->map(fn ($user) => [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'status'      => ucfirst($user->status ?? 'pending'),
            'roles'       => !empty($user->roles) ? implode(', ', (array) $user->roles) : 'Standard User',
            'permissions' => (int) ($user->permissionsCount ?? 0),
            'created_at'  => $user->created_at ?? '—',
            'edit_url'    => route('users.edit', $user->id),
            'delete_url'  => route('users.destroy', $user->id),
        ])->values()->all();
    @endphp

    @if(count($rows) === 0)
        <div class="flex flex-col items-center justify-center gap-2 py-16 text-center text-muted-foreground border border-dashed border-border rounded-xl">
            <x-lucide-inbox class="size-8 text-muted-foreground/50" />
            <p class="text-sm font-medium">No users found</p>
            @if($can['create'] ?? true)
            <x-ui.button href="{{ route('users.create') }}" as="a" variant="outline" size="sm">Add User</x-ui.button>
            @endif
        </div>
    @else
        <!-- Summary Cards (from ui.directory.summary_cards) -->
        @if(!empty($summaryCards))
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach($summaryCards as $card)
            <x-ui.stat
                :label="$card['label'] ?? ''"
                :value="$summaryData[$card['handle']] ?? '—'"
            />
            @endforeach
        </div>
        @endif

        <!-- Quick Filter Tabs (from ui.directory.quick_filters) -->
        @if(!empty($quickFilters))
        <div class="flex flex-wrap gap-2">
            @foreach($quickFilters as $qf)
            <x-ui.filter-tabs
                :tabs="$qf['options'] ?? []"
                :param="$qf['handle']"
            />
            @endforeach
        </div>
        @endif

        <!-- High-Density Data Table with Automatic 3-Dots Action Column -->
        <x-ui.data-table
            :columns="$columns"
            :rows="$rows"
            :page-size="10"
            search-placeholder="Start typing to search..."
        >
            <x-slot:actions>
                @if($can['edit'] ?? true)
                <x-ui.button as="a" ::href="item.r.edit_url" variant="ghost" size="icon" title="Edit">
                    <x-lucide-pencil class="size-4" />
                </x-ui.button>
                @endif
            </x-slot:actions>
            <x-slot:rowActions>
                @if($can['edit'] ?? true)
                <x-ui.button as="a" ::href="item.r.edit_url" variant="ghost" size="sm" class="w-full justify-start">
                    <x-lucide-pencil class="size-3.5 mr-1.5" /> Edit
                </x-ui.button>
                @endif
                @if($can['delete'] ?? true)
                <form method="POST" :action="item.r.delete_url" class="w-full" onsubmit="return confirm('Delete user account?')">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="ghost" size="sm" class="w-full justify-start text-destructive hover:text-destructive">
                        <x-lucide-trash-2 class="size-3.5 mr-1.5" /> Delete
                    </x-ui.button>
                </form>
                @endif
            </x-slot:rowActions>
        </x-ui.data-table>
    @endif
</div>
@endsection
