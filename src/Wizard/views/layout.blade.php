<!DOCTYPE html>
<html class="h-full scroll-smooth" dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LaraSlice Studio</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.17.4/dist/cdn.min.js" integrity="sha384-5/joNqFnRyVWzXp99bHot6RHG+EksGp+USSgZwPar7T9SD9PKKER37n/8bXBAZGd" crossorigin="anonymous"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            DEFAULT: '#f59e0b',
                            foreground: '#09090b',
                        },
                        secondary: {
                            DEFAULT: '#27272a',
                            foreground: '#fafafa',
                        },
                        background: '#09090b',
                        foreground: '#fafafa',
                        card: '#18181b',
                        border: '#27272a',
                        muted: {
                            DEFAULT: '#27272a',
                            foreground: '#a1a1aa',
                        },
                        destructive: {
                            DEFAULT: '#ef4444',
                            foreground: '#fafafa',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        code, pre, .font-mono { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="min-h-screen bg-background text-foreground antialiased flex flex-col selection:bg-primary/20 selection:text-primary">

    <!-- Top Navigation Header -->
    <header class="sticky top-0 z-40 w-full bg-card/80 backdrop-blur-md border-b border-border">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ url('/') }}" class="flex items-center gap-2.5 group">
                    <div class="size-9 rounded-xl bg-primary text-primary-foreground font-black text-base flex items-center justify-center shadow-md">
                        LS
                    </div>
                    <div>
                        <div class="font-extrabold text-foreground tracking-tight text-sm flex items-center gap-2">
                            <span>LaraSlice Studio</span>
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-primary/15 text-primary border border-primary/20 font-bold">v1.0</span>
                        </div>
                    </div>
                </a>
            </div>

            <div class="flex items-center gap-4 text-xs font-semibold">
                <a href="{{ url('/') }}" class="text-muted-foreground hover:text-foreground transition">Home</a>
                <a href="{{ route('laraslice.wizard') }}" class="{{ request()->routeIs('laraslice.wizard') && !request()->routeIs('laraslice.wizard.*') ? 'text-primary font-bold underline' : 'text-muted-foreground hover:text-foreground transition' }}">Wizard</a>
                <a href="{{ route('laraslice.wizard.blueprint') }}" class="{{ request()->routeIs('laraslice.wizard.blueprint*') ? 'text-primary font-bold underline' : 'text-muted-foreground hover:text-foreground transition' }}">Blueprint Studio</a>
                <a href="{{ route('laraslice.wizard.schema_studio') }}" class="{{ request()->routeIs('laraslice.wizard.schema_studio') ? 'text-primary font-bold underline' : 'text-muted-foreground hover:text-foreground transition' }} flex items-center gap-1.5">
                    <span>Schema Studio</span>
                    <span class="px-1.5 py-0.2 rounded bg-emerald-500/20 text-emerald-400 text-[9px] font-mono font-bold">2-Col</span>
                </a>
                <a href="{{ route('settings.ai') }}" class="text-muted-foreground hover:text-foreground transition flex items-center gap-1">
                    <span>AI Settings</span>
                </a>
                @if(Route::has('login'))
                    @auth
                        <span class="text-muted-foreground font-mono text-[11px]">{{ auth()->user()->email }}</span>
                    @else
                        <a href="{{ route('login') }}" class="px-3 py-1.5 rounded-lg bg-primary text-primary-foreground font-bold text-xs">Login</a>
                    @endauth
                @endif
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8">
        @yield('content')
    </main>

    @include('laraslice-ui::ai-copilot-bubble')
</body>
</html>
