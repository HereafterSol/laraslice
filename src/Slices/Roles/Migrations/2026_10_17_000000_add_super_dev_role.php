<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions the `super-dev` platform developer role for existing installations.
 *
 * Fresh installs get this role from `slice:install`; this migration covers
 * apps installed before the role existed. Idempotent — safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $exists = DB::table('roles')->where('slug', 'super-dev')->exists();
        if ($exists) {
            return;
        }

        DB::table('roles')->insert([
            'name' => 'Platform Developer',
            'slug' => 'super-dev',
            'description' => 'Framework developer: whole-system bypass for Studio, Blueprint, Porting, migrations and framework operations. Immune to data-port wipes.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Grant all existing permissions (mirrors SliceManager::syncPermissions).
        // Note: permission_role is a bare pivot (no timestamps).
        if (Schema::hasTable('permissions') && Schema::hasTable('permission_role')) {
            $roleId = DB::table('roles')->where('slug', 'super-dev')->value('id');
            $permissionIds = DB::table('permissions')->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    []
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'super-dev')->value('id');
        if ($roleId) {
            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->where('role_id', $roleId)->delete();
            }
            if (Schema::hasTable('role_user')) {
                DB::table('role_user')->where('role_id', $roleId)->delete();
            }
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
