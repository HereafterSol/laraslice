<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Porting;

use Illuminate\Support\Facades\Artisan;
use LaraSlice\Tests\TestCase;

/**
 * slice:port must register the --source-* connection BEFORE
 * it inspects the source.
 *
 * SlicePortService::inspect() reads
 * config('laraslice.porting.source_connection') to decide
 * whether the live database is the schema source of truth.
 * The command used to register the connection only after
 * inspection (behind the proceed prompt), so inspection
 * silently degraded to migration-file parsing on every CLI
 * run — the independent v2.1.0 evaluation's cold port hit
 * exactly this (finding B1).
 *
 * The pin: in a --dry-run invocation the registration line
 * must appear in the output BEFORE the "Discovered N tables"
 * line. Before the fix the registration block sat after the
 * dry-run return, so the line never appeared at all.
 */
final class PortCommandRegistrationOrderTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = sys_get_temp_dir().'/ls-portsrc-'.uniqid();
        mkdir($this->src.'/database/migrations', 0777, true);
        file_put_contents($this->src.'/database/migrations/2020_01_01_000000_create_widgets_table.php', <<<'PHP'
        <?php
        Schema::create('widgets', function ($table) {
            $table->id();
            $table->string('name');
        });
        PHP);
    }

    protected function tearDown(): void
    {
        unlink($this->src.'/database/migrations/2020_01_01_000000_create_widgets_table.php');
        rmdir($this->src.'/database/migrations');
        rmdir($this->src.'/database');
        rmdir($this->src);
        parent::tearDown();
    }

    public function test_source_connection_is_registered_before_inspection(): void
    {
        // A closed port: the live-DB extraction fails soft
        // (returns nothing) and inspection reconciles with the
        // migrations — the run still completes its dry run.
        // What is pinned is WHEN the registration happens.
        $code = Artisan::call('slice:port', [
            'source' => $this->src,
            '--domain' => 'Proof',
            '--source-host' => '127.0.0.1',
            '--source-port' => 59999,
            '--source-database' => 'definitely_not_real',
            '--source-username' => 'nobody',
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $registered = strpos($out, 'Source database connection registered');
        $discovered = strpos($out, 'Discovered');
        $this->assertNotFalse($registered, $out);
        $this->assertNotFalse($discovered, $out);
        $this->assertLessThan($discovered, $registered, $out);
    }
}
