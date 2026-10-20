<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\TestCase;

class ManifestRepositoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-manifest-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_list_and_mixed_field_formats_become_a_name_keyed_map(): void
    {
        $file = $this->dir.'/slice.json';
        // What older versions produced: a generator list, later extended by the modifier with a keyed entry
        file_put_contents($file, json_encode([
            'name' => 'Products',
            'fields' => ['0' => ['name' => 'title', 'type' => 'string'], 'sku' => ['type' => 'string']],
            'child_fields' => ['variants' => [['name' => 'size', 'type' => 'string']]],
        ]));

        $manifest = ManifestRepository::read($file);

        $this->assertSame(['title', 'sku'], array_keys($manifest['fields']));
        $this->assertSame('sku', $manifest['fields']['sku']['name']);
        $this->assertSame(['size'], array_keys($manifest['child_fields']['variants']));
    }

    public function test_generated_and_modified_manifests_stay_name_keyed(): void
    {
        $path = (new SliceGenerator($this->dir, 'App\\Slices'))->generate('Product', [
            ['name' => 'sku', 'type' => 'string', 'label' => 'SKU'],
        ]);
        (new SliceModifier($this->dir, 'App\\Slices'))->addFieldsBatch('Product', [['name' => 'barcode', 'type' => 'string']]);

        $raw = json_decode(file_get_contents($path.'/slice.json'), true);
        $this->assertSame(['sku', 'barcode'], array_keys($raw['fields']));
        $this->assertSame('SKU', $raw['fields']['sku']['label']);
    }

    public function test_writes_fail_loudly_instead_of_silently(): void
    {
        $this->expectException(\JsonException::class);
        ManifestRepository::write($this->dir.'/slice.json', ['name' => "\xB1\x31"]);
    }

    public function test_phase1_yaml_only_slice_loads(): void
    {
        $yaml = "name: Tasks\nhandle: tasks\nfields:\n  title:\n    name: title\n    type: string\n";
        file_put_contents($this->dir.'/slice.yaml', $yaml);

        $manifest = ManifestRepository::readFromSliceDir($this->dir);

        $this->assertSame('Tasks', $manifest['name']);
        $this->assertArrayHasKey('title', $manifest['fields']);
        $this->assertFileDoesNotExist($this->dir.'/slice.json');
    }

    public function test_phase1_json_only_migrates_to_yaml_and_keeps_json(): void
    {
        file_put_contents($this->dir.'/slice.json', json_encode([
            'name' => 'Invoices',
            'fields' => ['total' => ['name' => 'total', 'type' => 'decimal']],
        ]));

        $manifest = ManifestRepository::readFromSliceDir($this->dir);

        $this->assertSame('Invoices', $manifest['name']);
        $this->assertFileExists($this->dir.'/slice.json');
        $this->assertFileExists($this->dir.'/slice.yaml');

        // Round-trip: YAML parses back to the same manifest
        $again = ManifestRepository::read($this->dir.'/slice.yaml');
        $this->assertSame($manifest['name'], $again['name']);
        $this->assertSame(array_keys($manifest['fields']), array_keys($again['fields']));
    }

    public function test_phase1_both_present_yaml_wins(): void
    {
        file_put_contents($this->dir.'/slice.json', json_encode([
            'name' => 'FromJson',
            'fields' => ['a' => ['name' => 'a', 'type' => 'string']],
        ]));
        file_put_contents($this->dir.'/slice.yaml', "name: FromYaml\nfields:\n  b:\n    name: b\n    type: string\n");

        $manifest = ManifestRepository::readFromSliceDir($this->dir);

        $this->assertSame('FromYaml', $manifest['name']);
        $this->assertArrayHasKey('b', $manifest['fields']);
        $this->assertArrayNotHasKey('a', $manifest['fields']);
        // JSON was not deleted
        $this->assertFileExists($this->dir.'/slice.json');
    }

    public function test_phase1_malformed_yaml_falls_back_to_json(): void
    {
        file_put_contents($this->dir.'/slice.yaml', "name: [unclosed\n  bad: : :\n");
        file_put_contents($this->dir.'/slice.json', json_encode([
            'name' => 'Fallback',
            'fields' => ['x' => ['name' => 'x', 'type' => 'string']],
        ]));

        $manifest = ManifestRepository::readFromSliceDir($this->dir);

        $this->assertSame('Fallback', $manifest['name']);
        $this->assertFileExists($this->dir.'/slice.json');
    }

    public function test_phase2_write_yaml_only_does_not_create_json(): void
    {
        ManifestRepository::writeYaml($this->dir, [
            'name' => 'Orders',
            'fields' => ['sku' => ['name' => 'sku', 'type' => 'string']],
        ]);

        $this->assertFileExists($this->dir.'/slice.yaml');
        $this->assertFileDoesNotExist($this->dir.'/slice.json');

        $manifest = ManifestRepository::readFromSliceDir($this->dir);
        $this->assertSame('Orders', $manifest['name']);
    }

    public function test_phase2_migrate_dry_run_does_not_write(): void
    {
        file_put_contents($this->dir.'/slice.json', json_encode([
            'name' => 'Legacy',
            'fields' => ['a' => ['name' => 'a', 'type' => 'string']],
        ]));

        $result = ManifestRepository::migrateJsonToYaml($this->dir, true, false);

        $this->assertSame(1, $result['converted']);
        $this->assertFileDoesNotExist($this->dir.'/slice.yaml');
        $this->assertFileExists($this->dir.'/slice.json');
    }

    public function test_phase2_migrate_converts_and_keeps_json_unless_delete(): void
    {
        file_put_contents($this->dir.'/slice.json', json_encode([
            'name' => 'Legacy',
            'fields' => ['a' => ['name' => 'a', 'type' => 'string']],
        ]));

        $result = ManifestRepository::migrateJsonToYaml($this->dir, false, false);
        $this->assertSame(1, $result['converted']);
        $this->assertFileExists($this->dir.'/slice.yaml');
        $this->assertFileExists($this->dir.'/slice.json');

        $deleted = ManifestRepository::migrateJsonToYaml($this->dir, false, true);
        $this->assertSame(1, $deleted['deleted']);
        $this->assertFileDoesNotExist($this->dir.'/slice.json');
    }
}
