<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;

/**
 * Sidebar navigation rendering (backlog B3, Stage C-1).
 *
 * The starter layout used to render ~190 nav items through
 * nested Blade components — a collapsible, a menu button
 * and an Alpine tooltip wrapper per item — which cost
 * roughly 660KB of HTML and ~1.2s of render time on EVERY
 * page. This builder emits equivalent markup as plain
 * strings:
 *
 *  - same DOM slots and class strings, so the theme CSS
 *    (including data-[active=true] styling) is untouched;
 *  - icons are an SVG sprite: each icon's path data ships
 *    ONCE per page as a <symbol>, items reference it with
 *    <use> (the fleet's pages carried 150+ full inline
 *    SVGs, ~86KB, before this);
 *  - the shell ships the Workspace group and the current
 *    page's own domain group in full; every other domain
 *    group is a header shell whose items load lazily from
 *    the nav-group endpoint on first expand (October-style
 *    context navigation) — see lazyScript();
 *  - NO per-item tooltip component: the collapsed rail
 *    falls back to the native title tooltip;
 *  - NO server-baked active state: a small script marks
 *    the current page's link client-side
 *    (see activeScript()), which is what makes the whole
 *    fragment cacheable;
 *  - fragments are cached under a key derived from the nav
 *    STRUCTURE itself (titles + urls + icons): any registry
 *    or permission change alters the structure and
 *    therefore the key — no invalidation bookkeeping.
 */
final class NavHtml
{
    private const BUTTON_CLASS = 'peer/menu-button ls-nav-btn';

    private const SUB_BUTTON_CLASS = 'ls-nav-sub-btn';

    private const PALETTE_ITEM_CLASS = "data-[selected=true]:bg-accent data-[selected=true]:text-accent-foreground hover:bg-accent hover:text-accent-foreground [&_svg:not([class*='text-'])]:text-muted-foreground relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4";

    /** @var array<string, ?string> icon name => <symbol> markup (null = unparseable, raw fallback) */
    private static array $symbols = [];

    /** @var array<string, string> icon name => raw rendered svg (fallback path) */
    private static array $rawIcons = [];

    /**
     * Render the nav groups, fragment-cached by structure.
     *
     * @param  array<int, array{title: string, items: array<int, array<string, mixed>>}>  $navMain
     */
    public static function cached(array $navMain): string
    {
        $key = 'laraslice:navhtml:v3:'.md5((string) json_encode(self::structure($navMain)));

        return Cache::remember($key, now()->addHours(24), static fn () => self::render($navMain));
    }

    /**
     * Render the shell: Workspace + the active group in full,
     * all other groups as lazy header shells. Cached by
     * structure + active group.
     *
     * @param  array<int, array{title: string, items: array<int, array<string, mixed>>}>  $navMain
     */
    public static function cachedShell(array $navMain, ?string $activeGroup): string
    {
        $key = 'laraslice:navhtml:v3:shell:'.md5((string) json_encode([self::structure($navMain), $activeGroup]));

        return Cache::remember($key, now()->addHours(24), static fn () => self::renderShell($navMain, $activeGroup));
    }

    /**
     * @param  array<int, array{title: string, items: array<int, array<string, mixed>>}>  $navMain
     */
    public static function render(array $navMain): string
    {
        $html = '';
        foreach ($navMain as $group) {
            $html .= self::group($group);
        }

        return self::defs($navMain).$html;
    }

    /**
     * @param  array<int, array{title: string, items: array<int, array<string, mixed>>}>  $navMain
     */
    public static function renderShell(array $navMain, ?string $activeGroup): string
    {
        $html = '';
        foreach ($navMain as $group) {
            $title = (string) ($group['title'] ?? '');
            $html .= ($title === 'Workspace' || $title === $activeGroup)
                ? self::group($group)
                : self::lazyGroup($group);
        }

        return self::defs($navMain).$html;
    }

    /**
     * The <li> items of one group — the lazy-group endpoint's
     * payload (chrome already lives in the shell).
     *
     * @param  array{title: string, items: array<int, array<string, mixed>>}  $group
     */
    public static function renderGroupItems(array $group): string
    {
        $html = '';
        foreach ($group['items'] ?? [] as $item) {
            $html .= self::item($item);
        }

        return $html;
    }

    /**
     * @param  array<int, array{title: string, items: array<int, array<string, mixed>>}>  $navMain
     * @return array<int, mixed>
     */
    private static function structure(array $navMain): array
    {
        $structure = [];
        foreach ($navMain as $group) {
            $items = [];
            foreach ($group['items'] ?? [] as $item) {
                $items[] = [
                    $item['title'] ?? '',
                    $item['url'] ?? '',
                    $item['icon'] ?? '',
                    array_map(static fn ($c) => [$c['title'] ?? '', $c['url'] ?? ''], $item['children'] ?? []),
                ];
            }
            $structure[] = [$group['title'] ?? '', $items];
        }

        return $structure;
    }

    /**
     * The sprite: one <symbol> per icon used anywhere in the
     * structure (including lazy groups, whose items arrive
     * later and reference these ids), emitted once.
     *
     * @param  array<int, array{title: string, items: array<int, array<string, mixed>>}>  $navMain
     */
    private static function defs(array $navMain): string
    {
        $names = ['chevron-right' => true];
        foreach ($navMain as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $names[(string) ($item['icon'] ?? 'box')] = true;
            }
        }
        $symbols = '';
        foreach (array_keys($names) as $name) {
            $symbols .= self::symbol($name) ?? '';
        }
        if ($symbols === '') {
            return '';
        }

        return '<svg aria-hidden="true" width="0" height="0" style="position:absolute;overflow:hidden"><defs>'.$symbols.'</defs></svg>';
    }

    private static function group(array $group): string
    {
        $title = (string) ($group['title'] ?? '');
        $items = $group['items'] ?? [];
        $firstUrl = (string) ($items[0]['url'] ?? '');
        $head = $firstUrl !== ''
            ? '<a href="'.e($firstUrl).'" class="flex-1 hover:text-primary transition-colors truncate font-semibold" title="Go to '.e($title).'">'.e($title).'</a>'
            : '<span class="flex-1 truncate font-semibold">'.e($title).'</span>';
        $chevron = self::icon('chevron-right', 'size-4 transition-transform group-data-[state=open]/collapsible:rotate-90');

        $lis = '';
        foreach ($items as $item) {
            $lis .= self::item($item);
        }

        return '<div data-slot="collapsible" x-data="{ open: true }" class="group/collapsible" :data-state="open ? \'open\' : \'closed\'">'
            .'<div data-slot="sidebar-group" data-sidebar="group" class="ls-nav-group">'
            .'<div class="group/label ls-nav-group-head">'
            .$head
            .'<button type="button" @click="open = !open" class="p-1 rounded hover:bg-muted/60 transition cursor-pointer shrink-0 ml-1" aria-label="Toggle '.e($title).'">'.$chevron.'</button>'
            .'</div>'
            .'<div data-slot="collapsible-content" x-show="open" x-collapse :data-state="open ? \'open\' : \'closed\'">'
            .'<div data-slot="sidebar-group-content" data-sidebar="group-content" class="ls-nav-group-content">'
            .'<ul data-slot="sidebar-menu" data-sidebar="menu" class="ls-nav-menu">'
            .$lis
            .'</ul></div></div></div></div>';
    }

    /**
     * A group header shell with an empty item list: the items
     * load from the nav-group endpoint on first expand
     * (lazyScript wires the toggle button).
     *
     * @param  array{title: string, items: array<int, array<string, mixed>>}  $group
     */
    private static function lazyGroup(array $group): string
    {
        $title = (string) ($group['title'] ?? '');
        $items = $group['items'] ?? [];
        $firstUrl = (string) ($items[0]['url'] ?? '');
        $head = $firstUrl !== ''
            ? '<a href="'.e($firstUrl).'" class="flex-1 hover:text-primary transition-colors truncate font-semibold" title="Go to '.e($title).'">'.e($title).'</a>'
            : '<span class="flex-1 truncate font-semibold">'.e($title).'</span>';
        $chevron = self::icon('chevron-right', 'size-4 transition-transform group-data-[state=open]/collapsible:rotate-90');

        return '<div data-slot="collapsible" data-nav-lazy="'.e($title).'" x-data="{ open: false }" class="group/collapsible" :data-state="open ? \'open\' : \'closed\'">'
            .'<div data-slot="sidebar-group" data-sidebar="group" class="ls-nav-group">'
            .'<div class="group/label ls-nav-group-head">'
            .$head
            .'<button type="button" data-nav-lazy-toggle @click="open = !open" class="p-1 rounded hover:bg-muted/60 transition cursor-pointer shrink-0 ml-1" aria-label="Toggle '.e($title).'">'.$chevron.'</button>'
            .'</div>'
            .'<div data-slot="collapsible-content" x-show="open" x-collapse :data-state="open ? \'open\' : \'closed\'">'
            .'<div data-slot="sidebar-group-content" data-sidebar="group-content" class="ls-nav-group-content">'
            .'<ul data-slot="sidebar-menu" data-sidebar="menu" data-nav-lazy-list class="ls-nav-menu">'
            .'</ul></div></div></div></div>';
    }

    private static function item(array $item): string
    {
        // Section header marker (workspace-sidebar sections
        // inside a domain group, Stage E addendum): a quiet
        // caps label, not a link.
        if (! empty($item['header'])) {
            return '<li data-slot="sidebar-menu-item" data-sidebar="menu-item" class="relative mt-3 first:mt-0 px-2 pt-1.5 pb-0.5 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/45 select-none">'
                .e((string) $item['header']).'</li>';
        }
        $title = (string) ($item['title'] ?? '');
        $children = $item['children'] ?? [];
        if ($children === []) {
            return '<li data-slot="sidebar-menu-item" data-sidebar="menu-item" class="group/menu-item relative">'
                .'<a href="'.e((string) ($item['url'] ?? '')).'" data-slot="sidebar-menu-button" data-sidebar="menu-button" data-size="default" data-nav-link title="'.e($title).'" class="'.self::BUTTON_CLASS.'">'
                .self::icon((string) ($item['icon'] ?? 'box'))
                .'<span>'.e($title).'</span></a></li>';
        }

        $subs = '';
        foreach ($children as $child) {
            $subs .= '<li data-slot="sidebar-menu-sub-item" data-sidebar="menu-sub-item" class="group/menu-sub-item relative">'
                .'<a href="'.e((string) ($child['url'] ?? '')).'" data-slot="sidebar-menu-sub-button" data-sidebar="menu-sub-button" data-size="md" data-nav-link class="'.self::SUB_BUTTON_CLASS.'">'
                .'<span>'.e((string) ($child['title'] ?? '')).'</span></a></li>';
        }
        $chevron = self::icon('chevron-right', 'ml-auto transition-transform duration-200 group-data-[state=open]/menu-collapsible:rotate-90');

        return '<li data-slot="sidebar-menu-item" data-sidebar="menu-item" class="group/menu-item relative">'
            .'<div data-slot="collapsible" x-data="{ open: false }" class="group/menu-collapsible" :data-state="open ? \'open\' : \'closed\'" data-nav-parent>'
            .'<button type="button" data-slot="sidebar-menu-button" data-sidebar="menu-button" data-size="default" data-nav-toggle @click="open = !open" :aria-expanded="open" title="'.e($title).'" class="'.self::BUTTON_CLASS.'">'
            .self::icon((string) ($item['icon'] ?? 'box'))
            .'<span class="min-w-0 truncate">'.e($title).'</span>'.$chevron.'</button>'
            .'<div data-slot="collapsible-content" x-show="open" x-collapse :data-state="open ? \'open\' : \'closed\'">'
            .'<ul data-slot="sidebar-menu-sub" data-sidebar="menu-sub" class="ls-nav-sub">'
            .$subs
            .'</ul></div></div></li>';
    }

    /**
     * A sprite reference for the icon; the path data itself
     * ships once in defs(). Falls back to the raw inline SVG
     * when the rendered markup cannot be parsed into a symbol.
     */
    private static function icon(string $name, string $class = ''): string
    {
        if (self::symbol($name) === null) {
            $svg = self::rawIcon($name);
            if ($class === '') {
                return $svg;
            }
            // Merge the caller's class into the raw svg tag.
            return preg_replace('/<svg\b/', '<svg class="'.e($class).'"', $svg, 1) ?? $svg;
        }

        return '<svg'.($class !== '' ? ' class="'.e($class).'"' : '').' aria-hidden="true"><use href="#ls-i-'.e($name).'"></use></svg>';
    }

    private static function symbol(string $name): ?string
    {
        if (! array_key_exists($name, self::$symbols)) {
            $svg = self::rawIcon($name);
            if ($svg === '' || ! preg_match('/<svg\b([^>]*)>(.*)<\/svg>/s', $svg, $m)) {
                self::$symbols[$name] = null;
            } else {
                $attrs = $m[1];
                $viewBox = preg_match('/viewBox="([^"]*)"/', $attrs, $vm) ? $vm[1] : '0 0 24 24';
                // Presentation attributes live on the root in
                // Lucide markup; carry them onto the symbol so
                // the paths inherit them through <use>.
                $keep = trim((string) preg_replace('/\s*(xmlns|width|height|class|viewBox)="[^"]*"/', '', $attrs));
                self::$symbols[$name] = '<symbol id="ls-i-'.e($name).'" viewBox="'.e($viewBox).'"'.($keep !== '' ? ' '.$keep : '').'>'.$m[2].'</symbol>';
            }
        }

        return self::$symbols[$name];
    }

    private static function rawIcon(string $name): string
    {
        if (! isset(self::$rawIcons[$name])) {
            try {
                self::$rawIcons[$name] = Blade::render('<x-lucide-'.e($name).' />');
            } catch (\Throwable) {
                self::$rawIcons[$name] = '';
            }
        }

        return self::$rawIcons[$name];
    }

    /**
     * Client-side active marking: the fragment is shared
     * across pages, so the current page's link is flagged
     * here instead of server-side. Longest path-prefix
     * match wins; a matched child opens its parent group.
     */
    public static function activeScript(): string
    {
        return <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var path = window.location.pathname.replace(/\/+$/, '') || '/';
    var best = null, bestLen = -1;
    document.querySelectorAll('[data-nav-link]').forEach(function (a) {
        var href;
        try { href = new URL(a.href).pathname.replace(/\/+$/, '') || '/'; } catch (e) { return; }
        var match = href === path ? href.length + 10000
            : (href !== '/' && (path + '/').indexOf(href + '/') === 0 ? href.length : -1);
        if (match > bestLen) { bestLen = match; best = a; }
    });
    if (!best) return;
    best.setAttribute('data-active', 'true');
    best.setAttribute('aria-current', 'page');
    var parent = best.closest('[data-nav-parent]');
    if (parent) {
        var toggle = parent.querySelector('[data-nav-toggle]');
        if (toggle && parent.getAttribute('data-state') !== 'open') toggle.click();
    }
});
</script>
HTML;
    }

    /**
     * Lazy loaders (Stage C-1): sidebar group items on first
     * expand, and the command palette's slice index on first
     * open — both used to be rendered into every page.
     * $groupUrl / $indexUrl are the nav-group / nav-index
     * endpoint URLs; the palette container is
     * #ls-palette-slices in the layout.
     */
    public static function lazyScript(string $groupUrl, string $indexUrl): string
    {
        $itemClass = self::PALETTE_ITEM_CLASS;
        $groupUrl = (string) json_encode($groupUrl, JSON_UNESCAPED_SLASHES);
        $indexUrl = (string) json_encode($indexUrl, JSON_UNESCAPED_SLASHES);

        return <<<HTML
<script>
(function () {
    var GROUP_URL = {$groupUrl};
    var INDEX_URL = {$indexUrl};
    var ITEM_CLASS = "{$itemClass}";

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-nav-lazy-toggle]') : null;
        if (!btn) return;
        var wrap = btn.closest('[data-nav-lazy]');
        if (!wrap || wrap.dataset.loaded) return;
        wrap.dataset.loaded = 'loading';
        fetch(GROUP_URL + '?g=' + encodeURIComponent(wrap.getAttribute('data-nav-lazy')), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var ul = wrap.querySelector('[data-nav-lazy-list]');
                if (ul) {
                    ul.innerHTML = html;
                    if (window.Alpine) Alpine.initTree(ul);
                }
                wrap.dataset.loaded = '1';
            })
            .catch(function () { wrap.dataset.loaded = ''; });
    }, true);

    window.__lsLoadPalette = function () {
        var box = document.getElementById('ls-palette-slices');
        if (!box || box.dataset.loaded) return;
        box.dataset.loaded = 'loading';
        fetch(INDEX_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                box.innerHTML = '';
                (data.items || []).forEach(function (it) {
                    var kw = it.title + ' ' + it.url;
                    var a = document.createElement('a');
                    a.href = it.url;
                    a.setAttribute('role', 'option');
                    a.setAttribute('data-slot', 'command-item');
                    a.setAttribute('x-init', 'registerItem(\$el, ' + JSON.stringify(kw) + ', false)');
                    a.setAttribute('x-show', 'matches(' + JSON.stringify(kw) + ')');
                    a.setAttribute(':aria-selected', 'activeId === \$el.id');
                    a.setAttribute(':data-selected', 'activeId === \$el.id');
                    a.setAttribute('@mouseenter', 'activeId = \$el.id');
                    a.className = ITEM_CLASS;
                    a.innerHTML = '<svg class="size-4 mr-2" aria-hidden="true"><use href="#ls-i-' + it.icon + '"></use></svg><span></span><span class="ml-auto text-xs text-muted-foreground font-mono"></span>';
                    a.children[1].textContent = it.title;
                    a.children[2].textContent = it.url;
                    box.appendChild(a);
                    if (window.Alpine) Alpine.initTree(a);
                });
                box.dataset.loaded = '1';
            })
            .catch(function () { box.dataset.loaded = ''; });
    };
})();
</script>
HTML;
    }
}
