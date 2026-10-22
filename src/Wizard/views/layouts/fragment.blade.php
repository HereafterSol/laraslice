{{--
    Fragment layout (F7): renders ONLY the view's content section —
    no app chrome — for overlay fetches. When a slice screen is
    declared as a drawer/modal presentation, the base controller
    answers the overlay loader's request for the SAME canonical URL
    with this layout, mirroring how the source ERP returns an ajax
    fragment for its modal binding. Standalone visits keep the full
    app layout.
--}}
<style>
    /* Overlay form rules (S-UI 3): inside a drawer/modal the
       screen is single-column fluid — declared section grids
       collapse, field spans reset, and the page-level form
       container gives up its max-width/centering. */
    .ls-overlay [data-ls-section-grid] { grid-template-columns: minmax(0, 1fr) !important; }
    .ls-overlay [data-ls-section-grid] > * { grid-column: auto !important; }
    .ls-overlay [data-ls-form-root] { max-width: none !important; margin: 0 !important; }
</style>
<div class="ls-overlay">
@yield('content')
</div>
