<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Security\Access;

/**
 * L5 chrome: the topbar quick-create menu. Items are
 * derived from the app itself — every nav-visible slice
 * whose create route exists and whose create permission
 * the current user holds. Labels honour the Heart's
 * declared create_label (E5/L2). Nothing is hardcoded
 * per app.
 */
final class QuickCreate
{
    /**
     * @return array<int, array{label: string, url: string, icon: string}>
     */
    public static function items(mixed $user, int $limit = 8): array
    {
        if ($user === null) {
            return [];
        }
        $manager = app(SliceManager::class);
        $manager->discover();

        // Route prefix per controller namespace (create routes).
        $createByNamespace = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) ($route->getName() ?? '');
            if (! str_ends_with($name, '.create')) {
                continue;
            }
            $action = (string) ($route->getActionName() ?? '');
            $controller = str_contains($action, '@') ? strstr($action, '@', true) : $action;
            $namespace = Str::beforeLast($controller, '\\Controllers\\');
            if ($namespace !== $controller) {
                $createByNamespace[$namespace] = substr($name, 0, -7);
            }
        }

        $items = [];
        $seen = [];
        foreach ($manager->discoveredSlices() as $manifest) {
            $namespace = $manifest->getNamespace();
            if (! isset($createByNamespace[$namespace]) || isset($seen[$namespace])) {
                continue;
            }
            $seen[$namespace] = true;
            $raw = ManifestRepository::readFromSliceDir($manifest->path);
            $nav = $raw['navigation'] ?? [];
            if (($nav['visible'] ?? true) === false) {
                continue;
            }
            $prefix = $createByNamespace[$namespace];
            $base = RecordActions::permissionBase($raw, $prefix);
            $plural = Str::plural($base);
            $allowed = false;
            foreach (["{$base}.create", "{$plural}.create", "{$base}.*", "{$plural}.*"] as $candidate) {
                if (Access::allows($user, $candidate)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                continue;
            }
            $label = $raw['ui']['directory']['create_label']
                ?? ('Add '.Str::headline(Str::singular($manifest->name)));
            $items[] = [
                'label' => $label,
                'url' => route($prefix.'.create'),
                'icon' => (string) ($nav['icon'] ?? 'plus'),
                'order' => (int) ($nav['order'] ?? 50),
            ];
        }
        usort($items, static fn ($a, $b) => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);

        return array_slice($items, 0, $limit);
    }
}
