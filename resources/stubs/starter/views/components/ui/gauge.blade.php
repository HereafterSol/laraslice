@props([
    'value' => 0,
    'max' => 100,
    'label' => null,
    'display' => null,
])

@php
    $percent = $max > 0 ? max(0.0, min(100.0, round(((float) $value) / ((float) $max) * 100, 1))) : 0.0;
    $radius = 34;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - $percent / 100);
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-4']) }}>
    <div class="relative size-20 shrink-0">
        <svg viewBox="0 0 84 84" class="size-20 -rotate-90">
            <circle cx="42" cy="42" r="{{ $radius }}" fill="none" class="stroke-muted" stroke-width="8" />
            <circle cx="42" cy="42" r="{{ $radius }}" fill="none" class="stroke-primary" stroke-width="8"
                stroke-linecap="round"
                stroke-dasharray="{{ $circumference }}"
                stroke-dashoffset="{{ $offset }}" />
        </svg>
        <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-sm font-semibold text-foreground">{{ $percent }}%</span>
        </div>
    </div>
    <div class="min-w-0">
        @if($label)
            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ $label }}</p>
        @endif
        <p class="mt-0.5 text-sm font-medium text-foreground">{{ $display ?? (trim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . ' / ' . trim(rtrim(number_format((float) $max, 2, '.', ''), '0'), '.')) }}</p>
        {{ $slot }}
    </div>
</div>
