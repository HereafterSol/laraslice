<?php

namespace LaraSlice\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SliceRoleProvisioner
{
    /**
     * Provision roles and permissions defined in a slice manifest or configuration.
     *
     * @param array $roles List of role definitions [{slug, name, description, permissions: []}]
     * @param string|null $sliceName The associated slice or domain name
     * @param string|null $domain The domain name
     * @param bool $seedDemoUsers Whether to provision sandbox test users for each role
     * @return array Summary of provisioned roles and accounts
     */
    public function provision(array $roles, ?string $sliceName = null, ?string $domain = null, bool $seedDemoUsers = true): array
    {
        if (empty($roles) || ! Schema::hasTable('roles')) {
            return ['roles_count' => 0, 'provisioned' => []];
        }

        $provisioned = [];
        $now = now();

        foreach ($roles as $roleData) {
            $slug = Str::slug($roleData['slug'] ?? $roleData['name'] ?? '');
            if (empty($slug)) {
                continue;
            }

            $name = $roleData['name'] ?? Str::title(str_replace(['_', '-'], ' ', $slug));
            $description = $roleData['description'] ?? "Role provisioned for {$sliceName} slice";
            $permissions = $roleData['permissions'] ?? [];

            // 1. Idempotently insert/update Role
            $existingRole = DB::table('roles')->where('slug', $slug)->first();
            if ($existingRole) {
                $roleId = $existingRole->id;
                DB::table('roles')->where('id', $roleId)->update([
                    'name' => $name,
                    'description' => $description,
                    'updated_at' => $now,
                ]);
            } else {
                $roleId = DB::table('roles')->insertGetId([
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 2. Provision Permissions and attach to Role
            $attachedPerms = [];
            if (Schema::hasTable('permissions') && Schema::hasTable('permission_role')) {
                foreach ($permissions as $permSlug) {
                    $cleanSlug = trim($permSlug);
                    if (empty($cleanSlug)) {
                        continue;
                    }

                    $perm = DB::table('permissions')->where('slug', $cleanSlug)->first();
                    if ($perm) {
                        $permId = $perm->id;
                    } else {
                        $permData = [
                            'slug' => $cleanSlug,
                            'name' => Str::title(str_replace(['.', '_', '-'], ' ', $cleanSlug)),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        if (Schema::hasColumn('permissions', 'group')) {
                            $permData['group'] = $sliceName ?: 'Operations';
                        }
                        if (Schema::hasColumn('permissions', 'domain')) {
                            $permData['domain'] = $domain ?: 'Operations';
                        }
                        if (Schema::hasColumn('permissions', 'source')) {
                            $permData['source'] = 'slice';
                        }
                        if (Schema::hasColumn('permissions', 'description')) {
                            $permData['description'] = "Permission for {$cleanSlug}";
                        }
                        $permId = DB::table('permissions')->insertGetId($permData);
                    }

                    // Attach in pivot
                    $existsInPivot = DB::table('permission_role')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $permId)
                        ->exists();

                    if (! $existsInPivot) {
                        DB::table('permission_role')->insert([
                            'role_id' => $roleId,
                            'permission_id' => $permId,
                        ]);
                    }
                    $attachedPerms[] = $cleanSlug;
                }
            }

            // 3. Optional Sandbox User Creation
            $userEmail = null;
            if ($seedDemoUsers && Schema::hasTable('users') && Schema::hasTable('role_user')) {
                $userEmail = strtolower(str_replace(['_', ' '], '.', $slug)).'@laraslice.com';
                $existingUser = DB::table('users')->where('email', $userEmail)->first();

                if ($existingUser) {
                    $userId = $existingUser->id;
                } else {
                    $userData = [
                        'name' => $name.' (Demo)',
                        'email' => $userEmail,
                        'password' => Hash::make('password'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    if (Schema::hasColumn('users', 'status')) {
                        $userData['status'] = 'active';
                    }
                    if (Schema::hasColumn('users', 'email_verified_at')) {
                        $userData['email_verified_at'] = $now;
                    }

                    $userId = DB::table('users')->insertGetId($userData);
                }

                // Attach User to Role
                $hasUserRole = DB::table('role_user')
                    ->where('role_id', $roleId)
                    ->where('user_id', $userId)
                    ->exists();

                if (! $hasUserRole) {
                    DB::table('role_user')->insert([
                        'role_id' => $roleId,
                        'user_id' => $userId,
                    ]);
                }
            }

            $provisioned[] = [
                'role_id' => $roleId,
                'slug' => $slug,
                'name' => $name,
                'permissions' => $attachedPerms,
                'demo_user' => $userEmail,
            ];
        }

        return [
            'roles_count' => count($provisioned),
            'provisioned' => $provisioned,
        ];
    }

    /**
     * Read slice.json and provision its declared roles.
     */
    public function provisionForSlicePath(string $sliceDir, bool $seedDemoUsers = true): array
    {
        $manifestPath = rtrim($sliceDir, '/\\').'/slice.json';
        if (! file_exists($manifestPath)) {
            return ['roles_count' => 0, 'provisioned' => []];
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            return ['roles_count' => 0, 'provisioned' => []];
        }

        $roles = $manifest['roles'] ?? [];
        $sliceName = $manifest['name'] ?? basename($sliceDir);
        $domain = $manifest['domain'] ?? 'General';

        return $this->provision($roles, $sliceName, $domain, $seedDemoUsers);
    }
}
