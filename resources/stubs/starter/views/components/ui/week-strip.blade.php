@props([
    'days' => [],
])

<div {{ $attributes->merge(['class' => 'grid grid-cols-7 gap-1.5']) }}>
    @foreach($days as $day)
        <div class="flex flex-col items-center gap-0.5 rounded-lg border px-1 py-2 text-center {{ !empty($day['today']) ? 'border-primary/50 bg-primary/5' : 'border-border/60 bg-muted/30' }}">
            <span class="text-[10px] font-semibold uppercase tracking-wide {{ !empty($day['today']) ? 'text-primary' : 'text-muted-foreground' }}">{{ $day['label'] ?? '' }}</span>
            <span class="text-sm font-semibold text-foreground">{{ $day['count'] ?? 0 }}</span>
            <span class="text-[10px] text-muted-foreground">{{ $day['date'] ?? '' }}</span>
        </div>
    @endforeach
</div>
