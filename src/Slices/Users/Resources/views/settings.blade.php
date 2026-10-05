@extends('layouts.app')

@section('title', 'Account & Security Settings')

@section('content')
<div class="w-full max-w-6xl mx-auto space-y-6" x-data="{ 
    activeTab: window.location.hash ? window.location.hash.substring(1) : '{{ session('active_tab', $defaultTab ?? 'profile') }}',
    showRegenModal: false,
    showDeviceCodeModal: {{ session('generated_device_code') ? 'true' : 'false' }},
    setTab(tab) {
        this.activeTab = tab;
        window.location.hash = tab;
    }
}">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        @if(auth()->user() && (auth()->user()->hasRole('super-admin') || (method_exists(auth()->user(), 'hasPermission') && auth()->user()->hasPermission('users.view'))))
        <a href="{{ route('users.index') }}" class="hover:text-primary transition-colors">Users</a>
        <span>/</span>
        @endif
        <span class="text-foreground font-medium">My Profile & Settings</span>
    </div>

    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <x-lucide-settings class="size-6 text-primary" />
                <span>Account & Security Settings</span>
            </h1>
            <p class="text-sm text-muted-foreground">Manage your identity credentials, profile attributes, multi-factor security, and authorized devices</p>
        </div>
        <div class="flex items-center gap-2">
            @if(auth()->user() && (auth()->user()->hasRole('super-admin') || (method_exists(auth()->user(), 'hasPermission') && auth()->user()->hasPermission('users.metrics'))))
            <x-ui.button href="{{ route('users.metrics') }}" as="a" variant="outline" size="sm">
                <x-lucide-activity class="size-4 mr-1.5" />
                <span>Access Metrics</span>
            </x-ui.button>
            @endif
            <x-ui.button href="{{ route('lockscreen') }}" as="a" variant="outline" size="sm">
                <x-lucide-lock class="size-4 mr-1.5" />
                <span>Lock Screen</span>
            </x-ui.button>
        </div>
    </div>

    <!-- Quick Stats Summary (Enterprise match) -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-card border border-border shadow-xs flex items-center gap-3">
            <div class="size-10 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center shrink-0">
                <x-lucide-shield-check class="size-5" />
            </div>
            <div>
                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">MFA Status</span>
                <p class="text-sm font-bold {{ $user->hasMfa() ? 'text-emerald-600' : 'text-amber-600' }}">
                    {{ $user->hasMfa() ? 'Active (TOTP)' : 'Disabled' }}
                </p>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-card border border-border shadow-xs flex items-center gap-3">
            <div class="size-10 rounded-xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center shrink-0">
                <x-lucide-key class="size-5" />
            </div>
            <div>
                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Recovery Codes</span>
                <p class="text-sm font-bold text-foreground">
                    {{ $user->recoveryCodes->whereNull('used_at')->count() }} Remaining
                </p>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-card border border-border shadow-xs flex items-center gap-3">
            <div class="size-10 rounded-xl bg-blue-500/10 text-blue-600 flex items-center justify-center shrink-0">
                <x-lucide-smartphone class="size-5" />
            </div>
            <div>
                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Active Devices</span>
                <p class="text-sm font-bold text-foreground">
                    {{ $user->devices->count() }} Registered
                </p>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-card border border-border shadow-xs flex items-center gap-3">
            <div class="size-10 rounded-xl bg-amber-500/10 text-amber-600 flex items-center justify-center shrink-0">
                <x-lucide-history class="size-5" />
            </div>
            <div>
                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Security Events</span>
                <p class="text-sm font-bold text-foreground">
                    {{ $securityLogs->count() }} Audited
                </p>
            </div>
        </div>
    </div>

    <!-- Alert Notifications -->
    @if (session('success'))
        <div class="p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle-2 class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-alert-circle class="size-4 shrink-0" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <!-- Dual-Pane Hub -->
    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-start">
        <!-- Left Master Navigation (4 columns) -->
        <div class="md:col-span-4 space-y-4">
            <!-- User Summary Badge Card -->
            <x-ui.card variant="sectioned" class="p-5 bg-card border border-border">
                <div class="flex items-center gap-3.5">
                    <div class="size-12 rounded-full bg-primary/10 text-primary font-bold flex items-center justify-center text-sm shrink-0 border border-primary/20">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                    <div class="flex flex-col truncate">
                        <span class="font-bold text-foreground text-sm truncate">{{ $user->name }}</span>
                        <span class="text-xs text-muted-foreground truncate">{{ $user->email }}</span>
                        <div class="flex items-center gap-1.5 mt-1">
                            <span class="size-2 rounded-full bg-emerald-500"></span>
                            <span class="text-[10px] text-muted-foreground uppercase font-mono">{{ $user->status }}</span>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            <!-- Navigation Tabs List -->
            <x-ui.card variant="sectioned" class="p-2 bg-card border border-border space-y-1">
                <button type="button" @click="setTab('profile')"
                    :class="activeTab === 'profile' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-foreground hover:bg-muted font-medium'"
                    class="flex w-full items-center gap-3 px-3 py-2 rounded-lg text-xs transition">
                    <x-lucide-user class="size-4 shrink-0" />
                    <span>Profile & Personal</span>
                </button>

                <button type="button" @click="setTab('employment')"
                    :class="activeTab === 'employment' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-foreground hover:bg-muted font-medium'"
                    class="flex w-full items-center gap-3 px-3 py-2 rounded-lg text-xs transition">
                    <x-lucide-briefcase class="size-4 shrink-0" />
                    <span>Employment & Organization</span>
                </button>

                <button type="button" @click="setTab('password')"
                    :class="activeTab === 'password' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-foreground hover:bg-muted font-medium'"
                    class="flex w-full items-center gap-3 px-3 py-2 rounded-lg text-xs transition">
                    <x-lucide-key class="size-4 shrink-0" />
                    <span>Change Password</span>
                </button>

                <button type="button" @click="setTab('mfa')"
                    :class="activeTab === 'mfa' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-foreground hover:bg-muted font-medium'"
                    class="flex w-full items-center justify-between px-3 py-2 rounded-lg text-xs transition">
                    <span class="flex items-center gap-3">
                        <x-lucide-shield-check class="size-4 shrink-0" />
                        <span>Two-Factor Auth (MFA)</span>
                    </span>
                    @if ($user->hasMfa())
                        <span class="size-2 rounded-full bg-emerald-500"></span>
                    @endif
                </button>

                <button type="button" @click="setTab('devices')"
                    :class="activeTab === 'devices' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-foreground hover:bg-muted font-medium'"
                    class="flex w-full items-center gap-3 px-3 py-2 rounded-lg text-xs transition">
                    <x-lucide-smartphone class="size-4 shrink-0" />
                    <span>Active Sessions & Devices</span>
                </button>

                <button type="button" @click="setTab('activity')"
                    :class="activeTab === 'activity' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-foreground hover:bg-muted font-medium'"
                    class="flex w-full items-center gap-3 px-3 py-2 rounded-lg text-xs transition">
                    <x-lucide-history class="size-4 shrink-0" />
                    <span>Security Activity</span>
                </button>
            </x-ui.card>
        </div>

        <!-- Right Content Canvas (8 columns) -->
        <div class="md:col-span-8">
            <!-- Tab 1: Profile & Personal Details -->
            <div x-show="activeTab === 'profile'" x-transition>
                <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <x-ui.card-title class="text-base font-bold text-foreground">Profile & Personal Information</x-ui.card-title>
                        <x-ui.card-description>Basic identity, contact details, and account demographics</x-ui.card-description>
                    </x-ui.card-header>
                    <x-ui.card-content class="p-6">
                        <form action="{{ route('users.settings.profile') }}" method="POST" class="space-y-5">
                            @csrf
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div class="space-y-1.5">
                                    <x-ui.label for="name">Full Name *</x-ui.label>
                                    <x-ui.input id="name" name="name" value="{{ old('name', $user->name) }}" required />
                                </div>
                                <div class="space-y-1.5">
                                    <x-ui.label for="email">Email Address *</x-ui.label>
                                    <x-ui.input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div class="space-y-1.5">
                                    <x-ui.label for="gender">Gender</x-ui.label>
                                    <select id="gender" name="gender" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="male" {{ old('gender', $user->gender) === 'male' ? 'selected' : '' }}>Male</option>
                                        <option value="female" {{ old('gender', $user->gender) === 'female' ? 'selected' : '' }}>Female</option>
                                        <option value="other" {{ old('gender', $user->gender) === 'other' ? 'selected' : '' }}>Other</option>
                                    </select>
                                </div>
                                <div class="space-y-1.5">
                                    <x-ui.label for="phone">Phone / Mobile</x-ui.label>
                                    <x-ui.input id="phone" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="+92 300 1234567" />
                                </div>
                            </div>

                            <div class="flex justify-end pt-3">
                                <x-ui.button type="submit" variant="default">Save Profile</x-ui.button>
                            </div>
                        </form>
                    </x-ui.card-content>
                </x-ui.card>
            </div>

            <!-- Tab 2: Employment & Organization -->
            <div x-show="activeTab === 'employment'" x-transition>
                <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <x-ui.card-title class="text-base font-bold text-foreground">Employment & Organization (UserDetail)</x-ui.card-title>
                        <x-ui.card-description>Corporate credentials and workplace attributes stored in the 1-to-1 aggregate table</x-ui.card-description>
                    </x-ui.card-header>
                    <x-ui.card-content class="p-6">
                        <form action="{{ route('users.settings.profile') }}" method="POST" class="space-y-5">
                            @csrf
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div class="space-y-1.5">
                                    <x-ui.label for="employee_id">Employee ID</x-ui.label>
                                    <x-ui.input id="employee_id" name="employee_id" value="{{ old('employee_id', $user->employee_id) }}" placeholder="EMP-1001" />
                                </div>
                                <div class="space-y-1.5">
                                    <x-ui.label for="cnic">National ID / CNIC</x-ui.label>
                                    <x-ui.input id="cnic" name="cnic" value="{{ old('cnic', $user->cnic) }}" placeholder="61101-1234567-1" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div class="space-y-1.5">
                                    <x-ui.label for="department">Department / Unit</x-ui.label>
                                    <x-ui.input id="department" name="department" value="{{ old('department', $user->department) }}" placeholder="Directorate of MIS" />
                                </div>
                                <div class="space-y-1.5">
                                    <x-ui.label for="designation">Designation / Role Title</x-ui.label>
                                    <x-ui.input id="designation" name="designation" value="{{ old('designation', $user->designation) }}" placeholder="Director IT" />
                                </div>
                            </div>

                            <div class="flex justify-end pt-3">
                                <x-ui.button type="submit" variant="default">Save Details</x-ui.button>
                            </div>
                        </form>
                    </x-ui.card-content>
                </x-ui.card>
            </div>

            <!-- Tab 3: Change Password -->
            <div x-show="activeTab === 'password'" x-transition>
                <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <x-ui.card-title class="text-base font-bold text-foreground">Change Password</x-ui.card-title>
                        <x-ui.card-description>Ensure your account is using a long, random password for security</x-ui.card-description>
                    </x-ui.card-header>
                    <x-ui.card-content class="p-6">
                        <form action="{{ route('users.settings.password') }}" method="POST" class="space-y-5">
                            @csrf
                            <div class="space-y-1.5">
                                <x-ui.label for="current_password">Current Password *</x-ui.label>
                                <x-ui.input id="current_password" name="current_password" type="password" required />
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div class="space-y-1.5">
                                    <x-ui.label for="new_password">New Password *</x-ui.label>
                                    <x-ui.input id="new_password" name="new_password" type="password" required placeholder="Min 8 characters" />
                                </div>
                                <div class="space-y-1.5">
                                    <x-ui.label for="new_password_confirmation">Confirm New Password *</x-ui.label>
                                    <x-ui.input id="new_password_confirmation" name="new_password_confirmation" type="password" required />
                                </div>
                            </div>

                            <div class="flex justify-end pt-3">
                                <x-ui.button type="submit" variant="default">Update Password</x-ui.button>
                            </div>
                        </form>
                    </x-ui.card-content>
                </x-ui.card>
            </div>

            <!-- Tab 4: Two-Factor Authentication (MFA) -->
            <div x-show="activeTab === 'mfa'" x-transition>
                <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <x-ui.card-title class="text-base font-bold text-foreground">Two-Factor Authentication (TOTP)</x-ui.card-title>
                                <x-ui.card-description>Add an extra layer of security using Google Authenticator or Microsoft Authenticator</x-ui.card-description>
                            </div>
                            @if ($user->hasMfa())
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                                    Active & Protected
                                </span>
                            @else
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-600 border border-amber-500/20">
                                    Disabled
                                </span>
                            @endif
                        </div>
                    </x-ui.card-header>
                    <x-ui.card-content class="p-6 space-y-6">
                        <!-- 2FA Status Card with Toggle Controls -->
                        <div x-data="{ showSetupDetails: false }" class="space-y-4">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-xl border {{ $user->hasMfa() ? 'border-emerald-500/20 bg-emerald-500/5' : 'border-border bg-muted/20' }}">
                                <div class="flex items-start gap-3">
                                    @if($user->hasMfa())
                                        <div class="size-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-600 dark:text-emerald-400 shrink-0">
                                            <x-lucide-shield-check class="size-5" />
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-bold text-foreground">Authenticator App (TOTP)</span>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">Configured & Active</span>
                                            </div>
                                            <p class="text-xs text-muted-foreground mt-0.5">
                                                Protecting sign-ins via Google / Microsoft Authenticator. Confirmed on {{ $user->mfa_confirmed_at ? $user->mfa_confirmed_at->format('M d, Y') : 'Active' }}.
                                            </p>
                                        </div>
                                    @else
                                        <div class="size-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-600 dark:text-amber-400 shrink-0">
                                            <x-lucide-shield-alert class="size-5" />
                                        </div>
                                        <div>
                                            <span class="text-sm font-bold text-foreground">Authenticator App (TOTP)</span>
                                            <p class="text-xs text-muted-foreground mt-0.5">Generate 6-digit one-time verification codes when logging in</p>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex items-center flex-wrap gap-2 shrink-0">
                                    @if ($user->hasMfa())
                                        <button type="button" @click="showSetupDetails = !showSetupDetails" class="px-3 py-1.5 rounded-lg border border-input hover:bg-muted text-xs font-semibold text-foreground transition-colors flex items-center gap-1.5 cursor-pointer">
                                            <x-lucide-qr-code class="size-3.5 text-purple-600" />
                                            <span x-text="showSetupDetails ? 'Hide Setup Key' : 'View Setup Key & QR'"></span>
                                        </button>
                                        <form action="{{ route('users.settings.issue_device_code') }}" method="POST">
                                            @csrf
                                            <x-ui.button type="submit" variant="outline" size="sm">
                                                <x-lucide-key-round class="size-3.5 mr-1 text-primary" />
                                                Issue Device Code
                                            </x-ui.button>
                                        </form>
                                        <form action="{{ route('users.settings.2fa.toggle') }}" method="POST" onsubmit="return confirm('Are you sure you want to disable Two-Factor Authentication? Your account will only be protected by your password.')">
                                            @csrf
                                            <x-ui.button type="submit" variant="destructive" size="sm">
                                                Disable 2FA
                                            </x-ui.button>
                                        </form>
                                    @else
                                        <form action="{{ route('users.settings.2fa.toggle') }}" method="POST">
                                            @csrf
                                            <x-ui.button type="submit" variant="default" size="sm">
                                                Enable 2FA
                                            </x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            <!-- Device Code Banner if generated -->
                            @if(session('generated_device_code'))
                                <div class="p-4 rounded-xl border border-primary/30 bg-primary/10 text-foreground flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="size-9 rounded-lg bg-primary/20 text-primary flex items-center justify-center shrink-0">
                                            <x-lucide-key-round class="size-5" />
                                        </div>
                                        <div>
                                            <div class="text-xs font-bold uppercase tracking-wider text-primary">Device Enrollment Code Generated</div>
                                            <div class="text-lg font-mono font-black text-foreground tracking-widest">{{ session('generated_device_code') }}</div>
                                        </div>
                                    </div>
                                    <span class="text-xs text-muted-foreground font-medium">Valid for 15 minutes</span>
                                </div>
                            @endif

                            @if ($user->hasMfa())
                            <!-- QR Code & Setup Key Container (Expandable on demand) -->
                            <div x-show="showSetupDetails" x-cloak x-transition class="p-5 rounded-2xl border border-purple-500/20 bg-purple-500/5 dark:bg-purple-950/20 flex flex-col md:flex-row items-center gap-6">
                                <div class="shrink-0 flex flex-col items-center">
                                    <div class="p-2 bg-white rounded-xl shadow-xs border border-border">
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data={{ urlencode('otpauth://totp/LaraSlice:' . $user->email . '?secret=' . ($user->mfa_secret ?? 'JBSWY3DPEHPK3PXP') . '&issuer=LaraSlice') }}" alt="TOTP QR Code" class="w-36 h-36 rounded-lg block" />
                                    </div>
                                    <span class="text-[11px] font-medium text-muted-foreground mt-2 flex items-center gap-1">
                                        <x-lucide-scan class="size-3 text-purple-600" /> Link additional device
                                    </span>
                                </div>
                                <div class="flex-1 space-y-3">
                                    <div>
                                        <h4 class="text-sm font-bold text-foreground">Authenticator Setup Key & Live Test</h4>
                                        <p class="text-xs text-muted-foreground mt-0.5 leading-relaxed">
                                            Your authenticator app is active. If you need to link a second phone or re-configure <strong>Google Authenticator</strong> or <strong>Microsoft Authenticator</strong>, scan this code or enter the secret key manually:
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <div class="px-3 py-2 bg-background border border-input rounded-lg font-mono text-sm font-bold tracking-widest text-purple-600 dark:text-purple-400 select-all shadow-xs">
                                            {{ chunk_split($user->mfa_secret ?? 'JBSWY3DPEHPK3PXP', 4, ' ') }}
                                        </div>
                                        <button type="button" onclick="navigator.clipboard.writeText('{{ $user->mfa_secret ?? 'JBSWY3DPEHPK3PXP' }}'); alert('Secret key copied to clipboard!');" class="p-2 rounded-lg border border-input hover:bg-muted text-muted-foreground hover:text-foreground transition-colors" title="Copy Secret">
                                            <x-lucide-copy class="size-4" />
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-4 text-xs text-muted-foreground pt-1">
                                        <span class="flex items-center gap-1.5"><x-lucide-shield-check class="size-3.5 text-emerald-500" /> RFC-6238 TOTP Standard</span>
                                        <span class="flex items-center gap-1.5"><x-lucide-clock class="size-3.5 text-purple-500" /> 30-Second Rotation</span>
                                    </div>
                                    <!-- Live Test / Verification Input -->
                                    <div class="pt-3 border-t border-purple-500/20">
                                        <form action="{{ route('users.settings.2fa.verify_test') }}" method="POST" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                                            @csrf
                                            <div class="relative flex-1">
                                                <input type="text" name="code" maxlength="6" pattern="[0-9]{6}" required placeholder="Test 6-digit code from your app..." class="w-full h-9 rounded-lg border border-input bg-background px-3 text-sm font-mono tracking-widest text-foreground placeholder:text-muted-foreground placeholder:tracking-normal focus:outline-none focus:ring-1 focus:ring-purple-500 shadow-xs" />
                                            </div>
                                            <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold transition-colors shadow-xs flex items-center justify-center gap-1.5 shrink-0">
                                                <x-lucide-check-circle class="size-3.5" />
                                                Test Code
                                            </button>
                                        </form>
                                        @if(session('totp_test_success'))
                                            <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium mt-2 flex items-center gap-1.5">
                                                <x-lucide-check class="size-3.5" /> {{ session('totp_test_success') }}
                                            </p>
                                        @elseif(session('totp_test_error'))
                                            <p class="text-xs text-red-600 dark:text-red-400 font-medium mt-2 flex items-center gap-1.5">
                                                <x-lucide-alert-circle class="size-3.5" /> {{ session('totp_test_error') }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>

                        <!-- Recovery Codes Section (Enterprise match) -->
                        <div class="pt-2 border-t border-border">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Emergency Recovery Codes</span>
                                    <p class="text-xs text-muted-foreground mt-0.5">Single-use backup codes for account access if your authenticator device is lost</p>
                                </div>
                                @if($user->hasMfa())
                                    <form action="{{ route('users.settings.2fa.regenerate_codes') }}" method="POST" onsubmit="return confirm('Regenerate fresh backup codes? Any existing unused codes will be revoked.')">
                                        @csrf
                                        <x-ui.button type="submit" variant="outline" size="sm">
                                            <x-lucide-refresh-cw class="size-3 mr-1" />
                                            Regenerate Codes
                                        </x-ui.button>
                                    </form>
                                @endif
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-3 font-mono text-xs">
                                @forelse ($user->recoveryCodes as $rc)
                                    <div class="p-2 rounded bg-muted/40 border border-border text-center {{ $rc->used_at ? 'line-through text-muted-foreground/40' : 'text-foreground' }}">
                                        {{ substr($rc->code_hash, 0, 4) }}-{{ substr($rc->code_hash, 4, 4) }}
                                    </div>
                                @empty
                                    <div class="col-span-4 p-3 rounded-lg border border-dashed text-xs text-muted-foreground text-center">
                                        No recovery codes generated yet. Enable 2FA to generate backup codes.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </x-ui.card-content>
                </x-ui.card>
            
                <!-- FIDO2 / WebAuthn Biometric Passkeys (Enterprise Parity) -->
                <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border mt-6" x-data="{ passkeyRegistering: false, passkeyError: '', passkeySuccess: '' }">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <x-ui.card-title class="text-base font-bold text-foreground">FIDO2 & WebAuthn Passkeys</x-ui.card-title>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20">
                                        Passwordless & Phishing-Proof
                                    </span>
                                </div>
                                <x-ui.card-description class="mt-0.5">
                                    Sign in instantly with Windows Hello, Apple Touch ID / Face ID, Android Biometrics, or USB Security Keys (YubiKey).
                                </x-ui.card-description>
                            </div>
                            <button type="button" @click="registerPasskey()" :disabled="passkeyRegistering" class="px-3.5 py-2 rounded-lg bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-bold transition-all shadow-xs flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50 shrink-0">
                                <x-lucide-fingerprint class="size-4" />
                                <span x-text="passkeyRegistering ? 'Registering...' : '+ Enroll New Passkey'"></span>
                            </button>
                        </div>
                    </x-ui.card-header>

                    <x-ui.card-content class="p-6 space-y-4">
                        <div x-show="passkeyError" x-cloak class="p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-xs flex items-center gap-2">
                            <x-lucide-alert-circle class="size-4 shrink-0" />
                            <span x-text="passkeyError"></span>
                        </div>
                        <div x-show="passkeySuccess" x-cloak class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs flex items-center gap-2">
                            <x-lucide-check-circle class="size-4 shrink-0" />
                            <span x-text="passkeySuccess"></span>
                        </div>

                        <div class="divide-y divide-border">
                            @forelse ($user->passkeys as $pk)
                                <div class="py-3 flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-600 dark:text-purple-400">
                                            <x-lucide-key class="size-5" />
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-bold text-foreground">{{ $pk->label ?: 'Passkey' }}</span>
                                                @if($pk->revoked_at)
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-red-500/10 text-red-500 border border-red-500/20">Revoked</span>
                                                @else
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">Active</span>
                                                @endif
                                            </div>
                                            <p class="text-xs text-muted-foreground mt-0.5">
                                                Created {{ $pk->created_at->diffForHumans() }} ({{ $pk->created_at->format('M d, Y H:i') }}) • Sign count: {{ $pk->sign_count }}
                                            </p>
                                        </div>
                                    </div>

                                    @if(!$pk->revoked_at)
                                        <form action="{{ route('users.settings.passkey.destroy', $pk->id) }}" method="POST" onsubmit="return confirm('Revoke this biometric passkey?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-3 py-1.5 rounded-lg border border-red-500/30 text-red-500 hover:bg-red-500/10 text-xs font-semibold transition-colors flex items-center gap-1 cursor-pointer">
                                                <x-lucide-trash-2 class="size-3.5" /> Revoke
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @empty
                                <div class="py-6 text-center text-xs text-muted-foreground border border-dashed rounded-xl">
                                    <x-lucide-fingerprint class="size-8 mx-auto mb-2 text-muted-foreground/60" />
                                    <p class="font-medium text-foreground">No biometric passkeys enrolled yet</p>
                                    <p class="text-muted-foreground mt-1">Click the button above to register your fingerprint, face, or security key for quick 1-tap logins.</p>
                                </div>
                            @endforelse
                        </div>
                    </x-ui.card-content>
                </x-ui.card>
            </div>



            <!-- Tab 5: Active Sessions & Devices -->
            <div x-show="activeTab === 'devices'" x-transition>
                <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <x-ui.card-title class="text-base font-bold text-foreground">Browser Sessions & Authorized Devices</x-ui.card-title>
                                <x-ui.card-description>Manage and log out your active sessions on other browsers and devices</x-ui.card-description>
                            </div>
                            <form action="{{ route('users.settings.logout_others') }}" method="POST" onsubmit="return confirm('Log out of all other devices?')">
                                @csrf
                                <x-ui.button type="submit" variant="destructive" size="sm">
                                    Log Out Other Devices
                                </x-ui.button>
                            </form>
                        </div>
                    </x-ui.card-header>
                    <x-ui.card-content class="p-6">
                        <div class="space-y-4">
                            @forelse ($user->devices as $dev)
                                <div class="flex items-center justify-between p-3.5 rounded-xl border border-border bg-card hover:bg-muted/20 transition">
                                    <div class="flex items-center gap-3">
                                        <div class="p-2 rounded-lg bg-muted text-muted-foreground">
                                            @if (in_array(strtolower($dev->platform), ['android', 'ios']))
                                                <x-lucide-smartphone class="size-5" />
                                            @else
                                                <x-lucide-laptop class="size-5" />
                                            @endif
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-semibold text-foreground">{{ $dev->device_name }}</span>
                                                @if ($dev->is_current)
                                                    <span class="text-[10px] font-bold text-emerald-600 bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20">This Device</span>
                                                @endif
                                            </div>
                                            <span class="text-[11px] text-muted-foreground font-mono">{{ $dev->ip_address }} • {{ $dev->location_label ?? 'Local' }}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs text-muted-foreground font-mono">
                                            {{ $dev->last_active_at ? $dev->last_active_at->diffForHumans() : 'Active' }}
                                        </span>
                                        @if(!$dev->is_current)
                                            <form action="{{ route('users.devices.destroy', $dev->id) }}" method="POST" onsubmit="return confirm('Revoke this device session?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="p-1 rounded text-muted-foreground hover:text-destructive transition-colors" title="Revoke Device">
                                                    <x-lucide-trash-2 class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-muted-foreground text-center py-4">No active devices registered.</p>
                            @endforelse
                        </div>
                    </x-ui.card-content>
                </x-ui.card>
            </div>

            <!-- Tab 6: Security Activity (Enterprise match) -->
            <div x-show="activeTab === 'activity'" x-transition>
                <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <x-ui.card-title class="text-base font-bold text-foreground">Security Audit Trail</x-ui.card-title>
                        <x-ui.card-description>Chronological audit log of sign-ins, security credentials updates, and session events</x-ui.card-description>
                    </x-ui.card-header>
                    <x-ui.card-content class="p-0">
                        <div class="divide-y divide-border">
                            @forelse ($securityLogs as $log)
                                <div class="p-4 flex items-center justify-between gap-4 hover:bg-muted/10 transition">
                                    <div class="flex items-center gap-3">
                                        <div class="size-8 rounded-lg flex items-center justify-center shrink-0
                                            @if($log->severity === 'danger') bg-red-500/10 text-red-600
                                            @elseif($log->severity === 'warning') bg-amber-500/10 text-amber-600
                                            @elseif($log->severity === 'success') bg-emerald-500/10 text-emerald-600
                                            @else bg-purple-500/10 text-purple-600 @endif">
                                            @if($log->severity === 'danger')
                                                <x-lucide-shield-alert class="size-4" />
                                            @elseif($log->severity === 'warning')
                                                <x-lucide-alert-triangle class="size-4" />
                                            @elseif($log->severity === 'success')
                                                <x-lucide-shield-check class="size-4" />
                                            @else
                                                <x-lucide-info class="size-4" />
                                            @endif
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs font-bold text-foreground uppercase tracking-wider font-mono">{{ str_replace('_', ' ', $log->event_type) }}</span>
                                                <span class="text-[10px] px-1.5 py-0.2 rounded font-semibold 
                                                    @if($log->severity === 'danger') bg-red-500/10 text-red-600 border border-red-500/20
                                                    @elseif($log->severity === 'warning') bg-amber-500/10 text-amber-600 border border-amber-500/20
                                                    @elseif($log->severity === 'success') bg-emerald-500/10 text-emerald-600 border border-emerald-500/20
                                                    @else bg-blue-500/10 text-blue-600 border border-blue-500/20 @endif">
                                                    {{ strtoupper($log->severity ?? 'INFO') }}
                                                </span>
                                            </div>
                                            <p class="text-xs text-muted-foreground mt-0.5">{{ $log->notes ?? 'Authentication event recorded' }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <div class="text-xs font-mono text-muted-foreground">{{ $log->ip_address }}</div>
                                        <div class="text-[11px] text-muted-foreground">{{ $log->created_at->diffForHumans() }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="p-8 text-center text-xs text-muted-foreground">
                                    No security log entries recorded yet.
                                </div>
                            @endforelse
                        </div>
                    </x-ui.card-content>
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
@endsection


<script>
function bufferToBase64url(buffer) {
    let binary = '';
    const bytes = new Uint8Array(buffer);
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function base64urlToBuffer(base64url) {
    let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    while (base64.length % 4) { base64 += '='; }
    const binary = window.atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}

async function registerPasskey() {
    const cardEl = document.querySelector('[x-data*="passkeyRegistering"]');
    const alpine = cardEl ? Alpine.$data(cardEl) : {};
    if (alpine.passkeyRegistering !== undefined) alpine.passkeyRegistering = true;
    if (alpine.passkeyError !== undefined) alpine.passkeyError = '';
    if (alpine.passkeySuccess !== undefined) alpine.passkeySuccess = '';

    try {
        if (window.location.hostname === '127.0.0.1') {
            throw new Error("Passkeys (WebAuthn) require a domain name (such as http://localhost:7000 or an HTTPS .test domain) rather than an IP address (127.0.0.1). Please open this app via http://localhost:7000 to enroll your passkey.");
        }

        if (!window.isSecureContext) {
            throw new Error("Passkeys (WebAuthn) require a secure context. Open this app over HTTPS (e.g. https://" + window.location.host + ") or via http://localhost.");
        }

        if (!window.PublicKeyCredential) {
            throw new Error("WebAuthn is not supported by this browser.");
        }

        const optResp = await fetch("{{ route('users.settings.passkey.options') }}", {
            headers: { 'Accept': 'application/json' }
        });
        if (!optResp.ok) throw new Error("Could not initialize passkey registration.");
        const options = await optResp.json();

        const publicKey = options.args?.publicKey || options.publicKey || options;
        publicKey.challenge = base64urlToBuffer(publicKey.challenge);
        if (publicKey.user && publicKey.user.id) {
            publicKey.user.id = base64urlToBuffer(publicKey.user.id);
        }
        if (publicKey.excludeCredentials && publicKey.excludeCredentials.length > 0) {
            publicKey.excludeCredentials = publicKey.excludeCredentials.map(c => ({
                ...c,
                id: base64urlToBuffer(c.id)
            }));
        }

        const credential = await navigator.credentials.create({ publicKey });
        if (!credential) throw new Error("Registration was cancelled.");

        const label = prompt("Enter a label for this device / passkey:", "My Workstation Passkey") || "Passkey";

        const verifyPayload = {
            clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
            attestationObject: bufferToBase64url(credential.response.attestationObject),
            label: label,
            _token: "{{ csrf_token() }}"
        };

        const verifyResp = await fetch("{{ route('users.settings.passkey.verify') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': "{{ csrf_token() }}"
            },
            body: JSON.stringify(verifyPayload)
        });

        const result = await verifyResp.json();
        if (result.success) {
            if (alpine.passkeySuccess !== undefined) alpine.passkeySuccess = result.message;
            setTimeout(() => window.location.reload(), 1200);
        } else {
            throw new Error(result.message || "Failed to verify passkey.");
        }
    } catch (e) {
        if (alpine.passkeyError !== undefined) alpine.passkeyError = e.message;
    } finally {
        if (alpine.passkeyRegistering !== undefined) alpine.passkeyRegistering = false;
    }
}
</script>