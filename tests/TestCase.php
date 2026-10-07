<?php

namespace LaraSlice\Tests;

use LaraSlice\LaraSliceServiceProvider;
use LaraSlice\Slices\Roles\Models\Permission;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Slices\Users\Models\User;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots a Laravel application with LaraSlice and its core slices installed,
 * backed by an in-memory SQLite database.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \MallardDuck\LucideIcons\BladeLucideIconsServiceProvider::class,
            SanctumServiceProvider::class,
            LaraSliceServiceProvider::class,
        ];
    }

    /**
     * Mirror a host app after `slice:install`: the starter layout and BlatUI components are published.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['view']->addLocation(dirname(__DIR__) . '/resources/stubs/starter/views');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('laraslice.slices_path', sys_get_temp_dir() . '/laraslice-test-app-slices');
        $app['config']->set('laraslice.wizard.enabled', true);
        $app['config']->set('laraslice.ai.mcp_server.enabled', true);
    }

    /**
     * Run the host application's migrations first (as in a real install), then the package's.
     * The in-memory database is rebuilt for every test.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__) . '/vendor/laravel/sanctum/database/migrations');
        $this->artisan('migrate')->run();
    }

    protected function makeUser(array $attributes = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_merge([
            'name' => "Test User {$sequence}",
            'email' => "user{$sequence}@example.test",
            'password' => 'correct-horse-battery',
            'status' => 'active',
        ], $attributes));
    }

    /**
     * Create a user holding a role that grants exactly the given permission slugs.
     */
    protected function makeUserWithPermissions(array $slugs): User
    {
        $user = $this->makeUser();
        $role = Role::create(['name' => 'Role ' . $user->id, 'slug' => 'role-' . $user->id]);

        foreach ($slugs as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    protected function makeSuperAdmin(): User
    {
        $user = $this->makeUser();
        $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
