<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Tests\TestCase;

/**
 * Slice Studio is off by default in a fresh install; every other page must still render.
 */
class StudioDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laraslice.wizard.enabled', false);
    }

    public function test_admin_pages_render_without_studio_routes(): void
    {
        $this->assertFalse(app('router')->has('laraslice.wizard'));
        $this->actingAs($this->makeSuperAdmin());

        foreach (['/admin/users', '/admin/roles', '/admin/settings/smtp', '/admin/settings/ai', '/admin/users/mfa', '/admin/users/settings'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }
}
