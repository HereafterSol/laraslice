<?php

namespace LaraSlice\Tests\Feature\Slices;

use LaraSlice\Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    public function test_profile_update_validates_and_rejects_another_users_email(): void
    {
        $other = $this->makeUser(['email' => 'taken@example.test']);
        $user = $this->makeUser();

        $this->actingAs($user)->post('/admin/users/settings/profile', ['name' => 'Sara', 'email' => 'taken@example.test'])
            ->assertSessionHasErrors('email');
        $this->actingAs($user)->post('/admin/users/settings/profile', ['name' => '', 'email' => 'not-an-email', 'gender' => 'robot'])
            ->assertSessionHasErrors(['name', 'email', 'gender']);

        $this->assertSame('taken@example.test', $other->fresh()->email);
        $this->assertNotSame('taken@example.test', $user->fresh()->email);
    }

    public function test_gender_can_be_cleared_and_employment_form_leaves_profile_alone(): void
    {
        $user = $this->makeUser(['gender' => 'female']);

        $this->actingAs($user)->post('/admin/users/settings/profile', ['department' => 'Support'])->assertSessionHasNoErrors();
        $this->assertSame('female', $user->fresh()->gender);

        $this->actingAs($user)->post('/admin/users/settings/profile', ['name' => $user->name, 'email' => $user->email, 'gender' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->gender);
    }
}
