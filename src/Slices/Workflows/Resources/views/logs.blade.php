@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white flex items-center gap-2.5">
                <span class="p-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                </span>
                Global Workflow Audit Logs
            </h1>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Immutable, regulatory-grade log of all state transitions, author remarks, and routing handovers.
            </p>
        </div>

        <div>
            <span class="text-xs text-slate-500 font-medium">
                Total Log Entries: <strong class="text-slate-800 dark:text-slate-200">{{ $logs->total() }}</strong>
            </span>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-400 font-semibold uppercase tracking-wider">
                        <th class="py-3 px-4">Entity</th>
                        <th class="py-3 px-4">Transition</th>
                        <th class="py-3 px-4">State Flow</th>
                        <th class="py-3 px-4">Officer / Performed By</th>
                        <th class="py-3 px-4">Remarks / Findings</th>
                        <th class="py-3 px-4">Timestamp</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    @forelse($logs as $log)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-4 font-bold text-slate-900 dark:text-white">
                                {{ class_basename($log->entity_type) }} #{{ $log->entity_id }}
                            </td>
                            <td class="py-3.5 px-4 font-mono font-medium text-indigo-600 dark:text-indigo-400">
                                {{ $log->transition_slug ?: 'Initial State' }}
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="flex items-center gap-1.5">
                                    @if($log->from_state)
                                        <span class="px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                                            {{ $log->from_state }}
                                        </span>
                                        <span>&rarr;</span>
                                    @endif
                                    <span class="px-2 py-0.5 rounded bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-400 font-bold">
                                        {{ $log->to_state }}
                                    </span>
                                </div>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="font-semibold text-slate-800 dark:text-slate-200">{{ $log->performer_name }}</span>
                                @if($log->assignee_name)
                                    <span class="block text-[10px] text-slate-400">Assigned to: {{ $log->assignee_name }}</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-4 max-w-xs truncate italic text-slate-600 dark:text-slate-400">
                                {{ $log->remarks ? '"'.$log->remarks.'"' : '-' }}
                            </td>
                            <td class="py-3.5 px-4 font-mono text-slate-400">
                                {{ $log->created_at->format('M d, Y H:i') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-400 text-xs">
                                No workflow audit entries recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="p-4 border-t border-slate-100 dark:border-slate-800">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</div>
@endsection