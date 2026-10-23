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
        <div class="grid gap-4 lg:grid-cols-3 pb-6">
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
            <label class="text-xs font-bold uppercase tracking-wide text-muted-foreground" for="plan-roles">Roles — one per line: <span class="font-mono normal-case">Name | permission, permission</span></label>
            <textarea id="plan-roles" x-model="form.roles" rows="4" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-xs">{{ $form['roles'] }}</textarea>
        </div>
        <div>
            <label class="text-xs font-bold uppercase tracking-wide text-muted-foreground" for="plan-journeys">Journeys — one per line: <span class="font-mono normal-case">Name | Actor | Step &gt; Step &gt; Step | Outcome</span></label>
            <textarea id="plan-journeys" x-model="form.journeys" rows="4" class="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-xs">{{ $form['journeys'] }}</textarea>
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
    </form>
</div>

<script>
function planStudio() {
    return {
        form: {
            purpose: @js($form['purpose']),
            roles: @js($form['roles']),
            journeys: @js($form['journeys']),
            operations: @js($form['operations']),
        },
        diff: null,
        busy: false,
        saved: false,
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
        },
        async save() {
            this.busy = true;
            await this.post(@js(route('laraslice.studio.plan_save')));
            this.saved = true; this.busy = false;
        },
    };
}
</script>
