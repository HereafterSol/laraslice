<?php

namespace LaraSlice\Tests\Feature\Slices;

use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class UserPartialUpdateTest extends TestCase
{
    public function test_api_partial_updates_keep_status_roles_and_details(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $target = $this->makeUser(['status' => 'suspended', 'phone' => '0300-1234567']);
        $target->roles()->attach($role->id);
        $target->detail()->create(['department' => 'Support']);

        Sanctum::actingAs($this->makeSuperAdmin());
        $this->postJson('/api/users/save', [
            'id' => $target->id,
            'name' => 'Renamed Only',
            'email' => $target->email,
        ])->assertOk();

        $target->refresh();
        $this->assertSame('Renamed Only', $target->name);
        $this->assertSame('suspended', $target->status);
        $this->assertSame('0300-1234567', $target->phone);
        $this->assertSame(['agent'], $target->roles()->pluck('slug')->all());
        $this->assertSame('Support', $target->detail->department);
    }

    public function test_web_form_with_no_role_boxes_ticked_removes_all_roles(): void
    {
        $role = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $target = $this->makeUser();
        $target->roles()->attach($role->id);

        $this->actingAs($this->makeSuperAdmin())->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'status' => 'active',
            'roles' => '',
        ])->assertRedirect();

        $this->assertSame([], $target->fresh()->roles()->pluck('slug')->all());
    }

    public function test_web_form_role_changes_are_saved(): void
    {
        $agent = Role::create(['name' => 'Agent', 'slug' => 'agent']);
        $target = $this->makeUser();

        $this->actingAs($this->makeSuperAdmin())->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => [(string) $agent->id],
        ])->assertRedirect();

        $this->assertSame(['agent'], $target->fresh()->roles()->pluck('slug')->all());
    }
}
