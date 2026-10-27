<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * L5 chrome: the topbar global timer. Finds the signed-in
 * user's currently running timer across every slice that
 * declares the P11 timer pattern (ui.show.timer), so the
 * topbar can show what is being timed and stop it in one
 * click — the per-record pattern already enforces one
 * active timer per user globally; this surfaces it.
 */
final class GlobalTimer
{
    /**
     * @return array{slice: string, label: string, parent_url: ?string, stop_url: string, started_at: string}|null
     */
    public static function running(mixed $user): ?array
    {
        if ($user === null || ! isset($user->id)) {
            return null;
        }
        $manager = app(SliceManager::class);
        $manager->discover();

        // Route prefixes per controller namespace.
        $prefixByNamespace = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) ($route->getName() ?? '');
            if (str_ends_with($name, '.timer_stop')) {
                $action = (string) ($route->getActionName() ?? '');
                $controller = str_contains($action, '@') ? strstr($action, '@', true) : $action;
                $namespace = ltrim(Str::beforeLast($controller, '\\Controllers\\'), '\\');
                if ($namespace !== $controller) {
                    $prefixByNamespace[$namespace] = substr($name, 0, -11);
                }
            }
        }

        foreach ($manager->discoveredSlices() as $manifest) {
            $namespace = $manifest->getNamespace();
            if (! isset($prefixByNamespace[$namespace])) {
                continue;
            }
            $raw = ManifestRepository::readFromSliceDir($manifest->path);
            $decl = DirectoryUi::timerFromManifest($raw);
            if ($decl === null) {
                continue;
            }
            try {
                $modelClass = $manifest->getModelClass();
                if ($modelClass === null || ! method_exists($modelClass, $decl['relation'])) {
                    continue;
                }
                $parent = new $modelClass;
                $relation = $parent->{$decl['relation']}();
                $table = $relation->getRelated()->getTable();
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $decl['user_field']) || ! Schema::hasColumn($table, $decl['end_field'])) {
                    continue;
                }
                $row = DB::table($table)
                    ->where($decl['user_field'], $user->id)
                    ->whereNull($decl['end_field'])
                    ->orderByDesc($decl['start_field'])
                    ->first();
                if ($row === null) {
                    continue;
                }
                $foreignKey = $relation->getForeignKeyName();
                $parentId = $row->{$foreignKey} ?? null;
                if ($parentId === null) {
                    continue;
                }
                $parentRecord = $parent->newQuery()->find($parentId);
                $label = null;
                foreach (['heading', 'title', 'name', 'subject'] as $column) {
                    if ($parentRecord !== null && isset($parentRecord->{$column})) {
                        $label = (string) $parentRecord->{$column};
                        break;
                    }
                }
                $prefix = $prefixByNamespace[$namespace];

                $startedAt = (string) ($row->{$decl['start_field']} ?? '');
                try {
                    // ISO-8601 with offset: the topbar ticker must
                    // compute elapsed in the viewer's timezone
                    // against an absolute instant, not a naive
                    // wall-clock string.
                    $startedAt = \Carbon\Carbon::parse($startedAt)->toIso8601String();
                } catch (\Throwable) {
                    // keep the raw value; the ticker degrades gracefully
                }

                return [
                    'slice' => $manifest->name,
                    'label' => $label ?? ('#'.$parentId),
                    'parent_url' => Route::has($prefix.'.show') ? route($prefix.'.show', $parentId) : null,
                    'stop_url' => route($prefix.'.timer_stop', $parentId),
                    'started_at' => $startedAt,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
