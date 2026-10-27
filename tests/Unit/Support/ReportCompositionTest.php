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

    public function test_compare_aligns_sources_by_month_with_signed_net(): void
    {
        $decl = ['title' => 'Income vs Expense', 'compare' => [
            ['label' => 'Earnings', 'table' => 'payments', 'date_field' => 'paid_on', 'amount' => 'amount', 'sign' => 1],
            ['label' => 'Expenses', 'table' => 'expenses', 'date_field' => 'purchase_date', 'amount' => 'price', 'sign' => -1],
        ]];
        $sources = [
            ['label' => 'Earnings', 'sign' => 1, 'rows' => [
                ['date' => '2026-09-05', 'amount' => 1000],
                ['date' => '2026-10-02', 'amount' => 500],
                ['date' => null, 'amount' => 50],
            ]],
            ['label' => 'Expenses', 'sign' => -1, 'rows' => [
                ['date' => '2026-09-20', 'amount' => 300],
                ['date' => '2026-10-11', 'amount' => 900],
            ]],
        ];
        $report = DirectoryUi::compareFromManifest($decl, $sources);

        $this->assertNotNull($report);
        $this->assertSame('Income vs Expense', $report['title']);
        $this->assertSame(['Earnings', 'Expenses', 'Net'], $report['columns']);
        $this->assertSame(['September 2026', 'October 2026'], array_column($report['rows'], 'label'));
        $this->assertSame(['1,000.00', '300.00', '700.00'], $report['rows'][0]['values']);
        $this->assertSame(['500.00', '900.00', '-400.00'], $report['rows'][1]['values']);
        // Dateless rows count toward totals and cards, not periods.
        $this->assertSame(['1,550.00', '1,200.00', '350.00'], $report['totals']);
        $this->assertSame(5, $report['count']);
        $this->assertSame('Total Earnings', $report['cards'][0]['label']);
        $this->assertSame('350.00', $report['cards'][2]['value']);
    }

    public function test_compare_requires_declaration_and_sources(): void
    {
        $this->assertNull(DirectoryUi::compareFromManifest([], []));
        $this->assertNull(DirectoryUi::compareFromManifest(['compare' => [['label' => 'X']]], []));
    }
}
