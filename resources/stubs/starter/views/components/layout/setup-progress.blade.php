@php
    $setupProgress = \LaraSlice\Support\SetupProgress::summary(auth()->user());
@endphp
@if ($setupProgress !== null)
    {{-- Compact by design: setup is a first-run concern, so in the topbar it
         earns a slim indicator, not a widget — the full checklist lives one
         click away. Renders nothing once setup is complete (summary null). --}}
    <a href="{{ route('checklist') }}" class="hidden lg:flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-muted/50 transition" title="Setup Progress — {{ $setupProgress['completed'] }} of {{ $setupProgress['total'] }} steps done">
        <span class="block h-[5px] w-16 rounded-full bg-muted overflow-hidden">
            <span class="block h-full bg-primary rounded-full" style="width: {{ $setupProgress['percent'] }}%"></span>
        </span>
        <span class="text-[11px] leading-none text-muted-foreground">Setup <span class="text-foreground font-medium">{{ $setupProgress['completed'] }}/{{ $setupProgress['total'] }}</span></span>
    </a>
@endif
