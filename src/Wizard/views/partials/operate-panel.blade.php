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

    <div class="grid gap-4 lg:grid-cols-2 pb-6">
        <div class="rounded-xl border border-border bg-card p-4">
            <h3 class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Recent audit activity</h3>
            <ul class="mt-2 space-y-1.5">
                @forelse($audit as $entry)
                    <li class="text-xs"><span class="font-semibold text-foreground">{{ $entry['action'] ?? '' }}</span>
                        <span class="text-muted-foreground">{{ $entry['slice'] ?? '' }} {{ $entry['entity_type'] ?? '' }}#{{ $entry['entity_id'] ?? '' }} · {{ $entry['actor_email'] ?? 'system' }} · {{ $entry['created_at'] ?? '' }}</span></li>
                @empty
                    <li class="text-xs text-muted-foreground">No audit entries.</li>
                @endforelse
            </ul>
        </div>

        <div class="rounded-xl border border-border bg-card p-4">
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
