<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\Kit;
use PHPUnit\Framework\TestCase;

class KitTest extends TestCase
{
    public function test_param_wins_over_mind_and_config(): void
    {
        $this->assertSame('replica', Kit::resolve('replica', null, null));
        $this->assertNull(Kit::resolve('standard', 'replica', 'replica'));
    }

    public function test_mind_declaration_beats_config(): void
    {
        $this->assertSame('replica', Kit::resolve(null, 'replica', null));
        $this->assertSame('replica', Kit::resolve(null, null, 'replica'));
        $this->assertNull(Kit::resolve(null, null, null));
    }

    public function test_unknown_kit_names_fall_back_to_house(): void
    {
        $this->assertNull(Kit::resolve('material', 'material', 'material'));
        $this->assertNull(Kit::resolve(null, ['not-a-string'], null));
    }

    public function test_replica_stylesheet_path(): void
    {
        $this->assertSame('css/replica-kit.css', Kit::stylesheet('replica'));
        $this->assertNull(Kit::stylesheet(null));
    }

    public function test_the_kit_stylesheet_ships_with_the_package(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3).'/resources/kits/replica.css');
    }
}
