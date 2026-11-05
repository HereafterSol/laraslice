@php
    $sectionTitle = trim((string) $__env->yieldContent('title'));
    $documentTitle = \LaraSlice\Support\EnterpriseShell::documentTitle($sectionTitle !== '' ? $sectionTitle : null);
@endphp
<title>{{ $documentTitle }}</title>
<meta charset="utf-8" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
<meta content="follow, index" name="robots" />
<link href="{{ url(request()->path()) }}" rel="canonical" />
<meta content="width=device-width, initial-scale=1, shrink-to-fit=no" name="viewport" />
<meta content="{{ config('app.name', 'LaraSlice') }} — enterprise slice application" name="description" />
<style>
    :root {
        --ls-shell-title: 1rem;
        --ls-shell-subtitle: 0.6875rem;
        --ls-content-max: 1600px;
        --ls-filterbar-py: 0.375rem;
    }
</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet" />

<!-- Fast Instant Theme Restorer: Prevents Flash of Unstyled Theme before Alpine boots -->
<script>
(function() {
    try {
        const mode = localStorage.getItem('theme:mode') || 'light';
        const isDark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (isDark) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }

        const base = localStorage.getItem('theme:base');
        if (base && base !== 'neutral') {
            document.documentElement.setAttribute('data-base', base);
        } else {
            document.documentElement.removeAttribute('data-base');
        }

        const preset = localStorage.getItem('theme:preset');
        if (preset && preset !== 'default') {
            document.documentElement.setAttribute('data-theme', preset);
        } else {
            document.documentElement.removeAttribute('data-theme');
        }

        const radius = localStorage.getItem('theme:radius');
        if (radius) {
            document.documentElement.setAttribute('data-radius', radius);
        }

        const font = localStorage.getItem('theme:font');
        if (font && font !== 'sans') {
            document.documentElement.setAttribute('data-font', font);
        }

        const inputStyle = localStorage.getItem('theme:inputStyle');
        if (inputStyle && inputStyle !== 'outline') {
            document.documentElement.setAttribute('data-input-style', inputStyle);
        }
    } catch (e) {}
})();
</script>
