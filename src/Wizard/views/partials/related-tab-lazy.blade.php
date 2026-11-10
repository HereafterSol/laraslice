{{--
    Lazy hub tab panel (ui.show.lazy_tabs): the show page ships
    the tab strip + counts only; this placeholder fetches the
    real panel from the slice's tab fragment endpoint on first
    activation and swaps it in. Activation arrives three ways —
    the ls-show-tab window event (layout-kit strip), a ?tab=
    deep link present at load, and the panel becoming visible
    (IntersectionObserver covers strips that set state without
    dispatching the event). Scripts inside the fragment are
    re-created so they execute (innerHTML never runs them),
    and Alpine is initialised over the injected subtree.
--}}
<div x-data="{
        loaded: false,
        loading: false,
        load() {
            if (this.loaded || this.loading) return;
            this.loading = true;
            fetch('{{ $rtab['fragment_url'] ?? '' }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => { if (!r.ok) throw new Error('fragment'); return r.text(); })
                .then(h => {
                    const el = this.$refs.body;
                    el.innerHTML = h;
                    el.querySelectorAll('script').forEach(s => {
                        const n = document.createElement('script');
                        for (const a of s.attributes) n.setAttribute(a.name, a.value);
                        n.textContent = s.textContent;
                        s.replaceWith(n);
                    });
                    if (window.Alpine) Alpine.initTree(el);
                    this.loaded = true;
                    this.loading = false;
                })
                .catch(() => { this.loading = false; });
        }
     }"
     x-init="
        if ((new URLSearchParams(window.location.search).get('tab') || '') === '{{ $rtab['handle'] }}') load();
        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver(entries => {
                if (entries.some(e => e.isIntersecting)) { load(); io.disconnect(); }
            }, { threshold: 0.05 });
            io.observe($el);
        }
     "
     @ls-show-tab.window="if ($event.detail === '{{ $rtab['handle'] }}') load()">
    <div x-show="loading" class="flex items-center gap-2 py-8 text-xs text-muted-foreground">
        <x-ui.spinner class="h-4 w-4 animate-spin" />
        <span>Loading {{ $rtab['label'] ?? Str::headline($rtab['handle']) }}…</span>
    </div>
    <div x-show="!loaded && !loading" class="py-8 text-center text-xs text-muted-foreground">
        Open this tab to load its records.
    </div>
    <div x-ref="body" x-show="loaded"></div>
</div>
