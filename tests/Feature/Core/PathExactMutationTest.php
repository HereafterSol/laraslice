<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Generator\SliceModifier;
use LaraSlice\Tests\TestCase;
use LaraSlice\Wizard\WizardController;
use ReflectionMethod;

/**
 * Path-exact mutation for same-name slices: two installed
 * slices may share a name (Settings does). A Manage mutation
 * that resolves by name alone guesses which twin to edit, so
 * the endpoints now accept the slice's exact path, refuse an
 * ambiguous name without one, and refuse core package slices
 * even with one.
 */
final class PathExactMutationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-pathexact-'.uniqid();
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

    private function manifest(string $relativeDir, string $name, string $extra = ''): SliceManifest
    {
        $dir = $this->root.'/'.$relativeDir;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/slice.yaml', "name: '{$name}'\nversion: 1.0.0\n".$extra);

        return new SliceManifest($dir.'/slice.yaml');
    }

    private function bindManager(SliceManifest ...$manifests): void
    {
        $manager = new SliceManager($this->app instanceof Application ? $this->app : app());
        $register = new ReflectionMethod($manager, 'registerManifest');
        $register->setAccessible(true);
        foreach ($manifests as $manifest) {
            $register->invoke($manager, $manifest);
        }
        $this->app->instance(SliceManager::class, $manager);
    }

    public function test_ambiguous_name_without_a_path_is_refused(): void
    {
        $this->bindManager(
            $this->manifest('Alpha/Settings', 'Settings'),
            $this->manifest('Beta/Settings', 'Settings'),
        );

        $response = (new WizardController)->addField(Request::create('/laraslice/wizard/add-field', 'POST', [
            'slice' => 'Settings', 'field' => 'nickname', 'type' => 'string',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('matches 2 installed slices', $data['message']);
    }

    public function test_a_path_that_matches_no_discovered_slice_is_refused(): void
    {
        $this->bindManager(
            $this->manifest('Alpha/Settings', 'Settings'),
            $this->manifest('Beta/Settings', 'Settings'),
        );

        $response = (new WizardController)->addField(Request::create('/laraslice/wizard/add-field', 'POST', [
            'slice' => 'Settings', 'field' => 'nickname', 'type' => 'string', 'path' => '/nowhere/Settings',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('does not match a discovered', $data['message']);
    }

    public function test_the_core_package_slice_is_refused_even_with_its_path(): void
    {
        $coreYaml = dirname(__DIR__, 3).'/src/Slices/Settings/slice.yaml';
        $this->assertFileExists($coreYaml);
        $core = new SliceManifest($coreYaml);
        $this->bindManager($core, $this->manifest('Alpha/Settings', 'Settings'));

        $response = (new WizardController)->addField(Request::create('/laraslice/wizard/add-field', 'POST', [
            'slice' => 'Settings', 'field' => 'nickname', 'type' => 'string', 'path' => $core->path,
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('core LaraSlice slice inside the package', $data['message']);
    }

    public function test_sync_manifest_writes_to_the_exact_twin(): void
    {
        $alpha = $this->manifest('Alpha/Settings', 'Settings', "domain: Alpha\n");
        $beta = $this->manifest('Beta/Settings', 'Settings', "domain: Beta\n");
        $this->bindManager($alpha, $beta);
        $alphaBefore = file_get_contents($alpha->path.'/slice.yaml');

        $response = (new WizardController)->syncManifest(Request::create('/laraslice/wizard/sync-manifest', 'POST', [
            'slice' => 'Settings', 'path' => $beta->path, 'prefer' => 'yaml',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success'] ?? false, 'path-exact sync succeeds: '.json_encode($data));
        $this->assertStringContainsString('sync_manifest', (string) file_get_contents($beta->path.'/slice.yaml'), 'the addressed twin was written');
        $this->assertSame($alphaBefore, file_get_contents($alpha->path.'/slice.yaml'), 'the other twin is byte-untouched');
    }

    public function test_sync_manifest_with_an_ambiguous_name_and_no_path_is_refused(): void
    {
        $this->bindManager(
            $this->manifest('Alpha/Settings', 'Settings'),
            $this->manifest('Beta/Settings', 'Settings'),
        );

        $response = (new WizardController)->syncManifest(Request::create('/laraslice/wizard/sync-manifest', 'POST', [
            'slice' => 'Settings', 'prefer' => 'yaml',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('matches 2 installed slices', $data['message']);
    }

    public function test_a_single_slice_still_syncs_by_name_alone(): void
    {
        $only = $this->manifest('Solo/Widgets', 'Widgets');
        $this->bindManager($only);

        $response = (new WizardController)->syncManifest(Request::create('/laraslice/wizard/sync-manifest', 'POST', [
            'slice' => 'Widgets', 'prefer' => 'yaml',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success'] ?? false, 'unambiguous name-only flow is unchanged: '.json_encode($data));
    }

    public function test_the_modifier_pin_wins_over_name_resolution(): void
    {
        $alpha = $this->manifest('Alpha/Settings', 'Settings');
        $beta = $this->manifest('Beta/Settings', 'Settings');

        $modifier = (new SliceModifier($this->root))->forSliceDir($beta->path);
        $resolve = new ReflectionMethod($modifier, 'resolveSliceDir');
        $resolve->setAccessible(true);

        $this->assertSame($beta->path, $resolve->invoke($modifier, 'Settings'));
        $this->assertNotSame($alpha->path, $resolve->invoke($modifier, 'Settings'));
    }
}
