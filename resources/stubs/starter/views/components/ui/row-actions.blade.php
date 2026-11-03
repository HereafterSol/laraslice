@props([
    'viewUrl' => null,
    'editUrl' => null,
    'deleteUrl' => null,
    'deleteConfirm' => 'Are you sure you want to delete this record?',
    // F7: when set ('drawer'|'modal'), the link loads its canonical URL
    // into the list page's overlay shell instead of navigating.
    'viewOverlay' => null,
    'editOverlay' => null,
    // L3: declared record actions (payload from RecordActions::urlsFor):
    // a JS expression evaluating to [{name, label, icon, confirm, url}].
    'actions' => null,
])

{{-- Standard list pattern (P1): row actions collapse into ONE kebab
     menu — never a row of text links stretching the table. --}}
<div class="relative flex justify-end" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <button type="button" @click="open = !open" class="size-8 rounded-lg hover:bg-muted/80 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors" title="Actions" aria-haspopup="true" :aria-expanded="open">
        <x-lucide-more-vertical class="size-4" />
    </button>
    <div x-show="open" x-cloak x-transition.opacity.duration.100ms class="absolute right-0 top-9 z-30 w-40 rounded-lg border border-border bg-card p-1 shadow-lg">
        @if($viewUrl)
            <a :href="{{ $viewUrl }}" @if($viewOverlay) :data-overlay-url="{{ $viewUrl }}" data-overlay-mode="{{ $viewOverlay }}" data-overlay-title="View" @endif @click="open = false" class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs text-foreground hover:bg-muted/70 transition-colors">
                <x-lucide-eye class="size-4 text-muted-foreground" /> View
            </a>
        @endif
        @if($editUrl)
            <a :href="{{ $editUrl }}" @if($editOverlay) :data-overlay-url="{{ $editUrl }}" data-overlay-mode="{{ $editOverlay }}" data-overlay-title="Edit" @endif @click="open = false" class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs text-foreground hover:bg-muted/70 transition-colors">
                <x-lucide-pencil class="size-4 text-muted-foreground" /> Edit
            </a>
        @endif
        @if($actions)
            <template x-for="action in {{ $actions }}" :key="action.name">
                <span>
                    <template x-if="action.mode === 'link'">
                        <a :href="action.url" :target="action.target || '_self'" @click="open = false" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs text-foreground hover:bg-muted/70 transition-colors">
                            <span class="size-4 shrink-0 rounded-full border border-border/70 bg-muted/60" aria-hidden="true"></span>
                            <span x-text="action.label"></span>
                        </a>
                    </template>
                    <template x-if="action.mode === 'copy'">
                        <button type="button" @click="open = false; if (navigator.clipboard) { navigator.clipboard.writeText(action.url); }" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs text-foreground hover:bg-muted/70 transition-colors">
                            <span class="size-4 shrink-0 rounded-full border border-border/70 bg-muted/60" aria-hidden="true"></span>
                            <span x-text="action.label"></span>
                        </button>
                    </template>
                    <template x-if="!action.mode || action.mode === 'post'">
                        <button type="button" @click="open = false; const submit = () => { const f = document.createElement('form'); f.method = 'POST'; f.action = action.url; const t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = document.querySelector('meta[name=csrf-token]')?.content || ''; f.appendChild(t); document.body.appendChild(f); f.submit(); }; if (!action.confirm) { submit(); } else if (window.lsConfirm) { window.lsConfirm(action.confirm, 'Continue').then(ok => { if (ok) submit(); }); } else if (confirm(action.confirm)) { submit(); }" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs text-foreground hover:bg-muted/70 transition-colors">
                            <span class="size-4 shrink-0 rounded-full border border-border/70 bg-muted/60" aria-hidden="true"></span>
                            <span x-text="action.label"></span>
                        </button>
                    </template>
                </span>
            </template>
        @endif
        @if($deleteUrl)
            {{-- Delete submits a real DELETE form (built in JS, the
                 same pattern as the declared record actions above).
                 It previously dispatched a 'delete-record' event
                 that nothing listened for — the confirm appeared,
                 the user confirmed, and nothing was deleted. --}}
            <button type="button" @click="open = false; const submit = () => { const f = document.createElement('form'); f.method = 'POST'; f.action = {{ $deleteUrl }}; const t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = document.querySelector('meta[name=csrf-token]')?.content || ''; f.appendChild(t); const m = document.createElement('input'); m.type = 'hidden'; m.name = '_method'; m.value = 'DELETE'; f.appendChild(m); document.body.appendChild(f); f.submit(); }; if (window.lsConfirm) { window.lsConfirm('{{ $deleteConfirm }}', 'Yes, Delete It!').then(ok => { if (ok) submit(); }); } else if (confirm('{{ $deleteConfirm }}')) { submit(); }" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs text-rose-600 hover:bg-rose-500/10 transition-colors">
                <x-lucide-trash-2 class="size-4" /> Delete
            </button>
        @endif
    </div>
</div>
