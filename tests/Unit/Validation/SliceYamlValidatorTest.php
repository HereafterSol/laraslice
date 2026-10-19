<?php

namespace LaraSlice\Tests\Unit\Validation;

use LaraSlice\Validation\SliceYamlValidator;
use LaraSlice\Tests\TestCase;

final class SliceYamlValidatorTest extends TestCase
{
    private SliceYamlValidator $v;

    private string $pilotDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new SliceYamlValidator;
        $this->pilotDir = dirname(__DIR__, 3).'/resources/pilot';
    }

    public function test_pilot_tasks_yaml_passes(): void
    {
        $path = $this->pilotDir.'/tasks.slice.yaml';
        if (! is_file($path)) {
            $this->markTestSkipped('pilot tasks yaml missing');
        }
        $errors = $this->v->validateFile($path);
        $this->assertSame([], $errors, json_encode($errors));
    }

    public function test_pilot_projects_yaml_passes(): void
    {
        $path = $this->pilotDir.'/projects.slice.yaml';
        if (! is_file($path)) {
            $this->markTestSkipped('pilot projects yaml missing');
        }
        $this->assertSame([], $this->v->validateFile($path));
    }

    public function test_pilot_invoices_yaml_passes(): void
    {
        $path = $this->pilotDir.'/invoices.slice.yaml';
        if (! is_file($path)) {
            $this->markTestSkipped('pilot invoices yaml missing');
        }
        $this->assertSame([], $this->v->validateFile($path));
    }

    public function test_rejects_unknown_formatter(): void
    {
        $errors = $this->v->validate([
            'name' => 'Broken',
            'ui' => [
                'directory' => [
                    'columns' => [
                        ['key' => 'title', 'formatter' => 'rainbow_sparkles'],
                    ],
                ],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $paths = array_column($errors, 'path');
        $this->assertContains('ui.directory.columns[0].formatter', $paths);
    }

    public function test_rejects_missing_name(): void
    {
        $errors = $this->v->validate([
            'ui' => ['directory' => ['columns' => [['key' => 'id', 'formatter' => 'text']]]],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertSame('name', $errors[0]['path']);
    }

    public function test_rejects_html_render_in_column(): void
    {
        $errors = $this->v->validate([
            'name' => 'X',
            'ui' => [
                'directory' => [
                    'columns' => [
                        ['key' => 'title', 'formatter' => 'text', 'render' => '<b>x</b>'],
                    ],
                ],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('HTML', $errors[0]['message']);
    }

    public function test_rejects_invalid_field_type(): void
    {
        $errors = $this->v->validate([
            'name' => 'X',
            'fields' => [
                'title' => ['handle' => 'title', 'type' => 'varchar255'],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('varchar255', $errors[0]['message']);
    }

    public function test_canonical_v01_minimal_passes(): void
    {
        $errors = $this->v->validate([
            'spec_version' => '0.1',
            'name' => 'Tasks',
            'handle' => 'task',
            'models' => [
                [
                    'handle' => 'task',
                    'table' => 'tasks',
                    'root' => true,
                    'fields' => [
                        ['handle' => 'title', 'type' => 'string'],
                        ['handle' => 'status', 'type' => 'string'],
                    ],
                ],
            ],
            'navigation' => ['group' => 'Work', 'label' => 'Tasks'],
        ]);
        $this->assertSame([], $errors);
    }

    public function test_select_as_type_suggests_input_key(): void
    {
        $errors = $this->v->validate([
            'name' => 'X',
            'fields' => [
                'status' => ['handle' => 'status', 'type' => 'select'],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('input widget', $errors[0]['message']);
        $this->assertStringContainsString('input: select', $errors[0]['message']);
    }

    public function test_string_with_input_select_passes(): void
    {
        $errors = $this->v->validate([
            'name' => 'X',
            'fields' => [
                'status' => [
                    'handle' => 'status',
                    'type' => 'string',
                    'input' => 'select',
                    'options_from' => 'distinct:status',
                ],
            ],
        ]);
        $this->assertSame([], $errors);
    }
}
