<?php

namespace LaraSlice\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for LaraSlice super-admin and permission checks.
 *
 * Super-admin status comes only from holding the `super-admin` role slug.
 * There is deliberately no email- or id-based shortcut.
 */
final class Access
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

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

    /**
     * True when the user is a super-admin or holds at least one of the abilities.
     *
     * @param  string|array<int, string>  $abilities
     */
    public static function allows(mixed $user, string|array $abilities): bool
    {
        if (! is_object($user)) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        foreach ((array) $abilities as $ability) {
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
}
