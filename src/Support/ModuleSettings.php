<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Feature-module switches (the source-ERP `module_settings`
 * table: module_name × type(admin|employee|client) × status).
 *
 * A slice declares the feature module it belongs to with the
 * manifest key `feature_module`. When the host app carries a
 * module_settings table and the module is deactivated for the
 * current user's type, the slice leaves the navigation and its
 * pages refuse — the switch is behaviour, not decoration.
 *
 * Apps without the table are unaffected, and super-class users
 * (super admin, platform developer) are never restricted: the
 * switches govern the business roles they name.
 */
class ModuleSettings
{
    /**
     * Memoized per REQUEST via the container (never a raw static:
     * in long-lived processes a static outlives the settings it
     * mirrors, and a stale "nothing hidden" would silently
     * disable the switches — the SchemaCache lesson).
     *
     * @var array<string, list<string>>
     */
    private static array $cache = [];

    /**
     * The module_settings "type" for a user, derived from their
     * role names, or null when the switches do not bind them.
     */
    public static function typeForUser(mixed $user): ?string
    {
        if (! $user) {
            return null;
        }

        try {
            $names = [];
            if (method_exists($user, 'roles')) {
                foreach ($user->roles as $role) {
                    $names[] = strtolower((string) ($role->name ?? ''));
                }
            }
            if (isset($user->role) && is_string($user->role)) {
                $names[] = strtolower($user->role);
            }
        } catch (Throwable) {
            return null;
        }

        foreach ($names as $name) {
            if (str_contains($name, 'super') || str_contains($name, 'developer')) {
                return null;
            }
        }
        foreach (['client', 'employee', 'admin'] as $type) {
            foreach ($names as $name) {
                if (str_contains($name, $type)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Module names deactivated for the user's type.
     *
     * @return list<string>
     */
    public static function hiddenModules(mixed $user): array
    {
        $type = self::typeForUser($user);
        if ($type === null) {
            return [];
        }

        $key = $type.':'.(string) ($user->getAuthIdentifier() ?? '');
        $store = self::requestStore();
        if (isset($store[$key])) {
            return $store[$key];
        }

        if (! SchemaCache::hasTable('module_settings')) {
            return self::remember($key, []);
        }

        try {
            $rows = DB::table('module_settings')
                ->where('type', $type)
                ->where('status', '!=', 'active')
                ->pluck('module_name')
                ->all();
        } catch (Throwable) {
            return self::remember($key, []);
        }

        return self::remember($key, array_values(array_map(
            fn ($m) => strtolower(trim((string) $m)),
            $rows
        )));
    }

    /**
     * The per-request memo store: the container instance when an
     * app is booted (rebuilt every request, Octane included), a
     * static only for bare CLI/test contexts without an app.
     *
     * @return array<string, list<string>>
     */
    private static function requestStore(): array
    {
        if (function_exists('app') && app()->bound('laraslice.module_settings')) {
            return app('laraslice.module_settings');
        }

        return self::$cache;
    }

    /** @param list<string> $value @return list<string> */
    private static function remember(string $key, array $value): array
    {
        if (function_exists('app') && app()->bound('config')) {
            $store = app()->bound('laraslice.module_settings') ? app('laraslice.module_settings') : [];
            $store[$key] = $value;
            app()->instance('laraslice.module_settings', $store);
        } else {
            self::$cache[$key] = $value;
        }

        return $value;
    }

    public static function isHiddenFor(mixed $user, ?string $featureModule): bool
    {
        if ($featureModule === null || trim($featureModule) === '') {
            return false;
        }

        return in_array(strtolower(trim($featureModule)), self::hiddenModules($user), true);
    }

    /** Test seam: forget memoized results. */
    public static function flush(): void
    {
        self::$cache = [];
        if (function_exists('app') && app()->bound('laraslice.module_settings')) {
            app()->instance('laraslice.module_settings', []);
        }
    }
}
