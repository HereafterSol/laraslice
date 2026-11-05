<div x-data="planStudio()" class="bg-background">
    <div class="flex flex-wrap items-start justify-between gap-3 pb-4">
        <div class="max-w-3xl">
            <h2 class="text-base font-bold text-foreground">Plan — the Mind of this system</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Intent, in plain language: what the system is for, who it serves, what each role may do, and the journeys that matter.
                The Mind never runs — it guides building. Slices (the Heart) stay the runtime truth.
                @if($mindPath) <span class="font-mono">{{ str_replace(base_path().'/', '', $mindPath) }}</span> @endif
            </p>
        </div>
        @if($mind === null)
            <p class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">No Mind yet. Run <span class="font-mono">php artisan slice:plan:draft</span> to draft one from the discovered slices, or write the purpose below and save to create it.</p>
        @endif
    </div>

    @if($mind !== null)
        <div class="grid items-start gap-4 lg:grid-cols-3 pb-6">
            <div class="rounded-xl border border-border bg-card p-4">
                <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Actors</h3>
                <ul class="mt-2 space-y-2">
                    @foreach($mind['actors'] ?? [] as $actor)
                        <li class="text-sm"><span class="font-semibold text-foreground">{{ $actor['name'] ?? '' }}</span>
                            <span class="text-muted-foreground">· {{ $actor['kind'] ?? '' }}</span>
                            <p class="text-xs text-muted-foreground">{{ $actor['description'] ?? '' }}</p></li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Journeys</h3>
                <ul class="mt-2 space-y-2">
                    @foreach($mind['journeys'] ?? [] as $journey)
                        <li class="text-sm"><span class="font-semibold text-foreground">{{ $journey['name'] ?? '' }}</span>
                            <p class="text-xs text-muted-foreground">{{ implode(' → ', (array) ($journey['steps'] ?? [])) }}</p>
                            <p class="text-xs text-muted-foreground italic">{{ $journey['outcome'] ?? '' }}</p></li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Operations</h3>
                <ul class="mt-2 space-y-1">
                    @foreach((array) ($mind['operations'] ?? []) as $key => $value)
                        <li class="text-sm"><span class="font-semibold text-foreground">{{ $key }}</span>
                            <span class="text-muted-foreground">— {{ is_array($value) ? json_encode($value) : $value }}</span></li>
                    @endforeach
                </ul>
                @if(! empty($mind['roles']['overrides']))
                    <h3 class="mt-4 text-xs font-bold uppercase tracking-wide text-muted-foreground">Role overrides</h3>
                    <p class="mt-1 text-xs text-muted-foreground">{{ $mind['roles']['overrides'] }}</p>
                @endif
            </div>
        </div>
    @endif

    <form @submit.prevent="preview()" class="space-y-4">
        <div>
            <label class="text-xs font-bold uppercase tracking-wide text-muted-foreground" for="plan-purpose">Purpose</label>
            <textarea id="plan-purpose" x-model="form.purpose" rows="2" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm">{{ $form['purpose'] }}</textarea>
        </div>
        <div>
            <label class="text-xs font-bold uppercase tracking-wide text-muted-foreground" for="plan-actors">Actors — one per line: <span class="font-mono normal-case">Name | internal or external | What they do</span></label>
            <textarea id="plan-actors" x-model="form.actors" rows="3" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-xs">{{ $form['actors'] ?? '' }}</textarea>
            <p class="mt-1 text-[10px] text-muted-foreground">The journey builder's actor list reads from this field — add an actor here first, then build their journey below. Journeys name their actor as text, so if you rename an actor, update the journey lines that mention the old name.</p>
        </div>
        <div>
            <label class="text-xs font-bold uppercase tracking-wide text-muted-foreground" for="plan-roles">Roles — one per line: <span class="font-mono normal-case">Name | permission, permission</span></label>
            <textarea id="plan-roles" x-model="form.roles" rows="4" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-xs">{{ $form['roles'] }}</textarea>
        </div>
        <div>
            <label class="text-xs font-bold uppercase tracking-wide text-muted-foreground" for="plan-journeys">Journeys — one per line: <span class="font-mono normal-case">Name | Actor | Step &gt; Step &gt; Step | Outcome</span></label>
            <textarea id="plan-journeys" x-model="form.journeys" @input.debounce.600ms="loadImpact()" rows="4" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-xs">{{ $form['journeys'] }}</textarea>
            <div class="mt-2 flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-border bg-muted/30 p-3">
                <span class="text-[11px] font-semibold text-foreground">✨ Propose with AI:</span>
                <input type="text" x-model="proposeText" @keydown.enter.prevent="proposeJourneys()" placeholder="e.g. Add a journey where a Client pays an invoice online" class="min-w-64 flex-1 rounded-lg border border-input bg-background px-3 py-1.5 text-xs" />
                <button type="button" @click="proposeJourneys()" :disabled="proposeBusy" class="rounded-lg bg-secondary px-3 py-1.5 text-[11px] font-bold text-secondary-foreground hover:opacity-90 disabled:opacity-50 cursor-pointer">Propose journeys</button>
                <span x-show="proposeStatus" class="text-[11px] text-muted-foreground" x-text="proposeStatus"></span>
            </div>
            <p class="mt-1 text-[10px] text-muted-foreground">Proposals are appended to the journeys above — nothing is saved until you preview the diff and choose Save.</p>
            <div class="mt-2 rounded-xl border border-border bg-muted/30 p-3">
                <p class="text-[11px] font-semibold text-foreground">🧭 Build a journey — no syntax needed</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <input type="text" x-model="jbName" placeholder="Journey name — e.g. Client pays an invoice" class="rounded-lg border border-input bg-background px-3 py-1.5 text-xs" />
                    <select x-model="jbActor" class="rounded-lg border border-input bg-background px-3 py-1.5 text-xs">
                        <option value="">Choose an actor…</option>
                        <template x-for="actorName in actorNames()" :key="actorName">
                            <option :value="actorName" x-text="actorName"></option>
                        </template>
                    </select>
                </div>
                <div class="mt-2 space-y-1.5">
                    <template x-for="(step, i) in jbSteps" :key="i">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-bold text-muted-foreground" x-text="(i + 1) + '.'"></span>
                            <input type="text" x-model="jbSteps[i]" placeholder="A step — name the screen or slice it happens on, e.g. Open Invoices" class="flex-1 rounded-lg border border-input bg-background px-3 py-1.5 text-xs" />
                            <button type="button" @click="jbSteps.splice(i, 1)" x-show="jbSteps.length > 1" class="text-[11px] font-bold text-muted-foreground hover:text-foreground">✕</button>
                        </div>
                    </template>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="button" @click="jbSteps.push('')" class="text-[11px] font-semibold text-muted-foreground hover:text-foreground">+ Add a step</button>
                    <input type="text" x-model="jbOutcome" placeholder="Outcome — e.g. The invoice is marked paid" class="min-w-56 flex-1 rounded-lg border border-input bg-background px-3 py-1.5 text-xs" />
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button type="button" @click="addJourneyFromBuilder()" class="rounded-lg bg-secondary px-3 py-1.5 text-[11px] font-bold text-secondary-foreground hover:opacity-90 cursor-pointer">Add journey to the form</button>
                    <span x-show="jbStatus" class="text-[11px] text-muted-foreground" x-text="jbStatus"></span>
                </div>
                <p class="mt-1 text-[10px] text-muted-foreground">Tip: a step that names its slice ("Open Invoices") links to that surface in the Impact card below. Prefer typing? The field above takes the same lines directly — both end up in the same form.</p>
            </div>
        </div>
        <div>
            <label class="text-xs font-bold uppercase tracking-wide text-muted-foreground" for="plan-metrics">Success metrics — one per line: <span class="font-mono normal-case">Name | target</span></label>
            <textarea id="plan-metrics" x-model="form.metrics" rows="3" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-xs">{{ $form['metrics'] ?? '' }}</textarea>
        </div>
        <div>
            <label class="text-xs font-bold uppercase tracking-wide text-muted-foreground" for="plan-operations">Operations — one per line: <span class="font-mono normal-case">key: value</span></label>
            <textarea id="plan-operations" x-model="form.operations" rows="3" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-xs">{{ $form['operations'] }}</textarea>
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-xs font-bold text-primary-foreground hover:opacity-90" :disabled="busy">Preview diff</button>
            <button type="button" @click="save()" x-show="diff !== null" class="rounded-lg border border-border bg-card px-4 py-2 text-xs font-bold text-foreground hover:bg-muted" :disabled="busy">Save the Mind</button>
            <span x-show="saved" class="text-xs font-semibold text-emerald-600">Saved ✓ — the Heart is untouched; the Mind guides what gets built next.</span>
        </div>
        <pre x-show="diff !== null" x-text="diff" class="overflow-x-auto rounded-xl border border-border bg-muted/40 p-4 font-mono text-[11px] leading-relaxed"></pre>
        <div x-show="impact && impact.has_mind" x-cloak class="rounded-2xl border border-border bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Impact — what these journeys touch</h4>
                <span class="text-[11px] text-muted-foreground" x-text="impact ? (impact.pages + ' pages in ' + impact.sections + ' sections · sources: Mind + slice navigation') : ''"></span>
            </div>
            <ul class="mt-2 space-y-1.5">
                <template x-for="j in (impact && impact.candidate ? impact.candidate.journeys : (impact ? impact.journeys : []))" :key="j.name">
                    <li class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="font-semibold text-foreground" x-text="j.name"></span>
                        <span class="text-muted-foreground" x-text="j.actor"></span>
                        <template x-if="impact && impact.candidate && impact.candidate.added.includes(j.name)"><span class="rounded bg-emerald-500/15 px-1.5 py-0.5 text-[10px] font-bold text-emerald-600">NEW</span></template>
                        <span class="ml-auto text-muted-foreground" x-show="j.covered" x-text="j.linked + '/' + j.steps + ' steps linked · ' + j.slices.join(', ')"></span>
                        <span class="ml-auto font-semibold text-amber-600" x-show="!j.covered">No surface yet — intent only</span>
                    </li>
                </template>
            </ul>
            <p class="mt-2 text-[11px] text-muted-foreground" x-show="impact && impact.candidate && impact.candidate.removed.length" x-text="'Saving removes: ' + impact.candidate.removed.join(', ') + ' — their surfaces stay; the declared intent goes.'"></p>
            <p class="mt-1 text-[10px] text-muted-foreground">Step→slice linking follows the App Flow rule: a step is linked when its text names a slice's navigation label.</p>
        </div>
    </form>
</div>

<script>
function planStudio() {
    return {
        form: {
            purpose: @js($form['purpose']),
            actors: @js($form['actors'] ?? ''),
            roles: @js($form['roles']),
            journeys: @js($form['journeys']),
            metrics: @js($form['metrics'] ?? ''),
            operations: @js($form['operations']),
        },
        diff: null,
        busy: false,
        saved: false,
        impact: null,
        init() { this.loadImpact(); },
        async loadImpact() {
            try {
                const res = await fetch(@js(route('laraslice.studio.plan_impact')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
                    body: JSON.stringify({ journeys: this.form.journeys }),
                });
                this.impact = await res.json();
            } catch (e) { /* impact is best-effort */ }
        },
        proposeText: '',
        proposeStatus: '',
        proposeBusy: false,
        async proposeJourneys() {
            if (this.proposeBusy || !this.proposeText.trim()) return;
            this.proposeBusy = true;
            this.proposeStatus = 'Asking the AI…';
            try {
                const res = await fetch(@js(route('laraslice.studio.plan_propose_journeys')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
                    body: JSON.stringify({ instruction: this.proposeText }),
                });
                const data = await res.json();
                if (data.proposed && data.lines && data.lines.length) {
                    const current = (this.form.journeys || '').replace(/\s+$/, '');
                    this.form.journeys = (current ? current + '\n' : '') + data.lines.join('\n');
                    this.proposeText = '';
                    this.proposeStatus = 'Added ' + data.lines.length + ' proposed ' + (data.lines.length === 1 ? 'journey' : 'journeys') + ' — review the diff below, then Save.';
                    await this.preview();
                } else {
                    this.proposeStatus = data.reason || 'No proposal came back.';
                }
            } catch (e) {
                this.proposeStatus = 'Proposing failed (network) — try again.';
            } finally {
                this.proposeBusy = false;
            }
        },
        actorNames() {
            return (this.form.actors || '').split('\n').map(l => l.split('|')[0].trim()).filter(Boolean);
        },
        jbName: '',
        jbActor: '',
        jbSteps: [''],
        jbOutcome: '',
        jbStatus: '',
        addJourneyFromBuilder() {
            const name = this.jbName.trim();
            const steps = this.jbSteps.map(s => s.trim()).filter(s => s !== '');
            if (name === '') { this.jbStatus = 'Give the journey a name first.'; return; }
            if (this.jbActor === '') { this.jbStatus = 'Choose who takes this journey (the actor).'; return; }
            if (steps.length === 0) { this.jbStatus = 'Add at least one step — what happens, in plain words.'; return; }
            const existing = (this.form.journeys || '').split('\n').map(l => l.split('|')[0].trim().toLowerCase()).filter(Boolean);
            if (existing.includes(name.toLowerCase())) { this.jbStatus = '"' + name + '" is already in the journeys above — edit that line instead.'; return; }
            const line = name + ' | ' + this.jbActor + ' | ' + steps.join(' > ') + ' | ' + this.jbOutcome.trim();
            const current = (this.form.journeys || '').replace(/\s+$/, '');
            this.form.journeys = (current ? current + '\n' : '') + line;
            this.jbName = ''; this.jbSteps = ['']; this.jbOutcome = '';
            this.jbStatus = 'Added "' + name + '" — see it in the Impact card below, then Preview diff.';
            this.loadImpact();
        },
        async post(url) {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
                body: JSON.stringify(this.form),
            });
            return res.json();
        },
        async preview() {
            this.busy = true; this.saved = false;
            const data = await this.post(@js(route('laraslice.studio.plan_preview')));
            this.diff = data.diff === '' ? '(no changes)' : data.diff;
            this.busy = false;
            await this.loadImpact();
        },
        async save() {
            this.busy = true;
            await this.post(@js(route('laraslice.studio.plan_save')));
            this.saved = true; this.busy = false;
            await this.loadImpact();
        },
    };
}
</script>
