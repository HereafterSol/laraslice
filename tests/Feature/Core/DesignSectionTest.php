<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Tests\TestCase;

/**
 * The Design panel markup split, pinned: the installed-slice
 * catalog ships ONCE as compact data and both option lists
 * render from it client-side (the server used to repeat
 * ~160 <option> elements in two places), the DB table list
 * renders from data too, and the Porting Studio modal's
 * ~100 KB of review markup loads lazily on first open
 * instead of riding in every Design fragment.
 */
final class DesignSectionTest extends TestCase
{
    private function panel(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/partials/design-panel.blade.php');
    }

    private function factory(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/resources/assets/design-factory.js');
    }

    public function test_the_slice_catalog_ships_once_and_renders_client_side(): void
    {
        $panel = $this->panel();

        // One compact catalog in the constructor args, with the
        // server-computed snake value riding along (relation
        // targets must match Str::snake exactly).
        $this->assertStringContainsString('installedCatalog: @js($installedCatalog)', $panel);
        $this->assertStringContainsString("'s' => Str::snake(\$s['name'])", $panel);
        $this->assertStringContainsString('introspectTables: @js($dbTables ?? [])', $panel);

        // Neither picker server-renders its options any more.
        $this->assertStringNotContainsString('$groupedSlices', $panel, 'the slice picker no longer groups server-side');
        $this->assertStringNotContainsString('<option value="{{ $s[\'name\'] }}"', $panel);
        $this->assertStringNotContainsString('<option value="{{ Str::snake($s[\'name\']) }}">', $panel);
        $this->assertStringNotContainsString('@foreach ($dbTables as $tbl)', $panel, 'the introspect table list renders from data');

        // Both render from the one catalog via the factory.
        $this->assertStringContainsString('x-for="g in catalogGroups()"', $panel);
        $this->assertStringContainsString('x-for="s in catalogSlices()"', $panel);
        $this->assertStringContainsString('x-for="tb in introspectTables"', $panel);
        $factory = $this->factory();
        $this->assertStringContainsString('catalogGroups()', $factory);
        $this->assertStringContainsString('catalogSlices()', $factory);
    }

    public function test_the_porting_modal_is_a_lazy_section_not_fragment_baggage(): void
    {
        $panel = $this->panel();
        $this->assertStringNotContainsString("@include('laraslice::partials.porting-modal')", $panel, 'the modal no longer rides inside the Design fragment');
        $this->assertStringContainsString('id="design-porting-modal-slot"', $panel, 'the panel ships the slot the modal loads into');

        $factory = $this->factory();
        $this->assertStringContainsString('ensurePortingModal()', $factory);
        $this->assertStringContainsString('/laraslice/studio/blueprint/porting-modal', $factory);
        $this->assertStringContainsString("\$watch('showPortModal'", $factory, 'first open triggers the load');

        // The endpoint serves the real modal markup, standalone
        // (the partial is pure Alpine over component state).
        $response = (new BlueprintStudioController)->blueprintPortingModal();
        $html = $response->render();
        $this->assertStringContainsString('LaraSlice Porting Studio', $html);
        $this->assertStringContainsString('x-show="showPortModal"', $html);
        $this->assertGreaterThan(50_000, strlen($html), 'the whole modal ships from the endpoint, not a stub');
    }
}
