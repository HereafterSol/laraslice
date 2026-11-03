<?php

namespace LaraSlice\Tests\Unit;

use LaraSlice\Support\RecordActions;
use LaraSlice\Tests\TestCase;

class RecordActionsV2Test extends TestCase
{
    public function test_link_copy_send_and_method_declarations_are_normalized(): void
    {
        $raw = ['ui' => ['actions' => [
            ['name' => 'download', 'label' => 'Download', 'type' => 'link', 'url' => '{show}?print=1', 'new_tab' => true],
            ['name' => 'copy_link', 'label' => 'Copy', 'type' => 'copy', 'url' => '{public}'],
            ['name' => 'send_it', 'label' => 'Send', 'type' => 'send'],
            ['name' => 'convert', 'label' => 'Convert', 'type' => 'method', 'method' => 'convertToClient'],
        ]]];

        $actions = RecordActions::declaredActions($raw);

        $this->assertCount(4, $actions);
        $this->assertSame('{show}?print=1', $actions[0]['url']);
        $this->assertTrue($actions[0]['new_tab']);
        $this->assertSame('{public}', $actions[1]['url']);
        $this->assertSame('send', $actions[2]['type']);
        $this->assertSame('convertToClient', $actions[3]['method']);
    }

    public function test_link_without_url_and_method_without_method_are_dropped(): void
    {
        $raw = ['ui' => ['actions' => [
            ['name' => 'broken_link', 'label' => 'Broken', 'type' => 'link'],
            ['name' => 'broken_copy', 'label' => 'Broken', 'type' => 'copy'],
            ['name' => 'broken_method', 'label' => 'Broken', 'type' => 'method'],
            ['name' => 'mystery', 'label' => 'Mystery', 'type' => 'teleport'],
        ]]];

        $this->assertSame([], RecordActions::declaredActions($raw));
    }

    public function test_v1_types_still_normalize(): void
    {
        $raw = ['ui' => ['actions' => [
            ['name' => 'duplicate', 'label' => 'Duplicate', 'type' => 'duplicate'],
            ['name' => 'archive', 'label' => 'Archive', 'type' => 'transition', 'field' => 'status', 'value' => 'archived'],
        ]]];

        $actions = RecordActions::declaredActions($raw);

        $this->assertCount(2, $actions);
        $this->assertNull($actions[0]['url']);
        $this->assertFalse($actions[0]['new_tab']);
    }
}
