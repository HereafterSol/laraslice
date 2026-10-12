@props(['model', 'workflow' => null])

@php
    $resolvedModel = $model;
    $id = is_object($model) && method_exists($model, 'getKey') ? $model->getKey() : (data_get($model, 'id') ?: 1);
    $rawName = class_basename($model);
    $cleanSlice = str_replace(['FormBusinessObject', 'ListingBusinessObject', 'FilterBusinessObject'], '', $rawName);

    if (! method_exists($resolvedModel, 'getWorkflowDefinition')) {
        $candidates = [
            "App\\Slices\\Support\\{$cleanSlice}\\Models\\".\Illuminate\Support\Str::singular($cleanSlice),
            "App\\Slices\\Billing\\{$cleanSlice}\\Models\\".\Illuminate\Support\Str::singular($cleanSlice),
            "App\\Slices\\Support\\Tickets\\Models\\Ticket",
            "App\\Slices\\Billing\\Invoices\\Models\\Invoice",
        ];
        foreach ($candidates as $cand) {
            if (class_exists($cand)) {
                $found = $cand::find($id);
                if ($found) {
                    $resolvedModel = $found;
                    break;
                }
            }
        }
    }

    $workflow = $workflow ?: (method_exists($resolvedModel, 'getWorkflowDefinition') ? $resolvedModel->getWorkflowDefinition() : null);
    $currentState = method_exists($resolvedModel, 'getCurrentState') ? $resolvedModel->getCurrentState() : ($resolvedModel->status ?? data_get($model, 'status', 'draft'));
    $sla = method_exists($resolvedModel, 'getSlaStatus') ? $resolvedModel->getSlaStatus() : null;
    $states = $workflow ? $workflow->states : collect();
    $currentIndex = $states->search(fn($s) => $s->slug === $currentState);
    $currentIndex = $currentIndex === false ? 0 : $currentIndex;
    $totalStates = count($states);
@endphp

@if($workflow && $states->isNotEmpty())
<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-xs mb-6">
    <!-- Header: Pipeline Info & SLA Timer -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 mb-5 border-b border-slate-100 dark:border-slate-800 gap-3">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 text-indigo-600 dark:text-indigo-400 font-bold text-sm shadow-xs">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <div>
                <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Workflow Pipeline</span>
                <h4 class="text-sm font-bold text-slate-900 dark:text-white leading-tight">{{ $workflow->name }}</h4>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">
                Stage <span class="font-bold text-slate-800 dark:text-slate-200">{{ $currentIndex + 1 }}</span> of <span class="font-bold text-slate-800 dark:text-slate-200">{{ $totalStates }}</span>
            </span>

            @if($sla && $sla['has_sla'])
                @if($sla['is_breached'])
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-900/60">
                        <span class="w-2 h-2 rounded-full bg-rose-500 animate-ping"></span>
                        SLA Breached (by {{ abs($sla['hours_remaining']) }}h)
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-900/50">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        SLA: {{ $sla['hours_remaining'] }}h remaining
                    </span>
                @endif
            @endif
        </div>
    </div>

    <!-- Modern Horizontal Chevron Stepper -->
    <div class="overflow-x-auto pb-2 scrollbar-thin">
        <nav aria-label="Progress" class="min-w-[640px]">
            <ol role="list" class="flex items-center justify-between w-full">
                @foreach($states as $index => $state)
                    @php
                        $isPast = $index < $currentIndex;
                        $isCurrent = $state->slug === $currentState;
                        $isFuture = $index > $currentIndex;
                    @endphp
                    <li class="relative flex-1 {{ $loop->last ? '' : 'pr-4' }}">
                        @if(! $loop->last)
                            <!-- Connector Bar -->
                            <div class="absolute top-4 left-7 -right-2 h-0.5 -z-0 transition-all duration-300 {{ $isPast ? 'bg-emerald-500 dark:bg-emerald-600' : 'bg-slate-200 dark:bg-slate-750' }}" aria-hidden="true"></div>
                        @endif

                        <div class="group flex flex-col items-center text-center">
                            <!-- Stage Node -->
                            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full text-xs font-bold transition-all duration-200 shadow-xs
                                {{ $isCurrent ? 'bg-indigo-600 text-white ring-4 ring-indigo-100 dark:ring-indigo-950/80 scale-110 shadow-indigo-500/25' : '' }}
                                {{ $isPast ? 'bg-emerald-500 text-white' : '' }}
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

                            <!-- Stage Label & Meta -->
                            <div class="mt-2.5 max-w-[110px]">
                                <span class="block text-xs font-semibold leading-tight truncate transition-colors
                                    {{ $isCurrent ? 'text-indigo-600 dark:text-indigo-400 font-bold' : '' }}
                                    {{ $isPast ? 'text-slate-800 dark:text-slate-200' : '' }}
                                    {{ $isFuture ? 'text-slate-400 dark:text-slate-500' : '' }}
                                " title="{{ $state->label }}">
                                    {{ $state->label }}
                                </span>
                                @if($state->sla_hours)
                                    <span class="block text-[10px] text-slate-400 dark:text-slate-500 font-medium mt-0.5">
                                        {{ $state->sla_hours }}h SLA
                                    </span>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>
</div>
@endif