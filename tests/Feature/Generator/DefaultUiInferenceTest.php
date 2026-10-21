<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Support\FieldInference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * F5: generator inference parity. A slice generated WITHOUT a declared
 * ui must still get the porter's directory intelligence — inferred
 * formatters, status/priority quick filters, and summary cards — in
 * its manifest, from the shared FieldInference rules. Before F5 the
 * manifest carried no ui at all and listings rendered plain text.
 */
class DefaultUiInferenceTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-f5-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->slicesPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->slicesPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->slicesPath);
        }
        parent::tearDown();
    }

    public function test_inference_rules_pin_the_proven_cases(): void
    {
        $this->assertSame('status_pill', FieldInference::formatterFor('status'));
        $this->assertSame('status_pill', FieldInference::formatterFor('priority'));
        $this->assertSame('money_breakdown', FieldInference::formatterFor('total_amount', 'decimal'));
        $this->assertSame('text', FieldInference::formatterFor('latitude', 'decimal'), 'geo decimals are not money');
        $this->assertSame('text', FieldInference::formatterFor('site_longitude', 'decimal'));
        $this->assertSame('date_overdue', FieldInference::formatterFor('due_date', 'date'));
        $this->assertSame('date_time', FieldInference::formatterFor('created_at', 'datetime'));
        $this->assertSame('progress_bar', FieldInference::formatterFor('completion_percent', 'integer'));
        $this->assertSame('code_badge', FieldInference::formatterFor('invoice_number'));
        $this->assertSame('composite_user', FieldInference::formatterFor('assignee_id', 'integer'));
        $this->assertSame('text', FieldInference::formatterFor('email'));
    }

    public function test_generated_slice_without_ui_gets_inferred_directory(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('WorkOrder', [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'status', 'type' => 'string'],
            ['name' => 'total_amount', 'type' => 'decimal'],
            ['name' => 'due_date', 'type' => 'date'],
            ['name' => 'assignee_id', 'type' => 'integer'],
        ]);

        $manifestPath = $path.'/slice.yaml';
        $this->assertFileExists($manifestPath);
        $manifest = Yaml::parseFile($manifestPath);

        $columns = $manifest['ui']['directory']['columns'] ?? [];
        $formatters = array_column($columns, 'formatter', 'handle');
        $this->assertSame('status_pill', $formatters['status'] ?? null);
        $this->assertSame('money_breakdown', $formatters['total_amount'] ?? null);
        $this->assertSame('date_overdue', $formatters['due_date'] ?? null);
        $this->assertSame('composite_user', $formatters['assignee_id'] ?? null);

        $filterHandles = array_column($manifest['ui']['directory']['quick_filters'] ?? [], 'handle');
        $this->assertContains('status', $filterHandles);

        $cards = $manifest['ui']['directory']['summary_cards'] ?? [];
        $this->assertSame('total_amount', $cards[0]['handle'] ?? null);
        $this->assertSame('sum', $cards[0]['aggregate'] ?? null);

        $this->assertContains('board', $manifest['ui']['directory']['views'] ?? [], 'status slices get the board view');
    }

    public function test_declared_directory_columns_are_never_overridden(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Ledger', [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'status', 'type' => 'string'],
        ], false, [
            'ui' => ['directory' => ['columns' => [
                ['handle' => 'title', 'label' => 'Title', 'formatter' => 'text'],
            ]]],
        ]);

        $manifest = Yaml::parseFile($path.'/slice.yaml');
        $columns = $manifest['ui']['directory']['columns'] ?? [];
        $this->assertCount(1, $columns, 'declared columns win; no inferred directory is merged in');
        $this->assertSame('title', $columns[0]['handle']);
    }
}
