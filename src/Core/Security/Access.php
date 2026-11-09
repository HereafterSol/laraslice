<?php

namespace LaraSlice\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for LaraSlice super-admin, super-dev and permission checks.
 *
 * Super-admin status comes only from holding the `super-admin` role slug.
 * Developer status comes only from holding the `super-dev` role slug.
 * There is deliberately no email- or id-based shortcut.
 *
 * Personas:
 * - super-dev    → Platform developer. Whole-system access incl. all studios.
 *                  Immune to legacy data ports (ETL never wipes auth tables).
 * - super-admin  → Business administrator. Whole-system access via permissions.
 * - end user     → Scoped access via assigned roles/permissions only.
 */
final class Access
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

    public const SUPER_DEV_ROLE = 'super-dev';

    public static function isSuperAdmin(mixed $user): bool
    {
        if (! is_object($user)) {
            return false;
        }

        if (method_exists($user, 'hasRole')) {
            return (bool) $user->hasRole(self::SUPER_ADMIN_ROLE);
        }

        if (method_exists($user, 'isSuperAdmin')) {
            return (bool) $user->isSuperAdmin();
        }

        // Host user models without the HasSlicePermissions trait
        if (! isset($user->id)) {
            return false;
        }

        try {
            if (! Schema::hasTable('role_user') || ! Schema::hasTable('roles')) {
                return false;
            }

            return DB::table('role_user')
                ->join('roles', 'role_user.role_id', '=', 'roles.id')
                ->where('role_user.user_id', $user->id)
                ->where('roles.slug', self::SUPER_ADMIN_ROLE)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isSuperDev(mixed $user): bool
    {
        if (! is_object($user)) {
            return false;
        }

        if (method_exists($user, 'hasRole')) {
            return (bool) $user->hasRole(self::SUPER_DEV_ROLE);
        }

        if (method_exists($user, 'isSuperDev')) {
            return (bool) $user->isSuperDev();
        }

        // Host user models without the HasSlicePermissions trait
        if (! isset($user->id)) {
            return false;
        }

        try {
            if (! Schema::hasTable('role_user') || ! Schema::hasTable('roles')) {
                return false;
            }

            return DB::table('role_user')
                ->join('roles', 'role_user.role_id', '=', 'roles.id')
                ->where('role_user.user_id', $user->id)
                ->where('roles.slug', self::SUPER_DEV_ROLE)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True when the user is a super-dev, super-admin, or holds at least one of the abilities.
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function allows(mixed $user, string|array $abilities): bool
    {
        if (! is_object($user)) {
            return false;
        }

        if (self::isSuperDev($user)) {
            return true;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        foreach ((array) $abilities as $ability) {
            // Per-person overrides (PermissionOverrides): a
            // revoke wins over the club grant for this ability;
            // a grant adds to it.
            if (PermissionOverrides::revokes($user, (string) $ability)) {
                continue;
            }
            if (PermissionOverrides::grants($user, (string) $ability)) {
                return true;
            }
            if (method_exists($user, 'hasPermission')) {
                if ($user->hasPermission($ability)) {
                    return true;
                }
            } elseif (method_exists($user, 'can') && $user->can($ability)) {
                // Host user models without the trait fall back to their own Gate definitions
                return true;
            }
        }

        return false;
    }

    /**
     * The scope layer (Option B): how much of a permission's
     * records the user may reach — 'all', 'both', 'owned',
     * 'added' or 'none'. Mirrors the source systems' model:
     * every grant row carries a permission type, resolved
     * BY NAME (type ids differ across installs).
     *
     * Widest scope wins across the user's roles; 'added'
     * from one role and 'owned' from another merge to
     * 'both'. A grant row with no type (NULL, or the scope
     * schema not yet migrated) is a legacy grant and counts
     * as 'all', so pre-scope installs behave as before.
     * Per-person overrides: a revoke forces 'none', a grant
     * confers 'all'. Super roles always resolve to 'all'.
     */
    public static function scopeFor(mixed $user, string $ability): string
    {
        if (! is_object($user) || ! isset($user->id)) {
            return 'none';
        }

        if (self::isSuperDev($user) || self::isSuperAdmin($user)) {
            return 'all';
        }

        try {
            if (PermissionOverrides::revokes($user, $ability)) {
                return 'none';
            }
            if (PermissionOverrides::grants($user, $ability)) {
                return 'all';
            }

            // Per-user permission types (the employee hub's
            // Permissions tab): a customised value for this
            // person wins over their roles' grants — the
            // source systems' model, where the per-user row
            // is the operative grant for that permission. The
            // read degrades to "no customisation" when its
            // store is unavailable (UserPermissionTypes).
            $customised = UserPermissionTypes::typeForSlug((int) $user->id, $ability);
            if ($customised !== null) {
                return $customised;
            }

            if (! Schema::hasTable('role_user') || ! Schema::hasTable('permission_role') || ! Schema::hasTable('permissions')) {
                return self::allows($user, $ability) ? 'all' : 'none';
            }

            $hasScope = Schema::hasTable('permission_types')
                && Schema::hasColumn('permission_role', 'permission_type_id');

            $query = DB::table('permission_role')
                ->join('role_user', 'role_user.role_id', '=', 'permission_role.role_id')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('role_user.user_id', $user->id)
                ->where('permissions.slug', $ability);

            if ($hasScope) {
                $rows = $query
                    ->leftJoin('permission_types', 'permission_types.id', '=', 'permission_role.permission_type_id')
                    ->pluck('permission_types.name');
            } else {
                $rows = $query->pluck('permission_role.permission_id')
                    ->map(fn () => null);
            }

            $scope = 'none';
            foreach ($rows as $typeName) {
                $grantScope = $typeName === null ? 'all' : (string) $typeName;
                $scope = self::widerScope($scope, $grantScope);
            }

            return $scope;
        } catch (\Throwable) {
            return self::allows($user, $ability) ? 'all' : 'none';
        }
    }

    /**
     * The wider of two scopes. Rank: none < added = owned
     * < both < all; added + owned widen to both.
     */
    public static function widerScope(string $a, string $b): string
    {
        $rank = ['none' => 0, 'added' => 1, 'owned' => 1, 'both' => 2, 'all' => 3];
        $ra = $rank[$a] ?? 0;
        $rb = $rank[$b] ?? 0;
        if ($ra !== $rb) {
            return $ra > $rb ? $a : $b;
        }

        return ($a === 'added' && $b === 'owned') || ($a === 'owned' && $b === 'added')
            ? 'both'
            : $a;
    }
}
