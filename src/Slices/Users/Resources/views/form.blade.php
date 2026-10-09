@extends('layouts.app')

@section('content')
<div class="w-full max-w-5xl mx-auto space-y-6">
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('users.index') }}" class="hover:text-primary transition-colors">Users</a>
        <span>/</span>
        <span class="text-foreground font-medium">{{ $isNew ? 'Create User' : 'Edit User #' . $form->id }}</span>
    </div>

    @if (session('error'))
        <div class="p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-alert-circle class="size-4 shrink-0" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <form action="{{ $isNew ? route('users.store') : route('users.update', $form->id) }}" method="POST" class="space-y-6">
        @csrf
        @if(!$isNew) @method('PUT') @endif

        <!-- Card 1: Account Credentials & Status -->
        <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <x-ui.card-title class="text-base font-bold text-foreground">1. Account Credentials & Status</x-ui.card-title>
                <x-ui.card-description>Basic login credentials, access status, and RBAC security roles</x-ui.card-description>
            </x-ui.card-header>

            <x-ui.card-content class="p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="name">Full Name *</x-ui.label>
                        <x-ui.input id="name" name="name" value="{{ old('name', $form->name) }}" required placeholder="e.g. John Doe" />
                        @error('name')
                            <p class="text-xs text-destructive font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="email">Email Address *</x-ui.label>
                        <x-ui.input id="email" name="email" type="email" value="{{ old('email', $form->email) }}" required placeholder="john@example.com" />
                        @error('email')
                            <p class="text-xs text-destructive font-medium">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-1.5" x-data="{ showPass: false }">
                        <x-ui.label for="password">{{ $isNew ? 'Password *' : 'Password (Leave blank to keep unchanged)' }}</x-ui.label>
                        <div class="relative">
                            <input 
                                :type="showPass ? 'text' : 'password'" 
                                id="password" 
                                name="password" 
                                class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 pr-10 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring" 
                                placeholder="••••••••" 
                                autocomplete="new-password"
                                @if($isNew) required @endif
                            >
                            <button 
                                type="button" 
                                @click="showPass = !showPass" 
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted-foreground hover:text-foreground transition-colors"
                                title="Toggle password visibility"
                            >
                                <span x-show="!showPass"><x-lucide-eye class="size-4" /></span>
                                <span x-show="showPass" style="display:none;"><x-lucide-eye-off class="size-4" /></span>
                            </button>
                        </div>
                        @error('password')
                            <p class="text-xs text-destructive font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="status">Account Status</x-ui.label>
                        <select id="status" name="status" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                            <option value="active" {{ old('status', $form->status) === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="suspended" {{ old('status', $form->status) === 'suspended' ? 'selected' : '' }}>Suspended</option>
                            <option value="pending" {{ old('status', $form->status) === 'pending' ? 'selected' : '' }}>Pending</option>
                        </select>
                    </div>
                </div>

                <!-- Roles Checkboxes -->
                <div class="space-y-2 pt-2">
                    <x-ui.label>Assigned RBAC Roles</x-ui.label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 p-4 rounded-xl border border-border bg-muted/20">
                        @php
                            $availableRoles = \Illuminate\Support\Facades\DB::table('roles')->get();
                            $rawUserRoles = old('roles', $form->roles ?? ($form->roleIds ?? []));
                            $userRoleIds = is_array($rawUserRoles) ? array_map('intval', $rawUserRoles) : [];
                        @endphp
                        <input type="hidden" name="roles" value="">
                        @forelse ($availableRoles as $r)
                            <label class="flex items-center gap-3 p-2 rounded-lg hover:bg-muted/40 cursor-pointer transition-colors">
                                <input type="checkbox" name="roles[]" value="{{ $r->id }}" {{ in_array((int)$r->id, $userRoleIds, true) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
                                <div>
                                    <span class="text-sm font-semibold text-foreground">{{ $r->name }}</span>
                                    <span class="block text-[11px] text-muted-foreground">{{ $r->slug }}</span>
                                </div>
                            </label>
                        @empty
                            <p class="text-xs text-muted-foreground col-span-3">No roles configured. Standard user permissions will apply.</p>
                        @endforelse
                    </div>
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <!-- Card 2: Personal Profile & Demographics (Includes Gender & CNIC) -->
        <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <x-ui.card-title class="text-base font-bold text-foreground">2. Personal Profile & Identity</x-ui.card-title>
                <x-ui.card-description>Demographics, national identification, and contact details</x-ui.card-description>
            </x-ui.card-header>

            <x-ui.card-content class="p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="gender">Gender</x-ui.label>
                        <select id="gender" name="gender" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                            <option value="">Select Gender...</option>
                            <option value="" {{ blank(old('gender', $form->gender)) ? 'selected' : '' }}>Not specified</option>
                            <option value="male" {{ old('gender', $form->gender ?? '') === 'male' ? 'selected' : '' }}>Male</option>
                            <option value="female" {{ old('gender', $form->gender ?? '') === 'female' ? 'selected' : '' }}>Female</option>
                            <option value="other" {{ old('gender', $form->gender ?? '') === 'other' ? 'selected' : '' }}>Other</option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="phone">Phone / Mobile</x-ui.label>
                        <x-ui.input id="phone" name="phone" value="{{ old('phone', $form->phone ?? '') }}" placeholder="e.g. +92 300 1234567" />
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="dob">Date of Birth</x-ui.label>
                        <x-ui.input id="dob" name="dob" type="date" value="{{ old('dob', $form->dob ?? '') }}" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="cnic">National ID / CNIC</x-ui.label>
                        <x-ui.input id="cnic" name="cnic" value="{{ old('cnic', $form->cnic ?? '') }}" placeholder="e.g. 32301-8275113-7" />
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="avatarUrl">Avatar URL</x-ui.label>
                        <x-ui.input id="avatarUrl" name="avatarUrl" value="{{ old('avatarUrl', $form->avatarUrl ?? '') }}" placeholder="https://..." />
                    </div>
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <!-- Card 3: Enterprise Employment Details -->
        <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
            <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                <x-ui.card-title class="text-base font-bold text-foreground">3. Employment & Hierarchy</x-ui.card-title>
                <x-ui.card-description>Corporate employee identifiers and departmental assignments</x-ui.card-description>
            </x-ui.card-header>

            <x-ui.card-content class="p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="space-y-1.5">
                        <x-ui.label for="employeeId">Employee ID</x-ui.label>
                        <x-ui.input id="employeeId" name="employeeId" value="{{ old('employeeId', $form->employeeId ?? '') }}" placeholder="EMP-1001" />
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="department">Department</x-ui.label>
                        <x-ui.input id="department" name="department" value="{{ old('department', $form->department ?? '') }}" placeholder="e.g. Engineering" />
                    </div>

                    <div class="space-y-1.5">
                        <x-ui.label for="designation">Designation</x-ui.label>
                        <x-ui.input id="designation" name="designation" value="{{ old('designation', $form->designation ?? '') }}" placeholder="e.g. Senior Officer" />
                    </div>
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <!-- Card 4: Granted RBAC Capabilities & Permissions Preview -->
        @if (!empty($form->permissions) && count($form->permissions) > 0)
            <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
                <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <x-ui.card-title class="text-base font-bold text-foreground flex items-center gap-2">
                                <x-lucide-shield-check class="size-4 text-emerald-500" />
                                <span>4. Effective Assigned Permissions</span>
                            </x-ui.card-title>
                            <x-ui.card-description>Permissions and capabilities automatically inherited from assigned RBAC roles</x-ui.card-description>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                            {{ count($form->permissions) }} capabilities granted
                        </span>
                    </div>
                </x-ui.card-header>

                <x-ui.card-content class="p-6">
                    <div class="flex flex-wrap gap-2 max-h-52 overflow-y-auto p-1">
                        @foreach ($form->permissions as $p)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-mono bg-muted text-foreground border border-border">
                                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                {{ $p['name'] ?? $p['slug'] ?? 'Permission' }}
                            </span>
                        @endforeach
                    </div>
                </x-ui.card-content>
            </x-ui.card>
        @endif

        <!-- Actions -->
        <div class="flex items-center justify-end gap-3 pt-3">
            <x-ui.button href="{{ route('users.index') }}" as="a" variant="outline">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit" name="action" value="save_continue" variant="secondary">
                Save & Continue
            </x-ui.button>
            <x-ui.button type="submit" name="action" value="save_close">
                {{ $isNew ? 'Create User' : 'Save Changes' }}
            </x-ui.button>
        </div>
    </form>
</div>
@endsection
