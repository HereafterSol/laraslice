@props(['model', 'history' => null])

@php
    $logs = $history ?: (method_exists($model, 'getWorkflowHistory') ? $model->getWorkflowHistory() : collect());
@endphp

<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm mt-6">
    <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-100 dark:border-slate-800">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 font-semibold text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Audit Trail & History</h4>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Immutable Decision Log</p>
            </div>
        </div>
        <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">
            {{ count($logs) }} Recorded Action{{ count($logs) === 1 ? '' : 's' }}
        </span>
    </div>

    @if($logs->isEmpty())
        <div class="py-8 text-center text-slate-400 dark:text-slate-500 text-xs">
            No workflow transitions recorded for this entity yet.
        </div>
    @else
        <div class="relative pl-6 space-y-6 before:absolute before:left-2.5 before:top-3 before:bottom-3 before:w-0.5 before:bg-slate-200 dark:before:bg-slate-800">
            @foreach($logs as $log)
                <div class="relative group">
                    <!-- Bullet dot -->
                    <div class="absolute -left-6 top-1.5 w-5 h-5 rounded-full bg-white dark:bg-slate-900 border-2 border-indigo-500 flex items-center justify-center">
                        <div class="w-2 h-2 rounded-full bg-indigo-600"></div>
                    </div>

                    <div class="p-4 bg-slate-50 dark:bg-slate-800/40 rounded-xl border border-slate-100 dark:border-slate-800 space-y-2">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-slate-900 dark:text-white">
                                    {{ $log->performer_name }}
                                </span>
                                @if($log->transition_slug)
                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400">
                                        {{ ucwords(str_replace('_', ' ', $log->transition_slug)) }}
                                    </span>
                                @endif
                            </div>

                            <span class="text-[11px] text-slate-400 dark:text-slate-500 font-mono">
                                {{ $log->created_at->format('M d, Y h:i A') }} ({{ $log->created_at->diffForHumans() }})
                            </span>
                        </div>

                        <!-- State progression badge -->
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-slate-500 dark:text-slate-400">Status changed:</span>
                            @if($log->from_state)
                                <span class="px-2 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium text-[11px]">
                                    {{ $log->from_state }}
                                </span>
                                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                            @endif
                            <span class="px-2 py-0.5 rounded bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 font-bold text-[11px]">
                                {{ $log->to_state }}
                            </span>

                            @if($log->assignee_name)
                                <span class="text-slate-400 ml-2">Assigned to: <strong class="text-slate-700 dark:text-slate-300">{{ $log->assignee_name }}</strong></span>
                            @endif
                        </div>

                        <!-- Remarks quote -->
                        @if($log->remarks)
                            <div class="mt-2 text-xs bg-white dark:bg-slate-900/80 p-3 rounded-lg border border-slate-200/80 dark:border-slate-800 text-slate-700 dark:text-slate-300 italic">
                                &ldquo;{{ $log->remarks }}&rdquo;
                            </div>
                        @endif

                        <!-- Attachments -->
                        @if(! empty($log->attachments))
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($log->attachments as $att)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200">
                                        <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                        {{ basename($att) }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>