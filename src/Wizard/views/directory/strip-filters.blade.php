{{--
    Strip-placed declared filters (fidelity leftover): a declared
    range with placement: strip renders here — in the list's top
    strip like the source systems — instead of in the drawer.
    A range may declare column_options + column_param (the
    date_filter_on choice): the select chooses WHICH date column
    the range applies to; the controller rewrites the criteria.
--}}
@php
    $stripFilters = array_values(array_filter($sidebarFilters ?? [], fn ($sf) => is_array($sf) && ($sf['placement'] ?? 'drawer') === 'strip'));
    $stripKeys = [];
    foreach ($stripFilters as $sf) {
        $stripKeys[] = ($sf['handle'] ?? '').'_from';
        $stripKeys[] = ($sf['handle'] ?? '').'_to';
        if (!empty($sf['column_param'])) {
            $stripKeys[] = $sf['column_param'];
        }
    }
@endphp
@if (!empty($stripFilters))
<form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
    @foreach (($activeFilters ?? []) as $ak => $av)
        @if (!in_array($ak, $stripKeys, true) && !is_array($av) && $av !== null && $av !== '')
        <input type="hidden" name="{{ $ak }}" value="{{ $av }}" />
        @endif
    @endforeach
    @foreach ($stripFilters as $sf)
        @php $sfHandle = $sf['handle'] ?? ''; $param = $sf['column_param'] ?? 'date_filter_on'; @endphp
        @if (!empty($sf['column_options']))
        <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {{ $sf['column_label'] ?? 'Date filter on' }}
            <select name="{{ $param }}" class="h-9 rounded-lg border border-border bg-background px-2 text-xs font-normal normal-case tracking-normal text-foreground">
                @foreach ($sf['column_options'] as $value => $label)
                <option value="{{ $value }}" @selected((string) (request()->query($param) ?? ($sf['default_column'] ?? '')) === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @endif
        <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {{ $sf['label'] ?? 'Date' }} from
            <input type="date" name="{{ $sfHandle }}_from" value="{{ $activeFilters[$sfHandle . '_from'] ?? request()->query($sfHandle . '_from') ?? '' }}" class="h-9 rounded-lg border border-border bg-background px-2 text-xs font-normal normal-case tracking-normal text-foreground" />
        </label>
        <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {{ $sf['label'] ?? 'Date' }} to
            <input type="date" name="{{ $sfHandle }}_to" value="{{ $activeFilters[$sfHandle . '_to'] ?? request()->query($sfHandle . '_to') ?? '' }}" class="h-9 rounded-lg border border-border bg-background px-2 text-xs font-normal normal-case tracking-normal text-foreground" />
        </label>
    @endforeach
    <x-ui.button type="submit" size="sm">Apply</x-ui.button>
</form>
@endif
