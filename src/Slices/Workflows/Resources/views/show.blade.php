@extends('layouts.app')

@section('content')
@php
    $systemRoles = \Illuminate\Support\Facades\Schema::hasTable('roles')
        ? \Illuminate\Support\Facades\DB::table('roles')->orderBy('name')->get()
        : collect();
@endphp

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Header with Back & Workflow Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center gap-3">
            <a href="{{ route('workflows.index') }}" class="p-2 rounded-xl text-slate-500 hover:text-slate-800 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ $workflow->name }}
                    </h1>
                    @if($workflow->domain)
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                            {{ $workflow->domain }}
                        </span>
                    @endif
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 font-mono mt-1">
                    Model: {{ $workflow->entity_model ?: 'Dynamic' }} &bull; Slug: {{ $workflow->slug }} &bull; Slice: {{ $workflow->slice_name ?: 'Global' }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            @if($workflow->slice_name)
                <form action="{{ route('workflows.sync_manifest', $workflow->id) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 rounded-xl hover:bg-emerald-100 transition shadow-2xs">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        <span>Sync to slice.yaml</span>
                    </button>
                </form>
            @endif

            <button type="button" onclick="window.openAddStateModal()" class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-xs transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>+ Add Stage</span>
            </button>

            <button type="button" onclick="window.openAddTransitionModal()" class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl shadow-xs transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                <span>+ Add Transition Rule</span>
            </button>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (! empty($errors) && $errors->any())
        <div class="p-4 rounded-xl border border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400 text-sm font-medium">
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Pipeline Stages Designer -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Pipeline Stages Flow ({{ $workflow->states->count() }} Stages)
            </h3>
            <span class="text-xs text-slate-400">Stages define allowable lifecycles &bull; Click pencil icon to edit</span>
        </div>

        <div class="flex flex-wrap items-center gap-3 py-3">
            @forelse($workflow->states as $index => $state)
                <div class="flex items-center gap-3">
                    <div class="group relative p-3.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 flex items-center gap-3 shadow-xs hover:border-indigo-400 transition">
                        <span class="w-7 h-7 rounded-full bg-indigo-100 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs font-bold">
                            {{ $index + 1 }}
                        </span>
                        <div>
                            <p class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                                {{ $state->label }}
                                @if($state->is_initial)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] bg-sky-100 dark:bg-sky-950/60 text-sky-700 dark:text-sky-400 font-semibold">Initial</span>
                                @endif
                                @if($state->is_terminal)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-semibold">Terminal</span>
                                @endif
                            </p>
                            <p class="text-[10px] text-slate-400 font-mono mt-0.5">
                                {{ $state->slug }} &bull; {{ $state->sla_hours ? $state->sla_hours.'h SLA' : 'No SLA' }}
                            </p>
                        </div>

                        <!-- Stage Actions Toolbar: Edit & Delete -->
                        <div class="flex items-center gap-1 pl-2 border-l border-slate-200 dark:border-slate-700">
                            <button
                                type="button"
                                onclick="window.openEditStateModal({{ json_encode($state) }})"
                                class="p-1.5 text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-slate-200/60 dark:hover:bg-slate-700 rounded-lg transition"
                                title="Edit Stage Parameters"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                            </button>

                            @if(! $state->is_initial)
                                <form action="{{ route('workflows.delete_state', [$workflow->id, $state->id]) }}" method="POST" class="inline" onsubmit="return confirm('Delete stage \'{{ $state->label }}\' and all connected transitions?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-slate-200/60 dark:hover:bg-slate-700 rounded-lg transition" title="Delete Stage">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @if(! $loop->last)
                        <svg class="w-4 h-4 text-slate-300 dark:text-slate-600 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    @endif
                </div>
            @empty
                <p class="text-xs text-slate-400 py-4">No stages configured yet. Click "+ Add Stage" above to begin.</p>
            @endforelse
        </div>
    </div>

    <!-- Transition Matrix & Routing Rules Table -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                Transitions & Inter-Department Routing ({{ $workflow->transitions->count() }} Rules)
            </h3>
            <span class="text-xs text-slate-400">Transitions govern allowable movements & officer assignments</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-400 font-semibold uppercase tracking-wider">
                        <th class="py-3 px-4">Action Label & Slug</th>
                        <th class="py-3 px-4">From Stage</th>
                        <th class="py-3 px-4">To Stage</th>
                        <th class="py-3 px-4">Button Theme</th>
                        <th class="py-3 px-4">Routing / Assignee Target</th>
                        <th class="py-3 px-4">Remarks Gate</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse($workflow->transitions as $trans)
                        @php $rule = $trans->routingRule; @endphp
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-4">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                                    <div>
                                        <p class="font-bold text-slate-800 dark:text-white">{{ $trans->label }}</p>
                                        <p class="font-mono text-[10px] text-slate-400">{{ $trans->slug }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-4 font-mono text-slate-600 dark:text-slate-300">
                                {{ $trans->from_state_slug }}
                            </td>
                            <td class="py-3.5 px-4 font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                {{ $trans->to_state_slug }}
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2 py-0.5 rounded font-mono text-[10px] font-bold uppercase
                                    {{ $trans->button_color === 'success' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : '' }}
                                    {{ $trans->button_color === 'danger' ? 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300' : '' }}
                                    {{ $trans->button_color === 'warning' ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' : '' }}
                                    {{ $trans->button_color === 'secondary' ? 'bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-300' : '' }}
                                    {{ in_array($trans->button_color, ['primary', null, '']) ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300' : '' }}
                                ">
                                    {{ $trans->button_color ?: 'primary' }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4">
                                @if($rule)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 font-medium">
                                        {{ ucfirst($rule->routing_type) }}: {{ $rule->target_role ?: ($rule->target_department ?: 'Dynamic') }}
                                    </span>
                                @else
                                    <span class="text-slate-400">Unassigned</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4">
                                @if($trans->requires_remarks)
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-900">
                                        Mandatory Remarks
                                    </span>
                                @else
                                    <span class="text-slate-400">Optional</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <!-- Edit Transition Button -->
                                    <button
                                        type="button"
                                        onclick="window.openEditTransitionModal({{ json_encode($trans) }}, {{ json_encode($rule) }})"
                                        class="p-1.5 text-slate-400 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition"
                                        title="Edit Transition Rule"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                    </button>

                                    <!-- Delete Transition Button -->
                                    <form action="{{ route('workflows.delete_transition', [$workflow->id, $trans->id]) }}" method="POST" class="inline" onsubmit="return confirm('Delete transition rule \'{{ $trans->label }}\'?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition" title="Delete Transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-slate-400">
                                No transitions defined. Click "+ Add Transition Rule" above to create actions.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Add State -->
<div id="add-state-modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity z-10" onclick="window.closeAddStateModal()"></div>

    <div class="fixed inset-0 z-20 flex items-center justify-center p-4 sm:p-6 overflow-y-auto pointer-events-none">
        <div class="relative w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl text-left shadow-2xl border border-slate-200 dark:border-slate-800 pointer-events-auto transform transition-all my-auto overflow-hidden">
            <form action="{{ route('workflows.add_state', $workflow->id) }}" method="POST">
                @csrf
                <div class="px-6 pt-6 pb-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 text-indigo-600 dark:text-indigo-400 font-bold">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">Add Pipeline Stage</h3>
                                <p class="text-xs text-slate-500">Define a new stage for this workflow</p>
                            </div>
                        </div>
                        <button type="button" onclick="window.closeAddStateModal()" class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-3.5 text-xs">
                        <div>
                            <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Stage Label *</label>
                            <input type="text" name="label" required placeholder="e.g. Scrutiny Committee Review" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Stage Slug *</label>
                            <input type="text" name="slug" required placeholder="e.g. scrutiny_committee" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Badge Color</label>
                                <select name="badge_color" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                                    <option value="slate">Slate</option>
                                    <option value="sky">Sky (Blue)</option>
                                    <option value="amber">Amber (Warning)</option>
                                    <option value="emerald">Emerald (Success)</option>
                                    <option value="indigo">Indigo</option>
                                    <option value="purple">Purple</option>
                                    <option value="rose">Rose (Danger)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">SLA Hours (Optional)</label>
                                <input type="number" name="sla_hours" min="1" placeholder="e.g. 24" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="flex items-center gap-4 pt-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="is_terminal" value="1" class="rounded text-indigo-600 focus:ring-indigo-500">
                                <span class="font-medium text-slate-700 dark:text-slate-300">Terminal (Final Stage)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="window.closeAddStateModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-xs">Save Stage</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Edit State -->
<div id="edit-state-modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity z-10" onclick="window.closeEditStateModal()"></div>

    <div class="fixed inset-0 z-20 flex items-center justify-center p-4 sm:p-6 overflow-y-auto pointer-events-none">
        <div class="relative w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl text-left shadow-2xl border border-slate-200 dark:border-slate-800 pointer-events-auto transform transition-all my-auto overflow-hidden">
            <form id="edit-state-form" method="POST">
                @csrf
                @method('PUT')
                <div class="px-6 pt-6 pb-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 text-indigo-600 dark:text-indigo-400 font-bold">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">Edit Pipeline Stage</h3>
                                <p class="text-xs text-slate-500">Modify label, SLA threshold, and behavior</p>
                            </div>
                        </div>
                        <button type="button" onclick="window.closeEditStateModal()" class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-3.5 text-xs">
                        <div>
                            <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Stage Label *</label>
                            <input type="text" name="label" id="edit-state-label" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Stage Slug (Read Only)</label>
                            <input type="text" id="edit-state-slug" disabled class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-850 bg-slate-100 dark:bg-slate-800 font-mono text-slate-500 cursor-not-allowed">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Badge Color</label>
                                <select name="badge_color" id="edit-state-color" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                                    <option value="slate">Slate</option>
                                    <option value="sky">Sky (Blue)</option>
                                    <option value="amber">Amber (Warning)</option>
                                    <option value="emerald">Emerald (Success)</option>
                                    <option value="indigo">Indigo</option>
                                    <option value="purple">Purple</option>
                                    <option value="rose">Rose (Danger)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">SLA Hours (Optional)</label>
                                <input type="number" name="sla_hours" id="edit-state-sla" min="1" placeholder="e.g. 24" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="flex items-center gap-6 pt-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="is_initial" id="edit-state-initial" value="1" class="rounded text-sky-600 focus:ring-sky-500">
                                <span class="font-medium text-slate-700 dark:text-slate-300">Initial Stage</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="is_terminal" id="edit-state-terminal" value="1" class="rounded text-indigo-600 focus:ring-indigo-500">
                                <span class="font-medium text-slate-700 dark:text-slate-300">Terminal (Final Stage)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="window.closeEditStateModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-xs">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 3: Add Transition -->
<div id="add-trans-modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity z-10" onclick="window.closeAddTransitionModal()"></div>

    <div class="fixed inset-0 z-20 flex items-center justify-center p-4 sm:p-6 overflow-y-auto pointer-events-none">
        <div class="relative w-full max-w-lg bg-white dark:bg-slate-900 rounded-2xl text-left shadow-2xl border border-slate-200 dark:border-slate-800 pointer-events-auto transform transition-all my-auto overflow-hidden">
            <form action="{{ route('workflows.add_transition', $workflow->id) }}" method="POST">
                @csrf
                <div class="px-6 pt-6 pb-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <span class="p-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/70 text-emerald-600 dark:text-emerald-400 font-bold">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">Add Transition Action</h3>
                                <p class="text-xs text-slate-500">Configure allowable state movement & role forwarding</p>
                            </div>
                        </div>
                        <button type="button" onclick="window.closeAddTransitionModal()" class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-3.5 text-xs">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Action Name *</label>
                                <input type="text" name="label" required placeholder="e.g. Forward to Committee" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Action Slug *</label>
                                <input type="text" name="slug" required placeholder="e.g. forward_committee" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">From Stage *</label>
                                <select name="from_state_slug" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                                    <option value="*">* (Any Stage)</option>
                                    @foreach($workflow->states as $st)
                                        <option value="{{ $st->slug }}">{{ $st->label }} ({{ $st->slug }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">To Stage *</label>
                                <select name="to_state_slug" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                                    @foreach($workflow->states as $st)
                                        <option value="{{ $st->slug }}">{{ $st->label }} ({{ $st->slug }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Button Theme</label>
                                <select name="button_color" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                                    <option value="primary">Primary (Blue/Indigo)</option>
                                    <option value="success">Success (Green)</option>
                                    <option value="danger">Danger (Red)</option>
                                    <option value="warning">Warning (Amber)</option>
                                    <option value="secondary">Secondary (Slate)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Permission Gate (Optional)</label>
                                <input type="text" name="permission_gate" placeholder="e.g. tickets.escalate" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                            </div>
                        </div>

                        <!-- Routing Rule -->
                        <div class="p-3.5 bg-slate-50 dark:bg-slate-800/60 rounded-xl border border-slate-200 dark:border-slate-700 space-y-3">
                            <span class="block text-xs font-bold text-slate-800 dark:text-white uppercase tracking-wider">Inter-Department Routing / Handover</span>

                            <div class="grid grid-cols-3 gap-2.5">
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Routing Strategy</label>
                                    <select name="routing_type" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                                        <option value="role">By Role</option>
                                        <option value="department">By Department</option>
                                        <option value="hierarchy_manager">Reporting Manager</option>
                                        <option value="round_robin">Round-Robin</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Target Department</label>
                                    <input type="text" name="target_department" list="studio-dept-options" placeholder="e.g. Engineering & DevOps" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Target Role</label>
                                    <select name="target_role" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                                        <option value="">None / Auto</option>
                                        @foreach($systemRoles as $r)
                                            <option value="{{ $r->slug }}">{{ $r->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="requires_remarks" value="1" checked class="rounded text-emerald-600 focus:ring-emerald-500">
                                <span class="font-medium text-slate-700 dark:text-slate-300">Mandatory Official Remarks</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="requires_attachment" value="1" class="rounded text-emerald-600 focus:ring-emerald-500">
                                <span class="font-medium text-slate-700 dark:text-slate-300">Require Document Attachment</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="window.closeAddTransitionModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-xs">Save Transition Rule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 4: Edit Transition -->
<div id="edit-trans-modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm transition-opacity z-10" onclick="window.closeEditTransitionModal()"></div>

    <div class="fixed inset-0 z-20 flex items-center justify-center p-4 sm:p-6 overflow-y-auto pointer-events-none">
        <div class="relative w-full max-w-lg bg-white dark:bg-slate-900 rounded-2xl text-left shadow-2xl border border-slate-200 dark:border-slate-800 pointer-events-auto transform transition-all my-auto overflow-hidden">
            <form id="edit-trans-form" method="POST">
                @csrf
                @method('PUT')
                <div class="px-6 pt-6 pb-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-2.5">
                            <span class="p-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/70 text-emerald-600 dark:text-emerald-400 font-bold">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">Edit Transition Rule</h3>
                                <p class="text-xs text-slate-500">Update actions, routing, and gates</p>
                            </div>
                        </div>
                        <button type="button" onclick="window.closeEditTransitionModal()" class="p-1 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="mt-4 space-y-3.5 text-xs">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Action Name *</label>
                                <input type="text" name="label" id="edit-trans-label" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Action Slug (Read Only)</label>
                                <input type="text" id="edit-trans-slug" disabled class="w-full px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-850 bg-slate-100 dark:bg-slate-800 font-mono text-slate-500 cursor-not-allowed">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">From Stage *</label>
                                <select name="from_state_slug" id="edit-trans-from" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                                    <option value="*">* (Any Stage)</option>
                                    @foreach($workflow->states as $st)
                                        <option value="{{ $st->slug }}">{{ $st->label }} ({{ $st->slug }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">To Stage *</label>
                                <select name="to_state_slug" id="edit-trans-to" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                                    @foreach($workflow->states as $st)
                                        <option value="{{ $st->slug }}">{{ $st->label }} ({{ $st->slug }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Button Theme</label>
                                <select name="button_color" id="edit-trans-color" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                                    <option value="primary">Primary (Blue/Indigo)</option>
                                    <option value="success">Success (Green)</option>
                                    <option value="danger">Danger (Red)</option>
                                    <option value="warning">Warning (Amber)</option>
                                    <option value="secondary">Secondary (Slate)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Permission Gate (Optional)</label>
                                <input type="text" name="permission_gate" id="edit-trans-perm" placeholder="e.g. tickets.escalate" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-slate-900 dark:text-white focus:ring-2 focus:ring-emerald-500">
                            </div>
                        </div>

                        <!-- Routing Rule -->
                        <div class="p-3.5 bg-slate-50 dark:bg-slate-800/60 rounded-xl border border-slate-200 dark:border-slate-700 space-y-3">
                            <span class="block text-xs font-bold text-slate-800 dark:text-white uppercase tracking-wider">Inter-Department Routing / Handover</span>

                            <div class="grid grid-cols-3 gap-2.5">
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Routing Strategy</label>
                                    <select name="routing_type" id="edit-trans-routing-type" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                                        <option value="role">By Role</option>
                                        <option value="department">By Department</option>
                                        <option value="hierarchy_manager">Reporting Manager</option>
                                        <option value="round_robin">Round-Robin</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Target Department</label>
                                    <input type="text" name="target_department" id="edit-trans-target-dept" list="studio-dept-options" placeholder="e.g. Engineering & DevOps" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-400 mb-1">Target Role</label>
                                    <select name="target_role" id="edit-trans-target-role" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs text-slate-900 dark:text-white">
                                        <option value="">None / Auto</option>
                                        @foreach($systemRoles as $r)
                                            <option value="{{ $r->slug }}">{{ $r->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="requires_remarks" id="edit-trans-remarks" value="1" class="rounded text-emerald-600 focus:ring-emerald-500">
                                <span class="font-medium text-slate-700 dark:text-slate-300">Mandatory Official Remarks</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="requires_attachment" id="edit-trans-attachment" value="1" class="rounded text-emerald-600 focus:ring-emerald-500">
                                <span class="font-medium text-slate-700 dark:text-slate-300">Require Document Attachment</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 flex justify-end gap-2 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="window.closeEditTransitionModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-xs">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<datalist id="studio-dept-options">
    <option value="Customer Support">
    <option value="Engineering & DevOps">
    <option value="Billing & Finance">
    <option value="Legal & Compliance">
    <option value="Grants & Treasury">
    <option value="Executive Operations">
</datalist>

<script>
// Modal 1: Add State
window.openAddStateModal = function() {
    document.getElementById('add-state-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
};
window.closeAddStateModal = function() {
    document.getElementById('add-state-modal').classList.add('hidden');
    document.body.style.overflow = '';
};

// Modal 2: Edit State
window.openEditStateModal = function(state) {
    const form = document.getElementById('edit-state-form');
    form.action = "{{ url('admin/workflows/'.$workflow->id.'/states') }}/" + state.id;

    document.getElementById('edit-state-label').value = state.label || '';
    document.getElementById('edit-state-slug').value = state.slug || '';
    document.getElementById('edit-state-color').value = state.badge_color || 'slate';
    document.getElementById('edit-state-sla').value = state.sla_hours || '';
    document.getElementById('edit-state-initial').checked = !!state.is_initial;
    document.getElementById('edit-state-terminal').checked = !!state.is_terminal;

    document.getElementById('edit-state-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
};
window.closeEditStateModal = function() {
    document.getElementById('edit-state-modal').classList.add('hidden');
    document.body.style.overflow = '';
};

// Modal 3: Add Transition
window.openAddTransitionModal = function() {
    document.getElementById('add-trans-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
};
window.closeAddTransitionModal = function() {
    document.getElementById('add-trans-modal').classList.add('hidden');
    document.body.style.overflow = '';
};

// Modal 4: Edit Transition
window.openEditTransitionModal = function(trans, rule) {
    const form = document.getElementById('edit-trans-form');
    form.action = "{{ url('admin/workflows/'.$workflow->id.'/transitions') }}/" + trans.id;

    document.getElementById('edit-trans-label').value = trans.label || '';
    document.getElementById('edit-trans-slug').value = trans.slug || '';
    document.getElementById('edit-trans-from').value = trans.from_state_slug || '*';
    document.getElementById('edit-trans-to').value = trans.to_state_slug || '';
    document.getElementById('edit-trans-color').value = trans.button_color || 'primary';
    document.getElementById('edit-trans-perm').value = trans.permission_gate || '';
    document.getElementById('edit-trans-remarks').checked = !!trans.requires_remarks;
    document.getElementById('edit-trans-attachment').checked = !!trans.requires_attachment;

    if (rule) {
        document.getElementById('edit-trans-routing-type').value = rule.routing_type || 'role';
        document.getElementById('edit-trans-target-dept').value = rule.target_department || '';
        document.getElementById('edit-trans-target-role').value = rule.target_role || '';
    } else {
        document.getElementById('edit-trans-routing-type').value = 'role';
        document.getElementById('edit-trans-target-dept').value = '';
        document.getElementById('edit-trans-target-role').value = '';
    }

    document.getElementById('edit-trans-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
};
window.closeEditTransitionModal = function() {
    document.getElementById('edit-trans-modal').classList.add('hidden');
    document.body.style.overflow = '';
};
</script>
@endsection
