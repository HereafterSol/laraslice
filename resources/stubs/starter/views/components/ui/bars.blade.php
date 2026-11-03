@props([
    'bars' => [],
    'style' => 'rows',
])

@if($style === 'columns')
{{-- Vertical chart columns: value label above each bar, a baseline,
     and the bar labels beneath — the source's chart-card arrangement. --}}
<div {{ $attributes->merge(['class' => '']) }}>
    <div class="flex items-end justify-center gap-16 px-6">
        @foreach($bars as $bar)
            <div class="flex flex-col items-center w-24">
                <span class="text-xs font-medium text-foreground mb-1">{{ $bar['display'] ?? '' }}</span>
                <div class="relative w-full" style="height: 150px">
                    <div class="absolute bottom-0 w-full rounded-t-sm" style="height: {{ max(1.5, (float) ($bar['percent'] ?? 0)) }}%; background-color: {{ $bar['color'] ?? '#3b82f6' }}"></div>
                </div>
            </div>
        @endforeach
    </div>
    <div class="border-t border-border mx-2"></div>
    <div class="flex justify-center gap-16 px-6 mt-2">
        @foreach($bars as $bar)
            <span class="w-24 text-center text-xs text-muted-foreground">{{ $bar['label'] ?? '' }}</span>
        @endforeach
    </div>
</div>
@else
<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    @foreach($bars as $bar)
        <div>
            <div class="flex items-center justify-between gap-3 text-[13px] mb-1">
                <span class="text-muted-foreground">{{ $bar['label'] ?? '' }}</span>
                <span class="font-semibold text-foreground">{{ $bar['display'] ?? '' }}</span>
            </div>
            <div class="h-2 rounded-full bg-muted overflow-hidden">
                <div class="h-full rounded-full" style="width: {{ $bar['percent'] ?? 0 }}%; background-color: {{ $bar['color'] ?? '#3b82f6' }}"></div>
            </div>
        </div>
    @endforeach
</div>
@endif
