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

        <x-ui.card-content class="p-0">
            <x-ui.table>
                <x-ui.table-header class="bg-muted/40">
                    <x-ui.table-row>
                        <x-ui.table-head>User</x-ui.table-head>
                        <x-ui.table-head>Device & Platform</x-ui.table-head>
                        <x-ui.table-head>Browser / OS</x-ui.table-head>
                        <x-ui.table-head>IP & Location</x-ui.table-head>
                        <x-ui.table-head>Last Active</x-ui.table-head>
                        <x-ui.table-head class="text-right">Action</x-ui.table-head>
                    </x-ui.table-row>
                </x-ui.table-header>
                <x-ui.table-body>
                    @forelse ($devices as $dev)
                        <x-ui.table-row class="hover:bg-muted/30 transition">
                            <x-ui.table-cell>
                                <div class="flex items-center gap-2.5">
                                    <div class="size-8 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-xs shrink-0 border border-primary/20">
                                        {{ strtoupper(substr($dev->user->name ?? 'U', 0, 1)) }}
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="font-semibold text-foreground text-xs">{{ $dev->user->name ?? 'Unknown User' }}</span>
                                        <span class="text-[11px] text-muted-foreground">{{ $dev->user->email ?? '—' }}</span>
                                    </div>
                                </div>
                            </x-ui.table-cell>

                            <x-ui.table-cell>
                                <div class="flex items-center gap-2">
                                    @php $plat = strtolower($dev->platform ?? 'web'); @endphp
                                    @if ($plat === 'android' || $plat === 'ios')
                                        <x-lucide-smartphone class="size-4 text-emerald-500 shrink-0" />
                                    @else
                                        <x-lucide-laptop class="size-4 text-blue-500 shrink-0" />
                                    @endif
                                    <div class="flex flex-col">
                                        <span class="font-medium text-xs text-foreground">{{ $dev->device_name }}</span>
                                        <span class="text-[10px] text-muted-foreground uppercase font-mono">{{ $dev->platform }}</span>
                                    </div>
                                </div>
                            </x-ui.table-cell>

                            <x-ui.table-cell class="text-xs text-muted-foreground font-mono">
                                {{ $dev->browser ?? '—' }} / {{ $dev->os ?? '—' }}
                            </x-ui.table-cell>

                            <x-ui.table-cell>
                                <div class="flex flex-col text-xs font-mono">
                                    <span class="text-foreground">{{ $dev->ip_address }}</span>
                                    <span class="text-[11px] text-muted-foreground">{{ $dev->location_label ?? 'Local / Unknown' }}</span>
                                </div>
                            </x-ui.table-cell>

                            <x-ui.table-cell>
                                @if ($dev->is_current)
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                        <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                        Active Now
                                    </span>
                                @else
                                    <span class="text-xs text-muted-foreground font-mono">
                                        {{ $dev->last_active_at ? $dev->last_active_at->diffForHumans() : 'Recently' }}
                                    </span>
                                @endif
                            </x-ui.table-cell>

                            <x-ui.table-cell class="text-right">
                                <form action="{{ route('users.devices.destroy', $dev->id) }}" method="POST" class="inline" onsubmit="return confirm('Revoke this session and disconnect the device?')">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="ghost" size="sm" class="text-destructive hover:bg-destructive/10 text-xs h-7 px-2">
                                        <x-lucide-log-out class="size-3.5 mr-1" />
                                        <span>Revoke</span>
                                    </x-ui.button>
                                </form>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @empty
                        <x-ui.table-row>
                            <x-ui.table-cell colspan="6" class="h-32 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-lucide-smartphone class="size-8 text-muted-foreground/50" />
                                    <p class="text-sm font-medium">No registered devices or active mobile sessions yet</p>
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
