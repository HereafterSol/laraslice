<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\Blade;
use LaraSlice\Tests\TestCase;

/**
 * Blaze safety net: the starter stubs annotate slot-free
 * leaf components with @blaze(memo: true). Without the
 * livewire/blaze package installed, the annotation must
 * compile away to nothing — an unknown Blade directive
 * would otherwise leak into rendered HTML as literal text.
 * (The test app does not install Blaze, which is exactly
 * the case under test.)
 */
final class BlazeFallbackTest extends TestCase
{
    public function test_blaze_annotation_compiles_away_without_the_package(): void
    {
        $this->assertFalse(class_exists(\Livewire\Blaze\Blaze::class));

        $compiled = Blade::compileString("@blaze(memo: true)\n<span>ok</span>");

        $this->assertStringNotContainsString('@blaze', $compiled);
        $this->assertStringContainsString('<span>ok</span>', $compiled);
    }
}
