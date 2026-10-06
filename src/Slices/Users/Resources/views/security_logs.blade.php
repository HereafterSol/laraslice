@extends('layouts.app')

@section('content')
<div class="w-full max-w-7xl mx-auto space-y-6" x-data="{ activeTab: 'attempts' }">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('users.index') }}" class="hover:text-primary transition-colors">Users</a>
        <span>/</span>
        <span class="text-foreground font-medium">Security & Audit Logs</span>
    </div>

    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="p-2 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20">
                    <x-lucide-shield-alert class="size-6" />
                </span>
                <span>Security Forensics & Attempts</span>
            </h1>
            <p class="text-sm text-muted-foreground mt-1">Forensic authentication telemetry, brute-force lockout tracking, lockscreen verification, and MFA audits</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button href="{{ route('users.metrics') }}" as="a" variant="outline" size="sm">
                <x-lucide-activity class="size-4 mr-1.5" />
                <span>Access Metrics</span>
            </x-ui.button>
            <x-ui.button href="{{ route('users.index') }}" as="a" variant="outline" size="sm">
                <x-lucide-arrow-left class="size-4 mr-1.5" />
                <span>Back to Users</span>
            </x-ui.button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 border-b border-border pb-3">
        <button 
            @click="activeTab = 'attempts'"
            :class="activeTab === 'attempts' ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground font-medium'"
            class="px-4 py-2 rounded-xl text-xs flex items-center gap-2 transition cursor-pointer">
            <x-lucide-fingerprint class="size-4" />
            <span>Login & Lockscreen Attempts (user_attempts)</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                  :class="activeTab === 'attempts' ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-muted text-muted-foreground'">
                {{ $attempts->count() }}
            </span>
        </button>

        <button 
            @click="activeTab = 'security_logs'"
            :class="activeTab === 'security_logs' ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground font-medium'"
            class="px-4 py-2 rounded-xl text-xs flex items-center gap-2 transition cursor-pointer">
            <x-lucide-shield-check class="size-4" />
            <span>Security Events Trail (user_security_logs)</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                  :class="activeTab === 'security_logs' ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-muted text-muted-foreground'">
                {{ $logs->count() }}
            </span>
        </button>
    </div>

    <!-- TAB 1: User Attempts Table (user_attempts) -->
    <div x-show="activeTab === 'attempts'" x-cloak class="space-y-4">
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <div class="flex items-center justify-between">
                    <div>
                        <x-ui.card-title class="text-base font-semibold flex items-center gap-2">
                            <span>Forensic Attempt Audit Trail</span>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-purple-500/10 text-purple-600 border border-purple-500/20 font-mono">user_attempts</span>
                        </x-ui.card-title>
                        <x-ui.card-description>Real-time telemetry recorded on web logins, lockscreen verifications, passkey challenges, and API requests</x-ui.card-description>
                    </div>
                    <div class="text-xs text-muted-foreground font-medium">
                        Total Recorded: <span class="font-bold text-foreground">{{ $attempts->count() }}</span>
                    </div>
                </div>
            </x-ui.card-header>

            <x-ui.card-content class="p-6">
                @php
                    $attemptColumns = [
                        ['key' => 'reason', 'label' => 'Reason'],
                        ['key' => 'identifier', 'label' => 'Attempted Identifier'],
                        ['key' => 'user', 'label' => 'User'],
                        ['key' => 'channel', 'label' => 'Channel'],
                        ['key' => 'client', 'label' => 'Client Platform'],
                        ['key' => 'ip', 'label' => 'IP Address'],
                        ['key' => 'location', 'label' => 'Location'],
                        ['key' => 'time', 'label' => 'Timestamp'],
                    ];
                    $attemptRows = $attempts->map(fn ($att) => [
                        'id'         => $att->id,
                        'reason'     => ucwords(str_replace('_', ' ', strtolower($att->reason ?? 'unknown'))),
                        'identifier' => $att->identifier_attempted ?: 'Unknown',
                        'user'       => $att->user ? $att->user->name . ' (#' . $att->user->id . ')' : 'Unauthenticated',
                        'channel'    => $att->channel ?: 'Web',
                        'client'     => ($att->browser ?: 'Browser') . ' on ' . ($att->os ?: 'OS') . ($att->device_type ? ' (' . $att->device_type . ')' : ''),
                        'ip'         => $att->ip_address ?? '—',
                        'location'   => $att->location_label ?: 'Local',
                        'time'       => $att->created_at?->format('Y-m-d H:i:s') ?? '—',
                    ])->values()->all();
                @endphp

                @if (count($attemptRows) === 0)
                    <div class="flex flex-col items-center justify-center gap-2 py-10 text-center text-muted-foreground">
                        <x-lucide-shield-check class="size-8 text-emerald-500/50" />
                        <p class="text-sm font-medium">No failed authentication or lockscreen attempts logged</p>
                    </div>
                @else
                    <x-ui.data-table :columns="$attemptColumns" :rows="$attemptRows" :page-size="10" :selectable="false" search-placeholder="Filter attempts..." />
                @endif
            </x-ui.card-content>
        </x-ui.card>
    </div>

    <!-- TAB 2: User Security Logs (user_security_logs) -->
    <div x-show="activeTab === 'security_logs'" x-cloak class="space-y-4">
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <div class="flex items-center justify-between">
                    <div>
                        <x-ui.card-title class="text-base font-semibold flex items-center gap-2">
                            <span>Authentication Events Trail</span>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-600 border border-blue-500/20 font-mono">user_security_logs</span>
                        </x-ui.card-title>
                        <x-ui.card-description>Chronological audit log of access events, password updates, 2FA toggles, and session revocations</x-ui.card-description>
                    </div>
                    <div class="text-xs text-muted-foreground font-medium">
                        Total Events: <span class="font-bold text-foreground">{{ $logs->count() }}</span>
                    </div>
                </div>
            </x-ui.card-header>

            <x-ui.card-content class="p-6">
                @php
                    $logColumns = [
                        ['key' => 'event', 'label' => 'Event'],
                        ['key' => 'user', 'label' => 'User / Identifier'],
                        ['key' => 'email', 'label' => 'Email'],
                        ['key' => 'ip', 'label' => 'IP Address'],
                        ['key' => 'client', 'label' => 'Client / Browser'],
                        ['key' => 'time', 'label' => 'Timestamp'],
                    ];
                    $logRows = $logs->map(fn ($log) => [
                        'id'     => $log->id,
                        'event'  => ucwords(str_replace('_', ' ', strtolower($log->event_type ?? 'event'))),
                        'user'   => $log->user->name ?? ($log->identifier_attempted ?: 'Unauthenticated'),
                        'email'  => $log->user->email ?? '—',
                        'ip'     => $log->ip_address ?? '—',
                        'client' => \Illuminate\Support\Str::limit($log->user_agent ?? '—', 60),
                        'time'   => $log->created_at?->format('Y-m-d H:i:s') ?? '—',
                    ])->values()->all();
                @endphp

                @if (count($logRows) === 0)
                    <div class="flex flex-col items-center justify-center gap-2 py-10 text-center text-muted-foreground">
                        <x-lucide-shield-check class="size-8 text-muted-foreground/50" />
                        <p class="text-sm font-medium">No security alerts or lockout events recorded</p>
                    </div>
                @else
                    <x-ui.data-table :columns="$logColumns" :rows="$logRows" :page-size="10" :selectable="false" search-placeholder="Filter events..." />
                @endif
            </x-ui.card-content>
        </x-ui.card>
    </div>
</div>
@endsection
