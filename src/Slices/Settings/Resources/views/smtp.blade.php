@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:text-primary transition-colors">Dashboard</a>
        <span>/</span>
        <span class="text-foreground font-medium">System Settings & SMTP</span>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-success/30 bg-success/10 text-success text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
        <x-ui.card-header class="border-b pb-4 px-6 pt-6">
            <div class="flex items-center justify-between">
                <div>
                    <x-ui.card-title class="text-lg font-bold text-foreground">SMTP Mail Configuration</x-ui.card-title>
                    <x-ui.card-description>Configure outgoing mail server, transport credentials, and notifications</x-ui.card-description>
                </div>
                <x-ui.badge variant="secondary" class="font-mono text-xs">v1.0.0</x-ui.badge>
            </div>
        </x-ui.card-header>

        <x-ui.card-content class="p-6">
            <form action="{{ route('settings.smtp.save') }}" method="POST" class="space-y-6">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="mail_host">SMTP Host *</x-ui.label>
                        <x-ui.input id="mail_host" name="mail_host" value="{{ old('mail_host', $settings->mail_host ?? 'smtp.mailtrap.io') }}" required placeholder="e.g. smtp.gmail.com" />
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="mail_port">SMTP Port *</x-ui.label>
                        <x-ui.input id="mail_port" name="mail_port" type="number" value="{{ old('mail_port', $settings->mail_port ?? 587) }}" required placeholder="587" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="mail_username">Username</x-ui.label>
                        <x-ui.input id="mail_username" name="mail_username" value="{{ old('mail_username', $settings->mail_username ?? '') }}" placeholder="SMTP username" />
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="mail_password">Password</x-ui.label>
                        <x-ui.input id="mail_password" name="mail_password" type="password" value="" autocomplete="new-password" placeholder="{{ !empty($settings->mail_password) ? 'Saved password hidden. Leave blank to keep it' : 'SMTP password' }}" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="mail_encryption">Encryption</x-ui.label>
                        <select id="mail_encryption" name="mail_encryption" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                            <option value="tls" {{ ($settings->mail_encryption ?? 'tls') === 'tls' ? 'selected' : '' }}>TLS</option>
                            <option value="ssl" {{ ($settings->mail_encryption ?? '') === 'ssl' ? 'selected' : '' }}>SSL</option>
                            <option value="none" {{ ($settings->mail_encryption ?? '') === 'none' ? 'selected' : '' }}>None</option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="mail_from_address">From Address *</x-ui.label>
                        <x-ui.input id="mail_from_address" name="mail_from_address" type="email" value="{{ old('mail_from_address', $settings->mail_from_address ?? 'noreply@laraslice.com') }}" required />
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="mail_from_name">From Name *</x-ui.label>
                        <x-ui.input id="mail_from_name" name="mail_from_name" value="{{ old('mail_from_name', $settings->mail_from_name ?? 'LaraSlice') }}" required />
                    </div>
                </div>

                <div class="flex items-center justify-between pt-5 border-t border-border/50">
                    <x-ui.button type="submit" formaction="{{ route('settings.smtp.test') }}" variant="secondary">
                        <x-lucide-send class="size-4 mr-1.5" />
                        <span>Send Test Email</span>
                    </x-ui.button>

                    <div class="flex items-center gap-3">
                        <x-ui.button href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" as="a" variant="outline">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit">
                            Save Settings
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </x-ui.card-content>
    </x-ui.card>
</div>
@endsection