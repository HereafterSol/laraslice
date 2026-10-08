<?php

namespace LaraSlice\Tests\Feature\Security;

use Illuminate\Support\Facades\Hash;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Models\UserDevice;
use LaraSlice\Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class SliceAuthorizationTest extends TestCase
{
    public function test_view_permission_does_not_unlock_writes(): void
    {
        $viewer = $this->makeUserWithPermissions(['users.view']);
        $victim = $this->makeUser();

        $this->actingAs($viewer)->post('/admin/users', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'long-enough-1'])->assertForbidden();
        $this->actingAs($viewer)->delete("/admin/users/{$victim->id}")->assertForbidden();
        $this->actingAs($viewer)->post("/admin/users/{$victim->id}/unlock")->assertForbidden();
        $this->actingAs($viewer)->post("/admin/users/mfa/{$victim->id}/reset-enrollment")->assertForbidden();
        $this->actingAs($viewer)->post('/admin/users/mfa/policy', ['mfa_enforcement' => 'off', 'max_failed_attempts' => 5])->assertForbidden();

        $this->assertNotNull(User::find($victim->id));
    }

    public function test_users_without_any_permission_are_denied_even_when_permission_rows_are_missing(): void
    {
        $nobody = $this->makeUser();

        $this->actingAs($nobody)->post('/admin/roles', ['name' => 'Sneaky', 'slug' => 'sneaky'])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['slug' => 'sneaky']);
    }

    public function test_store_ignores_a_smuggled_id_and_never_overwrites_another_record(): void
    {
        $creator = $this->makeUserWithPermissions(['users.create']);
        $victim = $this->makeUser(['email' => 'victim@example.test']);

        $this->actingAs($creator)->post('/admin/users', [
            'id' => $victim->id,
            'name' => 'Attacker Copy',
            'email' => 'attacker@example.test',
            'password' => 'attacker-password',
        ]);

        $victim->refresh();
        $this->assertSame('victim@example.test', $victim->email);
        $this->assertTrue(Hash::check('correct-horse-battery', $victim->password));
    }

    public function test_non_super_admins_cannot_grant_roles_they_do_not_hold(): void
    {
        $superRole = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
        $manager = $this->makeUserWithPermissions(['users.create', 'users.edit']);

        $this->actingAs($manager)->post('/admin/users', [
            'name' => 'Escalated',
            'email' => 'escalated@example.test',
            'password' => 'escalated-password',
            'roleIds' => [$superRole->id],
        ])->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);
    }

    public function test_non_super_admins_cannot_edit_super_admin_accounts(): void
    {
        $admin = $this->makeSuperAdmin();
        $manager = $this->makeUserWithPermissions(['users.edit']);

        $this->actingAs($manager)->put("/admin/users/{$admin->id}", [
            'name' => 'Owned',
            'email' => 'owned@example.test',
            'password' => 'new-password-123',
        ])->assertForbidden();

        $this->assertNotSame('owned@example.test', $admin->fresh()->email);
    }

    public function test_new_users_require_a_password_instead_of_a_shared_default(): void
    {
        $creator = $this->makeUserWithPermissions(['users.create']);

        $this->actingAs($creator)->post('/admin/users', [
            'name' => 'No Password',
            'email' => 'nopass@example.test',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'nopass@example.test']);
    }

    public function test_editing_a_user_without_a_new_password_keeps_the_existing_one(): void
    {
        $editor = $this->makeUserWithPermissions(['users.edit']);
        $target = $this->makeUser();

        $this->actingAs($editor)->put("/admin/users/{$target->id}", [
            'name' => 'Renamed',
            'email' => $target->email,
        ]);

        $target->refresh();
        $this->assertSame('Renamed', $target->name);
        $this->assertTrue(Hash::check('correct-horse-battery', $target->password));
    }

    public function test_the_default_admin_email_is_not_a_super_admin_backdoor(): void
    {
        $impostor = $this->makeUser(['email' => 'admin@laraslice.com']);

        $this->actingAs($impostor)->get('/admin/roles')->assertForbidden();
        $this->assertFalse($impostor->hasRole('super-admin'));
    }

    public function test_role_id_one_is_not_treated_as_super_admin(): void
    {
        // Role 1 is seeded as super-admin; once renamed it must lose all special treatment
        $first = Role::findOrFail(1);
        $first->update(['slug' => 'viewer']);

        $user = $this->makeUser();
        $user->roles()->attach($first->id);

        $this->actingAs($user->fresh())->get('/admin/roles')->assertForbidden();
    }

    public function test_api_routes_enforce_slice_permissions(): void
    {
        $viewer = $this->makeUserWithPermissions(['users.view']);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/users/save', [
            'name' => 'Api Created',
            'email' => 'api@example.test',
            'password' => 'api-password-1',
        ])->assertForbidden();

        $this->deleteJson("/api/users/{$viewer->id}")->assertForbidden();
        $this->postJson('/api/users/list')->assertOk();
    }

    public function test_users_cannot_revoke_other_users_devices_through_self_service(): void
    {
        $owner = $this->makeUser();
        $device = UserDevice::create([
            'user_id' => $owner->id,
            'device_name' => 'Chrome on Windows',
            'ip_address' => '10.0.0.5',
            'is_current' => false,
            'last_active_at' => now(),
        ]);

        $other = $this->makeUser();
        $this->actingAs($other)->delete("/security/settings/devices/{$device->id}")->assertNotFound();
        $this->assertNotNull(UserDevice::find($device->id));
    }
}
