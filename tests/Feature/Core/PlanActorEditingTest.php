<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Http\Request;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Support\SystemMind;
use LaraSlice\Tests\TestCase;

/**
 * Plan edits the whole Mind — actors included. Saving with
 * the actors field replaces the cast; saving without it (any
 * caller that predates the field) preserves the cast, the
 * same opt-in rule as success metrics.
 */
final class PlanActorEditingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->removeMind();
        SystemMind::applyBriefSeed([
            'purpose' => 'Deliver client work.',
            'actors' => [['name' => 'Employee', 'kind' => 'internal', 'description' => 'Delivers work.']],
            'journeys' => [],
        ], 'Ops');
        $this->assertNotNull(SystemMind::path(), 'precondition: the Mind was written');
    }

    protected function tearDown(): void
    {
        $this->removeMind();
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

    public function test_plan_save_persists_actor_edits(): void
    {
        $form = SystemMind::toForm(SystemMind::load());
        $form['actors'] = "Employee | internal | Delivers work.\nPartner | external | Refers clients.";

        $response = (new BlueprintStudioController)->planSave(Request::create('/laraslice/studio/plan/save', 'POST', $form));
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['saved'] ?? false);

        $mind = SystemMind::load();
        $this->assertSame(['Employee', 'Partner'], array_map(fn ($a) => $a['name'], $mind['actors']));
        $this->assertSame('external', $mind['actors'][1]['kind']);
    }

    public function test_plan_save_without_the_actors_field_preserves_the_cast(): void
    {
        $form = SystemMind::toForm(SystemMind::load());
        unset($form['actors']);
        $form['purpose'] = 'An updated purpose.';

        (new BlueprintStudioController)->planSave(Request::create('/laraslice/studio/plan/save', 'POST', $form));

        $mind = SystemMind::load();
        $this->assertSame('An updated purpose.', $mind['system']['purpose']);
        $this->assertSame(['Employee'], array_map(fn ($a) => $a['name'], $mind['actors']), 'the cast survives a save that never mentioned it');
    }

    public function test_plan_preview_diff_shows_actor_changes(): void
    {
        $form = SystemMind::toForm(SystemMind::load());
        $form['actors'] = "Employee | internal | Delivers work.\nPartner | external | Refers clients.";

        $response = (new BlueprintStudioController)->planPreview(Request::create('/laraslice/studio/plan/preview', 'POST', $form));
        $data = json_decode($response->getContent(), true);

        $this->assertStringContainsString('Partner', $data['diff'], 'the diff-before-save shows the new actor before anything is written');
        $mind = SystemMind::load();
        $this->assertSame(['Employee'], array_map(fn ($a) => $a['name'], $mind['actors']), 'a preview writes nothing');
    }
}
