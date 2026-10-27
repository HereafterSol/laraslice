<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Porting\ConformanceGate;
use PHPUnit\Framework\TestCase;

class ConformanceGateTest extends TestCase
{
    private function anatomy(): array
    {
        return [
            'list' => [
                'placement' => 'below-facets-left',
                'facets' => [['item' => 'status'], ['item' => 'progress[]']],
                'columns' => [['item' => 'Code'], ['item' => 'Project Name'], ['item' => 'Action']],
            ],
            'detail' => ['tabs_observed' => ['Overview', 'Members']],
        ];
    }

    private function ui(string $placement = 'below-facets-left'): array
    {
        return [
            'pages' => ['list' => ['placement' => $placement]],
            'directory' => [
                'quick_filters' => [['handle' => 'status']],
                'sidebar_filters' => [['handle' => 'progress']],
                'columns' => [['label' => 'Code'], ['label' => 'Project Name']],
            ],
            'show' => ['tabs' => [['label' => 'Overview'], ['label' => 'Members'], ['label' => 'Activity']]],
        ];
    }

    public function test_compare_finds_only_real_differences(): void
    {
        $candidates = ConformanceGate::compare($this->anatomy(), $this->ui(), 'projects');
        // placement/facets/columns all agree (progress[] normalizes
        // to progress); only the extra Activity tab deviates.
        $this->assertSame(['projects.tabs-extra'], array_column($candidates, 'id'));
    }

    public function test_placement_mismatch_is_a_candidate(): void
    {
        $candidates = ConformanceGate::compare($this->anatomy(), $this->ui('header-right'), 'projects');
        $this->assertContains('projects.placement', array_column($candidates, 'id'));
    }

    public function test_evaluate_semantics(): void
    {
        $candidates = [
            ['id' => 'a.placement', 'kind' => 'placement', 'source' => 'x', 'port' => 'y'],
            ['id' => 'b.facets', 'kind' => 'facet', 'source' => 'x', 'port' => 'y'],
            ['id' => 'c.columns', 'kind' => 'column', 'source' => 'x', 'port' => 'y'],
        ];
        $register = [
            ['id' => 'b.facets', 'status' => 'open'],
            ['id' => 'c.columns', 'status' => 'approved', 'reason' => 'Source column is a legacy artifact', 'approved_by' => 'Abdur Rehman'],
            ['id' => 'z.stale', 'status' => 'open'],
        ];
        $result = ConformanceGate::evaluate($candidates, $register);
        $this->assertCount(1, $result['errors']);   // a.placement unrecorded
        $this->assertCount(1, $result['open']);     // b.facets
        $this->assertCount(1, $result['approved']); // c.columns
        $this->assertCount(1, $result['stale']);    // z.stale
    }

    public function test_approved_without_reason_or_approver_is_an_error(): void
    {
        $candidates = [['id' => 'a.x', 'kind' => 'placement', 'source' => 'x', 'port' => 'y']];
        $result = ConformanceGate::evaluate($candidates, [['id' => 'a.x', 'status' => 'approved']]);
        $this->assertCount(1, $result['errors']);
    }

    private function sourceTokens(): array
    {
        return ['tokens' => [
            'colors' => ['primary' => 'rgb(29, 130, 245)', 'sidebar' => 'rgb(23, 31, 41)'],
            'typography' => ['sans' => '"Helvetica Neue"', 'base_size' => '14px', 'table_header_size' => '13px', 'cell_size' => '13px'],
            'shell' => ['sidebar_width' => 240, 'sidebar_scheme' => 'dark'],
            'components' => ['button' => ['height' => 39], 'search' => ['height' => 31]],
        ]];
    }

    public function test_visual_gate_passes_identical_tokens(): void
    {
        $tokens = $this->sourceTokens()['tokens'];
        $this->assertSame([], ConformanceGate::visualCandidates($tokens, $tokens, 'employees'));
    }

    public function test_visual_gate_flags_identity_drift_as_one_candidate(): void
    {
        $source = $this->sourceTokens()['tokens'];
        $port = $source;
        $port['colors']['primary'] = 'rgb(10, 10, 10)';
        $port['shell']['sidebar_width'] = 256;
        $candidates = ConformanceGate::visualCandidates($source, $port, 'employees');
        $this->assertCount(1, $candidates);
        $this->assertSame('employees.visual', $candidates[0]['id']);
        $this->assertSame('visual', $candidates[0]['kind']);
        $this->assertStringContainsString('colors.primary=rgb(29, 130, 245)', $candidates[0]['source']);
    }

    public function test_visual_geometry_tolerance_is_two_px(): void
    {
        $source = $this->sourceTokens()['tokens'];
        $within = $source;
        $within['components']['button']['height'] = 41; // +2: passes
        $this->assertSame([], ConformanceGate::visualCandidates($source, $within, 'tasks'));
        $beyond = $source;
        $beyond['components']['button']['height'] = 32; // -7: fails
        $this->assertCount(1, ConformanceGate::visualCandidates($source, $beyond, 'tasks'));
    }

    public function test_visual_missing_port_evidence_fails_loudly(): void
    {
        $source = $this->sourceTokens()['tokens'];
        $candidates = ConformanceGate::visualCandidates($source, [], 'employees');
        $this->assertCount(1, $candidates);
        $this->assertStringContainsString('not captured', $candidates[0]['port']);
    }
}
