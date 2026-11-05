<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * Replica P2 / Projects hub (1 Nov 2026): the composition
 * vocabulary the project hub needed — semicircle gauges with
 * declared side fields, text widgets, stats headings/columns,
 * bars styles, and the detail_fields: none suppression.
 */
class HubCompositionTest extends TestCase
{
    private function record(): HubRecord
    {
        return new HubRecord;
    }

    public function test_gauge_carries_variant_color_and_side_fields(): void
    {
        $manifest = ['ui' => ['show' => ['widgets' => [[
            'handle' => 'progress', 'type' => 'gauge', 'title' => 'Project Progress',
            'value' => ['field' => 'completion_percent'], 'max' => 100,
            'variant' => 'semicircle', 'color' => '#28a745',
            'fields' => [
                ['label' => 'Start Date', 'from' => 'start_date', 'type' => 'date'],
                ['label' => 'Deadline', 'from' => 'deadline', 'type' => 'date'],
            ],
        ]]]]];

        $widgets = DirectoryUi::showWidgetsFromManifest($manifest, $this->record());
        $this->assertCount(1, $widgets);
        $this->assertSame('semicircle', $widgets[0]['variant']);
        $this->assertSame('#28a745', $widgets[0]['color']);
        $this->assertSame(88.0, $widgets[0]['percent']);
        $this->assertSame('Start Date', $widgets[0]['fields'][0]['label']);
        $this->assertSame('13-12-2023', $widgets[0]['fields'][0]['value']);
        $this->assertSame('13-04-2024', $widgets[0]['fields'][1]['value']);
    }

    public function test_text_widget_reads_a_record_field_and_skips_when_empty(): void
    {
        $manifest = ['ui' => ['show' => ['widgets' => [
            ['handle' => 'details_text', 'type' => 'text', 'title' => 'Project Details', 'from' => 'summary'],
            ['handle' => 'missing_text', 'type' => 'text', 'title' => 'Nope', 'from' => 'absent_field'],
        ]]]];

        $widgets = DirectoryUi::showWidgetsFromManifest($manifest, $this->record());
        $this->assertCount(1, $widgets);
        $this->assertSame('text', $widgets[0]['type']);
        $this->assertSame('A short summary.', $widgets[0]['text']);
    }

    public function test_stats_carry_heading_columns_and_bars_style(): void
    {
        $manifest = ['ui' => ['show' => ['widgets' => [
            [
                'handle' => 'money', 'type' => 'stats', 'title' => 'Statistics',
                'layout' => 'cards', 'columns' => 3, 'heading' => 'Statistics',
                'measures' => [['label' => 'Budget', 'field' => 'budget', 'icon' => 'coins']],
            ],
            [
                'handle' => 'hours', 'type' => 'bars', 'title' => 'Hours', 'style' => 'columns',
                'bars' => [['label' => 'Actual', 'field' => 'hours']],
            ],
        ]]]];

        $widgets = DirectoryUi::showWidgetsFromManifest($manifest, $this->record());
        $this->assertCount(2, $widgets);
        $this->assertSame('Statistics', $widgets[0]['heading']);
        $this->assertSame(3, $widgets[0]['columns']);
        $this->assertSame('coins', $widgets[0]['rows'][0]['icon']);
        $this->assertSame('columns', $widgets[1]['style']);
    }

    public function test_detail_fields_none_suppresses_rows(): void
    {
        $prop = new \ReflectionProperty(DirectoryUi::class, 'manifestDataBySlice');
        $prop->setAccessible(true);
        $data = $prop->getValue();
        $data['NoDetailsPin'] = ['ui' => ['show' => ['detail_fields' => 'none']]];
        $prop->setValue(null, $data);

        $this->assertTrue(DirectoryUi::detailsSuppressed('NoDetailsPin'));
        $this->assertSame([], DirectoryUi::detailRows('NoDetailsPin', $this->record()));
    }
}

class HubRecord
{
    public float $completion_percent = 88.0;

    public string $start_date = '2023-12-13';

    public string $deadline = '2024-04-13';

    public string $summary = 'A short summary.';

    public float $budget = 0.0;

    public float $hours = 1.0;
}
