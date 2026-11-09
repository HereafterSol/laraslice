{{--
    Related-tab THREAD render mode (declared render: thread
    on a relation tab): the tab's records as conversation
    message cards, oldest first — author, time, body — the
    way the source systems render a ticket conversation as
    the primary surface of the record. The tab's declared
    reply composer (the show template's related-tab-post
    include) renders after this partial, at the foot of the
    thread, exactly where the source puts its reply box.
--}}
@php
    $records = collect($rtab['records'] ?? [])->sortBy(static function ($rec) {
        $ts = data_get($rec, 'created_at');

        return $ts instanceof \DateTimeInterface ? $ts->getTimestamp() : (is_string($ts) ? strtotime($ts) ?: 0 : 0);
    })->values();
@endphp
@if($records->count() === 0)
    <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
        No messages in this thread yet.
    </div>
@else
    <div class="space-y-3">
        @foreach($records as $rec)
            @php
                $author = null;
                try {
                    $author = $rec->user ?? null;
                } catch (\Throwable $e) {
                    $author = null;
                }
                $authorName = $author !== null
                    ? (string) ($author->name ?? '—')
                    : (string) (data_get($rec, 'author_name') ?? data_get($rec, 'from_name') ?? '—');
                $initials = collect(explode(' ', trim($authorName)))->map(static fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('');
                $body = data_get($rec, 'message') ?? data_get($rec, 'body') ?? data_get($rec, 'description') ?? data_get($rec, 'reply') ?? '';
                $when = data_get($rec, 'created_at');
                try {
                    $whenFmt = $when ? \Illuminate\Support\Carbon::parse($when)->format('d M Y, h:i A') : '';
                } catch (\Throwable $e) {
                    $whenFmt = (string) $when;
                }
            @endphp
            <article class="rounded-xl border border-border bg-card p-4">
                <header class="flex items-center gap-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary">{{ $initials !== '' ? $initials : '?' }}</span>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-foreground">{{ $authorName }}</div>
                        <div class="text-[11px] text-muted-foreground">{{ $whenFmt }}</div>
                    </div>
                </header>
                <div class="mt-3 text-sm leading-relaxed text-foreground/90">{!! nl2br(e((string) $body)) !!}</div>
            </article>
        @endforeach
    </div>
@endif
