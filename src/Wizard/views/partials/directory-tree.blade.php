{{--
    Tree directory (D-064): the landing view for slices
    declaring ui.directory.tree — a nested, expandable
    tree of every record (the source's Tree View for
    group DocTypes), with per-node Edit / Add Child and
    the Desk "Create New" child dialog. Data comes from
    BaseSliceWebController::directoryTreeData; the table
    view stays one toggle away (?view=list).
--}}
@php
    $lsTree = $directoryTree ?? null;
    $lsTreeCfg = $lsTree['config'] ?? [];
@endphp
@if($lsTree)
<div x-data="{ lsCreateOpen: false, lsParentId: '', lsParentLabel: '' }" class="rounded-xl border border-border bg-card p-4 shadow-xs">
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <button type="button" @click="window.dispatchEvent(new CustomEvent('ls-tree-expand'))" class="inline-flex h-8 items-center rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Expand All</button>
        <button type="button" @click="window.dispatchEvent(new CustomEvent('ls-tree-collapse'))" class="inline-flex h-8 items-center rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Collapse All</button>
        @if(!empty($lsTree['store_url']))
            <button type="button" @click="lsParentId = ''; lsParentLabel = ''; lsCreateOpen = true" class="inline-flex h-8 items-center rounded-md bg-primary px-3 text-xs font-semibold text-primary-foreground shadow-xs transition-colors hover:bg-primary/90">Add {{ $lsTree['entity'] }}</button>
        @endif
    </div>

    @forelse($lsTree['nodes'] as $lsNode)
        @include('laraslice::partials.directory-tree-node', ['node' => $lsNode, 'depth' => 0])
    @empty
        <p class="py-6 text-center text-sm text-muted-foreground">No records yet.</p>
    @endforelse

    @if(!empty($lsTree['store_url']))
        <div x-show="lsCreateOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="lsCreateOpen = false">
            <form method="POST" action="{{ $lsTree['store_url'] }}" class="w-full max-w-md rounded-xl border border-border bg-card p-5 shadow-lg">
                @csrf
                <h3 class="text-base font-semibold text-foreground">Create New</h3>
                @if(!empty($lsTreeCfg['group_field']))
                    <label class="mt-4 flex items-start gap-2.5 text-sm text-foreground">
                        <input type="hidden" name="{{ $lsTreeCfg['group_field'] }}" value="0">
                        <input type="checkbox" name="{{ $lsTreeCfg['group_field'] }}" value="1" class="mt-0.5 h-4 w-4 rounded border-input text-primary focus:ring-primary">
                        <span>Is Group
                            <span class="block text-xs font-normal text-muted-foreground">Further sub-groups can only be created under records marked as 'Group'.</span>
                        </span>
                    </label>
                @endif
                <div class="mt-4 space-y-1">
                    <label for="ls-tree-title" class="block text-xs font-medium text-foreground/80">{{ $lsTree['title_label'] }} <span class="text-destructive">*</span></label>
                    <input id="ls-tree-title" type="text" name="{{ $lsTreeCfg['title_field'] }}" required class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                </div>
                <input type="hidden" name="{{ $lsTreeCfg['parent_field'] }}" x-model="lsParentId">
                <p class="mt-2 text-xs text-muted-foreground" x-show="lsParentLabel !== ''">Parent: <span class="font-medium text-foreground" x-text="lsParentLabel"></span></p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" @click="lsCreateOpen = false" class="inline-flex h-8 items-center rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Cancel</button>
                    <button type="submit" class="inline-flex h-8 items-center rounded-md bg-primary px-3 text-xs font-semibold text-primary-foreground shadow-xs transition-colors hover:bg-primary/90">Create New</button>
                </div>
            </form>
        </div>
    @endif
</div>
@endif
