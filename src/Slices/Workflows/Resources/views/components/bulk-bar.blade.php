{{--
  Index-page bulk workflow actions.
  @props:
    slice  - Studly slice name (e.g. Invoices)
    transitions - optional preloaded list of {slug,label,requires_remarks}
--}}
@props([
    'slice' => '',
    'transitions' => null,
])

@php
    $slice = $slice ?: ($sliceName ?? '');
    $bulkUrl = \Illuminate\Support\Facades\Route::has('workflows.bulk_transition')
        ? route('workflows.bulk_transition', ['slice' => $slice], false)
        : '#';
@endphp

<div
    x-data="workflowBulkBar({
        bulkUrl: @js($bulkUrl),
        csrf: @js(csrf_token()),
        transitions: @js($transitions ?? []),
    })"
    x-show="selectedCount > 0"
    x-cloak
    class="sticky top-0 z-20 mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-indigo-500/30 bg-indigo-500/5 px-3 py-2.5 shadow-xs"
>
    <span class="text-xs font-bold text-indigo-700 dark:text-indigo-300" x-text="selectedCount + ' selected'"></span>
    <span class="text-[10px] uppercase tracking-wider text-muted-foreground">Bulk forward</span>

    <template x-for="t in (transitions.length ? transitions : defaultTransitions)" :key="t.slug">
        <button
            type="button"
            @click="runTransition(t)"
            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1.5 text-xs font-semibold text-foreground hover:bg-muted transition"
        >
            <span x-text="t.label || t.slug"></span>
        </button>
    </template>

    <button type="button" @click="clearSelection()" class="ml-auto text-xs text-muted-foreground hover:text-foreground">Clear</button>

    {{-- Remarks modal for requires_remarks transitions --}}
    <div x-show="showRemarks" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showRemarks = false">
        <div class="absolute inset-0 bg-slate-950/60" @click="showRemarks = false"></div>
        <div class="relative w-full max-w-md rounded-2xl border border-border bg-card p-5 shadow-xl space-y-3">
            <h3 class="text-sm font-bold" x-text="'Forward: ' + (pending?.label || '')"></h3>
            <textarea x-model="remarks" rows="3" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm" placeholder="Remarks / note (optional or required)"></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" @click="showRemarks = false" class="px-3 py-1.5 text-xs rounded-lg border border-border">Cancel</button>
                <button type="button" @click="submitWithRemarks()" class="px-3 py-1.5 text-xs rounded-lg bg-indigo-600 text-white font-bold">Apply</button>
            </div>
        </div>
    </div>
</div>

<script>
function workflowBulkBar(cfg) {
    return {
        bulkUrl: cfg.bulkUrl,
        csrf: cfg.csrf,
        transitions: cfg.transitions || [],
        defaultTransitions: [
            { slug: 'submit', label: 'Submit', requires_remarks: false },
            { slug: 'approve', label: 'Approve', requires_remarks: true },
            { slug: 'reject', label: 'Reject', requires_remarks: true },
        ],
        selectedCount: 0,
        showRemarks: false,
        pending: null,
        remarks: '',
        init() {
            this._ids = [];
            window.addEventListener('laraslice-selection-changed', (e) => {
                this.selectedCount = (e.detail && e.detail.count) || 0;
                this._ids = (e.detail && e.detail.ids) || [];
            });
            // Poll checkbox fallbacks for non data-table lists
            setInterval(() => {
                const boxes = document.querySelectorAll('[data-row-select]:checked, input.row-select:checked, input[name="ids[]"]:checked');
                if (boxes.length && boxes.length !== this.selectedCount) {
                    this.selectedCount = boxes.length;
                    this._ids = Array.from(boxes).map(el => el.value).filter(Boolean);
                }
            }, 800);
        },
        selectedIds() {
            if (this._ids && this._ids.length) return this._ids;
            return Array.from(document.querySelectorAll('[data-row-select]:checked, input.row-select:checked, input[name="ids[]"]:checked'))
                .map(el => el.value).filter(Boolean);
        },
        clearSelection() {
            document.querySelectorAll('[data-row-select]:checked, input.row-select:checked, input[name=\"ids[]\"]:checked').forEach(el => { el.checked = false; });
            this.selectedCount = 0;
        },
        runTransition(t) {
            if (!this.selectedIds().length) return;
            if (t.requires_remarks) {
                this.pending = t;
                this.remarks = '';
                this.showRemarks = true;
                return;
            }
            this.post(t.slug, '');
        },
        submitWithRemarks() {
            if (!this.pending) return;
            this.showRemarks = false;
            this.post(this.pending.slug, this.remarks);
            this.pending = null;
        },
        async post(transition, remarks) {
            const ids = this.selectedIds();
            const body = new FormData();
            body.append('_token', this.csrf);
            body.append('transition', transition);
            body.append('remarks', remarks || '');
            ids.forEach(id => body.append('ids[]', id));
            try {
                const res = await fetch(this.bulkUrl, { method: 'POST', body, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json().catch(() => ({}));
                if (data.success === false) {
                    alert(data.message || 'Bulk transition failed');
                } else {
                    window.location.reload();
                }
            } catch (e) {
                alert(e.message || 'Network error');
            }
        }
    }
}
</script>