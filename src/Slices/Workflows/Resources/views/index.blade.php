@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white flex items-center gap-2.5">
                <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                Workflows & State Machines
            </h1>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Enterprise Business Process Automation (BPA), multi-tier approvals, dynamic department routing & SLA escalation.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" onclick="window.openCreateWorkflowModal()" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>+ Create Workflow</span>
            </button>
            <a href="{{ route('workflows.inbox') }}" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 shadow-xs transition">
                <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                <span>My Approvals Inbox</span>
            </a>
            <a href="{{ route('workflows.logs') }}" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-700/60 shadow-xs transition">
                <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>Global Audit Logs</span>
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Workflow Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($workflows as $wf)
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm flex flex-col justify-between hover:shadow-md transition">
                <div>
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                            {{ $wf->domain ?: 'Core System' }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-xs {{ $wf->is_active ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-slate-400' }}">
                            <span class="w-2 h-2 rounded-full {{ $wf->is_active ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                            {{ $wf->is_active ? 'Active' : 'Disabled' }}
                        </span>
                    </div>

                    <h3 class="text-base font-bold text-slate-900 dark:text-white mb-1">
                        {{ $wf->name }}
                    </h3>
                    <p class="text-xs font-mono text-slate-400 dark:text-slate-500 mb-4">
                        Slug: {{ $wf->slug }} &bull; Slice: {{ $wf->slice_name ?: 'Global' }}
                    </p>

                    <div class="grid grid-cols-2 gap-3 py-3 border-y border-slate-100 dark:border-slate-800 text-xs text-slate-600 dark:text-slate-400 mb-4">
                        <div>
                            <span class="block text-[11px] text-slate-400 uppercase">Stages</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ $wf->states_count }} Stages</span>
                        </div>
                        <div>
                            <span class="block text-[11px] text-slate-400 uppercase">Transitions</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ $wf->transitions_count }} Rules</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <span class="text-[11px] text-slate-400">
                        {{ $wf->entity_model ? class_basename($wf->entity_model) : 'Dynamic Model' }}
                    </span>
                    <a href="{{ route('workflows.show', $wf->id) }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition">
                        <span>Edit & Adjust Pipeline</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>
            </div>
        @empty
            <div class="col-span-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-12 text-center">
                <svg class="w-12 h-12 text-slate-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <h3 class="text-base font-bold text-slate-800 dark:text-slate-200">No Workflows Registered</h3>
                <p class="text-xs text-slate-500 mt-1 mb-4">Provision from pre-packaged enterprise templates or define in slice.json.</p>
                <form action="{{ route('workflows.seed_examples') }}" method="POST">
                    @csrf
                    <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-sm transition">
                        Seed Enterprise Workflow Templates
                    </button>
                </form>
            </div>
        @endforelse
    </div>
</div>

<!-- Modal: Create Workflow -->
<div id="create-wf-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true">

<!-- Modal: Create Workflow -->
<div id="create-wf-modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity z-10" onclick="window.closeCreateWorkflowModal()"></div>

    <div class="fixed inset-0 z-20 flex items-center justify-center p-4 sm:p-6 overflow-y-auto pointer-events-none">
        <div class="relative w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl text-left shadow-2xl border border-slate-200 dark:border-slate-800 pointer-events-auto transform transition-all my-auto overflow-hidden">
            <form action="{{ route('workflows.store') }}" method="POST">
                @csrf
                <div class="px-6 pt-6 pb-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 text-indigo-600 dark:text-indigo-400 font-bold">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">Create New Workflow</h3>
                                <p class="text-xs text-slate-500">Define a state machine and bind it to a slice</p>
                            </div>
                        </div>
                        <button type="button" onclick="window.closeCreateWorkflowModal()" class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-3.5 text-xs">
                        <div>
                            <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Workflow Name *</label>
                            <input type="text" name="name" required placeholder="e.g. Procurement Requisition Flow" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Workflow Slug *</label>
                            <input type="text" name="slug" required placeholder="e.g. procurement_requisitions" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Attach to Vertical Slice (Optional)</label>
                            <select name="slice_name" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                                <option value="">None (Generic / Cross-Slice)</option>
                                @foreach($slices ?? [] as $sl)
                                    <option value="{{ $sl->name }}">{{ $sl->name }} ({{ $sl->domain ?? 'General' }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Business Domain</label>
                                <input type="text" name="domain" placeholder="e.g. Operations" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Initial Stage Label</label>
                                <input type="text" name="initial_state_label" value="Draft" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="window.closeCreateWorkflowModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-xs">Create Workflow</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.openCreateWorkflowModal = function() {
    document.getElementById('create-wf-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
};
window.closeCreateWorkflowModal = function() {
    document.getElementById('create-wf-modal').classList.add('hidden');
    document.body.style.overflow = '';
};
</script>
@endsection