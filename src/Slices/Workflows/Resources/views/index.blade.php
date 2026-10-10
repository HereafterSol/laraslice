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

        <div class="flex items-center gap-3">
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
                        Slug: {{ $wf->slug }} &bull; {{ $wf->slice_name ?: 'Global' }}
                    </p>

                    <div class="grid grid-cols-2 gap-3 py-3 border-y border-slate-100 dark:border-slate-800 text-xs text-slate-600 dark:text-slate-400 mb-4">
                        <div>
                            <span class="block text-[11px] text-slate-400 uppercase">States</span>
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
                        <span>View Pipeline</span>
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
@endsection