@php
    // Kit resolution (LaraSlice\Support\Kit): the active kit
    // skins the APPLICATION (Mind design.kit / config / ?kit=);
    // platform surfaces keep the house identity. A kit is a
    // token + component-style layer over the standard markup —
    // the declarations underneath never change per kit.
    $activeKit = \LaraSlice\Support\Kit::current();
@endphp
<!DOCTYPE html>
<html class="h-full" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if($activeKit) data-kit="{{ $activeKit }}" @endif>
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
        @if($activeKit && \LaraSlice\Support\Kit::stylesheet($activeKit))
            <link rel="stylesheet" href="{{ asset(\LaraSlice\Support\Kit::stylesheet($activeKit)) }}">
        @endif
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased font-sans text-sm flex flex-col selection:bg-primary/20 selection:text-primary"
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
            $laraSliceVersion = class_exists(\LaraSlice\Support\FrameworkPackage::class)
                ? ltrim(\LaraSlice\Support\FrameworkPackage::version(), 'v')
                : (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('hereafter/laraslice')
                    ? ltrim((string) \Composer\InstalledVersions::getPrettyVersion('hereafter/laraslice'), 'v')
                    : 'dev');

            $sliceNavItem = fn (array $s) => [
                'title'    => $s['label'] ?? $s['title'] ?? $s['name'] ?? 'Slice',
                'url'      => $s['url'],
                'icon'     => $resolveLucide($s['icon'] ?? 'box'),
                'order'    => $s['order'] ?? 10,
                'isOpen'   => collect($s['children'] ?? [])->contains(fn ($c) => !empty($c['active'])),
                'isActive' => !empty($s['active']) && !collect($s['children'] ?? [])->contains(fn ($c) => !empty($c['active'])),
                'children' => array_map(fn ($c) => [
                    'title'    => $c['label'],
                    'url'      => $c['url'],
                    'isActive' => !empty($c['active']),
                ], $s['children'] ?? []),
            ];

            // Slice Studio links only for users who may open it
            $canUseStudio = Route::has('laraslice.wizard') && auth()->check()
                && \LaraSlice\Core\Security\Access::allows(auth()->user(), 'studio.access');

            // AI Copilot is a declared capability (ai.copilot.use):
            // the bubble renders only for users who may use it —
            // its endpoints enforce the same permission.
            $canUseAi = auth()->check()
                && \LaraSlice\Core\Security\Access::allows(auth()->user(), 'ai.copilot.use');

            $workspaceItems = [
                ['title' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => (Route::has('dashboard') ? route('dashboard') : url('/')), 'isActive' => request()->routeIs('dashboard')],
            ];
            if ($canUseStudio) {
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
            // Sort domains by lowest order of member slices
            uasort($navDomains, function ($a, $b) {
                $minA = min(array_map(fn ($i) => $i['order'] ?? 10, $a));
                $minB = min(array_map(fn ($i) => $i['order'] ?? 10, $b));
                return $minA <=> $minB;
            });

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
                    @if($activeKit === 'replica')
                    {{-- Replica shell: company switcher + signed-in user row (source chrome) --}}
                    <div class="flex items-center justify-between px-2 pt-1">
                        <span class="flex items-center gap-1.5 text-[15px] font-bold text-white">{{ config('app.name') }} <x-lucide-chevron-down class="size-4 opacity-70" /></span>
                        <x-lucide-layout-grid class="size-4 text-sidebar-foreground/70" />
                    </div>
                    <div class="flex items-center gap-2 px-2 pb-1 pt-2 text-[13px] text-sidebar-foreground">
                        <span class="size-2 shrink-0 rounded-full bg-emerald-500"></span>
                        <span class="truncate">{{ auth()->user()->name ?? 'Administrator' }}</span>
                    </div>
                    @else
                    <x-block.version-switcher title="LaraSlice" :versions="[$laraSliceVersion]">
                        <x-slot:icon><x-lucide-layers class="size-4" /></x-slot:icon>
                    </x-block.version-switcher>
                    <x-block.search-form placeholder="Search slices, routes..." readonly @focus="searchOpen = true; $el.blur()" @click="searchOpen = true" />
                    @endif
                </x-ui.sidebar-header>
                <x-ui.sidebar-content class="gap-0">
                    {!! \LaraSlice\Support\NavHtml::cached($navMain) !!}
                    {!! \LaraSlice\Support\NavHtml::activeScript() !!}
                </x-ui.sidebar-content>
                <x-ui.sidebar-footer>
                    @if($activeKit === 'replica')
                    {{-- Replica shell: collapse affordance + app version (source chrome) --}}
                    <div class="flex w-full items-center justify-between px-1">
                        <x-lucide-chevrons-left class="size-4 text-sidebar-foreground/60" />
                        <span class="text-[11px] text-sidebar-foreground/50">v{{ $laraSliceVersion }}</span>
                    </div>
                    @else
                    <x-block.nav-user
                        name="{{ auth()->user()->name ?? 'Administrator' }}"
                        email="{{ auth()->user()->email ?? 'admin@laraslice.dev' }}"
                        avatar=""
                        fallback="{{ substr(auth()->user()->name ?? 'Admin', 0, 2) }}" />
                    @endif
                </x-ui.sidebar-footer>
                <x-ui.sidebar-rail />
            </x-ui.sidebar>

                <x-ui.sidebar-inset>
                    {{-- Site Header --}}
                    <header class="flex h-14 shrink-0 items-center gap-2 border-b border-border px-4 lg:px-6 bg-background/95 backdrop-blur-xs sticky top-0 z-30">
                        @if($activeKit === 'replica')
                        {{-- Replica shell topbar: title + breadcrumb left, setup + utilities right (source chrome) --}}
                        <x-ui.sidebar-trigger class="-ml-1" />
                        @php
                            $crumbMatch = null; $crumbLen = -1; $reqPath = '/'.trim(request()->path(), '/');
                            foreach ($navSlices as $s) {
                                $candidates = [[$s['label'] ?? $s['title'] ?? $s['name'] ?? '', $s['url'] ?? '']];
                                foreach (($s['children'] ?? []) as $c) { $candidates[] = [$c['label'] ?? '', $c['url'] ?? '']; }
                                foreach ($candidates as [$cLabel, $cUrl]) {
                                    $u = '/'.trim((string) parse_url((string) $cUrl, PHP_URL_PATH), '/');
                                    if ($u !== '' && $u !== '/' && ($reqPath === $u || str_starts_with($reqPath, $u.'/')) && strlen($u) > $crumbLen) {
                                        $crumbMatch = $cLabel; $crumbLen = strlen($u);
                                    }
                                }
                            }
                            $pageTitle = trim($__env->yieldPushContent('page-title')) ?: $crumbMatch;
                        @endphp
                        <div class="flex min-w-0 items-baseline gap-3">
                            <span class="truncate text-[16px] font-bold text-foreground">{{ $pageTitle }}</span>
                            <nav class="hidden items-center gap-1.5 whitespace-nowrap text-[12.5px] text-muted-foreground md:flex">
                                <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:text-foreground">Home</a>
                                @if($crumbMatch)
                                <span aria-hidden="true">•</span><span>{{ $crumbMatch }}</span>
                                @if($crumbMatch !== $pageTitle)
                                <span aria-hidden="true">•</span><span class="text-foreground/80">{{ $pageTitle }}</span>
                                @endif
                                @endif
                            </nav>
                        </div>
                        <div class="ml-auto flex items-center gap-1">
                            @php $spSummary = \LaraSlice\Support\SetupProgress::summary(auth()->user()); @endphp
                            @if($spSummary)
                            <a href="{{ route('checklist') }}" class="mr-2 hidden items-center gap-2.5 lg:flex" title="Setup Progress">
                                <span class="text-[13px] text-muted-foreground">Setup Progress</span>
                                <span class="block h-1.5 w-28 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full bg-primary" style="width: {{ $spSummary['percent'] }}%"></span></span>
                                <span class="text-xs text-muted-foreground">{{ $spSummary['completed'] }}/{{ $spSummary['total'] }}</span>
                            </a>
                            @endif
                            <button type="button" @click="searchOpen = true" class="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition hover:text-foreground" title="Search">
                                <x-lucide-search class="size-5" />
                            </button>
                            <x-layout.global-timer />
                            <x-layout.quick-create />
                        </div>
                        @else
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
                            <x-layout.setup-progress />
                            <x-layout.global-timer />
                            <x-layout.quick-create />
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
                            @if ($canUseStudio)
                            <x-ui.button href="{{ route('laraslice.wizard') }}" as="a" size="sm" class="hidden sm:inline-flex gap-1.5 shadow-xs">
                                <x-lucide-wand-2 class="size-3.5" />
                                <span>Studio</span>
                            </x-ui.button>
                            @endif
                        </div>
                        @endif
                    </header>

                    {{-- Filterbar stack: directory facet bands and overview
                         hub tab strips push here (layout kit). Without this
                         stack those bands silently never render. --}}
                    <div class="sticky top-14 z-20 min-w-0">@stack('filterbar')</div>

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
                            @if ($canUseStudio)
                            <x-ui.command-item href="{{ route('laraslice.wizard') }}">
                                <x-lucide-wand-2 class="size-4 mr-2" />
                                <span>Open Slice Studio & Architecture Wizard</span>
                            </x-ui.command-item>
                            @endif
                            @if ($canUseStudio && Route::has('laraslice.wizard.schema_studio'))
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
        {{-- Print mode (?print=1): a clean document surface for
             show pages — chrome hidden, print dialog opens on
             load. The media rules also make Ctrl+P anywhere
             produce the document without the app shell. --}}
        <style>
            @media print {
                aside, header, nav, [data-print-hide] { display: none !important; }
                main { margin: 0 !important; padding: 0 !important; }
            }
        </style>
        @if (request()->boolean('print'))
            <style>
                aside, header, nav, [data-print-hide] { display: none !important; }
                main { margin: 0 !important; }
                .ls-print-bar { position: fixed; top: 0; left: 0; right: 0; z-index: 40; display: flex; gap: .75rem; align-items: center; padding: .6rem 1rem; background: #18181b; color: #fafafa; font-size: .85rem; }
                @media print { .ls-print-bar { display: none !important; } }
            </style>
            <div class="ls-print-bar">
                <span>Print view — use your browser's Print / Save as PDF to download this document.</span>
                <button type="button" onclick="window.print()" style="background:#fafafa;color:#18181b;border-radius:6px;padding:.3rem .8rem;font-weight:600;">Print / Save as PDF</button>
            </div>
            <script>
                window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); });
            </script>
        @endif
        {{-- Shared styled confirmation for destructive actions.
             window.lsConfirm(message, label) -> Promise<boolean>.
             Forms carrying data-ls-confirm, and legacy delete
             forms with onsubmit="return confirm('...')", are
             intercepted in the capture phase and re-submitted
             only when the user confirms — the styled successor
             of the native confirm, worded like the source
             systems' delete dialog. --}}
        <div x-data="{ lsMessage: '', lsLabel: 'Yes, Delete It!' }"
             @ls-confirm-ask.window="lsMessage = $event.detail.message; lsLabel = $event.detail.label || 'Yes, Delete It!'">
            <x-ui.alert-dialog id="ls-confirm">
                <x-ui.alert-dialog-content>
                    <x-ui.alert-dialog-header>
                        <x-ui.alert-dialog-title>Are you sure?</x-ui.alert-dialog-title>
                        <x-ui.alert-dialog-description>
                            <span x-text="lsMessage"></span>
                        </x-ui.alert-dialog-description>
                    </x-ui.alert-dialog-header>
                    <x-ui.alert-dialog-footer>
                        <x-ui.alert-dialog-cancel @click="window.__lsConfirmResolve && window.__lsConfirmResolve(false)">Cancel</x-ui.alert-dialog-cancel>
                        <x-ui.alert-dialog-action class="bg-rose-600 hover:bg-rose-500 text-white" @click="window.__lsConfirmResolve && window.__lsConfirmResolve(true)"><span x-text="lsLabel"></span></x-ui.alert-dialog-action>
                    </x-ui.alert-dialog-footer>
                </x-ui.alert-dialog-content>
            </x-ui.alert-dialog>
        </div>
        <script>
            (function () {
                window.lsConfirm = function (message, label) {
                    return new Promise(function (resolve) {
                        window.__lsConfirmResolve = resolve;
                        window.dispatchEvent(new CustomEvent('ls-confirm-ask', {
                            detail: {
                                message: message || 'You will not be able to recover the deleted record!',
                                label: label || 'Yes, Delete It!'
                            }
                        }));
                        window.dispatchEvent(new CustomEvent('open-alert-dialog-ls-confirm'));
                    });
                };
                document.addEventListener('submit', function (event) {
                    var form = event.target;
                    if (!(form instanceof HTMLFormElement)) { return; }
                    if (form.dataset.lsConfirmed === '1') { return; }
                    var message = form.getAttribute('data-ls-confirm');
                    if (message === null) {
                        var onsubmit = form.getAttribute('onsubmit') || '';
                        var match = onsubmit.match(/confirm\(\s*'([^']*)'\s*\)/) || onsubmit.match(/confirm\(\s*"([^"]*)"\s*\)/);
                        if (!match) { return; }
                        message = match[1];
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    window.lsConfirm(message, form.getAttribute('data-ls-confirm-label') || undefined).then(function (ok) {
                        if (ok) {
                            form.dataset.lsConfirmed = '1';
                            form.submit();
                        }
                    });
                }, true);
            })();
        </script>
        @if ($canUseAi)
            <x-ui.ai-copilot-bubble />
        @endif
</body>
</html>
