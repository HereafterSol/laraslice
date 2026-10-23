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
}
