{{--
    Tab content contract v2 — a resolved hub tab is a WORKING list,
    not a picture of one. Everything here is driven by the related
    slice's own Heart (prepared by DirectoryUi): its declared
    columns, its routes (scoped Add + per-row view/edit/delete),
    its declared inline editor (select posting to the slice's
    inline endpoint), and the rows the DataTableRows pipeline
    rendered. Styling uses semantic utility classes only — the
    behavior script is kit-independent vanilla JS, emitted once.
--}}
@php
    $inline = $rtab['inline'] ?? null;
    $hasActions = collect($rtab['rows'] ?? [])->contains(fn ($r) => !empty($r['show_url']) || !empty($r['edit_url']) || !empty($r['delete_url']));
@endphp
<div data-tab-list data-export-name="{{ $rtab['export_name'] ?? 'export' }}" data-csrf="{{ csrf_token() }}">
    @if(!empty($rtab['create_url']) || !empty($rtab['rows']))
    <div class="flex items-center gap-2 mb-3">
        @if(!empty($rtab['create_url']))
        <a href="{{ $rtab['create_url'] }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-2xs transition-colors">
            <x-lucide-plus class="size-3.5" />
            <span>{{ $rtab['create_label'] ?? 'Add' }}</span>
        </a>
        @endif
        @if(!empty($rtab['rows']))
        <button type="button" data-tab-export class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border bg-card text-foreground hover:bg-muted text-xs font-semibold shadow-2xs transition-colors cursor-pointer">
            <x-lucide-download class="size-3.5" />
            <span>Export</span>
        </button>
        @endif
    </div>
    @endif

    <div class="rounded-xl border border-border overflow-hidden bg-card">
        <table class="w-full text-xs" data-tab-table>
            <thead class="bg-muted/40 border-b border-border">
                <tr>
                    @foreach($rtab['columns'] as $col)
                    <th class="px-3 py-2 text-start font-semibold">{{ $col['label'] }}</th>
                    @endforeach
                    @if($hasActions)
                    <th class="px-3 py-2 text-end font-semibold">Action</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-border/50">
                @foreach($rtab['rows'] as $row)
                <tr class="hover:bg-muted/30" data-tab-row>
                    @foreach($rtab['columns'] as $col)
                    <td class="px-3 py-2">
                        @if($inline && ($col['key'] ?? null) === $inline['field'])
                        <select data-inline-select data-url="{{ str_replace('__ID__', (string) ($row['__id'] ?? ''), $inline['url']) }}" data-field="{{ $inline['field'] }}" class="rounded-lg border border-border bg-card px-2 py-1 text-xs font-medium cursor-pointer">
                            @if(!collect($inline['options'])->pluck('value')->contains((string) ($row['__raw_'.$inline['field']] ?? '')))
                            <option value="{{ $row['__raw_'.$inline['field']] ?? '' }}" selected>{{ $row['__raw_'.$inline['field']] ?? '' }}</option>
                            @endif
                            @foreach($inline['options'] as $opt)
                            <option value="{{ $opt['value'] }}" @selected((string) ($row['__raw_'.$inline['field']] ?? '') === (string) $opt['value'])>{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                        @elseif(!empty($col['html']))
                        {!! $row[$col['key']] ?? '—' !!}
                        @else
                        {{ $row[$col['key']] ?? '—' }}
                        @endif
                    </td>
                    @endforeach
                    @if($hasActions)
                    <td class="px-3 py-2">
                        <div class="flex items-center gap-1 justify-end">
                            @if(!empty($row['show_url']))
                            <a href="{{ $row['show_url'] }}" class="inline-flex items-center justify-center size-7 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors" title="View"><x-lucide-eye class="size-3.5" /></a>
                            @endif
                            @if(!empty($row['edit_url']))
                            <a href="{{ $row['edit_url'] }}" class="inline-flex items-center justify-center size-7 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors" title="Edit"><x-lucide-pencil class="size-3.5" /></a>
                            @endif
                            @if(!empty($row['delete_url']))
                            <form method="POST" action="{{ $row['delete_url'] }}" class="inline" onsubmit="return confirm('Delete this record?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center justify-center size-7 rounded-lg text-muted-foreground hover:text-red-500 hover:bg-muted transition-colors cursor-pointer" title="Delete"><x-lucide-trash-2 class="size-3.5" /></button>
                            </form>
                            @endif
                        </div>
                    </td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between mt-3 text-xs text-muted-foreground" data-tab-pager>
        <span data-tab-info></span>
        <span class="flex items-center gap-1">
            <button type="button" data-tab-prev class="px-2.5 py-1 rounded-lg border border-border bg-card font-semibold hover:bg-muted transition-colors cursor-pointer">Previous</button>
            <span data-tab-pages class="flex items-center gap-1"></span>
            <button type="button" data-tab-next class="px-2.5 py-1 rounded-lg border border-border bg-card font-semibold hover:bg-muted transition-colors cursor-pointer">Next</button>
        </span>
    </div>
</div>

@once
<script>
if (!window.__lsTabList) {
    window.__lsTabList = true;
    const PER_PAGE = 10;
    function wireTabList(root) {
        const rows = [...root.querySelectorAll('[data-tab-row]')];
        const pager = root.querySelector('[data-tab-pager]');
        let page = 1;
        const pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
        function render() {
            rows.forEach((r, i) => { r.style.display = (i >= (page - 1) * PER_PAGE && i < page * PER_PAGE) ? '' : 'none'; });
            if (pager) {
                const info = pager.querySelector('[data-tab-info]');
                const from = rows.length === 0 ? 0 : (page - 1) * PER_PAGE + 1;
                const to = Math.min(rows.length, page * PER_PAGE);
                if (info) info.textContent = 'Showing ' + from + ' to ' + to + ' of ' + rows.length + ' entries';
                const box = pager.querySelector('[data-tab-pages]');
                if (box) {
                    box.innerHTML = '';
                    for (let p = 1; p <= pages; p++) {
                        const b = document.createElement('button');
                        b.type = 'button'; b.textContent = p;
                        b.className = 'px-2.5 py-1 rounded-lg border font-semibold cursor-pointer ' + (p === page ? 'bg-primary text-primary-foreground border-primary' : 'border-border bg-card hover:bg-muted');
                        b.addEventListener('click', () => { page = p; render(); });
                        box.appendChild(b);
                    }
                }
                pager.style.display = rows.length <= PER_PAGE ? 'none' : '';
            }
        }
        const prev = root.querySelector('[data-tab-prev]');
        const next = root.querySelector('[data-tab-next]');
        if (prev) prev.addEventListener('click', () => { if (page > 1) { page--; render(); } });
        if (next) next.addEventListener('click', () => { if (page < pages) { page++; render(); } });
        render();

        const exportBtn = root.querySelector('[data-tab-export]');
        if (exportBtn) exportBtn.addEventListener('click', () => {
            const table = root.querySelector('[data-tab-table]');
            if (!table) return;
            const esc = (s) => '"' + String(s ?? '').replace(/"/g, '""') + '"';
            const lines = [];
            lines.push([...table.querySelectorAll('thead th')].filter(th => th.textContent.trim() !== 'Action').map(th => esc(th.textContent.trim())).join(','));
            rows.forEach(r => {
                const cells = [...r.querySelectorAll('td')];
                const dataCells = cells.slice(0, cells.length - (r.querySelector('form') || r.querySelector('a[title="View"]') ? 1 : 0));
                lines.push(dataCells.map(td => {
                    const sel = td.querySelector('select');
                    return esc(sel ? sel.options[sel.selectedIndex].text : td.textContent.trim());
                }).join(','));
            });
            const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = (root.dataset.exportName || 'export') + '.csv';
            a.click();
            URL.revokeObjectURL(a.href);
        });

        root.querySelectorAll('[data-inline-select]').forEach(sel => {
            sel.addEventListener('change', async () => {
                const prevValue = sel.dataset.current ?? sel.value;
                sel.dataset.current = sel.value;
                try {
                    const res = await fetch(sel.dataset.url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf, 'Accept': 'application/json' },
                        body: JSON.stringify({ field: sel.dataset.field, value: sel.value })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || data.success === false) throw new Error(data.message || 'Update failed');
                    sel.classList.add('ring-1', 'ring-emerald-500');
                    setTimeout(() => sel.classList.remove('ring-1', 'ring-emerald-500'), 1200);
                } catch (e) {
                    sel.value = prevValue;
                    alert(e.message || 'Update failed');
                }
            });
            sel.dataset.current = sel.value;
        });
    }
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-tab-list]').forEach(wireTabList);
    });
}
</script>
@endonce
