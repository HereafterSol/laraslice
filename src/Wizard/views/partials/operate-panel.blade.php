<div x-data="operateStudio()" class="bg-background">
    <div class="max-w-3xl pb-4">
        <h2 class="text-base font-bold text-foreground">Operate — caring for the running system</h2>
        <p class="mt-1 text-xs text-muted-foreground">Health, backups (as declared in the Mind), the audit trail, and who holds which access. Operate reports reality against the Mind's declarations — it does not change runtime behavior.</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-2 pb-6">
        <div class="rounded-xl border border-border bg-card p-4">
            <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Health</h3>
            <ul class="mt-2 space-y-1.5">
                @foreach($health as $check)
                    <li class="flex items-center justify-between gap-2 text-sm">
                        <span class="font-semibold text-foreground">{{ $check['name'] }}</span>
                        <span class="text-xs {{ $check['ok'] ? 'text-emerald-600' : 'text-destructive' }}">{{ $check['ok'] ? '✓' : '✗' }} {{ $check['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
            <h3 class="mt-5 text-xs font-bold uppercase tracking-wide text-muted-foreground">Sync (declared)</h3>
            <p class="mt-1 text-xs text-muted-foreground">{{ $sync ? json_encode($sync) : 'Not declared in the Mind.' }}</p>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
            <div class="flex items-center justify-between">
                <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Backups</h3>
                <button @click="runBackup()" :disabled="busy" class="rounded-lg bg-primary px-3 py-1.5 text-[11px] font-bold text-primary-foreground hover:opacity-90 disabled:opacity-50">Run backup now</button>
            </div>
            <p class="mt-1 text-xs text-muted-foreground">Policy from the Mind: {{ $backupPolicy ? json_encode($backupPolicy) : 'not declared' }}</p>
            <p x-show="created" class="mt-1 text-xs font-semibold text-emerald-600" x-text="'Created: ' + created"></p>
            <ul class="mt-2 space-y-1">
                <template x-for="b in backups" :key="b.name">
                    <li class="flex items-center justify-between text-sm"><span class="font-mono text-xs" x-text="b.name"></span><span class="text-xs text-muted-foreground" x-text="b.size + ' · ' + b.at"></span></li>
                </template>
                @foreach($backups as $b)
                    <li class="flex items-center justify-between text-sm" x-show="backups.length === 0"><span class="font-mono text-xs">{{ $b['name'] }}</span><span class="text-xs text-muted-foreground">{{ $b['size'] }} · {{ $b['at'] }}</span></li>
                @endforeach
                @if($backups === [])
                    <li class="text-xs text-muted-foreground" x-show="backups.length === 0">No backups yet.</li>
                @endif
            </ul>
            <h3 class="mt-5 text-xs font-bold uppercase tracking-wide text-muted-foreground">Manual</h3>
            <p class="mt-1 text-xs text-muted-foreground">Generate the operator manual from the Mind + slice inventory.</p>
            <button @click="generateManual()" :disabled="busy" class="mt-2 rounded-lg border border-border bg-card px-3 py-1.5 text-[11px] font-bold text-foreground hover:bg-muted disabled:opacity-50">Generate manual (.md)</button>
        </div>
    </div>

    <div class="rounded-xl border border-border bg-card p-4 mb-6">
        <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Quality — the trust layer</h3>
        <p class="mt-1 text-xs text-muted-foreground">The same facts the CLI gates report (<span class="font-mono">slice:conformance</span>, <span class="font-mono">slice:drift</span>, <span class="font-mono">slice:history</span>) — declarations measured, not asserted.</p>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Conformance evidence</p>
                @if(($quality['evidence']['available'] ?? false) && $quality['evidence']['checks'] > 0)
                    <p class="mt-1 text-sm font-semibold {{ $quality['evidence']['failed'] === [] ? 'text-emerald-600' : 'text-destructive' }}">{{ $quality['evidence']['passed'] }}/{{ $quality['evidence']['checks'] }} checks passed ({{ $quality['evidence']['percent'] }}%)</p>
                    <p class="text-xs text-muted-foreground">{{ $quality['evidence']['suites'] }} suite(s) · latest {{ $quality['evidence']['latest_at'] }}</p>
                    @if($quality['evidence']['failed'] !== [])
                        <p class="text-xs font-semibold text-destructive">Failing: {{ implode(', ', $quality['evidence']['failed']) }}</p>
                    @endif
                @elseif($quality['evidence']['available'] ?? false)
                    <p class="mt-1 text-sm text-muted-foreground">No evidence recorded yet — run a journey gate (it writes <span class="font-mono">porting/evidence/</span>).</p>
                @else
                    <p class="mt-1 text-sm text-muted-foreground">Not available.</p>
                @endif
                @foreach($quality['evidence']['errors'] ?? [] as $err)
                    <p class="text-xs font-semibold text-destructive">{{ $err }}</p>
                @endforeach
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Declaration drift</p>
                @if($quality['drift']['available'] ?? false)
                    <p class="mt-1 text-sm font-semibold {{ $quality['drift']['errors'] === 0 ? 'text-emerald-600' : 'text-destructive' }}">{{ $quality['drift']['errors'] }} error(s) · {{ $quality['drift']['warnings'] }} warning(s)</p>
                    <p class="text-xs text-muted-foreground">Mind vs roles, registry and Bodies — details: <span class="font-mono">php artisan slice:drift</span></p>
                @else
                    <p class="mt-1 text-sm text-muted-foreground">No Mind recorded — drift is not measurable.</p>
                @endif
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Declaration history</p>
                @if($quality['history']['available'] ?? false)
                    <p class="mt-1 text-sm font-semibold text-foreground">{{ $quality['history']['entries'] }} entr(ies) · {{ $quality['history']['tracked'] }} declaration files tracked</p>
                    @foreach($quality['history']['latest'] as $h)
                        <p class="text-xs text-muted-foreground">{{ $h['recorded_at'] }} · {{ $h['actor'] }}{{ !empty($h['by']) ? ' (by '.$h['by'].')' : '' }} · {{ $h['changes'] }} change(s)</p>
                    @endforeach
                    <p class="text-xs text-muted-foreground">Diffs: <span class="font-mono">php artisan slice:history --diff=&lt;id&gt;</span></p>
                @else
                    <p class="mt-1 text-sm text-muted-foreground">Not available.</p>
                @endif
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Runtime portability</p>
                @if($quality['portability']['available'] ?? false)
                    @if($quality['portability']['clean'])
                        <p class="mt-1 text-sm font-semibold text-emerald-600">Runtime-neutral — {{ $quality['portability']['files_scanned'] }} declaration file(s) scanned, no framework leakage.</p>
                    @else
                        <p class="mt-1 text-sm font-semibold text-destructive">{{ $quality['portability']['findings'] }} finding(s) of framework leakage in the declarations.</p>
                        <p class="text-xs text-muted-foreground">Details: <span class="font-mono">php artisan slice:portability</span></p>
                    @endif
                    @if(($quality['portability']['bindings'] ?? 0) > 0)
                        <p class="text-xs text-muted-foreground">{{ $quality['portability']['bindings'] }} runtime binding(s) in Hearts (namespace lines) — expected.</p>
                    @endif
                @else
                    <p class="mt-1 text-sm text-muted-foreground">Not available.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-border bg-card p-4 mb-6">
        <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Version control — declarations</h3>
        @if(($git['available'] ?? false))
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full bg-muted px-2 py-0.5 font-mono text-[10px]">branch: {{ $git['branch'] !== '' ? $git['branch'] : '(unknown)' }}</span>
                @if(($git['dirty_count'] ?? 0) === 0)
                    <span class="font-semibold text-emerald-600">All declaration files are committed — the Mind, Bodies and Hearts in Git match the files on disk.</span>
                @else
                    <span class="font-semibold text-amber-600">{{ $git['dirty_count'] }} declaration file(s) carry uncommitted changes:</span>
                @endif
            </div>
            @if(($git['dirty_count'] ?? 0) > 0)
                <ul class="mt-2 space-y-1">
                    @foreach(array_slice($git['dirty'], 0, 8) as $d)
                        <li class="font-mono text-[11px] text-muted-foreground"><span class="text-foreground/70">{{ $d['status'] === '??' ? 'untracked' : $d['status'] }}</span> {{ $d['path'] }}</li>
                    @endforeach
                    @if($git['dirty_count'] > 8)
                        <li class="text-[11px] text-muted-foreground">… and {{ $git['dirty_count'] - 8 }} more.</li>
                    @endif
                </ul>
            @endif
            @if(!empty($git['last_commit']))
                <p class="mt-2 text-xs text-muted-foreground">Last declaration commit: <span class="font-mono">{{ $git['last_commit']['hash'] }}</span> {{ $git['last_commit']['subject'] }} ({{ $git['last_commit']['when'] }})</p>
            @endif
        @else
            <p class="mt-1 text-xs leading-relaxed text-muted-foreground">{{ $git['reason'] ?? 'Git state is not available.' }}</p>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-2 pb-6">
        <div class="min-w-0 rounded-xl border border-border bg-card p-4">
            <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Recent audit activity</h3>
            <ul class="mt-2 space-y-1.5">
                @forelse($audit as $entry)
                    <li class="text-xs"><span class="font-semibold text-foreground">{{ $entry['action'] ?? '' }}</span>
                        <span class="break-all text-muted-foreground">{{ $entry['slice'] ?? '' }} {{ $entry['entity_type'] ?? '' }}#{{ $entry['entity_id'] ?? '' }} · {{ $entry['actor_email'] ?? 'system' }} · {{ $entry['created_at'] ?? '' }}</span></li>
                @empty
                    <li class="text-xs text-muted-foreground">No audit entries.</li>
                @endforelse
            </ul>
        </div>

        <div class="min-w-0 rounded-xl border border-border bg-card p-4">
            <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Access review</h3>
            <ul class="mt-2 space-y-2">
                @foreach($access as $entry)
                    <li class="text-sm">
                        <span class="font-semibold text-foreground">{{ $entry['user'] }}</span>
                        <span class="text-xs text-muted-foreground">{{ $entry['email'] }}</span>
                        <p class="text-xs text-muted-foreground">Roles: {{ $entry['roles'] === [] ? '—' : implode(', ', $entry['roles']) }}</p>
                        @foreach($entry['flags'] as $flag)
                            <p class="text-xs font-semibold text-amber-600">⚠ {{ $flag }}</p>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>

<script>
function operateStudio() {
    return {
        busy: false,
        created: '',
        backups: [],
        async post(url) {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
                body: '{}',
            });
            return res.json();
        },
        async runBackup() {
            this.busy = true;
            const data = await this.post(@js(route('laraslice.studio.operate_backup')));
            this.created = data.created || '';
            this.backups = data.backups || [];
            this.busy = false;
        },
        async generateManual() {
            this.busy = true;
            const data = await this.post(@js(route('laraslice.studio.operate_manual')));
            const blob = new Blob([data.markdown || ''], { type: 'text/markdown' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'operator-manual.md';
            a.click();
            URL.revokeObjectURL(a.href);
            this.busy = false;
        },
    };
}
</script>
