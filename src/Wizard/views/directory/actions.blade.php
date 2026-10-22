{{--
    Declared directory actions (fidelity stage): context buttons
    from ui.directory.actions, resolved by the controller into
    $directoryActions (label / href / icon / variant). Renders
    nothing when the slice declares none.
--}}
@if (!empty($directoryActions))
<div class="mb-4 flex flex-wrap items-center gap-2">
    @foreach ($directoryActions as $action)
    @if (($action['method'] ?? 'get') === 'post')
    <form method="POST" action="{{ $action['href'] }}" class="inline-flex" @if(!empty($action['confirm'])) onsubmit="return confirm('{{ $action['confirm'] }}')" @endif>
        @csrf
        @foreach (($action['fields'] ?? []) as $fname => $fvalue)
        <input type="hidden" name="{{ $fname }}" value="{{ $fvalue }}" />
        @endforeach
        <button type="submit"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer {{ ($action['variant'] ?? 'outline') === 'primary' ? 'bg-primary text-primary-foreground hover:bg-primary/90' : 'border border-border bg-card text-foreground hover:bg-muted' }}">
            @if (!empty($action['icon']))
            <x-dynamic-component :component="'lucide-' . $action['icon']" class="size-3.5" />
            @endif
            <span>{{ $action['label'] }}</span>
        </button>
    </form>
    @else
    <a href="{{ $action['href'] }}"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer {{ ($action['variant'] ?? 'outline') === 'primary' ? 'bg-primary text-primary-foreground hover:bg-primary/90' : 'border border-border bg-card text-foreground hover:bg-muted' }}">
        @if (!empty($action['icon']))
        <x-dynamic-component :component="'lucide-' . $action['icon']" class="size-3.5" />
        @endif
        <span>{{ $action['label'] }}</span>
    </a>
    @endif
    @endforeach
</div>
@endif
