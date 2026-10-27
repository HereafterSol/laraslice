<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Porting\SourceAnatomyExtractor;
use PHPUnit\Framework\TestCase;

class SourceAnatomyExtractorTest extends TestCase
{
    /** Trimmed from a real source probe (employees list, 23 Oct). */
    private function employeesProbe(): array
    {
        return [
            'url' => '/account/employees',
            'titleY' => 11, 'titleX' => 268,
            'primaryAction' => ['text' => 'Add Employee', 'x' => 268, 'y' => 127],
            'actions' => [['text' => 'Add Employee'], ['text' => 'Invite Employee'], ['text' => 'Import'], ['text' => 'Export']],
            'selects' => [
                ['name' => 'employee', 'y' => 71, 'options' => ['All', 'Alayna Fay', 'Marjolaine Altenwerth']],
                ['name' => 'designation', 'y' => 71, 'options' => ['All', 'Trainee', 'Senior']],
                ['name' => 'department', 'y' => 156, 'options' => ['All', 'Marketing', 'Sales']],
                ['name' => 'length', 'y' => 400, 'options' => ['10', '25', '50', '100']],
            ],
            'searches' => [['placeholder' => 'Start typing to search', 'y' => 70]],
            'headers' => ['', 'Employee ID', 'Name', 'Email', 'User Role', 'Status', 'Action'],
            'tableTop' => 188, 'cardTop' => 182, 'rowH' => 50,
            'paginationText' => 'Showing 1 to 10 of 12 entries',
            'tabGroups' => [],
        ];
    }

    public function test_employees_spec(): void
    {
        $spec = SourceAnatomyExtractor::extractList($this->employeesProbe(), 'employees');
        $list = $spec['pages']['list'];
        $this->assertSame('below-facets-left', $list['placement']);
        $this->assertSame(['employee', 'designation', 'department'], array_column($list['facets'], 'item'));
        $this->assertSame(['Employee ID', 'Name', 'Email', 'User Role', 'Status', 'Action'], array_column($list['columns'], 'item'));
        $this->assertTrue($list['select_column_observed']);
        $this->assertTrue($list['search_observed']);
        $this->assertSame('compact', $list['density']);
        $this->assertSame(10, $list['pagination']['page_size_observed']);
        $this->assertSame(12, $list['pagination']['total_observed']);
        $this->assertContains('Invite Employee', $list['actions_observed']);
    }

    public function test_header_right_classification(): void
    {
        $probe = $this->employeesProbe();
        $probe['primaryAction'] = ['text' => 'Add Employee', 'x' => 1100, 'y' => 14];
        $probe['cardTop'] = 300; $probe['tableTop'] = 306;
        $spec = SourceAnatomyExtractor::extractList($probe, 'employees');
        $this->assertSame('header-right', $spec['pages']['list']['placement']);
    }

    public function test_non_grid_views_suppress_density_and_say_so(): void
    {
        $probe = $this->employeesProbe();
        $probe['headers'] = [];
        $probe['rowH'] = 43;
        $spec = SourceAnatomyExtractor::extractList($probe, 'holidays');
        $this->assertSame('non-grid', $spec['pages']['list']['view_observed']);
        $this->assertNull($spec['pages']['list']['density']);
        $this->assertSame(43, $spec['pages']['list']['row_height_observed']);
    }

    public function test_rich_rows_leave_density_unnamed_but_recorded(): void
    {
        $probe = $this->employeesProbe();
        $probe['rowH'] = 97;
        $spec = SourceAnatomyExtractor::extractList($probe, 'projects');
        $this->assertNull($spec['pages']['list']['density']);
        $this->assertSame(97, $spec['pages']['list']['row_height_observed']);
    }

    public function test_detail_extraction_cleans_identity_and_noise(): void
    {
        $probe = [
            'url' => '/account/employees/1',
            'title' => 'Marjolaine Altenwerth',
            'tabs' => ['Profile', 'Projects', 'Leaves'],
            'sections' => [
                ['title' => null, 'fields' => [['label' => 'Marjolaine Altenwerth', 'value' => 'x']]],
                ['title' => 'Marjolaine Altenwerth', 'fields' => [['label' => 'Last login', 'value' => 'today'], ['label' => 'Status', 'value' => 'Active']]],
                ['title' => 'Profile Info', 'fields' => [
                    ['label' => 'Employee ID', 'value' => 'EMP-1'],
                    ['label' => 'Full Name', 'value' => 'Marjolaine Altenwerth'],
                ]],
                ['title' => 'Kyra Dare', 'fields' => [['label' => 'Shields, Daniel and Haley', 'value' => null]]],
            ],
            'actions' => [],
        ];
        $spec = SourceAnatomyExtractor::extractDetail($probe, 'employees');
        $detail = $spec['pages']['detail'];
        $this->assertSame('Marjolaine Altenwerth', $detail['identity_observed']);
        $this->assertSame(['Profile', 'Projects', 'Leaves'], $detail['tabs_observed']);
        $this->assertCount(1, $detail['sections']);
        $this->assertSame('Profile Info', $detail['sections'][0]['title']);
        $this->assertNotNull($detail['actions_note']);
    }

    /** Design block as the capture harness samples it (source list page, 24 Oct). */
    private function designProbe(): array
    {
        return [
            'design' => [
                'body' => ['fontFamily' => '"Helvetica Neue"', 'fontSize' => '14px', 'color' => 'rgb(40, 49, 60)', 'backgroundColor' => 'rgb(255, 255, 255)'],
                'sidebar' => ['width' => 240, 'backgroundColor' => 'rgb(23, 31, 41)'],
                'primary_button' => ['height' => 39, 'backgroundColor' => 'rgb(29, 130, 245)', 'color' => 'rgb(255, 255, 255)', 'borderRadius' => '4px', 'fontSize' => '14px', 'fontWeight' => '400'],
                'heading' => ['fontSize' => '18px', 'fontWeight' => '700'],
                'table_header' => ['fontSize' => '13px', 'fontWeight' => '500', 'color' => 'rgb(153, 165, 181)'],
                'cell' => ['fontSize' => '13px'],
                'card' => ['borderRadius' => '4px', 'boxShadow' => 'rgb(210, 217, 228) 0px 0px 4px 0px'],
                'search' => ['height' => 31, 'borderRadius' => '0px 4px 4px 0px'],
                'status_pill' => null,
            ],
            'forms' => ['create' => 'drawer'],
        ];
    }

    public function test_design_extraction_maps_tokens_and_derives_dark_scheme(): void
    {
        $spec = SourceAnatomyExtractor::extractDesign($this->designProbe());
        $this->assertSame('rgb(29, 130, 245)', $spec['colors']['primary']);
        $this->assertSame('rgb(23, 31, 41)', $spec['colors']['sidebar']);
        $this->assertSame('dark', $spec['shell']['sidebar_scheme']);
        $this->assertSame(240, $spec['shell']['sidebar_width']);
        $this->assertSame('"Helvetica Neue"', $spec['typography']['sans']);
        $this->assertSame(39, $spec['components']['button']['height']);
        $this->assertSame('4px', $spec['components']['card']['radius']);
        $this->assertNull($spec['components']['status_pill']);
        $this->assertSame('drawer', $spec['forms']['create']);
        $this->assertSame('observed', $spec['decision']);
    }

    public function test_design_extraction_absent_block_returns_null(): void
    {
        $this->assertNull(SourceAnatomyExtractor::extractDesign($this->employeesProbe()));
    }

    public function test_design_divergences_lists_differing_dotted_keys(): void
    {
        $reference = SourceAnatomyExtractor::extractDesign($this->designProbe());
        $candidate = $this->designProbe();
        $candidate['design']['primary_button']['backgroundColor'] = 'rgb(0, 0, 0)';
        $candidate['design']['sidebar']['width'] = 256;
        $candidateSpec = SourceAnatomyExtractor::extractDesign($candidate);
        $this->assertSame(
            ['colors.primary', 'shell.sidebar_width'],
            SourceAnatomyExtractor::designDivergences($reference, $candidateSpec)
        );
    }

    public function test_interactions_classify_pages_and_filter_chrome(): void
    {
        $page = fn (array $texts, array $extra = []): array => [
            'actions' => array_map(fn (string $t): array => ['t' => $t, 'region' => 'header'], $texts),
            ...$extra,
        ];
        $inventory = [
            '/account/invoices' => $page(['Add Client', 'Start Timer', 'Export', 'Send', 'Create Duplicate', 'Download', '2'], ['hasCheckbox' => true]),
            '/account/attendances' => $page(['Add Client', 'Start Timer', 'Export', 'Import', 'Mark Attendance']),
            '/account/attendance-report' => $page(['Add Client', 'Start Timer', 'Export']),
            '/account/settings/notifications' => $page(['Add Client', 'Start Timer', 'Save']),
            '/account/invoices/19' => $page(['Add Client', 'Start Timer', 'Send', 'View PDF']),
        ];
        $out = SourceAnatomyExtractor::extractInteractions($inventory);

        // 'Add Client' + 'Start Timer' appear on 100% of pages = chrome, excluded.
        $this->assertSame('list', $out['/account/invoices']['type']);
        $this->assertTrue($out['/account/invoices']['export']);
        $this->assertFalse($out['/account/invoices']['import']);
        $this->assertTrue($out['/account/invoices']['bulk']);
        $this->assertSame(['Create Duplicate', 'Download', 'Send'], $out['/account/invoices']['workflow_actions']);
        $this->assertTrue($out['/account/attendances']['import']);
        $this->assertSame(['Mark Attendance'], $out['/account/attendances']['workflow_actions']);
        $this->assertSame('report', $out['/account/attendance-report']['type']);
        $this->assertSame('settings', $out['/account/settings/notifications']['type']);
        $this->assertSame('detail', $out['/account/invoices/19']['type']);
        $this->assertSame(['Send', 'View PDF'], $out['/account/invoices/19']['workflow_actions']);
    }

    public function test_declaration_hints_flag_non_default_create_labels(): void
    {
        $this->assertSame(
            ['create_label' => 'New Leave'],
            SourceAnatomyExtractor::declarationHints(['primaryAction' => ['text' => 'New Leave']], 'leaves')
        );
        $this->assertSame(
            ['create_label' => 'Mark Attendance'],
            SourceAnatomyExtractor::declarationHints(['primaryAction' => ['text' => 'Mark Attendance']], 'attendances')
        );
        // The generator default produces no hint.
        $this->assertSame([], SourceAnatomyExtractor::declarationHints(['primaryAction' => ['text' => 'Add Employee']], 'employees'));
        $this->assertSame([], SourceAnatomyExtractor::declarationHints([], 'employees'));
    }

    public function test_design_scheme_derives_from_oklch_lightness(): void
    {
        $light = SourceAnatomyExtractor::extractDesign(['design' => ['sidebar' => ['backgroundColor' => 'oklch(1 0 0)', 'width' => 256]]]);
        $this->assertSame('light', $light['shell']['sidebar_scheme']);
        $dark = SourceAnatomyExtractor::extractDesign(['design' => ['sidebar' => ['backgroundColor' => 'oklch(0.205 0 0)', 'width' => 256]]]);
        $this->assertSame('dark', $dark['shell']['sidebar_scheme']);
    }
}
