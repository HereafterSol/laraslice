<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Route;

/**
 * Navigation structure assembly (Stage C-1).
 *
 * Single source for the shell's navigation data: the starter
 * layout used to build $navMain inline in a @php block, which
 * meant nothing else — the command palette's lazy index, the
 * lazy sidebar-group endpoint — could reuse it. The build is
 * unchanged, only relocated:
 *
 *  - 'slices': the raw navigable slice list (palette source);
 *  - 'main':   grouped items for NavHtml (Workspace first,
 *              domain groups by lowest member order, then
 *              ungrouped, then core slices as Administration);
 *  - 'activeGroup': the domain group whose item or child URL
 *              best matches the current path — the shell ships
 *              that group expanded and every other domain
 *              group as a lazy shell (October-style context
 *              navigation), instead of the whole fleet's markup
 *              on every page.
 */
final class NavStructure
{
    /**
     * @return array{slices: array<int, array<string, mixed>>, main: array<int, array{title: string, items: array<int, array<string, mixed>>}>, activeGroup: ?string}
     */
    public static function build(): array
    {
        $slices = \LaraSlice\Facades\LaraSlice::getNavigableSlices();

        $sliceNavItem = static fn (array $s) => [
            'title' => $s['label'] ?? $s['title'] ?? $s['name'] ?? 'Slice',
            'url' => $s['url'],
            'icon' => self::resolveLucide($s['icon'] ?? 'box'),
            'order' => $s['order'] ?? 10,
            'section' => $s['section'] ?? null,
            'children' => array_map(static fn ($c) => [
                'title' => $c['label'],
                'url' => $c['url'],
            ], $s['children'] ?? []),
        ];

        // Slice Studio links only for users who may open it
        $canUseStudio = Route::has('laraslice.wizard') && auth()->check()
            && \LaraSlice\Core\Security\Access::allows(auth()->user(), 'studio.access');

        $workspaceItems = [
            ['title' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => (Route::has('dashboard') ? route('dashboard') : url('/')), 'children' => []],
        ];
        // My Profile: the signed-in user's own-record hub,
        // resolved by the framework from the slice that declares
        // a profile widget for their audience — present only when
        // the user actually has a record there.
        $ownProfileUrl = auth()->check() ? DashboardUi::ownRecordUrl() : null;
        if ($ownProfileUrl) {
            $workspaceItems[] = ['title' => 'My Profile', 'icon' => 'user', 'url' => $ownProfileUrl, 'children' => []];
        }
        if ($canUseStudio) {
            $workspaceItems[] = ['title' => 'Slice Studio', 'icon' => 'wand-2', 'url' => route('laraslice.wizard'), 'children' => []];
        }
        $navMain = [
            ['title' => 'Workspace', 'items' => $workspaceItems],
        ];

        // Domain slices first, then ungrouped app slices, then
        // LaraSlice's core slices under Administration. A group
        // named after the ungrouped bucket is treated as
        // ungrouped so it never appears twice.
        $navDomains = [];
        $navUngrouped = [];
        $navCore = [];
        foreach ($slices as $s) {
            $grp = $s['group'] ?? null;
            if (! empty($s['core'])) {
                $navCore[] = $sliceNavItem($s);
            } elseif ($grp && $grp !== 'Vertical Slices') {
                $navDomains[$grp][] = $sliceNavItem($s);
            } else {
                $navUngrouped[] = $sliceNavItem($s);
            }
        }
        // Sort domains by lowest order of member slices
        uasort($navDomains, static function ($a, $b) {
            $minA = min(array_map(static fn ($i) => $i['order'] ?? 10, $a));
            $minB = min(array_map(static fn ($i) => $i['order'] ?? 10, $b));

            return $minA <=> $minB;
        });

        foreach ($navDomains as $groupName => $items) {
            $navMain[] = ['title' => $groupName, 'items' => self::withSections($items)];
        }
        if (! empty($navUngrouped)) {
            $navMain[] = ['title' => 'Vertical Slices', 'items' => $navUngrouped];
        }
        if (! empty($navCore)) {
            $navMain[] = ['title' => 'Administration', 'items' => $navCore];
        }

        return [
            'slices' => $slices,
            'main' => $navMain,
            'activeGroup' => self::activeGroup($navMain),
        ];
    }

    /**
     * The group that owns the current page: longest URL-prefix
     * match over item and child URLs (the same rule the
     * client-side active script applies to links). Workspace
     * never counts — it always ships in full.
     *
     * @param  array<int, array{title: string, items: array<int, array<string, mixed>>}>  $navMain
     */
    public static function activeGroup(array $navMain): ?string
    {
        $path = '/'.trim(request()->path(), '/');
        $best = null;
        $bestLen = -1;
        foreach ($navMain as $group) {
            $title = (string) ($group['title'] ?? '');
            if ($title === '' || $title === 'Workspace') {
                continue;
            }
            foreach ($group['items'] ?? [] as $item) {
                $urls = [(string) ($item['url'] ?? '')];
                foreach ($item['children'] ?? [] as $child) {
                    $urls[] = (string) ($child['url'] ?? '');
                }
                foreach ($urls as $url) {
                    $u = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
                    if ($u === '' || $u === '/') {
                        continue;
                    }
                    $len = ($path === $u) ? strlen($u) + 10000
                        : ((str_starts_with($path.'/', $u.'/')) ? strlen($u) : -1);
                    if ($len > $bestLen) {
                        $bestLen = $len;
                        $best = $title;
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Order one domain group's items the way the source
     * systems' workspace sidebars do (Stage E addendum):
     * unsectioned items first, then each declared section
     * as a header marker followed by its items. Sections
     * rank by their lowest member order; items inside a
     * section keep their declared order. A group with no
     * sections passes through untouched.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private static function withSections(array $items): array
    {
        $sections = [];
        foreach ($items as $item) {
            $section = $item['section'] ?? null;
            if (is_string($section) && $section !== '') {
                $sections[$section] = min($sections[$section] ?? PHP_INT_MAX, (int) ($item['order'] ?? 10));
            }
        }
        if ($sections === []) {
            return $items;
        }
        asort($sections);
        $rank = static fn (array $item) => ($item['section'] ?? null) ? (1000 + (array_search($item['section'], array_keys($sections), true) ?: 0)) : 0;
        usort($items, static fn ($a, $b) => [$rank($a), (int) ($a['order'] ?? 10)] <=> [$rank($b), (int) ($b['order'] ?? 10)]);

        $out = [];
        $current = null;
        foreach ($items as $item) {
            $section = $item['section'] ?? null;
            if ($section && $section !== $current) {
                $out[] = ['header' => $section, 'title' => $section, 'url' => '', 'icon' => '', 'children' => []];
                $current = $section;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Palette entries for the lazy command-palette index.
     *
     * @return array<int, array{title: string, url: string, icon: string}>
     */
    public static function paletteItems(): array
    {
        $items = [];
        foreach (\LaraSlice\Facades\LaraSlice::getNavigableSlices(true) as $s) {
            $items[] = [
                'title' => (string) ($s['label'] ?? $s['title'] ?? $s['name'] ?? 'Slice'),
                'url' => (string) ($s['url'] ?? ''),
                'icon' => self::resolveLucide($s['icon'] ?? 'box'),
            ];
        }

        return $items;
    }

    /**
     * Resolve a declared icon name to a Lucide component name,
     * with the legacy alias map the layout used to carry.
     */
    public static function resolveLucide($iconName): string
    {
        static $factory = null;
        if ($factory === null) {
            $factory = app(\BladeUI\Icons\Factory::class);
        }
        $map = [
            'basket' => 'shopping-bag',
            'shop' => 'shopping-bag',
            'cart' => 'shopping-cart',
            'bag' => 'shopping-bag',
            'box' => 'box',
            'cube' => 'box',
            'cube-2' => 'boxes',
            'cube-3' => 'box',
            'people' => 'users',
            'user' => 'user',
            'security-user' => 'shield-check',
            'shield' => 'shield',
            'shield-check' => 'shield-check',
            'lock' => 'lock',
            'key' => 'key',
            'setting-2' => 'settings',
            'settings' => 'settings',
            'document' => 'file-text',
            'file' => 'file-text',
            'chart-pie-simple' => 'pie-chart',
            'chart' => 'bar-chart-3',
            'element-11' => 'layout-dashboard',
            'dashboard' => 'layout-dashboard',
            'wallet' => 'wallet',
            'notification-status' => 'bell',
            'bell' => 'bell',
            'message-text-2' => 'message-square',
            'chat' => 'message-square',
            'wand' => 'wand-2',
            'code' => 'code',
            'database' => 'database',
            'tag' => 'tag',
            'layers' => 'layers',
            'package' => 'package',
        ];
        $clean = strtolower(trim((string) $iconName));
        $target = $map[$clean] ?? (str_starts_with($clean, 'lucide-') ? substr($clean, 7) : ($clean ?: 'box'));
        try {
            $factory->svg('lucide-'.$target);

            return $target;
        } catch (\Throwable $e) {
            return 'box';
        }
    }
}
