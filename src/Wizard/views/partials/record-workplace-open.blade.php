{{--
    Record workplace (Seat 2) — opening half, Desk
    arrangement (D-017): a tab strip (Details / Activities
    / Notes / Connections — the source record page's own
    strip) sits above the flex row of form + right rail;
    the strip switches the body regions the close partial
    stacks below the row (Details shows them all). Singles
    keep the plain settings anatomy (no strip). Rendered
    only on edit screens with a composed $workplace;
    create screens keep the plain form.
--}}
@php $lsWpSingle = !empty($workplace['single']); @endphp
<div class="w-full" x-data="{ lsWpTab: 'details' }">
    @if(!$lsWpSingle)
        <div class="mb-4 flex flex-nowrap items-center gap-4 overflow-x-auto border-b border-border/70 text-xs sm:gap-5 sm:text-[13px]">
            @foreach(['details' => 'Details', 'activities' => 'Activities', 'notes' => 'Notes', 'connections' => 'Connections'] as $lsTabKey => $lsTabLabel)
                <button type="button" @click="lsWpTab = '{{ $lsTabKey }}'" class="whitespace-nowrap border-b-2 px-0.5 pb-2 font-medium transition-colors" :class="lsWpTab === '{{ $lsTabKey }}' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'">{{ $lsTabLabel }}</button>
            @endforeach
        </div>
    @endif
    <div class="flex flex-col items-stretch gap-5 xl:flex-row xl:items-start">
