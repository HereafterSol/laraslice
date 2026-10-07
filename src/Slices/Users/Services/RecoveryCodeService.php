<?php

namespace LaraSlice\Slices\Users\Services;

use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Users\Models\UserCode;

/**
 * Single-use MFA recovery codes. Only SHA-256 hashes are stored; the plain
 * codes are returned once, at generation time, for the user to save.
 */
class RecoveryCodeService
{
    public const COUNT = 8;

    /**
     * Replace the user's recovery codes and return the new plain codes (XXXX-XXXX).
     *
     * @return array<int, string>
     */
    public function generate(User $user): array
    {
        $user->recoveryCodes()->delete();
        UserCode::where('user_id', $user->id)->delete();

        $plain = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $hash = self::hash($raw);

            $user->recoveryCodes()->create(['code_hash' => $hash, 'created_at' => now()]);
            UserCode::create(['user_id' => $user->id, 'code_hash' => $hash, 'created_at' => now()]);
        }

        return $plain;
    }

    /**
     * Consume a recovery code. Returns true once per valid, unused code.
     */
    public function consume(User $user, string $input): bool
    {
        $normalized = self::normalize($input);
        if (strlen($normalized) < 8) {
            return false;
        }

        $hash = self::hash($normalized);

        // Rows written before v1.4.0 stored the plain code; accept them until used or regenerated
        $record = $user->recoveryCodes()
            ->whereIn('code_hash', [$hash, $normalized])
            ->whereNull('used_at')
            ->first();

        if ($record) {
            $record->update(['used_at' => now()]);
            UserCode::where('user_id', $user->id)->where('code_hash', $hash)->whereNull('used_at')->update(['used_at' => now()]);

            return true;
        }

        $code = UserCode::where('user_id', $user->id)->where('code_hash', $hash)->whereNull('used_at')->first();
        if ($code) {
            $code->update(['used_at' => now()]);

            return true;
        }

        return false;
    }

    public static function normalize(string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }
}
