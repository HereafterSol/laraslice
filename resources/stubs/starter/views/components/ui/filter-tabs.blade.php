@props([
    'tabs' => [],
    'activeTab' => null,
    'param' => 'status',
    'default' => null,
    'class' => '',
])

@php
    $current = $activeTab ?? request()->query($param, $default ?? '');
    if ($current === '__all__') {
        $current = '';
    }
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-1.5 p-1 bg-muted/40 rounded-xl border border-border/40 overflow-x-auto no-scrollbar ' . $class]) }}>
    @foreach ($tabs as $tab)
        @php
            $val = $tab['value'] ?? ($tab['id'] ?? '');
            $label = $tab['label'] ?? ($tab['name'] ?? ucfirst($val ?: 'All'));
            $count = $tab['count'] ?? null;
            $isActive = (string)$current === (string)$val;
            
            $queryParams = request()->query();
            if ($val === '' || $val === null) {
                // With a declared default in play, "All" must clear
                // it explicitly ('__all__' sentinel); otherwise the
                // param simply drops out.
                if (($default ?? null) !== null && $default !== '') {
                    $queryParams[$param] = '__all__';
                } else {
                    unset($queryParams[$param]);
                }
            } else {
                $queryParams[$param] = $val;
            }
            $targetUrl = request()->url() . ($queryParams ? '?' . http_build_query($queryParams) : '');
        @endphp

        <a href="{{ $targetUrl }}"
           class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition-all cursor-pointer whitespace-nowrap {{ $isActive ? 'bg-background text-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:text-foreground hover:bg-background/50' }}">
            <span>{{ $label }}</span>
            @if (!is_null($count))
                <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-[10px] font-mono {{ $isActive ? 'bg-primary/10 text-primary font-bold' : 'bg-muted text-muted-foreground' }}">
                    {{ $count }}
                </span>
            @endif
        </a>
    @endforeach
</div>