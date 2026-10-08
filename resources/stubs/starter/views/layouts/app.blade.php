<!DOCTYPE html>
<html class="h-full" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @includeIf('layouts.partials.head')
        <style>
            [x-cloak] { display: none !important; }
        </style>
        @if(file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <script src="https://cdn.tailwindcss.com/3.4.17"></script>
            <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.17.4/dist/cdn.min.js" integrity="sha384-5/joNqFnRyVWzXp99bHot6RHG+EksGp+USSgZwPar7T9SD9PKKER37n/8bXBAZGd" crossorigin="anonymous"></script>
        @endif
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased font-sans flex flex-col selection:bg-primary/20 selection:text-primary"
          x-data="{
              searchOpen: false,
              themeMode: localStorage.getItem('theme:mode') || 'light',
              toggleTheme() {
                  this.themeMode = this.themeMode === 'dark' ? 'light' : 'dark';
                  localStorage.setItem('theme:mode', this.themeMode);
                  if (this.themeMode === 'dark') {
                      document.documentElement.classList.add('dark');
                  } else {
                      document.documentElement.classList.remove('dark');
                  }
              }
          }"
          @keydown.window.cmd.k.prevent="searchOpen = true"
          @keydown.window.ctrl.k.prevent="searchOpen = true">

        @php
            $navSlices = \LaraSlice\Facades\LaraSlice::getNavigableSlices();

            // Lucide Icon Resolver helper
            $resolveLucide = function ($iconName) {
                static $factory = null;
                if ($factory === null) {
                    $factory = app(\BladeUI\Icons\Factory::class);
                }
                $map = [
                    'basket'             => 'shopping-bag',
                    'shop'               => 'shopping-bag',
                    'cart'               => 'shopping-cart',
                    'bag'                => 'shopping-bag',
                    'box'                => 'box',
                    'cube'               => 'box',
                    'cube-2'             => 'boxes',
                    'cube-3'             => 'box',
                    'people'             => 'users',
                    'user'               => 'user',
                    'security-user'      => 'shield-check',
                    'shield'             => 'shield',
                    'shield-check'       => 'shield-check',
                    'lock'               => 'lock',
                    'key'                => 'key',
                    'setting-2'          => 'settings',
                    'settings'           => 'settings',
                    'document'           => 'file-text',
                    'file'               => 'file-text',
                    'chart-pie-simple'   => 'pie-chart',
                    'chart'              => 'bar-chart-3',
                    'element-11'         => 'layout-dashboard',
                    'dashboard'          => 'layout-dashboard',
                    'wallet'             => 'wallet',
                    'notification-status'=> 'bell',
                    'bell'               => 'bell',
                    'message-text-2'     => 'message-square',
                    'chat'               => 'message-square',
                    'wand'               => 'wand-2',
                    'code'               => 'code',
                    'database'           => 'database',
                    'tag'                => 'tag',
                    'layers'             => 'layers',
                    'package'            => 'package',
                ];
                $clean = strtolower(trim((string) $iconName));
                $target = $map[$clean] ?? (str_starts_with($clean, 'lucide-') ? substr($clean, 7) : ($clean ?: 'box'));
                try {
                    $factory->svg('lucide-' . $target);
                    return $target;
                } catch (\Throwable $e) {
                    return 'box';
                }
            };
        @endphp

        @php
            $laraSliceVersion = class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('hereafter/laraslice')
                ? ltrim((string) \Composer\InstalledVersions::getPrettyVersion('hereafter/laraslice'), 'v')
                : '1.0';

            $sliceNavItem = fn (array $s) => [
                'title'    => $s['label'] ?? $s['title'] ?? $s['name'] ?? 'Slice',
                'url'      => $s['url'],
                'icon'     => $resolveLucide($s['icon'] ?? 'box'),
                'isOpen'   => collect($s['children'] ?? [])->contains(fn ($c) => !empty($c['active'])),
                'isActive' => !empty($s['active']) && !collect($s['children'] ?? [])->contains(fn ($c) => !empty($c['active'])),
                'children' => array_map(fn ($c) => [
                    'title'    => $c['label'],
                    'url'      => $c['url'],
                    'isActive' => !empty($c['active']),
                ], $s['children'] ?? []),
            ];

            $workspaceItems = [
                ['title' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => (Route::has('dashboard') ? route('dashboard') : url('/')), 'isActive' => request()->routeIs('dashboard')],
            ];
            if (Route::has('laraslice.wizard')) {
                $workspaceItems[] = ['title' => 'Slice Studio', 'icon' => 'wand-2', 'url' => route('laraslice.wizard'), 'isActive' => request()->routeIs('laraslice.wizard*')];
            }
            $navMain = [
                ['title' => 'Workspace', 'items' => $workspaceItems],
            ];

            // Domain slices first, then ungrouped app slices, then LaraSlice's core slices under Administration.
            // A group named after the ungrouped bucket is treated as ungrouped so it never appears twice.
            $navDomains = [];
            $navUngrouped = [];
            $navCore = [];
            foreach ($navSlices as $s) {
                $grp = $s['group'] ?? null;
                if (!empty($s['core'])) {
                    $navCore[] = $sliceNavItem($s);
                } elseif ($grp && $grp !== 'Vertical Slices') {
                    $navDomains[$grp][] = $sliceNavItem($s);
                } else {
                    $navUngrouped[] = $sliceNavItem($s);
                }
            }
            foreach ($navDomains as $groupName => $items) {
                $navMain[] = ['title' => $groupName, 'items' => $items];
            }
            if (!empty($navUngrouped)) {
                $navMain[] = ['title' => 'Vertical Slices', 'items' => $navUngrouped];
            }
            if (!empty($navCore)) {
                $navMain[] = ['title' => 'Administration', 'items' => $navCore];
            }
        @endphp

        <x-ui.sidebar-provider>
            <x-ui.sidebar>
                <x-ui.sidebar-header>
                    <x-block.version-switcher title="LaraSlice" :versions="[$laraSliceVersion]">
                        <x-slot:icon><x-lucide-layers class="size-4" /></x-slot:icon>
                    </x-block.version-switcher>
                    <x-block.search-form placeholder="Search slices, routes..." readonly @focus="searchOpen = true; $el.blur()" @click="searchOpen = true" />
                </x-ui.sidebar-header>
                <x-ui.sidebar-content class="gap-0">
                    @foreach ($navMain as $group)
                        <x-ui.collapsible :open="true" class="group/collapsible" ::data-state="open ? 'open' : 'closed'">
                            <x-ui.sidebar-group>
                                <x-ui.collapsible-trigger
                                    class="group/label text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground ring-sidebar-ring flex h-8 w-full shrink-0 items-center rounded-md px-2 text-sm font-medium outline-none transition-[margin,opacity] duration-200 ease-linear focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0">
                                    {{ $group['title'] }}
                                    <x-lucide-chevron-right class="ml-auto transition-transform group-data-[state=open]/collapsible:rotate-90" />
                                </x-ui.collapsible-trigger>
                                <x-ui.collapsible-content>
                                    <x-ui.sidebar-group-content>
                                        <x-ui.sidebar-menu>
                                            @foreach ($group['items'] as $item)
                                                <x-ui.sidebar-menu-item>
                                                    @if (!empty($item['children']))
                                                        {{-- Parent with sub-pages: a dropdown, open while one of its pages is active --}}
                                                        <x-ui.collapsible :open="$item['isOpen'] ?? false" class="group/menu-collapsible" ::data-state="open ? 'open' : 'closed'">
                                                            <x-ui.sidebar-menu-button :tooltip="$item['title']" @click="open = !open" ::aria-expanded="open">
                                                                <x-dynamic-component :component="'lucide-' . $item['icon']" />
                                                                <span class="min-w-0 truncate">{{ $item['title'] }}</span>
                                                                <x-lucide-chevron-right class="ml-auto transition-transform duration-200 group-data-[state=open]/menu-collapsible:rotate-90" />
                                                            </x-ui.sidebar-menu-button>
                                                            <x-ui.collapsible-content>
                                                                <x-ui.sidebar-menu-sub>
                                                                    @foreach ($item['children'] as $child)
                                                                        <x-ui.sidebar-menu-sub-item>
                                                                            <x-ui.sidebar-menu-sub-button href="{{ $child['url'] }}" :is-active="$child['isActive']">
                                                                                <span>{{ $child['title'] }}</span>
                                                                            </x-ui.sidebar-menu-sub-button>
                                                                        </x-ui.sidebar-menu-sub-item>
                                                                    @endforeach
                                                                </x-ui.sidebar-menu-sub>
                                                            </x-ui.collapsible-content>
                                                        </x-ui.collapsible>
                                                    @else
                                                        <x-ui.sidebar-menu-button href="{{ $item['url'] }}" :is-active="$item['isActive'] ?? false" :tooltip="$item['title']">
                                                            <x-dynamic-component :component="'lucide-' . $item['icon']" />
                                                            <span>{{ $item['title'] }}</span>
                                                        </x-ui.sidebar-menu-button>
                                                    @endif
                                                </x-ui.sidebar-menu-item>
                                            @endforeach
                                        </x-ui.sidebar-menu>
                                    </x-ui.sidebar-group-content>
                                </x-ui.collapsible-content>
                            </x-ui.sidebar-group>
                        </x-ui.collapsible>
                    @endforeach
                </x-ui.sidebar-content>
                <x-ui.sidebar-footer>
                    <x-block.nav-user
                        name="{{ auth()->user()->name ?? 'Administrator' }}"
                        email="{{ auth()->user()->email ?? 'admin@laraslice.dev' }}"
                        avatar=""
                        fallback="{{ substr(auth()->user()->name ?? 'Admin', 0, 2) }}" />
                </x-ui.sidebar-footer>
                <x-ui.sidebar-rail />
            </x-ui.sidebar>

                <x-ui.sidebar-inset>
                    {{-- Site Header --}}
                    <header class="flex h-14 shrink-0 items-center gap-2 border-b border-border px-4 lg:px-6 bg-background/95 backdrop-blur-xs sticky top-0 z-30">
                        <x-ui.sidebar-trigger class="-ml-1" />
                        <x-ui.separator orientation="vertical" class="mr-2 data-[orientation=vertical]:h-4" />
                        
                        <x-ui.breadcrumb class="hidden sm:flex">
                            <x-ui.breadcrumb-list>
                                <x-ui.breadcrumb-item>
                                    <x-ui.breadcrumb-link href="{{ (Route::has('dashboard') ? route('dashboard') : url('/')) }}">Dashboard</x-ui.breadcrumb-link>
                                </x-ui.breadcrumb-item>
                                @php
                                    $activeSlice = null;
                                    $activeChild = null;
                                    foreach ($navSlices as $s) {
                                        if (!empty($s['children'])) {
                                            foreach ($s['children'] as $c) {
                                                if (!empty($c['active'])) {
                                                    $activeSlice = $s;
                                                    $activeChild = $c;
                                                    break 2;
                                                }
                                            }
                                        }
                                        if (!empty($s['active']) && !$activeSlice) {
                                            $activeSlice = $s;
                                        }
                                    }
                                @endphp
                                @if ($activeSlice)
                                    <x-ui.breadcrumb-separator />
                                    @if (!empty($activeSlice['group']))
                                        <x-ui.breadcrumb-item>
                                            <span class="text-muted-foreground">{{ $activeSlice['group'] }}</span>
                                        </x-ui.breadcrumb-item>
                                        <x-ui.breadcrumb-separator />
                                    @endif
                                    @if ($activeChild)
                                        <x-ui.breadcrumb-item>
                                            <x-ui.breadcrumb-link href="{{ $activeSlice['url'] }}">{{ $activeSlice['label'] ?? $activeSlice['title'] ?? $activeSlice['name'] ?? 'Slice' }}</x-ui.breadcrumb-link>
                                        </x-ui.breadcrumb-item>
                                        <x-ui.breadcrumb-separator />
                                        <x-ui.breadcrumb-item>
                                            <x-ui.breadcrumb-page>{{ $activeChild['label'] }}</x-ui.breadcrumb-page>
                                        </x-ui.breadcrumb-item>
                                    @else
                                        <x-ui.breadcrumb-item>
                                            <x-ui.breadcrumb-page>{{ $activeSlice['label'] ?? $activeSlice['title'] ?? $activeSlice['name'] ?? 'Slice' }}</x-ui.breadcrumb-page>
                                        </x-ui.breadcrumb-item>
                                    @endif
                                @endif
                            </x-ui.breadcrumb-list>
                        </x-ui.breadcrumb>

                        <div class="ml-auto flex items-center gap-2">
                            <!-- Quick Search Button (Cmd+K) -->
                            <button type="button" @click="searchOpen = true"
                                    class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-lg border border-input bg-muted/30 text-muted-foreground hover:text-foreground text-xs transition cursor-pointer">
                                <x-lucide-search class="size-3.5" />
                                <span>Search slices, routes...</span>
                                <kbd class="px-1.5 py-0.5 rounded bg-muted text-[10px] font-mono border border-border">⌘K</kbd>
                            </button>

                            <!-- Theme Toggle (Light / Dark) -->
                            <button type="button" @click="toggleTheme()"
                                    class="size-8 rounded-lg border border-input bg-background hover:bg-muted text-muted-foreground hover:text-foreground flex items-center justify-center transition cursor-pointer"
                                    title="Toggle Dark / Light Mode">
                                <x-lucide-sun class="size-4 dark:hidden" />
                                <x-lucide-moon class="size-4 hidden dark:block" />
                                <span class="sr-only">Toggle theme</span>
                            </button>

                            <!-- Slice Wizard Quick Action -->
                            @if (Route::has('laraslice.wizard'))
                            <x-ui.button href="{{ route('laraslice.wizard') }}" as="a" size="sm" class="hidden sm:inline-flex gap-1.5 shadow-xs">
                                <x-lucide-wand-2 class="size-3.5" />
                                <span>Studio</span>
                            </x-ui.button>
                            @endif
                        </div>
                    </header>

                    {{-- Main Content Body --}}
                    <main class="@container/main flex flex-1 flex-col gap-4 p-4 lg:p-6 w-full max-w-[1600px] mx-auto">
                        @yield('content')
                        {{ $slot ?? '' }}
                    </main>
                </x-ui.sidebar-inset>
            </x-ui.sidebar-provider>

            <!-- Command Palette Modal (Cmd+K) -->
            <x-ui.command-dialog x-model="searchOpen">
                <x-ui.command>
                    <x-ui.command-input placeholder="Type a command or search slices..." />
                    <x-ui.command-list>
                        <x-ui.command-empty>No results found.</x-ui.command-empty>
                        <x-ui.command-group heading="Slices & Domains">
                            @foreach ($navSlices as $s)
                                <x-ui.command-item href="{{ $s['url'] }}">
                                    <x-dynamic-component :component="'lucide-' . $resolveLucide($s['icon'] ?? 'box')" class="size-4 mr-2" />
                                    <span>{{ $s['label'] ?? $s['title'] ?? $s['name'] ?? 'Slice' }}</span>
                                    <span class="ml-auto text-xs text-muted-foreground font-mono">{{ $s['url'] }}</span>
                                </x-ui.command-item>
                            @endforeach
                        </x-ui.command-group>
                        <x-ui.command-separator />
                        <x-ui.command-group heading="Quick Actions">
                            @if (Route::has('laraslice.wizard'))
                            <x-ui.command-item href="{{ route('laraslice.wizard') }}">
                                <x-lucide-wand-2 class="size-4 mr-2" />
                                <span>Open Slice Studio & Architecture Wizard</span>
                            </x-ui.command-item>
                            @endif
                            @if (Route::has('laraslice.wizard.schema_studio'))
                              <x-ui.command-item href="{{ route('laraslice.wizard.schema_studio') }}">
                                  <x-lucide-columns-2 class="size-4 mr-2 text-emerald-400" />
                                  <span>Schema Studio (2-Column Visual Builder)</span>
                              </x-ui.command-item>
                            @endif
                            @if (Route::has('settings.ai'))
                              <x-ui.command-item href="{{ route('settings.ai') }}">
                                  <x-lucide-sparkles class="size-4 mr-2 text-indigo-400" />
                                  <span>AI Copilot & Model Settings</span>
                              </x-ui.command-item>
                            @endif

                            <x-ui.command-item href="{{ (Route::has('dashboard') ? route('dashboard') : url('/')) }}">
                                <x-lucide-layout-dashboard class="size-4 mr-2" />
                                <span>Go to Dashboard Overview</span>
                            </x-ui.command-item>
                        </x-ui.command-group>
                    </x-ui.command-list>
                </x-ui.command>
            </x-ui.command-dialog>

            <x-ui.sonner />

            {{-- Global Double-Submit & Rapid Double-Click Protection --}}
            <script>
            (function () {
                // Prevent duplicate form submissions
                document.addEventListener('submit', function (e) {
                    var form = e.target;
                    if (!form || form.tagName !== 'FORM') return;

                    if (form.getAttribute('data-submitting') === 'true') {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return false;
                    }

                    form.setAttribute('data-submitting', 'true');

                    var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                    setTimeout(function () {
                        submitButtons.forEach(function (btn) {
                            btn.disabled = true;
                            btn.classList.add('opacity-70', 'cursor-not-allowed', 'pointer-events-none');
                        });
                    }, 20);

                    // Safety fallback: re-enable after 8 seconds if navigation did not occur
                    setTimeout(function () {
                        form.removeAttribute('data-submitting');
                        submitButtons.forEach(function (btn) {
                            btn.disabled = false;
                            btn.classList.remove('opacity-70', 'cursor-not-allowed', 'pointer-events-none');
                        });
                    }, 8000);
                }, true);

                // Re-enable buttons on bfcache page restore
                window.addEventListener('pageshow', function () {
                    document.querySelectorAll('form[data-submitting]').forEach(function (form) {
                        form.removeAttribute('data-submitting');
                        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                            btn.disabled = false;
                            btn.classList.remove('opacity-70', 'cursor-not-allowed', 'pointer-events-none');
                        });
                    });
                });
            })();
            </script>
        <x-ui.ai-copilot-bubble />
</body>
</html>
