{{--
    Declared tab post form (ui.show.tabs[].post): an inline create
    for the tab's child collection — e.g. posting a reply from a
    ticket's Replies tab. Renders only when relatedTabsFromManifest
    resolved the child slice's store route into $rtab['post_url']
    (and the current user holds the child's create ability).
    The child slice's own controller/service authoritatively
    validates and stamps server-owned fields on save.
--}}
@php
    $post = $rtab['post'] ?? [];
    $postField = $post['field'] ?? 'message';
    $postFk = $post['foreign_key'] ?? null;
@endphp
@if(!empty($rtab['post_url']) && $postFk && isset($record))
<div class="rounded-xl border border-border bg-card p-4 shadow-xs">
    <form action="{{ $rtab['post_url'] }}" method="POST" class="space-y-3">
        @csrf
        <input type="hidden" name="{{ $postFk }}" value="{{ $record->id }}">
        {{-- Land back on this hub page (same tab) after the
             store, like the source systems' in-page reply. --}}
        <input type="hidden" name="_return" value="/{{ request()->path() }}{{ request()->getQueryString() ? '?'.request()->getQueryString() : '' }}">
        <label for="tab-post-{{ $rtab['handle'] ?? 'reply' }}" class="text-xs font-semibold text-foreground block">{{ $post['label'] ?? 'Add' }}</label>
        <textarea id="tab-post-{{ $rtab['handle'] ?? 'reply' }}" name="{{ $postField }}" rows="3" required
            placeholder="{{ $post['placeholder'] ?? '' }}"
            class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"></textarea>
        <div class="flex justify-end">
            <x-ui.button type="submit" size="sm">{{ $post['button'] ?? 'Post' }}</x-ui.button>
        </div>
    </form>
</div>
@endif
