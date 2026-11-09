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

    public function test_snake_case_relation_name_resolves_the_camel_case_model_method(): void
    {
        // Manifests name relations in the porter's snake_case form
        // (project_activity) while generated models expose camelCase
        // methods (projectActivity): the tab must still resolve its
        // count instead of rendering a false "no records" panel.
        $record = new class
        {
            public function projectActivity(): object
            {
                return new class
                {
                    public function count(): int
                    {
                        return 7;
                    }
                };
            }
        };
        $manifest = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'project_activity', 'label' => 'Project Activity', 'type' => 'relation', 'relation' => 'project_activity'],
        ]]]];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);

        $this->assertSame('project_activity', $tabs[0]['handle']);
        $this->assertSame(7, $tabs[0]['count'] ?? null);
    }

    public function test_chart_tab_resolves_with_kind_and_relation(): void
    {
        $record = new class
        {
            public function tasks(): object
            {
                return new class
                {
                    public function count(): int
                    {
                        return 3;
                    }
                };
            }
        };
        $manifest = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'burndown', 'label' => 'Burndown', 'type' => 'chart', 'chart' => 'burndown', 'relation' => 'tasks'],
        ]]]];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);

        $this->assertSame('burndown', $tabs[0]['handle']);
        $this->assertSame('chart', $tabs[0]['type']);
        $this->assertSame('burndown', $tabs[0]['chart']);
        $this->assertSame('tasks', $tabs[0]['relation']);
    }

    public function test_permissions_tab_resolves(): void
    {
        $record = new class
        {
            public ?int $user_id = 5;
        };
        $manifest = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'permissions', 'label' => 'Permissions', 'type' => 'permissions'],
        ]]]];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);

        $this->assertSame('permissions', $tabs[0]['handle']);
        $this->assertSame('permissions', $tabs[0]['type']);
    }

    public function test_calendar_and_thread_render_modes_supply_raw_records(): void
    {
        $makeRecord = static fn () => new class
        {
            public function employeeShiftSchedules(): object
            {
                return new class
                {
                    public function count(): int
                    {
                        return 2;
                    }

                    public function get(): array
                    {
                        return ['a', 'b'];
                    }

                    public function limit(int $n): static
                    {
                        return $this;
                    }
                };
            }

            public function replies(): object
            {
                return $this->employeeShiftSchedules();
            }
        };

        foreach (['calendar', 'thread'] as $render) {
            $relation = $render === 'calendar' ? 'employee_shift_schedules' : 'replies';
            $manifest = ['ui' => ['show' => ['tabs' => [
                ['handle' => 't', 'label' => 'T', 'type' => 'relation', 'relation' => $relation, 'render' => $render],
            ]]]];

            $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $makeRecord());

            $this->assertSame($render, $tabs[0]['render'], $render);
            $this->assertSame(['a', 'b'], $tabs[0]['records'] ?? null, $render);
        }
    }

    public function test_show_default_tab_validates_against_declared_tabs(): void
    {
        $tabs = [['handle' => 'replies', 'label' => 'Replies', 'type' => 'relation', 'relation' => 'replies']];
        $this->assertSame('replies', DirectoryUi::showDefaultTabFromManifest(
            ['ui' => ['show' => ['default_tab' => 'replies', 'tabs' => $tabs]]]
        ));
        $this->assertSame('overview', DirectoryUi::showDefaultTabFromManifest(
            ['ui' => ['show' => ['default_tab' => 'overview', 'tabs' => $tabs]]]
        ));
        $this->assertNull(DirectoryUi::showDefaultTabFromManifest(
            ['ui' => ['show' => ['default_tab' => 'missing', 'tabs' => $tabs]]]
        ));
        $this->assertNull(DirectoryUi::showDefaultTabFromManifest(
            ['ui' => ['show' => ['tabs' => $tabs]]]
        ));
        $this->assertNull(DirectoryUi::showDefaultTabFromManifest(
            ['ui' => ['show' => ['default_tab' => 'replies', 'tabs' => [
                ['handle' => 'replies', 'label' => 'Replies', 'type' => 'relation', 'relation' => 'replies', 'hidden' => true],
            ]]]]
        ));
    }

    public function test_generator_and_repair_carry_the_chart_and_permissions_panels(): void
    {
        $generator = (string) file_get_contents(dirname(__DIR__, 3).'/src/Generator/SliceGenerator.php');
        $this->assertStringContainsString('partials.related-tab-chart', $generator);
        $this->assertStringContainsString('partials.related-tab-permissions', $generator);
        $this->assertStringContainsString("'calendar', 'thread'", $generator);
        $this->assertStringContainsString('\$showDefaultTab ?? \'overview\'', $generator);

        $manager = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Discovery/SliceManager.php');
        $this->assertStringContainsString('(ao) Chart tab panel', $manager);
        $this->assertStringContainsString('(ap) Permissions tab panel', $manager);
        $this->assertStringContainsString('function addChartTabPanel', $manager);
        $this->assertStringContainsString('function addPermissionsTabPanel', $manager);

        $controller = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Base/BaseSliceWebController.php');
        $this->assertStringContainsString('showDefaultTabFromManifest', $controller);

        foreach (['related-tab-chart', 'related-tab-calendar', 'related-tab-thread', 'related-tab-permissions'] as $partial) {
            $this->assertFileExists(dirname(__DIR__, 3).'/src/Wizard/views/partials/'.$partial.'.blade.php', $partial);
        }
    }
}
