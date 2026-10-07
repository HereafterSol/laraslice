<?php

namespace LaraSlice\Core\Security\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Slices\Roles\Models\Permission;

/**
 * Trait HasSlicePermissions
 *
 * Provides slice-aware Role-Based Access Control (RBAC) to any User model.
 * Bridges transparently to Laravel's native Gate and @can directives.
 */
trait HasSlicePermissions
{
    /**
     * User belongs to many Roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    /**
     * Check if user possesses a specific role slug.
     */
    public function hasRole(string|array $role): bool
    {
        $roles = is_array($role) ? $role : func_get_args();

        $userRoles = [];
        try {
            if ((method_exists($this, 'relationLoaded') && $this->relationLoaded('roles')) || isset($this->roles)) {
                $userRoles = $this->roles->pluck('slug')->toArray();
            }
        } catch (\Throwable $e) {}

        if (empty($userRoles) && isset($this->id)) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('role_user') && \Illuminate\Support\Facades\Schema::hasTable('roles')) {
                    $userRoles = \Illuminate\Support\Facades\DB::table('role_user')
                        ->join('roles', 'role_user.role_id', '=', 'roles.id')
                        ->where('role_user.user_id', $this->id)
                        ->pluck('roles.slug')
                        ->toArray();
                }
            } catch (\Throwable $e) {}
        }

        foreach ($roles as $r) {
            if (in_array($r, $userRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        // Super-admin possesses all permissions by default
        if ($this->hasRole('super-admin')) {
            return true;
        }

        $allPermissions = $this->getAllPermissions();

        // Exact match check
        if ($allPermissions->contains('slug', $permissionSlug) || $allPermissions->contains('name', $permissionSlug)) {
            return true;
        }

        // Wildcard match (e.g. 'shop_product.*' satisfies 'shop_product.view')
        if (str_contains($permissionSlug, '.')) {
            $prefix = explode('.', $permissionSlug)[0];
            $wildcard = $prefix . '.*';
            if ($allPermissions->contains('slug', $wildcard) || $allPermissions->contains('name', $wildcard)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve all unique permissions assigned to this user through their roles.
     */
    public function getAllPermissions(): Collection
    {
        if ($this->hasRole('super-admin')) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('permissions')) {
                    return Permission::all();
                }
            } catch (\Throwable $e) {
                // Return empty collection if DB not available
            }
        }

        $hasRolesLoaded = (method_exists($this, 'relationLoaded') && $this->relationLoaded('roles')) || isset($this->roles);

        if ($hasRolesLoaded) {
            $permissions = collect();
            foreach ($this->roles as $role) {
                if (method_exists($role, 'relationLoaded') && $role->relationLoaded('permissions')) {
                    $permissions = $permissions->merge($role->permissions);
                } elseif (isset($role->permissions)) {
                    $permissions = $permissions->merge($role->permissions);
                } elseif (method_exists($role, 'permissions')) {
                    $permissions = $permissions->merge($role->permissions()->get());
                }
            }
            return $permissions->unique('id');
        }

        // Direct DB fallback for performance / unhydrated models
        return Permission::query()
            ->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $this->id)
            ->select('permissions.*')
            ->distinct()
            ->get();
    }

    /**
     * Determine if the user has multi-factor authentication active.
     */
    public function hasMfa(): bool
    {
        return $this->hasTotp()
            || $this->hasPasskey()
            || (!empty($this->mfa_channel) && $this->mfa_channel !== 'none' && !empty($this->mfa_confirmed_at))
            || !empty($this->two_factor_confirmed_at);
    }

    public function hasTotp(): bool
    {
        return in_array($this->mfa_channel, ['totp', 'both'])
            && !empty($this->mfa_secret)
            && !empty($this->mfa_confirmed_at);
    }

    public function hasPasskey(): bool
    {
        if ($this->relationLoaded('passkeys')) {
            return $this->passkeys->whereNull('revoked_at')->isNotEmpty();
        }
        return method_exists($this, 'passkeys') && $this->passkeys()->whereNull('revoked_at')->exists();
    }
}