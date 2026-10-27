<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Foundation\Application;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * slice:cache losslessness (foundation backlog A2,
 * 24 Oct 2026): the cache used to be built from the NAME
 * index, so every name-shadowed twin silently vanished —
 * 115 cached of 140 discovered on the demo — and a cached
 * boot then 500'd on the missing slices. The cache must
 * cover every discovered manifest, and a manifest whose
 * file cannot be resolved must refuse the write rather
 * than shrink the registry quietly.
 */
final class SliceCacheLosslessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-cache-'.uniqid();
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function manifest(string $relativeDir, string $name): SliceManifest
    {
        $dir = $this->root.'/'.$relativeDir;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/slice.yaml', "name: '{$name}'\nversion: 1.0.0\n");

        return new SliceManifest($dir.'/slice.yaml');
    }

    private function managerWith(SliceManifest ...$manifests): SliceManager
    {
        $manager = new SliceManager($this->app instanceof Application ? $this->app : app());
        $register = new ReflectionMethod($manager, 'registerManifest');
        $register->setAccessible(true);
        foreach ($manifests as $manifest) {
            $register->invoke($manager, $manifest);
        }

        return $manager;
    }

    public function test_cache_covers_same_named_twins(): void
    {
        $manager = $this->managerWith(
            $this->manifest('One/Invoices', 'Invoices'),
            $this->manifest('Two/Invoices', 'Invoices'),
            $this->manifest('One/Projects', 'Projects'),
        );

        $manager->clearCache();
        $count = $manager->cacheSlices();

        $this->assertSame(3, $count, 'every discovered manifest must be cached, twins included');
        $cached = include $manager->getCachedSlicesPath();
        $this->assertIsArray($cached);
        $this->assertCount(3, $cached);
        foreach ($cached as $path) {
            $this->assertIsString($path);
            $this->assertFileExists($path);
        }
        $manager->clearCache();
    }

    public function test_manifest_data_cache_round_trips_and_respects_freshness(): void
    {
        $manifest = $this->manifest('One/Projects', 'Projects');
        $manager = $this->managerWith($manifest);
        $manager->clearCache();
        $manager->cacheSlices();

        $dataPath = $manager->getManifestDataCachePath();
        $this->assertFileExists($dataPath);
        $data = include $dataPath;
        $this->assertIsArray($data);
        $this->assertArrayHasKey($manifest->path.'/slice.yaml', $data);

        $load = new ReflectionMethod($manager, 'loadManifest');
        $load->setAccessible(true);
        $warm = $load->invoke($manager, $manifest->path.'/slice.yaml');
        $this->assertSame('Projects', $warm->name);
        $this->assertSame($manifest->raw, $warm->raw, 'cached parse must hydrate identically');

        // Edit the file: the mtime+size guard must bypass
        // the stale entry and re-parse.
        file_put_contents($manifest->path.'/slice.yaml', "name: 'Projects'\nversion: 1.0.0\ntitle: 'Projects Renamed'\n");
        $fresh = $load->invoke($manager, $manifest->path.'/slice.yaml');
        $this->assertSame('Projects Renamed', $fresh->title);
        $manager->clearCache();
    }

    public function test_cache_refuses_when_a_manifest_file_vanishes(): void
    {
        $ghost = $this->manifest('One/Ghosts', 'Ghosts');
        unlink($ghost->path.'/slice.yaml');

        $manager = $this->managerWith($ghost);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ghosts/');
        $manager->cacheSlices();
    }
}
