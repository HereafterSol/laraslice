<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Tests\TestCase;

/**
 * The Design panel's diet, pinned: the js-yaml parser is a
 * shared package asset served once and cached, not ~39 KB
 * inlined into every panel load — and the panel carries
 * the lazy loader that fetches it.
 */
final class DesignAssetTest extends TestCase
{
    public function test_js_yaml_is_served_as_a_cacheable_asset(): void
    {
        $response = (new BlueprintStudioController)->blueprintJsYaml();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/javascript', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
        $body = (string) $response->getContent();
        $this->assertGreaterThan(30_000, strlen($body), 'the real parser ships, not a stub');
        $this->assertStringContainsString('js-yaml 4.1.0', $body);
    }

    public function test_the_design_panel_lazy_loads_instead_of_inlining(): void
    {
        $root = dirname(__DIR__, 3);
        $panel = (string) file_get_contents($root.'/src/Wizard/views/partials/design-panel.blade.php');

        $this->assertStringNotContainsString('/*! js-yaml', $panel, 'the parser must not ride inside the panel fragment');
        $this->assertStringContainsString('__loadJsYaml', $panel, 'the panel lazy-loads the shared asset instead');
        $this->assertFileExists($root.'/resources/assets/js-yaml.min.js');
    }

    public function test_the_design_factory_is_a_shared_asset_and_the_panel_no_longer_defines_it(): void
    {
        $response = (new BlueprintStudioController)->blueprintDesignFactory();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/javascript', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        $this->assertGreaterThan(100_000, strlen($body), 'the real factory ships, not a stub');
        $this->assertStringContainsString('window.blueprintStudio = ', $body);
        $this->assertStringContainsString('__loadStudioPresets', $body, 'the presets loader travels with the factory it serves');

        $root = dirname(__DIR__, 3);
        $panel = (string) file_get_contents($root.'/src/Wizard/views/partials/design-panel.blade.php');
        $this->assertStringNotContainsString('window.blueprintStudio = ', $panel, 'the factory must not ride inside the panel fragment');
        $shell = (string) file_get_contents($root.'/src/Wizard/views/studio-shell.blade.php');
        $this->assertStringContainsString('__loadDesignFactory', $shell, 'the shell owns loading the factory before the panel initializes');
        $this->assertStringContainsString('x-data="blueprintStudio({', $panel, 'the panel still constructs the component from its server data');
    }

    public function test_the_team_library_is_wired_into_the_design_surface(): void
    {
        $root = dirname(__DIR__, 3);
        $factory = (string) file_get_contents($root.'/resources/assets/design-factory.js');
        $panel = (string) file_get_contents($root.'/src/Wizard/views/partials/design-panel.blade.php');

        // The factory carries the whole round trip against
        // the library endpoints: list for the picker, load
        // through the YAML funnel, shelve, remove.
        $this->assertStringContainsString('loadLibraryItems', $factory);
        $this->assertStringContainsString('loadLibraryItem', $factory);
        $this->assertStringContainsString('saveToLibrary', $factory);
        $this->assertStringContainsString('deleteLibraryItem', $factory);
        $this->assertStringContainsString("value.startsWith('library:')", $factory, 'the Start-from picker routes library picks to the loader');

        // The panel offers the library in the picker and
        // the shelve/remove actions beside Save draft.
        $this->assertStringContainsString('Team Library', $panel);
        $this->assertStringContainsString('@click="saveToLibrary()"', $panel);
        $this->assertStringContainsString('@click="deleteLibraryItem()"', $panel);
    }
}
