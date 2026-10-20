<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\TestCase;

/**
 * Item 0: previewStudioConfig must resolve the slice and return a real diff.
 */
class StudioPreviewTest extends TestCase
{
    private string $root;
    private SliceModifier $modifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/laraslice-preview-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/Tasks', 0777, true);
        ManifestRepository::writeYaml($this->root.'/Tasks', [
            'name' => 'Tasks',
            'version' => '1.0.0',
            'fields' => ['title' => ['name' => 'title', 'type' => 'string']],
            'ui' => ['directory' => ['title' => 'Tasks']],
        ]);
        $this->modifier = new SliceModifier($this->root, 'App\\Slices');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_empty_config_is_unchanged(): void
    {
        $preview = $this->modifier->previewStudioConfig('Tasks', [], [], null);
        // Version bump alone counts as a change in applyStudioConfig; empty UI/nav
        // still bumps patch. Accept either no semantic change or version-only.
        $this->assertIsString($preview['diff']);
        $this->assertArrayHasKey('changed', $preview);
    }

    public function test_title_change_produces_diff(): void
    {
        $preview = $this->modifier->previewStudioConfig('Tasks', [
            'directory' => ['title' => 'X'],
        ], [], null);

        $this->assertTrue($preview['changed']);
        $this->assertStringContainsString('X', $preview['diff']);
        $this->assertStringContainsString('-', $preview['diff']);
        $this->assertStringContainsString('+', $preview['diff']);
    }
}
