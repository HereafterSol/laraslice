<?php

namespace LaraSlice\Slices\Users\Services;

use App\Models\User as AppUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LaraSlice\Core\Security\Access;
use LaraSlice\Slices\Users\Models\User;

class SecurityPolicyService
{
    public const MFA_OFF = 'off';

    public const MFA_OPTIONAL = 'optional';

    public const MFA_PRIVILEGED = 'privileged_only';

    public const MFA_ALL = 'all';

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::remember('sec_pol_'.$key, 120, function () use ($key, $default) {
                $row = DB::table('settings')->where('key', $key)->first();

                return $row ? $row->value : $default;
            });
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function set(string $key, mixed $value, string $description = ''): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => (string) $value,
                'group' => 'security',
                'description' => $description,
                'updated_at' => now(),
            ]
        );
        Cache::forget('sec_pol_'.$key);
    }

    public static function getPrivilegedRoles(): array
    {
        $raw = self::get('security.mfa_privileged_roles', '["super-admin","admin","it-security","manager"]');
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : ['super-admin', 'admin', 'it-security', 'manager'];
    }

    public static function getAll(): array
    {
        return [
            'privileged_roles' => self::getPrivilegedRoles(),
            'mfa_enforcement' => self::get('security.mfa_enforcement', self::MFA_PRIVILEGED),
            'allow_passkeys' => filter_var(self::get('security.allow_passkeys', 'true'), FILTER_VALIDATE_BOOLEAN),
            'allow_totp' => filter_var(self::get('security.allow_totp', 'true'), FILTER_VALIDATE_BOOLEAN),
            'allow_device_code' => filter_var(self::get('security.allow_device_code', 'true'), FILTER_VALIDATE_BOOLEAN),
            'allow_recovery_codes' => filter_var(self::get('security.allow_recovery_codes', 'true'), FILTER_VALIDATE_BOOLEAN),
            'max_failed_attempts' => (int) self::get('security.max_failed_attempts', 5),
            'lockout_minutes' => (int) self::get('security.lockout_minutes', 15),
            'lockout_duration_minutes' => (int) self::get('security.lockout_minutes', 15),
            'idle_lock_minutes' => (int) self::get('security.idle_lock_minutes', 15),
            'remember_device_days' => (int) self::get('security.remember_device_days', 30),
        ];
    }

    /**
     * Determine if a user is required to complete Multi-Factor Authentication.
     */
    public static function requiresMfa(User|AppUser|Authenticatable $user): bool
    {
        $policy = self::get('security.mfa_enforcement', self::MFA_PRIVILEGED);

        return match ($policy) {
            self::MFA_OFF => false,
            self::MFA_ALL => true,
            self::MFA_PRIVILEGED => self::isPrivilegedUser($user),
            self::MFA_OPTIONAL => $user->hasMfa(),
            default => false,
        };
    }

    /**
     * Resolve the mandated/recommended MFA method (Enterprise mfa_method_for parity).
     * Privileged/official roles mandate or recommend Passkeys (WebAuthn).
     * Staff/general users default to Authenticator App (TOTP).
     */
    public static function preferredMethodFor(User|AppUser|Authenticatable $user): string
    {
        if (self::isPrivilegedUser($user)) {
            return 'webauthn';
        }

        return 'totp';
    }

    /**
     * Check if user is an administrator, security officer, or holds a privileged role.
     */
    public static function isPrivilegedUser(User|AppUser|Authenticatable $user): bool
    {
        // 1. Super Admin universal check
        if (Access::isSuperAdmin($user)) {
            return true;
        }

        // 2. Check roles pivot against dynamic configured privileged roles
        $configuredRoles = self::getPrivilegedRoles();
        try {
            if (DB::table('role_user')
                ->join('roles', 'role_user.role_id', '=', 'roles.id')
                ->where('role_user.user_id', $user->id)
                ->whereIn('roles.slug', $configuredRoles)
                ->exists()) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        // 3. Check model method if exists
        if (method_exists($user, 'hasRole')) {
            foreach ($configuredRoles as $slug) {
                if ($user->hasRole($slug)) {
                    return true;
                }
            }
        }

        // 4. If user explicitly enabled TOTP / passkeys on their account
        return $user->hasMfa();
    }
}
