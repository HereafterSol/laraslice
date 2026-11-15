{{--
    Record workplace (Seat 2) — closing half, Desk
    arrangement (D-017): the right rail is an identity
    header (avatar, name, minted id, status) over
    plain text rows — Assign / Attachments / Tags /
    Share — each expanding in place from its + toggle,
    with the audit lines (relative time) at the foot.
    Below the row, the body stacks Comments, Activity
    and Connections as flat sections, switched by the
    tab strip the open partial renders (Details shows
    them all). All rail forms
    post to the generic laraslice.records.* endpoints;
    they are siblings of the record form, never nested
    inside it.
--}}
@php
    $lsSlice = $workplace['slice'];
    $lsId = $workplace['id'];
    $lsIdentity = $workplace['identity'] ?? [];
    $lsAssignment = $workplace['assignment'] ?? null;
    $lsUsers = $workplace['users'] ?? [];
    $lsSingle = !empty($workplace['single']);
    $lsInitials = collect(preg_split('/\s+/', trim((string) ($lsIdentity['label'] ?? ''))) ?: [])
        ->filter()->take(2)->map(fn ($w) => strtoupper(substr($w, 0, 1)))->implode('');
    $lsMe = auth()->user()?->name ?? auth()->user()?->email;
    $lsActor = fn ($label) => $label === null || $label === '' ? null : ($label === $lsMe ? 'You' : $label);
    $lsRel = fn ($ts) => $ts ? \Illuminate\Support\Carbon::parse($ts)->diffForHumans() : null;
@endphp
<aside class="w-full shrink-0 xl:w-72">
    <div class="flex items-start gap-3">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-[13px] font-semibold text-muted-foreground">{{ $lsInitials !== '' ? $lsInitials : '•' }}</span>
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-foreground">{{ $lsIdentity['label'] ?? '' }}</p>
            <p class="mt-0.5 truncate font-mono text-[11px] text-muted-foreground">{{ $lsIdentity['name'] ?? '' }}</p>
            @if(!empty($lsIdentity['status']))
                <span class="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-0.5 text-[11px] font-semibold text-muted-foreground"><span class="size-1.5 rounded-full bg-current"></span>{{ ucwords(str_replace('_', ' ', (string) $lsIdentity['status'])) }}</span>
            @endif
        </div>
    </div>

    <div class="mt-4 divide-y divide-border/70 border-y border-border/70">
        @if($lsAssignment)
            <div x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center gap-2 py-2.5 text-left text-[13px] text-foreground">
                    <span class="font-medium">Assign To</span>
                    <span class="min-w-0 flex-1 truncate text-right text-muted-foreground">{{ $lsAssignment['user_label'] ?? '' }}</span>
                    <x-lucide-plus class="size-4 shrink-0 text-muted-foreground transition-transform" x-bind:class="open ? 'rotate-45' : ''" />
                </button>
                <div x-show="open" x-cloak class="pb-3">
                    <form method="POST" action="{{ route('laraslice.records.assign', [$lsSlice, $lsId]) }}" class="flex gap-1.5">
                        @csrf
                        <input type="hidden" name="field" value="{{ $lsAssignment['field'] }}">
                        <select name="user_id" class="h-8 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-xs">
                            <option value="">Unassigned</option>
                            @foreach($lsUsers as $lsUserId => $lsUserLabel)
                                <option value="{{ $lsUserId }}" @selected((string) ($lsAssignment['user_id'] ?? '') === (string) $lsUserId)>{{ $lsUserLabel }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="h-8 shrink-0 rounded-md border border-input bg-background px-2.5 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Assign</button>
                    </form>
                </div>
            </div>
        @endif

        @if(!$lsSingle)
            <div x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center gap-2 py-2.5 text-left text-[13px] text-foreground">
                    <span class="font-medium">Attachments</span>
                    <span class="flex-1 text-right text-muted-foreground">{{ count($workplace['attachments'] ?? []) ?: '' }}</span>
                    <x-lucide-plus class="size-4 shrink-0 text-muted-foreground transition-transform" x-bind:class="open ? 'rotate-45' : ''" />
                </button>
                <div x-show="open" x-cloak class="pb-3">
                    @forelse($workplace['attachments'] ?? [] as $lsAtt)
                        <div class="flex items-center gap-2 py-1 text-[13px]">
                            <a href="{{ asset('storage/'.$lsAtt['path']) }}" target="_blank" class="min-w-0 flex-1 truncate font-medium text-foreground hover:underline">{{ $lsAtt['name'] }}</a>
                            <span class="shrink-0 text-[11px] text-muted-foreground">{{ number_format(($lsAtt['size'] ?? 0) / 1024, 1) }} KB</span>
                            <form method="POST" action="{{ route('laraslice.records.attachments.destroy', [$lsSlice, $lsId, $lsAtt['id']]) }}" onsubmit="return confirm('Remove this attachment?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[11px] font-medium text-destructive hover:underline">Remove</button>
                            </form>
                        </div>
                    @empty
                        <p class="py-1 text-[13px] text-muted-foreground">No attachments yet.</p>
                    @endforelse
                    <form method="POST" action="{{ route('laraslice.records.attachments.store', [$lsSlice, $lsId]) }}" enctype="multipart/form-data" class="mt-1.5 flex items-center gap-1.5">
                        @csrf
                        <input type="file" name="file" required class="min-w-0 flex-1 text-xs text-muted-foreground file:mr-2 file:h-8 file:rounded-md file:border file:border-input file:bg-background file:px-2.5 file:text-xs file:font-semibold">
                        <button type="submit" class="h-8 shrink-0 rounded-md border border-input bg-background px-2.5 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Upload</button>
                    </form>
                </div>
            </div>

            <div x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center gap-2 py-2.5 text-left text-[13px] text-foreground">
                    <span class="font-medium">Tags</span>
                    <span class="flex-1 text-right text-muted-foreground">{{ count($workplace['tags'] ?? []) ?: '' }}</span>
                    <x-lucide-plus class="size-4 shrink-0 text-muted-foreground transition-transform" x-bind:class="open ? 'rotate-45' : ''" />
                </button>
                <div x-show="open" x-cloak class="pb-3">
                    <div class="flex flex-wrap gap-1.5">
                        @forelse($workplace['tags'] ?? [] as $lsTag)
                            <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-foreground">
                                {{ $lsTag['tag'] }}
                                <form method="POST" action="{{ route('laraslice.records.tags.destroy', [$lsSlice, $lsId, $lsTag['id']]) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-muted-foreground hover:text-destructive" title="Remove tag">×</button>
                                </form>
                            </span>
                        @empty
                            <span class="text-[13px] text-muted-foreground">No tags yet.</span>
                        @endforelse
                    </div>
                    <form method="POST" action="{{ route('laraslice.records.tags.store', [$lsSlice, $lsId]) }}" class="mt-2 flex gap-1.5">
                        @csrf
                        <input type="text" name="tag" required maxlength="80" placeholder="Add a tag" class="h-8 min-w-0 flex-1 rounded-md border border-input bg-background px-2.5 text-xs">
                        <button type="submit" class="h-8 shrink-0 rounded-md border border-input bg-background px-2.5 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Add</button>
                    </form>
                </div>
            </div>

            <div x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center gap-2 py-2.5 text-left text-[13px] text-foreground">
                    <span class="font-medium">Share</span>
                    <span class="flex-1 text-right text-muted-foreground">{{ count($workplace['shares'] ?? []) ?: '' }}</span>
                    <x-lucide-plus class="size-4 shrink-0 text-muted-foreground transition-transform" x-bind:class="open ? 'rotate-45' : ''" />
                </button>
                <div x-show="open" x-cloak class="pb-3">
                    @forelse($workplace['shares'] ?? [] as $lsShare)
                        <div class="flex items-center gap-2 py-1 text-[13px]">
                            <span class="min-w-0 flex-1 truncate text-foreground">{{ $lsShare['user_label'] ?? '' }}</span>
                            <form method="POST" action="{{ route('laraslice.records.shares.destroy', [$lsSlice, $lsId, $lsShare['id']]) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[11px] font-medium text-destructive hover:underline">Remove</button>
                            </form>
                        </div>
                    @empty
                        <p class="py-1 text-[13px] text-muted-foreground">Not shared with anyone.</p>
                    @endforelse
                    <form method="POST" action="{{ route('laraslice.records.shares.store', [$lsSlice, $lsId]) }}" class="mt-1.5 flex gap-1.5">
                        @csrf
                        <select name="user_id" required class="h-8 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-xs">
                            <option value="">Select a user…</option>
                            @foreach($lsUsers as $lsUserId => $lsUserLabel)
                                <option value="{{ $lsUserId }}">{{ $lsUserLabel }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="h-8 shrink-0 rounded-md border border-input bg-background px-2.5 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Share</button>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <div class="mt-3 space-y-1 text-xs text-muted-foreground">
        @if(!empty($workplace['audit']['created_at']))
            <p>Created{{ $lsActor($workplace['audit']['created_by'] ?? null) ? ' By '.$lsActor($workplace['audit']['created_by']) : '' }} · {{ $lsRel($workplace['audit']['created_at']) }}</p>
        @endif
        @if(!empty($workplace['audit']['updated_at']))
            <p>Last Edited{{ $lsActor($workplace['audit']['updated_by'] ?? null) ? ' By '.$lsActor($workplace['audit']['updated_by']) : '' }} · {{ $lsRel($workplace['audit']['updated_at']) }}</p>
        @endif
    </div>
</aside>
    </div>{{-- /workplace flex row --}}

    <section class="mt-8" @if(!$lsSingle) x-show="lsWpTab === 'details' || lsWpTab === 'notes'" @endif>
        <h3 class="text-sm font-semibold text-foreground">Comments</h3>
        <form method="POST" action="{{ route('laraslice.records.notes.store', [$lsSlice, $lsId]) }}" class="mt-3 space-y-2">
            @csrf
            <textarea name="body" required rows="3" placeholder="Write a comment…" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"></textarea>
            <div class="flex justify-end">
                <button type="submit" class="inline-flex h-8 items-center rounded-md bg-primary px-3 text-xs font-semibold text-primary-foreground shadow-xs transition-colors hover:bg-primary/90">Add Comment</button>
            </div>
        </form>
        <div class="mt-2">
            @forelse($workplace['notes'] ?? [] as $lsNote)
                <div class="border-b border-border/60 py-3 last:border-0">
                    <p class="whitespace-pre-line text-sm text-foreground">{{ $lsNote['body'] }}</p>
                    <div class="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                        <span>{{ $lsNote['user_label'] ?? 'Someone' }}</span><span>·</span><span>{{ $lsRel($lsNote['created_at']) ?? $lsNote['created_at'] }}</span>
                        <form method="POST" action="{{ route('laraslice.records.notes.destroy', [$lsSlice, $lsId, $lsNote['id']]) }}" onsubmit="return confirm('Remove this comment?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="font-medium text-destructive hover:underline">Remove</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="py-2 text-sm text-muted-foreground">No comments yet.</p>
            @endforelse
        </div>
    </section>

    <section class="mt-8" @if(!$lsSingle) x-show="lsWpTab === 'details' || lsWpTab === 'activities'" @endif>
        <h3 class="text-sm font-semibold text-foreground">Activity</h3>
        <div class="mt-1">
            @forelse($workplace['activity'] ?? [] as $lsEvent)
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b border-border/60 py-2.5 last:border-0">
                    <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold capitalize text-foreground">{{ $lsEvent['action'] }}</span>
                    <span class="text-[13px] text-foreground">{{ $lsEvent['actor'] }}</span>
                    <span class="text-xs text-muted-foreground">{{ $lsRel($lsEvent['at']) ?? $lsEvent['at'] }}</span>
                    @if(!empty($lsEvent['summary']))<span class="w-full truncate text-xs text-muted-foreground">{{ $lsEvent['summary'] }}</span>@endif
                </div>
            @empty
                <p class="py-2 text-sm text-muted-foreground">No activity recorded for this record yet.</p>
            @endforelse
        </div>
    </section>

    @if(!$lsSingle && !empty($workplace['connections']))
        <section class="mt-8" x-show="lsWpTab === 'details' || lsWpTab === 'connections'">
            <h3 class="text-sm font-semibold text-foreground">Connections</h3>
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                @foreach($workplace['connections'] as $lsGroup)
                    <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-sm font-semibold text-foreground">{{ $lsGroup['title'] }}</h4>
                            <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">{{ $lsGroup['count'] }}</span>
                        </div>
                        <div class="mt-2 space-y-1">
                            @foreach($lsGroup['records'] as $lsLinked)
                                @if($lsLinked['url'])
                                    <a href="{{ $lsLinked['url'] }}" class="block truncate rounded-md px-2 py-1.5 text-[13px] font-medium text-foreground transition-colors hover:bg-muted">{{ $lsLinked['label'] }}</a>
                                @else
                                    <span class="block truncate px-2 py-1.5 text-[13px] text-foreground">{{ $lsLinked['label'] }}</span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>{{-- /workplace --}}
