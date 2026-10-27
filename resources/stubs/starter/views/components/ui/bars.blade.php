@props([
    'bars' => [],
])

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
