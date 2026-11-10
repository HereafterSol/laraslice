<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Foundation\Application;
use LaraSlice\Commands\SliceLinksCommand;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * slice:links Check 4 precision (Cursor EVAL-B, 10 Nov 2026):
 * dual table claims split into real collisions (two PRIMARY
 * owners) vs shared composition (a parent slice modelling a
 * table another slice owns) — the old wording reported both
 * as "models write through each other" failures.
 */
final class SliceLinksTableClaimsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-links-'.uniqid();
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

    private function manifest(string $relativeDir, string $name, array $tables): SliceManifest
    {
        $dir = $this->root.'/'.$relativeDir;
        mkdir($dir, 0777, true);
        $list = implode(', ', $tables);
        file_put_contents($dir.'/slice.yaml', "name: '{$name}'\nversion: 1.0.0\ntables: [{$list}]\n");

        return new SliceManifest($dir.'/slice.yaml');
    }

    private function manager(): SliceManager
    {
        return new SliceManager($this->app instanceof Application ? $this->app : app());
    }

    public function test_parent_child_sharing_is_information_not_a_problem(): void
    {
        $manager = $this->manager();
        $orders = $this->manifest('Sales/Orders', 'Orders', ['orders', 'order_items']);
        $items = $this->manifest('Sales/OrderItems', 'OrderItems', ['order_items']);

        $result = SliceLinksCommand::classifyTableClaims([$orders, $items], $manager);

        $this->assertSame([], $result['problems']);
        $this->assertCount(1, $result['shared']);
        $this->assertSame('table:order_items', $result['shared'][0][0]);
        $this->assertStringContainsString('primary owner OrderItems', $result['shared'][0][1]);
    }

    public function test_two_primary_owners_are_a_problem(): void
    {
        $manager = $this->manager();
        $a = $this->manifest('Finance/Invoices', 'Invoices', ['invoices']);
        $b = $this->manifest('Billing/Invoices', 'Billing', ['invoices', 'payments']);

        $result = SliceLinksCommand::classifyTableClaims([$a, $b], $manager);

        $this->assertCount(1, $result['problems']);
        $this->assertSame('table:invoices', $result['problems'][0][0]);
        $this->assertStringContainsString('owned as primary table by 2', $result['problems'][0][1]);
        $this->assertSame([], $result['shared']);
    }
}
