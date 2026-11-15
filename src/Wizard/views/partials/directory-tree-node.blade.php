{{--
    One tree node (D-064): title (linking to the record's
    form), a Group marker, Add Child + Edit actions, and
    the recursively included children. Expand state is
    per-node Alpine, driven globally by the root's
    Expand/Collapse All window events.
--}}
<div x-data="{ open: true }" x-on:ls-tree-expand.window="open = true" x-on:ls-tree-collapse.window="open = false" style="margin-left: {{ min((int) ($depth ?? 0), 8) * 20 }}px">
    <div class="group flex items-center gap-2 border-b border-border/50 py-2">
        @if(!empty($node['children']))
            <button type="button" @click="open = !open" class="flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted" :aria-expanded="open">
                <x-lucide-chevron-right class="size-4 transition-transform duration-150" x-bind:class="open ? 'rotate-90' : ''" />
            </button>
        @else
            <span class="size-6 shrink-0"></span>
        @endif
        @if($node['edit_url'])
            <a href="{{ $node['edit_url'] }}" class="min-w-0 flex-1 truncate text-sm font-medium text-foreground hover:underline">{{ $node['title'] }}</a>
        @else
            <span class="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{{ $node['title'] }}</span>
        @endif
        @if(!empty($node['is_group']))
            <span class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">Group</span>
        @endif
        <span class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
            <button type="button" @click="lsParentId = '{{ $node['id'] }}'; lsParentLabel = @js($node['title']); lsCreateOpen = true" class="inline-flex h-7 items-center rounded-md px-2 text-[11px] font-semibold text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">Add Child</button>
            @if($node['edit_url'])
                <a href="{{ $node['edit_url'] }}" class="inline-flex h-7 items-center rounded-md px-2 text-[11px] font-semibold text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">Edit</a>
            @endif
        </span>
    </div>
    @if(!empty($node['children']))
        <div x-show="open" x-cloak>
            @foreach($node['children'] as $lsChild)
                @include('laraslice::partials.directory-tree-node', ['node' => $lsChild, 'depth' => ($depth ?? 0) + 1])
            @endforeach
        </div>
    @endif
</div>
