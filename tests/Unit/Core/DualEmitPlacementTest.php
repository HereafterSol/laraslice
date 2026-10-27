<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Core\Discovery\SliceManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DualEmitPlacementTest extends TestCase
{
    private function dualEmit(string $blade): ?string
    {
        $m = new ReflectionMethod(SliceManager::class, 'dualEmitPlacementCluster');
        $m->setAccessible(true);

        return $m->invoke((new \ReflectionClass(SliceManager::class))->newInstanceWithoutConstructor(), $blade);
    }

    private function shapeA(): string
    {
        return <<<'BLADE'
<div class="flex items-center gap-3 pb-2 border-b border-border/40">
        <h1 class="text-base font-medium text-foreground">Things</h1>
        <div class="flex items-center gap-1.5 text-xs text-muted-foreground font-medium">
            <a href="/">Home</a>
            <span>•</span>
            <span class="text-foreground">Things</span>
        </div>
    </div>
    <x-ui.data-table>
        <x-slot:below-toolbar>
            @if($can['create'] ?? true)
            <x-ui.button href="/things/create" as="a" size="sm"><span>Add Thing</span></x-ui.button>
            @endif
            <button type="button" onclick="window.dispatchEvent(new CustomEvent('export-table'))"><span>Export</span></button>
        </x-slot:below-toolbar>
    </x-ui.data-table>
BLADE;
    }

    public function test_shape_a_gets_dual_emission(): void
    {
        $out = $this->dualEmit($this->shapeA());
        $this->assertNotNull($out);
        $this->assertStringContainsString("=== 'header-right'", $out);
        $this->assertStringContainsString("!== 'header-right'", $out);
        // Add appears twice in source (header + band), renders once per placement.
        $this->assertSame(2, substr_count($out, 'Add Thing'));
        // Idempotent: a second pass changes nothing.
        $this->assertNull($this->dualEmit($out));
    }

    public function test_seed_block_is_stripped_from_the_header_copy_only(): void
    {
        $blade = str_replace(
            '</x-slot:below-toolbar>',
            <<<'BLADE'
            @if(($demoSeed ?? false) && ($can['seed'] ?? true))
                <form action="/seed" method="POST"><button type="submit"><span>Seed Demo</span></button></form>
            @endif
        </x-slot:below-toolbar>
BLADE,
            $this->shapeA()
        );
        $out = $this->dualEmit($blade);
        $this->assertNotNull($out);
        // Once in source (band only) — the header copy must not carry it.
        $this->assertSame(1, substr_count($out, 'Seed Demo'));
    }

    private function quadEmit(string $blade): ?string
    {
        $m = new ReflectionMethod(SliceManager::class, 'quadEmitPlacementCluster');
        $m->setAccessible(true);

        return $m->invoke((new \ReflectionClass(SliceManager::class))->newInstanceWithoutConstructor(), $blade);
    }

    public function test_quad_emission_adds_command_bar_and_toolbar_sites(): void
    {
        $dual = $this->dualEmit($this->shapeA());
        $this->assertNotNull($dual);
        // (ah) needs the quick-filters slot the real blades carry.
        $dual = str_replace('<x-ui.data-table>', '<x-ui.data-table>\n            <x-slot:quick-filters></x-slot:quick-filters>', $dual);
        $quad = $this->quadEmit($dual);
        $this->assertNotNull($quad);
        foreach (['header-right', 'command-bar', 'toolbar-left', 'below-facets-left'] as $placement) {
            $this->assertStringContainsString("=== '{$placement}'", $quad);
        }
        $this->assertStringContainsString('<x-slot:toolbar-lead>', $quad);
        $this->assertStringNotContainsString("!== 'header-right'", $quad);
        // Cluster now lives at 4 sites in source.
        $this->assertSame(4, substr_count($quad, 'Add Thing'));
        // Idempotent.
        $this->assertNull($this->quadEmit($quad));
    }

    public function test_quad_requires_dual_emission_first(): void
    {
        $this->assertNull($this->quadEmit($this->shapeA()));
    }

    public function test_blades_without_the_slot_or_title_tail_are_untouched(): void
    {
        $this->assertNull($this->dualEmit('<div><h1>Hi</h1></div>'));
        $noTail = str_replace('<span class="text-foreground">Things</span>', '<span>Things</span>', $this->shapeA());
        $this->assertNull($this->dualEmit($noTail));
    }
}
