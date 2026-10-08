<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Routing\Route;
use LaraSlice\Tests\TestCase;

/**
 * A super admin can open every parameterless LaraSlice page without a server error.
 */
class RouteSmokeTest extends TestCase
{
    /** Routes that need a browser, an external service, or a pending login step */
    private const SKIP = ['logout', 'lockscreen', '.well-known', 'passkey/options', 'mfa/challenge', 'mfa/enroll'];

    public function test_every_get_page_renders_for_a_super_admin(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $failures = [];
        $visited = 0;

        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')
                || ! str_contains((string) $route->getActionName(), 'LaraSlice')) {
                continue;
            }
            foreach (self::SKIP as $skip) {
                if (str_contains($uri, $skip)) {
                    continue 2;
                }
            }

            $visited++;
            $status = $this->get('/'.ltrim($uri, '/'))->getStatusCode();
            if ($status >= 500) {
                $failures[] = "{$status} GET /{$uri}";
            }
        }

        $this->assertGreaterThan(25, $visited, 'the route filter must not skip the admin pages');
        $this->assertSame([], $failures);
    }
}
