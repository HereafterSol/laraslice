<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Support\PageStructureDrift;
use PHPUnit\Framework\TestCase;

class PageStructureDriftTest extends TestCase
{
    private function kinds(array $findings): array
    {
        return array_column($findings, 'kind');
    }

    public function test_invalid_placement_is_an_error(): void
    {
        $f = PageStructureDrift::auditBlade('<div></div>', ['pages' => ['list' => ['placement' => 'floating']]], 'app/Slices/X');
        $this->assertContains('structure.invalid-placement', $this->kinds($f));
        $this->assertSame('error', $f[0]['severity']);
    }

    public function test_guarded_seed_is_clean_but_unguarded_seed_is_an_error(): void
    {
        $guarded = "@if((\$demoSeed ?? false) && (\$can['seed'] ?? true)) <button><span>Seed Demo</span></button> @endif";
        $this->assertSame([], PageStructureDrift::auditBlade($guarded, [], 'app/Slices/X'));

        $unguarded = '<button><span>Seed Demo</span></button>';
        $f = PageStructureDrift::auditBlade($unguarded, [], 'app/Slices/X');
        $this->assertContains('structure.undeclared-seed', $this->kinds($f));

        // …unless the slice declares demo_seed.
        $declared = PageStructureDrift::auditBlade($unguarded, ['directory' => ['demo_seed' => true]], 'app/Slices/X');
        $this->assertSame([], $declared);
    }

    public function test_placement_readiness_warnings(): void
    {
        $bandOnly = '<x-slot:below-toolbar><button>Add</button></x-slot:below-toolbar>';
        $this->assertContains('structure.placement-not-dual', $this->kinds(PageStructureDrift::auditBlade($bandOnly, [], 'app/Slices/X')));

        $dual = $bandOnly." @if((\$placement ?? 'x') === 'header-right') @endif";
        $this->assertContains('structure.placement-not-quad', $this->kinds(PageStructureDrift::auditBlade($dual, [], 'app/Slices/X')));

        $quad = $dual." @if((\$placement ?? 'x') === 'command-bar') @endif";
        $this->assertSame([], PageStructureDrift::auditBlade($quad, [], 'app/Slices/X'));
    }

    public function test_version_pill_is_an_error(): void
    {
        $blade = '<h1>Tasks <x-ui.badge variant="secondary">v1.0.0</x-ui.badge></h1>';
        $f = PageStructureDrift::auditBlade($blade, [], 'app/Slices/X');
        $this->assertContains('structure.version-pill', $this->kinds($f));
    }

    public function test_component_band_gate(): void
    {
        $this->assertSame([], PageStructureDrift::auditComponent('@if(isset($belowToolbar) && $belowToolbar->isNotEmpty())'));
        $f = PageStructureDrift::auditComponent('@isset($belowToolbar) {{ $belowToolbar }} @endisset');
        $this->assertContains('structure.empty-band-risk', $this->kinds($f));
    }

    public function test_hollow_hub_is_an_error_when_the_heart_promises_relation_tabs(): void
    {
        $manifest = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'overview', 'label' => 'Overview', 'type' => 'fields'],
            ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
        ]]]];
        $findings = \LaraSlice\Support\PageStructureDrift::auditShowBlade('<div>overview only</div>', $manifest, 'app/Slices/Demo');
        $this->assertSame('structure.hollow-hub', $findings[0]['kind']);
        $this->assertSame('error', $findings[0]['severity']);
    }

    public function test_hub_with_the_loop_and_hub_without_promises_are_clean(): void
    {
        $manifest = ['models' => [['relations' => [['name' => 'comments', 'type' => 'hasMany']]]]];
        $this->assertSame([], \LaraSlice\Support\PageStructureDrift::auditShowBlade('@foreach($relatedTabs as $rtab)', $manifest, 'x'));
        $this->assertSame([], \LaraSlice\Support\PageStructureDrift::auditShowBlade('<div>plain detail</div>', ['ui' => ['show' => ['tabs' => []]]], 'x'));
    }
}
