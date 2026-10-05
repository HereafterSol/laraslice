@props([
    'versions' => [],
    'defaultVersion' => null,
    'title' => 'Documentation',
])

@php
    use Illuminate\Support\Js;

    $versions = array_values($versions);
    $selected = $defaultVersion ?? ($versions[0] ?? '');
@endphp

<x-ui.sidebar-menu x-data="{ selectedVersion: {{ Js::from($selected) }} }" {{ $attributes }}>
    <x-ui.sidebar-menu-item>
        <x-ui.dropdown-menu>
            <x-ui.dropdown-menu-trigger>
                <x-ui.sidebar-menu-button size="lg" class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground">
                    <div class="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-lg">
                        @isset($icon)
                            {{ $icon }}
                        @else
                            <x-lucide-gallery-vertical-end class="size-4" />
                        @endisset
                    </div>
                    <div class="flex flex-col gap-0.5 leading-none">
                        <span class="font-medium">{{ $title }}</span>
                        <span x-text="'v' + selectedVersion">v{{ $selected }}</span>
                    </div>
                    <x-lucide-chevrons-up-down class="ml-auto" />
                </x-ui.sidebar-menu-button>
            </x-ui.dropdown-menu-trigger>
            <x-ui.dropdown-menu-content align="start" class="min-w-56">
                @foreach ($versions as $version)
                    <x-ui.dropdown-menu-item @click="selectedVersion = {{ Js::from($version) }}">
                        v{{ $version }}
                        <x-lucide-check class="ml-auto" x-show="selectedVersion === {{ Js::from($version) }}" />
                    </x-ui.dropdown-menu-item>
                @endforeach
            </x-ui.dropdown-menu-content>
        </x-ui.dropdown-menu>
    </x-ui.sidebar-menu-item>
</x-ui.sidebar-menu>
