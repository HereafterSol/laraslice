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

            <x-ui.card-content class="p-0">
                <x-ui.table>
                    <x-ui.table-header class="bg-muted/40">
                        <x-ui.table-row>
                            <x-ui.table-head>Reason / Failure Mode</x-ui.table-head>
                            <x-ui.table-head>Attempted Identifier</x-ui.table-head>
                            <x-ui.table-head>Channel</x-ui.table-head>
                            <x-ui.table-head>Client Platform</x-ui.table-head>
                            <x-ui.table-head>IP & Location</x-ui.table-head>
                            <x-ui.table-head class="text-right">Timestamp</x-ui.table-head>
                        </x-ui.table-row>
                    </x-ui.table-header>
                    <x-ui.table-body>
                        @forelse ($attempts as $att)
                            <x-ui.table-row class="hover:bg-muted/30 transition">
                                <!-- Reason Badge -->
                                <x-ui.table-cell>
                                    @php $r = strtolower($att->reason ?? ''); @endphp
                                    @if (str_contains($r, 'lockscreen'))
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                            <x-lucide-lock class="size-3" />
                                            <span>{{ ucwords(str_replace('_', ' ', $r)) }}</span>
                                        </span>
                                    @elseif (str_contains($r, 'locked_out') || str_contains($r, 'max'))
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-rose-500/10 text-rose-600 border border-rose-500/20">
                                            <x-lucide-shield-ban class="size-3" />
                                            <span>{{ ucwords(str_replace('_', ' ', $r)) }}</span>
                                        </span>
                                    @elseif (str_contains($r, 'passkey'))
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-indigo-500/10 text-indigo-600 border border-indigo-500/20">
                                            <x-lucide-key class="size-3" />
                                            <span>{{ ucwords(str_replace('_', ' ', $r)) }}</span>
                                        </span>
                                    @elseif (str_contains($r, 'mfa'))
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-purple-500/10 text-purple-600 border border-purple-500/20">
                                            <x-lucide-shield class="size-3" />
                                            <span>{{ ucwords(str_replace('_', ' ', $r)) }}</span>
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-red-500/10 text-red-600 border border-red-500/20">
                                            <x-lucide-x-circle class="size-3" />
                                            <span>{{ ucwords(str_replace('_', ' ', $r)) }}</span>
                                        </span>
                                    @endif
                                </x-ui.table-cell>

                                <!-- Identifier / User -->
                                <x-ui.table-cell>
                                    <div class="flex flex-col">
                                        <span class="font-mono text-xs font-bold text-foreground">{{ $att->identifier_attempted ?: 'Unknown' }}</span>
                                        @if ($att->user)
                                            <span class="text-[11px] text-muted-foreground">{{ $att->user->name }} (ID: #{{ $att->user->id }})</span>
                                        @else
                                            <span class="text-[10px] text-muted-foreground">Unauthenticated Attempt</span>
                                        @endif
                                    </div>
                                </x-ui.table-cell>

                                <!-- Channel Badge -->
                                <x-ui.table-cell>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-muted text-muted-foreground border border-border">
                                        {{ $att->channel ?: 'Web' }}
                                    </span>
                                </x-ui.table-cell>

                                <!-- Client Browser & OS -->
                                <x-ui.table-cell>
                                    <div class="flex items-center gap-1.5">
                                        @if ($att->device_type === 'Mobile')
                                            <x-lucide-smartphone class="size-3.5 text-muted-foreground shrink-0" />
                                        @elseif ($att->device_type === 'Tablet')
                                            <x-lucide-tablet class="size-3.5 text-muted-foreground shrink-0" />
                                        @else
                                            <x-lucide-monitor class="size-3.5 text-muted-foreground shrink-0" />
                                        @endif
                                        <span class="text-xs font-medium text-foreground">
                                            {{ $att->browser ?: 'Browser' }} on {{ $att->os ?: 'OS' }}
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-muted-foreground truncate max-w-xs font-mono mt-0.5" title="{{ $att->user_agent }}">
                                        {{ $att->user_agent ?: '-€”' }}
                                    </div>
                                </x-ui.table-cell>

                                <!-- IP & Location -->
                                <x-ui.table-cell>
                                    <div class="flex flex-col">
                                        <span class="text-xs font-mono font-medium text-foreground">{{ $att->ip_address }}</span>
                                        <span class="text-[10px] text-muted-foreground flex items-center gap-1">
                                            <x-lucide-map-pin class="size-2.5" />
                                            <span>{{ $att->location_label ?: 'Local' }}</span>
                                        </span>
                                    </div>
                                </x-ui.table-cell>

                                <!-- Timestamp -->
                                <x-ui.table-cell class="text-right text-xs text-muted-foreground font-mono">
                                    {{ $att->created_at ? $att->created_at->format('M d, Y H:i:s') : '-€”' }}
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-cell colspan="6" class="h-32 text-center text-muted-foreground">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <x-lucide-shield-check class="size-8 text-emerald-500/50" />
                                        <p class="text-sm font-medium">No failed authentication or lockscreen attempts logged</p>
                                    </div>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
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

            <x-ui.card-content class="p-0">
                <x-ui.table>
                    <x-ui.table-header class="bg-muted/40">
                        <x-ui.table-row>
                            <x-ui.table-head>Event</x-ui.table-head>
                            <x-ui.table-head>User / Identifier</x-ui.table-head>
                            <x-ui.table-head>IP Address</x-ui.table-head>
                            <x-ui.table-head>Client / Browser</x-ui.table-head>
                            <x-ui.table-head class="text-right">Timestamp</x-ui.table-head>
                        </x-ui.table-row>
                    </x-ui.table-header>
                    <x-ui.table-body>
                        @forelse ($logs as $log)
                            <x-ui.table-row class="hover:bg-muted/30 transition">
                                <x-ui.table-cell>
                                    @php $ev = strtolower($log->event_type ?? ''); @endphp
                                    @if (str_contains($ev, 'success'))
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                            <x-lucide-check-circle-2 class="size-3" />
                                            Success
                                        </span>
                                    @elseif (str_contains($ev, 'fail'))
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-rose-500/10 text-rose-600 border border-rose-500/20">
                                            <x-lucide-x-circle class="size-3" />
                                            Failed Attempt
                                        </span>
                                    @elseif (str_contains($ev, 'lock'))
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                            <x-lucide-lock class="size-3" />
                                            Locked Out
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-500/10 text-blue-600 border border-blue-500/20">
                                            <x-lucide-shield-alert class="size-3" />
                                            {{ ucwords(str_replace('_', ' ', $ev)) }}
                                        </span>
                                    @endif
                                </x-ui.table-cell>

                                <x-ui.table-cell>
                                    <div class="flex flex-col">
                                        @if ($log->user)
                                            <span class="font-semibold text-xs text-foreground">{{ $log->user->name }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ $log->user->email }}</span>
                                        @else
                                            <span class="font-mono text-xs text-foreground">{{ $log->identifier_attempted }}</span>
                                            <span class="text-[10px] text-muted-foreground">Unauthenticated Attempt</span>
                                        @endif
                                    </div>
                                </x-ui.table-cell>

                                <x-ui.table-cell class="text-xs text-muted-foreground font-mono">
                                    {{ $log->ip_address }}
                                </x-ui.table-cell>

                                <x-ui.table-cell class="text-xs text-muted-foreground truncate max-w-xs font-mono">
                                    {{ $log->user_agent ?? '-€”' }}
                                </x-ui.table-cell>

                                <x-ui.table-cell class="text-right text-xs text-muted-foreground font-mono">
                                    {{ $log->created_at ? $log->created_at->format('M d, Y H:i:s') : '-€”' }}
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-cell colspan="5" class="h-32 text-center text-muted-foreground">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <x-lucide-shield-check class="size-8 text-muted-foreground/50" />
                                        <p class="text-sm font-medium">No security alerts or lockout events recorded</p>
                                    </div>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </x-ui.card-content>
        </x-ui.card>
    </div>
</div>
@endsection
