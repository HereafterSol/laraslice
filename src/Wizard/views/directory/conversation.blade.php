{{--
    Standard conversation template (25 Oct 2026): two-pane 1:1
    chat for any slice declaring ui.conversation (the source
    app's Messages page). A slice overrides it with its own
    Resources/views/conversation.blade.php. Server-rendered:
    selecting a conversation is a link (?c=id), starting one is
    a POST, sending is a POST — no realtime transport is faked.
--}}
@extends('layouts.app')

@section('content')
@php
    $initials = function (string $name): string {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first.$last);
    };
    $selected = $chat['selected'] ?? null;
@endphp
<div class="space-y-4" x-data="{ newOpen: false, q: '' }">
    {{-- No in-page title: on ERP pages the shell topbar carries
         the section title + breadcrumb (source composition). --}}
    <div class="grid grid-cols-1 lg:grid-cols-[360px_1fr] rounded-xl border border-border bg-card overflow-hidden">
        {{-- Left pane: contact search + conversation list --}}
        <div class="border-b lg:border-b-0 lg:border-r border-border">
            <div class="p-3 border-b border-border/60">
                <div class="relative">
                    <x-lucide-search class="size-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground/70 pointer-events-none" />
                    <input type="text" x-model="q" placeholder="Type to search contact"
                        class="w-full pl-9 pr-3 py-2 rounded-lg border border-border bg-background text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-primary" />
                </div>
            </div>
            <div class="divide-y divide-border/50 max-h-[62vh] overflow-y-auto">
                @forelse($chat['conversations'] as $c)
                    <a href="{{ $chat['urls']['page'] }}?c={{ $c['id'] }}" data-name="{{ \Illuminate\Support\Str::lower($c['other']['name']) }}"
                        x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())"
                        class="flex gap-3 px-4 py-3 hover:bg-muted/50 transition-colors {{ $selected && $selected['id'] === $c['id'] ? 'bg-muted/70' : '' }}">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-sm font-bold">{{ $initials($c['other']['name']) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm font-semibold text-foreground">{{ $c['other']['name'] }}</span>
                                @if($c['last_at'])
                                    <span class="shrink-0 text-[11px] text-muted-foreground">{{ \Illuminate\Support\Carbon::parse($c['last_at'])->diffForHumans(null, true) }}</span>
                                @endif
                            </span>
                            <span class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{{ $c['preview'] }}</span>
                        </span>
                    </a>
                @empty
                    <p class="px-4 py-6 text-sm text-muted-foreground">No conversations yet. Start one with the New Conversation button.</p>
                @endforelse
            </div>
        </div>

        {{-- Right pane: thread / empty state --}}
        <div class="flex min-h-[62vh] flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-border/60 px-4 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    @if($selected)
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-bold">{{ $initials($selected['other']['name']) }}</span>
                        <span class="truncate text-sm font-semibold text-foreground">{{ $selected['other']['name'] }}</span>
                    @endif
                </div>
                <button type="button" @click="newOpen = true"
                    class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground shadow-xs transition-opacity hover:opacity-90">
                    <x-lucide-plus class="size-4" /> New Conversation
                </button>
            </div>

            @if($selected)
                <div class="flex-1 space-y-3 overflow-y-auto px-4 py-4" id="chat-thread">
                    @forelse($selected['replies'] as $r)
                        <div class="flex {{ $r['mine'] ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[75%] rounded-2xl px-3.5 py-2.5 text-sm {{ $r['mine'] ? 'bg-primary text-primary-foreground rounded-br-md' : 'bg-muted text-foreground rounded-bl-md' }}">
                                <p class="whitespace-pre-line">{{ $r['body'] }}</p>
                                <p class="mt-1 text-right text-[10px] {{ $r['mine'] ? 'text-primary-foreground/70' : 'text-muted-foreground' }}">{{ \Illuminate\Support\Carbon::parse($r['at'])->format('d M, H:i') }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-muted-foreground">No messages yet. Say hello.</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ $chat['urls']['reply'] }}" class="flex items-end gap-2 border-t border-border/60 px-4 py-3">
                    @csrf
                    <textarea name="reply" rows="1" required placeholder="Type a message..."
                        class="flex-1 resize-none rounded-lg border border-border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-primary"></textarea>
                    <button type="submit"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-xs transition-opacity hover:opacity-90">
                        <x-lucide-send class="size-4" /> Send
                    </button>
                </form>
            @else
                <div class="flex flex-1 flex-col items-center justify-center gap-3 px-4 py-16 text-center">
                    <x-lucide-message-square class="size-10 text-muted-foreground/50" />
                    <p class="text-sm text-muted-foreground">- Select a conversation to send a message -</p>
                </div>
            @endif
        </div>
    </div>

    {{-- New Conversation modal --}}
    <div x-show="newOpen" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 pt-24" @click.self="newOpen = false" @keydown.escape.window="newOpen = false">
        <div class="w-full max-w-md rounded-xl border border-border bg-card shadow-xl">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <h3 class="text-sm font-semibold text-foreground">New Conversation</h3>
                <button type="button" @click="newOpen = false" class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted/80 hover:text-foreground" title="Close">
                    <x-lucide-x class="size-4" />
                </button>
            </div>
            <div class="max-h-80 overflow-y-auto p-2">
                @forelse($chat['users'] as $u)
                    <form method="POST" action="{{ $chat['urls']['start'] }}">
                        @csrf
                        <input type="hidden" name="user_id" value="{{ $u['id'] }}" />
                        <button type="submit" class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-muted/60">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-bold">{{ $initials($u['name']) }}</span>
                            <span class="text-sm font-medium text-foreground">{{ $u['name'] }}</span>
                        </button>
                    </form>
                @empty
                    <p class="px-3 py-4 text-sm text-muted-foreground">No other users available.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
<script>
    // Land at the newest message, like every chat surface.
    const thread = document.getElementById('chat-thread');
    if (thread) { thread.scrollTop = thread.scrollHeight; }
</script>
@endsection
