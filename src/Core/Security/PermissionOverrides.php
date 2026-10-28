<?php

declare(strict_types=1);

namespace LaraSlice\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person permission overrides — the Mind's second role
 * layer, declared in the trilogy doc ("individual people may
 * hold overrides on top of their club") and enforced here:
 * effective access = role grants ∪ override grants − override
 * revokes. Overrides never bind super personas (Access
 * short-circuits them before consulting this layer).
 *
 * Slugs may be exact (leaf.create) or prefix wildcards
 * (leaf.*). Reads are memoized per request; writes clear the
 * memo for that user.
 */
final class PermissionOverrides
{
    /** @var array<int, array{grant: list<string>, revoke: list<string>}> */
    private static array $memo = [];

    /** @return array{grant: list<string>, revoke: list<string>} */
    public static function forUser(int $userId): array
    {
        if (isset(self::$memo[$userId])) {
            return self::$memo[$userId];
        }
        $out = ['grant' => [], 'revoke' => []];
        try {
            if (Schema::hasTable('user_permissions')) {
                foreach (DB::table('user_permissions')->where('user_id', $userId)->get(['permission', 'effect']) as $row) {
                    $effect = $row->effect === 'revoke' ? 'revoke' : 'grant';
                    $out[$effect][] = (string) $row->permission;
                }
            }
        } catch (\Throwable) {
            // No table / no DB: no overrides.
        }

        return self::$memo[$userId] = $out;
    }

    public static function grants(mixed $user, string $ability): bool
    {
        if (! is_object($user) || ! isset($user->id)) {
            return false;
        }

        return self::slugMatches(self::forUser((int) $user->id)['grant'], $ability);
    }

    public static function revokes(mixed $user, string $ability): bool
    {
        if (! is_object($user) || ! isset($user->id)) {
            return false;
        }

        return self::slugMatches(self::forUser((int) $user->id)['revoke'], $ability);
    }

    /** @param list<string> $slugs */
    public static function slugMatches(array $slugs, string $ability): bool
    {
        foreach ($slugs as $slug) {
            if ($slug === $ability || $slug === '*') {
                return true;
            }
            if (str_ends_with($slug, '.*') && str_starts_with($ability, substr($slug, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    public static function set(int $userId, string $permission, string $effect, ?int $grantedBy = null, ?string $note = null): void
    {
        $effect = $effect === 'revoke' ? 'revoke' : 'grant';
        DB::table('user_permissions')->updateOrInsert(
            ['user_id' => $userId, 'permission' => $permission, 'effect' => $effect],
            ['granted_by' => $grantedBy, 'note' => $note, 'updated_at' => now(), 'created_at' => now()],
        );
        unset(self::$memo[$userId]);
    }

    public static function clear(int $userId, string $permission, string $effect): void
    {
        DB::table('user_permissions')
            ->where('user_id', $userId)->where('permission', $permission)->where('effect', $effect)
            ->delete();
        unset(self::$memo[$userId]);
    }

    public static function forgetMemo(): void
    {
        self::$memo = [];
    }
}
