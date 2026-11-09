<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Blueprint;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintValidator;
use LaraSlice\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * F9 (Day-2 drill, 8 Nov 2026): slice:blueprint:apply
 * --migrate printed "Migrations finished" while the
 * slice's table did not exist — the invocation ran only
 * the app's default migrate path, and slice-folder
 * migrations are not on it; the error branch also
 * swallowed failures and still returned SUCCESS. And the
 * PLAN diverged from reality: it promised invented
 * title/description/status columns while omitting the
 * audit columns the migration actually creates.
 *
 * Fixed behaviour, pinned here:
 *  - apply runs the applied slice's own Migrations
 *    directory explicitly;
 *  - a failed migration (exception or non-zero exit) is
 *    a command FAILURE, and seeding never follows it;
 *  - "Migrations finished" prints only after every
 *    planned create_table is verified to exist;
 *  - the plan's root-table columns are exactly the
 *    declared fields + the generator's audit columns
 *    (created_by/updated_by) + timestamps.
 */
final class BlueprintApplyMigrationTest extends TestCase
{
    private string $tmp;

    private string $slicesPath;

    private string $blueprintPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/ls-apply-'.uniqid();
        $this->slicesPath = $this->tmp.'/Slices';
        mkdir($this->slicesPath, 0777, true);
        config()->set('laraslice.slices_path', $this->slicesPath);

        $this->blueprintPath = $this->tmp.'/apply-widget.slice.yaml';
        file_put_contents($this->blueprintPath, <<<'YAML'
schema_version: 1
name: 'Apply Widget'
handle: apply_widget
description: 'F9 apply-migration test slice.'
models:
  -
    handle: apply_widget
    table: apply_widgets
    root: true
    fields:
      -
        handle: label
        label: Label
        type: string
        required: true
      -
        handle: quantity
        label: Quantity
        type: integer
        nullable: true
YAML);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $blueprint = (new BlueprintValidator)->validate((new BlueprintLoader)->load($this->blueprintPath));

        return (new BlueprintPlanner)->plan($blueprint, $this->slicesPath);
    }

    public function test_apply_with_migrate_creates_the_slice_table_and_the_plan_tells_the_truth(): void
    {
        $plan = $this->plan();
        $this->assertFalse(Schema::hasTable('apply_widgets'));

        $buffer = new BufferedOutput;
        $exit = Artisan::call('slice:blueprint:apply', [
            'path' => $this->blueprintPath,
            '--plan-hash' => $plan['plan_hash'],
            '--yes' => true,
            '--migrate' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exit, 'apply --migrate failed: '.$output);
        $this->assertStringContainsString('Migrations finished.', $output);

        // The table exists in the SAME run that printed
        // success — the drill's apply printed success with
        // no table behind it.
        $this->assertTrue(Schema::hasTable('apply_widgets'), 'slice table missing after apply --migrate (F9)');

        // Plan truth: every planned column exists, the
        // audit columns the migration creates are planned,
        // and no invented columns are promised.
        $rootOp = collect($plan['database_operations'])
            ->firstWhere(fn (array $op): bool => $op['kind'] === 'create_table' && $op['table'] === 'apply_widgets');
        $this->assertNotNull($rootOp);
        $planned = array_column($rootOp['columns'], 'column');
        $actual = Schema::getColumnListing('apply_widgets');
        foreach ($planned as $column) {
            $this->assertContains($column, $actual, "plan promises column {$column} the migration did not create");
        }
        $this->assertContains('created_by', $planned, 'plan omits the audit columns the generator emits');
        $this->assertContains('updated_by', $planned);
        foreach (['title', 'description', 'status'] as $invented) {
            $this->assertNotContains($invented, $planned, "plan still invents the {$invented} column (F9)");
            $this->assertNotContains($invented, $actual);
        }
    }

    public function test_apply_fails_loudly_when_the_slice_migration_cannot_create_the_table(): void
    {
        $plan = $this->plan();

        // Recreate the drill's failure mode deterministically:
        // the migrations repository already believes the
        // slice's create migration has run (for any timestamp
        // the generator might stamp around now), so migrate
        // reports "nothing to migrate" and exits 0 — while
        // the table does not exist.
        $rows = [];
        for ($t = time() - 30; $t <= time() + 300; $t++) {
            $rows[] = ['migration' => date('Y_m_d_His', $t).'_create_apply_widgets_table', 'batch' => 1];
        }
        DB::table('migrations')->insert($rows);
        $this->assertFalse(Schema::hasTable('apply_widgets'));

        $buffer = new BufferedOutput;
        $exit = Artisan::call('slice:blueprint:apply', [
            'path' => $this->blueprintPath,
            '--plan-hash' => $plan['plan_hash'],
            '--yes' => true,
            '--migrate' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertNotSame(0, $exit, 'apply claimed success although the slice table was never created (F9)');
        $this->assertStringNotContainsString('Migrations finished.', $output);
        $this->assertStringContainsString('apply_widgets', $output, 'failure output must name the missing table');
        $this->assertFalse(Schema::hasTable('apply_widgets'));
    }
}
