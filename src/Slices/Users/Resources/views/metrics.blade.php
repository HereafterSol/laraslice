@extends('layouts.app')

@section('content')
<div class="w-full max-w-7xl mx-auto space-y-6" x-data="{ 
    activeTab: (window.location.hash ? window.location.hash.substring(1) : 'sessions'),
    setTab(tab) {
        this.activeTab = tab;
        window.location.hash = tab;
    }
}">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('users.index') }}" class="hover:text-primary transition-colors">Users</a>
        <span>/</span>
        <span class="text-foreground font-medium">Access Metrics & Telemetry</span>
    </div>

    <!-- Header & Action Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <x-lucide-activity class="size-6 text-primary" />
                <span>Access Metrics & Telemetry</span>
            </h1>
            <p class="text-sm text-muted-foreground">Real-time authentication telemetry, concurrent user sessions, client device analytics, and brute-force lockouts</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button href="{{ route('users.settings') }}" as="a" variant="outline" size="sm">
                <x-lucide-settings class="size-4 mr-1.5" />
                <span>My Settings</span>
            </x-ui.button>
            <x-ui.button href="{{ route('users.index') }}" as="a" variant="secondary" size="sm">
                <x-lucide-users class="size-4 mr-1.5" />
                <span>User Directory</span>
            </x-ui.button>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle-2 class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- 6 Counter KPI Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <!-- 1. Total Logins Today -->
        <x-ui.card variant="sectioned" class="p-4 bg-card border border-border flex flex-col justify-between">
            <span class="text-xs font-medium text-muted-foreground">Logins Today</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold text-foreground">{{ $stats['logins_today'] ?? 0 }}</span>
                <x-lucide-log-in class="size-4 text-primary shrink-0 opacity-70" />
            </div>
            <span class="text-[10px] text-muted-foreground mt-1">{{ date('M d, Y') }}</span>
        </x-ui.card>

        <!-- 2. Active Sessions -->
        <x-ui.card variant="sectioned" class="p-4 bg-card border border-border flex flex-col justify-between">
            <span class="text-xs font-medium text-muted-foreground">Active Sessions</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['active_devices'] ?? 0 }}</span>
                <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
            </div>
            <span class="text-[10px] text-muted-foreground mt-1">Live workstations</span>
        </x-ui.card>

        <!-- 3. Total Users -->
        <x-ui.card variant="sectioned" class="p-4 bg-card border border-border flex flex-col justify-between">
            <span class="text-xs font-medium text-muted-foreground">Total Users</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold text-foreground">{{ $stats['total_users'] ?? 0 }}</span>
                <x-lucide-users class="size-4 text-blue-500 shrink-0 opacity-70" />
            </div>
            <span class="text-[10px] text-muted-foreground mt-1">Identities registered</span>
        </x-ui.card>

        <!-- 4. Failed Logins -->
        <x-ui.card variant="sectioned" class="p-4 bg-card border border-border flex flex-col justify-between">
            <span class="text-xs font-medium text-muted-foreground">Failed Attempts</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold {{ ($stats['failed_logins'] ?? 0) > 0 ? 'text-rose-600' : 'text-foreground' }}">
                    {{ $stats['failed_logins'] ?? 0 }}
                </span>
                <x-lucide-shield-alert class="size-4 text-rose-500 shrink-0 opacity-70" />
            </div>
            <span class="text-[10px] text-muted-foreground mt-1">Unsuccessful tries</span>
        </x-ui.card>

        <!-- 5. Locked Accounts -->
        <x-ui.card variant="sectioned" class="p-4 bg-card border border-border flex flex-col justify-between">
            <span class="text-xs font-medium text-muted-foreground">Locked Out</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold {{ ($stats['locked_accounts'] ?? 0) > 0 ? 'text-amber-600' : 'text-foreground' }}">
                    {{ $stats['locked_accounts'] ?? 0 }}
                </span>
                <x-lucide-lock class="size-4 text-amber-500 shrink-0 opacity-70" />
            </div>
            <span class="text-[10px] text-muted-foreground mt-1">Brute-force stops</span>
        </x-ui.card>

        <!-- 6. 2FA Enrolled -->
        <x-ui.card variant="sectioned" class="p-4 bg-card border border-border flex flex-col justify-between">
            <span class="text-xs font-medium text-muted-foreground">MFA Protected</span>
            <div class="flex items-baseline justify-between mt-2">
                <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['mfa_enabled'] ?? 0 }}</span>
                <x-lucide-shield-check class="size-4 text-emerald-500 shrink-0 opacity-70" />
            </div>
            <span class="text-[10px] text-muted-foreground mt-1">TOTP / Biometrics</span>
        </x-ui.card>
    </div>

    <!-- Real In-Page Dynamic Tabs -->
    <div class="flex flex-wrap items-center justify-between gap-3 p-2 rounded-xl border border-border bg-card shadow-xs">
        <div class="flex items-center gap-1.5 overflow-x-auto">
            <!-- Tab 1: Active Client Sessions -->
            <button 
                type="button" 
                @click="setTab('sessions')"
                :class="activeTab === 'sessions' ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-muted font-medium'"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs transition cursor-pointer">
                <x-lucide-activity class="size-3.5" />
                <span>Active Client Sessions</span>
                <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-mono" :class="activeTab === 'sessions' ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-muted text-muted-foreground'">
                    {{ $activeSessions->count() }}
                </span>
            </button>

            <!-- Tab 2: Registered Devices -->
            <button 
                type="button" 
                @click="setTab('devices')"
                :class="activeTab === 'devices' ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-muted font-medium'"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs transition cursor-pointer">
                <x-lucide-laptop class="size-3.5" />
                <span>Registered Devices</span>
                <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-mono" :class="activeTab === 'devices' ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-muted text-muted-foreground'">
                    {{ $devices->count() }}
                </span>
            </button>

            <!-- Tab 3: Location History & Telemetry -->
            <button 
                type="button" 
                @click="setTab('locations')"
                :class="activeTab === 'locations' ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-muted font-medium'"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs transition cursor-pointer">
                <x-lucide-map-pin class="size-3.5 text-rose-500" />
                <span>Location History</span>
                <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-mono" :class="activeTab === 'locations' ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-muted text-muted-foreground'">
                    {{ $locationRecords->count() }}
                </span>
            </button>

            <!-- Tab 4: Failed & Forensic Logs -->
            <button 
                type="button" 
                @click="setTab('logs')"
                :class="activeTab === 'logs' ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-muted font-medium'"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs transition cursor-pointer">
                <x-lucide-shield-alert class="size-3.5" />
                <span>Failed & Forensic Logs</span>
                <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-mono" :class="activeTab === 'logs' ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-muted text-muted-foreground'">
                    {{ $logs->count() }}
                </span>
            </button>
        </div>
        <div class="text-xs text-muted-foreground font-mono flex items-center gap-1.5">
            <span class="size-2 rounded-full bg-emerald-500"></span>
            Server Time: {{ date('H:i:s T') }}
        </div>
    </div>

    <!-- ==================== TAB 1: ACTIVE CLIENT SESSIONS ==================== -->
    <div x-show="activeTab === 'sessions'" x-cloak class="space-y-4">
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <div class="flex items-center justify-between">
                    <div>
                        <x-ui.card-title class="text-base font-semibold">Live Access & Client Sessions</x-ui.card-title>
                        <x-ui.card-description>Real-time device footprints, authentication state, and session tokens</x-ui.card-description>
                    </div>
                    <div class="text-xs text-muted-foreground font-medium">
                        Showing {{ $activeSessions->count() }} active connections
                    </div>
                </div>
            </x-ui.card-header>

            <x-ui.card-content class="p-0">
                <x-ui.table>
                    <x-ui.table-header class="bg-muted/40">
                        <x-ui.table-row>
                            <x-ui.table-head>User Information</x-ui.table-head>
                            <x-ui.table-head>Employment / Role</x-ui.table-head>
                            <x-ui.table-head>Device & Platform</x-ui.table-head>
                            <x-ui.table-head>Network & IP</x-ui.table-head>
                            <x-ui.table-head>Session Status</x-ui.table-head>
                            <x-ui.table-head class="text-right">Action</x-ui.table-head>
                        </x-ui.table-row>
                    </x-ui.table-header>
                    <x-ui.table-body>
                        @forelse ($activeSessions as $session)
                            @php $u = $session->user; @endphp
                            <x-ui.table-row class="hover:bg-muted/30 transition">
                                <!-- User Information -->
                                <x-ui.table-cell>
                                    <div class="flex items-center gap-3">
                                        <div class="size-9 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-xs shrink-0 border border-primary/20">
                                            {{ strtoupper(substr($u->name ?? 'U', 0, 1)) }}
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="font-semibold text-foreground text-xs">{{ $u->name ?? 'Unknown' }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ $u->email ?? '—' }}</span>
                                            @if ($u?->detail?->cnic)
                                                <span class="text-[10px] text-muted-foreground/80 font-mono">CNIC: {{ $u->detail->cnic }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </x-ui.table-cell>

                                <!-- Employment / Role -->
                                <x-ui.table-cell>
                                    <div class="flex flex-col text-xs">
                                        <span class="font-semibold text-foreground">{{ $u?->detail?->designation ?? 'Team Member' }}</span>
                                        <span class="text-[11px] text-muted-foreground">{{ $u?->detail?->department ?? 'General' }}</span>
                                        @if ($u?->detail?->employee_id)
                                            <span class="text-[10px] text-muted-foreground font-mono">ID: {{ $u->detail->employee_id }}</span>
                                        @endif
                                    </div>
                                </x-ui.table-cell>

                                <!-- Device & Platform -->
                                <x-ui.table-cell>
                                    <div class="flex items-center gap-2">
                                        @php $plat = strtolower($session->platform ?? 'web'); @endphp
                                        @if ($plat === 'android' || $plat === 'ios')
                                            <x-lucide-smartphone class="size-4 text-emerald-500 shrink-0" />
                                        @else
                                            <x-lucide-laptop class="size-4 text-blue-500 shrink-0" />
                                        @endif
                                        <div class="flex flex-col text-xs">
                                            <span class="font-medium text-foreground">{{ $session->device_name }}</span>
                                            <span class="text-[11px] text-muted-foreground font-mono">{{ $session->browser ?? 'Browser' }} on {{ $session->os ?? 'OS' }}</span>
                                        </div>
                                    </div>
                                </x-ui.table-cell>

                                <!-- Network & IP -->
                                <x-ui.table-cell>
                                    <div class="flex flex-col text-xs font-mono">
                                        <span class="text-foreground">{{ $session->ip_address }}</span>
                                        @if($session->location_label)
                                            <div class="flex items-center gap-1 text-[11px] text-muted-foreground">
                                                <x-lucide-map-pin class="size-3 text-rose-500 shrink-0" />
                                                <span>{{ $session->location_label }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </x-ui.table-cell>

                                <!-- Session Status -->
                                <x-ui.table-cell>
                                    <div class="flex flex-col text-xs">
                                        @if ($session->is_current)
                                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                                                <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                                Active Now
                                            </span>
                                        @else
                                            <span class="text-muted-foreground font-mono">
                                                {{ $session->last_active_at ? $session->last_active_at->diffForHumans() : 'Recently' }}
                                            </span>
                                        @endif
                                    </div>
                                </x-ui.table-cell>

                                <!-- Action: Revoke Device -->
                                <x-ui.table-cell class="text-right">
                                    <form action="{{ route('users.devices.destroy', $session->id) }}" method="POST" onsubmit="return confirm('Terminate and revoke this active device session?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-500/10 rounded-lg transition">
                                            <x-lucide-log-out class="size-3.5" />
                                            <span>Revoke</span>
                                        </button>
                                    </form>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-cell colspan="6" class="text-center py-10 text-muted-foreground text-sm">
                                    No active sessions found.
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </x-ui.card-content>
        </x-ui.card>
    </div>

    <!-- ==================== TAB 2: REGISTERED DEVICES ==================== -->
    <div x-show="activeTab === 'devices'" x-cloak class="space-y-4">
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <div class="flex items-center justify-between">
                    <div>
                        <x-ui.card-title class="text-base font-semibold">Registered Client Devices</x-ui.card-title>
                        <x-ui.card-description>All authorized workstations, browsers, and mobile devices linked to user accounts</x-ui.card-description>
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
                                    <div class="flex items-center gap-2 text-xs">
                                        <x-lucide-laptop class="size-4 text-primary shrink-0" />
                                        <span class="font-medium text-foreground">{{ $dev->device_name }}</span>
                                    </div>
                                </x-ui.table-cell>

                                <x-ui.table-cell>
                                    <span class="text-xs text-muted-foreground font-mono">{{ $dev->browser ?? 'Browser' }} / {{ $dev->os ?? 'OS' }}</span>
                                </x-ui.table-cell>

                                <x-ui.table-cell>
                                    <div class="flex flex-col text-xs font-mono">
                                        <span class="text-foreground">{{ $dev->ip_address }}</span>
                                        <span class="text-[11px] text-muted-foreground">{{ $dev->location_label ?? 'Local / Remote' }}</span>
                                    </div>
                                </x-ui.table-cell>

                                <x-ui.table-cell>
                                    <span class="text-xs text-muted-foreground font-mono">
                                        {{ $dev->last_active_at ? $dev->last_active_at->diffForHumans() : 'Never' }}
                                    </span>
                                </x-ui.table-cell>

                                <x-ui.table-cell class="text-right">
                                    <form action="{{ route('users.devices.destroy', $dev->id) }}" method="POST" onsubmit="return confirm('Revoke this device?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-500/10 rounded-lg transition">
                                            <x-lucide-trash-2 class="size-3.5" />
                                            <span>Revoke</span>
                                        </button>
                                    </form>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-cell colspan="6" class="text-center py-10 text-muted-foreground text-sm">
                                    No registered devices found.
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </x-ui.card-content>
        </x-ui.card>
    </div>

    <!-- ==================== TAB 3: LOCATION HISTORY & TELEMETRY ==================== -->
    <div x-show="activeTab === 'locations'" x-cloak class="space-y-4">
        <!-- Location History Header & Map Visual Card -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- Left Info Panel -->
            <x-ui.card variant="sectioned" class="border shadow-xs bg-card p-6 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="p-2 rounded-xl bg-rose-500/10 text-rose-500 border border-rose-500/20">
                            <x-lucide-map-pin class="size-5" />
                        </span>
                        <div>
                            <h3 class="text-base font-bold text-foreground">Location Telemetry</h3>
                            <p class="text-xs text-muted-foreground">GPS tracking and IP geolocation history</p>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed mt-3">
                        LaraSlice captures client workstation coordinates via HTML5 Geolocation and IP mapping during login challenges and authenticated sessions.
                    </p>
                </div>

                <div class="space-y-2.5 pt-4 mt-4 border-t border-border text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Total Location Records</span>
                        <span class="font-bold text-foreground font-mono">{{ $locationRecords->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Active GPS Workstations</span>
                        <span class="font-bold text-emerald-600 font-mono">{{ $locationRecords->whereNotNull('latitude')->count() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">Primary Sector</span>
                        <span class="font-semibold text-foreground">Islamabad / Rawalpindi</span>
                    </div>
                </div>
            </x-ui.card>

            <!-- Right Interactive Coordinates & Radar Card -->
            <x-ui.card variant="sectioned" class="lg:col-span-2 border shadow-xs bg-slate-950 text-slate-100 p-6 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute -right-16 -top-16 w-64 h-64 bg-purple-600/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none"></div>

                <div class="flex items-center justify-between z-10 mb-4">
                    <div class="flex items-center gap-2">
                        <x-lucide-radar class="size-5 text-emerald-400 animate-spin" style="animation-duration: 6s;" />
                        <span class="text-xs font-bold uppercase tracking-wider text-emerald-400">Live GPS Radar</span>
                    </div>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-900 px-2.5 py-1 rounded-full border border-slate-800">
                        Geo-Telemetry Active
                    </span>
                </div>

                <!-- Recent Coordinates Chips -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 z-10 my-2">
                    @foreach($locationRecords->whereNotNull('latitude')->take(4) as $geo)
                        <div class="p-3 rounded-xl bg-slate-900/90 border border-slate-800 text-xs flex items-center justify-between hover:border-purple-500/50 transition">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="size-2 rounded-full bg-emerald-500 shrink-0"></span>
                                <div class="truncate">
                                    <div class="font-bold text-white truncate">{{ $geo->user->name ?? 'User' }}</div>
                                    <div class="text-[11px] text-slate-400 font-mono">{{ $geo->latitude }}, {{ $geo->longitude }}</div>
                                </div>
                            </div>
                            <a href="https://www.google.com/maps?q={{ $geo->latitude }},{{ $geo->longitude }}" target="_blank" rel="noopener noreferrer" class="shrink-0 p-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white hover:bg-slate-700 transition" title="Open in Maps">
                                <x-lucide-external-link class="size-3.5" />
                            </a>
                        </div>
                    @endforeach
                </div>

                <div class="z-10 mt-3 pt-3 border-t border-slate-800/80 flex items-center justify-between text-xs text-slate-400">
                    <span class="flex items-center gap-1.5">
                        <x-lucide-crosshair class="size-3 text-purple-400" />
                        <span>High-accuracy WGS-84 coordinates</span>
                    </span>
                    <span class="font-mono text-[11px]">RFC-6238 Telemetry Parity</span>
                </div>
            </x-ui.card>
        </div>

        <!-- Full Location History Table -->
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <div class="flex items-center justify-between">
                    <div>
                        <x-ui.card-title class="text-base font-semibold">Location Audit Trail</x-ui.card-title>
                        <x-ui.card-description>Every physical location and IP checkpoint captured during user authentication</x-ui.card-description>
                    </div>
                    <div class="text-xs text-muted-foreground font-medium font-mono">
                        {{ $locationRecords->count() }} Recorded Points
                    </div>
                </div>
            </x-ui.card-header>

            <x-ui.card-content class="p-0">
                <x-ui.table>
                    <x-ui.table-header class="bg-muted/40">
                        <x-ui.table-row>
                            <x-ui.table-head>User</x-ui.table-head>
                            <x-ui.table-head>Location / Coordinates</x-ui.table-head>
                            <x-ui.table-head>IP & Telemetry</x-ui.table-head>
                            <x-ui.table-head>Source & Device</x-ui.table-head>
                            <x-ui.table-head>Timestamp</x-ui.table-head>
                            <x-ui.table-head class="text-right">Map View</x-ui.table-head>
                        </x-ui.table-row>
                    </x-ui.table-header>
                    <x-ui.table-body>
                        @forelse ($locationRecords as $loc)
                            <x-ui.table-row class="hover:bg-muted/30 transition">
                                <!-- User -->
                                <x-ui.table-cell>
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-8 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-xs shrink-0 border border-primary/20">
                                            {{ strtoupper(substr($loc->user->name ?? 'U', 0, 1)) }}
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="font-semibold text-foreground text-xs">{{ $loc->user->name ?? 'Unknown User' }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ $loc->user->email ?? '—' }}</span>
                                        </div>
                                    </div>
                                </x-ui.table-cell>

                                <!-- Location / Coordinates -->
                                <x-ui.table-cell>
                                    <div class="flex flex-col gap-1 text-xs">
                                        <div class="flex items-center gap-1.5 font-medium text-foreground">
                                            <x-lucide-map-pin class="size-3.5 text-rose-500 shrink-0" />
                                            <span>{{ $loc->location_label }}</span>
                                        </div>
                                        @if($loc->latitude && $loc->longitude)
                                            <span class="text-[11px] text-muted-foreground font-mono">
                                                Lat: {{ $loc->latitude }}, Lng: {{ $loc->longitude }}
                                            </span>
                                        @endif
                                    </div>
                                </x-ui.table-cell>

                                <!-- IP & Telemetry -->
                                <x-ui.table-cell>
                                    <span class="text-xs font-mono font-medium text-foreground bg-muted/60 px-2 py-0.5 rounded-md border border-border">
                                        {{ $loc->ip_address }}
                                    </span>
                                </x-ui.table-cell>

                                <!-- Source & Device -->
                                <x-ui.table-cell>
                                    <div class="flex flex-col text-xs">
                                        <span class="font-medium text-foreground">{{ $loc->device_name }}</span>
                                        <span class="text-[11px] text-muted-foreground">{{ $loc->source }}</span>
                                    </div>
                                </x-ui.table-cell>

                                <!-- Timestamp -->
                                <x-ui.table-cell>
                                    <div class="flex flex-col text-xs font-mono">
                                        <span class="text-foreground">
                                            {{ $loc->last_active_at ? \Carbon\Carbon::parse($loc->last_active_at)->diffForHumans() : 'Recently' }}
                                        </span>
                                        <span class="text-[10px] text-muted-foreground">
                                            {{ $loc->last_active_at ? \Carbon\Carbon::parse($loc->last_active_at)->format('Y-m-d H:i:s') : '—' }}
                                        </span>
                                    </div>
                                </x-ui.table-cell>

                                <!-- Map View -->
                                <x-ui.table-cell class="text-right">
                                    @if($loc->latitude && $loc->longitude)
                                        <a href="https://www.google.com/maps?q={{ $loc->latitude }},{{ $loc->longitude }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-semibold bg-rose-500/10 text-rose-600 hover:bg-rose-500/20 border border-rose-500/20 transition">
                                            <x-lucide-map class="size-3.5" />
                                            <span>Open Map</span>
                                        </a>
                                    @else
                                        <span class="text-xs text-muted-foreground font-mono">No GPS</span>
                                    @endif
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-cell colspan="6" class="text-center py-10 text-muted-foreground text-sm">
                                    No location telemetry captured yet.
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforelse
                    </x-ui.table-body>
                </x-ui.table>
            </x-ui.card-content>
        </x-ui.card>
    </div>

    <!-- ==================== TAB 4: FAILED & FORENSIC LOGS ==================== -->
    <div x-show="activeTab === 'logs'" x-cloak class="space-y-4">
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <div class="flex items-center justify-between">
                    <div>
                        <x-ui.card-title class="text-base font-semibold">Authentication Activity Trail</x-ui.card-title>
                        <x-ui.card-description>Chronological audit log of access attempts and security events</x-ui.card-description>
                    </div>
                    <div class="text-xs text-muted-foreground font-medium">
                        Total: {{ $logs->count() }} events
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
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-muted text-muted-foreground border border-border">
                                            <x-lucide-info class="size-3" />
                                            {{ ucwords(str_replace('_', ' ', $log->event_type)) }}
                                        </span>
                                    @endif
                                </x-ui.table-cell>

                                <x-ui.table-cell>
                                    <div class="flex items-center gap-2">
                                        <div class="size-7 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-xs shrink-0">
                                            {{ strtoupper(substr($log->user->name ?? $log->identifier_attempted ?? 'U', 0, 1)) }}
                                        </div>
                                        <div class="flex flex-col text-xs">
                                            <span class="font-medium text-foreground">{{ $log->user->name ?? $log->identifier_attempted ?? 'Unknown' }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ $log->identifier_attempted ?? $log->user->email ?? '' }}</span>
                                        </div>
                                    </div>
                                </x-ui.table-cell>

                                <x-ui.table-cell>
                                    <span class="text-xs text-muted-foreground font-mono">{{ $log->ip_address ?? '—' }}</span>
                                </x-ui.table-cell>

                                <x-ui.table-cell>
                                    <span class="text-xs text-muted-foreground truncate max-w-xs block font-mono" title="{{ $log->user_agent }}">
                                        {{ $log->user_agent ? substr($log->user_agent, 0, 45) . '...' : 'Unknown client' }}
                                    </span>
                                </x-ui.table-cell>

                                <x-ui.table-cell class="text-right">
                                    <div class="flex flex-col text-xs font-mono">
                                        <span class="text-foreground">{{ $log->created_at ? $log->created_at->diffForHumans() : '—' }}</span>
                                        <span class="text-[10px] text-muted-foreground">{{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '—' }}</span>
                                    </div>
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-cell colspan="5" class="text-center py-10 text-muted-foreground text-sm">
                                    No security logs recorded.
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