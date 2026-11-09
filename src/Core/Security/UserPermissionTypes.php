<?php

declare(strict_types=1);

namespace LaraSlice\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per-user permission TYPES — the scope layer's per-person
 * dimension, mirroring the source systems' model: a user's
 * effective type for a module permission normally comes from
 * their roles' grants (permission_role), but an administrator
 * may customise it per person from the employee hub's
 * Permissions tab. A customised value lives in
 * `user_permission_types` (user × permission → type) and wins
 * over the role-derived value in Access::scopeFor; resetting
 * deletes the user's rows and the role values apply again.
 * The users.customised_permissions flag records that a user
 * carries any customisation (the source's own flag).
 *
 * Reads are best-effort: a missing table (pre-migration
 * installs) simply means "no customisations", so the layer
 * never breaks an install that has not migrated yet.
 */
final class UserPermissionTypes
{
    public static function available(): bool
    {
        try {
            return Schema::hasTable('user_permission_types')
                && Schema::hasTable('permissions')
                && Schema::hasTable('permission_types');
        } catch (\Throwable) {
            return false;
        }
    }

    /** The customised type NAME for one user + permission slug — Access's resolution entry point. */
    public static function typeForSlug(int $userId, string $slug): ?string
    {
        try {
            if (! Schema::hasTable('permissions')) {
                return null;
            }
            $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');

            return $permissionId === null ? null : self::typeFor($userId, (int) $permissionId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The customised type NAME for one user + permission, or null when not customised. */
    public static function typeFor(int $userId, int $permissionId): ?string
    {
        if (! self::available()) {
            return null;
        }
        try {
            $name = DB::table('user_permission_types as upt')
                ->join('permission_types as pt', 'pt.id', '=', 'upt.permission_type_id')
                ->where('upt.user_id', $userId)
                ->where('upt.permission_id', $permissionId)
                ->value('pt.name');

            return $name === null ? null : (string) $name;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function customised(int $userId): bool
    {
        try {
            if (Schema::hasColumn('users', 'customised_permissions')
                && (int) (DB::table('users')->where('id', $userId)->value('customised_permissions') ?? 0) === 1) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to the rows check.
        }

        if (! self::available()) {
            return false;
        }
        try {
            return DB::table('user_permission_types')->where('user_id', $userId)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Set (or replace) one user's type for one permission.
     * The type must be in the permission's declared allowed
     * set when one is recorded — the source's own constraint.
     */
    public static function setType(int $userId, int $permissionId, int $typeId): bool
    {
        if (! self::available()) {
            return false;
        }
        try {
            $permission = DB::table('permissions')->where('id', $permissionId)->first(['id', 'allowed_permissions']);
            if ($permission === null) {
                return false;
            }
            $allowed = self::allowedTypes($permission->allowed_permissions ?? null);
            if ($allowed !== [] && ! in_array($typeId, array_map(static fn (array $t): int => $t['id'], $allowed), true)) {
                return false;
            }
            if (! DB::table('permission_types')->where('id', $typeId)->exists()) {
                return false;
            }

            DB::table('user_permission_types')->updateOrInsert(
                ['user_id' => $userId, 'permission_id' => $permissionId],
                ['permission_type_id' => $typeId, 'updated_at' => now(), 'created_at' => now()],
            );
            self::setCustomisedFlag($userId, true);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Remove every customisation for the user; role values apply again (the source's Reset). */
    public static function reset(int $userId): void
    {
        if (self::available()) {
            try {
                DB::table('user_permission_types')->where('user_id', $userId)->delete();
            } catch (\Throwable) {
                // Nothing to reset.
            }
        }
        self::setCustomisedFlag($userId, false);
    }

    /**
     * The Permissions-tab matrix for one user: every module
     * with its main permissions (is_custom = 0, in source id
     * order) and its custom permissions (is_custom = 1), each
     * carrying the allowed type set and the user's EFFECTIVE
     * type (customised value, else the widest role grant, else
     * 'none') — exactly what the source tab presents.
     *
     * @return array{user_id: int, customised: bool, admin_role: bool, roles: list<string>, modules: list<array<string, mixed>>}|null
     */
    public static function matrixForUser(int $userId): ?array
    {
        try {
            if (! Schema::hasTable('modules') || ! Schema::hasTable('permissions')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        try {
            $roles = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $userId)
                ->pluck('roles.name')
                ->map(static fn ($n): string => (string) $n)
                ->all();

            $roleTypes = self::roleTypesFor($userId);
            $overrides = self::available()
                ? DB::table('user_permission_types as upt')
                    ->join('permission_types as pt', 'pt.id', '=', 'upt.permission_type_id')
                    ->where('upt.user_id', $userId)
                    ->pluck('pt.name', 'upt.permission_id')
                    ->map(static fn ($n): string => (string) $n)
                    ->all()
                : [];

            $permissions = DB::table('permissions')
                ->whereNotNull('module_id')
                ->orderBy('id')
                ->get(['id', 'name', 'display_name', 'module_id', 'is_custom', 'allowed_permissions']);

            $byModule = [];
            foreach ($permissions as $permission) {
                $effective = $overrides[(int) $permission->id]
                    ?? $roleTypes[(int) $permission->id]
                    ?? 'none';
                $byModule[(int) $permission->module_id][(int) $permission->is_custom === 1 ? 'custom' : 'main'][] = [
                    'id' => (int) $permission->id,
                    'name' => (string) $permission->name,
                    'label' => (string) ($permission->display_name ?: Str::headline((string) $permission->name)),
                    'allowed' => self::allowedTypes($permission->allowed_permissions ?? null),
                    'effective' => $effective,
                    'customised' => isset($overrides[(int) $permission->id]),
                ];
            }

            $modules = [];
            foreach (DB::table('modules')->orderBy('id')->get(['id', 'module_name']) as $module) {
                // A module's bucket carries only the splits it
                // has permissions in — normalize both keys.
                $entry = ($byModule[(int) $module->id] ?? []) + ['main' => [], 'custom' => []];
                if ($entry['main'] === [] && $entry['custom'] === []) {
                    continue;
                }
                $modules[] = [
                    'id' => (int) $module->id,
                    'name' => (string) $module->module_name,
                    'label' => Str::headline((string) $module->module_name),
                    'main' => $entry['main'],
                    'custom' => $entry['custom'],
                ];
            }

            // The framework-native permission family carries
            // no module assignment: its axis is the permission
            // `group` (one per slice surface, View / Create /
            // Edit / Delete shaped). Both families are the
            // host's real permission data — a person's set is
            // only honest with both on the matrix.
            if (Schema::hasColumn('permissions', 'group')) {
                $columns = ['id', 'name', 'display_name', 'group'];
                if (Schema::hasColumn('permissions', 'allowed_permissions')) {
                    $columns[] = 'allowed_permissions';
                }
                $grouped = [];
                $native = DB::table('permissions')
                    ->whereNull('module_id')
                    ->whereNotNull('group')
                    ->where('group', '<>', '')
                    ->orderBy('group')
                    ->orderBy('id')
                    ->get($columns);
                foreach ($native as $permission) {
                    $grouped[(string) $permission->group][] = [
                        'id' => (int) $permission->id,
                        'name' => (string) $permission->name,
                        'label' => (string) ($permission->display_name ?: Str::headline((string) $permission->name)),
                        'allowed' => self::allowedTypes($permission->allowed_permissions ?? null),
                        'effective' => $overrides[(int) $permission->id]
                            ?? $roleTypes[(int) $permission->id]
                            ?? 'none',
                        'customised' => isset($overrides[(int) $permission->id]),
                    ];
                }
                foreach ($grouped as $group => $entries) {
                    $modules[] = [
                        'id' => 'group-'.Str::slug($group),
                        'name' => $group,
                        'label' => Str::headline($group),
                        'main' => $entries,
                        'custom' => [],
                    ];
                }
            }

            return [
                'user_id' => $userId,
                'customised' => self::customised($userId),
                'admin_role' => collect($roles)->contains(
                    static fn ($n): bool => strtolower((string) $n) === 'admin'
                ),
                'roles' => $roles,
                'modules' => $modules,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, string> permission_id => widest role-granted type name */
    private static function roleTypesFor(int $userId): array
    {
        try {
            if (! Schema::hasTable('permission_role') || ! Schema::hasColumn('permission_role', 'permission_type_id')) {
                return [];
            }
            $rows = DB::table('permission_role')
                ->join('role_user', 'role_user.role_id', '=', 'permission_role.role_id')
                ->leftJoin('permission_types', 'permission_types.id', '=', 'permission_role.permission_type_id')
                ->where('role_user.user_id', $userId)
                ->get(['permission_role.permission_id', 'permission_types.name']);

            $out = [];
            foreach ($rows as $row) {
                // A typeless grant row is a legacy grant: 'all'.
                $name = $row->name === null ? 'all' : (string) $row->name;
                $existing = $out[(int) $row->permission_id] ?? 'none';
                $out[(int) $row->permission_id] = Access::widerScope($existing, $name);
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The permission's allowed type set from its recorded
     * allowed_permissions JSON ({"all":4, "none":5} — name => id,
     * in the source's own order). No recorded set → every type.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function allowedTypes(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $out = [];
        if (is_array($decoded) && $decoded !== []) {
            foreach ($decoded as $name => $id) {
                if (is_numeric($id)) {
                    $out[] = ['id' => (int) $id, 'name' => (string) $name];
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        try {
            foreach (DB::table('permission_types')->orderBy('id')->get(['id', 'name']) as $type) {
                $out[] = ['id' => (int) $type->id, 'name' => (string) $type->name];
            }
        } catch (\Throwable) {
            // No types table: no constraint can be expressed.
        }

        return $out;
    }

    private static function setCustomisedFlag(int $userId, bool $value): void
    {
        try {
            if (Schema::hasColumn('users', 'customised_permissions')) {
                DB::table('users')->where('id', $userId)->update(['customised_permissions' => $value ? 1 : 0]);
            }
        } catch (\Throwable) {
            // The flag is a display convenience; rows are truth.
        }
    }
}
