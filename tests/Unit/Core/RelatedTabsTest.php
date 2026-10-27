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
}
