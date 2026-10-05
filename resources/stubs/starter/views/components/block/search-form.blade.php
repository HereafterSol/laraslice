@props([
    'placeholder' => 'Search the docs...',
    'action' => null,
])

<form @if ($action) action="{{ $action }}" @endif @submit="{{ $action ? '' : '$event.preventDefault()' }}">
    <x-ui.sidebar-group class="py-0">
        <x-ui.sidebar-group-content class="relative">
            {{-- No id/label pairing: the sidebar renders its slot twice (desktop + mobile), which would duplicate the id --}}
            <x-ui.sidebar-input name="q" aria-label="Search" :placeholder="$placeholder" class="pl-8" {{ $attributes }} />
            <x-lucide-search class="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 opacity-50 select-none" />
        </x-ui.sidebar-group-content>
    </x-ui.sidebar-group>
</form>
