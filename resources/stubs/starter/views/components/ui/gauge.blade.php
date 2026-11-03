@props([
    'value' => 0,
    'max' => 100,
    'label' => null,
    'display' => null,
    'variant' => 'ring',
    'color' => null,
])

@php
    $percent = $max > 0 ? max(0.0, min(100.0, round(((float) $value) / ((float) $max) * 100, 1))) : 0.0;
    $percentText = rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');
    $radius = 34;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - $percent / 100);
    $arcLength = M_PI * 44;
    $arcOffset = $arcLength * (1 - $percent / 100);
    $maxText = rtrim(rtrim(number_format((float) $max, 1, '.', ''), '0'), '.');
@endphp

@if($variant === 'semicircle')
{{-- Half gauge: the value arc sweeps a semicircle, the percent sits
     at its centre, and the scale ends sit under the arc tips — the
     source progress card's gauge. --}}
<div {{ $attributes->merge(['class' => 'shrink-0']) }}>
    <div class="relative" style="width: 150px">
        <svg viewBox="0 0 100 58" class="w-full">
            <path d="M 6 52 A 44 44 0 0 1 94 52" fill="none" class="stroke-muted" stroke-width="9" stroke-linecap="round" />
            <path d="M 6 52 A 44 44 0 0 1 94 52" fill="none" stroke="{{ $color ?: '#28a745' }}" stroke-width="9" stroke-linecap="round"
                stroke-dasharray="{{ $arcLength }}" stroke-dashoffset="{{ $arcOffset }}" />
        </svg>
        <span class="absolute bottom-4 inset-x-0 text-center text-[15px] font-semibold text-foreground">{{ $percentText }}%</span>
        <span class="absolute bottom-0 left-1 text-[10px] text-muted-foreground">0</span>
        <span class="absolute bottom-0 right-1 text-[10px] text-muted-foreground">{{ $maxText }}</span>
    </div>
</div>
@else
<div {{ $attributes->merge(['class' => 'flex items-center gap-4']) }}>
    <div class="relative size-20 shrink-0">
        <svg viewBox="0 0 84 84" class="size-20 -rotate-90">
            <circle cx="42" cy="42" r="{{ $radius }}" fill="none" class="stroke-muted" stroke-width="8" />
            <circle cx="42" cy="42" r="{{ $radius }}" fill="none" @if($color) style="stroke: {{ $color }}" @else class="stroke-primary" @endif stroke-width="8"
                stroke-linecap="round"
                stroke-dasharray="{{ $circumference }}"
                stroke-dashoffset="{{ $offset }}" />
        </svg>
        <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-sm font-semibold text-foreground">{{ $percentText }}%</span>
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
@endif
