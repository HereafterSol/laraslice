<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Security\Access;

/**
 * Declared record actions (L3): a slice's Heart may declare
 * record-level workflow actions beyond CRUD under `ui.actions`:
 *
 *   ui:
 *     actions:
 *       - {name: duplicate, label: Duplicate, type: duplicate}
 *       - {name: archive, label: Archive, type: transition,
 *          field: status, value: archived, confirm: "Archive?"}
 *
 * Types in v1: `duplicate` (copy the record + repeater children;
 * gated on the slice's create permission) and `transition`
 * (set one declared column to one declared value; gated on
 * update). Rows expose the actions through the directory
 * payload (urlsFor); a single framework endpoint executes them
 * (RecordActionController), so existing slices adopt actions by
 * declaration alone — no route regeneration.
 */
final class RecordActions
{
    /**
     * Normalize a manifest's declared actions.
     *
     * @param  array<string, mixed>  $raw  raw manifest data
     * @return list<array<string, mixed>>
     */
    public static function declaredActions(array $raw): array
    {
        $out = [];
        foreach (($raw['ui']['actions'] ?? []) as $entry) {
            if (! is_array($entry) || empty($entry['name']) || empty($entry['type'])) {
                continue;
            }
            $type = (string) $entry['type'];
            if ($type === 'transition' && (empty($entry['field']) || ! array_key_exists('value', $entry))) {
                continue;
            }
            if (! in_array($type, ['duplicate', 'transition'], true)) {
                continue;
            }
            $out[] = [
                'name' => (string) $entry['name'],
                'label' => (string) ($entry['label'] ?? Str::headline((string) $entry['name'])),
                'type' => $type,
                'field' => isset($entry['field']) ? (string) $entry['field'] : null,
                'value' => $entry['value'] ?? null,
                'confirm' => isset($entry['confirm']) ? (string) $entry['confirm'] : null,
                'icon' => (string) ($entry['icon'] ?? ($type === 'duplicate' ? 'copy' : 'arrow-right')),
            ];
        }

        return $out;
    }

    /**
     * The permission base for a slice, mirroring the base web
     * controller: the Heart's first declared permission base,
     * else the singular of the route prefix's last segment.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function permissionBase(array $raw, string $routePrefix): string
    {
        foreach (($raw['permissions'] ?? []) as $perm) {
            if (is_string($perm) && str_contains($perm, '.')) {
                return substr($perm, 0, (int) strpos($perm, '.'));
            }
        }
        $prefix = rtrim($routePrefix, '.');
        if (str_contains($prefix, '.')) {
            $parts = explode('.', $prefix);
            $prefix = (string) end($parts);
        }

        return Str::snake(Str::singular($prefix));
    }

    /**
     * Resolve the slice that owns a route prefix (post-R1 the
     * prefix is unique per slice): via its index route's
     * controller namespace matched against discovered slices.
     *
     * @return array{manifest: SliceManifest, raw: array<string, mixed>}|null
     */
    public static function sliceForRoutePrefix(string $routePrefix): ?array
    {
        $manager = app(SliceManager::class);
        $manager->discover();
        $route = Route::getRoutes()->getByName($routePrefix.'.index');
        if ($route === null) {
            return null;
        }
        $action = (string) ($route->getActionName() ?? '');
        $controller = str_contains($action, '@') ? strstr($action, '@', true) : $action;
        $namespace = ltrim(Str::beforeLast($controller, '\\Controllers\\'), '\\');
        if ($namespace === $controller) {
            return null;
        }
        foreach ($manager->discoveredSlices() as $manifest) {
            if ($manifest->getNamespace() === $namespace) {
                return ['manifest' => $manifest, 'raw' => ManifestRepository::readFromSliceDir($manifest->path)];
            }
        }

        return null;
    }

    /**
     * Row payload for the directory: the declared actions the
     * current user may run, with execution URLs.
     *
     * @return list<array<string, mixed>>
     */
    public static function urlsFor(string $routePrefix, string|int $id): array
    {
        if (! Route::has('laraslice.record_action')) {
            return [];
        }
        $resolved = self::sliceForRoutePrefix($routePrefix);
        if ($resolved === null) {
            return [];
        }
        $declared = self::declaredActions($resolved['raw']);
        if ($declared === []) {
            return [];
        }
        $user = auth()->user();
        if ($user === null) {
            return [];
        }
        $base = self::permissionBase($resolved['raw'], $routePrefix);
        $plural = Str::plural($base);
        $out = [];
        foreach ($declared as $action) {
            $verb = $action['type'] === 'duplicate' ? 'create' : 'update';
            $candidates = ["{$base}.{$verb}", "{$plural}.{$verb}", "{$base}.*", "{$plural}.*"];
            if (! Access::allows($user, $candidates)) {
                continue;
            }
            $out[] = [
                'name' => $action['name'],
                'label' => $action['label'],
                'icon' => $action['icon'],
                'confirm' => $action['confirm'],
                'url' => route('laraslice.record_action', ['prefix' => $routePrefix, 'id' => $id, 'action' => $action['name']]),
            ];
        }

        return $out;
    }

    /**
     * Execute one declared action. Returns [status, message, newId].
     *
     * @return array{status: int, message: string, new_id: int|string|null}
     */
    public static function execute(string $routePrefix, string|int $id, string $actionName, mixed $user): array
    {
        $resolved = self::sliceForRoutePrefix($routePrefix);
        if ($resolved === null) {
            return ['status' => 404, 'message' => 'Unknown slice.', 'new_id' => null];
        }
        $action = null;
        foreach (self::declaredActions($resolved['raw']) as $candidate) {
            if ($candidate['name'] === $actionName) {
                $action = $candidate;
                break;
            }
        }
        if ($action === null) {
            return ['status' => 404, 'message' => 'Action not declared for this slice.', 'new_id' => null];
        }
        $base = self::permissionBase($resolved['raw'], $routePrefix);
        $plural = Str::plural($base);
        $verb = $action['type'] === 'duplicate' ? 'create' : 'update';
        if ($user === null || ! Access::allows($user, ["{$base}.{$verb}", "{$plural}.{$verb}", "{$base}.*", "{$plural}.*"])) {
            return ['status' => 403, 'message' => 'Access denied for this action.', 'new_id' => null];
        }
        $service = self::serviceFor($resolved['manifest']);
        if ($service === null) {
            return ['status' => 500, 'message' => 'Slice service not resolvable.', 'new_id' => null];
        }
        if ($action['type'] === 'duplicate') {
            $copy = $service->duplicateRecord($id);

            return $copy === null
                ? ['status' => 404, 'message' => 'Record not found.', 'new_id' => null]
                : ['status' => 200, 'message' => $action['label'].' complete.', 'new_id' => $copy->getKey()];
        }
        $ok = $service->transitionRecord($id, (string) $action['field'], $action['value']);

        return $ok
            ? ['status' => 200, 'message' => $action['label'].' complete.', 'new_id' => null]
            : ['status' => 422, 'message' => 'Transition could not be applied.', 'new_id' => null];
    }

    private static function serviceFor(SliceManifest $manifest): ?BaseSliceService
    {
        $namespace = $manifest->getNamespace();
        $singular = Str::studly(Str::singular($manifest->name));
        $candidates = ["{$namespace}\\Services\\{$singular}SliceService"];
        $servicesPath = $manifest->getServicesPath();
        if ($servicesPath !== null) {
            foreach (glob($servicesPath.'/*SliceService.php') ?: [] as $file) {
                $candidates[] = $namespace.'\\Services\\'.basename($file, '.php');
            }
        }
        foreach (array_unique($candidates) as $class) {
            if (class_exists($class)) {
                $service = app($class);
                if ($service instanceof BaseSliceService) {
                    return $service;
                }
            }
        }

        return null;
    }

    /**
     * Execute a native bulk action (L4) for a slice resolved
     * by its route prefix. v1 supports 'delete' (the
     * directory's declared bulk handle), gated on the
     * slice's delete permission. Returns [status, message,
     * affected].
     *
     * @param  array<int, mixed>  $ids
     * @return array{0: int, 1: string, 2: int}
     */
    public static function executeBulk(string $routePrefix, string $action, array $ids, mixed $user): array
    {
        if ($action !== 'delete') {
            return [404, 'Unknown bulk action.', 0];
        }
        $resolved = self::sliceForRoutePrefix($routePrefix);
        if ($resolved === null) {
            return [404, 'Unknown slice.', 0];
        }
        $ids = array_values(array_filter($ids, static fn ($id) => is_int($id) || (is_string($id) && $id !== '')));
        if ($ids === []) {
            return [422, 'No records selected.', 0];
        }
        $base = self::permissionBase($resolved['raw'], $routePrefix);
        $plural = Str::plural($base);
        $allowed = false;
        foreach (["{$base}.delete", "{$plural}.delete", "{$base}.*", "{$plural}.*"] as $candidate) {
            if (Access::allows($user, $candidate)) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            return [403, 'You are not allowed to delete these records.', 0];
        }
        $service = self::serviceFor($resolved['manifest']);
        if ($service === null) {
            return [500, 'The slice service could not be resolved.', 0];
        }
        $deleted = $service->bulkDelete($ids);

        return [200, "Deleted {$deleted} record(s).", $deleted];
    }
}
