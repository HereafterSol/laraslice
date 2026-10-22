@props([
    'title' => null,
    'icon' => null,
    'href' => null,
    'actionLabel' => 'View all',
])

<x-ui.card {{ $attributes->merge(['class' => 'bg-card border shadow-xs overflow-hidden']) }}>
    @if($title)
        <div class="flex items-center justify-between border-b border-border/60 px-5 py-3">
            <h3 class="flex items-center gap-2 text-[13px] font-semibold text-foreground">
                @if($icon)
                    <x-dynamic-component :component="'lucide-' . $icon" class="size-4 text-primary" />
                @endif
                {{ $title }}
            </h3>
            @if($href)
                <a href="{{ $href }}" class="text-xs font-medium text-primary hover:underline">{{ $actionLabel }}</a>
            @endif
        </div>
    @endif
    <div class="p-5">
        {{ $slot }}
    </div>
</x-ui.card>
