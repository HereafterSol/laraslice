<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\RecordActions;
use PHPUnit\Framework\TestCase;

final class RecordActionsTest extends TestCase
{
    public function test_declared_actions_normalize_and_filter(): void
    {
        $raw = ['ui' => ['actions' => [
            ['name' => 'duplicate', 'type' => 'duplicate'],
            ['name' => 'archive', 'label' => 'Archive', 'type' => 'transition', 'field' => 'status', 'value' => 'archived', 'confirm' => 'Archive this?'],
            ['name' => 'broken', 'type' => 'transition'],
            ['name' => 'explode', 'type' => 'explode'],
            ['type' => 'duplicate'],
        ]]];
        $out = RecordActions::declaredActions($raw);
        $this->assertCount(2, $out);
        $this->assertSame('Duplicate', $out[0]['label']);
        $this->assertSame('copy', $out[0]['icon']);
        $this->assertSame('archive', $out[1]['name']);
        $this->assertSame('status', $out[1]['field']);
        $this->assertSame('archived', $out[1]['value']);
        $this->assertSame('Archive this?', $out[1]['confirm']);
        $this->assertSame([], RecordActions::declaredActions([]));
    }

    public function test_permission_base_prefers_the_heart_then_the_prefix(): void
    {
        $this->assertSame('holiday', RecordActions::permissionBase(['permissions' => ['holiday.view']], 'hr_holidays'));
        // Mirrors the base controller exactly: dotted prefixes split
        // on the last segment; underscored ones are the known
        // misfire the Heart declaration exists to override.
        $this->assertSame('holiday', RecordActions::permissionBase([], 'hr.holidays'));
        $this->assertSame('hr_holiday', RecordActions::permissionBase([], 'hr_holidays'));
        $this->assertSame('project', RecordActions::permissionBase([], 'projects'));
        $this->assertSame('task', RecordActions::permissionBase(['permissions' => ['task.view']], 'erp_erp_work_tasks'));
        $this->assertSame('erp_erp_work_task', RecordActions::permissionBase([], 'erp_erp_work_tasks'));
    }
}
