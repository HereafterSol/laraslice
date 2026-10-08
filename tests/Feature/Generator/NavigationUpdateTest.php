<?php

namespace LaraSlice\Tests\Feature\Generator;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceModifier;
use LaraSlice\Tests\TestCase;

class NavigationUpdateTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laraslice-nav-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->slicesPath);
        parent::tearDown();
    }

    /** Register a routes file on a fresh router and return its route names and redirect URIs. */
    private function loadRoutes(string $file): array
    {
        $original = app('router');
        $router = new Router(app('events'), app());
        Route::swap($router);

        try {
            require $file;
        } finally {
            Route::swap($original);
        }

        $names = [];
        $uris = [];
        // getRoutes() is what route:cache compiles: routes sharing a method and URI collapse to one
        foreach ($router->getRoutes() as $route) {
            $uris[] = $route->uri();
            if ($route->getName()) {
                $names[] = $route->getName();
            }
        }

        return [$names, $uris];
    }

    public function test_changing_the_url_moves_routes_without_duplicate_names(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice', [], false, ['domain' => 'Billing']);
        $modifier = new SliceModifier($this->slicesPath, 'App\\Slices');

        $modifier->updateNavigation('Invoice', ['url' => '/finance/invoices']);
        $modifier->updateNavigation('Invoice', ['url' => '/accounts/invoices']);

        [$names, $uris] = $this->loadRoutes($path . '/Routes/web.php');

        $this->assertSame(array_unique($names), $names, 'route names must stay unique for route:cache');
        $this->assertContains('invoices.index', $names);
        $this->assertContains('accounts/invoices', $uris);
        $this->assertNotContains('finance/invoices/create', $uris, 'the intermediate prefix is gone');

        $routes = file_get_contents($path . '/Routes/web.php');
        $this->assertSame(1, substr_count($routes, '// laraslice:navigation-redirect'));
        $this->assertStringContainsString("Route::redirect('finance/invoices', '/accounts/invoices', 301);", $routes);
        $this->assertStringContainsString("Route::redirect('invoices', '/accounts/invoices');", $routes);
        token_get_all($routes, TOKEN_PARSE);
    }

    public function test_generated_routes_survive_route_caching(): void
    {
        $blueprint = (new \LaraSlice\Blueprint\BlueprintValidator())->validate(
            (new \LaraSlice\Blueprint\BlueprintLoader())->load(dirname(__DIR__, 3) . '/blueprints/examples/service-desk.slice.yaml')
        );
        $plan = (new \LaraSlice\Blueprint\BlueprintPlanner())->plan($blueprint, $this->slicesPath);
        $target = (new \LaraSlice\Blueprint\BlueprintApplier())->apply($blueprint, $plan, $this->slicesPath, 'App\Slices');

        [$names] = $this->loadRoutes($target . '/Routes/web.php');

        foreach (['service_desks.index', 'service_desks.edit', 'tickets.index', 'service_desks.tickets.index'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertNotContains('service_desks.tickets.show', $names, 'controllers have no show() action');
    }

    public function test_children_and_unspecified_settings_are_kept(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice');
        $manifestFile = $path . '/slice.json';
        $manifest = json_decode(file_get_contents($manifestFile), true);
        $manifest['navigation']['children'] = [['label' => 'Overdue', 'route' => 'invoices.index']];
        $manifest['navigation']['icon'] = 'receipt';
        file_put_contents($manifestFile, json_encode($manifest));

        $nav = (new SliceModifier($this->slicesPath, 'App\\Slices'))->updateNavigation('Invoice', ['title' => 'Bills']);

        $this->assertSame('Bills', $nav['title']);
        $this->assertSame('receipt', $nav['icon']);
        $this->assertSame([['label' => 'Overdue', 'route' => 'invoices.index']], $nav['children']);
    }

    public function test_icon_and_order_changes_are_logged(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice');

        (new SliceModifier($this->slicesPath, 'App\\Slices'))->updateNavigation('Invoice', ['icon' => 'receipt', 'order' => 3]);

        $history = json_decode(file_get_contents($path . '/slice.json'), true)['version_history'];
        $last = end($history)['description'];
        $this->assertStringContainsString("Icon changed to 'receipt'", $last);
        $this->assertStringContainsString('Menu order set to 3', $last);
    }

    public function test_core_slices_in_the_package_cannot_be_modified(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('core LaraSlice slice');

        (new SliceModifier($this->slicesPath, 'App\\Slices'))->updateNavigation('Users', ['url' => '/people']);
    }
}
