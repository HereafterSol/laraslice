<?php

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\RowSizeGuard;
use PHPUnit\Framework\TestCase;

/**
 * Cursor EVAL-B (10 Nov 2026): a cold port's migrate died
 * mid-run with MySQL error 1118 (row size) and left a
 * half-migrated schema. The guard estimates each CREATE's
 * worst-case row before anything migrates.
 */
class RowSizeGuardTest extends TestCase
{
    public function test_bloated_create_is_flagged(): void
    {
        $cols = '';
        for ($i = 0; $i < 70; $i++) {
            $cols .= "\$table->string('col_{$i}');\n";
        }
        $content = "<?php Schema::create('wide_table', function (Blueprint \$table) { \$table->id(); {$cols} });";
        $scan = RowSizeGuard::scanContent($content);
        $this->assertArrayHasKey('wide_table', $scan);
        $this->assertGreaterThan(RowSizeGuard::LIMIT, $scan['wide_table']);

        $tmp = tempnam(sys_get_temp_dir(), 'mig').'.php';
        file_put_contents($tmp, $content);
        $violations = RowSizeGuard::violations([$tmp]);
        unlink($tmp);
        $this->assertCount(1, $violations);
        $this->assertSame('wide_table', $violations[0]['table']);
    }

    public function test_normal_create_passes_and_text_counts_at_pointer_size(): void
    {
        $cols = '';
        for ($i = 0; $i < 100; $i++) {
            $cols .= "\$table->text('body_{$i}');\n";
        }
        $content = "<?php Schema::create('docs', function (Blueprint \$table) { \$table->id(); \$table->string('title'); {$cols} \$table->timestamps(); });";
        $scan = RowSizeGuard::scanContent($content);
        $this->assertLessThan(RowSizeGuard::LIMIT, $scan['docs']);
    }

    public function test_create_blocks_are_bounded_per_table(): void
    {
        // The phantom-column class: a later table's columns must
        // never inflate an earlier table's estimate.
        $big = '';
        for ($i = 0; $i < 70; $i++) {
            $big .= "\$table->string('c{$i}');\n";
        }
        $content = "<?php
            Schema::create('small', function (Blueprint \$table) { \$table->id(); \$table->string('name'); });
            Schema::create('huge', function (Blueprint \$table) { \$table->id(); {$big} });
        ";
        $scan = RowSizeGuard::scanContent($content);
        $this->assertLessThan(2000, $scan['small']);
        $this->assertGreaterThan(RowSizeGuard::LIMIT, $scan['huge']);
    }
}
