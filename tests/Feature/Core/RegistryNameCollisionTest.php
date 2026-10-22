<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Foundation\Application;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * Registry name collisions (the twin-name defect): slices whose
 * names differ only by spacing ('Email Templates' vs
 * 'EmailTemplates') both register, but lookups must resolve
 * deterministically — never by scan order — and the collision
 * must be recorded for slice:links to report.
 */
final class RegistryNameCollisionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-registry-'.uniqid();
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

    public function test_normalized_twins_are_recorded_as_a_collision(): void
    {
        $manager = $this->managerWith(
            $this->manifest('Accounting/Operations/EmailTemplates', 'Email Templates'),
            $this->manifest('ERP/Accounting/Operations/EmailTemplates', 'EmailTemplates'),
        );

        $collisions = $manager->nameCollisions();
        $this->assertCount(1, $collisions);
        $this->assertSame('normalized-name', $collisions[0]['type']);
        $this->assertSame('emailtemplates', $collisions[0]['key']);
        $this->assertCount(2, $collisions[0]['slices']);
        // Both slices stay registered under their raw names.
        $this->assertNotNull($manager->getSlice('Email Templates'));
        $this->assertNotNull($manager->getSlice('EmailTemplates'));
    }

    public function test_duplicate_raw_names_are_recorded(): void
    {
        $manager = $this->managerWith(
            $this->manifest('One/Widgets', 'Widgets'),
            $this->manifest('Two/Widgets', 'Widgets'),
        );

        $types = array_column($manager->nameCollisions(), 'type');
        $this->assertContains('duplicate-name', $types);
    }

    public function test_ambiguous_lookup_is_deterministic_regardless_of_registration_order(): void
    {
        $a = $this->manifest('Accounting/Operations/EmailTemplates', 'Email Templates');
        $b = $this->manifest('ERP/Accounting/Operations/EmailTemplates', 'EmailTemplates');

        $forward = $this->managerWith($a, $b)->getSlice('EmailTemplate');
        $reverse = $this->managerWith($b, $a)->getSlice('EmailTemplate');

        $this->assertNotNull($forward);
        $this->assertNotNull($reverse);
        $this->assertSame($forward->path, $reverse->path, 'the winner must not depend on scan order');
        // The shorter (structurally closer) tree wins the tie-break.
        $this->assertSame($a->path, $forward->path);
    }

    public function test_unique_names_record_no_collision(): void
    {
        $manager = $this->managerWith(
            $this->manifest('HR/Leaves', 'Leaves'),
            $this->manifest('Work/Tasks', 'Tasks'),
        );

        $this->assertSame([], $manager->nameCollisions());
        $this->assertSame('Leaves', $manager->getSlice('Leaves')?->name);
    }
}
