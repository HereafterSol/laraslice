@if(!empty($rtab['lazy']))
    @include('laraslice::partials.related-tab-lazy', ['rtab' => $rtab])
@else
{{--
    Related-tab PERMISSIONS panel (declared type:
    permissions): the record's person's module permission
    matrix — one row per module, the module's main
    permissions as the Add / View / Update / Delete columns
    (further mains extend the row, as the source does), each
    cell the EFFECTIVE permission type (added / owned / both
    / all / none) from role grants plus per-user
    customisations. Mirrors the source employee hub's
    Permissions tab: a customised user carries the warning
    banner with a Reset action; an admin-role user shows the
    admin notice instead of the matrix; the module's custom
    permissions expand inline behind More. Editable inline
    (per-cell type selects posting to the framework's
    per-user endpoints) only for viewers who may manage the
    record — everyone else gets the faithful read-only
    matrix.
--}}
@php
    $matrix = $rtab['matrix'] ?? null;
    $editable = ($rtab['editable'] ?? false) && ! empty($rtab['update_url']);
    $rootId = 'ls-perms-'.($rtab['handle'] ?? 'permissions');
    $maxMain = 4;
    foreach (($matrix['modules'] ?? []) as $m) {
        $maxMain = max($maxMain, count($m['main'] ?? []));
    }
@endphp
@if($matrix === null)
    <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
        Permission data is not available for this record.
    </div>
@elseif($matrix['admin_role'])
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        This user has the Admin role and has full access to all modules — module permissions are not managed per-permission for admins.
    </div>
@else
    <div id="{{ $rootId }}" data-update-url="{{ $rtab['update_url'] ?? '' }}" data-reset-url="{{ $rtab['reset_url'] ?? '' }}" data-token="{{ csrf_token() }}" class="space-y-3">
        <div data-ls-perms-banner @if(! $matrix['customised']) hidden @endif class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <span>This user has customised permissions that differ from their role's permissions.</span>
            @if($editable && ! empty($rtab['reset_url']))
                <button type="button" data-ls-perms-reset class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white">Reset to role permissions</button>
            @endif
        </div>
        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border/60 bg-muted/30 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        <th class="px-4 py-2.5">Module</th>
                        @foreach(['Add', 'View', 'Update', 'Delete'] as $h)
                            <th class="px-3 py-2.5">{{ $h }}</th>
                        @endforeach
                        @for($i = 4; $i < $maxMain; $i++)
                            <th class="px-3 py-2.5"></th>
                        @endfor
                        <th class="px-3 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($matrix['modules'] as $module)
                        <tr class="border-b border-border/40 align-top">
                            <td class="px-4 py-2.5 font-medium text-foreground">{{ $module['name'] }}</td>
                            @for($i = 0; $i < $maxMain; $i++)
                                <td class="px-3 py-2.5">
                                    @if(isset($module['main'][$i]))
                                        @php $p = $module['main'][$i]; @endphp
                                        @if($editable)
                                            @php $allowedNames = array_column($p['allowed'], 'name'); @endphp
                                            <select data-ls-perm-select data-permission-id="{{ $p['id'] }}" class="rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground">
                                                @if(! in_array($p['effective'], $allowedNames, true))
                                                    <option value="" selected>{{ ucfirst($p['effective']) }}</option>
                                                @endif
                                                @foreach($p['allowed'] as $opt)
                                                    <option value="{{ $opt['id'] }}" @selected($opt['name'] === $p['effective'])>{{ ucfirst($opt['name']) }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="text-foreground/90">{{ ucfirst($p['effective']) }}</span>@if($p['customised'])<span class="text-amber-600" title="Customised for this user"> •</span>@endif
                                        @endif
                                    @else
                                        <span class="text-muted-foreground">—</span>
                                    @endif
                                </td>
                            @endfor
                            <td class="px-3 py-2.5">
                                @if(count($module['custom']) > 0)
                                    <button type="button" data-ls-perms-more="{{ $rootId }}-{{ $module['id'] }}" class="rounded-md border border-border px-2.5 py-1 text-xs font-semibold text-muted-foreground">More ({{ count($module['custom']) }})</button>
                                @endif
                            </td>
                        </tr>
                        @if(count($module['custom']) > 0)
                            <tr id="{{ $rootId }}-{{ $module['id'] }}" class="hidden border-b border-border/40 bg-muted/20">
                                <td colspan="{{ $maxMain + 2 }}" class="px-4 py-3">
                                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach($module['custom'] as $p)
                                            <label class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-card px-3 py-2 text-xs">
                                                <span class="font-medium text-foreground">{{ $p['label'] }}</span>
                                                @if($editable)
                                                    @php $allowedNames = array_column($p['allowed'], 'name'); @endphp
                                                    <select data-ls-perm-select data-permission-id="{{ $p['id'] }}" class="rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground">
                                                        @if(! in_array($p['effective'], $allowedNames, true))
                                                            <option value="" selected>{{ ucfirst($p['effective']) }}</option>
                                                        @endif
                                                        @foreach($p['allowed'] as $opt)
                                                            <option value="{{ $opt['id'] }}" @selected($opt['name'] === $p['effective'])>{{ ucfirst($opt['name']) }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="text-foreground/90">{{ ucfirst($p['effective']) }}@if($p['customised'])<span class="text-amber-600" title="Customised for this user"> •</span>@endif</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        <p data-ls-perms-note hidden class="text-xs font-medium text-muted-foreground"></p>
    </div>
    <script>
    (function () {
        var root = document.getElementById(@json($rootId));
        if (!root || root.dataset.lsPermsBound) return;
        root.dataset.lsPermsBound = '1';
        var token = root.dataset.token;
        var note = root.querySelector('[data-ls-perms-note]');
        function say(msg) { if (note) { note.hidden = false; note.textContent = msg; } }
        root.querySelectorAll('[data-ls-perm-select]').forEach(function (sel) {
            sel.dataset.current = sel.value;
            sel.addEventListener('change', function () {
                var body = new URLSearchParams();
                body.set('permission_id', sel.dataset.permissionId);
                body.set('permission_type_id', sel.value);
                body.set('_token', token);
                fetch(root.dataset.updateUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (!res.ok) { sel.value = sel.dataset.current; say((res.j && res.j.message) || 'That change was refused.'); return; }
                        sel.dataset.current = sel.value;
                        var banner = root.querySelector('[data-ls-perms-banner]');
                        if (banner) banner.hidden = false;
                        say('Saved — this user now has customised permissions.');
                    })
                    .catch(function () { sel.value = sel.dataset.current; say('Save failed — check your connection and retry.'); });
            });
        });
        root.querySelectorAll('[data-ls-perms-more]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = document.getElementById(btn.dataset.lsPermsMore);
                if (row) row.classList.toggle('hidden');
            });
        });
        var resetBtn = root.querySelector('[data-ls-perms-reset]');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var body = new URLSearchParams();
                body.set('_token', token);
                fetch(root.dataset.resetUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: body, credentials: 'same-origin' })
                    .then(function () { window.location.reload(); })
                    .catch(function () { say('Reset failed — check your connection and retry.'); });
            });
        }
    })();
    </script>
@endif

@endif
