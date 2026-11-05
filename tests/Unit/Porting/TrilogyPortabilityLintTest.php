<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\TrilogyPortabilityLint;
use PHPUnit\Framework\TestCase;

class TrilogyPortabilityLintTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir().'/laraslice-portability-'.uniqid('', true);
        mkdir($this->tempRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempRoot);
        parent::tearDown();
    }

    public function test_heart_scalar_leakage_is_reported(): void
    {
        $heartDir = $this->tempRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Slices'.DIRECTORY_SEPARATOR.'Demo';
        mkdir($heartDir, 0777, true);
        file_put_contents($heartDir.DIRECTORY_SEPARATOR.'slice.yaml', <<<'YAML'
name: Demo
fields:
  -
    name: template
    type: string
    default: resources/views/form.blade.php
YAML);

        $lint = new TrilogyPortabilityLint;
        $findings = $lint->scan($this->tempRoot);

        $this->assertNotEmpty($findings);
        $this->assertSame('heart.blade_path', $findings[0]['rule']);
    }

    public function test_mind_blade_component_is_reported(): void
    {
        $porting = $this->tempRoot.DIRECTORY_SEPARATOR.'porting';
        mkdir($porting, 0777, true);
        file_put_contents($porting.DIRECTORY_SEPARATOR.'system.yaml', "navigation:\n  - label: Home\n    component: <x-ui.sidebar>\n");

        $findings = (new TrilogyPortabilityLint)->scan($this->tempRoot);

        $this->assertNotEmpty($findings);
        $this->assertSame('mind', $findings[0]['layer']);
        $this->assertSame('mind.blade_component', $findings[0]['rule']);
    }

    public function test_clean_heart_passes(): void
    {
        $heartDir = $this->tempRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Slices'.DIRECTORY_SEPARATOR.'Clean';
        mkdir($heartDir, 0777, true);
        file_put_contents($heartDir.DIRECTORY_SEPARATOR.'slice.yaml', <<<'YAML'
name: Clean
ui:
  directory:
    columns:
      - handle: name
        label: Name
YAML);

        $findings = (new TrilogyPortabilityLint)->scan($this->tempRoot);

        $this->assertSame([], $findings);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
