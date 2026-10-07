@extends('layouts.app')

@section('title', 'MFA Management - LaraSlice Security')

@section('content')
<div class="w-full max-w-7xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-border">
        <div>
            <div class="flex items-center gap-2">
                <span class="p-2 bg-purple-500/10 text-purple-600 dark:text-purple-400 rounded-xl border border-purple-500/20">
                    <x-lucide-shield class="size-6" />
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">MFA Management</h1>
                    <p class="text-sm text-muted-foreground">Enterprise Multi-Factor Authentication oversight, passkey orchestration, and recovery operations</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <x-ui.button href="{{ route('users.metrics') }}" as="a" variant="outline" size="sm" class="shadow-xs">
                <x-lucide-arrow-left class="size-4 mr-1.5" />
                <span>Access Metrics</span>
            </x-ui.button>
            <x-ui.button href="{{ route('users.settings') }}" as="a" variant="default" size="sm" class="bg-purple-600 hover:bg-purple-700 text-white shadow-xs">
                <x-lucide-settings class="size-4 mr-1.5" />
                <span>MFA Policy Settings</span>
            </x-ui.button>
        </div>
    </div>

    @if(session('success'))
        <div class="p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle-2 class="size-4 shrink-0 text-emerald-600" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if(session('error'))
        <div class="p-4 rounded-xl border border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-400 text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-alert-triangle class="size-4 shrink-0 text-red-600" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Top Summary KPI Cards (LaraSlice Purple & Indigo Brand Theme) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <!-- Users -->
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-content class="p-5 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Total Users</p>
                    <h3 class="text-3xl font-extrabold text-foreground mt-1">{{ $stats['totalUsers'] ?? count($users) }}</h3>
                    <p class="text-xs text-muted-foreground mt-1">Identities provisioned</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center border border-purple-500/20">
                    <x-lucide-users class="size-6" />
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <!-- Passkeys Enrolled -->
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-content class="p-5 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Passkeys Enrolled</p>
                    <h3 class="text-3xl font-extrabold text-indigo-600 dark:text-indigo-400 mt-1">{{ $stats['passkeyEnrolled'] ?? $stats['passkeyRequired'] ?? 0 }}</h3>
                    <p class="text-xs text-muted-foreground mt-1">{{ $stats['totalPasskeys'] ?? 0 }} hardware keys active</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center border border-indigo-500/20">
                    <x-lucide-key class="size-6" />
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <!-- Authenticator (TOTP) -->
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-content class="p-5 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Authenticator (TOTP)</p>
                    <h3 class="text-3xl font-extrabold text-purple-600 dark:text-purple-400 mt-1">{{ $stats['authenticatorRequired'] ?? $stats['enrolledCount'] ?? 1 }}</h3>
                    <p class="text-xs text-muted-foreground mt-1">RFC-6238 mobile apps</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center border border-purple-500/20">
                    <x-lucide-smartphone class="size-6" />
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <!-- Needs Enrollment -->
        <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
            <x-ui.card-content class="p-5 flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Needs Enrollment</p>
                    <h3 class="text-3xl font-extrabold text-amber-600 dark:text-amber-400 mt-1">{{ $stats['needsEnrollment'] ?? 0 }}</h3>
                    <p class="text-xs text-muted-foreground mt-1">Pending MFA onboarding</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center border border-amber-500/20">
                    <x-lucide-alert-triangle class="size-6" />
                </div>
            </x-ui.card-content>
        </x-ui.card>
    </div>

    <!-- Enterprise System Security Policy & Global Rules Console -->
    <x-ui.card variant="sectioned" class="border shadow-xs bg-card overflow-hidden" x-data="{ openPolicy: false }">
        <div class="p-5 border-b border-border flex items-center justify-between gap-4 bg-muted/20">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20">
                    <x-lucide-sliders-horizontal class="size-5" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-bold text-foreground">Global Security & MFA Policies</h3>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-purple-500/10 text-purple-600 border border-purple-500/20">
                            Enterprise Engine
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">Manage organization-wide MFA enforcement tiers, authentication factors, and account lockout thresholds</p>
                </div>
            </div>
            <button type="button" @click="openPolicy = !openPolicy" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-border bg-card hover:bg-muted text-foreground transition-colors shadow-xs">
                <span x-text="openPolicy ? 'Collapse Settings' : 'Configure Rules'">Configure Rules</span>
                <x-lucide-chevron-down class="size-3.5 transition-transform" x-bind:class="openPolicy ? 'rotate-180' : ''" />
            </button>
        </div>

        <div x-show="openPolicy" x-collapse>
            <form action="{{ route('users.mfa.update_policy') }}" method="POST" class="p-6 space-y-6">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-5">
                    <!-- Enforcement Scope -->
                    <div class="space-y-2" x-data="{ tier: '{{ $securityPolicies['mfa_enforcement'] ?? 'privileged_only' }}' }">
                        <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">Enforcement Tier</label>
                        <select name="mfa_enforcement" x-model="tier" class="w-full text-xs rounded-xl border border-input bg-background text-foreground py-2 px-3 focus:outline-none focus:ring-1 focus:ring-purple-500">
                            <option value="off">Disabled (Optional for all)</option>
                            <option value="optional">User Choice (Self-enrollment)</option>
                            <option value="privileged_only">Privileged Roles Only (Configurable)</option>
                            <option value="all">Mandatory For All Users (Zero-Trust)</option>
                        </select>
                        
                        <!-- Dynamic Privileged Roles Checkboxes -->
                        @php
                            $activePrivilegedRoles = \LaraSlice\Slices\Users\Services\SecurityPolicyService::getPrivilegedRoles();
                        @endphp
                        <div x-show="tier === 'privileged_only'" class="mt-2.5 p-2.5 bg-muted/40 rounded-xl border border-border/60 space-y-1.5">
                            <div class="text-[11px] font-semibold text-foreground flex items-center justify-between">
                                <span>Privileged Roles:</span>
                                <span class="text-[10px] text-muted-foreground font-normal">Require MFA</span>
                            </div>
                            <div class="space-y-1 max-h-36 overflow-y-auto pr-1">
                                @forelse($allRoles ?? [] as $role)
                                    <label class="flex items-center gap-2 text-xs text-muted-foreground hover:text-foreground cursor-pointer select-none">
                                        <input type="checkbox" name="privileged_roles[]" value="{{ $role->slug ?? $role->name }}" 
                                            {{ in_array($role->slug ?? $role->name, $activePrivilegedRoles) ? 'checked' : '' }}
                                            class="rounded border-input text-purple-600 focus:ring-purple-500 size-3.5">
                                        <span class="font-medium text-[11px]">{{ $role->name ?? $role->slug }}</span>
                                        <span class="text-[10px] text-muted-foreground/70 font-mono">({{ $role->slug ?? $role->name }})</span>
                                    </label>
                                @empty
                                    <p class="text-[10px] text-muted-foreground">No roles registered in database.</p>
                                @endforelse
                            </div>
                        </div>

                        <p x-show="tier !== 'privileged_only'" class="text-[11px] text-muted-foreground">
                            <span x-show="tier === 'off'">MFA challenges disabled across system.</span>
                            <span x-show="tier === 'optional'">Users opt-in voluntarily.</span>
                            <span x-show="tier === 'all'">100% of accounts must pass 2FA / Passkey.</span>
                        </p>
                    </div>

                    <!-- Max Failed Attempts -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">Max Failed Attempts</label>
                        <input type="number" name="max_failed_attempts" min="1" max="20" value="{{ $securityPolicies['max_failed_attempts'] ?? 5 }}" class="w-full text-xs rounded-xl border border-input bg-background text-foreground py-2 px-3 focus:outline-none focus:ring-1 focus:ring-purple-500">
                        <p class="text-[11px] text-muted-foreground">Consecutive errors before account lockout.</p>
                    </div>

                    <!-- Failed Login Lockout Duration -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">Login Freeze (Mins)</label>
                        <input type="number" name="lockout_duration_minutes" min="1" max="1440" value="{{ $securityPolicies['lockout_duration_minutes'] ?? $securityPolicies['lockout_minutes'] ?? 15 }}" class="w-full text-xs rounded-xl border border-input bg-background text-foreground py-2 px-3 focus:outline-none focus:ring-1 focus:ring-purple-500">
                        <p class="text-[11px] text-muted-foreground">Failed login freeze before retry.</p>
                    </div>

                    <!-- Inactivity Idle Screen Lock -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">Idle Auto-Lock (Mins)</label>
                        <input type="number" name="idle_lock_minutes" min="0" max="1440" value="{{ $securityPolicies['idle_lock_minutes'] ?? 15 }}" class="w-full text-xs rounded-xl border border-input bg-background text-foreground py-2 px-3 focus:outline-none focus:ring-1 focus:ring-purple-500">
                        <p class="text-[11px] text-muted-foreground">Screen lock on inactivity (0 = off).</p>
                    </div>

                    <!-- Remember Device Bypass Window -->
                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">Remember Device (Days)</label>
                        <input type="number" name="remember_device_days" min="0" max="365" value="{{ $securityPolicies['remember_device_days'] ?? 30 }}" class="w-full text-xs rounded-xl border border-input bg-background text-foreground py-2 px-3 focus:outline-none focus:ring-1 focus:ring-purple-500">
                        <p class="text-[11px] text-muted-foreground">Device bypass cookie authorization window.</p>
                    </div>
                </div>

                <!-- Factor Checkboxes -->
                <div class="space-y-3 pt-4 border-t border-border">
                    <label class="block text-xs font-bold uppercase tracking-wider text-muted-foreground">Allowed Authentication Factors</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                        <label class="flex items-center gap-2.5 p-3 rounded-xl border border-border bg-muted/20 cursor-pointer hover:bg-muted/30 transition-colors">
                            <input type="checkbox" name="allow_passkeys" value="1" {{ !empty($securityPolicies['allow_passkeys']) ? 'checked' : '' }} class="rounded border-input text-purple-600 focus:ring-0">
                            <div>
                                <strong class="block font-semibold">Passkeys / FIDO2</strong>
                                <span class="text-[11px] text-muted-foreground">Biometrics & hardware keys</span>
                            </div>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-xl border border-border bg-muted/20 cursor-pointer hover:bg-muted/30 transition-colors">
                            <input type="checkbox" name="allow_totp" value="1" {{ !empty($securityPolicies['allow_totp']) ? 'checked' : '' }} class="rounded border-input text-purple-600 focus:ring-0">
                            <div>
                                <strong class="block font-semibold">Authenticator Apps</strong>
                                <span class="text-[11px] text-muted-foreground">Google/Microsoft RFC-6238</span>
                            </div>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-xl border border-border bg-muted/20 cursor-pointer hover:bg-muted/30 transition-colors">
                            <input type="checkbox" name="allow_device_code" value="1" {{ !empty($securityPolicies['allow_device_code']) ? 'checked' : '' }} class="rounded border-input text-purple-600 focus:ring-0">
                            <div>
                                <strong class="block font-semibold">Device Pairing Codes</strong>
                                <span class="text-[11px] text-muted-foreground">DEV-XXXXXX single-use codes</span>
                            </div>
                        </label>
                        <label class="flex items-center gap-2.5 p-3 rounded-xl border border-border bg-muted/20 cursor-pointer hover:bg-muted/30 transition-colors">
                            <input type="checkbox" name="allow_recovery_codes" value="1" {{ !empty($securityPolicies['allow_recovery_codes']) ? 'checked' : '' }} class="rounded border-input text-purple-600 focus:ring-0">
                            <div>
                                <strong class="block font-semibold">Recovery Backup</strong>
                                <span class="text-[11px] text-muted-foreground">8-character emergency codes</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                    <button type="button" @click="openPolicy = false" class="px-4 py-2 text-xs font-semibold text-foreground bg-muted hover:bg-muted/80 rounded-xl transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-xl shadow-xs transition-colors flex items-center gap-1.5">
                        <x-lucide-check-circle-2 class="size-4" />
                        <span>Save Security Policies</span>
                    </button>
                </div>
            </form>
        </div>
    </x-ui.card>

    <!-- Table Container -->
    <x-ui.card variant="sectioned" class="border shadow-xs bg-card overflow-hidden">
        <!-- Filter Bar -->
        <x-ui.card-header class="p-4 border-b border-border flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="text-sm font-bold text-foreground">MFA Directory</span>
                <span class="text-xs px-2.5 py-0.5 rounded-full bg-muted text-muted-foreground font-medium">{{ count($users) }} Records</span>
            </div>

        </x-ui.card-header>

        @php
            $mfaColumns = [
                ['key' => 'code', 'label' => 'ID'],
                ['key' => 'name', 'label' => 'User'],
                ['key' => 'cnic', 'label' => 'CNIC'],
                ['key' => 'role', 'label' => 'Role'],
                ['key' => 'dept', 'label' => 'Sector'],
                ['key' => 'method', 'label' => 'MFA Method'],
                ['key' => 'enrollment', 'label' => 'Enrollment'],
                ['key' => 'passkeys', 'label' => 'Passkeys'],
                ['key' => 'totp', 'label' => 'TOTP'],
                ['key' => 'recovery', 'label' => 'Recovery Codes'],
                ['key' => 'devices', 'label' => 'Devices'],
            ];
            $mfaRows = [];
            $mfaPayloads = [];
            foreach ($users as $u) {
                $isEnrolled = $u->hasMfa();
                $formattedId = str_pad($u->id, 6, '0', STR_PAD_LEFT);
                $roleName = $u->roles->first()?->name ?? 'User';
                $dept = $u->detail?->department ?? 'General';

                $activePasskeys = $u->passkeys ? $u->passkeys->whereNull('revoked_at') : collect();
                $passkeysCount = $activePasskeys->count();
                $hasTotp = in_array($u->mfa_channel, ['totp', 'both']);

                if ($passkeysCount > 0 && $hasTotp) {
                    $methodLabel = 'Passkey + Authenticator';
                } elseif ($passkeysCount > 0 || $u->mfa_channel === 'webauthn') {
                    $methodLabel = 'Passkey (FIDO2)';
                } elseif ($hasTotp) {
                    $methodLabel = 'Authenticator App';
                } else {
                    $methodLabel = 'Password Only';
                }

                $recCodesCount = $u->recoveryCodes->whereNull('used_at')->count();
                if ($recCodesCount === 0 && !empty($u->two_factor_recovery_codes)) {
                    $recCodesCount = is_array($u->two_factor_recovery_codes) ? count($u->two_factor_recovery_codes) : ($isEnrolled ? 8 : 0);
                }
                $devicesCount = $u->devices?->count() ?? 0;

                $mfaRows[] = [
                    'id'         => $u->id,
                    'code'       => $formattedId,
                    'name'       => $u->name,
                    'cnic'       => $u->detail?->cnic ?? 'N/A',
                    'role'       => $roleName,
                    'dept'       => $dept,
                    'method'     => $methodLabel,
                    'enrollment' => $isEnrolled ? 'Enrolled' : 'Needs Enrollment',
                    'passkeys'   => $passkeysCount,
                    'totp'       => $hasTotp ? 1 : 0,
                    'recovery'   => $recCodesCount,
                    'devices'    => $devicesCount,
                ];

                // Serialized data for the recovery console modal (openRecoveryConsole reads #mfa-data-{id})
                $mfaPayloads[$u->id] = [
                    'id' => $u->id,
                    'formattedId' => $formattedId,
                    'name' => $u->name,
                    'email' => $u->email,
                    'cnic' => $u->detail?->cnic ?? 'N/A',
                    'role' => $roleName,
                    'dept' => $dept,
                    'enrolled' => $isEnrolled,
                    'methodLabel' => $methodLabel,
                    'activeSince' => $u->mfa_confirmed_at ? $u->mfa_confirmed_at->format('d-m-Y h:i A') : ($isEnrolled ? 'Enrolled' : 'Not Enrolled'),
                    'passkeys' => $passkeysCount,
                    'hasTotp' => $hasTotp,
                    'recoveryCodes' => $recCodesCount,
                    'devices' => $devicesCount,
                    'pending' => 0,
                    'passkeysList' => $activePasskeys->map(fn($pk) => [
                        'id' => $pk->id,
                        'name' => $pk->label ?: $pk->name ?: 'Hardware Key / Biometrics',
                        'credential_id' => substr($pk->credential_id, 0, 16) . '...',
                        'created_at' => $pk->created_at ? $pk->created_at->format('d M Y, h:i A') : 'Recently',
                        'last_used_at' => $pk->last_used_at ? $pk->last_used_at->diffForHumans() : 'Never'
                    ])->values(),
                    'devicesList' => ($u->devices ?? collect())->map(fn($d) => [
                        'id' => $d->id,
                        'device_name' => $d->device_name,
                        'platform' => $d->platform ?? 'Desktop',
                        'browser' => $d->browser ?? 'Browser',
                        'ip_address' => $d->ip_address ?? '127.0.0.1',
                        'last_active_at' => $d->last_active_at ? $d->last_active_at->diffForHumans() : 'Active Now'
                    ])->values(),
                    'logsList' => ($u->securityLogs ?? collect())->sortByDesc('created_at')->take(15)->values()->map(fn($l) => [
                        'event' => $l->event,
                        'severity' => $l->severity ?? 'info',
                        'description' => $l->description,
                        'ip_address' => $l->ip_address,
                        'created_at' => $l->created_at ? $l->created_at->format('M d, H:i') : ''
                    ])->values(),
                ];
            }
        @endphp

        @foreach ($mfaPayloads as $payloadId => $payload)
            <script type="application/json" id="mfa-data-{{ $payloadId }}">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
        @endforeach

        <x-ui.card-content class="p-6">
            @if (count($mfaRows) === 0)
                <p class="py-8 text-center text-sm text-muted-foreground">No identities available in MFA register.</p>
            @else
                <x-ui.data-table :columns="$mfaColumns" :rows="$mfaRows" :page-size="10" :selectable="false" search-placeholder="Search user, CNIC, method or status..." sticky-actions>
                    <x-slot:actions>
                        <x-ui.button size="sm" class="bg-purple-600 text-white hover:bg-purple-700" @click="openRecoveryConsole(item.r.id)">
                            <x-lucide-sliders class="size-4" /> Manage
                        </x-ui.button>
                    </x-slot:actions>
                </x-ui.data-table>
            @endif
        </x-ui.card-content>
    </x-ui.card>
</div>

<!-- MFA Recovery Console Modal (LaraSlice Purple Brand Theme + Working Tabs) -->
<div id="mfaModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-950/70 backdrop-blur-xs transition-opacity flex items-center justify-center p-4">
    <div class="relative bg-card rounded-2xl max-w-3xl w-full border border-border shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        
        <!-- Modal Top Bar (LaraSlice Purple Gradient) -->
        <div class="bg-gradient-to-r from-purple-700 via-purple-600 to-indigo-600 px-6 py-4 flex items-center justify-between text-white">
            <div class="flex items-center gap-2.5">
                <x-lucide-shield-alert class="size-5 text-white" />
                <h3 id="modalTitle" class="font-bold text-base tracking-wide">MFA Recovery Console</h3>
            </div>
            <button type="button" onclick="closeRecoveryConsole()" class="text-white/80 hover:text-white p-1 rounded-lg hover:bg-white/10 transition-colors">
                <x-lucide-x class="size-5" />
            </button>
        </div>

        <div class="p-6 space-y-6 max-h-[85vh] overflow-y-auto">
            <!-- User Summary Card -->
            <div class="bg-muted/30 rounded-xl p-4 border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div id="modalAvatar" class="w-14 h-14 rounded-full bg-purple-500/10 text-purple-600 dark:text-purple-400 font-bold text-lg flex items-center justify-center shrink-0 border border-purple-500/20">
                        AD
                    </div>
                    <div>
                        <h4 id="modalUserName" class="text-lg font-bold text-foreground">Administrator</h4>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground mt-0.5">
                            <span>CNIC: <strong id="modalCnic" class="text-foreground font-mono">32301-8275113-7</strong></span>
                            <span>Email: <strong id="modalEmail" class="text-foreground">admin@laraslice.com</strong></span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground mt-0.5">
                            <span>Role: <strong id="modalRole" class="text-foreground">Super Admin</strong></span>
                            <span>Sector: <strong id="modalDept" class="text-foreground">MIS Directorate</strong></span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-1">MFA confirmed: <span id="modalActiveSince" class="font-medium text-foreground">Active</span></p>
                    </div>
                </div>
                <div class="flex sm:flex-col items-end justify-between gap-2 shrink-0">
                    <span id="modalIdBadge" class="px-3 py-1 font-mono text-xs font-bold bg-purple-500/10 text-purple-600 dark:text-purple-400 rounded-lg border border-purple-500/20">
                        000001
                    </span>
                    <span id="modalEnrollmentBadge" class="px-2.5 py-0.5 text-xs font-semibold bg-emerald-500/10 text-emerald-600 rounded-full border border-emerald-500/20">
                        Enrolled
                    </span>
                </div>
            </div>

            <!-- Top Stat Tiles -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div class="bg-card p-3 rounded-xl border border-border shadow-xs">
                    <p id="modalStatPasskeys" class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">0</p>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mt-0.5">Passkeys</p>
                </div>
                <div class="bg-card p-3 rounded-xl border border-border shadow-xs">
                    <p id="modalStatRecovery" class="text-2xl font-bold text-purple-600 dark:text-purple-400">0</p>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mt-0.5">Recovery Codes</p>
                </div>
                <div class="bg-card p-3 rounded-xl border border-border shadow-xs">
                    <p id="modalStatDevices" class="text-2xl font-bold text-foreground">0</p>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mt-0.5">Remembered Devices</p>
                </div>
                <div class="bg-card p-3 rounded-xl border border-border shadow-xs">
                    <p id="modalStatPending" class="text-2xl font-bold text-foreground">0</p>
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mt-0.5">Pending Sessions</p>
                </div>
            </div>

            <!-- Recovery Actions Title (5 Standard Enterprise Actions) -->
            <div>
                <h5 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-3">Enterprise Recovery Actions</h5>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Action 1: Issue Device Enrollment Code -->
                    <div class="bg-card p-4 rounded-xl border border-purple-500/20 hover:border-purple-500/40 transition-colors flex flex-col justify-between shadow-xs">
                        <div>
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="p-1.5 bg-purple-500/10 text-purple-600 rounded-lg">
                                    <x-lucide-qr-code class="size-4" />
                                </span>
                                <h6 class="text-sm font-bold text-foreground">Issue Device Enrollment Code</h6>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                Generates a short-lived single-use code (<strong class="font-mono text-foreground">DEV-XXXXXX</strong>) so this official can register a passkey on another laptop or phone after signing in with their password.
                            </p>
                        </div>
                        <form id="formIssueDeviceCode" method="POST" class="mt-4">
                            @csrf
                            <button type="submit" class="w-full sm:w-auto px-4 py-2 text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-lg shadow-xs transition-colors">
                                Generate Code
                            </button>
                        </form>
                    </div>

                    <!-- Action 2: Reset MFA Enrollment -->
                    <div class="bg-card p-4 rounded-xl border border-red-500/20 hover:border-red-500/40 transition-colors flex flex-col justify-between shadow-xs">
                        <div>
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="p-1.5 bg-red-500/10 text-red-600 rounded-lg">
                                    <x-lucide-rotate-ccw class="size-4" />
                                </span>
                                <h6 class="text-sm font-bold text-foreground">Reset MFA Enrollment</h6>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                Complete identity reset for stuck users. Purges passkeys, authenticator secrets, recovery codes, and remembered devices. User re-enrolls on next login.
                            </p>
                        </div>
                        <div class="mt-4">
                            <button type="button" onclick="openResetConfirmModal()" class="w-full sm:w-auto px-4 py-2 text-xs font-bold text-white bg-red-600 hover:bg-red-700 rounded-lg shadow-xs transition-colors">
                                Reset Enrollment
                            </button>
                        </div>
                    </div>

                    <!-- Action 3: Reset Authenticator App -->
                    <div class="bg-card p-4 rounded-xl border border-amber-500/20 hover:border-amber-500/40 transition-colors flex flex-col justify-between shadow-xs">
                        <div>
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="p-1.5 bg-amber-500/10 text-amber-600 rounded-lg">
                                    <x-lucide-smartphone class="size-4" />
                                </span>
                                <h6 class="text-sm font-bold text-foreground">Reset Authenticator App</h6>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                Revokes TOTP secret only. Use when the official changed phones or lost access to their Google or Microsoft Authenticator app.
                            </p>
                        </div>
                        <form id="formResetTotp" method="POST" class="mt-4">
                            @csrf
                            <button type="submit" onclick="return confirm('Revoke authenticator app secret? User must scan a new QR code.')" class="w-full sm:w-auto px-4 py-2 text-xs font-bold text-white bg-amber-600 hover:bg-amber-700 rounded-lg shadow-xs transition-colors">
                                Revoke TOTP Secret
                            </button>
                        </form>
                    </div>

                    <!-- Action 4: Revoke Remembered Devices -->
                    <div class="bg-card p-4 rounded-xl border border-border hover:border-border/80 transition-colors flex flex-col justify-between shadow-xs">
                        <div>
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="p-1.5 bg-muted text-foreground rounded-lg">
                                    <x-lucide-laptop class="size-4" />
                                </span>
                                <h6 class="text-sm font-bold text-foreground">Revoke Remembered Devices</h6>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                Clears trusted-device cookies & bypass tokens so MFA challenge is prompted again on all browsers and mobile apps.
                            </p>
                        </div>
                        <form id="formRevokeDevices" method="POST" class="mt-4">
                            @csrf
                            <button type="submit" onclick="return confirm('Revoke all trusted device bypass tokens for this user?')" class="w-full sm:w-auto px-4 py-2 text-xs font-bold text-foreground bg-muted hover:bg-muted/80 rounded-lg shadow-xs transition-colors">
                                Revoke All Devices
                            </button>
                        </form>
                    </div>

                    <!-- Action 5: Clear Pending Sessions -->
                    <div class="bg-card p-4 rounded-xl border border-indigo-500/20 hover:border-indigo-500/40 transition-colors flex flex-col justify-between shadow-xs sm:col-span-2">
                        <div>
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="p-1.5 bg-indigo-500/10 text-indigo-600 rounded-lg">
                                    <x-lucide-lock class="size-4" />
                                </span>
                                <h6 class="text-sm font-bold text-foreground">Clear Pending Sessions</h6>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                Terminates stuck or hanging MFA challenge verification states if an official is locked out midway through 2FA.
                            </p>
                        </div>
                        <form id="formClearPending" method="POST" class="mt-4">
                            @csrf
                            <button type="submit" onclick="return confirm('Clear pending MFA challenges for this user?')" class="w-full sm:w-auto px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-xs transition-colors">
                                Terminate Pending Sessions
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Working Tabs: Credentials, Sessions, Audit Trail -->
            <div class="pt-4 border-t border-border">
                <div class="flex items-center gap-6 text-xs font-medium text-muted-foreground border-b border-border">
                    <button type="button" id="tabBtnCredentials" onclick="switchModalTab('credentials')" class="tab-btn text-purple-600 font-bold border-b-2 border-purple-600 pb-2 -mb-[1px] flex items-center gap-1.5 transition-colors">
                        <x-lucide-key class="size-3.5" /> Credentials
                    </button>
                    <button type="button" id="tabBtnSessions" onclick="switchModalTab('sessions')" class="tab-btn hover:text-foreground pb-2 -mb-[1px] flex items-center gap-1.5 transition-colors">
                        <x-lucide-clock class="size-3.5" /> Remembered Devices
                    </button>
                    <button type="button" id="tabBtnAudit" onclick="switchModalTab('audit')" class="tab-btn hover:text-foreground pb-2 -mb-[1px] flex items-center gap-1.5 transition-colors">
                        <x-lucide-file-text class="size-3.5" /> Security Audit Log
                    </button>
                </div>

                <!-- Tab Panels -->
                <div class="mt-4">
                    <!-- Panel 1: Credentials -->
                    <div id="paneCredentials" class="tab-pane space-y-4">
                        <!-- Authenticator TOTP -->
                        <div class="p-3.5 rounded-xl border border-border bg-muted/20">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="p-2 rounded-lg bg-purple-500/10 text-purple-600"><x-lucide-smartphone class="size-4" /></div>
                                    <div>
                                        <p class="text-xs font-bold text-foreground">Authenticator App (RFC-6238 TOTP)</p>
                                        <p class="text-[11px] text-muted-foreground">Standard 30-second rotating 6-digit codes (Google / MS Authenticator)</p>
                                    </div>
                                </div>
                                <span id="paneTotpStatus" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-500/10 text-emerald-600">Active</span>
                            </div>
                        </div>

                        <!-- FIDO2 / WebAuthn Passkeys -->
                        <div class="p-3.5 rounded-xl border border-indigo-500/20 bg-indigo-500/5 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600"><x-lucide-key class="size-4" /></div>
                                    <div>
                                        <p class="text-xs font-bold text-foreground">FIDO2 / WebAuthn Passkeys</p>
                                        <p class="text-[11px] text-muted-foreground">Biometric hardware security keys (Windows Hello, Touch ID, YubiKey)</p>
                                    </div>
                                </div>
                                <span id="panePasskeyStatus" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-indigo-500/10 text-indigo-600">0 Registered</span>
                            </div>

                            <!-- Dynamic List of Passkeys -->
                            <div id="modalPasskeysList" class="space-y-2 pt-1">
                                <!-- Populated dynamically via JS -->
                            </div>
                        </div>

                        <!-- Recovery Codes -->
                        <div class="p-3.5 rounded-xl border border-border bg-muted/20">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600"><x-lucide-shield-alert class="size-4" /></div>
                                    <div>
                                        <p class="text-xs font-bold text-foreground">Emergency Recovery Codes</p>
                                        <p class="text-[11px] text-muted-foreground">Single-use fallback codes if phone or security key is lost</p>
                                    </div>
                                </div>
                                <span id="paneRecoveryCodesPill" class="px-2 py-0.5 rounded text-[11px] font-semibold bg-purple-500/15 text-purple-700 dark:text-purple-300">8 Remaining</span>
                            </div>
                        </div>
                    </div>

                    <!-- Panel 2: Sessions -->
                    <div id="paneSessions" class="tab-pane hidden space-y-3">
                        <div id="modalSessionsList" class="space-y-2">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>

                    <!-- Panel 3: Audit Trail -->
                    <div id="paneAudit" class="tab-pane hidden space-y-2">
                        <div id="modalAuditList" class="space-y-2">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal: Reset MFA Enrollment (Enterprise Enterprise Standard) -->
<div id="resetConfirmModal" class="fixed inset-0 z-[60] hidden overflow-y-auto bg-slate-950/80 backdrop-blur-xs transition-opacity flex items-center justify-center p-4">
    <div class="relative bg-card rounded-2xl max-w-md w-full border border-border shadow-2xl p-6 text-center animate-in fade-in zoom-in-95 duration-150">
        <!-- Yellow Warning Circle -->
        <div class="w-16 h-16 rounded-full border-4 border-amber-500 text-amber-500 font-bold text-3xl flex items-center justify-center mx-auto mb-4">
            !
        </div>

        <h3 class="text-xl font-bold text-foreground tracking-tight">Reset MFA enrollment?</h3>
        <p class="text-xs text-muted-foreground mt-1">You are about to perform a full MFA reset for this user.</p>

        <!-- Bullet List -->
        <ul class="text-left text-xs text-muted-foreground space-y-1.5 my-5 bg-muted/30 p-3.5 rounded-xl border border-border">
            <li class="flex items-center gap-2">&bull; All passkeys and hardware keys will be revoked</li>
            <li class="flex items-center gap-2">&bull; Authenticator app enrollment will be cleared</li>
            <li class="flex items-center gap-2">&bull; Unused recovery codes will be deleted</li>
            <li class="flex items-center gap-2">&bull; Remembered devices will be removed</li>
            <li class="flex items-center gap-2">&bull; Pending MFA challenge sessions will be closed</li>
        </ul>

        <p class="text-[11px] text-muted-foreground leading-relaxed mb-6">
            <strong>Result:</strong> The user must enroll MFA again on next login. Use this for users stuck on the wrong passkey or device prompt.
        </p>

        <form id="formResetEnrollment" method="POST" class="flex items-center justify-center gap-3">
            @csrf
            <button type="button" onclick="closeResetConfirmModal()" class="px-5 py-2 text-xs font-semibold text-foreground bg-muted hover:bg-muted/80 rounded-xl transition-colors">
                Cancel
            </button>
            <button type="submit" class="px-5 py-2 text-xs font-bold text-white bg-red-600 hover:bg-red-700 rounded-xl shadow-xs transition-colors">
                Yes, reset MFA
            </button>
        </form>
    </div>
</div>

@if(session('device_code_modal'))
<!-- Issued Device Code Modal -->
<div id="deviceCodeResultModal" class="fixed inset-0 z-[70] overflow-y-auto bg-slate-950/80 backdrop-blur-xs transition-opacity flex items-center justify-center p-4">
    <div class="relative bg-card rounded-2xl max-w-sm w-full border border-border shadow-2xl p-6 text-center animate-in fade-in zoom-in-95 duration-150">
        <div class="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center mx-auto mb-3">
            <x-lucide-qr-code class="size-6" />
        </div>
        <h4 class="text-base font-bold text-foreground">Device Enrollment Code</h4>
        <p class="text-xs text-muted-foreground mt-0.5">Issued for {{ session('device_code_modal.user_name') }}</p>

        <div class="my-5 p-4 bg-muted/40 rounded-xl border border-border font-mono text-2xl font-extrabold tracking-widest text-purple-600 select-all">
            {{ session('device_code_modal.code') }}
        </div>

        <p class="text-[11px] text-muted-foreground mb-5">
            Valid for <strong>{{ session('device_code_modal.expires_in') }}</strong>. Provide this code to the official to register their new device.
        </p>

        <button type="button" onclick="document.getElementById('deviceCodeResultModal').remove()" class="w-full px-4 py-2 text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-xl transition-colors shadow-xs">
            Done
        </button>
    </div>
</div>
@endif

<script>
let currentModalData = null;

// Device names, browsers and log text come from user agents and user input: escape before innerHTML
function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function openRecoveryConsole(userId) {
    const rawEl = document.getElementById('mfa-data-' + userId);
    if (!rawEl) return;
    const data = JSON.parse(rawEl.textContent);
    currentModalData = data;

    // Header & User Summary
    document.getElementById('modalTitle').textContent = data.name + ' - MFA Recovery Console';
    document.getElementById('modalUserName').textContent = data.name;
    document.getElementById('modalAvatar').textContent = data.name.substring(0, 2).toUpperCase();
    document.getElementById('modalEmail').textContent = data.email;
    document.getElementById('modalCnic').textContent = data.cnic;
    document.getElementById('modalRole').textContent = data.role;
    document.getElementById('modalDept').textContent = data.dept;
    document.getElementById('modalIdBadge').textContent = data.formattedId;
    document.getElementById('modalActiveSince').textContent = data.activeSince;

    // Top Stat Tiles
    document.getElementById('modalStatPasskeys').textContent = data.passkeys;
    document.getElementById('modalStatRecovery').textContent = data.recoveryCodes;
    document.getElementById('modalStatDevices').textContent = data.devices;
    document.getElementById('modalStatPending').textContent = data.pending;

    // Tab 1: Credentials
    const enBadge = document.getElementById('modalEnrollmentBadge');
    const totpPill = document.getElementById('paneTotpStatus');
    const passkeyPill = document.getElementById('panePasskeyStatus');
    const recoveryPill = document.getElementById('paneRecoveryCodesPill');

    if (data.enrolled) {
        enBadge.textContent = 'Enrolled (' + data.methodLabel + ')';
        enBadge.className = 'px-2.5 py-0.5 text-xs font-semibold bg-emerald-500/10 text-emerald-600 rounded-full border border-emerald-500/20';
    } else {
        enBadge.textContent = 'Needs Enrollment';
        enBadge.className = 'px-2.5 py-0.5 text-xs font-semibold bg-red-500/10 text-red-600 rounded-full border border-red-500/20';
    }

    if (data.hasTotp) {
        totpPill.textContent = 'Active & Enrolled';
        totpPill.className = 'px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20';
    } else {
        totpPill.textContent = 'Not Configured';
        totpPill.className = 'px-2 py-0.5 rounded text-[11px] font-semibold bg-muted text-muted-foreground';
    }

    if (data.passkeys > 0) {
        passkeyPill.textContent = data.passkeys + ' Registered';
        passkeyPill.className = 'px-2 py-0.5 rounded text-[11px] font-semibold bg-indigo-500/10 text-indigo-600 border border-indigo-500/20';
    } else {
        passkeyPill.textContent = '0 Registered';
        passkeyPill.className = 'px-2 py-0.5 rounded text-[11px] font-semibold bg-muted text-muted-foreground';
    }

    if (data.recoveryCodes > 0) {
        recoveryPill.textContent = data.recoveryCodes + ' Remaining';
        recoveryPill.className = 'px-2 py-0.5 rounded text-[11px] font-semibold bg-purple-500/15 text-purple-700 dark:text-purple-300 border border-purple-500/20';
    } else {
        recoveryPill.textContent = 'None Active';
        recoveryPill.className = 'px-2 py-0.5 rounded text-[11px] font-semibold bg-muted text-muted-foreground';
    }

    // Populate Registered Passkeys list in Credentials tab
    const pkContainer = document.getElementById('modalPasskeysList');
    if (data.passkeysList && data.passkeysList.length > 0) {
        pkContainer.innerHTML = data.passkeysList.map(pk => `
            <div class="p-3 rounded-xl border border-indigo-500/20 bg-background flex items-center justify-between text-xs">
                <div class="flex items-center gap-3">
                    <span class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                    </span>
                    <div>
                        <p class="font-bold text-foreground">${esc(pk.name)}</p>
                        <p class="text-muted-foreground font-mono text-[11px]">ID: ${esc(pk.credential_id)} &bull; Registered: ${esc(pk.created_at)}</p>
                        <p class="text-[10px] text-muted-foreground">Last used: ${esc(pk.last_used_at)}</p>
                    </div>
                </div>
                <form method="POST" action="{{ url('/admin/users/settings/passkey') }}/${esc(pk.id)}">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" onclick="return confirm('Revoke this passkey credential? The user will no longer be able to use this hardware/biometric key to sign in.')" class="px-2.5 py-1 text-[11px] font-semibold text-red-600 hover:text-white hover:bg-red-600 border border-red-500/30 rounded-lg transition-colors">
                        Revoke
                    </button>
                </form>
            </div>
        `).join('');
    } else {
        pkContainer.innerHTML = '<p class="text-xs text-muted-foreground py-2.5 text-center italic bg-background/50 rounded-xl border border-dashed border-border">No passkeys or biometric security keys enrolled for this user.</p>';
    }

    // Populate Sessions List in Sessions tab
    const sessContainer = document.getElementById('modalSessionsList');
    if (data.devicesList && data.devicesList.length > 0) {
        sessContainer.innerHTML = data.devicesList.map(dev => `
            <div class="p-3.5 rounded-xl border border-border bg-card flex items-center justify-between text-xs">
                <div class="flex items-center gap-3">
                    <span class="p-2.5 rounded-xl bg-muted text-foreground">
                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    </span>
                    <div>
                        <p class="font-bold text-foreground">${esc(dev.device_name)} &bull; ${esc(dev.browser)}</p>
                        <p class="text-muted-foreground font-mono text-[11px]">${esc(dev.ip_address)} &bull; ${esc(dev.platform)} &bull; Active: ${esc(dev.last_active_at)}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">Authorized Bypass</span>
                    <form method="POST" action="{{ url('/admin/users/devices') }}/${esc(dev.id)}">
                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit" onclick="return confirm('Revoke this device session?')" class="p-1.5 text-muted-foreground hover:text-red-600 transition-colors" title="Revoke Device Session">
                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    </form>
                </div>
            </div>
        `).join('');
    } else {
        sessContainer.innerHTML = '<p class="text-xs text-muted-foreground py-6 text-center bg-muted/20 rounded-xl border border-dashed border-border">No remembered device bypass sessions recorded.</p>';
    }

    // Populate Audit Trail in Audit tab
    const auditContainer = document.getElementById('modalAuditList');
    if (data.logsList && data.logsList.length > 0) {
        auditContainer.innerHTML = data.logsList.map(log => {
            let badgeClass = 'bg-slate-500/10 text-slate-600 border-slate-500/20';
            if (log.severity === 'success') badgeClass = 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20';
            else if (log.severity === 'danger' || log.severity === 'error') badgeClass = 'bg-red-500/10 text-red-600 border-red-500/20';
            else if (log.severity === 'warning') badgeClass = 'bg-amber-500/10 text-amber-600 border-amber-500/20';
            else if (log.severity === 'info') badgeClass = 'bg-purple-500/10 text-purple-600 border-purple-500/20';

            return `
                <div class="p-3 rounded-xl border border-border bg-card flex items-start justify-between text-xs gap-3">
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border ${badgeClass}">${esc(log.event)}</span>
                            <span class="text-muted-foreground font-mono text-[11px]">${esc(log.ip_address || '127.0.0.1')}</span>
                        </div>
                        <p class="font-medium text-foreground pt-0.5">${esc(log.description)}</p>
                    </div>
                    <div class="text-right text-muted-foreground font-mono text-[11px] shrink-0">
                        <p>${esc(log.created_at)}</p>
                    </div>
                </div>
            `;
        }).join('');
    } else {
        auditContainer.innerHTML = '<p class="text-xs text-muted-foreground py-6 text-center bg-muted/20 rounded-xl border border-dashed border-border">No security audit entries recorded yet.</p>';
    }

    // Wire all 5 modal recovery action forms
    const baseUrl = '{{ url("/admin/users/mfa") }}/' + data.id;
    document.getElementById('formIssueDeviceCode').action = baseUrl + '/issue-device-code';
    document.getElementById('formResetEnrollment').action = baseUrl + '/reset-enrollment';
    document.getElementById('formResetTotp').action = baseUrl + '/reset-totp';
    document.getElementById('formRevokeDevices').action = baseUrl + '/revoke-devices';
    document.getElementById('formClearPending').action = baseUrl + '/clear-pending';

    // Reset tab to Credentials
    switchModalTab('credentials');

    document.getElementById('mfaModal').classList.remove('hidden');
}

function closeRecoveryConsole() {
    document.getElementById('mfaModal').classList.add('hidden');
}

function openResetConfirmModal() {
    document.getElementById('resetConfirmModal').classList.remove('hidden');
}

function closeResetConfirmModal() {
    document.getElementById('resetConfirmModal').classList.add('hidden');
}

function switchModalTab(tab) {
    const tabs = ['credentials', 'sessions', 'audit'];
    tabs.forEach(t => {
        const btn = document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1));
        const pane = document.getElementById('pane' + t.charAt(0).toUpperCase() + t.slice(1));
        if (t === tab) {
            btn.className = 'tab-btn text-purple-600 font-bold border-b-2 border-purple-600 pb-2 -mb-[1px] flex items-center gap-1.5 transition-colors';
            pane.classList.remove('hidden');
        } else {
            btn.className = 'tab-btn hover:text-foreground pb-2 -mb-[1px] flex items-center gap-1.5 transition-colors text-muted-foreground';
            pane.classList.add('hidden');
        }
    });
}


</script>
@endsection