@php
    $setupProgress = \LaraSlice\Support\SetupProgress::summary(auth()->user());
@endphp
@if ($setupProgress !== null)
    <a href="{{ route('checklist') }}" class="hidden lg:block px-2 py-1 rounded-lg hover:bg-muted/50 transition" title="Setup Progress">
        <span class="flex items-center justify-between gap-6 text-[11px] leading-tight">
            <span class="text-muted-foreground">Setup Progress</span>
            <span class="text-foreground font-medium">{{ $setupProgress['completed'] }}/{{ $setupProgress['total'] }}</span>
        </span>
        <span class="block h-[5px] w-[150px] rounded-full bg-muted overflow-hidden mt-1">
            <span class="block h-full bg-primary rounded-full" style="width: {{ $setupProgress['percent'] }}%"></span>
        </span>
    </a>
@endif
