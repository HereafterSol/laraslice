<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\BenchmarkGate;
use PHPUnit\Framework\TestCase;

/**
 * Benchmark gate scorer: surface columns grade walkthrough
 * DOM captures (visible text only), the behaviour column
 * grades Heart declarations, and missing evidence is
 * NOT-CHECKED + FAIL — never a silent pass.
 */
class BenchmarkGateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ls-benchmark-'.uniqid();
        mkdir($this->dir.'/evidence', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            is_dir($f) ? array_map('unlink', glob($f.'/*') ?: []) && rmdir($f) : unlink($f);
        }
        @rmdir($this->dir.'/evidence');
        @rmdir($this->dir);
    }

    private function spec(): array
    {
        return [
            'slice' => 'Leads',
            'threshold' => 90.0,
            'surfaces' => [
                'list' => [
                    ['label' => 'Add action', 'find' => 'Add Lead', 'find_all' => [], 'weight' => 1],
                    ['label' => 'Tabs group', 'find' => null, 'find_all' => ['Details', 'Activities'], 'weight' => 2],
                ],
            ],
            'behaviour' => [
                ['label' => 'Full name computed', 'declaration' => 'fields.lead_name.computed', 'weight' => 1],
                ['label' => 'Conversions declared', 'declaration' => 'slice.conversions', 'weight' => 1],
            ],
        ];
    }

    public function test_visible_text_strips_scripts_and_tags(): void
    {
        $text = BenchmarkGate::visibleText('<html><head><style>.a{}</style><script>var x = "Add Lead";</script></head><body><h1>Real&nbsp;Title</h1></body></html>');
        $this->assertStringContainsString('Real Title', $text);
        $this->assertStringNotContainsString('Add Lead', $text);
    }

    public function test_full_marks_pass(): void
    {
        file_put_contents($this->dir.'/evidence/list-1440.html', '<body>Add Lead Details Activities</body>');
        $heart = $this->dir.'/heart.yaml';
        file_put_contents($heart, "fields:\n    lead_name:\n        computed: {from: [first_name], join: ' '}\nconversions: []\n");
        // conversions: [] is empty -> not present; use a non-empty map instead
        file_put_contents($heart, "fields:\n    lead_name:\n        computed: {from: [first_name], join: ' '}\nconversions:\n    opportunity: {target: Opportunities}\n");

        $result = BenchmarkGate::score($this->spec(), $this->dir.'/evidence', $heart);

        $this->assertSame('PASS', $result['verdict']);
        $this->assertSame(100.0, $result['overall']);
        $this->assertSame([1440], $result['columns']['list']['widths']);
    }

    public function test_missing_elements_fail_below_threshold(): void
    {
        file_put_contents($this->dir.'/evidence/list-1440.html', '<body>Add Lead</body>');
        $heart = $this->dir.'/heart.yaml';
        file_put_contents($heart, "fields:\n    lead_name:\n        computed: {from: [first_name], join: ' '}\n");

        $result = BenchmarkGate::score($this->spec(), $this->dir.'/evidence', $heart);

        $this->assertSame('FAIL', $result['verdict']);
        $this->assertSame(33.3, $result['columns']['list']['score']);
        $this->assertSame(50.0, $result['columns']['behaviour']['score']);
    }

    public function test_no_evidence_is_not_checked_and_fails(): void
    {
        $result = BenchmarkGate::score($this->spec(), $this->dir.'/evidence', null);

        $this->assertSame('FAIL', $result['verdict']);
        $this->assertNotSame([], $result['not_checked']);
        $this->assertTrue($result['columns']['list']['not_checked']);
        $this->assertTrue($result['columns']['behaviour']['not_checked']);
    }

    public function test_spec_loader_rejects_shapeless_specs(): void
    {
        $path = $this->dir.'/bad.yaml';
        file_put_contents($path, "slice: X\nsurfaces:\n    list:\n        elements:\n            - {label: 'no matcher'}\n");
        $this->expectException(\InvalidArgumentException::class);
        BenchmarkGate::loadSpec($path);
    }
}
