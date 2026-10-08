<!DOCTYPE html>
<html class="h-full scroll-smooth" dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LaraSlice — Autonomous Vertical Slice Architecture for Laravel 13+ & Flutter</title>
    <meta name="description" content="Generate enterprise-grade Vertical Slices via Visual Studio UI, Artisan CLI, or Autonomous AI Prompts. Pure BlatUI tokens, typed DTOs, domain RBAC, and zero-lock-in.">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

    <!-- Optional host layout head partial if present -->
    @includeIf('layouts.partials.head')

    <!-- Standalone Tailwind CSS CDN & BlatUI Theme Config -->
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        border: 'hsl(var(--border))',
                        input: 'hsl(var(--input))',
                        ring: 'hsl(var(--ring))',
                        background: 'hsl(var(--background))',
                        foreground: 'hsl(var(--foreground))',
                        primary: {
                            DEFAULT: 'hsl(var(--primary))',
                            foreground: 'hsl(var(--primary-foreground))',
                        },
                        secondary: {
                            DEFAULT: 'hsl(var(--secondary))',
                            foreground: 'hsl(var(--secondary-foreground))',
                        },
                        destructive: {
                            DEFAULT: 'hsl(var(--destructive))',
                            foreground: 'hsl(var(--destructive-foreground))',
                        },
                        muted: {
                            DEFAULT: 'hsl(var(--muted))',
                            foreground: 'hsl(var(--muted-foreground))',
                        },
                        accent: {
                            DEFAULT: 'hsl(var(--accent))',
                            foreground: 'hsl(var(--accent-foreground))',
                        },
                        card: {
                            DEFAULT: 'hsl(var(--card))',
                            foreground: 'hsl(var(--card-foreground))',
                        },
                    }
                }
            }
        };
    </script>

    <!-- Alpine.js CDN for interactive tabs & FAQs -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.17.4/dist/cdn.min.js" integrity="sha384-5/joNqFnRyVWzXp99bHot6RHG+EksGp+USSgZwPar7T9SD9PKKER37n/8bXBAZGd" crossorigin="anonymous"></script>

    <!-- Design Tokens (BlatUI standard: Light & Dark) -->
    <style>
        :root {
            --background: 0 0% 100%;
            --foreground: 240 10% 3.9%;
            --card: 0 0% 100%;
            --card-foreground: 240 10% 3.9%;
            --primary: 24.6 95% 53.1%;
            --primary-foreground: 0 0% 98%;
            --secondary: 240 4.8% 95.9%;
            --secondary-foreground: 240 5.9% 10%;
            --muted: 240 4.8% 95.9%;
            --muted-foreground: 240 3.8% 46.1%;
            --accent: 240 4.8% 95.9%;
            --accent-foreground: 240 5.9% 10%;
            --destructive: 0 84.2% 60.2%;
            --destructive-foreground: 0 0% 98%;
            --border: 240 5.9% 90%;
            --input: 240 5.9% 90%;
            --ring: 24.6 95% 53.1%;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .dark {
            --background: 240 10% 3.9%;
            --foreground: 0 0% 98%;
            --card: 240 10% 4.9%;
            --card-foreground: 0 0% 98%;
            --primary: 24.6 95% 53.1%;
            --primary-foreground: 0 0% 98%;
            --secondary: 240 3.7% 15.9%;
            --secondary-foreground: 0 0% 98%;
            --muted: 240 3.7% 15.9%;
            --muted-foreground: 240 5% 64.9%;
            --accent: 240 3.7% 15.9%;
            --accent-foreground: 0 0% 98%;
            --destructive: 0 62.8% 30.6%;
            --destructive-foreground: 0 0% 98%;
            --border: 240 3.7% 15.9%;
            --input: 240 3.7% 15.9%;
            --ring: 24.6 95% 53.1%;
        }
    </style>

    <script>
        // Alpine theme store registration
        document.addEventListener('alpine:init', () => {
            Alpine.store('theme', {
                isDark: localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches),
                toggle() {
                    this.isDark = !this.isDark;
                    localStorage.setItem('theme', this.isDark ? 'dark' : 'light');
                    this.apply();
                },
                apply() {
                    if (this.isDark) {
                        document.documentElement.classList.add('dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                    }
                },
                init() {
                    this.apply();
                }
            });
        });

        // Instant inline theme restorer before paint
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    @if(file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-background text-foreground antialiased font-sans selection:bg-primary/20 selection:text-primary flex flex-col relative overflow-x-hidden"
      x-data="{
          genTab: 'cli',
          anatomyTab: 'manifest',
          faqOpen: null
      }">

    <!-- Ambient Glowing Background Gradient Mesh -->
    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full max-w-7xl h-[650px] pointer-events-none opacity-40 blur-3xl -z-10 overflow-hidden">
        <div class="w-[850px] h-[450px] bg-gradient-to-tr from-primary/35 via-violet-500/20 to-transparent rounded-full mx-auto transform -translate-y-1/2"></div>
    </div>

    <!-- Top Navigation -->
    <nav class="sticky top-0 z-40 w-full bg-background/80 backdrop-blur-md border-b border-border">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-18 flex items-center justify-between">
            <!-- Brand Logo -->
            <a href="/" class="flex items-center gap-3 group">
                <div class="size-10 rounded-xl bg-primary text-primary-foreground font-black text-lg flex items-center justify-center shadow-md shadow-primary/20 group-hover:scale-105 transition-transform">
                    LS
                </div>
                <div>
                    <div class="font-extrabold text-foreground tracking-tight text-lg flex items-center gap-2">
                        <span>LaraSlice</span>
                        <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-primary/15 text-primary border border-primary/20 font-bold">v1.0</span>
                    </div>
                    <div class="text-[11px] text-muted-foreground font-medium -mt-0.5">Vertical Slice Architecture for Laravel 13+</div>
                </div>
            </a>

            <!-- Navigation Links -->
            <div class="hidden md:flex items-center gap-6 text-xs font-semibold text-muted-foreground">
                <a href="#generation" class="hover:text-foreground transition-colors">3 Generation Engines</a>
                <a href="#comparison" class="hover:text-foreground transition-colors">Architecture</a>
                <a href="#anatomy" class="hover:text-foreground transition-colors">Slice Anatomy</a>
                <a href="#faq" class="hover:text-foreground transition-colors">Developer FAQ</a>
                <a href="{{ Route::has('laraslice.wizard') ? route('laraslice.wizard') : url('/laraslice/wizard') }}" class="text-primary hover:underline font-bold flex items-center gap-1">
                    <span>⚡</span> Blueprint Studio
                </a>
            </div>

            <!-- Right Actions -->
            <div class="flex items-center gap-3">
                <!-- Theme Studio Link -->
                <a href="{{ Route::has('settings.theme') ? route('settings.theme') : url('/admin/settings/theme') }}" class="hidden sm:inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-border bg-card hover:bg-muted text-foreground text-xs font-semibold transition shadow-2xs">
                    <span>🎨</span>
                    <span>Theme Studio</span>
                </a>

                <!-- Dark/Light Mode Toggle -->
                <button type="button" @click="$store.theme.toggle()" class="size-9 rounded-xl border border-border bg-card hover:bg-muted text-foreground flex items-center justify-center text-xs transition shadow-2xs cursor-pointer" title="Toggle Mode">
                    <span x-show="!$store.theme.isDark">☀️</span>
                    <span x-show="$store.theme.isDark">🌙</span>
                </button>

                <!-- Dashboard / Portal Button -->
                <a href="{{ Route::has('dashboard') ? route('dashboard') : (Route::has('login') ? route('login') : (Route::has('laraslice.wizard') ? route('laraslice.wizard') : url('/laraslice/wizard'))) }}" class="px-4 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-bold hover:opacity-90 transition shadow-md shadow-primary/20 flex items-center gap-1.5">
                    <span>Dashboard</span>
                    <span>→</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="relative pt-16 pb-14 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto text-center">
        <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-primary/10 border border-primary/20 text-primary text-xs font-semibold mb-6">
            <span>✨</span>
            <span>Enterprise Vertical Slice Architecture for Laravel 13+ & Flutter</span>
            <span class="size-1 rounded-full bg-primary"></span>
            <span class="text-muted-foreground font-normal">Zero Vendor Lock-in</span>
        </div>

        <h1 class="text-4xl sm:text-6xl lg:text-7xl font-black text-foreground tracking-tight leading-[1.08] mb-6">
            Stop building monoliths.<br>
            <span class="text-primary bg-gradient-to-r from-primary via-orange-500 to-amber-500 bg-clip-text text-transparent">
                Scale with Vertical Slices.
            </span>
        </h1>

        <p class="text-base sm:text-xl text-muted-foreground max-w-3xl mx-auto leading-relaxed mb-10">
            LaraSlice replaces scattered controllers, models, and migrations with cohesive, autonomous domain slices. 
            Generate enterprise modules via <strong>Visual Studio UI</strong>, <strong>Artisan CLI</strong>, or <strong>Autonomous AI prompts</strong> — complete with typed DTO contracts, native RBAC, compliance audit trails, and Flutter exports.
        </p>

        <!-- CTA Buttons -->
        <div class="flex flex-wrap items-center justify-center gap-4 mb-14">
            <a href="{{ Route::has('laraslice.wizard') ? route('laraslice.wizard') : url('/laraslice/wizard') }}" class="px-6 py-3.5 rounded-2xl bg-primary text-primary-foreground font-bold text-sm hover:opacity-90 transition shadow-xl shadow-primary/25 flex items-center gap-2">
                <span>⚡</span>
                <span>Open Blueprint Studio</span>
            </a>
            <a href="{{ Route::has('settings.theme') ? route('settings.theme') : url('/admin/settings/theme') }}" class="px-6 py-3.5 rounded-2xl bg-card border border-border text-foreground font-bold text-sm hover:bg-muted transition shadow-sm flex items-center gap-2">
                <span>🎨</span>
                <span>Theme Editor</span>
            </a>
            <a href="https://github.com/hereaftersol/laraslice" target="_blank" class="px-6 py-3.5 rounded-2xl bg-muted border border-border text-foreground font-bold text-sm hover:bg-muted/80 transition flex items-center gap-2">
                <span>GitHub Repo</span>
                <span>↗</span>
            </a>
        </div>

        <!-- Metric Badges -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-3xl mx-auto text-left">
            <div class="p-4 rounded-2xl bg-card border border-border">
                <div class="text-2xl font-black text-primary mb-0.5">3 Ways</div>
                <div class="text-xs text-muted-foreground font-medium">UI, CLI, or AI Prompts</div>
            </div>
            <div class="p-4 rounded-2xl bg-card border border-border">
                <div class="text-2xl font-black text-primary mb-0.5">100%</div>
                <div class="text-xs text-muted-foreground font-medium">Pure BlatUI + Tailwind v4</div>
            </div>
            <div class="p-4 rounded-2xl bg-card border border-border">
                <div class="text-2xl font-black text-primary mb-0.5">0 Lock-In</div>
                <div class="text-xs text-muted-foreground font-medium">Runs as Pure Laravel</div>
            </div>
            <div class="p-4 rounded-2xl bg-card border border-border">
                <div class="text-2xl font-black text-primary mb-0.5">1-Click</div>
                <div class="text-xs text-muted-foreground font-medium">Flutter Mobile Export</div>
            </div>
        </div>
    </header>

    <!-- SECTION 1: 3 GENERATION ENGINES (UI / CLI / AI) -->
    <section id="generation" class="py-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto border-t border-border">
        <div class="text-center max-w-3xl mx-auto mb-14">
            <div class="text-xs font-mono font-bold text-primary uppercase tracking-widest mb-2">Generation Superpowers</div>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-foreground tracking-tight mb-3">
                Three Ways to Build. Infinite Velocity.
            </h2>
            <p class="text-sm text-muted-foreground">
                Visual schema builder for architects, blazing artisan commands for developers, or autonomous prompt generation for AI-native teams.
            </p>
        </div>

        <!-- 3 Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
            <!-- Card 1: Visual Studio UI -->
            <div class="p-6 rounded-3xl bg-card border border-border hover:border-primary/50 transition group flex flex-col justify-between shadow-xs">
                <div class="space-y-4">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-500 text-xs font-semibold mb-4">
                <span>⚠️ Requirement:</span>
                <span class="text-foreground">Requires an existing Laravel 11, 12, or 13 application</span>
            </div>
                    <div class="size-12 rounded-2xl bg-primary/10 text-primary flex items-center justify-center text-xl font-bold">
                        🖥️
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-foreground mb-1 flex items-center gap-2">
                            <span>Visual Studio UI</span>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-primary/15 text-primary font-mono font-bold">Web GUI</span>
                        </h3>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Interactive Blueprint Studio & Wizard. Design multi-table aggregates, field types, validations, and parent-child entity trees with live directory previews.
                        </p>
                    </div>
                    <ul class="text-xs text-muted-foreground space-y-1.5 font-medium">
                        <li class="flex items-center gap-2 text-foreground">✓ High-density Statamic-style field grid</li>
                        <li class="flex items-center gap-2 text-foreground">✓ Aggregate root child entity manager</li>
                        <li class="flex items-center gap-2 text-foreground">✓ Real-time LaraSlice directory tree</li>
                    </ul>
                </div>
                <div class="pt-6 border-t border-border mt-6">
                    <a href="{{ Route::has('laraslice.wizard') ? route('laraslice.wizard') : url('/laraslice/wizard') }}" class="text-xs font-bold text-primary hover:underline flex items-center gap-1">
                        <span>Launch Studio</span>
                        <span>→</span>
                    </a>
                </div>
            </div>

            <!-- Card 2: Developer CLI -->
            <div class="p-6 rounded-3xl bg-card border border-border hover:border-blue-500/50 transition group flex flex-col justify-between shadow-xs">
                <div class="space-y-4">
                    <div class="size-12 rounded-2xl bg-blue-500/10 text-blue-500 flex items-center justify-center text-xl font-bold">
                        ⚡
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-foreground mb-1 flex items-center gap-2">
                            <span>Developer CLI</span>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-500/15 text-blue-600 dark:text-blue-400 font-mono font-bold">Artisan</span>
                        </h3>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            One artisan command generates complete vertical slice architectures in under a second — including models, DTOs, controllers, routes, views, and migrations.
                        </p>
                    </div>
                    <ul class="text-xs text-muted-foreground space-y-1.5 font-medium">
                        <li class="flex items-center gap-2 text-foreground">✓ <code>slice:make &lt;Name&gt; --workflow</code></li>
                        <li class="flex items-center gap-2 text-foreground">✓ <code>blueprint:apply &lt;file.yaml&gt;</code></li>
                        <li class="flex items-center gap-2 text-foreground">✓ <code>slice:make &lt;Name&gt; --flutter</code></li>
                    </ul>
                </div>
                <div class="pt-6 border-t border-border mt-6">
                    <span class="text-[11px] font-mono text-muted-foreground">php artisan slice:make Order</span>
                </div>
            </div>

            <!-- Card 3: Autonomous AI Prompts -->
            <div class="p-6 rounded-3xl bg-card border border-border hover:border-emerald-500/50 transition group flex flex-col justify-between shadow-xs">
                <div class="space-y-4">
                    <div class="size-12 rounded-2xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-xl font-bold">
                        🤖
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-foreground mb-1 flex items-center gap-2">
                            <span>Autonomous AI Engine</span>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 font-mono font-bold">AI-Native</span>
                        </h3>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            Natural language to production slices. LaraSlice provides Antigravity & Cursor skills that generate typed schemas directly from specs and user stories.
                        </p>
                    </div>
                    <ul class="text-xs text-muted-foreground space-y-1.5 font-medium">
                        <li class="flex items-center gap-2 text-foreground">✓ Jev System One typed decision engine</li>
                        <li class="flex items-center gap-2 text-foreground">✓ LaraSlice Skill prompt integration</li>
                        <li class="flex items-center gap-2 text-foreground">✓ Autonomous test and schema generation</li>
                    </ul>
                </div>
                <div class="pt-6 border-t border-border mt-6">
                    <span class="text-[11px] font-mono text-emerald-500">"Create Order slice with Stripe..."</span>
                </div>
            </div>
        </div>

        <!-- Interactive Terminal Simulator -->
        <div class="bg-card border border-border rounded-3xl p-6 shadow-xl">
            <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-border">
                <div class="flex items-center gap-2">
                    <span class="size-3 rounded-full bg-destructive/80"></span>
                    <span class="size-3 rounded-full bg-amber-500/80"></span>
                    <span class="size-3 rounded-full bg-emerald-500/80"></span>
                    <span class="text-xs font-mono text-muted-foreground ml-2">laraslice-terminal</span>
                </div>
                <!-- Interactive Tabs -->
                <div class="flex items-center gap-1.5 bg-muted/60 p-1 rounded-xl border border-border text-xs">
                    <button type="button" @click="genTab = 'cli'" :class="genTab === 'cli' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1 rounded-lg transition cursor-pointer">
                        Artisan CLI
                    </button>
                    <button type="button" @click="genTab = 'ai'" :class="genTab === 'ai' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1 rounded-lg transition cursor-pointer">
                        AI Prompt
                    </button>
                    <button type="button" @click="genTab = 'blueprint'" :class="genTab === 'blueprint' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1 rounded-lg transition cursor-pointer">
                        Blueprint YAML
                    </button>
                    <button type="button" @click="genTab = 'flutter'" :class="genTab === 'flutter' ? 'bg-primary text-primary-foreground font-bold' : 'text-muted-foreground hover:text-foreground'" class="px-3 py-1 rounded-lg transition cursor-pointer">
                        Flutter Export
                    </button>
                </div>
            </div>

            <!-- Terminal Output Area -->
            <div class="p-6 text-xs sm:text-sm font-mono overflow-x-auto min-h-[220px] bg-muted/30 rounded-2xl mt-4">
                <!-- Tab: CLI -->
                <div x-show="genTab === 'cli'" class="space-y-1.5">
                    <div class="text-muted-foreground">$ php artisan slice:make Order --workflow --field=order_number:string --field=total:decimal</div>
                    <div class="text-primary font-bold">⚡ Scaffolding Vertical Slice: Order...</div>
                    <div class="text-emerald-500">✓ Created Slice Manifest: app/Slices/Orders/slice.json</div>
                    <div class="text-emerald-500">✓ Created Contracts (DTOs): OrderFormObject, OrderListingObject, OrderFilter</div>
                    <div class="text-emerald-500">✓ Created Model & Migration: Order.php, create_orders_table.php</div>
                    <div class="text-emerald-500">✓ Created Data Service: OrderSliceService.php (with CRUD lifecycle hooks)</div>
                    <div class="text-emerald-500">✓ Created Controllers & Routes: OrderWebController, OrderApiController</div>
                    <div class="text-emerald-500">✓ Created BlatUI Views: index.blade.php, form.blade.php</div>
                    <div class="text-emerald-500 font-bold pt-1">🎉 Slice [Order] generated successfully! Run 'php artisan migrate'.</div>
                </div>

                <!-- Tab: AI -->
                <div x-show="genTab === 'ai'" class="space-y-1.5" style="display: none;">
                    <div class="text-muted-foreground">Prompt: "Build an E-Commerce Order slice with items, status workflow, and stripe payments"</div>
                    <div class="text-violet-500 font-bold">🤖 LaraSlice AI Copilot resolving schema...</div>
                    <div class="text-foreground">↳ Detected Aggregate Root: Order with child entity OrderItem (One-to-Many)</div>
                    <div class="text-foreground">↳ Injected State Machine: draft ➔ pending ➔ processing ➔ completed ➔ cancelled</div>
                    <div class="text-foreground">↳ Added AuditableSlice trait with SOC2-compliant diff logging</div>
                    <div class="text-emerald-500 font-bold pt-1">✓ Autonomous slice compiled to app/Slices/Orders/ with 100% test coverage.</div>
                </div>

                <!-- Tab: Blueprint -->
                <div x-show="genTab === 'blueprint'" class="space-y-1.5" style="display: none;">
                    <div class="text-muted-foreground">$ php artisan blueprint:apply blueprints/ecommerce.slice.yaml</div>
                    <div class="text-blue-500 font-bold">📖 Reading declarative blueprint: blueprints/ecommerce.slice.yaml...</div>
                    <div class="text-foreground">↳ Validating domain boundary [Shop] with 3 cohesive slices...</div>
                    <div class="text-emerald-500">✓ Applied Slice [Shop/Products] (12 fields, inventory tracking)</div>
                    <div class="text-emerald-500">✓ Applied Slice [Shop/Categories] (hierarchical nesting)</div>
                    <div class="text-emerald-500">✓ Applied Slice [Shop/Orders] (multi-table aggregate root)</div>
                    <div class="text-emerald-500 font-bold pt-1">✓ 3 slices synchronized with zero database drifts.</div>
                </div>

                <!-- Tab: Flutter -->
                <div x-show="genTab === 'flutter'" class="space-y-1.5" style="display: none;">
                    <div class="text-muted-foreground">$ php artisan slice:make Invoice --flutter</div>
                    <div class="text-cyan-500 font-bold">📱 Generating Laravel Backend & Flutter Client Slice...</div>
                    <div class="text-emerald-500">✓ Laravel Backend Slice: app/Slices/Invoices/</div>
                    <div class="text-cyan-500">✓ Flutter Model: lib/slices/invoices/models/invoice_model.dart</div>
                    <div class="text-cyan-500">✓ Flutter API Client: lib/slices/invoices/services/invoice_api_service.dart</div>
                    <div class="text-cyan-500">✓ Flutter UI Screen: lib/slices/invoices/screens/invoice_list_screen.dart</div>
                    <div class="text-emerald-500 font-bold pt-1">🎉 Full-stack cross-platform slice ready!</div>
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION 2: ARCHITECTURAL COMPARISON (TRADITIONAL VS LARASLICE) -->
    <section id="comparison" class="py-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto border-t border-border">
        <div class="text-center max-w-3xl mx-auto mb-14">
            <div class="text-xs font-mono font-bold text-primary uppercase tracking-widest mb-2">Architectural Paradigm</div>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-foreground tracking-tight mb-3">
                Monolith Hell vs. Vertical Slice Clarity
            </h2>
            <p class="text-sm text-muted-foreground">
                In traditional layered Laravel, a single feature scatters code across 10 folders. LaraSlice groups all cohesive business logic into one autonomous bounded context.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
            <!-- Left: Traditional Layered -->
            <div class="p-6 rounded-3xl bg-destructive/5 border border-destructive/20 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-destructive/20">
                    <span class="text-sm font-bold text-destructive">❌ Traditional Layered Laravel (Scattered)</span>
                    <span class="text-[11px] font-mono text-destructive/80">Coupled & Fragile</span>
                </div>
                <div class="text-xs font-mono space-y-1 text-muted-foreground leading-relaxed">
                    <div>app/Http/Controllers/OrderController.php</div>
                    <div>app/Http/Requests/StoreOrderRequest.php</div>
                    <div>app/Models/Order.php</div>
                    <div>app/Models/OrderItem.php</div>
                    <div>app/Services/OrderService.php</div>
                    <div>database/migrations/2026_create_orders_table.php</div>
                    <div>resources/views/orders/index.blade.php</div>
                    <div>resources/views/orders/create.blade.php</div>
                    <div>routes/web.php (contains 100+ random routes)</div>
                </div>
                <p class="text-xs text-destructive/80 pt-2 border-t border-destructive/20">
                    ⚠️ Changing one field requires editing 8 different folders. Deleting a feature leaves orphaned migrations, routes, and views behind.
                </p>
            </div>

            <!-- Right: LaraSlice Vertical Slice -->
            <div class="p-6 rounded-3xl bg-primary/5 border border-primary/30 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-primary/20">
                    <span class="text-sm font-bold text-primary">✅ LaraSlice Vertical Slice (Bounded Context)</span>
                    <span class="text-[11px] font-mono text-emerald-500 font-bold">100% Encapsulated</span>
                </div>
                <div class="text-xs font-mono space-y-1 text-foreground leading-relaxed">
                    <div class="text-primary font-bold">app/Slices/Orders/</div>
                    <div class="pl-4">├── slice.json <span class="text-muted-foreground">(Declarative Manifest)</span></div>
                    <div class="pl-4">├── Contracts/ <span class="text-muted-foreground">(Typed DTOs - Form, Listing, Filter)</span></div>
                    <div class="pl-4">├── Models/ <span class="text-muted-foreground">(Order.php & OrderItem.php)</span></div>
                    <div class="pl-4">├── Services/ <span class="text-muted-foreground">(OrderSliceService.php lifecycle hooks)</span></div>
                    <div class="pl-4">├── Http/ <span class="text-muted-foreground">(OrderWebController & OrderApiController)</span></div>
                    <div class="pl-4">├── Resources/views/ <span class="text-muted-foreground">(Pure BlatUI Blade templates)</span></div>
                    <div class="pl-4">└── Routes/ <span class="text-muted-foreground">(web.php & api.php self-registered)</span></div>
                </div>
                <p class="text-xs text-emerald-600 dark:text-emerald-400 pt-2 border-t border-primary/20">
                    💎 Everything about the feature lives in one directory. Slices can be tested in total isolation, published as packages, or safely deleted in 1 click.
                </p>
            </div>
        </div>
    </section>

    <!-- SECTION 3: SLICE ANATOMY EXPLORER -->
    <section id="anatomy" class="py-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto border-t border-border">
        <div class="text-center max-w-3xl mx-auto mb-14">
            <div class="text-xs font-mono font-bold text-primary uppercase tracking-widest mb-2">Code Standard</div>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-foreground tracking-tight mb-3">
                Anatomy of a LaraSlice
            </h2>
            <p class="text-sm text-muted-foreground">
                Explore the clean, typed PHP and Blade code generated by the engine. Zero magic, zero bloat — just pure, scalable architectural standards.
            </p>
        </div>

        <div class="bg-card border border-border rounded-3xl overflow-hidden shadow-xl">
            <!-- File Tabs -->
            <div class="flex items-center gap-1 bg-muted/50 px-4 py-3 border-b border-border overflow-x-auto text-xs font-mono">
                <button type="button" @click="anatomyTab = 'manifest'" :class="anatomyTab === 'manifest' ? 'bg-card text-primary font-bold border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground'" class="px-4 py-2 rounded-t-lg transition">
                    slice.json
                </button>
                <button type="button" @click="anatomyTab = 'dto'" :class="anatomyTab === 'dto' ? 'bg-card text-primary font-bold border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground'" class="px-4 py-2 rounded-t-lg transition">
                    Contracts/OrderFormObject.php
                </button>
                <button type="button" @click="anatomyTab = 'service'" :class="anatomyTab === 'service' ? 'bg-card text-primary font-bold border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground'" class="px-4 py-2 rounded-t-lg transition">
                    Services/OrderSliceService.php
                </button>
                <button type="button" @click="anatomyTab = 'blade'" :class="anatomyTab === 'blade' ? 'bg-card text-primary font-bold border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground'" class="px-4 py-2 rounded-t-lg transition">
                    Resources/views/index.blade.php
                </button>
            </div>

            <!-- Code Blocks -->
            <div class="p-6 text-xs sm:text-sm font-mono overflow-x-auto bg-card leading-relaxed">
                <!-- manifest -->
                <div x-show="anatomyTab === 'manifest'">
<pre class="text-foreground"><span class="text-muted-foreground">// app/Slices/Orders/slice.json</span>
{
    <span class="text-primary font-bold">"name"</span>: <span class="text-emerald-500">"Orders"</span>,
    <span class="text-primary font-bold">"domain"</span>: <span class="text-emerald-500">"Shop"</span>,
    <span class="text-primary font-bold">"version"</span>: <span class="text-emerald-500">"1.0.0"</span>,
    <span class="text-primary font-bold">"root_table"</span>: <span class="text-emerald-500">"orders"</span>,
    <span class="text-primary font-bold">"aggregate_children"</span>: [<span class="text-emerald-500">"order_items"</span>, <span class="text-emerald-500">"order_shipments"</span>],
    <span class="text-primary font-bold">"capabilities"</span>: [<span class="text-emerald-500">"view"</span>, <span class="text-emerald-500">"create"</span>, <span class="text-emerald-500">"edit"</span>, <span class="text-emerald-500">"delete"</span>, <span class="text-emerald-500">"export"</span>],
    <span class="text-primary font-bold">"auditable"</span>: <span class="text-blue-500">true</span>,
    <span class="text-primary font-bold">"workflow"</span>: {
        <span class="text-primary font-bold">"states"</span>: [<span class="text-emerald-500">"draft"</span>, <span class="text-emerald-500">"pending"</span>, <span class="text-emerald-500">"completed"</span>, <span class="text-emerald-500">"cancelled"</span>]
    }
}</pre>
                </div>

                <!-- DTO -->
                <div x-show="anatomyTab === 'dto'" style="display: none;">
<pre class="text-foreground"><span class="text-muted-foreground">// app/Slices/Orders/Contracts/OrderFormObject.php</span>
<span class="text-violet-500">namespace</span> App\Slices\Orders\Contracts;

<span class="text-violet-500">readonly class</span> <span class="text-primary font-bold">OrderFormObject</span>
{
    <span class="text-violet-500">public function</span> <span class="text-blue-500">__construct</span>(
        <span class="text-violet-500">public string</span> <span class="text-foreground">$orderNumber</span>,
        <span class="text-violet-500">public int</span> <span class="text-foreground">$customerId</span>,
        <span class="text-violet-500">public float</span> <span class="text-foreground">$totalAmount</span>,
        <span class="text-violet-500">public string</span> <span class="text-foreground">$status</span> = <span class="text-emerald-500">'draft'</span>,
        <span class="text-violet-500">public array</span> <span class="text-foreground">$items</span> = [],
    ) {}

    <span class="text-violet-500">public static function</span> <span class="text-blue-500">fromRequest</span>(Request <span class="text-foreground">$request</span>): <span class="text-primary font-bold">self</span>
    {
        <span class="text-violet-500">return new self</span>(
            orderNumber: <span class="text-foreground">$request</span>->validated(<span class="text-emerald-500">'order_number'</span>),
            customerId: <span class="text-foreground">$request</span>->validated(<span class="text-emerald-500">'customer_id'</span>),
            totalAmount: (float) <span class="text-foreground">$request</span>->validated(<span class="text-emerald-500">'total_amount'</span>),
            status: <span class="text-foreground">$request</span>->validated(<span class="text-emerald-500">'status'</span>, <span class="text-emerald-500">'draft'</span>),
            items: <span class="text-foreground">$request</span>->validated(<span class="text-emerald-500">'items'</span>, []),
        );
    }
}</pre>
                </div>

                <!-- Service -->
                <div x-show="anatomyTab === 'service'" style="display: none;">
<pre class="text-foreground"><span class="text-muted-foreground">// app/Slices/Orders/Services/OrderSliceService.php</span>
<span class="text-violet-500">namespace</span> App\Slices\Orders\Services;

<span class="text-violet-500">use</span> LaraSlice\Core\Base\BaseSliceService;
<span class="text-violet-500">use</span> LaraSlice\Core\Audit\Traits\AuditableSlice;

<span class="text-violet-500">class</span> <span class="text-primary font-bold">OrderSliceService</span> <span class="text-violet-500">extends</span> <span class="text-blue-500">BaseSliceService</span>
{
    <span class="text-violet-500">use</span> <span class="text-blue-500">AuditableSlice</span>;

    <span class="text-violet-500">public function</span> <span class="text-blue-500">afterCreated</span>(Model <span class="text-foreground">$order</span>, array <span class="text-foreground">$data</span>): <span class="text-violet-500">void</span>
    {
        <span class="text-muted-foreground">// Automated audit trail, notification dispatch, and child items sync</span>
        <span class="text-foreground">$this</span>->syncChildEntities(<span class="text-foreground">$order</span>, <span class="text-emerald-500">'items'</span>, <span class="text-foreground">$data</span>[<span class="text-emerald-500">'items'</span>] ?? []);
        <span class="text-foreground">$this</span>->logAudit(<span class="text-emerald-500">'order.created'</span>, <span class="text-foreground">$order</span>);
    }
}</pre>
                </div>

                <!-- Blade -->
                <div x-show="anatomyTab === 'blade'" style="display: none;">
<pre class="text-foreground"><span class="text-muted-foreground">{{-- app/Slices/Orders/Resources/views/index.blade.php --}}</span>
&#64;extends('layouts.app')

&#64;section('content')
&lt;<span class="text-violet-500">div</span> class=<span class="text-emerald-500">"space-y-6"</span>&gt;
    &lt;<span class="text-violet-500">x-ui.table</span> :items=<span class="text-emerald-500">"$orders"</span>&gt;
        &lt;<span class="text-violet-500">x-ui.table-column</span> label=<span class="text-emerald-500">"Order #"</span> field=<span class="text-emerald-500">"order_number"</span> /&gt;
        &lt;<span class="text-violet-500">x-ui.table-column</span> label=<span class="text-emerald-500">"Customer"</span> field=<span class="text-emerald-500">"customer.name"</span> /&gt;
        &lt;<span class="text-violet-500">x-ui.table-column</span> label=<span class="text-emerald-500">"Status"</span>&gt;
            &lt;<span class="text-violet-500">x-ui.badge</span> :variant=<span class="text-emerald-500">"$item->status === 'completed' ? 'success' : 'outline'"</span>&gt;
                @{{ $item-&gt;status }}
            &lt;/<span class="text-violet-500">x-ui.badge</span>&gt;
        &lt;/<span class="text-violet-500">x-ui.table-column</span>&gt;
        &lt;<span class="text-violet-500">x-ui.table-column</span> label=<span class="text-emerald-500">"Total"</span> field=<span class="text-emerald-500">"total_amount"</span> /&gt;
    &lt;/<span class="text-violet-500">x-ui.table</span>&gt;
&lt;/<span class="text-violet-500">div</span>&gt;
&#64;endsection</pre>
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION 4: THREE-LAYER PACKAGING MODEL (ADR-001) -->
    <section class="py-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto border-t border-border">
        <div class="text-center max-w-3xl mx-auto mb-14">
            <div class="text-xs font-mono font-bold text-primary uppercase tracking-widest mb-2">Architectural Foundation</div>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-foreground tracking-tight mb-3">
                The 3-Layer Packaging Model (ADR-001)
            </h2>
            <p class="text-sm text-muted-foreground">
                How LaraSlice guarantees zero broken upgrades. Customize core slices in your project without fighting Composer updates.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="p-6 rounded-3xl bg-card border border-border space-y-4">
                <span class="text-xs font-mono px-2.5 py-1 rounded-full bg-muted text-foreground font-bold">Layer 1: Engine</span>
                <h3 class="text-base font-bold text-foreground">vendor/hereafter/laraslice</h3>
                <p class="text-xs text-muted-foreground leading-relaxed">
                    The core runtime engine: base classes, audit logging, blueprint parsers, discovery manager, and CLI commands. Updated cleanly via Composer without touching project business logic.
                </p>
            </div>

            <div class="p-6 rounded-3xl bg-card border border-primary/40 space-y-4 shadow-lg shadow-primary/5">
                <span class="text-xs font-mono px-2.5 py-1 rounded-full bg-primary/20 text-primary font-bold">Layer 2: Starter Slices</span>
                <h3 class="text-base font-bold text-foreground">app/Slices/ (Project Space)</h3>
                <p class="text-xs text-muted-foreground leading-relaxed">
                    Users, Roles, Auth, and Settings ship in <code>app/Slices/</code>. When you add CNIC, phone, or custom relationships, you commit them directly to your Git repository. Safe from Composer overwrites forever.
                </p>
            </div>

            <div class="p-6 rounded-3xl bg-card border border-border space-y-4">
                <span class="text-xs font-mono px-2.5 py-1 rounded-full bg-muted text-blue-500 font-bold">Layer 3: Community Packages</span>
                <h3 class="text-base font-bold text-foreground">Packaged Bounded Contexts</h3>
                <p class="text-xs text-muted-foreground leading-relaxed">
                    Reusable vertical slices (Shop, Helpdesk, Billing). Customized cleanly via <code>php artisan slice:publish</code> or decoupled DTO event listeners.
                </p>
            </div>
        </div>
    </section>

    <!-- SECTION 5: DEVELOPER ARCHITECTURE FAQ ACCORDION -->
    <section id="faq" class="py-16 px-4 sm:px-6 lg:px-8 max-w-4xl mx-auto border-t border-border">
        <div class="text-center mb-14">
            <div class="text-xs font-mono font-bold text-primary uppercase tracking-widest mb-2">Team Guidance</div>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-foreground tracking-tight mb-3">
                Developer Guide & Architecture FAQ
            </h2>
            <p class="text-sm text-muted-foreground">
                Answers to the most critical architectural questions for your engineering team.
            </p>
        </div>

        <div class="space-y-4">
            <!-- FAQ 1 -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden">
                <button type="button" @click="faqOpen = (faqOpen === 1 ? null : 1)" class="w-full px-6 py-4 text-left flex items-center justify-between gap-4 font-bold text-sm text-foreground hover:text-primary transition cursor-pointer">
                    <span>1. When I customize a core slice (e.g. adding CNIC to Users), where does it live?</span>
                    <span class="font-mono text-base" x-text="faqOpen === 1 ? '−' : '+'">+</span>
                </button>
                <div x-show="faqOpen === 1" class="px-6 pb-5 text-xs sm:text-sm text-muted-foreground leading-relaxed border-t border-border pt-4" style="display: none;">
                    It lives in <strong>app/Slices/Users/</strong> in your project space. It is tracked by your application's Git repository. Running <code>composer update</code> will update the core engine in <code>vendor/hereafter/laraslice</code> without ever touching or wiping your custom fields, migrations, or DTO contracts.
                </div>
            </div>

            <!-- FAQ 2 -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden">
                <button type="button" @click="faqOpen = (faqOpen === 2 ? null : 2)" class="w-full px-6 py-4 text-left flex items-center justify-between gap-4 font-bold text-sm text-foreground hover:text-primary transition cursor-pointer">
                    <span>2. How does LaraSlice compare to October CMS plugin model?</span>
                    <span class="font-mono text-base" x-text="faqOpen === 2 ? '−' : '+'">+</span>
                </button>
                <div x-show="faqOpen === 2" class="px-6 pb-5 text-xs sm:text-sm text-muted-foreground leading-relaxed border-t border-border pt-4" style="display: none;">
                    October CMS relies heavily on dynamic runtime monkey-patching (<code>UserModel::extend()</code>), which can lead to brittle runtime conflicts and difficult debugging. LaraSlice uses <strong>Domain-Driven Design (DDD) Vertical Slices</strong>: slices communicate strictly via typed DTO contracts and domain events. If you need full ownership of a vendor slice, you publish it via <code>php artisan slice:publish Acme/Shop</code>.
                </div>
            </div>

            <!-- FAQ 3 -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden">
                <button type="button" @click="faqOpen = (faqOpen === 3 ? null : 3)" class="w-full px-6 py-4 text-left flex items-center justify-between gap-4 font-bold text-sm text-foreground hover:text-primary transition cursor-pointer">
                    <span>3. Can slices be generated by AI agents (like Antigravity or Cursor)?</span>
                    <span class="font-mono text-base" x-text="faqOpen === 3 ? '−' : '+'">+</span>
                </button>
                <div x-show="faqOpen === 3" class="px-6 pb-5 text-xs sm:text-sm text-muted-foreground leading-relaxed border-t border-border pt-4" style="display: none;">
                    Yes! LaraSlice ships with specialized skills (<code>skills/laraslice/SKILL.md</code>) and Jev System One integration. You can give an AI agent a natural language user story or specification, and it will autonomously run <code>slice:make</code>, configure parent-child multi-table schemas, and produce valid BlatUI Blade templates and Flutter code.
                </div>
            </div>

            <!-- FAQ 4 -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden">
                <button type="button" @click="faqOpen = (faqOpen === 4 ? null : 4)" class="w-full px-6 py-4 text-left flex items-center justify-between gap-4 font-bold text-sm text-foreground hover:text-primary transition cursor-pointer">
                    <span>4. What happens if I want to remove LaraSlice from my project later?</span>
                    <span class="font-mono text-base" x-text="faqOpen === 4 ? '−' : '+'">+</span>
                </button>
                <div x-show="faqOpen === 4" class="px-6 pb-5 text-xs sm:text-sm text-muted-foreground leading-relaxed border-t border-border pt-4" style="display: none;">
                    <strong>Zero lock-in.</strong> Every vertical slice is pure Laravel code (Eloquent models, standard Controllers, standard Blade views, standard migrations). If you ever decide to remove LaraSlice, you can register your slices in standard Laravel service providers or move them to <code>app/Http</code> and your application will continue running with zero refactoring.
                </div>
            </div>

            <!-- FAQ 5 -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden">
                <button type="button" @click="faqOpen = (faqOpen === 5 ? null : 5)" class="w-full px-6 py-4 text-left flex items-center justify-between gap-4 font-bold text-sm text-foreground hover:text-primary transition cursor-pointer">
                    <span>5. How do I customize core slices in my project or contribute upstream?</span>
                    <span class="font-mono text-base" x-text="faqOpen === 5 ? '−' : '+'">+</span>
                </button>
                <div x-show="faqOpen === 5" class="px-6 pb-5 text-xs sm:text-sm text-muted-foreground leading-relaxed border-t border-border pt-4 space-y-3" style="display: none;">
                    <p>
                        <strong>Customizing in Your Project:</strong> LaraSlice's discovery system is designed so that any slice in <code>app/Slices/</code> automatically overrides the core slice of the same name. Run:
                    </p>
                    <pre class="p-3 rounded-xl bg-muted/60 border border-border text-primary font-mono text-xs">php artisan slice:publish Users</pre>
                    <p>
                        *(or <code>php artisan slice:publish --all</code>)*. This copies the core slice into <code>app/Slices/Users</code>, allowing you to edit models, DTOs, controllers, and views directly in your Git repo without touching <code>vendor/</code>.
                    </p>
                    <p>
                        <strong>Open Source Contributions:</strong> Fork <a href="https://github.com/hereaftersol/laraslice" target="_blank" class="text-primary underline font-semibold">github.com/AbdurRehman712/laraslice</a>, edit files in <code>src/Slices/{SliceName}</code>, run <code>./vendor/bin/phpunit</code>, and open a Pull Request.
                    </p>
                    <p>
                        <strong>Local Package Development:</strong> In a test Laravel project, add a path repository in <code>composer.json</code> with <code>"type": "path", "url": "../LaraSlice", "options": { "symlink": true }</code> to test framework changes live in real time.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION 6: TEAM CONTRIBUTION & SLICE OWNERSHIP MATRIX -->
    <section class="py-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto border-t border-border">
        <div class="p-8 rounded-3xl bg-card border border-border shadow-sm space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-border">
                <div>
                    <h3 class="text-xl font-black text-foreground">Developer Contribution & Slice Ownership</h3>
                    <p class="text-xs text-muted-foreground mt-1">Recommended team delegation for core foundation and domain modules</p>
                </div>
                <span class="text-xs font-mono px-3 py-1 rounded-full bg-primary/10 text-primary border border-primary/20 font-bold">Team Workflow</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Slice 1: Users (Abdur Rehman) -->
                <div class="p-5 rounded-2xl bg-muted/40 border border-primary/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-mono font-bold text-primary">app/Slices/Users</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-primary text-primary-foreground">Abdur Rehman (Lead)</span>
                    </div>
                    <div class="text-sm font-bold text-foreground">User Management & CNIC</div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Profile details, CNIC verification, avatar uploads, password security, and active session guards.
                    </p>
                </div>

                <!-- Slice 2: Roles (Team Member 1) -->
                <div class="p-5 rounded-2xl bg-muted/40 border border-border space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-mono font-bold text-muted-foreground">app/Slices/Roles</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-muted text-foreground">Team Member 1</span>
                    </div>
                    <div class="text-sm font-bold text-foreground">Domain RBAC Matrix</div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Domain-grouped permissions (e.g. E-Commerce grouping 12 capabilities), role inheritance, and audit logs.
                    </p>
                </div>

                <!-- Slice 3: Auth & Security (Team Member 2) -->
                <div class="p-5 rounded-2xl bg-muted/40 border border-border space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-mono font-bold text-muted-foreground">app/Slices/Auth</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-muted text-foreground">Team Member 2</span>
                    </div>
                    <div class="text-sm font-bold text-foreground">Auth & WebAuthn</div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Passkeys, multi-factor biometric authentication, rate limiting, and session revocation.
                    </p>
                </div>

                <!-- Slice 4: Settings (Team Member 3) -->
                <div class="p-5 rounded-2xl bg-muted/40 border border-border space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-mono font-bold text-muted-foreground">app/Slices/Settings</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-muted text-foreground">Team Member 3</span>
                    </div>
                    <div class="text-sm font-bold text-foreground">Settings & Theme Tokens</div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        SMTP tester dispatch, dynamic key-value storage, and live CSS theme variables.
                    </p>
                </div>

                <!-- Slice 5: Domain Aggregates (Team Member 4) -->
                <div class="p-5 rounded-2xl bg-muted/40 border border-border space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-mono font-bold text-muted-foreground">app/Slices/Shop</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-muted text-foreground">Team Member 4</span>
                    </div>
                    <div class="text-sm font-bold text-foreground">Multi-Table Aggregates</div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Orders, Items, Shipments with state machine workflows and DTO event listeners.
                    </p>
                </div>

                <!-- Slice 6: Mobile Client (Team Member 5) -->
                <div class="p-5 rounded-2xl bg-muted/40 border border-border space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-mono font-bold text-muted-foreground">Flutter Clients</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-muted text-foreground">Team Member 5</span>
                    </div>
                    <div class="text-sm font-bold text-foreground">Flutter Client Exporter</div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Consuming REST slice endpoints in native Flutter apps with generated Dart models and API clients.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- SECTION 6: ECOSYSTEM, PARTNERS & PHILOSOPHY -->
    <section id="ecosystem" class="py-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto border-t border-border">
        <div class="text-center max-w-3xl mx-auto mb-14">
            <div class="text-xs font-mono font-bold text-primary uppercase tracking-widest mb-2">Our Purpose & Collective</div>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-foreground tracking-tight mb-3">
                Engineered with Purpose. Driven by Impact.
            </h2>
            <p class="text-sm text-muted-foreground">
                LaraSlice is built upon a visionary foundation of technological excellence, compassionate innovation, and strategic digital reach.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-stretch">
            <!-- Partner 1: Hereafter Solutions -->
            <div class="p-8 rounded-3xl bg-card border border-border shadow-xl space-y-5 flex flex-col justify-between hover:border-primary/50 transition">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="px-3 py-1 rounded-full text-xs font-bold bg-primary/10 text-primary border border-primary/20">
                            Creator & Lead Architect
                        </span>
                        <a href="https://hereaftersol.com" target="_blank" class="text-xs font-mono text-muted-foreground hover:text-primary transition">
                            hereaftersol.com &rarr;
                        </a>
                    </div>
                    <div class="text-2xl font-black text-foreground">Hereafter Solutions (H. Sol)</div>
                    <p class="text-sm font-semibold text-primary italic">
                        "Sowing Seeds of Innovation, Harvesting Compassion: Bridging Today’s Technology with Tomorrow’s Generosity."
                    </p>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        Inspired by the 1/3 prophetic tradition (Sahih Muslim 7473), Hereafter Solutions dedicates one-third of its yield to continuous reinvestment into open-source developer tooling like LaraSlice, and one-third to Sadqah-e-Jariyah as a Service (SJaaS) to empower non-tech and social startups worldwide.
                    </p>
                </div>
                <div class="pt-4 border-t border-border flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">Philosophy & Social Collective</span>
                    <a href="https://hereaftersol.com" target="_blank" class="font-bold text-foreground hover:text-primary transition">Visit H. Sol &rarr;</a>
                </div>
            </div>

            <!-- Partner 2: BrandUp -->
            <div class="p-8 rounded-3xl bg-card border border-border shadow-xl space-y-5 flex flex-col justify-between hover:border-primary/50 transition">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="px-3 py-1 rounded-full text-xs font-bold bg-amber-500/10 text-amber-500 border border-amber-500/20">
                            Official Digital Marketing Partner
                        </span>
                        <a href="https://brandup247.com" target="_blank" class="text-xs font-mono text-muted-foreground hover:text-primary transition">
                            brandup247.com &rarr;
                        </a>
                    </div>
                    <div class="text-2xl font-black text-foreground">BrandUp</div>
                    <p class="text-sm font-semibold text-amber-500 italic">
                        Accelerating High-Impact Brand Growth, Performance Marketing & Creative Reach.
                    </p>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        BrandUp drives strategic market adoption, audience acquisition, and creative digital storytelling for modern tech ecosystems and enterprises. As LaraSlice's strategic marketing partner, BrandUp helps bring enterprise architecture and developer productivity to teams across the globe.
                    </p>
                </div>
                <div class="pt-4 border-t border-border flex items-center justify-between text-xs">
                    <span class="text-muted-foreground">Brand Strategy & Marketing</span>
                    <a href="https://brandup247.com" target="_blank" class="font-bold text-foreground hover:text-primary transition">Visit BrandUp &rarr;</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="mt-auto border-t border-border bg-card py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-3">
                <div class="size-8 rounded-lg bg-primary text-primary-foreground font-black flex items-center justify-center text-sm font-bold">
                    LS
                </div>
                <div class="text-xs text-muted-foreground">
                    <span class="text-foreground font-bold">LaraSlice Framework</span> — Engineered by <a href="https://hereaftersol.com" target="_blank" class="text-primary hover:underline">Hereafter Solutions (H. Sol)</a> • Marketing by <a href="https://brandup247.com" target="_blank" class="text-primary hover:underline">BrandUp</a> • Created by <a href="https://github.com/AbdurRehman712" target="_blank" class="text-foreground font-semibold hover:underline">Abdur Rehman</a>.
                </div>
            </div>
            <div class="flex items-center gap-6 text-xs text-muted-foreground font-medium">
                <a href="https://github.com/hereaftersol/laraslice" target="_blank" class="hover:text-primary transition-colors">GitHub</a>
                <a href="{{ Route::has('laraslice.wizard') ? route('laraslice.wizard') : url('/laraslice/wizard') }}" class="hover:text-primary transition-colors">Blueprint Studio</a>
                <a href="{{ Route::has('settings.theme') ? route('settings.theme') : url('/admin/settings/theme') }}" class="hover:text-primary transition-colors">Theme Editor</a>
                <a href="#faq" class="hover:text-primary transition-colors">Developer FAQ</a>
            </div>
        </div>
    </footer>

</body>
</html>