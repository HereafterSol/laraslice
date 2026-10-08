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
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laraslice-manifest-' . bin2hex(random_bytes(6));
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
        $file = $this->dir . '/slice.json';
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

        $raw = json_decode(file_get_contents($path . '/slice.json'), true);
        $this->assertSame(['sku', 'barcode'], array_keys($raw['fields']));
        $this->assertSame('SKU', $raw['fields']['sku']['label']);
    }

    public function test_writes_fail_loudly_instead_of_silently(): void
    {
        $this->expectException(\JsonException::class);
        ManifestRepository::write($this->dir . '/slice.json', ['name' => "\xB1\x31"]);
    }
}
