<?php

namespace LaraSlice\Tests\Unit\Generator;

use InvalidArgumentException;
use LaraSlice\Generator\SliceNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SliceNamespaceTest extends TestCase
{
    public static function namespaces(): array
    {
        return [
            'ends in n' => ['App\\Admin', 'App\\Admin'],
            'starts with B' => ['Brand\\Slices', 'Brand\\Slices'],
            'ends in n, nested' => ['App\\Domain\\Main', 'App\\Domain\\Main'],
            'ends in x' => ['App\\Inbox', 'App\\Inbox'],
            'surrounding slashes and spaces' => [' \\App\\Slices\\ ', 'App\\Slices'],
            'trailing newline' => ["App\\Slices\n", 'App\\Slices'],
        ];
    }

    #[DataProvider('namespaces')]
    public function test_it_only_trims_whitespace_and_backslashes(string $input, string $expected): void
    {
        $this->assertSame($expected, SliceNamespace::validate($input));
    }

    public function test_it_rejects_invalid_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SliceNamespace::validate("App\\Slices'); system('id'); //");
    }
}
