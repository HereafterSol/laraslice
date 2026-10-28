<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\SurfaceDerivationPipeline;
use LaraSlice\Tests\TestCase;

final class SurfaceDerivationPipelineTest extends TestCase
{
    private SurfaceDerivationPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = new SurfaceDerivationPipeline;
    }

    public function test_derives_surface_from_source_and_probe(): void
    {
        $fixtures = dirname(__DIR__, 2).'/Fixtures';
        $probe = [
            'headers' => ['', 'Employee ID', 'Name', 'Email', 'User Role', 'Status', 'Action'],
            'selects' => [
                ['name' => 'employee', 'options' => ['All', 'John Doe'], 'y' => 50],
                ['name' => 'designation', 'options' => ['All', 'Senior'], 'y' => 50],
            ],
            'actions' => [
                ['text' => 'Add Employee'],
                ['text' => 'Invite Employee'],
                ['text' => 'Import'],
                ['text' => 'Export'],
            ],
            'tableTop' => 200,
            'titleY' => 20,
        ];

        $derived = $this->pipeline->derive($fixtures, 'Employees', $probe, [
            'name' => 'Employees',
            'models' => [
                [
                    'handle' => 'employee',
                    'fields' => [
                        ['handle' => 'employee_id', 'type' => 'string'],
                        ['handle' => 'name', 'type' => 'string'],
                        ['handle' => 'email', 'type' => 'string'],
                        ['handle' => 'role', 'type' => 'string'],
                        ['handle' => 'status', 'type' => 'string'],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('ui', $derived);
        $ui = $derived['ui'];

        // Directory columns from getColumns
        $columns = $ui['directory']['columns'] ?? [];
        $this->assertCount(5, $columns);
        $this->assertSame('employee_id', $columns[0]['handle']);
        $this->assertSame('code_badge', $columns[0]['formatter']);
        $this->assertSame('name', $columns[1]['handle']);
        $this->assertSame('composite_user', $columns[1]['formatter']);
        $this->assertSame('email', $columns[2]['handle']);
        $this->assertSame('text', $columns[2]['formatter']);
        $this->assertSame('role', $columns[3]['handle']);
        $this->assertSame('text', $columns[3]['formatter']);
        $this->assertTrue($columns[3]['inline_edit'] ?? false);
        $this->assertSame('status', $columns[4]['handle']);
        $this->assertSame('status_pill', $columns[4]['formatter']);

        // Probe-derived facets and actions
        $this->assertNotEmpty($ui['directory']['quick_filters']);
        $this->assertSame('employee', $ui['directory']['quick_filters'][0]['handle']);
        $this->assertSame('designation', $ui['directory']['quick_filters'][1]['handle']);
        $this->assertNotEmpty($ui['directory']['actions']);
        $this->assertSame('invite_employee', $ui['directory']['actions'][0]['handle']);
    }
}
