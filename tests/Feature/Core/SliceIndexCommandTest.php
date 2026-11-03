<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use LaraSlice\Support\AgentGuide;
use LaraSlice\Support\CommandIndex;
use LaraSlice\Tests\TestCase;

/**
 * R&D P0: the CLI discovery index (slice:index) and the
 * install-time AGENTS.md generation. Agents discover the
 * CLI surface from the index instead of guessing, and
 * every install ships the always-loaded guide.
 */
class SliceIndexCommandTest extends TestCase
{
    private bool $agentsExisted = false;

    private bool $claudeExisted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentsExisted = File::exists(base_path('AGENTS.md'));
        $this->claudeExisted = File::exists(base_path('CLAUDE.md'));
    }

    protected function tearDown(): void
    {
        if (! $this->agentsExisted) {
            @unlink(base_path('AGENTS.md'));
        }
        if (! $this->claudeExisted) {
            @unlink(base_path('CLAUDE.md'));
        }
        parent::tearDown();
    }

    public function test_index_json_lists_the_laraslice_surface(): void
    {
        Artisan::call('slice:index', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame('slice:index', $payload['generated_by']);
        // The full surface is 40+ in a real install; the test
        // app registers a subset (no workflow/audit extras).
        $this->assertGreaterThanOrEqual(35, $payload['count']);

        $names = array_column($payload['commands'], 'name');
        $this->assertContains('slice:index', $names);
        $this->assertContains('slice:list', $names);
        $this->assertContains('slice:install', $names);
        $this->assertContains('laraslice:mcp', $names);
        $this->assertContains('workflow:transition', $names);
        // Only the LaraSlice surface is indexed.
        $this->assertSame([], array_filter($names, fn ($n) => ! str_starts_with($n, 'slice:') && ! str_starts_with($n, 'laraslice:') && ! str_starts_with($n, 'workflow:')));

        $list = $payload['commands'][array_search('slice:list', $names, true)];
        $this->assertNotSame('', $list['description']);
        $this->assertArrayHasKey('options', $list);
    }

    public function test_index_table_and_markdown_render(): void
    {
        $this->artisan('slice:index')->assertSuccessful()
            ->expectsOutputToContain('slice:list');

        $table = CommandIndex::toMarkdownTable(CommandIndex::collect());
        $this->assertStringContainsString('| `slice:list` |', $table);
        $this->assertStringContainsString('| `slice:index` |', $table);
    }

    public function test_agents_guide_written_then_kept_then_forced(): void
    {
        $status = AgentGuide::write(false);
        $this->assertSame('written', $status['AGENTS.md']);
        $this->assertSame('written', $status['CLAUDE.md']);
        $this->assertSame("@AGENTS.md\n", File::get(base_path('CLAUDE.md')));

        $guide = File::get(base_path('AGENTS.md'));
        $this->assertStringContainsString('Mind', $guide);
        $this->assertStringContainsString('`slice:make', $guide);
        $this->assertStringContainsString('| `slice:index` |', $guide);
        // Index-form: the guide routes, it does not swell.
        $this->assertLessThan(10240, strlen($guide));

        File::append(base_path('AGENTS.md'), "\n<!-- hand-curated marker -->\n");
        $status = AgentGuide::write(false);
        $this->assertSame('kept', $status['AGENTS.md']);
        $this->assertStringContainsString('hand-curated marker', File::get(base_path('AGENTS.md')));

        $status = AgentGuide::write(true);
        $this->assertSame('written', $status['AGENTS.md']);
        $this->assertStringNotContainsString('hand-curated marker', File::get(base_path('AGENTS.md')));
    }

    public function test_agents_option_on_the_command(): void
    {
        $this->artisan('slice:index', ['--agents' => true])->assertSuccessful()
            ->expectsOutputToContain('AGENTS.md');
        $this->assertTrue(File::exists(base_path('AGENTS.md')));
    }
}
