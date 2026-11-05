<?php

namespace LaraSlice\Tests\Unit;

use LaraSlice\Support\FindingFixes;
use LaraSlice\Tests\TestCase;

class FindingFixesTest extends TestCase
{
    public function test_known_kinds_get_specific_hints(): void
    {
        $this->assertStringContainsString('slice:regen-form', FindingFixes::forKind('repeater-foreign-key'));
        $this->assertStringContainsString('slice:drift --fix', FindingFixes::forKind('club-grant-missing'));
    }

    public function test_unknown_kinds_fall_back_to_the_layer_rule(): void
    {
        $this->assertStringContainsString('declaration layer', FindingFixes::forKind('something-new'));
        $this->assertStringContainsString('declaration layer', FindingFixes::forKind(null));
    }

    public function test_conformance_lines_route_by_id(): void
    {
        $this->assertStringContainsString('design layer', FindingFixes::forLine('invoices.visual: token mismatch'));
        $this->assertStringContainsString('deviations.yaml', FindingFixes::forLine('tasks: deviation in fields'));
    }
}
