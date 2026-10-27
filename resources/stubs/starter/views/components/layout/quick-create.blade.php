@php
    $quickCreateItems = \LaraSlice\Support\QuickCreate::items(auth()->user());
@endphp
@if ($quickCreateItems !== [])
    <div class="relative" x-data="{ qcOpen: false }" @click.outside="qcOpen = false">
        <button type="button" @click="qcOpen = !qcOpen"
                class="size-8 rounded-lg border border-input bg-background hover:bg-muted text-muted-foreground hover:text-foreground flex items-center justify-center transition cursor-pointer"
                title="Quick create">
            <x-lucide-plus class="size-4" />
            <span class="sr-only">Quick create</span>
        </button>
        <div x-show="qcOpen" x-cloak class="absolute right-0 mt-2 w-56 rounded-xl border border-border bg-card shadow-lg p-1.5 z-50">
            @foreach ($quickCreateItems as $item)
                <a href="{{ $item['url'] }}" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs text-foreground hover:bg-muted/70 transition-colors">
                    <x-lucide-plus class="size-3.5 text-muted-foreground" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
