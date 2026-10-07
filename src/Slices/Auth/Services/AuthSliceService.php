<?php

namespace LaraSlice\Slices\Auth\Services;

use LaraSlice\Slices\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LaraSlice\Slices\Users\Services\RecoveryCodeService;
use LaraSlice\Slices\Users\Services\SecurityPolicyService;
use LaraSlice\Slices\Users\Services\TotpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthSliceService
{
    public function __construct(
        protected LoginAttemptService $attempts,
        protected TotpService $totp,
        protected RecoveryCodeService $recoveryCodes,
    ) {}

    /**
     * Mobile/Flutter API token issuance (Laravel Sanctum).
     *
     * Applies the same lockout policy as web sign-in. When the security policy
     * requires MFA for this user, an authenticator or recovery code must be sent
     * as `mfa_code`.
     */
    public function issueApiToken(Request $request, string $email, string $password, string $deviceName = 'Flutter Client', ?string $mfaCode = null): array
    {
        $user = $this->attempts->verify($email, $password, $request);

        if (SecurityPolicyService::requiresMfa($user)) {
            $code = trim((string) $mfaCode);
            $verified = $code !== '' && (
                $this->totp->verify($user->mfa_secret, $code, $user->id)
                || $this->recoveryCodes->consume($user, $code)
            );

            if (! $verified) {
                throw ValidationException::withMessages([
                    'mfa_code' => ['A valid authenticator or recovery code is required for this account.'],
                ]);
            }
        }

        return [
            'token' => $user->createToken($deviceName)->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->roles->pluck('name')->toArray(),
            ]
        ];
    }

    /**
     * Register a new user account.
     */
    public function registerUser(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * Logout web session.
     */
    public function logoutWeb(): void
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }
}
