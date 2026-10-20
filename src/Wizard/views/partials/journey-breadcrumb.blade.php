{{-- Persistent journey: Port → Design → Customize. Same placement under each Studio header. --}}
@php
    $current = $current ?? 'port';
    $steps = [
        'port' => ['label' => 'Port', 'route' => 'laraslice.studio', 'hint' => 'Import source'],
        'design' => ['label' => 'Design', 'route' => 'laraslice.wizard.blueprint', 'hint' => 'Blueprint'],
        'customize' => ['label' => 'Customize', 'route' => 'laraslice.wizard.studio', 'hint' => 'Slice Studio'],
    ];
@endphp
<nav aria-label="Studio journey" class="flex items-center gap-1 text-xs">
    @foreach ($steps as $key => $step)
        @if (! $loop->first)
            <span class="text-muted-foreground/40 px-1">→</span>
        @endif
        <a href="{{ $key === 'port' ? route('laraslice.studio', ['mode' => 'port']) : route($step['route']) }}"
           class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 transition {{ $current === $key ? 'bg-primary text-primary-foreground font-semibold' : 'text-muted-foreground hover:text-foreground hover:bg-accent' }}"
           @if ($current === $key) aria-current="step" @endif>
            <span class="font-mono text-[10px] opacity-70">{{ $loop->iteration }}</span>
            <span>{{ $step['label'] }}</span>
        </a>
    @endforeach
</nav>
