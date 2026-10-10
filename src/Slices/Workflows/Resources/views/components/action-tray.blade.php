@props(['model', 'actionUrl' => null])

@php
    $transitions = method_exists($model, 'getAvailableTransitions') ? $model->getAvailableTransitions() : [];
    $actionUrl = $actionUrl ?: route('workflows.transition', [
        'slice' => class_basename($model),
        'id' => $model->getKey()
    ], false);
@endphp

@if(! empty($transitions))
<div class="flex flex-wrap items-center gap-2.5 p-3 bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 rounded-xl">
    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mr-2 flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        Workflow Actions:
    </span>

    @foreach($transitions as $trans)
        @php
            $slug = is_object($trans) ? $trans->slug : $trans->name;
            $label = is_object($trans) ? ($trans->label ?? $trans->name) : $trans->name;
            $color = is_object($trans) ? ($trans->button_color ?? 'primary') : 'primary';
            $requiresRemarks = is_object($trans) && ! empty($trans->requires_remarks);
            $requiresAttachment = is_object($trans) && ! empty($trans->requires_attachment);

            $btnClasses = match($color) {
                'success' => 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm ring-1 ring-emerald-500/20',
                'danger' => 'bg-rose-600 hover:bg-rose-700 text-white shadow-sm ring-1 ring-rose-500/20',
                'warning' => 'bg-amber-500 hover:bg-amber-600 text-white shadow-sm ring-1 ring-amber-400/20',
                'secondary' => 'bg-slate-600 hover:bg-slate-700 text-white shadow-sm',
                default => 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm ring-1 ring-indigo-500/20',
            };
        @endphp

        @if($requiresRemarks || $requiresAttachment)
            <button
                type="button"
                onclick="window.openWorkflowModal('{{ $slug }}', '{{ addslashes($label) }}', '{{ $actionUrl }}', {{ $requiresRemarks ? 'true' : 'false' }}, {{ $requiresAttachment ? 'true' : 'false' }})"
                class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors duration-150 {{ $btnClasses }}"
            >
                <span>{{ $label }}</span>
                <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
        @else
            <form action="{{ $actionUrl }}" method="POST" class="inline" onsubmit="return confirm('Execute transition: {{ $label }}?');">
                @csrf
                <input type="hidden" name="transition" value="{{ $slug }}">
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-colors duration-150 {{ $btnClasses }}"
                >
                    <span>{{ $label }}</span>
                    <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </form>
        @endif
    @endforeach
</div>
@endif