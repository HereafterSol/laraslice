@extends('layouts.app')

@section('content')
<div class="w-full max-w-7xl mx-auto space-y-6">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('users.index') }}" class="hover:text-primary transition-colors">Users</a>
        <span>/</span>
        <span class="text-foreground font-medium">Active Devices & Sessions</span>
    </div>

    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">Active Devices & Sessions</h1>
            <p class="text-sm text-muted-foreground">Real-time tracking of authenticated devices, mobile push tokens, and remote session management</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button href="{{ route('users.index') }}" as="a" variant="outline" size="sm">
                <x-lucide-arrow-left class="size-4 mr-1.5" />
                <span>Back to Users</span>
            </x-ui.button>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle-2 class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Devices Table Card -->
    <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
        <x-ui.card-header class="border-b pb-4 px-6 pt-6">
            <div class="flex items-center justify-between">
                <div>
                    <x-ui.card-title class="text-base font-semibold">Registered Client Devices</x-ui.card-title>
                    <x-ui.card-description>All active browsers, mobile apps, and authorized workstations</x-ui.card-description>
                </div>
                <div class="text-xs text-muted-foreground font-medium">
                    Total: {{ $devices->count() }} devices
                </div>
            </div>
        </x-ui.card-header>

        <x-ui.card-content class="p-6">
            @php
                $columns = [
                    ['key' => 'user', 'label' => 'User'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'device', 'label' => 'Device'],
                    ['key' => 'platform', 'label' => 'Platform'],
                    ['key' => 'browser_os', 'label' => 'Browser / OS'],
                    ['key' => 'ip', 'label' => 'IP Address'],
                    ['key' => 'location', 'label' => 'Location'],
                    ['key' => 'last_active', 'label' => 'Last Active'],
                ];
                $rows = $devices->map(fn ($dev) => [
                    'id'          => $dev->id,
                    'user'        => $dev->user->name ?? 'Unknown User',
                    'email'       => $dev->user->email ?? '—',
                    'device'      => $dev->device_name ?? '—',
                    'platform'    => $dev->platform ?? '—',
                    'browser_os'  => ($dev->browser ?? '—') . ' / ' . ($dev->os ?? '—'),
                    'ip'          => $dev->ip_address ?? '—',
                    'location'    => $dev->location_label ?? 'Local / Unknown',
                    'last_active' => $dev->is_current ? 'Active now' : ($dev->last_active_at?->format('Y-m-d H:i') ?? '—'),
                    'revoke_url'  => route('users.devices.destroy', $dev->id),
                ])->values()->all();
            @endphp

            @if (count($rows) === 0)
                <div class="flex flex-col items-center justify-center gap-2 py-10 text-center text-muted-foreground">
                    <x-lucide-smartphone class="size-8 text-muted-foreground/50" />
                    <p class="text-sm font-medium">No registered devices or active mobile sessions yet</p>
                </div>
            @else
                <x-ui.data-table :columns="$columns" :rows="$rows" :page-size="10" :selectable="false" search-placeholder="Filter devices...">
                    <x-slot:actions>
                        <form method="POST" :action="item.r.revoke_url" class="inline" onsubmit="return confirm('Revoke this session and disconnect the device?')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm" class="text-destructive hover:text-destructive">
                                <x-lucide-log-out class="size-4" /> Revoke
                            </x-ui.button>
                        </form>
                    </x-slot:actions>
                </x-ui.data-table>
            @endif
        </x-ui.card-content>
    </x-ui.card>
</div>
@endsection
