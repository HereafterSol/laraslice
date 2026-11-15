<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\BenchmarkGate;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6: BenchmarkGate::visibleText must grade the RENDERED
 * page, not the artefacts of its own extraction. Drawer pages
 * embed the form HTML as a JS string containing literal
 * '<script>' substrings; regex stripping mispaired those tags
 * and left live Alpine JS in the text, whose '<' comparisons
 * then made strip_tags eat the workplace rail. The parser-based
 * extractor keeps rendered text (incl. the rail) and drops
 * script payloads entirely.
 */
class BenchmarkGateVisibleTextTest extends TestCase
{
    private function fixture(): string
    {
        return <<<'HTML'
<html><body>
<script>
    window.Drawer = { payload: "<div><script>var a = 1;</" + "script></div>" };
</script>
<script>
    Alpine.data('repeater', () => ({ removeItem(i) { if (this.items.length < 2) return; this.items.splice(i, 1); } }));
</script>
<main>
    <label>Address Title</label>
    <aside>
        <span>Assign</span><span>Attachments</span><span>Tags</span><span>Share</span>
    </aside>
</main>
</body></html>
HTML;
    }

    public function test_rendered_rail_survives_extraction(): void
    {
        $text = BenchmarkGate::visibleText($this->fixture());
        foreach (['Assign', 'Attachments', 'Tags', 'Share', 'Address Title'] as $word) {
            $this->assertStringContainsString($word, $text);
        }
    }

    public function test_script_payload_does_not_count_as_visible(): void
    {
        $text = BenchmarkGate::visibleText($this->fixture());
        $this->assertStringNotContainsString('removeItem', $text);
        $this->assertStringNotContainsString('payload', $text);
    }

    public function test_find_absent_grades_absence(): void
    {
        $dir = sys_get_temp_dir().'/laraslice-gate-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/list-1440.html', '<html><body><h1>Languages</h1></body></html>');
        $spec = [
            'slice' => 'Languages',
            'threshold' => 90,
            'surfaces' => [
                'list' => ['elements' => [
                    ['label' => 'Title', 'find' => 'Languages'],
                    ['label' => 'No Add', 'find_absent' => 'Add Language'],
                ]],
            ],
            'behaviour' => [],
        ];
        $specPath = $dir.'/spec.yaml';
        file_put_contents($specPath, \Symfony\Component\Yaml\Yaml::dump($spec));
        $loaded = BenchmarkGate::loadSpec($specPath);
        $result = BenchmarkGate::score($loaded, $dir);
        $this->assertSame('PASS', $result['verdict']);
        // And the negative element must FAIL when the text is present.
        file_put_contents($dir.'/list-1440.html', '<html><body><h1>Languages</h1><button>Add Language</button></body></html>');
        $result2 = BenchmarkGate::score($loaded, $dir);
        $this->assertSame('FAIL', $result2['verdict']);
    }
}
