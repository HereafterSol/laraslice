<title>{{ config('app.name', 'LaraSlice') }} - Vertical Slice Architecture</title>
<meta charset="utf-8" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
<meta content="follow, index" name="robots" />
<link href="{{ url(request()->path()) }}" rel="canonical" />
<meta content="width=device-width, initial-scale=1, shrink-to-fit=no" name="viewport" />
<meta content="LaraSlice Modular Vertical Slice Enterprise Application" name="description" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet" />

<!-- Fast Instant Theme Restorer: Prevents Flash of Unstyled Theme before Alpine boots -->
{{-- Server-saved brand theme (Settings → Theme, Stage C-3):
     seeded ahead of the restorer; brand dimensions prefer the
     server values over this browser's localStorage, so a saved
     theme reaches every user. Mode (light/dark) stays personal. --}}
<script>window.__lsTheme = @json(\LaraSlice\Support\SettingsUi::savedTheme());</script>
<script>
(function() {
    try {
        const server = window.__lsTheme || {};
        const pick = (key) => (server[key] !== undefined && server[key] !== null && server[key] !== '') ? server[key] : localStorage.getItem('theme:' + key);

        const mode = localStorage.getItem('theme:mode') || 'light';
        const isDark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (isDark) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }

        const base = pick('base');
        if (base && base !== 'neutral') {
            document.documentElement.setAttribute('data-base', base);
        } else {
            document.documentElement.removeAttribute('data-base');
        }

        const preset = pick('preset');
        if (preset && preset !== 'default') {
            document.documentElement.setAttribute('data-theme', preset);
        } else {
            document.documentElement.removeAttribute('data-theme');
        }

        const radius = pick('radius');
        if (radius) {
            document.documentElement.setAttribute('data-radius', radius);
        }

        const font = pick('font');
        if (font && font !== 'sans') {
            document.documentElement.setAttribute('data-font', font);
        }

        const inputStyle = pick('inputStyle');
        if (inputStyle && inputStyle !== 'outline') {
            document.documentElement.setAttribute('data-input-style', inputStyle);
        }
    } catch (e) {}
})();
</script>
