{{--
    Fragment layout (F7): renders ONLY the view's content section —
    no app chrome — for overlay fetches. When a slice screen is
    declared as a drawer/modal presentation, the base controller
    answers the overlay loader's request for the SAME canonical URL
    with this layout, mirroring how the source ERP returns an ajax
    fragment for its modal binding. Standalone visits keep the full
    app layout.
--}}
@yield('content')
