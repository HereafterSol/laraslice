<?php

namespace LaraSlice\Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Tests\TestCase;

/**
 * F4–F6 (Day-2 drill): the built-in copilot must answer factual
 * questions from the same sources the Studio reads — the slice
 * inventory from discovery (not the active-only subset), a named
 * slice's facts from its registry entry/Heart (never a guessed
 * table and a spurious refusal), and EVERY part of a multi-part
 * count/health question (never one table's telemetry).
 */
class CopilotFactualAnswersTest extends TestCase
{
    private string $slicePath;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tasks', function ($table) {
            $table->id();
            $table->string('heading');
            $table->string('status')->default('open');
            $table->timestamps();
        });
        DB::table('tasks')->insert([
            ['heading' => 'One', 'status' => 'open'],
            ['heading' => 'Two', 'status' => 'open'],
            ['heading' => 'Three', 'status' => 'done'],
        ]);

        Schema::create('invoices', function ($table) {
            $table->id();
            $table->string('number');
            $table->timestamps();
        });
        DB::table('invoices')->insert([
            ['number' => 'INV-1'],
            ['number' => 'INV-2'],
        ]);

        $this->slicePath = config('laraslice.slices_path').DIRECTORY_SEPARATOR.'Tasks';
        @mkdir($this->slicePath, 0755, true);
        ManifestRepository::writeYaml($this->slicePath, [
            'name' => 'Tasks',
            'title' => 'Tasks',
            'version' => '1.0.0',
            'description' => 'Work items and boards.',
            'active' => true,
            'domain' => 'Adjudication',
            'tables' => ['tasks'],
            'models' => [[
                'handle' => 'task',
                'table' => 'tasks',
                'root' => true,
                'fields' => [
                    'heading' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                ],
                'relations' => [
                    ['name' => 'project', 'type' => 'belongsTo', 'model' => 'Project', 'foreign_key' => 'project_id'],
                ],
            ]],
        ]);

        app(SliceManager::class)->discover(true);
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->slicePath);
        parent::tearDown();
    }

    private function ask(object $user, string $message): string
    {
        return (string) $this->actingAs($user)
            ->postJson('/laraslice/ai/chat', ['message' => $message])
            ->assertOk()
            ->json('reply');
    }

    public function test_named_slice_question_gets_the_fact_sheet_not_a_refusal(): void
    {
        $admin = $this->makeSuperAdmin();

        foreach ([
            'Tell me about the Tasks slice: what fields does it have?',
            'What fields does the Tasks slice have?',
        ] as $question) {
            $reply = $this->ask($admin, $question);

            $this->assertStringContainsString('### 🧩 Tasks (`Tasks`)', $reply, $question);
            $this->assertStringContainsString('`heading`', $reply, $question);
            $this->assertStringContainsString('`tasks` (3 records)', $reply, $question);
            $this->assertStringContainsString('belongsTo', $reply, $question);
            $this->assertStringContainsString('_Source: slice registry (live)._', $reply, $question);
            $this->assertStringNotContainsString('Access restricted', $reply, $question);
        }
    }

    public function test_multi_part_count_and_health_question_answers_every_part(): void
    {
        $admin = $this->makeSuperAdmin();

        $reply = $this->ask($admin, 'Is the system healthy? How many users, tasks and invoices are there?');

        $this->assertStringContainsString('System Health', $reply);
        $this->assertStringContainsString('Basis:', $reply, 'the health basis must be named, not implied');
        $this->assertStringContainsString('- **Users** (`users`): **1**', $reply);
        $this->assertStringContainsString('- **Tasks** (`tasks`): **3**', $reply);
        $this->assertStringContainsString('- **Invoices** (`invoices`): **2**', $reply);
        $this->assertStringContainsString('_Source: database (live counts)', $reply);
    }

    public function test_multi_part_question_names_entities_the_caller_may_not_count(): void
    {
        $user = $this->makeUserWithPermissions(['ai.copilot.use']);

        $reply = $this->ask($user, 'Is the system healthy? How many users, tasks and invoices are there?');

        // Named as not counted — never silently dropped, never leaked.
        $this->assertStringContainsString('(`users`): not counted', $reply);
        $this->assertStringContainsString('(`tasks`): not counted', $reply);
        $this->assertStringContainsString('(`invoices`): not counted', $reply);
        $this->assertStringNotContainsString('(`tasks`): **', $reply);
    }

    public function test_inventory_answer_counts_discovery_not_the_active_subset(): void
    {
        // An installed-but-inactive slice: discovery counts it,
        // the active-only subset (the old copilot source) does not.
        $dormantPath = config('laraslice.slices_path').DIRECTORY_SEPARATOR.'FieldNotes';
        @mkdir($dormantPath, 0755, true);
        ManifestRepository::writeYaml($dormantPath, [
            'name' => 'FieldNotes',
            'title' => 'Field Notes',
            'active' => false,
            'domain' => 'Fieldwork',
        ]);
        app(SliceManager::class)->discover(true);

        try {
            $manager = app(SliceManager::class);
            $expected = $manager->inventorySummary();
            $this->assertGreaterThan(
                count($manager->getActiveSlices()),
                $expected['total'],
                'fixture sanity: discovery must out-count the active subset'
            );

            $reply = $this->ask($this->makeSuperAdmin(), 'How many slices are installed, and which domains do they belong to?');

            $this->assertStringContainsString("**{$expected['total']} slices**", $reply);
            $this->assertStringContainsString('**Fieldwork** — 1 slice', $reply);
            $this->assertStringContainsString('_Source: slice registry (live)._', $reply);
        } finally {
            app('files')->deleteDirectory($dormantPath);
            app(SliceManager::class)->discover(true);
        }
    }

    public function test_cognitive_inventory_prefers_the_shared_inventory_fact(): void
    {
        $engine = app(AiEngine::class);
        $ref = new \ReflectionMethod($engine, 'generateCognitiveResponse');
        $context = [
            'framework' => 'LaraSlice',
            'version' => '2.0.2',
            'current_page' => '/laraslice/studio',
            'active_slices' => [
                'Projects' => ['title' => 'Projects', 'domain' => 'Delivery', 'enabled' => true, 'version' => '1.2.0', 'description' => '', 'dependencies' => []],
                'Tasks' => ['title' => 'Tasks', 'domain' => 'Delivery', 'enabled' => true, 'version' => '1.0.0', 'description' => '', 'dependencies' => []],
            ],
            'inventory' => [
                'total' => 5,
                'domains' => ['ERP Adjudication' => 3, 'Operations' => 1, 'Settings' => 1],
            ],
            'database_metrics' => ['users_total' => 24, 'roles_total' => 5, 'tables_count' => 258, 'audit_logs_total' => 91],
            'studio_capabilities' => [],
        ];

        $reply = $ref->invokeArgs($engine, ['How many slices are installed, and which domains do they belong to?', $context, ['path' => '/laraslice/studio'], []]);

        $this->assertStringContainsString('**5 slices** across **3 domains**', $reply);
        $this->assertStringContainsString('**ERP Adjudication** — 3 slices', $reply);
        $this->assertStringContainsString('**Settings** — 1 slice', $reply);
    }
}
