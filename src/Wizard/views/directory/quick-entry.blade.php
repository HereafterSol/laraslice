{{--
    Quick-entry dialog (D-011) — the directory Add dialog a
    slice declares with `ui.quick_entry.fields` in its Heart.
    The dialog carries exactly the declared subset, rendered
    with the same field components as the full create form
    (x-ui.label / x-ui.input / native selects fed by the
    controller-resolved option lists), and posts to the SAME
    store endpoint as the full form. Its footer carries Save
    plus "Edit Full Form" — a plain link to the full create
    page. Slices without the declaration never include this
    partial (the generator emits the include only for
    declared slices, and existing slices receive it by
    hand-carry), and the partial itself renders nothing when
    the controller resolved no quick-entry fields, so an
    undeclared directory is byte-for-byte unchanged.

    Add interception: the directory's create links point at
    the full create URL; this partial's script intercepts
    clicks on exactly that URL and opens the dialog instead
    of navigating. The "Edit Full Form" link carries
    data-ls-quick-entry-full and is never intercepted.
--}}
@if(!empty($quickEntryFields) && !empty($quickEntryStoreUrl) && !empty($quickEntryCreateUrl))
<div data-ls-quick-entry hidden>
    <div class="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4" data-ls-quick-entry-backdrop>
        <div class="mx-auto mt-[6vh] w-full max-w-2xl rounded-xl border border-border bg-card shadow-xl" role="dialog" aria-modal="true" aria-label="{{ $quickEntryTitle ?? 'New' }}">
            <div class="flex items-center justify-between border-b border-border/60 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-foreground">{{ $quickEntryTitle ?? 'New' }}</h2>
                <button type="button" data-ls-quick-entry-close class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground" aria-label="Close">
                    <x-lucide-x class="size-4" />
                </button>
            </div>
            <form method="POST" action="{{ $quickEntryStoreUrl }}" data-ls-quick-entry-form>
                @csrf
                <div class="grid grid-cols-1 gap-4 px-5 py-4 md:grid-cols-2">
                    @foreach($quickEntryFields as $qeField)
                        @if(!empty($qeField['section_title']))
                            <div class="md:col-span-2 border-t border-border/60 pt-3">
                                <h3 class="text-xs font-semibold text-foreground">{{ $qeField['section_title'] }}</h3>
                            </div>
                        @endif
                        @php($qeWrapAttrs = ' data-ls-field="'.$qeField['handle'].'"'.(!empty($qeField['depends_on']) ? ' data-ls-depends-on="'.e(json_encode($qeField['depends_on'])).'"' : '').(!empty($qeField['mandatory_depends_on']) ? ' data-ls-mandatory-on="'.e(json_encode($qeField['mandatory_depends_on'])).'"' : ''))
                        @if($qeField['input'] === 'checkbox')
                        <div class="space-y-1"{!! $qeWrapAttrs !!}>
                            <div class="flex items-center justify-between min-h-8">
                                <x-ui.label for="qe_{{ $qeField['handle'] }}" class="text-xs font-medium text-foreground/80">{{ $qeField['label'] }}@if($qeField['required']) *@endif</x-ui.label>
                                <input type="hidden" name="{{ $qeField['name'] ?? $qeField['handle'] }}" value="0">
                                <input type="checkbox" id="qe_{{ $qeField['handle'] }}" name="{{ $qeField['name'] ?? $qeField['handle'] }}" value="1" @checked((bool) old($qeField['handle'], $qeField['default'] ?? false)) class="h-4 w-4 rounded border-gray-300 text-primary" />
                            </div>
                            @if(!empty($qeField['description'])) <p class="text-[11px] leading-snug text-muted-foreground">{{ $qeField['description'] }}</p> @endif
                            @error($qeField['handle']) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                        </div>
                        @elseif($qeField['input'] === 'select')
                        <div class="space-y-1"{!! $qeWrapAttrs !!}>
                            <x-ui.label for="qe_{{ $qeField['handle'] }}" class="text-xs font-medium text-foreground/80">{{ $qeField['label'] }}@if($qeField['required']) *@endif</x-ui.label>
                            <select id="qe_{{ $qeField['handle'] }}" name="{{ $qeField['name'] ?? $qeField['handle'] }}" @if($qeField['required']) required @endif class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="">Select {{ $qeField['label'] }}...</option>
                                @foreach($qeField['options'] as $qeOpt)
                                <option value="{{ $qeOpt['value'] }}" @selected((string) old($qeField['handle'], $qeField['default'] ?? '') === (string) $qeOpt['value'])>{{ $qeOpt['label'] }}</option>
                                @endforeach
                            </select>
                            @if(!empty($qeField['description'])) <p class="text-[11px] leading-snug text-muted-foreground">{{ $qeField['description'] }}</p> @endif
                            @error($qeField['handle']) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                        </div>
                        @elseif($qeField['input'] === 'textarea')
                        <div class="space-y-1 md:col-span-2"{!! $qeWrapAttrs !!}>
                            <x-ui.label for="qe_{{ $qeField['handle'] }}" class="text-xs font-medium text-foreground/80">{{ $qeField['label'] }}@if($qeField['required']) *@endif</x-ui.label>
                            <textarea id="qe_{{ $qeField['handle'] }}" name="{{ $qeField['name'] ?? $qeField['handle'] }}" @if($qeField['required']) required @endif class="flex w-full rounded-md border border-input bg-background px-2.5 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">{{ old($qeField['handle'], $qeField['default'] ?? '') }}</textarea>
                            @if(!empty($qeField['description'])) <p class="text-[11px] leading-snug text-muted-foreground">{{ $qeField['description'] }}</p> @endif
                            @error($qeField['handle']) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                        </div>
                        @else
                        <div class="space-y-1"{!! $qeWrapAttrs !!}>
                            <x-ui.label for="qe_{{ $qeField['handle'] }}" class="text-xs font-medium text-foreground/80">{{ $qeField['label'] }}@if($qeField['required']) *@endif</x-ui.label>
                            @if($qeField['required'])
                            <x-ui.input id="qe_{{ $qeField['handle'] }}" size="sm" type="{{ $qeField['input'] }}" name="{{ $qeField['name'] ?? $qeField['handle'] }}" value="{{ old($qeField['handle'], $qeField['default'] ?? '') }}" required />
                            @else
                            <x-ui.input id="qe_{{ $qeField['handle'] }}" size="sm" type="{{ $qeField['input'] }}" name="{{ $qeField['name'] ?? $qeField['handle'] }}" value="{{ old($qeField['handle'], $qeField['default'] ?? '') }}" />
                            @endif
                            @if(!empty($qeField['description'])) <p class="text-[11px] leading-snug text-muted-foreground">{{ $qeField['description'] }}</p> @endif
                            @error($qeField['handle']) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                        </div>
                        @endif
                    @endforeach
                </div>
                <div class="flex items-center justify-between border-t border-border/60 px-5 py-3.5">
                    <a href="{{ $quickEntryCreateUrl }}" data-ls-quick-entry-full class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-semibold text-foreground hover:bg-muted">Edit Full Form</a>
                    <x-ui.button type="submit" size="sm" class="gap-1.5 text-xs font-semibold shadow-xs">
                        <span>Save</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var root = document.querySelector('[data-ls-quick-entry]');
    if (!root) return;
    var createUrl = @json($quickEntryCreateUrl);
    function openQuickEntry() {
        root.hidden = false;
        var first = root.querySelector('form input:not([type="hidden"]), form select, form textarea');
        if (first) first.focus();
    }
    function closeQuickEntry() { root.hidden = true; }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-ls-quick-entry-close]')) { closeQuickEntry(); return; }
        var backdrop = e.target.closest('[data-ls-quick-entry-backdrop]');
        if (backdrop && e.target === backdrop) { closeQuickEntry(); return; }
        var link = e.target.closest('a[href]');
        if (link && link.getAttribute('href') === createUrl && !link.hasAttribute('data-ls-quick-entry-full')) {
            e.preventDefault();
            openQuickEntry();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !root.hidden) closeQuickEntry();
    });
})();
</script>
@endif
