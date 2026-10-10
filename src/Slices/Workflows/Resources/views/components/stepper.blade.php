@props(['model', 'workflow' => null])

@php
    $workflow = $workflow ?: (method_exists($model, 'getWorkflowDefinition') ? $model->getWorkflowDefinition() : null);
    $currentState = method_exists($model, 'getCurrentState') ? $model->getCurrentState() : ($model->status ?? 'draft');
    $sla = method_exists($model, 'getSlaStatus') ? $model->getSlaStatus() : null;
    $states = $workflow ? $workflow->states : collect();
    $currentIndex = $states->search(fn($s) => $s->slug === $currentState);
    $currentIndex = $currentIndex === false ? 0 : $currentIndex;
@endphp

@if($workflow && $states->isNotEmpty())
<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-5 shadow-sm mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 mb-4 border-b border-slate-100 dark:border-slate-800 gap-3">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 font-semibold text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Workflow Pipeline</h4>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $workflow->name }}</p>
            </div>
        </div>

        @if($sla && $sla['has_sla'])
            <div class="flex items-center gap-2">
                @if($sla['is_breached'])
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 animate-pulse border border-rose-200 dark:border-rose-900">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        SLA Breached (by {{ abs($sla['hours_remaining']) }} hrs)
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-900/50">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        SLA Timer: {{ $sla['hours_remaining'] }} hrs remaining
                    </span>
                @endif
            </div>
        @endif
    </div>

    <!-- Stepper Timeline Progression -->
    <div class="relative">
        <div class="grid grid-cols-1 sm:grid-cols-{{ count($states) }} gap-3 sm:gap-2">
            @foreach($states as $index => $state)
                @php
                    $isPast = $index < $currentIndex;
                    $isCurrent = $state->slug === $currentState;
                    $isFuture = $index > $currentIndex;
                @endphp
                <div class="flex sm:flex-col items-center gap-3 sm:gap-2 relative text-left sm:text-center group">
                    <!-- Circle indicator -->
                    <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full text-xs font-bold transition-all duration-200
                        {{ $isCurrent ? 'bg-indigo-600 text-white ring-4 ring-indigo-100 dark:ring-indigo-950 shadow-md scale-110' : '' }}
                        {{ $isPast ? 'bg-emerald-500 text-white shadow-sm' : '' }}
                        {{ $isFuture ? 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 border border-slate-200 dark:border-slate-700' : '' }}
                    ">
                        @if($isPast)
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        @elseif($isCurrent)
                            <span class="w-2 h-2 rounded-full bg-white animate-ping absolute"></span>
                            <span>{{ $index + 1 }}</span>
                        @else
                            <span>{{ $index + 1 }}</span>
                        @endif
                    </div>

                    <!-- Label -->
                    <div class="flex-1 sm:flex-none">
                        <p class="text-xs font-semibold transition-colors
                            {{ $isCurrent ? 'text-indigo-600 dark:text-indigo-400 font-bold' : '' }}
                            {{ $isPast ? 'text-slate-800 dark:text-slate-200' : '' }}
                            {{ $isFuture ? 'text-slate-400 dark:text-slate-500' : '' }}
                        ">
                            {{ $state->label }}
                        </p>
                        @if($state->sla_hours)
                            <span class="hidden sm:inline-block text-[10px] text-slate-400 dark:text-slate-500 font-normal">
                                Max {{ $state->sla_hours }}h SLA
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif