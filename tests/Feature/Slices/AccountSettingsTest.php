<?php

namespace LaraSlice\Tests\Feature\Slices;

use LaraSlice\Slices\Users\Services\TotpService;
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

    public function test_authenticator_is_only_turned_on_after_a_code_is_confirmed(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->post('/admin/users/settings/2fa/toggle')->assertRedirect();

        $user->refresh();
        $this->assertNotEmpty($user->mfa_secret);
        $this->assertFalse($user->hasTotp(), 'a pending secret must not make sign-in ask for codes');

        $this->actingAs($user)->post('/admin/users/settings/2fa/verify-test', ['code' => '000000']);
        $this->assertFalse($user->fresh()->hasTotp());

        $code = app(TotpService::class)->code($user->mfa_secret, intdiv(time(), 30));
        $this->actingAs($user)->post('/admin/users/settings/2fa/verify-test', ['code' => $code])
            ->assertSessionHas('recovery_codes');
        $this->assertTrue($user->fresh()->hasTotp());
    }

    public function test_a_pending_authenticator_setup_can_be_cancelled(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->post('/admin/users/settings/2fa/toggle');
        $this->actingAs($user)->post('/admin/users/settings/2fa/toggle');

        $this->assertEmpty($user->fresh()->mfa_secret);
        $this->assertFalse($user->fresh()->hasTotp());
    }
}
