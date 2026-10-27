@props([
    'name' => 'Administrator',
    'email' => 'admin@laraslice.com',
    'avatar' => '',
    'fallback' => 'AD',
    'align' => 'end',
])

<div x-data="{ userMenuOpen: false }" class="relative w-full">
    <button type="button" 
            @click="userMenuOpen = !userMenuOpen"
            class="w-full flex items-center gap-3 p-2 rounded-xl hover:bg-muted/70 transition text-left cursor-pointer group">
        <div class="size-8 rounded-lg bg-primary/15 text-primary font-bold text-xs flex items-center justify-center shrink-0">
            @if ($avatar)
                <img src="{{ $avatar }}" alt="{{ $name }}" class="size-8 rounded-lg object-cover" />
            @else
                <span>{{ $fallback }}</span>
            @endif
        </div>
        <div class="flex-1 min-w-0 leading-tight">
            <div class="truncate text-xs font-bold text-foreground">{{ $name }}</div>
            <div class="truncate text-[10px] text-muted-foreground">{{ $email }}</div>
        </div>
        <x-lucide-chevrons-up-down class="size-3.5 text-muted-foreground group-hover:text-foreground shrink-0 transition" />
    </button>

    <!-- Upward Popover Menu -->
    <div x-show="userMenuOpen" 
         x-cloak 
         @click.outside="userMenuOpen = false"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 translate-y-1 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-1 scale-95"
         class="absolute bottom-full left-0 mb-2 w-56 bg-card text-card-foreground rounded-2xl border border-border p-1.5 shadow-2xl z-50">
        
        <div class="flex items-center gap-2.5 px-3 py-2 border-b border-border/50 mb-1">
            <div class="size-7 rounded-lg bg-primary text-primary-foreground font-black text-xs flex items-center justify-center shrink-0">
                {{ $fallback }}
            </div>
            <div class="min-w-0 flex-1 leading-tight">
                <div class="truncate text-xs font-bold text-foreground">{{ $name }}</div>
                <div class="truncate text-[10px] text-muted-foreground">{{ $email }}</div>
            </div>
        </div>

        <div class="space-y-0.5 text-xs font-medium">
            @if ($inviteUrl = \LaraSlice\Support\InviteTarget::url())
            <a href="{{ $inviteUrl }}"
               class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-muted text-muted-foreground hover:text-foreground transition">
                <x-lucide-mail-plus class="size-3.5 text-primary" />
                <span>Invite member</span>
            </a>
            @endif
            <a href="{{ Route::has('settings.theme') ? route('settings.theme') : url('/admin/settings/theme') }}" 
               class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-muted text-muted-foreground hover:text-foreground transition">
                <x-lucide-sparkles class="size-3.5 text-primary" />
                <span>Theme Studio</span>
            </a>
            <a href="{{ Route::has('users.index') ? route('users.index') : url('/admin/users') }}" 
               class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-muted text-muted-foreground hover:text-foreground transition">
                <x-lucide-badge-check class="size-3.5" />
                <span>Account & Users</span>
            </a>
            <a href="{{ Route::has('settings.smtp') ? route('settings.smtp') : url('/admin/settings/smtp') }}" 
               class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-muted text-muted-foreground hover:text-foreground transition">
                <x-lucide-settings class="size-3.5" />
                <span>System Settings</span>
            </a>
        </div>

        <div class="border-t border-border/50 my-1 pt-1">
            <form method="POST" action="{{ Route::has('logout') ? route('logout') : url('/logout') }}">
                @csrf
                <button type="submit" class="w-full flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-destructive/10 text-destructive text-xs font-medium transition cursor-pointer">
                    <x-lucide-log-out class="size-3.5" />
                    <span>Log out</span>
                </button>
            </form>
        </div>
    </div>
</div>
