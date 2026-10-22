{{--
    Standard record timer card (G8, P11): rendered on show pages
    whose slice declares ui.show.timer. Expects $timerState from
    BaseSliceWebController::show() (totals + active session +
    start/stop URLs). Server-rendered forms; the elapsed clock
    is a progressive-enhancement ticker.
--}}
@if (!empty($timerState))
<x-ui.card class="p-4 bg-card border shadow-xs">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block">{{ $timerState['decl']['title'] }}</span>
            <span class="text-sm font-semibold text-foreground mt-1 block">
                {{ intdiv($timerState['totalMinutes'], 60) }}h {{ $timerState['totalMinutes'] % 60 }}m logged
                @if ($timerState['activeHere'])
                    · <span class="text-emerald-600">running since {{ \Illuminate\Support\Carbon::parse($timerState['activeHere']->{$timerState['decl']['start_field']})->format('H:i') }}</span>
                    <span class="font-mono" data-ls-timer-elapsed data-start="{{ \Illuminate\Support\Carbon::parse($timerState['activeHere']->{$timerState['decl']['start_field']})->toIso8601String() }}"></span>
                @endif
            </span>
        </div>
        <div class="flex items-center gap-2">
            @if ($timerState['activeHere'])
                <form method="POST" action="{{ $timerState['stopUrl'] }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-destructive text-destructive-foreground hover:bg-destructive/90 text-xs font-semibold shadow-xs transition-colors">
                        <x-lucide-square class="size-3.5" />
                        <span>Stop Timer</span>
                    </button>
                </form>
            @elseif ($timerState['activeElsewhere'])
                <span class="text-xs text-muted-foreground">A timer is already running on another record.</span>
            @else
                <form method="POST" action="{{ $timerState['startUrl'] }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-xs transition-colors">
                        <x-lucide-play class="size-3.5" />
                        <span>Start Timer</span>
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-ui.card>
<script>
    document.querySelectorAll('[data-ls-timer-elapsed]').forEach(function (el) {
        var start = new Date(el.getAttribute('data-start')).getTime();
        var tick = function () {
            var s = Math.max(0, Math.floor((Date.now() - start) / 1000));
            var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
            el.textContent = '(' + h + ':' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0') + ' elapsed)';
        };
        tick();
        setInterval(tick, 1000);
    });
</script>
@endif
