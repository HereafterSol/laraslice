<?php

namespace LaraSlice\Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Support\SchemaCache;
use LaraSlice\Tests\TestCase;

class PermissionQueryCountTest extends TestCase
{
    private function queriesDuring(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_repeated_permission_checks_reuse_one_lookup(): void
    {
        $user = $this->makeUserWithPermissions(['users.view', 'roles.view']);
        $this->actingAs($user);

        $first = $this->queriesDuring(fn () => $user->hasPermission('users.view'));
        $repeated = $this->queriesDuring(function () use ($user) {
            for ($i = 0; $i < 25; $i++) {
                $user->hasPermission('users.view');
                $user->hasRole('super-admin');
                Gate::allows('roles.view');
            }
        });

        $this->assertLessThanOrEqual(2, $first);
        $this->assertSame(0, $repeated, 'cached roles and permissions answer every later check');
    }

    public function test_changing_roles_is_visible_after_flushing(): void
    {
        $user = $this->makeUser();
        $this->assertFalse($user->hasRole('super-admin'));

        $role = \LaraSlice\Slices\Roles\Models\Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
        $user->roles()->attach($role->id);

        $this->assertTrue($user->flushSlicePermissionCache()->hasRole('super-admin'));
    }

    public function test_navigation_is_built_once_per_request(): void
    {
        $this->actingAs($this->makeUserWithPermissions(['users.view']));
        $manager = app(SliceManager::class);

        $manager->getNavigableSlices();
        $again = $this->queriesDuring(fn () => [$manager->getNavigableSlices(), $manager->getNavigableSlices()]);

        $this->assertSame(0, $again);
    }

    public function test_schema_checks_are_cached_and_reset_after_migrations(): void
    {
        SchemaCache::flush();
        $this->assertTrue(SchemaCache::hasColumn('users', 'email'));

        $this->assertSame(0, $this->queriesDuring(fn () => [SchemaCache::hasTable('users'), SchemaCache::hasColumn('users', 'name')]));

        $this->assertFalse(SchemaCache::hasTable('late_table'));
        \Illuminate\Support\Facades\Schema::create('late_table', fn ($t) => $t->id());
        event(new \Illuminate\Database\Events\MigrationsEnded('up'));
        $this->assertTrue(SchemaCache::hasTable('late_table'));
    }
}
