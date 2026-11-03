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
                        One surface. Six modes.<span x-show="sliceCount > 0" x-cloak> · <span x-text="sliceCount"></span> slices</span>
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
                <p class="mt-1 text-sm text-muted-foreground">Every path lands in the same Studio. Pick the one that matches what you have — you can switch modes anytime<span class="hidden sm:inline"> (⌘1–⌘4)</span>.</p>

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
                        <p class="mt-0.5 text-xs leading-relaxed text-muted-foreground">Analyze a legacy Laravel app or database, review journeys, then execute when ready — no files written until you confirm.</p>
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
                <p class="mt-1 text-[11px] text-muted-foreground">Carried from your BRD / description. With an AI provider connected (Settings → AI), draft a starting blueprint from it — the draft lands in Design as an unsaved draft for your review; nothing is generated until you say so.</p>
                <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded-lg bg-background/70 p-3 text-[11px] leading-relaxed text-foreground" x-text="kickoffBrief"></pre>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" @click="draftBlueprintFromBrief()" :disabled="briefDrafting" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-[11px] font-bold text-primary-foreground hover:opacity-90 disabled:opacity-50 cursor-pointer">
                        <span x-text="briefDrafting ? '✨ Drafting…' : '✨ Draft blueprint from this brief'"></span>
                    </button>
                    <span x-show="briefDraftStatus" class="text-[11px] text-muted-foreground" x-text="briefDraftStatus"></span>
                </div>
                <div x-show="briefMind" x-cloak class="mt-3 rounded-xl border border-border bg-background/70 p-3">
                    <p class="text-[11px] font-semibold text-foreground">🧠 Mind seed drafted from this brief</p>
                    <p class="mt-1 text-[11px] leading-relaxed text-muted-foreground" x-text="briefMind ? briefMind.purpose : ''"></p>
                    <p class="mt-1 text-[11px] text-muted-foreground" x-show="briefMind">
                        <span class="font-semibold text-foreground">Actors:</span> <span x-text="briefMind ? briefMind.actors.join(', ') : ''"></span><template x-if="briefMind && briefMind.journeys.length"><span> · <span class="font-semibold text-foreground">Journeys:</span> <span x-text="briefMind.journeys.join(', ')"></span></span></template>
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" @click="applyBriefMind()" :disabled="briefMindBusy" class="inline-flex items-center gap-1.5 rounded-lg bg-secondary px-3 py-1.5 text-[11px] font-bold text-secondary-foreground hover:opacity-90 disabled:opacity-50 cursor-pointer">Add as the app's Mind (Plan)</button>
                        <button type="button" @click="briefMind = null" class="text-[11px] font-semibold text-muted-foreground hover:text-foreground">Skip</button>
                        <span x-show="briefMindStatus" class="text-[11px] text-muted-foreground" x-text="briefMindStatus"></span>
                    </div>
                    <p class="mt-1 text-[10px] text-muted-foreground">Applies only when the app has no Mind yet — an existing Mind is never overwritten; it is edited in Plan.</p>
                </div>
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
        {{-- OPERATE: lazy-mounted panel (health, backups, audit, access) --}}
        <div x-show="mode === 'operate'" x-cloak>
            <div x-show="!operateLoaded" class="py-12 text-center text-sm text-muted-foreground">Loading Operate…</div>
            <div id="one-studio-operate" x-show="operateLoaded"></div>
        </div>
        {{-- PLAN: lazy-mounted panel (the Mind) --}}
        <div x-show="mode === 'plan'" x-cloak>
            <div x-show="!planLoaded" class="py-12 text-center text-sm text-muted-foreground">Loading Plan…</div>
            <div id="one-studio-plan" x-show="planLoaded"></div>
        </div>
        {{-- CREATE + MANAGE share one mounted wizard panel; the shell mode drives its tab. --}}
        <div x-show="mode === 'create' || mode === 'manage'" x-cloak>
            <div x-show="!wizardLoaded" class="py-12 text-center text-sm text-muted-foreground">Loading Studio…</div>
            <div id="one-studio-wizard" x-show="wizardLoaded"></div>
        </div>
    </main>
</div>

<script>
/* The Design editor's component factory ships as a shared, cacheable
   package asset instead of inline panel code (~113 KB per Design load).
   mountDesign awaits this loader before initializing the panel; the
   shell also prefetches it so Design usually opens instantly. */
window.__loadDesignFactory = window.__loadDesignFactory || function () {
    if (window.blueprintStudio) return Promise.resolve(window.blueprintStudio);
    if (window.__designFactoryPromise) return window.__designFactoryPromise;
    window.__designFactoryPromise = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = '/laraslice/studio/blueprint/design-factory';
        s.onload = function () { window.blueprintStudio ? resolve(window.blueprintStudio) : reject(new Error('design factory asset did not define the editor')); };
        s.onerror = function () { reject(new Error('design factory failed to load')); };
        document.head.appendChild(s);
    });
    return window.__designFactoryPromise;
};
</script>
<script>
function oneStudioShell(initialMode, hasSlices, sliceCount) {
    const allowed = ['home', 'port', 'design', 'create', 'manage', 'plan', 'operate'];
    const start = allowed.includes(initialMode) ? initialMode : 'home';
    return {
        mode: start,
        hasSlices: !!hasSlices,
        sliceCount: sliceCount || 0,
        portLoaded: false,
        planLoaded: false,
        operateLoaded: false,
        designLoaded: false,
        wizardLoaded: false,
        schemaLoaded: false,
        designView: new URLSearchParams(window.location.search).get('view') === 'schema' ? 'schema' : 'blueprint',
        kickoffText: '',
        kickoffBrief: '',
        briefDrafting: false,
        briefDraftStatus: '',
        briefMind: null,
        briefMindHandle: '',
        briefMindStatus: '',
        briefMindBusy: false,
        modes: [
            { id: 'port', key: '1', icon: '🔀', label: 'Port' },
            { id: 'design', key: '2', icon: '📐', label: 'Design' },
            { id: 'create', key: '3', icon: '🧩', label: 'Create' },
            { id: 'manage', key: '4', icon: '🛠️', label: 'Manage' },
            { id: 'plan', key: '5', icon: '🧭', label: 'Plan' },
            { id: 'operate', key: '6', icon: '🎛️', label: 'Operate' },
        ],
        init() {
            if (window.__loadDesignFactory) window.__loadDesignFactory().catch(() => {});
            window.__oneStudioSetMode = (id) => this.setMode(id);
            window.__oneStudioDesignSlice = (slice) => this.reloadDesignWithSlice(slice);
            if (this.mode === 'port') {
                this.mountPort();
            }
            if (this.mode === 'design') {
                if (this.designView === 'schema') { this.mountSchema(); } else { this.mountDesign(); }
            }
            if (this.mode === 'create' || this.mode === 'manage') {
                this.mountWizard(this.mode);
            }
            if (this.mode === 'plan') {
                this.mountPlan();
            }
            if (this.mode === 'operate') {
                this.mountOperate();
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
            if (id === 'plan') {
                this.mountPlan();
            }
            if (id === 'operate') {
                this.mountOperate();
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
        async draftBlueprintFromBrief() {
            if (!this.kickoffBrief || this.briefDrafting) return;
            this.briefDrafting = true;
            this.briefDraftStatus = 'Drafting from your brief… this can take up to a minute.';
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('/laraslice/studio/blueprint/draft-from-brief', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ brief: this.kickoffBrief }),
                });
                const data = await res.json();
                if (data.drafted && data.source) {
                    try { sessionStorage.setItem('laraslice_drafted_blueprint', data.source); } catch (e) {}
                    this.briefMind = data.mind || null;
                    this.briefMindHandle = data.handle || '';
                    this.briefMindStatus = '';
                    this.briefDraftStatus = 'Draft ready — review every field in Design before generating. Nothing was written.';
                    await this.reloadDesignWithSlice(null);
                } else {
                    this.briefDraftStatus = data.reason || 'Drafting failed.';
                }
            } catch (e) {
                this.briefDraftStatus = 'Drafting failed (network). The brief is still here — try again.';
            } finally {
                this.briefDrafting = false;
            }
        },
        async applyBriefMind() {
            if (!this.briefMindHandle || this.briefMindBusy) return;
            this.briefMindBusy = true;
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const res = await fetch('/laraslice/studio/mind-seed/apply', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ handle: this.briefMindHandle }),
                });
                const data = await res.json();
                if (data.applied) {
                    this.briefMindStatus = 'Mind saved — open Plan (⌘5) to review and complete it.';
                    this.briefMind = null;
                } else {
                    this.briefMindStatus = data.reason || 'The Mind seed could not be applied.';
                }
            } catch (e) {
                this.briefMindStatus = 'Applying the Mind seed failed (network) — try again.';
            } finally {
                this.briefMindBusy = false;
            }
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
        async mountOperate() {
            if (this.operateLoaded) return;
            const el = document.getElementById('one-studio-operate');
            if (!el) return;
            const res = await fetch(@js(route('laraslice.studio.panel', ['mode' => 'operate'])), {
                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                el.innerHTML = '<p class="text-sm text-destructive">Could not load Operate.</p>';
                this.operateLoaded = true;
                return;
            }
            const html = await res.text();
            el.innerHTML = html;
            el.querySelectorAll('script').forEach((oldScript) => {
                const s = document.createElement('script');
                if (oldScript.src) { s.src = oldScript.src; } else { s.textContent = oldScript.textContent; }
                oldScript.replaceWith(s);
            });
            this.operateLoaded = true;
            this.$nextTick(() => { if (window.Alpine) window.Alpine.initTree(el); });
        },
        async mountPlan() {
            if (this.planLoaded) return;
            const el = document.getElementById('one-studio-plan');
            if (!el) return;
            const res = await fetch(@js(route('laraslice.studio.panel', ['mode' => 'plan'])), {
                headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) {
                el.innerHTML = '<p class="text-sm text-destructive">Could not load Plan.</p>';
                this.planLoaded = true;
                return;
            }
            const html = await res.text();
            el.innerHTML = html;
            el.querySelectorAll('script').forEach((oldScript) => {
                const s = document.createElement('script');
                if (oldScript.src) { s.src = oldScript.src; } else { s.textContent = oldScript.textContent; }
                oldScript.replaceWith(s);
            });
            this.planLoaded = true;
            this.$nextTick(() => { if (window.Alpine) window.Alpine.initTree(el); });
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
        async reloadDesignWithSlice(slice) {
            // In-shell slice switch: keep the shell, sidebar
            // and scroll context; swap only the Design
            // fragment and record the slice in the URL so a
            // refresh or shared link lands on the same slice.
            const params = new URLSearchParams(window.location.search);
            params.set('mode', 'design');
            if (slice) { params.set('slice', slice); } else { params.delete('slice'); }
            history.replaceState(null, '', window.location.pathname + '?' + params.toString());
            const el = document.getElementById('one-studio-design');
            if (el) { el.innerHTML = '<p class="text-sm text-muted-foreground">Loading Design…</p>'; }
            this.designLoaded = false;
            await this.mountDesign();
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
            // The panel's component factory is a shared asset now:
            // Alpine must not initialize the panel before it exists.
            try {
                if (window.__loadDesignFactory) await window.__loadDesignFactory();
            } catch (e) {
                el.innerHTML = '<p class="text-sm text-destructive">The Design editor failed to load. Check your connection, then open Design again.</p>';
                return;
            }
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
