<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Porting;

use LaraSlice\Porting\SlicePortService;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * A Schema::create block must be bounded by its OWN closure.
 *
 * The block end used to be "the next create in the file, or
 * strlen($content) for the last one", so every statement after
 * the last create in a consolidated migration file — including
 * Schema::table alter column definitions for OTHER tables —
 * was swept into the last created table. A cold port of a real
 * application produced a 212-column table that MySQL refused
 * with error 1118 (found by the independent v2.1.0 evaluation,
 * finding B2). The alter pass replays alters separately; the
 * create pass must not pre-consume them.
 */
final class MigrationBlockBoundsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ls-migbounds-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function extract(): array
    {
        $svc = app(SlicePortService::class);
        $ref = new ReflectionMethod($svc, 'extractTablesFromMigrations');
        $ref->setAccessible(true);

        return $ref->invoke($svc, $this->dir);
    }

    /** @return list<string> */
    private function columnNames(array $tables, string $table): array
    {
        return array_column($tables[$table]['columns'] ?? [], 'name');
    }

    public function test_trailing_alter_columns_do_not_pollute_the_last_create(): void
    {
        file_put_contents($this->dir.'/2020_01_01_000000_create_core_tables.php', <<<'PHP'
        <?php
        Schema::create('currencies', function ($table) {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        Schema::create('invoices', function ($table) {
            $table->id();
            $table->decimal('total', 16, 2);
        });
        Schema::table('estimates', function ($table) {
            $table->string('gateway_ref')->nullable();
            $table->decimal('tax_total', 16, 2)->nullable();
        });
        PHP);

        $tables = $this->extract();

        $this->assertSame(['code', 'name'], $this->columnNames($tables, 'currencies'));
        // The pre-fix parser extended the invoices block to
        // strlen($content) and absorbed gateway_ref + tax_total.
        $this->assertSame(['total'], $this->columnNames($tables, 'invoices'));
    }

    public function test_alter_block_does_not_absorb_the_following_create(): void
    {
        file_put_contents($this->dir.'/2021_01_01_000000_mixed_statements.php', <<<'PHP'
        <?php
        Schema::create('currencies', function ($table) {
            $table->id();
            $table->string('code');
        });
        Schema::table('currencies', function ($table) {
            $table->string('exchange_rate_note')->nullable();
        });
        Schema::create('estimates', function ($table) {
            $table->id();
            $table->string('estimate_number');
            $table->decimal('sub_total', 16, 2);
        });
        PHP);

        $tables = $this->extract();

        // The alter's own column lands; the following create's
        // columns must NOT be swept into the altered table (the
        // alter pass carried the same unbounded-block defect as
        // the create pass — the second door of the phantom-
        // column failure).
        $this->assertSame(['code', 'exchange_rate_note'], $this->columnNames($tables, 'currencies'));
        $this->assertSame(['estimate_number', 'sub_total'], $this->columnNames($tables, 'estimates'));
    }

    public function test_creates_sharing_one_file_keep_their_own_columns(): void
    {
        file_put_contents($this->dir.'/2020_01_01_000000_create_pair.php', <<<'PHP'
        <?php
        Schema::create('alpha', function ($table) {
            $table->id();
            $table->string('alpha_only');
        });
        // A statement between the two creates.
        Schema::table('elsewhere', function ($table) {
            $table->string('stray_column');
        });
        Schema::create('beta', function ($table) {
            $table->id();
            $table->string('beta_only');
        });
        PHP);

        $tables = $this->extract();

        $this->assertSame(['alpha_only'], $this->columnNames($tables, 'alpha'));
        $this->assertSame(['beta_only'], $this->columnNames($tables, 'beta'));
    }
}
