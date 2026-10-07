<?php

namespace LaraSlice\Slices\Auth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Models\UserAttempt;
use LaraSlice\Slices\Users\Models\UserSecurityLog;
use LaraSlice\Slices\Users\Services\SecurityPolicyService;

/**
 * Password verification shared by web and API sign-in.
 *
 * Unknown accounts and wrong passwords get the same message and take the same
 * time, and lockout thresholds come from the security policy settings.
 */
class LoginAttemptService
{
    public const GENERIC_ERROR = 'The provided credentials do not match our records.';

    /** Hash checked for unknown accounts so they take as long as real ones. */
    private static ?string $dummyHash = null;

    /**
     * Return the user when the password is correct and the account may sign in.
     *
     * @throws ValidationException on any failure, keyed by $field
     */
    public function verify(string $identifier, string $password, Request $request, string $field = 'email'): User
    {
        $identifier = trim($identifier);
        $user = $this->findUser($identifier);

        if (! $user) {
            Hash::check($password, self::$dummyHash ??= Hash::make('laraslice-timing-equaliser'));
            $this->record($request, null, $identifier, 'unknown_account');

            throw ValidationException::withMessages([$field => self::GENERIC_ERROR]);
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            $this->record($request, $user, $user->email, 'login_locked_attempt');

            throw ValidationException::withMessages([$field => 'Too many failed sign-in attempts. Please try again later.']);
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user, $request);

            throw ValidationException::withMessages([$field => self::GENERIC_ERROR]);
        }

        if ($user->status !== 'active') {
            $this->record($request, $user, $user->email, 'login_inactive_account');

            throw ValidationException::withMessages([$field => 'Your account is currently inactive or suspended.']);
        }

        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();

        return $user;
    }

    /**
     * Whether a user who has already proven their identity (e.g. with a passkey) may sign in.
     */
    public function canSignIn(User $user): bool
    {
        return $user->status === 'active' && ! ($user->locked_until && $user->locked_until->isFuture());
    }

    public function findUser(string $identifier): ?User
    {
        if ($identifier === '') {
            return null;
        }

        return User::where('email', $identifier)
            ->orWhereHas('detail', fn ($q) => $q->where('cnic', $identifier))
            ->first();
    }

    protected function registerFailure(User $user, Request $request): void
    {
        $maxAttempts = max(1, (int) SecurityPolicyService::get('security.max_failed_attempts', 5));
        $lockoutMinutes = max(1, (int) SecurityPolicyService::get('security.lockout_minutes', 15));

        $user->failed_attempts = ($user->failed_attempts ?? 0) + 1;

        if ($user->failed_attempts >= $maxAttempts) {
            $user->locked_until = now()->addMinutes($lockoutMinutes);
            $user->save();
            $this->record($request, $user, $user->email, 'account_locked_out_max_attempts', "Account locked for {$lockoutMinutes} minutes after {$maxAttempts} failed attempts.");

            return;
        }

        $user->save();
        $this->record($request, $user, $user->email, "invalid_password ({$user->failed_attempts}/{$maxAttempts})");
    }

    protected function record(Request $request, ?User $user, string $identifier, string $reason, ?string $description = null): void
    {
        try {
            UserAttempt::record(identifier: $identifier, request: $request, reason: $reason, userId: $user?->id);

            UserSecurityLog::create([
                'user_id'              => $user?->id,
                'identifier_attempted' => $identifier,
                'event_type'           => str_starts_with($reason, 'invalid_password') ? 'login_failed' : $reason,
                'ip_address'           => $request->ip() ?: '127.0.0.1',
                'user_agent'           => $request->userAgent(),
                'payload'              => $description ? ['description' => $description] : null,
                'created_at'           => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
