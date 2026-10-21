<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * G2: ui.directory.report groups records and computes declared
 * aggregates (count/sum/avg) with option-label resolution and a
 * totals row.
 */
class ReportCompositionTest extends TestCase
{
    private function manifest(): array
    {
        return [
            'name' => 'Task',
            'models' => [['fields' => [
                ['handle' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => [
                    ['value' => 'incomplete', 'label' => 'Incomplete'],
                    ['value' => 'completed', 'label' => 'Completed'],
                ]],
                ['handle' => 'amount', 'type' => 'decimal', 'label' => 'Amount'],
            ]]],
            'ui' => ['directory' => ['report' => [
                'group_by' => 'status',
                'aggregates' => [
                    ['fn' => 'count', 'label' => 'Tasks'],
                    ['column' => 'amount', 'fn' => 'sum', 'label' => 'Total Amount'],
                ],
            ]]],
        ];
    }

    public function test_report_groups_and_aggregates(): void
    {
        $records = [
            ['status' => 'completed', 'amount' => 100],
            ['status' => 'completed', 'amount' => 300],
            ['status' => 'incomplete', 'amount' => 50],
        ];
        $report = DirectoryUi::reportFromManifest($this->manifest(), $records);

        $this->assertNotNull($report);
        $this->assertSame('Task Report', $report['title']);
        $this->assertSame(['Completed', 'Incomplete'], array_column($report['rows'], 'label'));
        $this->assertSame(['2', '400.00'], $report['rows'][0]['values']);
        $this->assertSame(['3', '450.00'], $report['totals']);
        $this->assertSame(3, $report['count']);
    }

    public function test_report_requires_declaration(): void
    {
        $this->assertNull(DirectoryUi::reportFromManifest(['name' => 'X'], []));
    }
}
