<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Tests\TestCase;

/**
 * An application without its own users migration: the package creates the table.
 */
class FreshInstallWithoutHostUsersTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/vendor/laravel/sanctum/database/migrations');
        $this->artisan('migrate')->run();
    }

    public function test_package_created_users_table_uses_integer_ids_that_work_with_roles(): void
    {
        $user = $this->makeUser();
        $this->assertIsInt($user->id);

        $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
        $user->roles()->attach($role->id);

        $this->assertTrue($user->fresh()->hasRole('super-admin'));
    }
}
