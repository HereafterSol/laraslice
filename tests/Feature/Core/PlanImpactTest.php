<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Support\SystemMind;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * Plan impact view: journey coverage computed from the same
 * declarations slice:flow renders — a journey whose steps
 * name a slice's navigation label is covered by that slice;
 * a journey with no linked step is intent with no surface
 * yet. The candidate impact (from the Plan form's journeys
 * text) shows what an unsaved edit would add or remove.
 */
final class PlanImpactTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-impact-'.uniqid();
        mkdir($this->root, 0777, true);
        $this->removeMind();
    }

    protected function tearDown(): void
    {
        $this->removeMind();
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeMind(): void
    {
        foreach ([SystemMind::path(), SystemMind::defaultPath()] as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
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

    private function manifest(string $relativeDir, string $name, string $label): SliceManifest
    {
        $dir = $this->root.'/'.$relativeDir;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/slice.yaml', "name: '{$name}'\nversion: 1.0.0\ntitle: '{$label}'\nnavigation:\n  label: '{$label}'\n  url: '/work/".strtolower($label)."'\n  section: 'Work'\n");

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

    private function writeMind(): void
    {
        SystemMind::applyBriefSeed([
            'purpose' => 'Deliver client work.',
            'actors' => [['name' => 'Employee', 'kind' => 'internal', 'description' => 'Delivers work.']],
            'journeys' => [[
                'name' => 'Deliver work', 'actor' => 'Employee',
                'steps' => ['Open Projects', 'File the report'], 'outcome' => 'Done.',
            ]],
        ], 'Ops');
        $this->assertNotNull(SystemMind::path(), 'precondition: the Mind was written');
    }

    public function test_coverage_links_steps_to_slices_by_navigation_label(): void
    {
        $this->writeMind();
        $this->bindManager($this->manifest('Work/Projects', 'Projects', 'Projects'));

        $response = (new BlueprintStudioController)->planImpact(Request::create('/laraslice/studio/plan/impact', 'POST', []));
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['has_mind']);
        $this->assertSame(1, $data['pages']);
        $this->assertSame(1, $data['sections']);
        $journey = $data['journeys'][0];
        $this->assertSame('Deliver work', $journey['name']);
        $this->assertSame(2, $journey['steps']);
        $this->assertSame(1, $journey['linked'], 'one step names the Projects label');
        $this->assertSame(['Projects'], $journey['slices']);
        $this->assertTrue($journey['covered']);
    }

    public function test_candidate_shows_added_uncovered_and_removed_journeys(): void
    {
        $this->writeMind();
        $this->bindManager($this->manifest('Work/Projects', 'Projects', 'Projects'));
        $controller = new BlueprintStudioController;

        $added = json_decode($controller->planImpact(Request::create('/laraslice/studio/plan/impact', 'POST', [
            'journeys' => "Deliver work | Employee | Open Projects > File the report | Done.\nMoon trip | Employee | Fly to the moon | Back safely.",
        ]))->getContent(), true);
        $this->assertSame(['Moon trip'], $added['candidate']['added']);
        $this->assertSame([], $added['candidate']['removed']);
        $moon = $added['candidate']['journeys'][1];
        $this->assertFalse($moon['covered'], 'a journey naming no slice label has no surface yet');
        $this->assertSame(0, $moon['linked']);

        $removed = json_decode($controller->planImpact(Request::create('/laraslice/studio/plan/impact', 'POST', [
            'journeys' => 'Moon trip | Employee | Fly to the moon | Back safely.',
        ]))->getContent(), true);
        $this->assertSame(['Deliver work'], $removed['candidate']['removed']);
    }

    public function test_no_mind_is_an_honest_empty_state(): void
    {
        $this->bindManager($this->manifest('Work/Projects', 'Projects', 'Projects'));

        $response = (new BlueprintStudioController)->planImpact(Request::create('/laraslice/studio/plan/impact', 'POST', []));
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['has_mind']);
        $this->assertSame([], $data['journeys']);
    }
}
