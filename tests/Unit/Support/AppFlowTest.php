<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\AppFlow;
use PHPUnit\Framework\TestCase;

class AppFlowTest extends TestCase
{
    public function test_build_groups_pages_and_links_journey_steps_by_label(): void
    {
        $mind = [
            'system' => ['name' => 'Ops Suite'],
            'journeys' => [[
                'name' => 'Hire an employee',
                'actor' => 'HR Manager',
                'steps' => ['Open Employees', 'Review the applicant', 'Send offer'],
                'outcome' => 'Employee onboarded.',
            ]],
        ];
        $pages = [
            ['label' => 'Employees', 'url' => '/erp/hr/employees', 'section' => 'HR'],
            ['label' => 'Projects', 'url' => '/erp/work/projects', 'section' => 'Work'],
        ];

        $flow = AppFlow::build($mind, $pages);

        $this->assertSame('Ops Suite', $flow['system']);
        $this->assertSame(['Employees'], array_column($flow['sections']['HR'], 'label'));
        $this->assertSame('Employees', $flow['journeys'][0]['steps'][0]['slice'], 'a step naming a slice label links to it');
        $this->assertNull($flow['journeys'][0]['steps'][1]['slice'], 'an unmatched step stays a plain declared step');
    }

    public function test_renderers_carry_the_system_and_the_links(): void
    {
        $flow = AppFlow::build(
            ['system' => ['name' => 'Ops Suite'], 'journeys' => [['name' => 'J', 'actor' => 'A', 'steps' => ['Open Employees'], 'outcome' => '']]],
            [['label' => 'Employees', 'url' => '/x', 'section' => 'HR']],
        );

        $html = AppFlow::renderHtml($flow);
        $this->assertStringContainsString('App Flow — Ops Suite', $html);
        $this->assertStringContainsString('Open Employees', $html);

        $mermaid = AppFlow::renderMermaid($flow);
        $this->assertStringStartsWith('flowchart LR', $mermaid);
        $this->assertStringContainsString('Employees', $mermaid);
    }

    public function test_build_without_a_mind_still_maps_navigation(): void
    {
        $flow = AppFlow::build(null, [['label' => 'Tasks', 'url' => '/t', 'section' => 'Work']]);
        $this->assertSame([], $flow['journeys']);
        $this->assertSame(['Tasks'], array_column($flow['sections']['Work'], 'label'));
    }
}
