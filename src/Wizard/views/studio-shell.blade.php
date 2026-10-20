@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('title', 'Dev Studio')

@section('content')
<div class="min-h-screen bg-background" x-data="oneStudioShell(@js($initialMode), @js($hasSlices))" x-cloak>
    <header class="sticky top-0 z-40 border-b border-border bg-card/95 backdrop-blur">
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 md:px-6">
            <div class="min-w-0">
                <h1 class="text-lg font-bold text-foreground">Dev Studio</h1>
                <p class="text-xs text-muted-foreground">One surface. Four modes.</p>
            </div>
            {{-- Mode switcher (evolved journey breadcrumb). Overflow on small screens. --}}
            <nav aria-label="Studio modes" class="flex items-center gap-1">
                <div class="hidden sm:flex items-center gap-1">
                    <template x-for="m in modes" :key="m.id">
                        <button type="button" @click="setMode(m.id)"
                                :class="mode === m.id ? 'bg-primary text-primary-foreground font-semibold' : 'text-muted-foreground hover:text-foreground hover:bg-accent'"
                                :aria-current="mode === m.id ? 'page' : null"
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs transition">
                            <span class="font-mono text-[10px] opacity-70" x-text="m.n"></span>
                            <span x-text="m.label"></span>
                        </button>
                    </template>
                </div>
                <div class="sm:hidden relative" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="inline-flex h-9 items-center gap-1 rounded-lg border border-border bg-card px-3 text-xs font-semibold">
                        <span x-text="modes.find(m => m.id === mode)?.label || 'Mode'"></span>
                        <span>▾</span>
                    </button>
                    <div x-show="open" @click.outside="open = false" x-cloak
                         class="absolute right-0 top-full mt-1 z-50 min-w-[160px] rounded-lg border border-border bg-card shadow-lg py-1">
                        <template x-for="m in modes" :key="m.id">
                            <button type="button" @click="setMode(m.id); open = false"
                                    class="w-full text-left px-3 py-2 text-xs hover:bg-accent"
                                    x-text="m.n + '  ' + m.label"></button>
                        </template>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <main class="mx-auto w-full max-w-[1600px] px-3 py-4 sm:px-6">
        {{-- Port: lazy-mounted. Design/Create/Manage are Phase 2/3 — placeholders only, no heavy markup. --}}
        <div x-show="mode === 'port'" x-cloak>
            <div x-show="!portLoaded" class="py-12 text-center text-sm text-muted-foreground">Loading Port…</div>
            <div id="one-studio-port" x-show="portLoaded"></div>
        </div>
        <div x-show="mode === 'design'" x-cloak class="rounded-2xl border border-border bg-card p-8 text-center">
            <p class="text-sm font-semibold text-foreground">Design</p>
            <p class="mt-1 text-xs text-muted-foreground">Blueprint Studio moves here in Phase 2.</p>
            <a href="{{ route('laraslice.wizard.blueprint') }}" class="mt-3 inline-flex text-xs font-semibold text-primary underline">Open Blueprint Studio</a>
        </div>
        <div x-show="mode === 'create'" x-cloak class="rounded-2xl border border-border bg-card p-8 text-center">
            <p class="text-sm font-semibold text-foreground">Create</p>
            <p class="mt-1 text-xs text-muted-foreground">Scaffold Wizard moves here in Phase 3.</p>
            <a href="{{ route('laraslice.wizard') }}" class="mt-3 inline-flex text-xs font-semibold text-primary underline">Open Scaffold Wizard</a>
        </div>
        <div x-show="mode === 'manage'" x-cloak class="rounded-2xl border border-border bg-card p-8 text-center">
            <p class="text-sm font-semibold text-foreground">Manage</p>
            <p class="mt-1 text-xs text-muted-foreground">Slice Studio moves here in Phase 3.</p>
            <a href="{{ route('laraslice.wizard.studio') }}" class="mt-3 inline-flex text-xs font-semibold text-primary underline">Open Slice Studio</a>
        </div>
    </main>
</div>

<script>
function oneStudioShell(initialMode, hasSlices) {
    const allowed = ['port', 'design', 'create', 'manage'];
    const fallback = hasSlices ? 'manage' : 'port';
    const start = allowed.includes(initialMode) ? initialMode : fallback;
    return {
        mode: start,
        hasSlices: !!hasSlices,
        portLoaded: false,
        modes: [
            { id: 'port', n: '1', label: 'Port' },
            { id: 'design', n: '2', label: 'Design' },
            { id: 'create', n: '3', label: 'Create' },
            { id: 'manage', n: '4', label: 'Manage' },
        ],
        init() {
            if (this.mode === 'port') {
                this.mountPort();
            }
        },
        setMode(id) {
            if (!allowed.includes(id)) return;
            this.mode = id;
            const url = new URL(window.location.href);
            url.searchParams.set('mode', id);
            history.replaceState({}, '', url.pathname + url.search);
            if (id === 'port') {
                this.mountPort();
            }
        },
        async mountPort() {
            if (this.portLoaded) return;
            const el = document.getElementById('one-studio-port');
            if (!el) return;
            const res = await fetch(@js(route('laraslice.studio.panel', ['mode' => 'port'])), {
                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                el.innerHTML = '<p class="text-sm text-destructive">Could not load Port.</p>';
                this.portLoaded = true;
                return;
            }
            const html = await res.text();
            el.innerHTML = html;
            el.querySelectorAll('script').forEach((oldScript) => {
                const s = document.createElement('script');
                if (oldScript.src) {
                    s.src = oldScript.src;
                } else {
                    s.textContent = oldScript.textContent;
                }
                document.body.appendChild(s);
                oldScript.remove();
            });
            this.portLoaded = true;
            if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                window.Alpine.initTree(el);
            }
        },
    };
}
</script>
@endsection
