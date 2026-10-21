<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * G6 step 2: slices generated into a nested path get path-qualified
 * route names (erp_erp_work_tasks.*) across their routes file,
 * controller prefix and blade references, so same-named slices never
 * share route names. Root slices keep the plain plural name.
 */
class RouteNameQualificationTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir().'/laraslice-g6-'.uniqid();
        mkdir($this->baseDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->baseDir);
        parent::tearDown();
    }

    public function test_nested_slice_gets_path_qualified_route_names(): void
    {
        $path = (new SliceGenerator($this->baseDir, 'App\\Slices'))->generate('Permit', [
            ['name' => 'title', 'type' => 'string'],
        ], false, ['table' => 'permits', 'domain' => 'Work', 'app' => 'ERP', 'super_domain' => 'ERP']);

        $routes = file_get_contents($path.'/Routes/web.php');
        $this->assertStringContainsString("->name('erp_erp_work_permits.')", $routes);

        $controller = file_get_contents($path.'/Controllers/PermitWebController.php');
        $this->assertStringContainsString("return 'erp_erp_work_permits.';", $controller);

        $index = file_get_contents($path.'/Resources/views/index.blade.php');
        $this->assertStringContainsString("route('erp_erp_work_permits.create')", $index);
        $this->assertStringNotContainsString("route('permits.", $index);
    }

    public function test_root_slice_keeps_plain_route_names(): void
    {
        $path = (new SliceGenerator($this->baseDir, 'App\\Slices'))->generate('License', [
            ['name' => 'title', 'type' => 'string'],
        ], false, ['table' => 'licenses']);

        $routes = file_get_contents($path.'/Routes/web.php');
        $this->assertStringContainsString("->name('licenses.')", $routes);
    }
}
