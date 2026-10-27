<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

class RelatedTabsTest extends TestCase
{
    public function test_two_declared_tabs_may_present_one_relation_with_distinct_handles(): void
    {
        $manifest = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
            ['handle' => 'task_board', 'label' => 'Task Board', 'type' => 'relation', 'relation' => 'tasks',
                'render' => 'board', 'group_by' => 'board_column_id'],
            ['handle' => 'gantt', 'label' => 'Gantt Chart', 'type' => 'relation', 'relation' => 'tasks',
                'render' => 'gantt', 'start_field' => 'start_date', 'end_field' => 'due_date', 'label_field' => 'heading'],
        ]]]];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest);
        $handles = array_column($tabs, 'handle');

        $this->assertSame(['tasks', 'task_board', 'gantt'], $handles);
        $this->assertSame('board', $tabs[1]['render']);
        $this->assertSame('board_column_id', $tabs[1]['group_by']);
        $this->assertSame('gantt', $tabs[2]['render']);
        $this->assertSame('start_date', $tabs[2]['start_field']);
    }

    public function test_same_handle_twice_still_dedupes_and_auto_adds_use_the_relation(): void
    {
        $manifest = [
            'ui' => ['show' => ['tabs' => [
                ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
                ['handle' => 'tasks', 'label' => 'Tasks Again', 'type' => 'relation', 'relation' => 'tasks'],
            ]]],
            'models' => [['relations' => [
                ['name' => 'tasks', 'type' => 'hasMany'],
                ['name' => 'comments', 'type' => 'hasMany'],
            ]]],
        ];

        $handles = array_column(DirectoryUi::relatedTabsFromManifest($manifest), 'handle');

        $this->assertSame(['tasks', 'comments'], $handles);
    }

    public function test_exclude_tabs_suppresses_inferred_relation_tabs(): void
    {
        $manifest = [
            'ui' => ['show' => [
                'tabs' => [
                    ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
                ],
                'exclude_tabs' => ['expensesRecurring', 'invoiceRecurring', 'issues'],
            ]],
            'models' => [['relations' => [
                ['name' => 'tasks', 'type' => 'hasMany'],
                ['name' => 'expensesRecurring', 'type' => 'hasMany'],
                ['name' => 'invoiceRecurring', 'type' => 'hasMany'],
                ['name' => 'issues', 'type' => 'hasMany'],
                ['name' => 'notes', 'type' => 'hasMany'],
            ]]],
        ];

        $handles = array_column(DirectoryUi::relatedTabsFromManifest($manifest), 'handle');

        $this->assertSame(['tasks', 'notes'], $handles);
    }

    public function test_exclude_tabs_wins_over_a_declared_tab_and_matches_handles(): void
    {
        $manifest = [
            'ui' => ['show' => [
                'tabs' => [
                    ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
                    ['handle' => 'task_board', 'label' => 'Task Board', 'type' => 'relation', 'relation' => 'tasks', 'render' => 'board'],
                    ['handle' => 'milestones', 'label' => 'Milestones', 'type' => 'relation', 'relation' => 'milestones'],
                ],
                'exclude_tabs' => ['task_board', 'milestones'],
            ]],
            'models' => [['relations' => [
                ['name' => 'tasks', 'type' => 'hasMany'],
            ]]],
        ];

        $handles = array_column(DirectoryUi::relatedTabsFromManifest($manifest), 'handle');

        // The board presentation is excluded by handle; the plain
        // tasks tab over the same relation survives; the declared
        // milestones tab is excluded by relation — exclusion wins.
        $this->assertSame(['tasks'], $handles);
    }

    public function test_exclude_tabs_suppresses_repeater_derived_tabs(): void
    {
        $manifest = [
            'ui' => [
                'show' => ['exclude_tabs' => ['items']],
                'forms' => ['sections' => [
                    ['fields' => [
                        ['handle' => 'items', 'label' => 'Items', 'type' => 'repeater'],
                        ['handle' => 'payments', 'label' => 'Payments', 'type' => 'repeater'],
                    ]],
                ]],
            ],
            'models' => [['relations' => []]],
        ];

        $handles = array_column(DirectoryUi::relatedTabsFromManifest($manifest), 'handle');

        $this->assertSame(['payments'], $handles);
    }

    public function test_hidden_declaration_suppresses_the_tab_and_its_inferred_twin(): void
    {
        $manifest = [
            'ui' => ['show' => ['tabs' => [
                ['handle' => 'discussion', 'label' => 'Discussion', 'type' => 'relation', 'relation' => 'discussion', 'hidden' => true],
                ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
            ]]],
            'models' => [['relations' => [
                ['name' => 'discussion', 'type' => 'hasMany'],
                ['name' => 'tasks', 'type' => 'hasMany'],
            ]]],
        ];

        $handles = array_column(DirectoryUi::relatedTabsFromManifest($manifest), 'handle');

        $this->assertSame(['tasks'], $handles);
    }

    public function test_declared_audit_tab_resolves_with_default_handle_and_label(): void
    {
        $manifest = ['ui' => ['show' => ['tabs' => [
            ['type' => 'audit'],
            ['handle' => 'history', 'label' => 'History', 'type' => 'audit'],
            ['handle' => 'history', 'label' => 'History Again', 'type' => 'audit'],
        ]]]];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest);

        $this->assertCount(2, $tabs);
        $this->assertSame(['handle' => 'activity', 'label' => 'Activity', 'type' => 'audit', 'relation' => ''], $tabs[0]);
        $this->assertSame('history', $tabs[1]['handle']);
        $this->assertSame('History', $tabs[1]['label']);
    }

    public function test_audit_tab_can_be_excluded_by_handle(): void
    {
        $manifest = ['ui' => ['show' => [
            'tabs' => [['handle' => 'activity', 'label' => 'Activity', 'type' => 'audit']],
            'exclude_tabs' => ['activity'],
        ]]];

        $this->assertSame([], DirectoryUi::relatedTabsFromManifest($manifest));
    }

    public function test_audit_tab_carries_an_empty_trail_when_no_audit_source_answers(): void
    {
        $record = new class
        {
            public int $id = 7;

            public function getKey(): int
            {
                return $this->id;
            }
        };
        $manifest = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'activity', 'label' => 'Activity', 'type' => 'audit'],
        ]]]];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);

        $this->assertCount(1, $tabs);
        $this->assertSame([], $tabs[0]['entries']);
        $this->assertSame(0, $tabs[0]['count']);
    }

    public function test_fields_tab_label_comes_from_the_declared_fields_tab(): void
    {
        $declared = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'overview', 'label' => 'Profile', 'type' => 'fields'],
            ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
        ]]]];
        $this->assertSame('Profile', DirectoryUi::fieldsTabLabelFromManifest($declared));

        $undeclared = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
        ]]]];
        $this->assertNull(DirectoryUi::fieldsTabLabelFromManifest($undeclared));

        $unlabelled = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'overview', 'type' => 'fields'],
        ]]]];
        $this->assertNull(DirectoryUi::fieldsTabLabelFromManifest($unlabelled));
    }

    public function test_generator_and_repair_carry_the_audit_panel_and_fields_label(): void
    {
        $generator = (string) file_get_contents(dirname(__DIR__, 3).'/src/Generator/SliceGenerator.php');
        $this->assertStringContainsString('partials.related-tab-audit', $generator);
        $this->assertStringContainsString("\$fieldsTabLabel ?? 'Overview'", $generator);

        $partial = (string) file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/partials/related-tab-audit.blade.php');
        $this->assertStringContainsString('No activity recorded yet.', $partial);
        $this->assertStringContainsString("\$rtab['entries']", $partial);

        $manager = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Discovery/SliceManager.php');
        $this->assertStringContainsString('(am) Declared fields-tab label', $manager);
        $this->assertStringContainsString('(an) Audit tab panel', $manager);
        $this->assertStringContainsString('function honourDeclaredFieldsTabLabel', $manager);
        $this->assertStringContainsString('function addAuditTabPanel', $manager);

        $controller = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Base/BaseSliceWebController.php');
        $this->assertStringContainsString('fieldsTabLabelFromManifest', $controller);
    }
}
