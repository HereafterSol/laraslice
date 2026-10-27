@php
    $runningTimer = \LaraSlice\Support\GlobalTimer::running(auth()->user());
@endphp
@if ($runningTimer !== null)
    <div class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-lg border border-input bg-muted/30 text-xs"
         x-data="{ startedAt: @js($runningTimer['started_at']), elapsed: '' }"
         x-init="const tick = () => { const s = Math.max(0, Math.floor((Date.now() - new Date(startedAt.replace(' ', 'T')).getTime()) / 1000)); const h = String(Math.floor(s / 3600)).padStart(2, '0'); const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0'); const sec = String(s % 60).padStart(2, '0'); elapsed = h + ':' + m + ':' + sec; }; tick(); setInterval(tick, 1000);">
        <span class="relative flex size-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-60"></span>
            <span class="relative inline-flex rounded-full size-2 bg-red-500"></span>
        </span>
        @if ($runningTimer['parent_url'])
            <a href="{{ $runningTimer['parent_url'] }}" class="max-w-40 truncate text-foreground hover:underline" title="{{ $runningTimer['label'] }}">{{ $runningTimer['label'] }}</a>
        @else
            <span class="max-w-40 truncate text-foreground">{{ $runningTimer['label'] }}</span>
        @endif
        <span class="font-mono text-muted-foreground" x-text="elapsed"></span>
        <form method="POST" action="{{ $runningTimer['stop_url'] }}" class="inline">
            @csrf
            <button type="submit" class="text-red-500 hover:text-red-600 font-semibold cursor-pointer" title="Stop timer">Stop</button>
        </form>
    </div>
@endif
