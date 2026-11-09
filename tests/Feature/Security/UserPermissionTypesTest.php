<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Security\Access;
use LaraSlice\Core\Security\UserPermissionTypes;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Tests\TestCase;

/**
 * The per-user permission-type layer (the source systems'
 * customised permissions): a user's typed rows win over
 * their roles' grants in Access::scopeFor, a write is
 * refused outside the permission's own allowed set, and
 * Reset returns the user to pure role resolution. The
 * endpoints serve only viewers who may manage employees,
 * and never an admin-role target.
 */
class UserPermissionTypesTest extends TestCase
{
    private int $moduleId;

    private int $permissionId;

    private int $typeAll;

    private int $typeOwned;

    private int $typeNone;

    protected function setUp(): void
    {
        parent::setUp();

        // Port-shaped permission columns (host apps carried
        // from the source carry these; the lean framework
        // schema gains them here — each under its own guard,
        // the user_permission_types migration may already
        // have added allowed_permissions).
        Schema::table('permissions', function (Blueprint $t) {
            if (! Schema::hasColumn('permissions', 'module_id')) {
                $t->unsignedBigInteger('module_id')->nullable();
            }
            if (! Schema::hasColumn('permissions', 'display_name')) {
                $t->string('display_name')->nullable();
            }
            if (! Schema::hasColumn('permissions', 'is_custom')) {
                $t->boolean('is_custom')->default(false);
            }
            if (! Schema::hasColumn('permissions', 'allowed_permissions')) {
                $t->text('allowed_permissions')->nullable();
            }
        });
        if (! Schema::hasTable('modules')) {
            Schema::create('modules', function (Blueprint $t) {
                $t->id();
                $t->string('module_name');
            });
        }
        if (! Schema::hasColumn('users', 'customised_permissions')) {
            Schema::table('users', function (Blueprint $t) {
                $t->boolean('customised_permissions')->default(false);
            });
        }

        $this->typeAll = (int) DB::table('permission_types')->where('name', 'all')->value('id');
        $this->typeOwned = (int) DB::table('permission_types')->where('name', 'owned')->value('id');
        $this->typeNone = (int) DB::table('permission_types')->where('name', 'none')->value('id');

        $this->moduleId = (int) DB::table('modules')->insertGetId(['module_name' => 'Projects']);
        $this->permissionId = (int) DB::table('permissions')->insertGetId([
            'name' => 'view_projects',
            'slug' => 'project.view',
            'module_id' => $this->moduleId,
            'display_name' => 'View Projects',
            'is_custom' => 0,
            'allowed_permissions' => json_encode(['all' => $this->typeAll, 'owned' => $this->typeOwned, 'none' => $this->typeNone]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function employeeWithRoleGrant(int $typeId): object
    {
        $user = $this->makeUser();
        $role = Role::create(['name' => 'Staff '.$user->id, 'slug' => 'staff-'.$user->id]);
        DB::table('permission_role')->insert([
            'permission_id' => $this->permissionId,
            'role_id' => $role->id,
            'permission_type_id' => $typeId,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_typed_override_wins_over_the_role_grant(): void
    {
        $user = $this->employeeWithRoleGrant($this->typeOwned);
        $this->assertSame('owned', Access::scopeFor($user, 'project.view'));

        $this->assertTrue(UserPermissionTypes::setType($user->id, $this->permissionId, $this->typeAll));
        $this->assertSame('all', UserPermissionTypes::typeFor($user->id, $this->permissionId));
        $this->assertSame('all', Access::scopeFor($user, 'project.view'));
        $this->assertTrue(UserPermissionTypes::customised($user->id));
    }

    public function test_override_to_none_hides_the_role_grant(): void
    {
        $user = $this->employeeWithRoleGrant($this->typeOwned);

        $this->assertTrue(UserPermissionTypes::setType($user->id, $this->permissionId, $this->typeNone));
        $this->assertSame('none', Access::scopeFor($user, 'project.view'));
    }

    public function test_set_type_refuses_a_type_outside_the_allowed_set(): void
    {
        $user = $this->employeeWithRoleGrant($this->typeOwned);
        $typeAdded = (int) DB::table('permission_types')->where('name', 'added')->value('id');

        $this->assertFalse(UserPermissionTypes::setType($user->id, $this->permissionId, $typeAdded));
        $this->assertNull(UserPermissionTypes::typeFor($user->id, $this->permissionId));
        $this->assertSame('owned', Access::scopeFor($user, 'project.view'));
    }

    public function test_reset_restores_role_resolution_and_clears_the_flag(): void
    {
        $user = $this->employeeWithRoleGrant($this->typeOwned);
        UserPermissionTypes::setType($user->id, $this->permissionId, $this->typeAll);
        $this->assertSame(1, (int) DB::table('users')->where('id', $user->id)->value('customised_permissions'));

        UserPermissionTypes::reset($user->id);

        $this->assertNull(UserPermissionTypes::typeFor($user->id, $this->permissionId));
        $this->assertSame('owned', Access::scopeFor($user, 'project.view'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $user->id)->value('customised_permissions'));
    }

    public function test_matrix_reports_effective_values_and_the_customised_marker(): void
    {
        $user = $this->employeeWithRoleGrant($this->typeOwned);

        $matrix = UserPermissionTypes::matrixForUser($user->id);
        $this->assertFalse($matrix['admin_role']);
        $this->assertFalse($matrix['customised']);
        $this->assertSame('Projects', $matrix['modules'][0]['name']);
        $this->assertSame('owned', $matrix['modules'][0]['main'][0]['effective']);
        $this->assertFalse($matrix['modules'][0]['main'][0]['customised']);

        UserPermissionTypes::setType($user->id, $this->permissionId, $this->typeAll);
        $matrix = UserPermissionTypes::matrixForUser($user->id);
        $this->assertTrue($matrix['customised']);
        $this->assertSame('all', $matrix['modules'][0]['main'][0]['effective']);
        $this->assertTrue($matrix['modules'][0]['main'][0]['customised']);
    }

    public function test_matrix_includes_the_framework_family_group_axis(): void
    {
        $user = $this->employeeWithRoleGrant($this->typeOwned);
        // A framework-native permission: no module, a `group`.
        $nativeId = (int) DB::table('permissions')->insertGetId([
            'name' => 'Task Create',
            'slug' => 'task.create',
            'group' => 'Tasks',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = (int) DB::table('role_user')->where('user_id', $user->id)->value('role_id');
        DB::table('permission_role')->insert([
            'permission_id' => $nativeId,
            'role_id' => $roleId,
            'permission_type_id' => $this->typeAll,
        ]);

        $matrix = UserPermissionTypes::matrixForUser($user->id);
        $groups = collect($matrix['modules'])->firstWhere('name', 'Tasks');
        $this->assertNotNull($groups, 'the Tasks group row exists');
        $this->assertSame('all', $groups['main'][0]['effective']);
        $this->assertSame([], $groups['custom']);
    }

    public function test_endpoint_refuses_a_viewer_who_cannot_manage_employees(): void
    {
        $target = $this->employeeWithRoleGrant($this->typeOwned);
        $viewer = $this->makeUser();

        $this->actingAs($viewer);
        $this->postJson(route('laraslice.user_permissions.update', ['userId' => $target->id]), [
            'permission_id' => $this->permissionId,
            'permission_type_id' => $this->typeAll,
        ])->assertForbidden();
        $this->assertNull(UserPermissionTypes::typeFor($target->id, $this->permissionId));
    }

    public function test_endpoint_serves_a_manager_and_refuses_an_admin_target(): void
    {
        $target = $this->employeeWithRoleGrant($this->typeOwned);
        $manager = $this->makeUserWithPermissions(['employee_detail.edit']);

        $this->actingAs($manager);
        $this->postJson(route('laraslice.user_permissions.update', ['userId' => $target->id]), [
            'permission_id' => $this->permissionId,
            'permission_type_id' => $this->typeAll,
        ])->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame('all', UserPermissionTypes::typeFor($target->id, $this->permissionId));

        // An admin-role target: the matrix refuses, like the source.
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $adminUser = $this->makeUser();
        $adminUser->roles()->attach($adminRole->id);
        $this->postJson(route('laraslice.user_permissions.update', ['userId' => $adminUser->id]), [
            'permission_id' => $this->permissionId,
            'permission_type_id' => $this->typeAll,
        ])->assertStatus(422);
    }
}
