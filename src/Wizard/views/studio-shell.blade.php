@extends(view()->exists('layouts.app') ? 'layouts.app' : 'laraslice::layout')

@section('title', 'Dev Studio')

@section('content')
<div class="min-h-screen bg-background" x-data="oneStudioShell(@js($initialMode), @js($hasSlices), @js($sliceCount ?? 0))" x-cloak>
    <header class="sticky top-0 z-40 border-b border-border bg-card/95 backdrop-blur">
        <div class="mx-auto flex w-full max-w-[1600px] flex-wrap items-center justify-between gap-3 px-4 py-3 md:px-6">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" @click="setMode('home')" class="text-left" title="Studio Home (⌘0)">
                    <h1 class="text-lg font-bold leading-tight text-foreground">Dev Studio</h1>
                    <p class="text-[11px] leading-tight text-muted-foreground">
                        One surface. Four modes.<span x-show="sliceCount > 0" x-cloak> · <span x-text="sliceCount"></span> slices</span>
                    </p>
                </button>
            </div>
            {{-- Mode switcher: destinations, not steps. Segmented tabs on desktop, dropdown on mobile. --}}
            <nav aria-label="Studio modes" class="flex items-center gap-1">
                <div class="hidden items-center gap-0.5 rounded-full bg-muted p-1 sm:flex" role="tablist">
                    <template x-for="m in modes" :key="m.id">
                        <button type="button" role="tab" @click="setMode(m.id)"
                                :class="mode === m.id ? 'bg-background text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'"
                                :aria-selected="mode === m.id"
                                :title="m.label + ' (⌘' + m.key + ')'"
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs transition">
                            <span x-text="m.icon"></span>
                            <span x-text="m.label"></span>
                        </button>
                    </template>
                </div>
                <div class="relative sm:hidden" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="inline-flex h-9 items-center gap-1.5 rounded-full border border-border bg-card px-3 text-xs font-semibold">
                        <span x-text="modes.find(m => m.id === mode)?.icon || '🏠'"></span>
                        <span x-text="modes.find(m => m.id === mode)?.label || 'Home'"></span>
                        <span>▾</span>
                    </button>
                    <div x-show="open" @click.outside="open = false" x-cloak
                         class="absolute right-0 top-full z-50 mt-1 min-w-[170px] rounded-xl border border-border bg-card py-1 shadow-lg">
                        <button type="button" @click="setMode('home'); open = false" class="w-full px-3 py-2 text-left text-xs hover:bg-accent">🏠&nbsp; Home</button>
                        <template x-for="m in modes" :key="m.id">
                            <button type="button" @click="setMode(m.id); open = false"
                                    class="w-full px-3 py-2 text-left text-xs hover:bg-accent"
                                    x-text="m.icon + '  ' + m.label"></button>
                        </template>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <main class="mx-auto w-full max-w-[1600px] px-3 py-4 sm:px-6">
        {{-- HOME: the front door. Pick a starting point; modes are destinations. --}}
        <div x-show="mode === 'home'" x-cloak>
            <div class="mx-auto max-w-4xl py-4 sm:py-8">
                <h2 class="text-xl font-bold text-foreground sm:text-2xl">What are you starting from?</h2>
                <p class="mt-1 text-sm text-muted-foreground">Every path lands in the same Studio. Pick the one that matches what you have — you can switch modes anytime (⌘1–⌘4).</p>

                {{-- AI / BRD entry: describes the app, lands in Design with the text carried over --}}
                <div class="mt-6 rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-5">
                    <div class="flex items-center gap-2">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-base">🪄</span>
                        <div>
                            <p class="text-sm font-semibold text-foreground">Describe your app — or paste a BRD</p>
                            <p class="text-xs text-muted-foreground">AI drafts a blueprint from it. You review the YAML diff in Design before anything is written.</p>
                        </div>
                    </div>
                    <textarea x-model="kickoffText" rows="4" placeholder="e.g. A clinic app: Patients, Doctors, Appointments (book, reschedule, cancel), Invoices with payments. Doctors have schedules; patients get reminders… or paste your full BRD here."
                              class="mt-3 w-full rounded-xl border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" @click="startFromText()" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-xs font-bold text-primary-foreground hover:opacity-90">
                            ✨ Draft blueprint in Design →
                        </button>
                        <span class="text-[11px] text-muted-foreground">Freelancer fast-path and team BRD flow use the same door.</span>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <button type="button" @click="setMode('port')" class="group rounded-2xl border border-border bg-card p-4 text-left shadow-sm transition hover:shadow-md">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-muted text-base">🔀</span>
                        <p class="mt-2.5 text-sm font-semibold text-foreground">Port a legacy app</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-muted-foreground">Reverse-engineer an existing Laravel app or SQL database into slices.</p>
                        <p class="mt-2 text-xs font-semibold text-primary">Open Port →</p>
                    </button>
                    <button type="button" @click="setMode('design')" class="group rounded-2xl border border-border bg-card p-4 text-left shadow-sm transition hover:shadow-md">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-muted text-base">📐</span>
                        <p class="mt-2.5 text-sm font-semibold text-foreground">Design a domain</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-muted-foreground">Model slices in YAML or visually, plan the blueprint, then apply.</p>
                        <p class="mt-2 text-xs font-semibold text-primary">Open Design →</p>
                    </button>
                    <button type="button" @click="setMode(hasSlices ? 'manage' : 'create')" class="group rounded-2xl border border-border bg-card p-4 text-left shadow-sm transition hover:shadow-md">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-muted text-base" x-text="hasSlices ? '🛠️' : '🧩'"></span>
                        <p class="mt-2.5 text-sm font-semibold text-foreground" x-text="hasSlices ? 'Manage existing slices' : 'Create your first slice'"></p>
                        <p class="mt-0.5 text-xs leading-relaxed text-muted-foreground" x-text="hasSlices ? 'Fields, UI, workflows and version history for installed slices.' : 'Guided scaffold: one slice, step by step.'"></p>
                        <p class="mt-2 text-xs font-semibold text-primary" x-text="hasSlices ? 'Open Manage →' : 'Open Create →'"></p>
                    </button>
                </div>
            </div>
        </div>

        {{-- PORT: lazy-mounted panel --}}
        <div x-show="mode === 'port'" x-cloak>
            <div x-show="!portLoaded" class="py-12 text-center text-sm text-muted-foreground">Loading Port…</div>
            <div id="one-studio-port" x-show="portLoaded"></div>
        </div>
        <div x-show="mode === 'design'" x-cloak>
            <div x-show="kickoffBrief" x-cloak class="mb-4 rounded-2xl border border-primary/30 bg-primary/5 p-4">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs font-semibold text-foreground">📄 Kickoff brief from Studio Home</p>
                    <button type="button" @click="dismissKickoff()" class="text-[11px] font-semibold text-muted-foreground hover:text-foreground">Dismiss</button>
                </div>
                <p class="mt-1 text-[11px] text-muted-foreground">Carried from your BRD / description. AI blueprint drafting from a brief is the next step — for now, keep it beside you while you model the domain below.</p>
                <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded-lg bg-background/70 p-3 text-[11px] leading-relaxed text-foreground" x-text="kickoffBrief"></pre>
            </div>
            <div class="mb-4 flex w-fit items-center gap-0.5 rounded-full bg-muted p-1" role="tablist" aria-label="Design views">
                <button type="button" role="tab" @click="setDesignView('blueprint')"
                        :class="designView === 'blueprint' ? 'bg-background text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'"
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs transition">📐 Blueprint</button>
                <button type="button" role="tab" @click="setDesignView('schema')"
                        :class="designView === 'schema' ? 'bg-background text-foreground shadow-sm font-semibold' : 'text-muted-foreground hover:text-foreground'"
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs transition">⚡ Schema</button>
            </div>
            <div x-show="designView === 'blueprint'">
                <div x-show="!designLoaded" class="py-12 text-center text-sm text-muted-foreground">Loading Design…</div>
                <div id="one-studio-design" x-show="designLoaded"></div>
            </div>
            <div x-show="designView === 'schema'" x-cloak>
                <div x-show="!schemaLoaded" class="py-12 text-center text-sm text-muted-foreground">Loading Schema…</div>
                <div id="one-studio-schema" x-show="schemaLoaded"></div>
            </div>
        </div>
        {{-- CREATE + MANAGE share one mounted wizard panel; the shell mode drives its tab. --}}
        <div x-show="mode === 'create' || mode === 'manage'" x-cloak>
            <div x-show="!wizardLoaded" class="py-12 text-center text-sm text-muted-foreground">Loading Studio…</div>
            <div id="one-studio-wizard" x-show="wizardLoaded"></div>
        </div>
    </main>
</div>

<script>
function oneStudioShell(initialMode, hasSlices, sliceCount) {
    const allowed = ['home', 'port', 'design', 'create', 'manage'];
    const start = allowed.includes(initialMode) ? initialMode : 'home';
    return {
        mode: start,
        hasSlices: !!hasSlices,
        sliceCount: sliceCount || 0,
        portLoaded: false,
        designLoaded: false,
        wizardLoaded: false,
        schemaLoaded: false,
        designView: new URLSearchParams(window.location.search).get('view') === 'schema' ? 'schema' : 'blueprint',
        kickoffText: '',
        kickoffBrief: '',
        modes: [
            { id: 'port', key: '1', icon: '🔀', label: 'Port' },
            { id: 'design', key: '2', icon: '📐', label: 'Design' },
            { id: 'create', key: '3', icon: '🧩', label: 'Create' },
            { id: 'manage', key: '4', icon: '🛠️', label: 'Manage' },
        ],
        init() {
            window.__oneStudioSetMode = (id) => this.setMode(id);
            if (this.mode === 'port') {
                this.mountPort();
            }
            if (this.mode === 'design') {
                if (this.designView === 'schema') { this.mountSchema(); } else { this.mountDesign(); }
            }
            if (this.mode === 'create' || this.mode === 'manage') {
                this.mountWizard(this.mode);
            }
            document.addEventListener('keydown', (e) => {
                if (!(e.metaKey || e.ctrlKey)) return;
                const t = e.target;
                if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
                if (e.key === '0') { e.preventDefault(); this.setMode('home'); }
                const m = this.modes.find(x => x.key === e.key);
                if (m) { e.preventDefault(); this.setMode(m.id); }
            });
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
            if (id === 'design') {
                if (this.designView === 'schema') { this.mountSchema(); } else { this.mountDesign(); }
            }
            if (id === 'create' || id === 'manage') {
                this.mountWizard(id);
            }
        },
        startFromText() {
            const text = (this.kickoffText || '').trim();
            if (text) {
                try { sessionStorage.setItem('laraslice_kickoff', text); } catch (e) {}
            }
            this.setMode('design');
        },
        dismissKickoff() {
            this.kickoffBrief = '';
            try { sessionStorage.removeItem('laraslice_kickoff'); } catch (e) {}
        },
        setDesignView(v) {
            this.designView = v;
            const url = new URL(window.location.href);
            url.searchParams.set('view', v);
            history.replaceState({}, '', url.pathname + url.search);
            if (v === 'schema') { this.mountSchema(); } else { this.mountDesign(); }
        },
        async mountSchema() {
            if (this.schemaLoaded) return;
            const el = document.getElementById('one-studio-schema');
            if (!el) return;
            const res = await fetch(@js(route('laraslice.studio.panel', ['mode' => 'schema'])), {
                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                el.innerHTML = '<p class="text-sm text-destructive">Could not load Schema.</p>';
                this.schemaLoaded = true;
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
            this.schemaLoaded = true;
            if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                window.Alpine.initTree(el);
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
        async mountDesign() {
            if (this.designLoaded) return;
            const el = document.getElementById('one-studio-design');
            if (!el) return;
            // Forward studio deep-link params (slice / template / source) to the panel.
            const src = new URLSearchParams(window.location.search);
            const fwd = new URLSearchParams();
            ['slice', 'template', 'source', 'load_port'].forEach(k => { if (src.get(k)) fwd.set(k, src.get(k)); });
            try {
                if (sessionStorage.getItem('laraslice_load_port') === '1') { fwd.set('load_port', '1'); sessionStorage.removeItem('laraslice_load_port'); }
                const brief = sessionStorage.getItem('laraslice_kickoff');
                if (brief) { this.kickoffBrief = brief; }
            } catch (e) {}
            const base = @js(route('laraslice.studio.panel', ['mode' => 'design']));
            const url = fwd.toString() ? base + '?' + fwd.toString() : base;
            const res = await fetch(url, {
                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                el.innerHTML = '<p class="text-sm text-destructive">Could not load Design.</p>';
                this.designLoaded = true;
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
            this.designLoaded = true;
            if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                window.Alpine.initTree(el);
            }
            window.dispatchEvent(new CustomEvent('laraslice:design-mounted'));
        },
        async mountWizard(firstMode) {
            if (this.wizardLoaded) {
                this.syncWizardTab();
                return;
            }
            const el = document.getElementById('one-studio-wizard');
            if (!el) return;
            const src = new URLSearchParams(window.location.search);
            const fwd = new URLSearchParams();
            ['slice', 'tab'].forEach(k => { if (src.get(k)) fwd.set(k, src.get(k)); });
            const base = @js(route('laraslice.studio.panel', ['mode' => '__MODE__']));
            const url = base.replace('__MODE__', firstMode) + (fwd.toString() ? '?' + fwd.toString() : '');
            const res = await fetch(url, {
                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                el.innerHTML = '<p class="text-sm text-destructive">Could not load Studio panel.</p>';
                this.wizardLoaded = true;
                return;
            }
            const html = await res.text();
            // Parse as a full document: the wizard markup's tag balance was authored
            // for document parsing; fragment (innerHTML) parsing mis-nests it.
            const doc = new DOMParser().parseFromString(html, 'text/html');
            el.innerHTML = '';
            // The wizard panel leads with <style>/<script>, which a document
            // parse places in <head> — import those too, in order.
            [...doc.head.childNodes].forEach(n => el.appendChild(document.importNode(n, true)));
            [...doc.body.childNodes].forEach(n => el.appendChild(document.importNode(n, true)));
            // The wizard markup nests the Studio section inside the Wizard grid
            // (unbalanced tags upstream), where x-show would hide it with its
            // parent. Hoist mode sections to the component root before Alpine runs.
            const wroot = el.querySelector('#laraslice-wizard-root');
            if (wroot) {
                el.querySelectorAll('div[x-show^="mainTab ==="]').forEach(sec => {
                    if (sec.parentElement !== wroot) wroot.appendChild(sec);
                });
            }
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
            this.wizardLoaded = true;
            if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                window.Alpine.initTree(el);
            }
            // Sync once the component has settled (its init + first render);
            // syncing earlier races Alpine's effect creation.
            setTimeout(() => this.syncWizardTab(), 600);
        },
        syncWizardTab() {
            const root = document.getElementById('laraslice-wizard-root');
            if (!root || !window.Alpine || typeof window.Alpine.$data !== 'function') return;
            try {
                const data = window.Alpine.$data(root);
                if (!data) return;
                const target = this.mode === 'manage' ? 'studio' : 'wizard';
                // Force the x-show effects to re-evaluate: after the fetch-mount,
                // the panel's first effect pass can run before its component data
                // settles, leaving the active section hidden. Toggling through
                // the other tab and back reliably re-runs them.
                data.mainTab = target === 'studio' ? 'wizard' : 'studio';
                data.mainTab = target;
            } catch (e) {}
        },
    };
}
</script>
@endsection
