<?php

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\SlicePortService;
use LaraSlice\Tests\TestCase;

/**
 * Covers the porting engine's classification, tenancy detection, and safety behaviors.
 */
final class SlicePortServiceTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-port-fixture-'.bin2hex(random_bytes(6));
        mkdir($this->fixtureDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations', 0755, true);

        // Minimal legacy Laravel project fixture with multi-tenant tables, children, and a pivot.
        $migration = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('invoice_number')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('invoice_id');
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name')->nullable();
        });
        Schema::create('invoice_currency', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('currency_id');
        });
    }
};
PHP;
        file_put_contents(
            $this->fixtureDir.'/database/migrations/2024_01_01_000000_create_legacy_tables.php',
            $migration
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->fixtureDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_inspect_discovers_tables_and_detects_multitenancy(): void
    {
        $service = new SlicePortService;
        $inspection = $service->inspect($this->fixtureDir, 'Accounting');

        $this->assertSame(4, $inspection['total_tables']);
        $this->assertTrue($inspection['tenancy']['enabled']);
        $this->assertSame('company_id', $inspection['tenancy']['tenant_key']);

        // Invoices should be an aggregate root with invoice_items attached.
        $this->assertArrayHasKey('Invoices', $inspection['slice_mapping']);
        $mapping = $inspection['slice_mapping']['Invoices'];
        $this->assertSame('invoices', $mapping['primary_table']);
        $this->assertContains('invoice_items', $mapping['child_tables']);
    }

    public function test_pivot_tables_are_surfaced_not_dropped(): void
    {
        $service = new SlicePortService;
        $inspection = $service->inspect($this->fixtureDir, 'Accounting');

        // invoice_currency is a pivot (2 FKs, <= 5 columns): it must either attach
        // to a slice or appear in unmapped_pivots — never vanish silently.
        $allTables = ['invoices', 'invoice_items', 'currencies', 'invoice_currency'];
        $mappedTables = [];
        foreach ($inspection['slice_mapping'] as $info) {
            $mappedTables[] = $info['primary_table'];
            foreach ($info['child_tables'] ?? [] as $c) {
                $mappedTables[] = $c;
            }
            foreach ($info['pivot_tables'] ?? [] as $p) {
                $mappedTables[] = $p;
            }
        }
        $accounted = array_unique(array_merge($mappedTables, $inspection['unmapped_pivots'] ?? []));
        foreach (['invoices', 'invoice_items', 'invoice_currency'] as $t) {
            $this->assertContains($t, $accounted, "Table [{$t}] vanished from the port plan");
        }
        // Every source table must be accounted for somewhere (order-insensitive).
        $this->assertEmpty(array_diff($allTables, $accounted), 'Unaccounted tables: '.implode(', ', array_diff($allTables, $accounted)));
    }

    public function test_scaffold_slices_accepts_super_domain(): void
    {
        // Signature regression: scaffoldSlices must accept the super-domain parameter
        // so --super-domain is propagated instead of silently ignored.
        $ref = new \ReflectionMethod(SlicePortService::class, 'scaffoldSlices');
        $params = array_map(fn ($p) => $p->getName(), $ref->getParameters());
        $this->assertContains('superDomain', $params);
    }

    public function test_execute_accepts_import_data_and_source_connection(): void
    {
        $ref = new \ReflectionMethod(SlicePortService::class, 'execute');
        $params = array_map(fn ($p) => $p->getName(), $ref->getParameters());
        $this->assertContains('importData', $params);
        $this->assertContains('sourceConnection', $params);
    }

    public function test_row_counts_use_configured_connection_not_hardcoded_credentials(): void
    {
        // Regression: queryDatabaseRowCounts must not contain hardcoded PDO credentials.
        $src = file_get_contents((new \ReflectionClass(SlicePortService::class))->getFileName());
        $this->assertStringNotContainsString("dbname=erp', 'root', ''", $src);
        $this->assertStringNotContainsString('dbname=erp", "root", ""', $src);
    }

    public function test_build_ui_from_inspected_fields_accepts_null_workflow(): void
    {
        // Regression (cc644e1): buildUiFromInspectedFields must accept null workflow
        // without "Undefined variable $workflow" — scaffold path passes null when
        // no workflow transitions are detected.
        $service = new SlicePortService();
        $ui = $service->buildUiFromInspectedFields('Tasks', [
            ['name' => 'id', 'type' => 'bigint'],
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'status', 'type' => 'string'],
        ], 'company_id', [], null);

        $this->assertIsArray($ui);
        $this->assertArrayHasKey('directory', $ui);
        $this->assertArrayHasKey('bulk_actions', $ui['directory']);
        // Null workflow → native export/delete actions only
        $handles = array_column($ui['directory']['bulk_actions'], 'handle');
        $this->assertContains('export', $handles);
        $this->assertContains('delete', $handles);
    }

    public function test_build_ui_from_inspected_fields_with_workflow_transitions(): void
    {
        // Workflow transitions should seed bulk_actions when provided.
        $service = new SlicePortService();
        $ui = $service->buildUiFromInspectedFields('Tasks', [
            ['name' => 'id', 'type' => 'bigint'],
            ['name' => 'status', 'type' => 'string'],
        ], 'company_id', [], [
            'states' => ['todo', 'done'],
            'transitions' => [['from' => 'todo', 'to' => 'done', 'label' => 'Complete']],
        ]);

        $this->assertArrayHasKey('bulk_actions', $ui['directory']);
        $this->assertNotEmpty($ui['directory']['bulk_actions']);
    }

    public function test_build_ui_infers_rich_formatters_for_erp_fields(): void
    {
        // Phase 1 UI richness (handoff): infer status_pill, progress_bar, date_overdue,
        // money_breakdown, composite_user, text_with_badge from field names.
        $service = new SlicePortService();
        $ui = $service->buildUiFromInspectedFields('Tasks', [
            ['handle' => 'id', 'type' => 'bigint'],
            ['handle' => 'title', 'type' => 'string'],
            ['handle' => 'status', 'type' => 'string'],
            ['handle' => 'priority', 'type' => 'string'],
            ['handle' => 'due_date', 'type' => 'date'],
            ['handle' => 'progress', 'type' => 'integer'],
            ['handle' => 'user_id', 'type' => 'integer'],
            ['handle' => 'total', 'type' => 'decimal'],
            ['handle' => 'paid_amount', 'type' => 'decimal'],
        ], 'company_id', [], null);

        $byHandle = [];
        foreach ($ui['directory']['columns'] ?? [] as $col) {
            $byHandle[$col['handle'] ?? ''] = $col['formatter'] ?? '';
        }

        $this->assertSame('status_pill', $byHandle['status'] ?? null);
        $this->assertSame('date_overdue', $byHandle['due_date'] ?? null);
        $this->assertSame('progress_bar', $byHandle['progress'] ?? null);
        $this->assertSame('composite_user', $byHandle['user_id'] ?? null);
        $this->assertContains('board', $ui['directory']['views'] ?? []);
        $this->assertContains('status', $ui['directory']['inline_edit'] ?? []);
        // title may pick up priority badge
        if (isset($byHandle['title'])) {
            $this->assertTrue(
                in_array($byHandle['title'], ['text', 'text_with_badge'], true),
                'title formatter should be text or text_with_badge'
            );
        }
        // money pair
        if (isset($byHandle['total'])) {
            $this->assertSame('money_breakdown', $byHandle['total']);
        }
    }

    public function test_date_overdue_formatter_marks_past_dates(): void
    {
        $html = \LaraSlice\Support\DataTableRows::dateOverdue('2000-01-01');
        $this->assertStringContainsString('rose', $html);
        $html2 = \LaraSlice\Support\DataTableRows::dateOverdue(null);
        $this->assertStringContainsString('—', $html2);
    }

    public function test_dashboard_widgets_use_renderable_types_never_stat(): void
    {
        // Regression (fresh-port verification of v2.0.0): the porter emitted
        // dashboard widgets with type 'stat' (and 'board_preview'), but
        // DashboardUi's dispatch has no such types — unknown types resolve
        // to null, so a fresh port's dashboard silently rendered nothing.
        // Count widgets must be 'counter'; the by-status widget a 'chart'.
        $service = new SlicePortService;
        $ui = $service->buildUiFromInspectedFields('Tasks', [
            ['handle' => 'id', 'type' => 'bigint'],
            ['handle' => 'title', 'type' => 'string'],
            ['handle' => 'status', 'type' => 'string'],
        ], 'company_id', [], null);

        $widgets = $ui['dashboard']['widgets'] ?? [];
        $this->assertNotEmpty($widgets);
        $types = array_column($widgets, 'type');
        $this->assertNotContains('stat', $types, 'porter must never emit the unrenderable stat widget type');
        $this->assertNotContains('board_preview', $types, 'porter must never emit the unrenderable board_preview widget type');

        $byHandle = [];
        foreach ($widgets as $w) {
            $byHandle[$w['handle'] ?? ''] = $w;
        }
        $this->assertSame('counter', $byHandle['total']['type'] ?? null);
        $this->assertSame('counter', $byHandle['open']['type'] ?? null);
        $this->assertSame('chart', $byHandle['by_status']['type'] ?? null);
        $this->assertSame('status', $byHandle['by_status']['group_by'] ?? null);
        // Without declared own-scope info, counters stay unscoped.
        $this->assertArrayNotHasKey('scope', $byHandle['total']);
    }

    public function test_dashboard_counters_carry_declared_own_scope_inline(): void
    {
        // Portal-parity rule: when the slice's declared access.own_scope
        // clauses are available at emission time, the emitted counters
        // carry scope: own with the (first) clause inline — the shape
        // DashboardUi and the flagship dashboards consume.
        $service = new SlicePortService;
        $ui = $service->buildUiFromInspectedFields('Projects', [
            ['handle' => 'id', 'type' => 'bigint'],
            ['handle' => 'name', 'type' => 'string'],
            ['handle' => 'status', 'type' => 'string'],
        ], 'company_id', [], null, null, [['relation' => 'members', 'key' => 'id']]);

        $byHandle = [];
        foreach ($ui['dashboard']['widgets'] ?? [] as $w) {
            $byHandle[$w['handle'] ?? ''] = $w;
        }
        foreach (['total', 'open'] as $handle) {
            $this->assertSame('own', $byHandle[$handle]['scope'] ?? null, "{$handle} counter must be own-scoped");
            $this->assertSame(['relation' => 'members', 'key' => 'id'], $byHandle[$handle]['own'] ?? null);
        }
    }

    public function test_scaffolded_port_declares_root_model_and_renderable_widgets(): void
    {
        // End-to-end porter path: inspect the fixture, scaffold slices,
        // and read back the written manifest. It must declare the root
        // model (with child models non-root) and dashboard widgets of
        // types DashboardUi can actually render.
        $service = new SlicePortService;
        $inspection = $service->inspect($this->fixtureDir, 'Accounting');
        $result = $service->scaffoldSlices($inspection, 'ERP');

        $this->assertArrayHasKey('Invoices', $result['created']);
        $sliceDir = $result['created']['Invoices']['path'];
        $manifest = \Symfony\Component\Yaml\Yaml::parseFile($sliceDir.'/slice.yaml');

        $models = (array) ($manifest['models'] ?? []);
        $this->assertNotEmpty($models, 'ported manifest must declare models');
        $roots = array_values(array_filter($models, static fn ($m) => is_array($m) && ($m['root'] ?? false) === true));
        $this->assertCount(1, $roots, 'ported manifest must declare exactly one root model');
        $this->assertSame('invoice', $roots[0]['handle']);
        $this->assertSame('invoices', $roots[0]['table']);

        $childHandles = [];
        foreach ($models as $m) {
            if (($m['root'] ?? false) !== true) {
                $childHandles[] = $m['handle'] ?? null;
            }
        }
        $this->assertContains('invoice_item', $childHandles, 'child models stay declared as non-root');

        $types = array_column((array) ($manifest['ui']['dashboard']['widgets'] ?? []), 'type');
        $this->assertContains('counter', $types);
        $this->assertNotContains('stat', $types);
    }
}
