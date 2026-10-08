<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Workflow\HasWorkflow;
use LaraSlice\Core\Workflow\WorkflowEngine;
use LaraSlice\Core\Workflow\WorkflowTransition;
use LaraSlice\Tests\TestCase;

class PackageWiringTest extends TestCase
{
    public function test_every_composer_facade_alias_points_to_an_existing_class(): void
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        foreach ($composer['extra']['laravel']['aliases'] as $alias => $class) {
            $this->assertTrue(class_exists($class), "Alias {$alias} points to missing class {$class}");
        }
    }

    public function test_publishable_starter_files_exist(): void
    {
        foreach (\Illuminate\Support\ServiceProvider::pathsToPublish(\LaraSlice\LaraSliceServiceProvider::class) as $from => $to) {
            $this->assertFileExists($from);
        }
    }

    public function test_skill_publish_copies_the_packaged_skill(): void
    {
        $target = base_path('.agents/skills/laraslice/SKILL.md');
        $created = array_filter([base_path('.agents'), base_path('.cursor')], fn ($dir) => ! is_dir($dir));

        try {
            $this->artisan('laraslice:skill:publish')->assertSuccessful();
            $this->assertFileEquals(dirname(__DIR__, 3) . '/skills/laraslice/SKILL.md', $target);
        } finally {
            // Leave the Testbench skeleton as it was
            foreach ($created as $dir) {
                app('files')->deleteDirectory($dir);
            }
        }
    }

    public function test_manifest_active_flag_accepts_string_booleans(): void
    {
        $dir = sys_get_temp_dir() . '/laraslice-manifest-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            foreach (['"false"' => false, '"no"' => false, 'false' => false, '"true"' => true, '1' => true] as $raw => $expected) {
                file_put_contents($dir . '/slice.json', '{"name": "Things", "active": ' . $raw . '}');
                $this->assertSame($expected, (new SliceManifest($dir . '/slice.json'))->active, "active: {$raw}");
            }
        } finally {
            @unlink($dir . '/slice.json');
            @rmdir($dir);
        }
    }

    public function test_invalid_workflow_transitions_throw_a_logic_exception(): void
    {
        WorkflowEngine::define(WorkflowDummy::class, [
            'approve' => new WorkflowTransition('approve', 'submitted', 'approved'),
        ]);

        $this->expectException(\LogicException::class);
        (new WorkflowDummy(['status' => 'draft']))->transitionTo('approve');
    }

    public function test_workflow_transition_permissions_are_enforced(): void
    {
        WorkflowEngine::define(WorkflowDummy::class, [
            'approve' => new WorkflowTransition('approve', 'draft', 'approved', 'invoices.approve'),
        ]);
        $model = new WorkflowDummy(['status' => 'draft']);

        $this->assertFalse($model->canTransitionTo('approve'), 'no signed-in user');

        $this->actingAs($this->makeUserWithPermissions(['invoices.view']));
        $this->assertFalse($model->canTransitionTo('approve'));

        $this->actingAs($this->makeUserWithPermissions(['invoices.approve']));
        $this->assertTrue($model->canTransitionTo('approve'));
    }

    public function test_the_studio_layout_carries_a_csrf_meta_tag(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $this->get('/laraslice/wizard/schema-studio')
            ->assertOk()
            ->assertSee('<meta name="csrf-token"', false);
    }
}

class WorkflowDummy extends \Illuminate\Database\Eloquent\Model
{
    use HasWorkflow;

    protected $guarded = [];

    public function save(array $options = []): bool
    {
        return true;
    }
}
