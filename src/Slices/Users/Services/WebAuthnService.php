<?php

namespace LaraSlice\Slices\Users\Services;

use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Models\UserPasskey;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;

class WebAuthnService
{
    protected ?WebAuthn $webauthn = null;

    public function __construct()
    {
        $this->registerAutoloader();
    }

    protected function registerAutoloader(): void
    {
        spl_autoload_register(function ($class) {
            $prefix = 'lbuchs\\WebAuthn\\';
            $baseDir = __DIR__.'/../WebAuthn/lbuchs/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $baseDir.str_replace('\\', '/', $relativeClass).'.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
    }

    public function getWebAuthnInstance(): WebAuthn
    {
        if ($this->webauthn !== null) {
            return $this->webauthn;
        }

        $rpName = config('app.name', 'LaraSlice Enterprise');
        $host = request()->getHost() ?: 'localhost';
        if ($host === '127.0.0.1' || $host === '::1') {
            $rpId = 'localhost';
        } else {
            $rpId = $host;
        }

        $formats = ['none', 'packed', 'apple', 'android-key', 'fido-u2f', 'tpm'];
        $this->webauthn = new WebAuthn($rpName, $rpId, $formats, true);

        return $this->webauthn;
    }

    public function getRegisterArgs(User $user): array
    {
        $webauthn = $this->getWebAuthnInstance();
        $excludeIds = $user->passkeys()->whereNull('revoked_at')->pluck('credential_id')->map(function ($id) {
            return $this->b64decode($id);
        })->filter()->toArray();

        $args = $webauthn->getCreateArgs(
            (string) $user->id,
            (string) $user->email,
            (string) $user->name,
            60,
            'preferred', // discoverable where supported, so sign-in works without listing credentials
            'preferred',
            null,
            $excludeIds
        );

        $challenge = $this->b64encode($webauthn->getChallenge()->getBinaryString());
        session(['webauthn_challenge' => $challenge]);

        return [
            'args' => $args,
            'challenge' => $challenge,
        ];
    }

    public function processRegister(User $user, string $clientDataJSON, string $attestationObject, ?string $label = 'Passkey'): UserPasskey
    {
        $challengeB64 = session('webauthn_challenge');
        if (! $challengeB64) {
            throw new \RuntimeException('WebAuthn registration challenge missing or expired.');
        }

        $webauthn = $this->getWebAuthnInstance();
        $challenge = new ByteBuffer($this->b64decode($challengeB64));

        $data = $webauthn->processCreate(
            $this->b64decode($clientDataJSON),
            $this->b64decode($attestationObject),
            $challenge,
            false,
            true,
            false
        );

        session()->forget('webauthn_challenge');

        $credId = $this->b64encode($data->credentialId);
        $hasTotpConfirmed = in_array($user->mfa_channel, ['totp', 'both']);
        $user->mfa_channel = $hasTotpConfirmed ? 'both' : 'webauthn';
        $user->mfa_confirmed_at = now();
        if (! $hasTotpConfirmed) {
            $user->mfa_secret = null;
        }
        $user->save();

        return UserPasskey::create([
            'user_id' => $user->id,
            'credential_id' => $credId,
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) ($data->signatureCounter ?? 0),
            'aaguid' => isset($data->AAGUID) ? bin2hex($data->AAGUID) : null,
            'label' => $label ?: 'Passkey',
            'last_used_at' => now(),
        ]);
    }

    public function getLoginArgs(?User $user = null): array
    {
        $webauthn = $this->getWebAuthnInstance();
        $ids = [];

        if ($user) {
            $ids = $user->passkeys()->whereNull('revoked_at')->pluck('credential_id')->map(function ($id) {
                return $this->b64decode($id);
            })->filter()->toArray();
        }

        // Without a known user the list stays empty and the browser offers discoverable passkeys;
        // never hand out every credential id in the system. User verification is required
        // because a passkey sign-in counts as a full second factor.
        $userVerification = config('laraslice.auth.passkey_user_verification', 'preferred');
        $args = $webauthn->getGetArgs($ids, 60, true, true, true, true, true, $userVerification);
        $challenge = $this->b64encode($webauthn->getChallenge()->getBinaryString());
        session(['webauthn_login_challenge' => $challenge]);

        return [
            'args' => $args,
            'challenge' => $challenge,
        ];
    }

    public function processLogin(string $clientDataJSON, string $authenticatorData, string $signature, string $credentialIdB64): User
    {
        $challengeB64 = session('webauthn_login_challenge');
        if (! $challengeB64) {
            throw new \RuntimeException('WebAuthn login challenge missing or expired.');
        }

        $passkey = UserPasskey::where('credential_id', $credentialIdB64)->whereNull('revoked_at')->first();
        if (! $passkey) {
            throw new \RuntimeException('Unknown or revoked passkey credential.');
        }

        $webauthn = $this->getWebAuthnInstance();
        $challenge = new ByteBuffer($this->b64decode($challengeB64));

        $webauthn->processGet(
            $this->b64decode($clientDataJSON),
            $this->b64decode($authenticatorData),
            $this->b64decode($signature),
            $passkey->public_key,
            $challenge,
            (int) $passkey->sign_count,
            (bool) config('laraslice.auth.passkey_require_user_verification', false),
            true
        );

        $counter = $webauthn->getSignatureCounter();
        $passkey->update([
            'sign_count' => is_int($counter) ? $counter : $passkey->sign_count + 1,
            'last_used_at' => now(),
        ]);

        session()->forget('webauthn_login_challenge');

        return $passkey->user;
    }

    public function b64decode(string $str): string
    {
        $str = strtr($str, '-_', '+/');
        $pad = strlen($str) % 4;
        if ($pad > 0) {
            $str .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($str) ?: '';
    }

    public function b64encode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
