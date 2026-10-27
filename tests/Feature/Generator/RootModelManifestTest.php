<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression (fresh-port verification of v2.0.0): the generator wrote
 * slice.yaml manifests with NO `models:` section at all, so no root
 * model was ever declared. Manifest-driven features that resolve the
 * root model from `models[]` (dashboard counters, porter collision
 * detection, repair field collection) were blind on every generated
 * slice — a ported Projects slice needed a hand-added root entry
 * before its dashboard counters could bind. The generator is the
 * shared emitter: the porter's scaffoldSlices() delegates manifest
 * writing to it, so the fix belongs here.
 */
class RootModelManifestTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-rootmodel-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->slicesPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->slicesPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->slicesPath);
        }
        parent::tearDown();
    }

    public function test_generated_manifest_declares_root_model(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Project', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
            ['name' => 'status', 'type' => 'string', 'label' => 'Status'],
        ], false, [
            'table' => 'projects',
            'relations' => [
                ['name' => 'members', 'type' => 'hasMany', 'model' => 'project_member', 'foreign_key' => 'project_id'],
            ],
        ]);

        $manifest = Yaml::parseFile($path.'/slice.yaml');

        $this->assertArrayHasKey('models', $manifest, 'generated manifest must declare its models');
        $this->assertIsArray($manifest['models']);
        $roots = array_values(array_filter(
            $manifest['models'],
            static fn ($m) => is_array($m) && ($m['root'] ?? false) === true
        ));
        $this->assertCount(1, $roots, 'exactly one root model must be declared');

        $root = $roots[0];
        $this->assertSame('project', $root['handle']);
        $this->assertSame('projects', $root['table']);

        $fieldHandles = array_column($root['fields'] ?? [], 'handle');
        $this->assertSame(['title', 'status'], $fieldHandles);

        $relationNames = array_column($root['relations'] ?? [], 'name');
        $this->assertContains('members', $relationNames, 'declared relations must land on the root model entry');
    }

    public function test_generated_manifest_root_model_defaults_table_from_slice_name(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Purchase Order', [
            ['name' => 'reference', 'type' => 'string', 'label' => 'Reference'],
        ]);

        $manifest = Yaml::parseFile($path.'/slice.yaml');

        $roots = array_values(array_filter(
            (array) ($manifest['models'] ?? []),
            static fn ($m) => is_array($m) && ($m['root'] ?? false) === true
        ));
        $this->assertCount(1, $roots);
        $this->assertSame('purchase_order', $roots[0]['handle']);
        $this->assertSame('purchase_orders', $roots[0]['table']);
    }
}
