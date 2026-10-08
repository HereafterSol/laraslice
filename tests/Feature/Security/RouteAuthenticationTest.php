<?php

namespace LaraSlice\Tests\Feature\Security;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use LaraSlice\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RouteAuthenticationTest extends TestCase
{
    public static function guardedWebRoutes(): array
    {
        return [
            'users index' => ['GET', '/admin/users'],
            'users store' => ['POST', '/admin/users'],
            'users mfa policy' => ['POST', '/admin/users/mfa/policy'],
            'account settings' => ['GET', '/account/settings'],
            'account profile update' => ['POST', '/account/settings/profile'],
            'passkey register options' => ['GET', '/security/settings/passkey/options'],
            'roles index' => ['GET', '/admin/roles'],
            'roles store' => ['POST', '/admin/roles'],
            'smtp settings' => ['GET', '/admin/settings/smtp'],
            'smtp save' => ['POST', '/admin/settings/smtp'],
            'ai settings' => ['GET', '/admin/settings/ai'],
            'ai settings save' => ['POST', '/admin/settings/ai'],
            'ai chat' => ['POST', '/laraslice/ai/chat'],
            'ai config' => ['POST', '/laraslice/ai/config'],
            'ai record create' => ['POST', '/laraslice/ai/record-create'],
            'ai test' => ['POST', '/laraslice/ai/test'],
            'ai context' => ['GET', '/laraslice/ai/context'],
            'wizard' => ['GET', '/laraslice/wizard'],
            'wizard destroy' => ['POST', '/laraslice/wizard/destroy-slice'],
        ];
    }

    #[DataProvider('guardedWebRoutes')]
    public function test_guests_cannot_reach_protected_web_routes(string $method, string $uri): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->json($method, $uri, ['table' => 'users', 'data' => ['email' => 'x@y.z']]);

        $this->assertContains($response->getStatusCode(), [401, 403], "{$method} {$uri} returned {$response->getStatusCode()}");
    }

    public static function guardedApiRoutes(): array
    {
        return [
            'users list' => ['POST', '/api/users/list'],
            'users show' => ['GET', '/api/users/1'],
            'users save' => ['POST', '/api/users/save'],
            'users delete' => ['DELETE', '/api/users/1'],
            'roles list' => ['POST', '/api/roles/list'],
            'roles save' => ['POST', '/api/roles/save'],
            'smtp read' => ['GET', '/api/settings/smtp'],
            'smtp update' => ['POST', '/api/settings/smtp'],
        ];
    }

    #[DataProvider('guardedApiRoutes')]
    public function test_guests_cannot_reach_protected_api_routes(string $method, string $uri): void
    {
        $response = $this->json($method, $uri);

        $this->assertSame(401, $response->getStatusCode(), "{$method} {$uri} returned {$response->getStatusCode()}");
    }

    public function test_route_names_are_unique_so_route_cache_works(): void
    {
        $names = [];
        foreach (app('router')->getRoutes() as $route) {
            if ($name = $route->getName()) {
                $names[$name] = ($names[$name] ?? 0) + 1;
            }
        }

        $this->assertSame([], array_keys(array_filter($names, fn ($count) => $count > 1)));
    }

    public function test_mcp_endpoint_rejects_guests_and_get_requests(): void
    {
        $this->get('/.well-known/mcp?method=tools/list')->assertStatus(405);
        $this->postJson('/.well-known/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
    }
}
