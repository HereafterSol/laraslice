<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Security;

use LaraSlice\Core\Security\Access;
use LaraSlice\Core\Security\PermissionOverrides;
use PHPUnit\Framework\TestCase;

class PermissionOverridesTest extends TestCase
{
    public function test_slug_matching_exact_and_wildcard(): void
    {
        $this->assertTrue(PermissionOverrides::slugMatches(['leaf.create'], 'leaf.create'));
        $this->assertTrue(PermissionOverrides::slugMatches(['leaf.*'], 'leaf.delete'));
        $this->assertTrue(PermissionOverrides::slugMatches(['*'], 'anything.view'));
        $this->assertFalse(PermissionOverrides::slugMatches(['leaf.*'], 'task.view'));
        $this->assertFalse(PermissionOverrides::slugMatches([], 'leaf.view'));
    }

    public function test_access_without_overrides_falls_through_to_club_grants(): void
    {
        // No user_permissions rows for this user (and no facade
        // root in the unit environment — reads must degrade to
        // "no overrides", never throw).
        $user = new class
        {
            public int $id = 999999;

            public function hasRole($role): bool
            {
                return false;
            }

            public function hasPermission(string $slug): bool
            {
                return $slug === 'leaf.create'; // the "club" grant
            }
        };

        $this->assertTrue(Access::allows($user, 'leaf.create'));
        $this->assertFalse(Access::allows($user, 'leaf.delete'));
        // The grant/revoke integration against a live DB is
        // proven in the host app (user_permissions migrated);
        // see the Tier 0 verification notes.
    }
}
