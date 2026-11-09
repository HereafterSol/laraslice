<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\Route;
use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Core\Ai\McpServer;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Metadata\ExplorerForm;
use LaraSlice\Core\Metadata\MetadataProjector;
use LaraSlice\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase B (Proposals #2 + #3): ONE discovery ladder.
 *
 * The projector Phase A built is now the single source for
 * the HTTP endpoints, the MCP tools and the Copilot's
 * provider context — these tests pin that unity, the schema
 * contract, the enum-cap visibility, the per-issue error
 * shape on the new surfaces, the tier configurability, and
 * the Explorer's form classification.
 *
 * Fixture: a 'Ledger' slice planted in the app slices path —
 * 3 declared fields (one required string, one enum with 60
 * declared values to exercise the cap, one integer), one
 * relation, resource-style operations via a routes file in
 * the generated style. Every test here fails on the
 * pre-Phase-B code: the surfaces (schema endpoint, explorer,
 * ladder context, MCP projection) did not exist, and the one
 * behaviour that did exist — the enum cap — dropped the
 * information silently.
 */
class LadderConsumersTest extends TestCase
{
    protected string $fixtureBase;

    protected SliceManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Planted in the app slices path — the same way Phase
        // A's tests plant fixtures — so real discovery sees it.
        $this->fixtureBase = (string) config('laraslice.slices_path');
        $dir = $this->fixtureBase.'/Ledger';
        mkdir($dir.'/Routes', 0777, true);

        $options = [];
        for ($i = 1; $i <= 60; $i++) {
            $options[] = 'opt_'.$i;
        }
        $heart = [
            'name' => 'Ledger',
            'title' => 'Ledger Entries',
            'domain' => 'Finance',
            'status' => 'active',
            'version' => '1.2.0',
            'multitenant' => true,
            'permissions' => ['ledger.view', 'ledger.manage'],
            'models' => [
                [
                    'name' => 'LedgerEntry',
                    'table' => 'ledger_entries',
                    'fields' => [
                        ['name' => 'id', 'type' => 'integer'],
                        ['name' => 'memo', 'type' => 'string', 'required' => true],
                        ['name' => 'kind', 'type' => 'string', 'options' => $options],
                        ['name' => 'state', 'type' => 'string', 'options' => ['open', 'closed']],
                        ['name' => 'project_id', 'type' => 'foreign_id', 'relation' => 'projects'],
                        ['name' => 'amount_cents', 'type' => 'integer'],
                    ],
                    'relations' => [
                        ['name' => 'project', 'type' => 'belongsTo', 'related' => 'Projects', 'foreign_key' => 'project_id'],
                    ],
                ],
            ],
        ];
        file_put_contents($dir.'/slice.yaml', Yaml::dump($heart, 6, 2));

        // The slice's declared routes, in the generated style
        // the projector's operations parser reads (single
        // quotes, named routes, Routes/web.php).
        file_put_contents($dir.'/Routes/web.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/ledger-entries', fn () => response()->json([]))->name('ledger.index');
Route::post('/ledger-entries', fn (Request $r) => response()->json(['created' => true], 201))->name('ledger.store');
Route::get('/ledger-entries/{ledger_entry}', fn ($id) => response()->json(['id' => $id]))->name('ledger.show');
Route::put('/ledger-entries/{ledger_entry}', fn (Request $r, $id) => response()->json(['updated' => $id]))->name('ledger.update');
Route::delete('/ledger-entries/{ledger_entry}', fn ($id) => response()->json(['deleted' => $id]))->name('ledger.destroy');
Route::post('/ledger-entries/{ledger_entry}/approve', fn ($id) => response()->json(['approved' => $id]))->name('ledger.approve');
PHP);

        $this->manager = $this->fixtureManager($dir);
        $this->app->instance(SliceManager::class, $this->manager);
        $this->app->instance(MetadataProjector::class, new MetadataProjector($this->manager));

        Route::middleware('api')->group($dir.'/Routes/web.php');
    }

    protected function tearDown(): void
    {
        $dir = $this->fixtureBase.'/Ledger';
        @unlink($dir.'/Routes/web.php');
        @unlink($dir.'/slice.yaml');
        @rmdir($dir.'/Routes');
        @rmdir($dir);

        // Planted only by the max-describes test; absent otherwise.
        $journal = $this->fixtureBase.'/Journal';
        @unlink($journal.'/Routes/web.php');
        @unlink($journal.'/slice.yaml');
        @rmdir($journal.'/Routes');
        @rmdir($journal);

        parent::tearDown();
    }

    /**
     * A manager whose fleet is only the fixture slice (the
     * Phase A pattern): discovery itself stays real.
     */
    protected function fixtureManager(string|array $dirs): SliceManager
    {
        return new class($dirs) extends SliceManager
        {
            /** @var array<int, string> */
            private array $dirs;

            public function __construct(string|array $dirs)
            {
                $this->dirs = is_array($dirs) ? $dirs : [$dirs];
            }

            public function discover(bool $force = false): void {}

            public function discoveredSlices(): array
            {
                $real = new SliceManager(app());
                $real->discover(true);

                return array_values(array_filter(
                    $real->discoveredSlices(),
                    fn ($s) => in_array($s->path ?? null, $this->dirs, true)
                ));
            }

            public function getSlices(): array
            {
                $out = [];
                foreach ($this->discoveredSlices() as $slice) {
                    $out[(string) $slice->name] = $slice;
                }

                return $out;
            }
        };
    }

    protected function projector(): MetadataProjector
    {
        return app(MetadataProjector::class);
    }

    protected function mcp(): McpServer
    {
        return new McpServer(new AiEngine($this->manager), $this->manager, $this->projector());
    }

    // ---- Enum-cap visibility (brief item; Phase A goldfish 8)

    public function test_enum_above_the_cap_is_visible_not_silent(): void
    {
        $describe = $this->projector()->describe('Ledger');
        $kind = collect($describe['fields'])->firstWhere('name', 'kind');

        $this->assertArrayNotHasKey('enum', $kind);
        $this->assertSame(60, $kind['enum_count']);
        $this->assertTrue($kind['enum_truncated']);
    }

    public function test_small_enum_still_served_inline(): void
    {
        $describe = $this->projector()->describe('Ledger');
        $state = collect($describe['fields'])->firstWhere('name', 'state');

        $this->assertSame(['open', 'closed'], $state['enum']);
        $this->assertArrayNotHasKey('enum_truncated', $state);
        $this->assertArrayNotHasKey('enum_count', $state);
    }

    // ---- The schema contract (Proposal #2)

    public function test_schema_contract_wraps_the_describe_core(): void
    {
        $schema = $this->projector()->schema('Ledger');
        $describe = $this->projector()->describe('Ledger');

        $this->assertSame('laraslice/slice-schema', $schema['schema']);
        $this->assertSame(1, $schema['schema_version']);
        $this->assertSame($describe['id'], $schema['id']);
        $this->assertSame($describe['fields'], $schema['fields']);
        $this->assertSame($describe['relations'], $schema['relations']);
        $this->assertSame($describe['operations'], $schema['operations']);

        // Declaration only: no live row-count overlay.
        $this->assertArrayNotHasKey('rows', $schema);
        foreach ($schema['tables'] as $table) {
            $this->assertArrayNotHasKey('rows', $table);
        }

        // The draft-07 shape: required list + per-field types.
        $this->assertSame('object', $schema['json_schema']['type']);
        $this->assertContains('memo', $schema['json_schema']['required']);
        $this->assertSame('string', $schema['json_schema']['properties']['memo']['type']);
        $this->assertSame('integer', $schema['json_schema']['properties']['amount_cents']['type']);
        $this->assertSame('Projects', $schema['json_schema']['properties']['project_id']['x-relation']);
        $this->assertSame(60, $schema['json_schema']['properties']['kind']['x-enum-count']);
        $this->assertTrue($schema['json_schema']['properties']['kind']['x-enum-truncated']);
        $this->assertArrayNotHasKey('enum', $schema['json_schema']['properties']['kind']);
        $this->assertSame(['open', 'closed'], $schema['json_schema']['properties']['state']['enum']);
    }

    public function test_schema_of_unknown_slice_is_null(): void
    {
        $this->assertNull($this->projector()->schema('Nope'));
    }

    public function test_junk_names_do_not_resolve_through_the_path_substring_pass(): void
    {
        // The manager resolver's loosest pass matches the query
        // as a substring of the slice's file path — every slice
        // path contains "slices" (the discovery root), so the
        // unguarded fallback resolved 'slices' to the first
        // slice in the fleet. On the proof fleet the same pass
        // resolved 'Nope' to the Flags slice ("adjudicatio**n**"
        // + "**ope**rations"). The projector must reject these:
        // a wrong-slice answer is worse than a correctable error.
        $projector = new MetadataProjector(new SliceManager($this->app));

        $this->assertNull($projector->resolve('slices'));
        $this->assertNull($projector->describe('slices'));
    }

    public function test_tolerant_but_legitimate_names_still_resolve(): void
    {
        // The guard keeps the strict tolerant matches: plural
        // and case variants of a real slice name resolve.
        $projector = new MetadataProjector(new SliceManager($this->app));

        $resolved = $projector->resolve('ledgers');
        $this->assertNotNull($resolved);
        $this->assertSame('Ledger', $resolved->name);
    }

    public function test_map_shaped_permissions_project_as_declared(): void
    {
        // The Users Heart shape: permissions as {slug, name}
        // maps. Pre-fix, describe() coerced entries with
        // strval() and threw on this shape.
        $heart = [
            'name' => 'Permed',
            'domain' => 'Ops',
            'permissions' => [['slug' => 'permed.view', 'name' => 'View Permed']],
            'models' => [['name' => 'Permed', 'table' => 'permeds', 'fields' => [['name' => 'id', 'type' => 'integer']]]],
        ];
        $dir = $this->fixtureBase.'/Permed';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/slice.yaml', Yaml::dump($heart, 6, 2));

        $describe = (new MetadataProjector($this->fixtureManager($dir)))->describe('Permed');
        $this->assertSame([['slug' => 'permed.view', 'name' => 'View Permed']], $describe['auth']['permissions']);

        @unlink($dir.'/slice.yaml');
        @rmdir($dir);
    }

    // ---- HTTP endpoints

    public function test_schema_endpoint_serves_the_contract_behind_the_studio_gate(): void
    {
        $this->getJson('/laraslice/studio/metadata/Ledger/schema')->assertUnauthorized();

        $this->actingAs($this->makeUser(['name' => 'NoPerm'.uniqid()]))
            ->getJson('/laraslice/studio/metadata/Ledger/schema')
            ->assertForbidden();

        $this->actingAs($this->makeSuperAdmin())
            ->getJson('/laraslice/studio/metadata/Ledger/schema')
            ->assertOk()
            ->assertJsonPath('schema', 'laraslice/slice-schema')
            ->assertJsonPath('name', 'Ledger')
            ->assertJsonCount(6, 'fields');
    }

    public function test_schema_endpoint_rejection_is_per_issue_and_readable(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())
            ->getJson('/laraslice/studio/metadata/Nope/schema')
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $issue = $response->json('issues.0');
        $this->assertSame('slice', $issue['field']);
        $this->assertStringContainsString("'Nope'", $issue['problem']);
        $this->assertStringContainsString('index', $issue['expected']);
    }

    // ---- Explorer (Proposal #2, executable form)

    public function test_explorer_form_classification_follows_the_schema(): void
    {
        $forms = collect(ExplorerForm::operations($this->projector()->schema('Ledger')))
            ->keyBy(fn ($f) => $f['operation']['name']);

        $this->assertTrue($forms['ledger.index']['invocable']);
        $this->assertSame('read', $forms['ledger.index']['kind']);

        $this->assertTrue($forms['ledger.show']['invocable']);
        $this->assertSame('ledger_entry', $forms['ledger.show']['id_param']);

        $create = $forms['ledger.store'];
        $this->assertTrue($create['invocable']);
        $inputs = collect($create['fields'])->keyBy('name');
        $this->assertSame('text', $inputs['memo']['input']);
        $this->assertTrue($inputs['memo']['required']);
        // The capped enum renders as text with the count named,
        // never as a partial select.
        $this->assertSame('text', $inputs['kind']['input']);
        $this->assertStringContainsString('60 declared values', $inputs['kind']['note']);
        $this->assertSame('number', $inputs['amount_cents']['input']);
        $this->assertStringContainsString('Projects', $inputs['project_id']['note']);
        // A small enum renders as a select with its values.
        $this->assertSame('select', $inputs['state']['input']);
        $this->assertSame(['open', 'closed'], array_column($inputs['state']['options'], 'value'));

        $this->assertTrue($forms['ledger.update']['invocable']);
        $this->assertTrue($forms['ledger.destroy']['invocable']);

        // A custom action's payload is not derivable from the
        // schema — inspect-only, with the reason stated.
        $this->assertFalse($forms['ledger.approve']['invocable']);
        $this->assertStringContainsString('not derivable', $forms['ledger.approve']['note']);
    }

    public function test_explorer_page_renders_the_generated_form(): void
    {
        $this->actingAs($this->makeSuperAdmin())
            ->get('/laraslice/studio/explorer/Ledger')
            ->assertOk()
            ->assertSee('Ledger Entries')
            ->assertSee('data-field="memo"', false)
            ->assertSee('data-field="amount_cents"', false)
            ->assertSee('ledger.approve')
            ->assertSee('inspect-only');
    }

    public function test_explorer_unknown_slice_shows_the_issue(): void
    {
        $this->actingAs($this->makeSuperAdmin())
            ->get('/laraslice/studio/explorer/Nope')
            ->assertNotFound()
            ->assertSee('No slice matches');
    }

    // ---- MCP on the ladder (Proposal #3)

    public function test_mcp_list_slices_reads_the_projection(): void
    {
        $result = $this->mcp()->callTool('list_slices', []);

        // The projector's envelope — not the old name-keyed
        // registry walk (which had no totals and no pagination).
        $this->assertArrayHasKey('total', $result);
        $this->assertSame($this->projector()->index()['total'], $result['total']);
        $row = collect($result['slices'])->firstWhere('name', 'Ledger');
        $this->assertNotNull($row);
        $this->assertSame(6, $row['fields']);
        $this->assertArrayHasKey('filtered', $result);
    }

    public function test_mcp_schema_tool_serves_the_contract(): void
    {
        $result = $this->mcp()->callTool('get_slice_schema', ['slice' => 'Ledger']);

        $this->assertSame('laraslice/slice-schema', $result['schema']);
        $this->assertSame($this->projector()->schema('Ledger')['fields'], $result['fields']);
    }

    public function test_mcp_schema_tool_rejections_are_per_issue(): void
    {
        $unknown = $this->mcp()->callTool('get_slice_schema', ['slice' => 'Nope']);
        $this->assertFalse($unknown['success']);
        $this->assertSame('slice', $unknown['issues'][0]['field']);

        $missing = $this->mcp()->callTool('get_slice_schema', []);
        $this->assertFalse($missing['success']);
        $this->assertSame('slice', $missing['issues'][0]['field']);
        $this->assertStringContainsString('required', $missing['issues'][0]['problem']);
    }

    public function test_mcp_index_resource_matches_the_projector(): void
    {
        $server = $this->mcp();
        $read = new \ReflectionMethod($server, 'resourceReadResult');
        $read->setAccessible(true);
        $contents = $read->invoke($server, ['uri' => 'laraslice://slices/index'])['contents'][0];

        $decoded = json_decode($contents['text'], true);
        $this->assertSame($this->projector()->index()['total'], $decoded['total']);
        $this->assertSame('Ledger', collect($decoded['slices'])->firstWhere('name', 'Ledger')['name']);
    }

    // ---- Copilot ladder context (Proposal #3)

    public function test_copilot_ladder_context_describes_only_touched_slices(): void
    {
        $engine = new AiEngine($this->manager);
        $ladder = $engine->ladderContext('What fields does the Ledger slice have?', [], []);

        $this->assertArrayHasKey('Ledger', $ladder['describes']);
        $this->assertSame(
            $this->projector()->describe('Ledger')['fields'],
            $ladder['describes']['Ledger']['fields']
        );
        $this->assertSame($this->projector()->index()['total'], $ladder['inventory']['total']);
        // The old per-call dump key is gone from this path.
        $this->assertArrayNotHasKey('active_slices', $ladder);
    }

    public function test_copilot_ladder_context_without_a_slice_has_no_describes(): void
    {
        $ladder = (new AiEngine($this->manager))->ladderContext('Hello there, good morning.', [], []);
        $this->assertSame([], $ladder['describes']);
    }

    public function test_ladder_tiers_are_configurable(): void
    {
        config(['laraslice.ai.ladder.tier' => 'on_device']);
        $ladder = (new AiEngine($this->manager))->ladderContext('What fields does the Ledger slice have?', [], []);
        $this->assertSame('on_device', $ladder['tier']);
        $this->assertSame('summary', $ladder['index']['mode']);
        $this->assertLessThanOrEqual(1, count($ladder['describes']));

        config(['laraslice.ai.ladder.tier' => 'frontier']);
        $prompt = $this->buildPromptFor('What fields does the Ledger slice have?');
        // The frontier rendering carries the tabular index.
        $this->assertStringContainsString('"columns"', $prompt);
        $this->assertStringContainsString('Ledger Entries', $prompt);
    }

    public function test_ladder_max_describes_cap_is_enforced(): void
    {
        // A second fixture slice, planted only for this test:
        // the describe cap can only be falsified on a fleet
        // where one conversation touches MORE slices than the
        // cap allows (the single-slice fleet the other tests
        // use can never exceed any cap).
        $journalDir = $this->fixtureBase.'/Journal';
        mkdir($journalDir.'/Routes', 0777, true);
        $heart = [
            'name' => 'Journal',
            'title' => 'Journal Books',
            'domain' => 'Finance',
            'status' => 'active',
            'version' => '1.0.0',
            'models' => [
                [
                    'name' => 'JournalBook',
                    'table' => 'journal_books',
                    'fields' => [
                        ['name' => 'id', 'type' => 'integer'],
                        ['name' => 'title', 'type' => 'string', 'required' => true],
                    ],
                ],
            ],
        ];
        file_put_contents($journalDir.'/slice.yaml', Yaml::dump($heart, 6, 2));
        file_put_contents($journalDir.'/Routes/web.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/journal-books', fn () => response()->json([]))->name('journal.index');
PHP);

        $manager = $this->fixtureManager([$this->fixtureBase.'/Ledger', $journalDir]);
        $this->app->instance(MetadataProjector::class, new MetadataProjector($manager));
        $engine = new AiEngine($manager);

        // The message touches Ledger; a recent user turn
        // touches Journal. Touch order is message first.
        $message = 'What fields does the Ledger slice have?';
        $history = [['role' => 'user', 'content' => 'And what about the Journal slice?']];

        // free allows 2 describes: both touched slices arrive.
        config(['laraslice.ai.ladder.tier' => 'free']);
        $free = $engine->ladderContext($message, [], $history);
        $this->assertSame(['Ledger', 'Journal'], array_keys($free['describes']));

        // on_device caps at 1: the message's slice wins and the
        // history turn's slice is cut. If the cap were ignored
        // this would still hold 2; if it were stuck at 1, the
        // free assertion above would already have failed.
        config(['laraslice.ai.ladder.tier' => 'on_device']);
        $device = $engine->ladderContext($message, [], $history);
        $this->assertSame(['Ledger'], array_keys($device['describes']));
    }

    protected function buildPromptFor(string $message): string
    {
        $engine = new AiEngine($this->manager);
        $method = new \ReflectionMethod($engine, 'buildSystemPrompt');
        $method->setAccessible(true);
        $args = [$engine->getSystemContext(), [], $message, []];
        if ($method->getNumberOfParameters() < 4) {
            $args = [$engine->getSystemContext(), []];
        }

        return $method->invokeArgs($engine, $args);
    }
}
