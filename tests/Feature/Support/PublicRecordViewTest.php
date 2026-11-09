<?php

namespace LaraSlice\Tests\Feature\Support;

use LaraSlice\Tests\TestCase;

/**
 * Regression pin (flagship audit, Phase 1 item 6): the anonymous
 * public record page must honour the detailRows() 'html' flag —
 * the same contract the authenticated show view honours — so a
 * formatter-generated status pill renders as a pill instead of
 * printing its markup as literal text to the client.
 */
class PublicRecordViewTest extends TestCase
{
    public function test_public_record_renders_html_flagged_rows_unescaped(): void
    {
        $this->app['view']->addLocation(dirname(__DIR__, 3).'/src/Wizard/views');

        $pill = '<span class="ls-status-pill" style="--pill-bg:#EEF2F3"><span class="ls-status-dot" style="background:#90509C"></span>Unpaid</span>';
        $html = view('public.record', [
            'title' => 'Invoice 97',
            'rows' => [
                ['label' => 'Status', 'value' => $pill, 'html' => true],
                ['label' => 'Note', 'value' => 'Paid <b>in full</b>', 'html' => false],
            ],
            'children' => [],
        ])->render();

        $this->assertStringContainsString($pill, $html);
        $this->assertStringNotContainsString('&lt;span class=&quot;ls-status-pill&quot;', $html);
        $this->assertStringContainsString('Paid &lt;b&gt;in full&lt;/b&gt;', $html);
    }
}
