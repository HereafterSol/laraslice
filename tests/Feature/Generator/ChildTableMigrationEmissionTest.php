<?php

namespace LaraSlice\Tests\Feature\Generator;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Generator\ArtefactRegenerator;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;

/**
 * D-014: the slice migration builder emitted only the
 * parent table's Schema::create, so every declared
 * repeater / multiselect child table needed a hand-written
 * slice migration (port rounds 10-11: 000004-000009,
 * 000013, 000014, 000018). The generator now emits the
 * child tables in the same migration, and the regen path
 * warns loudly when a declared child table does not exist
 * in the target app's database. A slice with NO declared
 * children must keep producing the parent-only migration
 * it produced before (verified byte-identical against a
 * pre-change generation when this test was written).
 */
class ChildTableMigrationEmissionTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-d014-'.bin2hex(random_bytes(8));
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

    private function generateProbe(): string
    {
        return (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('ChildProbe', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true],
            ['name' => 'lines', 'type' => 'repeater', 'label' => 'Lines',
                'table' => 'probe_lines', 'foreign_key' => 'child_probe_id',
                'repeater_fields' => [
                    ['handle' => 'item_name', 'label' => 'Item Name', 'type' => 'string', 'required' => true],
                    ['handle' => 'quantity', 'label' => 'Quantity', 'type' => 'integer', 'required' => true],
                    ['handle' => 'price', 'label' => 'Price', 'type' => 'decimal'],
                    ['handle' => 'notes', 'label' => 'Notes', 'type' => 'text'],
                    ['handle' => 'active', 'label' => 'Active', 'type' => 'boolean'],
                    ['handle' => 'due_on', 'label' => 'Due On', 'type' => 'date'],
                ]],
            ['name' => 'picks', 'type' => 'multiselect', 'label' => 'Picks',
                'table' => 'probe_picks', 'foreign_key' => 'parent_id',
                'target_field' => 'company', 'target' => 'Companies',
                'parent_type' => 'ChildProbe'],
        ], false, ['table' => 'child_probes']);
    }

    private function migrationOf(string $slicePath): string
    {
        $migration = (string) file_get_contents(glob($slicePath.'/Migrations/*.php')[0]);
        token_get_all($migration, TOKEN_PARSE);

        return $migration;
    }

    public function test_repeater_and_multiselect_child_tables_are_emitted_in_the_slice_migration(): void
    {
        $migration = $this->migrationOf($this->generateProbe());

        // Parent + both children, nothing else.
        $this->assertSame(3, substr_count($migration, 'Schema::create('));
        $this->assertStringContainsString("Schema::create('child_probes'", $migration);

        // Repeater child: FK constrained to the parent with
        // cascade, typed sub-field columns, timestamps, no
        // audit columns (the hand-written repeater shape).
        $this->assertStringContainsString("if (! Schema::hasTable('probe_lines'))", $migration);
        $this->assertStringContainsString("Schema::create('probe_lines'", $migration);
        $this->assertStringContainsString("\$table->foreignId('child_probe_id')->constrained('child_probes')->cascadeOnDelete();", $migration);
        $this->assertStringContainsString("\$table->string('item_name');", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('quantity');", $migration);
        $this->assertStringContainsString("\$table->decimal('price', 12, 2)->nullable();", $migration);
        $this->assertStringContainsString("\$table->text('notes')->nullable();", $migration);
        $this->assertStringContainsString("\$table->boolean('active')->nullable();", $migration);
        $this->assertStringContainsString("\$table->date('due_on')->nullable();", $migration);

        // Multiselect child: indexed parent key, declared
        // parent_type, the target link column, audit columns
        // as plain nullable unsignedBigInteger, timestamps
        // (the hand-written item_allowed_companies shape).
        $this->assertStringContainsString("if (! Schema::hasTable('probe_picks'))", $migration);
        $this->assertStringContainsString("Schema::create('probe_picks'", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('parent_id')->index();", $migration);
        $this->assertStringContainsString("\$table->string('parent_type')->nullable();", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('company');", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('created_by')->nullable();", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('updated_by')->nullable();", $migration);

        // down() drops the children (reverse order) before
        // the parent.
        $picksDrop = strpos($migration, "Schema::dropIfExists('probe_picks')");
        $linesDrop = strpos($migration, "Schema::dropIfExists('probe_lines')");
        $parentDrop = strpos($migration, "Schema::dropIfExists('child_probes')");
        $this->assertNotFalse($picksDrop);
        $this->assertNotFalse($linesDrop);
        $this->assertNotFalse($parentDrop);
        $this->assertLessThan($linesDrop, $picksDrop);
        $this->assertLessThan($parentDrop, $linesDrop);
    }

    public function test_multiselect_without_parent_type_emits_no_parent_type_column(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('PlainPick', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
            ['name' => 'picks', 'type' => 'multiselect', 'label' => 'Picks',
                'table' => 'plain_picks', 'foreign_key' => 'plain_pick_id',
                'target_field' => 'reason', 'target' => 'Reasons'],
        ], false, ['table' => 'plain_picks_parent']);

        $migration = $this->migrationOf($path);
        $this->assertStringContainsString("Schema::create('plain_picks'", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('plain_pick_id')->index();", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('reason');", $migration);
        // parent_type is emitted only inside the child block
        // that declares one — this slice declares none, and
        // the parent table carries no such column either.
        $childBlock = substr($migration, strpos($migration, "Schema::create('plain_picks'"));
        $this->assertStringNotContainsString('parent_type', $childBlock);
    }

    public function test_ui_dialect_repeater_child_table_is_emitted(): void
    {
        $ui = ['forms' => ['sections' => [
            ['key' => 'general', 'title' => 'General', 'grid' => 2, 'fields' => [
                ['handle' => 'invoice_number', 'span' => 1],
            ]],
            ['key' => 'items_items', 'title' => 'Items', 'grid' => 1, 'fields' => [[
                'handle' => 'items',
                'type' => 'repeater',
                'label' => 'Items',
                'model' => 'invoice_item',
                'table' => 'invoice_items',
                'foreign_key' => 'invoice_id',
                'repeater_fields' => [
                    ['handle' => 'item_name', 'label' => 'Item Name', 'type' => 'string', 'required' => true],
                    ['handle' => 'quantity', 'label' => 'Quantity', 'type' => 'integer', 'required' => true],
                    ['handle' => 'price', 'label' => 'Price', 'type' => 'decimal', 'required' => true],
                ],
            ]]],
        ]]];

        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice', [
            ['name' => 'invoice_number', 'type' => 'string', 'label' => 'Invoice Number', 'required' => true],
            ['name' => 'total', 'type' => 'decimal', 'label' => 'Total'],
        ], false, ['table' => 'invoices', 'ui' => $ui]);

        $migration = $this->migrationOf($path);
        $this->assertSame(2, substr_count($migration, 'Schema::create('));
        $this->assertStringContainsString("Schema::create('invoice_items'", $migration);
        $this->assertStringContainsString("\$table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();", $migration);
        $this->assertStringContainsString("\$table->string('item_name');", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('quantity');", $migration);
        $this->assertStringContainsString("\$table->decimal('price', 12, 2);", $migration);
        $this->assertStringContainsString("Schema::dropIfExists('invoice_items');", $migration);
    }

    public function test_childless_slice_migration_stays_parent_only(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Note', [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
        ]);

        $migration = $this->migrationOf($path);
        $this->assertSame(1, substr_count($migration, 'Schema::create('));
        $this->assertSame(1, substr_count($migration, 'Schema::dropIfExists('));
        $this->assertStringNotContainsString('if (! Schema::hasTable(', $migration);
    }

    public function test_missing_child_tables_are_detected_and_named_in_the_warning(): void
    {
        $sliceDir = $this->generateProbe();
        $manifest = new SliceManifest($sliceDir.'/slice.yaml');
        $regenerator = new ArtefactRegenerator;

        $declared = $regenerator->declaredChildTables($manifest);
        $this->assertSame(
            [
                ['field' => 'lines', 'kind' => 'repeater', 'table' => 'probe_lines'],
                ['field' => 'picks', 'kind' => 'multiselect', 'table' => 'probe_picks'],
            ],
            $declared
        );

        // The probe tables were never migrated in this
        // test database, so both are missing under the
        // default (booted-app Schema) checker.
        $missing = $regenerator->missingChildTables($manifest);
        $this->assertSame($declared, $missing);

        $warning = ArtefactRegenerator::missingChildTableWarning($manifest->name, $missing[0]);
        $this->assertStringContainsString('Missing child table:', $warning);
        $this->assertStringContainsString("'{$manifest->name}'", $warning);
        $this->assertStringContainsString("repeater field 'lines'", $warning);
        $this->assertStringContainsString("'probe_lines'", $warning);
        $this->assertStringContainsString('Regen does not create tables', $warning);

        $warning = ArtefactRegenerator::missingChildTableWarning($manifest->name, $missing[1]);
        $this->assertStringContainsString("multiselect field 'picks'", $warning);
        $this->assertStringContainsString("'probe_picks'", $warning);

        // An injected checker that reports the tables as
        // present clears the warning list (no false alarm).
        $this->assertSame([], $regenerator->missingChildTables($manifest, static fn (string $table): bool => true));

        // And once the tables really exist, the default
        // checker clears them too.
        foreach (['probe_lines', 'probe_picks'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
            });
        }
        $this->assertSame([], $regenerator->missingChildTables($manifest));
    }
}
