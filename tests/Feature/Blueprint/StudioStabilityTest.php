<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Blueprint;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Blueprint\BlueprintValidator;
use LaraSlice\Generator\SliceModifier;
use LaraSlice\Tests\TestCase;

/**
 * F7 + F2 + F8 (Day-2 drill, 8 Nov 2026): Studio in-session stability.
 *
 * F7 — the Design panel died on the plan/apply re-render: those POSTs
 *      return the standalone blueprint-studio wrapper, and nothing on
 *      that page loaded the component factory, so Alpine evaluated
 *      x-data="blueprintStudio(...)" against a missing global. The
 *      wrapper now loads the factory synchronously before the panel.
 * F2 — the Manage save-preview diff modal sat OUTSIDE the wizard
 *      component root (its bindings resolved against the shell:
 *      "showDiffModal is not defined" on every mount), and the
 *      preview itself 422'd for every segmented-layout slice because
 *      SliceModifier::resolveSliceDir only searched two levels deep
 *      while the framework's own layout nests slices three segments
 *      down (Slices/ERP/Adjudication/Work/Tasks).
 * F8 — the Studio's in-browser badge only parsed YAML, so it blessed
 *      length: '255' (which the Studio's own initializer emitted)
 *      while the engine's BlueprintValidator rejected it. The Studio
 *      now asks the engine itself via the blueprint/validate
 *      endpoint, which runs plan()'s exact front half.
 */
final class StudioStabilityTest extends TestCase
{
    private const BLUEPRINT_QUOTED_LENGTH = <<<'YAML'
        schema_version: 1
        name: Parity Case
        handle: parity_case
        domain: HR
        models:
          - handle: probe_widget
            name: ProbeWidget
            table: probe_widgets
            root: true
            fields:
              - handle: name
                label: Name
                type: string
                length: '255'
        YAML;

    private function callValidateEndpoint(string $source): array
    {
        $controller = app(BlueprintStudioController::class);
        $response = $controller->blueprintValidate(
            Request::create('/laraslice/studio/blueprint/validate', 'POST', [
                'source' => $source,
                'format' => 'yaml',
            ]),
            app(BlueprintLoader::class),
            app(BlueprintValidator::class),
        );

        return json_decode($response->getContent(), true);
    }

    public function test_f8_validate_endpoint_rejects_quoted_length_with_the_engine_message(): void
    {
        $result = $this->callValidateEndpoint(self::BLUEPRINT_QUOTED_LENGTH);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('length must be 1-65535', $result['errors'][0]);
    }

    public function test_f8_validate_endpoint_accepts_integer_length_like_the_engine(): void
    {
        $source = str_replace("length: '255'", 'length: 255', self::BLUEPRINT_QUOTED_LENGTH);
        $result = $this->callValidateEndpoint($source);

        $this->assertTrue($result['valid'], json_encode($result));
        $this->assertSame([], $result['errors']);
    }

    public function test_f8_validate_route_is_registered(): void
    {
        $this->assertTrue(Route::has('laraslice.studio.blueprint_validate'));
    }

    public function test_f7_standalone_wrapper_loads_the_design_factory_before_the_panel(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/blueprint-studio.blade.php');

        $factoryPos = strpos($blade, 'laraslice.studio.blueprint_design_factory');
        $panelPos = strpos($blade, 'partials.design-panel');

        $this->assertNotFalse($factoryPos, 'wrapper must load the design factory');
        $this->assertNotFalse($panelPos);
        $this->assertLessThan($panelPos, $factoryPos, 'factory script must precede the panel include');
    }

    public function test_f2_preview_resolves_the_segmented_slice_layout(): void
    {
        $slicesPath = $this->makeSliceTree([
            'ERP/Adjudication/Work/Tasks' => "name: Tasks\nhandle: tasks\nversion: 1.0.0\nui:\n  directory:\n    default_view: table\n",
        ]);

        $modifier = new SliceModifier($slicesPath);
        $preview = $modifier->previewStudioConfig('Tasks', ['directory' => ['default_view' => 'board']], [], null);

        $this->assertTrue($preview['changed']);
        $this->assertStringContainsString('board', $preview['diff']);
    }

    public function test_f2_preview_does_not_guess_between_same_name_twins(): void
    {
        $slicesPath = $this->makeSliceTree([
            'ERP/Adjudication/Work/Tasks' => "name: Tasks\nhandle: tasks\nversion: 1.0.0\n",
            'ERP/Other/Work/Tasks' => "name: Tasks\nhandle: tasks\nversion: 1.0.0\n",
        ]);

        $modifier = new SliceModifier($slicesPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $modifier->previewStudioConfig('Tasks', ['directory' => ['default_view' => 'board']], [], null);
    }

    /** @param array<string, string> $slices relative dir => slice.yaml contents */
    private function makeSliceTree(array $slices): string
    {
        $base = sys_get_temp_dir().'/laraslice-stability-'.uniqid();
        foreach ($slices as $relative => $yaml) {
            $dir = $base.'/Slices/'.$relative;
            mkdir($dir, 0777, true);
            file_put_contents($dir.'/slice.yaml', $yaml);
        }
        $this->beforeApplicationDestroyed(static function () use ($base): void {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($base);
        });

        return $base.'/Slices';
    }
}
