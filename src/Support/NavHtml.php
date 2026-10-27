<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;

/**
 * Sidebar navigation rendering (backlog B3).
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
 *  - icons are rendered once per unique name per build,
 *    not once per item;
 *  - NO per-item tooltip component: the collapsed rail
 *    falls back to the native title tooltip;
 *  - NO server-baked active state: a small script marks
 *    the current page's link client-side
 *    (see activeScript()), which is what makes the whole
 *    fragment cacheable;
 *  - the fragment is cached under a key derived from the
 *    nav STRUCTURE itself (titles + urls + icons): any
 *    registry or permission change alters the structure
 *    and therefore the key — no invalidation bookkeeping.
 */
final class NavHtml
{
    private const BUTTON_CLASS = 'peer/menu-button flex w-full items-center gap-2 overflow-hidden rounded-md p-2 text-start text-sm outline-none ring-sidebar-ring transition-[width,height,padding] hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 active:bg-sidebar-accent active:text-sidebar-accent-foreground disabled:pointer-events-none disabled:opacity-50 group-has-[[data-sidebar=menu-action]]/menu-item:pe-8 aria-disabled:pointer-events-none aria-disabled:opacity-50 data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground group-data-[collapsible=icon]:size-8! [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0 h-8 group-data-[collapsible=icon]:p-2!';

    private const SUB_BUTTON_CLASS = 'text-sidebar-foreground ring-sidebar-ring hover:bg-sidebar-accent hover:text-sidebar-accent-foreground active:bg-sidebar-accent active:text-sidebar-accent-foreground [&>svg]:text-sidebar-accent-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground flex h-7 min-w-0 -translate-x-px items-center gap-2 overflow-hidden rounded-md px-2 outline-none focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0 group-data-[collapsible=icon]:hidden text-sm';

    /** @var array<string, string> */
    private static array $iconCache = [];

    /**
     * Render the nav groups, fragment-cached by structure.
     *
     * @param  array<int, array{title: string, items: array<int, array<string, mixed>>}>  $navMain
     */
    public static function cached(array $navMain): string
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
        $key = 'laraslice:navhtml:'.md5((string) json_encode($structure));
        $ttl = now()->addHours(24);

        return Cache::remember($key, $ttl, static fn () => self::render($navMain));
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

        return $html;
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
            .'<div data-slot="sidebar-group" data-sidebar="group" class="relative flex w-full min-w-0 flex-col p-2">'
            .'<div class="group/label text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground ring-sidebar-ring flex h-8 w-full shrink-0 items-center justify-between rounded-md px-2 text-sm font-medium outline-none transition-[margin,opacity] duration-200 ease-linear">'
            .$head
            .'<button type="button" @click="open = !open" class="p-1 rounded hover:bg-muted/60 transition cursor-pointer shrink-0 ml-1" aria-label="Toggle '.e($title).'">'.$chevron.'</button>'
            .'</div>'
            .'<div data-slot="collapsible-content" x-show="open" x-collapse :data-state="open ? \'open\' : \'closed\'">'
            .'<div data-slot="sidebar-group-content" data-sidebar="group-content" class="w-full text-sm relative">'
            .'<ul data-slot="sidebar-menu" data-sidebar="menu" class="flex w-full min-w-0 flex-col gap-1">'
            .$lis
            .'</ul></div></div></div></div>';
    }

    private static function item(array $item): string
    {
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
            .'<ul data-slot="sidebar-menu-sub" data-sidebar="menu-sub" class="border-sidebar-border mx-3.5 flex min-w-0 translate-x-px flex-col gap-1 border-s px-2.5 py-0.5 group-data-[collapsible=icon]:hidden">'
            .$subs
            .'</ul></div></div></li>';
    }

    private static function icon(string $name, string $class = ''): string
    {
        $key = $name.'|'.$class;
        if (! isset(self::$iconCache[$key])) {
            try {
                $svg = Blade::render('<x-lucide-'.e($name).($class !== '' ? ' class="'.e($class).'"' : '').' />');
            } catch (\Throwable) {
                $svg = '';
            }
            self::$iconCache[$key] = $svg;
        }

        return self::$iconCache[$key];
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
}
