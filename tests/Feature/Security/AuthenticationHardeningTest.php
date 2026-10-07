<?php

namespace LaraSlice\Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use LaraSlice\Slices\Auth\Services\LoginAttemptService;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Services\RecoveryCodeService;
use LaraSlice\Slices\Users\Services\SecurityPolicyService;
use LaraSlice\Slices\Users\Services\TotpService;
use LaraSlice\Tests\TestCase;

class AuthenticationHardeningTest extends TestCase
{
    private function requireMfaForEveryone(): void
    {
        SecurityPolicyService::set('security.mfa_enforcement', SecurityPolicyService::MFA_ALL);
    }

    private function enrolTotp(User $user): string
    {
        $secret = TotpService::generateSecret();
        $user->mfa_secret = $secret;
        $user->mfa_channel = 'totp';
        $user->mfa_confirmed_at = now();
        $user->save();

        return $secret;
    }

    private function currentCode(string $secret): string
    {
        return app(TotpService::class)->code($secret, intdiv(time(), TotpService::PERIOD));
    }

    public function test_unknown_accounts_and_wrong_passwords_get_the_same_message(): void
    {
        $this->makeUser(['email' => 'known@example.test']);

        $unknown = $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123']);
        $wrong = $this->post('/login', ['email' => 'known@example.test', 'password' => 'whatever-123']);

        $unknown->assertSessionHasErrors(['email' => LoginAttemptService::GENERIC_ERROR]);
        $wrong->assertSessionHasErrors(['email' => LoginAttemptService::GENERIC_ERROR]);
    }

    public function test_lockout_uses_the_configured_policy(): void
    {
        SecurityPolicyService::set('security.max_failed_attempts', 2);
        $user = $this->makeUser(['email' => 'lock@example.test']);

        $this->post('/login', ['email' => 'lock@example.test', 'password' => 'wrong-1']);
        $this->post('/login', ['email' => 'lock@example.test', 'password' => 'wrong-2']);

        $this->assertTrue($user->fresh()->locked_until?->isFuture());
        $this->post('/login', ['email' => 'lock@example.test', 'password' => 'correct-horse-battery'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'flood@example.test', 'password' => "guess-{$i}"]);
        }

        $this->post('/login', ['email' => 'flood@example.test', 'password' => 'guess-6'])->assertStatus(429);
    }

    public function test_password_alone_cannot_read_or_replace_an_enrolled_factor(): void
    {
        $this->requireMfaForEveryone();
        $user = $this->makeUser(['email' => 'mfa@example.test']);
        $secret = $this->enrolTotp($user);

        $this->post('/login', ['email' => 'mfa@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('login.mfa.challenge'));

        // The enrolment page must not hand out the existing secret
        $this->get('/login/mfa-enroll')->assertRedirect(route('login.mfa.challenge'));
        $this->getJson('/login/passkey/enroll-options')->assertForbidden();
        $this->assertGuest();
        $this->assertSame($secret, $user->fresh()->mfa_secret);
    }

    public function test_totp_codes_cannot_be_replayed(): void
    {
        $this->requireMfaForEveryone();
        $user = $this->makeUser(['email' => 'replay@example.test']);
        $secret = $this->enrolTotp($user);
        $code = $this->currentCode($secret);

        $this->post('/login', ['email' => 'replay@example.test', 'password' => 'correct-horse-battery']);
        $this->post('/login/mfa-challenge', ['auth_mode' => 'totp', 'code' => $code]);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->post('/login', ['email' => 'replay@example.test', 'password' => 'correct-horse-battery']);
        $this->post('/login/mfa-challenge', ['auth_mode' => 'totp', 'code' => $code]);
        $this->assertGuest();
    }

    public function test_pending_mfa_state_expires(): void
    {
        $this->requireMfaForEveryone();
        $user = $this->makeUser(['email' => 'slow@example.test']);
        $secret = $this->enrolTotp($user);

        $this->post('/login', ['email' => 'slow@example.test', 'password' => 'correct-horse-battery']);
        $this->travel(6)->minutes();

        $this->post('/login/mfa-challenge', ['auth_mode' => 'totp', 'code' => $this->currentCode($secret)])
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_recovery_codes_are_hashed_single_use_and_accept_legacy_rows(): void
    {
        $user = $this->makeUser();
        $codes = app(RecoveryCodeService::class)->generate($user);

        $stored = DB::table('user_recovery_codes')->where('user_id', $user->id)->pluck('code_hash')->all();
        $this->assertCount(8, $stored);
        foreach ($codes as $code) {
            $this->assertNotContains(RecoveryCodeService::normalize($code), $stored);
        }

        $service = app(RecoveryCodeService::class);
        $this->assertTrue($service->consume($user, $codes[0]));
        $this->assertFalse($service->consume($user, $codes[0]), 'recovery codes are single-use');

        // A pre-v1.4.0 plaintext row is still accepted once
        $user->recoveryCodes()->create(['code_hash' => 'ABCD1234', 'created_at' => now()]);
        $this->assertTrue($service->consume($user, 'abcd-1234'));
        $this->assertFalse($service->consume($user, 'abcd-1234'));
    }

    public function test_mfa_secrets_are_encrypted_at_rest_and_legacy_values_still_read(): void
    {
        $user = $this->makeUser();
        $secret = $this->enrolTotp($user);

        $raw = DB::table('users')->where('id', $user->id)->value('mfa_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, $user->fresh()->mfa_secret);

        DB::table('users')->where('id', $user->id)->update(['mfa_secret' => 'LEGACYPLAINTEXT2']);
        $this->assertSame('LEGACYPLAINTEXT2', $user->fresh()->mfa_secret);
    }

    public function test_suspended_users_cannot_complete_mfa(): void
    {
        $this->requireMfaForEveryone();
        $user = $this->makeUser(['email' => 'suspend@example.test']);
        $secret = $this->enrolTotp($user);

        $this->post('/login', ['email' => 'suspend@example.test', 'password' => 'correct-horse-battery']);
        $user->forceFill(['status' => 'suspended'])->save();

        $this->post('/login/mfa-challenge', ['auth_mode' => 'totp', 'code' => $this->currentCode($secret)]);
        $this->assertGuest();
    }

    public function test_passkey_options_never_list_every_credential(): void
    {
        $owner = $this->makeUser();
        DB::table('user_passkeys')->insert([
            'user_id' => $owner->id,
            'credential_id' => 'c2VjcmV0LWNyZWRlbnRpYWwtaWQ',
            'public_key' => 'pk',
            'sign_count' => 0,
            'label' => 'Key',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $options = $this->getJson('/login/passkey/options')->assertOk()->json();
        $allow = $options['args']['publicKey']['allowCredentials'] ?? [];
        $this->assertSame([], $allow);
    }

    public function test_api_login_enforces_mfa_and_issues_sanctum_tokens(): void
    {
        $this->requireMfaForEveryone();
        $user = $this->makeUser(['email' => 'api@example.test']);
        $secret = $this->enrolTotp($user);

        $this->postJson('/api/auth/login', ['email' => 'api@example.test', 'password' => 'correct-horse-battery'])
            ->assertStatus(422)->assertJsonValidationErrors('mfa_code');

        $token = $this->postJson('/api/auth/login', [
            'email' => 'api@example.test',
            'password' => 'correct-horse-battery',
            'mfa_code' => $this->currentCode($secret),
        ])->assertOk()->json('data.token');

        $this->assertNotEmpty($token);
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_mfa_pages_render_without_sending_the_secret_to_a_third_party(): void
    {
        $this->requireMfaForEveryone();
        $this->makeUser(['email' => 'new@example.test']);

        $this->post('/login', ['email' => 'new@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('login.mfa.enroll'));
        $enroll = $this->get('/login/mfa-enroll')->assertOk()->getContent();
        $this->assertStringContainsString('otpauth://totp/', $enroll);
        $this->assertStringNotContainsString('qrserver', $enroll);

        $enrolled = $this->makeUser();
        $this->enrolTotp($enrolled);
        $settings = $this->actingAs($enrolled)->get('/account/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('qrserver', $settings);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $settings);
    }

    public function test_recovery_codes_are_shown_once_after_regeneration(): void
    {
        $user = $this->makeUser();
        $this->enrolTotp($user);

        $response = $this->actingAs($user)->post('/admin/users/settings/2fa/regenerate-recovery-codes');
        $codes = session('recovery_codes');
        $this->assertCount(8, $codes);

        $first = $this->actingAs($user)->get($response->headers->get('Location'))->getContent();
        $this->assertStringContainsString($codes[0], $first);

        $second = $this->actingAs($user)->get('/account/settings')->getContent();
        $this->assertStringNotContainsString($codes[0], $second);
    }

    public function test_api_registration_is_disabled_by_default(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.test',
            'password' => 'walk-in-password',
            'password_confirmation' => 'walk-in-password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'walkin@example.test']);
    }
}
