@props(['slice' => null, 'workflow' => null, 'domain' => null, 'roles' => null])

@php
    $excludedSlugs = ['super-admin', 'super_admin', 'super-administrator', 'admin', 'administrator', 'user', 'standard-user'];
    $filteredRoles = collect();

    // 1. If explicit roles collection/array passed in props
    if (! empty($roles)) {
        $filteredRoles = collect($roles);
    } elseif ($slice) {
        // 2. Try loading domain-bound roles from slice manifest (slice.json)
        $sliceJsonPath = null;
        $matches = glob(base_path("app/Slices/*/{$slice}/slice.json"));
        if (! empty($matches)) {
            $sliceJsonPath = $matches[0];
        } else {
            $studly = \Illuminate\Support\Str::studly($slice);
            $matches = glob(base_path("app/Slices/*/{$studly}/slice.json"));
            if (! empty($matches)) {
                $sliceJsonPath = $matches[0];
            }
        }

        if ($sliceJsonPath && file_exists($sliceJsonPath)) {
            $data = json_decode(file_get_contents($sliceJsonPath), true) ?: [];
            $sliceRoles = $data['roles'] ?? $data['workflow']['roles'] ?? [];
            $sliceDomain = $data['domain'] ?? null;

            if (! empty($sliceRoles)) {
                $filteredRoles = collect($sliceRoles)->map(function ($r) use ($sliceDomain) {
                    return (object) [
                        'slug' => is_array($r) ? ($r['slug'] ?? '') : $r->slug,
                        'name' => is_array($r) ? ($r['name'] ?? '') : $r->name,
                        'domain' => is_array($r) ? ($r['domain'] ?? $sliceDomain) : ($r->domain ?? $sliceDomain),
                    ];
                });
            }
        }

        // Fallback: Query database roles matching slice name / domain
        if ($filteredRoles->isEmpty() && \Illuminate\Support\Facades\Schema::hasTable('roles')) {
            $snake = \Illuminate\Support\Str::snake($slice);
            $singular = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::singular($slice));
            $filteredRoles = \Illuminate\Support\Facades\DB::table('roles')
                ->whereNotIn('slug', $excludedSlugs)
                ->where(function ($q) use ($snake, $singular) {
                    $q->where('slug', 'like', "%{$singular}%")
                      ->orWhere('slug', 'like', "%{$snake}%");
                })
                ->get();
        }
    }

    // 3. Multi-domain / general fallback: Get all operational roles (excluding system super-admin)
    if ($filteredRoles->isEmpty() && \Illuminate\Support\Facades\Schema::hasTable('roles')) {
        $filteredRoles = \Illuminate\Support\Facades\DB::table('roles')
            ->whereNotIn('slug', $excludedSlugs)
            ->get();
    }
@endphp
<div id="workflow-action-modal" class="fixed inset-0 z-50 hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity z-10" onclick="window.closeWorkflowModal()"></div>

    <!-- Centered Dialog Wrapper -->
    <div class="fixed inset-0 z-20 flex items-center justify-center p-4 sm:p-6 overflow-y-auto pointer-events-none">
        <div class="relative w-full max-w-lg bg-white dark:bg-slate-900 rounded-2xl text-left shadow-2xl border border-slate-200 dark:border-slate-800 pointer-events-auto transform transition-all my-auto overflow-hidden">
            <form id="workflow-modal-form" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="transition" id="wf-modal-transition-input">

                <div class="px-6 pt-6 pb-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <span class="p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 text-indigo-600 dark:text-indigo-400 font-bold">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white" id="wf-modal-title">Transition Document</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Review official remarks & target departmental routing</p>
                            </div>
                        </div>
                        <button type="button" onclick="window.closeWorkflowModal()" class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-4">
                        <!-- Remarks / Notes -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Official Remarks & Scrutiny Notes <span id="wf-remarks-required" class="text-rose-500 font-bold">*</span>
                            </label>
                            <textarea
                                name="remarks"
                                id="wf-modal-remarks"
                                rows="3"
                                placeholder="Enter reason, findings, or directives for this transition..."
                                class="w-full px-3.5 py-2.5 text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none shadow-xs"
                            ></textarea>
                        </div>

                        <!-- Forward To Role / Department Routing -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Target Department</label>
                                <input
                                    type="text"
                                    name="assigned_to_department"
                                    id="wf-modal-target-dept"
                                    list="department-options"
                                    placeholder="Select or enter dept..."
                                    class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                >
                                <datalist id="department-options">
                                    <option value="Legal & Compliance">
                                    <option value="Customer Support">
                                    <option value="Billing & Finance">
                                    <option value="Operations & Registry">
                                    <option value="Inquiry & Investigation">
                                </datalist>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Target Domain Role
                                </label>
                                <select
                                    name="assigned_to_role"
                                    id="wf-modal-target-role"
                                    class="w-full px-3 py-2 text-xs rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                                >
                                    <option value="">-- Maintain Current / Auto-Route --</option>
                                    @php
                                        $grouped = $filteredRoles->groupBy(fn($r) => $r->domain ?? 'Other Roles');
                                    @endphp
                                    @if($grouped->count() > 1 && ! $slice)
                                        @foreach($grouped as $groupDomain => $groupRoles)
                                            <optgroup label="{{ $groupDomain }}">
                                                @foreach($groupRoles as $r)
                                                    <option value="{{ $r->slug }}">{{ $r->name }} ({{ $r->slug }})</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    @else
                                        @foreach($filteredRoles as $r)
                                            <option value="{{ $r->slug }}">{{ $r->name }} ({{ $r->slug }})</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                        </div>

                        <!-- File Attachment -->
                        <div id="wf-attachment-group">
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Supporting Document / Findings Report (Optional)
                            </label>
                            <input
                                type="file"
                                name="attachment"
                                class="w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-950/60 dark:file:text-indigo-400 hover:file:bg-indigo-100"
                            >
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex items-center justify-end gap-2.5 border-t border-slate-100 dark:border-slate-800">
                    <button
                        type="button"
                        onclick="window.closeWorkflowModal()"
                        class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        id="wf-modal-submit-btn"
                        class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-sm transition cursor-pointer"
                    >
                        Confirm & Forward
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.openWorkflowModal = function(slug, label, actionUrl, requiresRemarks, requiresAttachment, defaultRole, defaultDept) {
    const modal = document.getElementById('workflow-action-modal');
    const form = document.getElementById('workflow-modal-form');
    const input = document.getElementById('wf-modal-transition-input');
    const title = document.getElementById('wf-modal-title');
    const remarks = document.getElementById('wf-modal-remarks');
    const remarksReq = document.getElementById('wf-remarks-required');
    const roleSelect = document.getElementById('wf-modal-target-role');
    const deptInput = document.getElementById('wf-modal-target-dept');

    if (!modal || !form) return;

    form.action = actionUrl;
    input.value = slug;
    title.innerText = label;
    remarks.value = '';

    // Auto-select targeted role if defined by transition routing
    if (roleSelect && defaultRole) {
        const norm = defaultRole.replace(/_/g, '-');
        let matched = false;
        for (let opt of roleSelect.options) {
            if (opt.value === defaultRole || opt.value === norm) {
                roleSelect.value = opt.value;
                matched = true;
                break;
            }
        }
        if (!matched) roleSelect.value = '';
    } else if (roleSelect) {
        roleSelect.value = '';
    }

    if (deptInput && defaultDept) {
        deptInput.value = defaultDept;
    }

    if (requiresRemarks) {
        remarks.setAttribute('required', 'required');
        if (remarksReq) remarksReq.style.display = 'inline';
    } else {
        remarks.removeAttribute('required');
        if (remarksReq) remarksReq.style.display = 'none';
    }

    modal.classList.remove('hidden');
    // Lock body scroll
    document.body.style.overflow = 'hidden';
    setTimeout(() => { if (remarks) remarks.focus(); }, 100);
};

window.closeWorkflowModal = function() {
    const modal = document.getElementById('workflow-action-modal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }
};
</script>
