@props([
    'status' => 'draft',
    'label' => null,
    'color' => null,
    'size' => 'default',
    'pulse' => false,
])

@php
    $statusKey = strtolower(trim((string) $status));
    $label ??= ucwords(str_replace('_', ' ', (string) $status));

    $colorMap = [
        'active' => 'emerald',
        'enabled' => 'emerald',
        'paid' => 'emerald',
        'completed' => 'emerald',
        'approved' => 'emerald',
        'present' => 'emerald',
        'success' => 'emerald',
        'pending' => 'amber',
        'in_progress' => 'amber',
        'partially_paid' => 'amber',
        'waiting' => 'amber',
        'warning' => 'amber',
        'inactive' => 'rose',
        'disabled' => 'rose',
        'unpaid' => 'rose',
        'cancelled' => 'rose',
        'rejected' => 'rose',
        'absent' => 'rose',
        'overdue' => 'rose',
        'danger' => 'rose',
        'failed' => 'rose',
        'draft' => 'slate',
        'archived' => 'slate',
        'holiday' => 'sky',
        'leave' => 'sky',
        'info' => 'sky',
    ];

    $resolvedColor = $color ?: ($colorMap[$statusKey] ?? 'indigo');

    $dotClasses = match ($resolvedColor) {
        'emerald', 'green', 'success' => 'bg-emerald-500',
        'rose', 'red', 'danger' => 'bg-rose-500',
        'amber', 'yellow', 'warning' => 'bg-amber-500',
        'sky', 'blue', 'info' => 'bg-sky-500',
        'purple' => 'bg-purple-500',
        'indigo' => 'bg-indigo-500',
        default => 'bg-slate-400',
    };

    $pillClasses = match ($resolvedColor) {
        'emerald', 'green', 'success' => 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800',
        'rose', 'red', 'danger' => 'bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border-rose-200 dark:border-rose-800',
        'amber', 'yellow', 'warning' => 'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800',
        'sky', 'blue', 'info' => 'bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-400 border-sky-200 dark:border-sky-800',
        'purple' => 'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-400 border-purple-200 dark:border-purple-800',
        'indigo' => 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 border-indigo-200 dark:border-indigo-800',
        default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700',
    };

    $sizeClasses = match ($size) {
        'sm' => 'px-2 py-0.5 text-[10px] gap-1',
        'lg' => 'px-3 py-1 text-sm gap-2',
        default => 'px-2.5 py-0.5 text-xs gap-1.5',
    };
@endphp

<span {{ $attributes->twMerge('inline-flex items-center font-semibold rounded-full border transition-colors ' . $pillClasses . ' ' . $sizeClasses) }}>
    <span class="relative flex size-1.5">
        @if($pulse)
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 {{ $dotClasses }}"></span>
        @endif
        <span class="relative inline-flex rounded-full size-1.5 {{ $dotClasses }}"></span>
    </span>
    <span>{{ $label }}</span>
</span>
