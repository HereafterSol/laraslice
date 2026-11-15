<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\SliceModifier;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * F3 (Stage A, Stage C-6 fix): a slice whose plural name
 * equals its domain name (Contacts in domain Contacts)
 * resolved to the DOMAIN directory — resolveSliceDir's
 * first is_dir check matched Slices/Contacts, which is
 * the domain folder, and child generation wrote into it.
 * A directory only counts as the slice when it holds the
 * slice's Heart (slice.yaml).
 */
class ResolveSliceDirTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/ls-f3-'.uniqid();
        mkdir($this->tmp.'/Contacts/Contacts', 0777, true);
        file_put_contents($this->tmp.'/Contacts/Contacts/slice.yaml', "name: Contacts\n");
        mkdir($this->tmp.'/Widgets', 0777, true);
        file_put_contents($this->tmp.'/Widgets/slice.yaml', "name: Widgets\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
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

    private function resolve(string $name): string
    {
        $modifier = new SliceModifier($this->tmp, 'App\\Slices');
        $method = new ReflectionMethod($modifier, 'resolveSliceDir');
        $method->setAccessible(true);

        return $method->invoke($modifier, $name);
    }

    public function test_domain_named_slice_resolves_to_the_slice_not_the_domain(): void
    {
        $this->assertSame($this->tmp.'/Contacts/Contacts', $this->resolve('Contacts'));
    }

    public function test_flat_slice_still_resolves_directly(): void
    {
        $this->assertSame($this->tmp.'/Widgets', $this->resolve('Widgets'));
    }
}
