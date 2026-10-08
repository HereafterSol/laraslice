<?php

namespace LaraSlice\Tests\Feature\Performance;

use Illuminate\Support\Facades\DB;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Tests\TestCase;

/**
 * Admin pages must issue the same number of queries whether they show 2 rows or 12.
 */
class ListingQueryCountTest extends TestCase
{
    private function queriesFor(string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function addUsersWithRoles(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $user = $this->makeUser();
            $role = Role::create(['name' => 'Team ' . $user->id, 'slug' => 'team-' . $user->id]);
            $user->roles()->attach($role->id);
        }
    }

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return [
            'users listing' => ['/admin/users'],
            'roles listing' => ['/admin/roles'],
            'mfa overview' => ['/admin/users/mfa'],
            'metrics' => ['/admin/users/metrics'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_query_count_does_not_grow_with_rows(string $uri): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->addUsersWithRoles(2);
        $this->get($uri)->assertOk(); // warm per-process caches

        $few = $this->queriesFor($uri);
        $this->addUsersWithRoles(10);
        $many = $this->queriesFor($uri);

        $this->assertSame($few, $many, "{$uri}: {$few} queries with 3 users, {$many} with 13");
    }
}
