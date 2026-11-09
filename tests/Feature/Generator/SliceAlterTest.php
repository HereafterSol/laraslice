<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Generator;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * F11 (Day-2 drill, 8 Nov 2026): an EXISTING slice had no
 * Heart→DB path — adding a field to its Heart changed
 * files (after the F12 fix, all of them) but never the
 * table; slice:sync runs migration files, not a Heart
 * diff, and blueprint apply is create-only, so the alter
 * migration had to be hand-written.
 *
 * slice:alter is that path: it diffs the Heart against
 * the live table, generates + runs the alter migration
 * for additive changes on --write, and REFUSES — named,
 * never guessed — type/nullability changes and unsafe
 * NOT NULL additions, while undeclared columns are named
 * and left untouched.
 *
 * On the pre-fix code the command does not exist, so
 * every test here fails with CommandNotFoundException —
 * that absence IS the finding.
 */
final class SliceAlterTest extends TestCase
{
    private string $slicesPath;

    private string $sliceDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = (string) config('laraslice.slices_path');
        $this->sliceDir = $this->generateWidget();
        $exit = Artisan::call('migrate', [
            '--path' => $this->sliceDir.'/Migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->assertSame(0, $exit);
        $this->assertTrue(Schema::hasTable('alter_widgets'));
    }

    private function generateWidget(): string
    {
        return (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('AlterWidget', [
            ['name' => 'label', 'type' => 'string', 'nullable' => false],
            ['name' => 'starts_on', 'type' => 'date', 'nullable' => true],
        ], false, ['app' => 'Sandbox', 'domain' => 'Testing']);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    private function heart(callable $mutate): void
    {
        $yamlPath = $this->sliceDir.'/slice.yaml';
        $data = Yaml::parseFile($yamlPath);
        file_put_contents($yamlPath, Yaml::dump($mutate($data), 6, 2));
    }

    /** @return array{0: int, 1: string} */
    private function alter(array $args = []): array
    {
        $buffer = new BufferedOutput;
        $exit = Artisan::call('slice:alter', ['slice' => 'AlterWidget'] + $args, $buffer);

        return [$exit, $buffer->fetch()];
    }

    private function migrationFiles(): array
    {
        return glob($this->sliceDir.'/Migrations/*.php') ?: [];
    }

    public function test_additive_field_flows_from_heart_to_live_column(): void
    {
        $this->assertFalse(Schema::hasColumn('alter_widgets', 'score_percent'));
        $filesBefore = count($this->migrationFiles());

        // The Heart gains a field — the drill's step.
        $this->heart(static function (array $data): array {
            $data['fields']['score_percent'] = [
                'name' => 'score_percent', 'label' => 'Score Percent', 'type' => 'integer',
                'nullable' => true, 'default' => null, 'options' => [],
            ];

            return $data;
        });

        // Dry run: named as additive, nothing written,
        // nothing migrated.
        [$exit, $output] = $this->alter();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('ADDITIVE', $output);
        $this->assertStringContainsString('score_percent', $output);
        $this->assertCount($filesBefore, $this->migrationFiles(), 'a dry run must not generate a migration');
        $this->assertFalse(Schema::hasColumn('alter_widgets', 'score_percent'));

        // --write: one alter migration, generated and run,
        // the column verified present — no hand-written
        // migration anywhere in the loop.
        [$exit, $output] = $this->alter(['--write' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertTrue(Schema::hasColumn('alter_widgets', 'score_percent'));
        $alterFiles = array_values(array_filter(
            $this->migrationFiles(),
            static fn (string $f): bool => str_contains(basename($f), '_add_columns_to_alter_widgets_table.php')
        ));
        $this->assertCount(1, $alterFiles, 'exactly one generated alter migration expected');
        $this->assertStringContainsString('$table->integer(\'score_percent\')->nullable();', (string) file_get_contents($alterFiles[0]));

        // Idempotent: a second --write finds nothing to do.
        [$exit, $output] = $this->alter(['--write' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('in sync', $output);
        $this->assertCount($filesBefore + 1, $this->migrationFiles());
    }

    public function test_type_change_is_refused_named_and_never_applied(): void
    {
        $this->assertSame('date', Schema::getColumnType('alter_widgets', 'starts_on'));
        $filesBefore = count($this->migrationFiles());

        $this->heart(static function (array $data): array {
            $data['fields']['starts_on']['type'] = 'integer';

            return $data;
        });

        [$exit, $output] = $this->alter();
        $this->assertNotSame(0, $exit, 'a type change must not exit 0');
        $this->assertStringContainsString('REFUSED', $output);
        $this->assertStringContainsString('starts_on', $output);

        [$exit, $output] = $this->alter(['--write' => true]);
        $this->assertNotSame(0, $exit);
        $this->assertSame('date', Schema::getColumnType('alter_widgets', 'starts_on'), 'the refused change was applied anyway');
        $this->assertCount($filesBefore, $this->migrationFiles(), 'a refused diff must not generate a migration');
    }

    public function test_undeclared_column_is_named_and_left_untouched(): void
    {
        $this->heart(static function (array $data): array {
            unset($data['fields']['starts_on']);

            return $data;
        });

        [$exit, $output] = $this->alter(['--write' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('UNDECLARED', $output);
        $this->assertStringContainsString('starts_on', $output);
        $this->assertTrue(Schema::hasColumn('alter_widgets', 'starts_on'), 'slice:alter dropped an undeclared column');
    }

    public function test_not_null_without_default_is_refused_on_a_populated_table(): void
    {
        DB::table('alter_widgets')->insert(['label' => 'existing row']);
        $filesBefore = count($this->migrationFiles());

        $this->heart(static function (array $data): array {
            $data['fields']['code'] = [
                'name' => 'code', 'label' => 'Code', 'type' => 'string',
                'nullable' => false, 'default' => null, 'options' => [],
            ];

            return $data;
        });

        [$exit, $output] = $this->alter(['--write' => true]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('REFUSED', $output);
        $this->assertStringContainsString('code', $output);
        $this->assertFalse(Schema::hasColumn('alter_widgets', 'code'));
        $this->assertCount($filesBefore, $this->migrationFiles());
    }
}
