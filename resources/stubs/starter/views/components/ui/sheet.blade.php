{{--
    Sheet root. Holds the open state.
      open  initial open state
      id    optional — enables "dispatchable" mode: the sheet also opens/closes from
            anywhere via $dispatch('open-sheet-{id}') / $dispatch('close-sheet-{id}').
            Use <x-ui.sheet-trigger for="{id}"> from inside a @foreach/partial.
--}}
@props(['open' => false, 'id' => null])

<div
    data-slot="sheet"
    x-data="{ open: @js((bool) $open) }"
    x-init="$watch('open', val => { if (!val) { $dispatch('sheet-closed'); } else { $dispatch('sheet-opened'); } })"
    @if ($id)
        @open-sheet-{{ $id }}.window="open = true"
        @close-sheet-{{ $id }}.window="open = false"
    @endif
    x-id="['blat-sheet']"
    {{ $attributes }}
>
    {{ $slot }}
</div>
