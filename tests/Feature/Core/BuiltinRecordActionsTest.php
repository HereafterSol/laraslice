<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use LaraSlice\Support\RecordActions;
use LaraSlice\Tests\TestCase;

/**
 * D-083: the built-in Duplicate + Print record actions.
 * Every record FORM carries both (Desk's form menu does);
 * directory rows do not gain them; a slice may suppress
 * either via ui.record_actions, and a same-named declared
 * action replaces the built-in.
 */
class BuiltinRecordActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Anchor the 'users' prefix on the package's Users
        // slice (sliceForRoutePrefix resolves by controller
        // namespace) and give it a create route if the test
        // app did not register one.
        if (! Route::has('users.index')) {
            Route::get('/stub-users', 'LaraSlice\Slices\Users\Controllers\UserController@index')->name('users.index');
        }
        if (! Route::has('users.create')) {
            Route::get('/stub-users/create', 'LaraSlice\Slices\Users\Controllers\UserController@create')->name('users.create');
        }
        $this->actingAs($this->makeSuperAdmin());
    }

    public function test_form_context_carries_duplicate_and_print_builtins(): void
    {
        $this->assertTrue(Route::has('laraslice.record_print'));
        $actions = RecordActions::urlsFor('users', 7);
        $byName = [];
        foreach ($actions as $action) {
            $byName[$action['name']] = $action;
        }
        $this->assertArrayHasKey('duplicate', $byName);
        $this->assertArrayHasKey('print', $byName);
        $this->assertSame('link', $byName['duplicate']['mode']);
        $this->assertStringContainsString('duplicate_from=7', $byName['duplicate']['url']);
        $this->assertSame('link', $byName['print']['mode']);
        $this->assertSame('_blank', $byName['print']['target']);
        $this->assertStringContainsString('record-print/users/7', $byName['print']['url']);
    }

    public function test_directory_context_has_no_builtins(): void
    {
        $actions = RecordActions::urlsFor('users', 7, 'directory');
        $names = array_column($actions, 'name');
        $this->assertNotContains('duplicate', $names);
        $this->assertNotContains('print', $names);
    }

    public function test_print_route_is_registered_for_the_controller(): void
    {
        $route = Route::getRoutes()->getByName('laraslice.record_print');
        $this->assertNotNull($route);
        $this->assertSame('laraslice/record-print/{prefix}/{id}', $route->uri());
    }
}
