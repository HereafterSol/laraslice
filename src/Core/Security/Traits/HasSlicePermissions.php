<?php

namespace LaraSlice\Core\Security\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LaraSlice\Slices\Roles\Models\Permission;
use LaraSlice\Slices\Roles\Models\Role;

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

    /** @var array<int, string>|null role slugs, loaded once per user instance */
    protected ?array $sliceRoleSlugs = null;

    /** Permissions through the user's roles, loaded once per user instance */
    protected ?Collection $slicePermissions = null;

    /**
     * Forget cached roles and permissions, e.g. after syncing this user's roles.
     */
    public function flushSlicePermissionCache(): static
    {
        $this->sliceRoleSlugs = null;
        $this->slicePermissions = null;
        if (method_exists($this, 'unsetRelation')) {
            $this->unsetRelation('roles');
        }

        return $this;
    }

    /**
     * The slugs of the user's roles.
     *
     * @return array<int, string>
     */
    public function roleSlugs(): array
    {
        if ($this->sliceRoleSlugs !== null) {
            return $this->sliceRoleSlugs;
        }

        if ($this->rolesInMemory()) {
            return $this->sliceRoleSlugs = collect($this->roles)->pluck('slug')->all();
        }

        if (! isset($this->id)) {
            return [];
        }

        try {
            $slugs = DB::table('role_user')
                ->join('roles', 'role_user.role_id', '=', 'roles.id')
                ->where('role_user.user_id', $this->id)
                ->pluck('roles.slug')
                ->all();
        } catch (\Throwable $e) {
            return []; // tables not migrated yet; do not cache
        }

        return $this->sliceRoleSlugs = $slugs;
    }

    /**
     * Whether the roles are already in memory: an eager-loaded relation, or a plain
     * object (not an Eloquent model) that carries its roles in a property.
     */
    protected function rolesInMemory(): bool
    {
        if (method_exists($this, 'relationLoaded')) {
            return $this->relationLoaded('roles');
        }

        return isset($this->roles);
    }

    /**
     * Check if user possesses a specific role slug.
     */
    public function hasRole(string|array $role): bool
    {
        $roles = is_array($role) ? $role : func_get_args();

        return array_intersect($roles, $this->roleSlugs()) !== [];
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
            $wildcard = explode('.', $permissionSlug)[0].'.*';
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
        if ($this->slicePermissions !== null) {
            return $this->slicePermissions;
        }

        try {
            if ($this->hasRole('super-admin')) {
                return $this->slicePermissions = Permission::all();
            }

            if ($this->rolesInMemory()) {
                // Roles already loaded (eager-loaded, or a plain object): use their permissions
                $permissions = collect();
                foreach ($this->roles as $role) {
                    $rolePermissions = method_exists($role, 'relationLoaded') && ! $role->relationLoaded('permissions') && method_exists($role, 'permissions')
                        ? $role->permissions()->get()
                        : ($role->permissions ?? collect());
                    $permissions = $permissions->merge($rolePermissions);
                }

                return $this->slicePermissions = $permissions->unique('id')->values();
            }

            if (! isset($this->id)) {
                return collect();
            }

            return $this->slicePermissions = Permission::query()
                ->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
                ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $this->id)
                ->select('permissions.*')
                ->distinct()
                ->get();
        } catch (\Throwable $e) {
            return collect(); // tables not migrated yet; do not cache
        }
    }

    /**
     * Determine if the user has multi-factor authentication active.
     */
    public function hasMfa(): bool
    {
        return $this->hasTotp()
            || $this->hasPasskey()
            || (! empty($this->mfa_channel) && $this->mfa_channel !== 'none' && ! empty($this->mfa_confirmed_at))
            || ! empty($this->two_factor_confirmed_at);
    }

    public function hasTotp(): bool
    {
        return in_array($this->mfa_channel, ['totp', 'both'])
            && ! empty($this->mfa_secret)
            && ! empty($this->mfa_confirmed_at);
    }

    public function hasPasskey(): bool
    {
        if ($this->relationLoaded('passkeys')) {
            return $this->passkeys->whereNull('revoked_at')->isNotEmpty();
        }

        return method_exists($this, 'passkeys') && $this->passkeys()->whereNull('revoked_at')->exists();
    }
}
