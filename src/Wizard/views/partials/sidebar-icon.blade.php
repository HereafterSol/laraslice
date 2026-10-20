{{--
    LaraSlice Sidebar Icon Helper
    
    Maps icon names from slice.yaml to inline SVG icons.
    Usage: @include('laraslice::partials.sidebar-icon', ['icon' => 'users'])
--}}

@php $icon = $icon ?? 'package'; @endphp

@switch($icon)
    @case('users')
    @case('people')
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        @break
    @case('briefcase')
    @case('work')
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect x="2" y="7" width="20" height="14" rx="2" ry="2" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
        @break
    @case('building')
    @case('office')
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-16 0H3m2.5-18H15m-7 7h2m4 0h2m-6 4h2m4 0h2"/></svg>
        @break
    @case('layers')
    @case('stack')
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><polygon points="12 2 2 7 12 12 22 7 12 2" stroke-width="1.5"/><polyline points="2 17 12 22 22 17" stroke-width="1.5"/><polyline points="2 12 12 17 22 12" stroke-width="1.5"/></svg>
        @break
    @case('shopping-cart')
    @case('cart')
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="9" cy="21" r="1" stroke-width="1.5"/><circle cx="20" cy="21" r="1" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
        @break
    @case('settings')
    @case('gear')
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="3" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9c.2.65.59 1.2 1.12 1.51H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        @break
    @case('shield')
    @case('security')
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        @break
    @case('circle')
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="1.5"/></svg>
        @break
    @case('cube')
    @case('package')
    @default
        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96" stroke-width="1.5"/><line x1="12" y1="22.08" x2="12" y2="12" stroke-width="1.5"/></svg>
        @break
@endswitch
