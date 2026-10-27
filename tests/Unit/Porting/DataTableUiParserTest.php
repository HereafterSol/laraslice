<?php

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\DataTableUiParser;
use LaraSlice\Tests\TestCase;

final class DataTableUiParserTest extends TestCase
{
    private string $fixtures;

    private DataTableUiParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = dirname(__DIR__, 2).'/Fixtures';
        $this->parser = new DataTableUiParser;
    }

    public function test_tasks_datatable_extracts_status_due_and_assignee(): void
    {
        $h = $this->parser->parseForSlice($this->fixtures, 'Tasks');
        $this->assertSame('TasksDataTable.php', $h['file']);
        $cols = $h['columns'];
        $this->assertSame('date_overdue', $cols['due_date']['formatter'] ?? null);
        $this->assertSame('status_pill', $cols['board_column']['formatter'] ?? $cols['status']['formatter'] ?? null);
        $this->assertSame('composite_user', $cols['users']['formatter'] ?? null);
        $this->assertContains($cols['due_date']['formatter'], DataTableUiParser::FORMATTERS);
    }

    public function test_projects_datatable_extracts_progress_and_deadline(): void
    {
        $h = $this->parser->parseForSlice($this->fixtures, 'Projects');
        $this->assertSame('ProjectsDataTable.php', $h['file']);
        $cols = $h['columns'];
        $this->assertSame('date_overdue', $cols['deadline']['formatter'] ?? null);
        $this->assertSame('progress_bar', $cols['completion_percent']['formatter'] ?? null);
        $this->assertSame('composite_user', $cols['members']['formatter'] ?? null);
    }

    public function test_invoices_datatable_extracts_money_and_status(): void
    {
        $h = $this->parser->parseForSlice($this->fixtures, 'Invoices');
        $this->assertSame('InvoicesDataTable.php', $h['file']);
        $cols = $h['columns'];
        $this->assertSame('money_breakdown', $cols['total']['formatter'] ?? null);
        $this->assertSame('status_pill', $cols['status']['formatter'] ?? null);
        $this->assertSame('code_badge', $cols['invoice_number']['formatter'] ?? null);
        $this->assertArrayHasKey('breakdown', $cols['total']);
    }

    public function test_merge_overrides_phase1_guesses(): void
    {
        $ui = [
            'directory' => [
                'columns' => [
                    ['handle' => 'due_date', 'formatter' => 'date_time'],
                    ['handle' => 'total', 'formatter' => 'text'],
                ],
                'sidebar_filters' => [],
            ],
        ];
        $hints = $this->parser->parseForSlice($this->fixtures, 'Invoices');
        $merged = $this->parser->mergeIntoUi($ui, $hints);
        $by = [];
        foreach ($merged['directory']['columns'] as $c) {
            $by[$c['handle']] = $c['formatter'];
        }
        $this->assertSame('money_breakdown', $by['total']);
        $this->assertSame('status_pill', $by['status'] ?? null);
    }

    public function test_missing_source_returns_empty_not_exception(): void
    {
        $h = $this->parser->parseForSlice('/tmp/does-not-exist-xyz', 'Tasks');
        $this->assertNull($h['file']);
        $this->assertSame([], $h['columns']);
    }

    public function test_formatters_stay_in_v0_1_vocabulary(): void
    {
        foreach (['Tasks', 'Projects', 'Invoices'] as $slice) {
            $h = $this->parser->parseForSlice($this->fixtures, $slice);
            foreach ($h['columns'] as $meta) {
                $this->assertContains(
                    $meta['formatter'],
                    DataTableUiParser::FORMATTERS,
                    "{$slice}.{$meta['handle']} emitted non-v0.1 formatter"
                );
            }
        }
    }
}
