<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Http\Request;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Tests\TestCase;

/**
 * Port analysis support: the fast scope pre-flight (a bad
 * source fails in a second, not after a minute of silence)
 * and server-side retention of the latest plan (leaving Port
 * and returning restores the review instead of losing it).
 */
final class PortAnalyzeSupportTest extends TestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceDir = sys_get_temp_dir().'/ls-scope-'.uniqid();
        mkdir($this->sourceDir.'/database/migrations', 0775, true);
        $migration = <<<'PHP'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        return new class extends Migration {
            public function up(): void {
                Schema::create('widgets', function (Blueprint $table) { $table->id(); });
                Schema::create('gadgets', function (Blueprint $table) { $table->id(); });
            }
        };
        PHP;
        file_put_contents($this->sourceDir.'/database/migrations/2024_01_01_000001_create_widgets.php', $migration);
        file_put_contents($this->sourceDir.'/database/migrations/2024_01_01_000002_more.php', str_replace(['widgets', 'gadgets'], ['sprockets', 'widgets'], $migration));
        $this->removeRetained();
    }

    protected function tearDown(): void
    {
        $this->removeRetained();
        foreach (glob($this->sourceDir.'/database/migrations/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->sourceDir.'/database/migrations');
        @rmdir($this->sourceDir.'/database');
        @rmdir($this->sourceDir);
        parent::tearDown();
    }

    private function retainedPath(): string
    {
        return storage_path('app/laraslice/port-plans/latest.json');
    }

    private function removeRetained(): void
    {
        if (is_file($this->retainedPath())) {
            @unlink($this->retainedPath());
        }
    }

    public function test_scope_counts_unique_tables_from_migrations(): void
    {
        $response = (new BlueprintStudioController)->portAnalyzeScope(Request::create('/x', 'POST', ['source' => $this->sourceDir]));
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertSame(3, $data['tables'], 'widgets + gadgets + sprockets, the duplicate create counted once');
        $this->assertSame('migrations', $data['kind']);
    }

    public function test_scope_fails_fast_on_a_missing_source(): void
    {
        $response = (new BlueprintStudioController)->portAnalyzeScope(Request::create('/x', 'POST', ['source' => '/nonexistent-'.uniqid()]));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_scope_reports_unknown_for_an_empty_source(): void
    {
        $empty = sys_get_temp_dir().'/ls-empty-'.uniqid();
        mkdir($empty, 0775, true);
        try {
            $response = (new BlueprintStudioController)->portAnalyzeScope(Request::create('/x', 'POST', ['source' => $empty]));
            $data = json_decode($response->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertNull($data['tables']);
            $this->assertSame('unknown', $data['kind'], 'unknown scope never blocks — the full analysis stays the authority');
        } finally {
            @rmdir($empty);
        }
    }

    public function test_retained_plan_round_trips_through_latest(): void
    {
        $controller = new BlueprintStudioController;

        $empty = json_decode($controller->portPlanLatest()->getContent(), true);
        $this->assertFalse($empty['found']);

        $plan = ['domain' => 'ERP', 'modules' => [['name' => 'Sales']], 'studio_slices' => []];
        $retained = $controller->portPlanRetain(Request::create('/x', 'POST', [
            'source' => '/srv/legacy', 'domain' => 'ERP', 'total_tables' => 358, 'plan' => $plan,
        ]));
        $this->assertTrue(json_decode($retained->getContent(), true)['retained']);

        $latest = json_decode($controller->portPlanLatest()->getContent(), true);
        $this->assertTrue($latest['found']);
        $this->assertSame('/srv/legacy', $latest['source']);
        $this->assertSame(358, $latest['total_tables']);
        $this->assertSame($plan, $latest['plan'], 'the reviewed plan comes back whole — Execute consumes it as before');
    }

    public function test_retain_refuses_an_empty_plan(): void
    {
        $response = (new BlueprintStudioController)->portPlanRetain(Request::create('/x', 'POST', ['plan' => []]));

        $this->assertSame(422, $response->getStatusCode());
    }
}
