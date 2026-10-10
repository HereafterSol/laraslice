@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('workflows.index') }}" class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white flex items-center gap-2">
                    {{ $workflow->name }}
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400">
                        {{ $workflow->domain ?: 'Core' }}
                    </span>
                </h1>
                <p class="text-xs text-slate-500 font-mono mt-0.5">
                    Model: {{ $workflow->entity_model ?: 'App\Models\GenericEntity' }} &bull; Slug: {{ $workflow->slug }}
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <span class="px-3 py-1 rounded-lg text-xs font-semibold {{ $workflow->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">
                {{ $workflow->is_active ? 'Active Engine' : 'Disabled' }}
            </span>
        </div>
    </div>

    <!-- Visual Pipeline Stages -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
        <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Visual Pipeline Stages ({{ $workflow->states->count() }} States)
        </h3>

        <div class="flex flex-wrap items-center gap-3 py-2">
            @foreach($workflow->states as $index => $state)
                <div class="flex items-center gap-3">
                    <div class="p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 flex items-center gap-2.5 shadow-xs">
                        <span class="w-6 h-6 rounded-full bg-indigo-100 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs font-bold">
                            {{ $index + 1 }}
                        </span>
                        <div>
                            <p class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                                {{ $state->label }}
                                @if($state->is_initial)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] bg-sky-100 text-sky-700 font-semibold">Initial</span>
                                @endif
                                @if($state->is_terminal)
                                    <span class="px-1.5 py-0.5 rounded text-[9px] bg-slate-200 text-slate-700 font-semibold">Terminal</span>
                                @endif
                            </p>
                            <p class="text-[10px] text-slate-400 font-mono">
                                {{ $state->slug }} &bull; {{ $state->sla_hours ? $state->sla_hours.'h SLA' : 'No SLA' }}
                            </p>
                        </div>
                    </div>

                    @if(! $loop->last)
                        <svg class="w-4 h-4 text-slate-300 dark:text-slate-600 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <!-- Transitions & Routing Rules Table -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-sm">
        <h3 class="text-sm font-bold text-slate-900 dark:text-white uppercase tracking-wider mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
            Transition Matrix & Forwarding Rules ({{ $workflow->transitions->count() }} Transitions)
        </h3>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-400 font-semibold uppercase tracking-wider">
                        <th class="py-3 px-4">Transition</th>
                        <th class="py-3 px-4">From State</th>
                        <th class="py-3 px-4">To State</th>
                        <th class="py-3 px-4">Permission Gate</th>
                        <th class="py-3 px-4">Routing Target</th>
                        <th class="py-3 px-4">Remarks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    @foreach($workflow->transitions as $trans)
                        @php $rule = $trans->routingRules->first(); @endphp
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-4 font-bold text-slate-900 dark:text-white">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                                    {{ $trans->label }}
                                </span>
                                <span class="block text-[10px] text-slate-400 font-mono mt-0.5">{{ $trans->slug }}</span>
                            </td>
                            <td class="py-3.5 px-4 font-mono">
                                <span class="px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                    {{ $trans->from_state_slug }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 font-mono">
                                <span class="px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 font-bold">
                                    {{ $trans->to_state_slug }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 font-mono text-[11px] text-slate-500">
                                {{ $trans->permission_gate ?: 'None (Public/Auto)' }}
                            </td>
                            <td class="py-3.5 px-4">
                                @if($rule)
                                    <span class="px-2 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 font-medium">
                                        {{ ucfirst($rule->routing_type) }}: {{ $rule->target_role ?: ($rule->target_department ?: 'Dynamic') }}
                                    </span>
                                @else
                                    <span class="text-slate-400">None</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4">
                                @if($trans->requires_remarks)
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                        Mandatory
                                    </span>
                                @else
                                    <span class="text-slate-400">Optional</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection