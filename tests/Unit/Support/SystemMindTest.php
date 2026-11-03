<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\SystemMind;
use PHPUnit\Framework\TestCase;

class SystemMindTest extends TestCase
{
    public function test_form_round_trip_preserves_untouched_sections(): void
    {
        $mind = [
            'system' => ['name' => 'Ops', 'purpose' => 'Old purpose.'],
            'actors' => [['name' => 'Admin', 'kind' => 'internal', 'description' => 'Runs it.']],
            'roles' => ['model' => 'role-clubs-with-user-overrides', 'clubs' => [['name' => 'Admin', 'permissions' => ['hr.*']]], 'overrides' => 'Per-person grants.'],
            'journeys' => [['name' => 'Hire', 'actor' => 'Admin', 'steps' => ['Post job', 'Interview'], 'outcome' => 'Hired.']],
            'operations' => ['backups' => 'daily'],
            'navigation' => [['label' => 'HR']],
        ];

        $form = SystemMind::toForm($mind);
        $this->assertSame('Old purpose.', $form['purpose']);
        $this->assertStringContainsString('Admin | hr.*', $form['roles']);
        $this->assertStringContainsString('Post job > Interview', $form['journeys']);

        $form['purpose'] = 'New purpose.';
        $form['roles'] = "Admin | hr.*, work.*\nEmployee | tasks.view-own";
        $updated = SystemMind::applyForm($mind, $form);

        $this->assertSame('New purpose.', $updated['system']['purpose']);
        $this->assertSame(['hr.*', 'work.*'], $updated['roles']['clubs'][0]['permissions']);
        $this->assertSame('Employee', $updated['roles']['clubs'][1]['name']);
        $this->assertSame('Per-person grants.', $updated['roles']['overrides'], 'untouched sections survive');
        $this->assertSame([['label' => 'HR']], $updated['navigation']);
    }

    public function test_success_metrics_round_trip_through_the_plan_form(): void
    {
        $mind = [
            'system' => ['name' => 'Ops', 'purpose' => 'Runs the place.'],
            'success_metrics' => [
                ['name' => 'Weekly active teams', 'target' => '500 by month 3'],
                'Ad-hoc string metric',
            ],
        ];

        $form = SystemMind::toForm($mind);
        $this->assertStringContainsString('Weekly active teams | 500 by month 3', $form['metrics']);
        $this->assertStringContainsString('Ad-hoc string metric', $form['metrics'], 'string-shaped metrics survive the flatten');

        $form['metrics'] = "Weekly active teams | 750 by month 6\nChurn | under 2%";
        $updated = SystemMind::applyForm($mind, $form);
        $this->assertSame(
            [['name' => 'Weekly active teams', 'target' => '750 by month 6'], ['name' => 'Churn', 'target' => 'under 2%']],
            $updated['success_metrics']
        );

        // A form that never heard of metrics must not wipe them.
        unset($form['metrics']);
        $untouched = SystemMind::applyForm($mind, $form);
        $this->assertSame($mind['success_metrics'], $untouched['success_metrics']);
    }

    public function test_diff_marks_changes_and_render_round_trips(): void
    {
        $a = ['system' => ['purpose' => 'One.']];
        $b = ['system' => ['purpose' => 'Two.']];
        $diff = SystemMind::diff(SystemMind::render($a), SystemMind::render($b));
        $this->assertStringContainsString('- ', $diff);
        $this->assertStringContainsString('+ ', $diff);
        $this->assertStringContainsString('Two.', $diff);
        $this->assertSame('', SystemMind::diff('x', 'x'));
    }

    public function test_draft_body_v2_classifies_fields_and_wires_workflow(): void
    {
        $manifest = [
            'name' => 'Leaves',
            'title' => 'Leaves',
            'ui' => ['directory' => ['columns' => [['label' => 'Employee'], ['label' => 'Leave Date']]]],
            'fields' => [
                'user_id' => ['type' => 'integer'],
                'leave_date' => ['type' => 'datetime'],
                'reason' => ['type' => 'text'],
                'status' => ['type' => 'string'],
                'approved_by' => ['type' => 'integer'],
                'approved_at' => ['type' => 'datetime'],
                'added_by' => ['type' => 'integer'],
            ],
            'workflow' => ['state_field' => 'status', 'initial_state' => 'pending'],
        ];
        $body = \LaraSlice\Support\SystemMind::draftBodyV2($manifest, 'Leaves');
        $this->assertStringContainsString('user_id: {input: picker, relation: user}', $body);
        $this->assertStringContainsString('status: {input: workflow-state, default: workflow-initial, editable: false}', $body);
        $this->assertStringContainsString('system_fields: [approved_by, approved_at, added_by]', $body);
        $this->assertStringContainsString('workflow: {state_field: status, initial: pending, on_create: apply-initial}', $body);
        $this->assertStringContainsString('leave_date: {input: date}', $body);
    }

    public function test_actors_round_trip_through_the_plan_form(): void
    {
        $mind = [
            'system' => ['name' => 'Ops', 'purpose' => 'Run it.'],
            'actors' => [
                ['name' => 'Admin', 'kind' => 'internal', 'description' => 'Runs it.'],
                ['name' => 'Client', 'kind' => 'external', 'description' => 'Uses the portal.'],
            ],
            'roles' => ['model' => 'role-clubs-with-user-overrides', 'clubs' => []],
            'journeys' => [],
            'operations' => [],
        ];

        $form = SystemMind::toForm($mind);
        $this->assertSame("Admin | internal | Runs it.\nClient | external | Uses the portal.", $form['actors']);

        $updated = SystemMind::applyForm($mind, $form);
        $this->assertSame($mind['actors'], $updated['actors'], 'actors survive the form round trip unchanged');
    }

    public function test_actors_are_only_touched_when_the_form_supplies_them(): void
    {
        $mind = [
            'system' => ['name' => 'Ops', 'purpose' => 'Run it.'],
            'actors' => [['name' => 'Admin', 'kind' => 'internal', 'description' => 'Runs it.']],
            'roles' => ['model' => 'role-clubs-with-user-overrides', 'clubs' => []],
            'journeys' => [],
            'operations' => [],
        ];

        $form = SystemMind::toForm($mind);
        unset($form['actors']);
        $preserved = SystemMind::applyForm($mind, $form);
        $this->assertSame($mind['actors'], $preserved['actors'], 'a form without the field never wipes the cast');

        $form['actors'] = "Partner | external | Helps clients\nGreeter | INTERNAL | Front desk\nNoKind | Welcomes people";
        $edited = SystemMind::applyForm($mind, $form);
        $this->assertSame('Partner', $edited['actors'][0]['name']);
        $this->assertSame('internal', $edited['actors'][1]['kind'], 'kind is case-insensitive');
        $this->assertSame('external', $edited['actors'][2]['kind'], 'a missing or unknown kind defaults to external');
        $this->assertSame('', $edited['actors'][2]['description'], 'a missing description stays empty');
    }
}
