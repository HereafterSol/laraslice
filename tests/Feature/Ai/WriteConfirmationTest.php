<?php

namespace LaraSlice\Tests\Feature\Ai;

use Illuminate\Support\Facades\Route;
use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Core\Ai\ApprovalSpec;
use LaraSlice\Core\Ai\McpServer;
use LaraSlice\Core\Ai\WriteConfirmer;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Metadata\MetadataProjector;
use LaraSlice\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase C (Proposal #4): two-phase confirmation for AI writes.
 *
 * Pins, on the Ledger fixture (the Phase B pattern: a slice
 * planted in the app slices path with generated-style named
 * routes registered for real):
 *  - the token state machine (WriteConfirmer): a proposal
 *    issues a signed, args-bound, user-bound, expiring,
 *    single-use token; verification accepts exactly the
 *    proposed call once and rejects — per-issue — altered
 *    arguments, expiry, another user, another tool, a
 *    tampered signature, and replay;
 *  - the approval catalog (ApprovalSpec): built specs
 *    validate clean; a malformed spec yields one readable
 *    issue per defect;
 *  - the MCP gate: a write tool without a token returns the
 *    proposal and executes NOTHING (the fixture Heart is the
 *    witness); with the token it executes; read tools are
 *    not gated; permission refusal precedes any proposal;
 *  - the Explorer seat: propose returns a token + valid spec
 *    without executing; execute dispatches exactly the
 *    confirmed write through the slice's own route; altered
 *    arguments and replay are rejected per-issue; reads and
 *    inspect-only operations cannot be proposed; the
 *    endpoints sit behind the Studio gate.
 *
 * On pre-Phase-C code every test here fails by absence (the
 * confirmer, the spec, the gate and the endpoints did not
 * exist) — except the hash-canonicalisation and spec-builder
 * pins, which fail by absence of the classes they name.
 */
class WriteConfirmationTest extends TestCase
{
    protected string $fixtureBase;

    protected SliceManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

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

        file_put_contents($dir.'/Routes/web.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/ledger-entries', fn () => response()->json([]))->name('ledger.index');
Route::post('/ledger-entries', fn (Request $r) => response()->json(['created' => true, 'memo' => $r->input('memo')], 201))->name('ledger.store');
Route::get('/ledger-entries/{ledger_entry}', fn ($id) => response()->json(['id' => $id]))->name('ledger.show');
Route::put('/ledger-entries/{ledger_entry}', fn (Request $r, $id) => response()->json(['updated' => $id, 'memo' => $r->input('memo')]))->name('ledger.update');
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

        parent::tearDown();
    }

    protected function fixtureManager(string $dir): SliceManager
    {
        $manager = new class($dir) extends SliceManager
        {
            public function __construct(private string $dir)
            {
                parent::__construct(app());
            }

            public function discover(bool $force = false): void
            {
                // Hydrate the registry from real discovery,
                // filtered to the fixture — toggleSlice() and
                // friends read $this->slices, which the Phase B
                // pattern's no-op discover left empty.
                $real = new SliceManager(app());
                $real->discover(true);
                $this->slices = [];
                foreach ($real->discoveredSlices() as $slice) {
                    if (($slice->path ?? null) === $this->dir) {
                        $this->slices[(string) $slice->name] = $slice;
                    }
                }
            }

            public function discoveredSlices(): array
            {
                $real = new SliceManager(app());
                $real->discover(true);

                return array_values(array_filter(
                    $real->discoveredSlices(),
                    fn ($s) => ($s->path ?? null) === $this->dir
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

        // Hydrate eagerly: SliceToggleCommand calls getSlice()
        // before toggleSlice()'s internal discover() runs.
        $manager->discover(true);

        return $manager;
    }

    protected function confirmer(): WriteConfirmer
    {
        return app(WriteConfirmer::class);
    }

    protected function mcp(): McpServer
    {
        return new McpServer(new AiEngine($this->manager), $this->manager, app(MetadataProjector::class));
    }

    protected function heartActiveFlag(): mixed
    {
        $yaml = Yaml::parse((string) file_get_contents($this->fixtureBase.'/Ledger/slice.yaml'));

        return $yaml['active'] ?? null;
    }

    // ---- The token state machine (WriteConfirmer)

    public function test_propose_returns_requires_confirmation_with_a_valid_spec(): void
    {
        $proposal = $this->confirmer()->proposeMcp('seed_slice', ['slice' => 'Ledger', 'count' => 3], 5, false);

        $this->assertFalse($proposal['success']);
        $this->assertSame('requires_confirmation', $proposal['status']);
        $this->assertSame(300, $proposal['confirmation']['ttl_seconds']);
        $this->assertSame(2, substr_count($proposal['confirmation']['token'], '.'));
        $this->assertSame([], ApprovalSpec::validate($proposal['spec']));
        $this->assertStringContainsString('seed_slice', $proposal['summary']);
        $this->assertSame(['slice' => 'Ledger', 'count' => 3], $proposal['proposal']['arguments']);
    }

    public function test_verify_accepts_the_exact_proposal_once_then_rejects_replay(): void
    {
        $args = ['slice' => 'Ledger', 'count' => 3];
        $token = $this->confirmer()->proposeMcp('seed_slice', $args, 5, false)['confirmation']['token'];

        $first = $this->confirmer()->verifyMcp($token, 'seed_slice', $args, 5);
        $this->assertTrue($first['ok']);

        $replay = $this->confirmer()->verifyMcp($token, 'seed_slice', $args, 5);
        $this->assertFalse($replay['ok']);
        $this->assertSame('confirmation_token', $replay['result']['issues'][0]['field']);
        $this->assertStringContainsString('already been used', $replay['result']['issues'][0]['problem']);
    }

    public function test_verify_rejects_altered_arguments(): void
    {
        $token = $this->confirmer()->proposeMcp('seed_slice', ['slice' => 'Ledger', 'count' => 3], 5, false)['confirmation']['token'];

        $verdict = $this->confirmer()->verifyMcp($token, 'seed_slice', ['slice' => 'Ledger', 'count' => 4], 5);
        $this->assertFalse($verdict['ok']);
        $this->assertSame('arguments', $verdict['result']['issues'][0]['field']);
        $this->assertStringContainsString('differ from the approved proposal', $verdict['result']['issues'][0]['problem']);
    }

    public function test_verify_rejects_an_expired_token(): void
    {
        // Test-scale TTL: a zero-second token is born expired.
        config(['laraslice.ai.confirmation.ttl_seconds' => 0]);
        $token = $this->confirmer()->proposeMcp('seed_slice', ['slice' => 'Ledger'], null, false)['confirmation']['token'];

        $verdict = $this->confirmer()->verifyMcp($token, 'seed_slice', ['slice' => 'Ledger'], null);
        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('expired', $verdict['result']['issues'][0]['problem']);
    }

    public function test_verify_rejects_a_token_presented_by_another_user(): void
    {
        $args = ['slice' => 'Ledger'];
        $token = $this->confirmer()->proposeMcp('seed_slice', $args, 5, false)['confirmation']['token'];

        $verdict = $this->confirmer()->verifyMcp($token, 'seed_slice', $args, 6);
        $this->assertFalse($verdict['ok']);
        $this->assertSame('user', $verdict['result']['issues'][0]['field']);
    }

    public function test_verify_rejects_a_tampered_signature(): void
    {
        $args = ['slice' => 'Ledger'];
        $token = $this->confirmer()->proposeMcp('seed_slice', $args, 5, false)['confirmation']['token'];
        // Tamper a character in the MIDDLE of the signature
        // segment: the final base64url character of a 32-byte
        // HMAC carries only 4 significant bits, so flipping it
        // can decode to the identical signature (a no-op tamper
        // for tokens ending in A–D). A middle character carries
        // full entropy — the tamper always lands.
        $parts = explode('.', $token);
        $sig = $parts[2];
        $mid = intdiv(strlen($sig), 2);
        $sig[$mid] = $sig[$mid] === 'A' ? 'B' : 'A';
        $tampered = $parts[0].'.'.$parts[1].'.'.$sig;

        $verdict = $this->confirmer()->verifyMcp($tampered, 'seed_slice', $args, 5);
        $this->assertFalse($verdict['ok']);
        $this->assertSame('confirmation_token', $verdict['result']['issues'][0]['field']);
        $this->assertStringContainsString('signature does not verify', $verdict['result']['issues'][0]['problem']);
    }

    public function test_an_absent_app_key_fails_closed(): void
    {
        $args = ['slice' => 'Ledger'];
        $token = $this->confirmer()->proposeMcp('seed_slice', $args, 5, false)['confirmation']['token'];

        config(['app.key' => '']);

        // Nothing verifies against an empty key (an empty-key
        // HMAC would be forgeable by anyone)…
        $verdict = $this->confirmer()->verifyMcp($token, 'seed_slice', $args, 5);
        $this->assertFalse($verdict['ok']);
        $this->assertSame('confirmation_token', $verdict['result']['issues'][0]['field']);

        // …and nothing is issued under one.
        $this->expectException(\RuntimeException::class);
        $this->confirmer()->proposeMcp('seed_slice', $args, 5, false);
    }

    public function test_verify_rejects_a_token_issued_for_a_different_tool(): void
    {
        $args = ['slice' => 'Ledger'];
        $token = $this->confirmer()->proposeMcp('seed_slice', $args, 5, false)['confirmation']['token'];

        $verdict = $this->confirmer()->verifyMcp($token, 'wipe_slice_data', $args, 5);
        $this->assertFalse($verdict['ok']);
        $this->assertSame('tool', $verdict['result']['issues'][0]['field']);
    }

    public function test_args_hash_is_key_order_insensitive_but_value_sensitive(): void
    {
        $a = WriteConfirmer::argsHash(['slice' => 'Ledger', 'opts' => ['x' => 1, 'y' => 2]]);
        $b = WriteConfirmer::argsHash(['opts' => ['y' => 2, 'x' => 1], 'slice' => 'Ledger']);
        $c = WriteConfirmer::argsHash(['slice' => 'Ledger', 'opts' => ['x' => 1, 'y' => 3]]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);

        // Type changes and list order are changes too.
        $this->assertNotSame(
            WriteConfirmer::argsHash(['count' => 1]),
            WriteConfirmer::argsHash(['count' => '1'])
        );
        $this->assertNotSame(
            WriteConfirmer::argsHash(['ids' => [1, 2]]),
            WriteConfirmer::argsHash(['ids' => [2, 1]])
        );
    }

    // ---- The approval catalog (ApprovalSpec)

    public function test_approval_spec_validation_reports_each_defect_per_issue(): void
    {
        $bad = [
            'spec' => 'bogus/spec',
            'version' => 99,
            'components' => [
                ['type' => 'operation', 'surface' => 'mcp', 'name' => 'wipe_slice_data', 'destructive' => true],
                ['type' => 'teleport', 'target' => 'x'],
                ['type' => 'field', 'name' => 'count', 'label' => 'Count', 'value' => ['nested' => 1]],
                ['type' => 'warning', 'level' => 'scary', 'text' => 'Boo.'],
                ['type' => 'expiry', 'expires_at' => '2030-01-01T00:00:00Z'],
            ],
        ];

        $fields = array_column(ApprovalSpec::validate($bad), 'field');
        foreach (['spec', 'version', 'components.1.type', 'components.2.value', 'components.3.level', 'components.4.ttl_seconds'] as $expected) {
            $this->assertContains($expected, $fields);
        }
    }

    public function test_built_specs_validate_clean(): void
    {
        $mcp = ApprovalSpec::forMcpTool('wipe_slice_data', ['slice' => 'Ledger'], true, time() + 300, 300);
        $this->assertSame([], ApprovalSpec::validate($mcp));

        $explorer = ApprovalSpec::forExplorerOperation(
            'Ledger Entries',
            ['name' => 'ledger.destroy', 'method' => 'DELETE', 'uri' => '/ledger-entries/7'],
            'delete',
            [],
            [],
            time() + 300,
            300
        );
        $this->assertSame([], ApprovalSpec::validate($explorer));
    }

    // ---- The MCP gate

    public function test_mcp_write_tool_proposes_instead_of_executing(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $result = $this->mcp()->callTool('toggle_slice', ['slice' => 'Ledger', 'action' => 'disable']);

        $this->assertSame('requires_confirmation', $result['status']);
        $this->assertArrayHasKey('confirmation', $result);
        // Nothing executed: the fixture Heart carries no
        // disabled flag (toggle would have written active=false).
        $this->assertNotSame(false, $this->heartActiveFlag());
    }

    public function test_mcp_write_tool_executes_with_the_token_and_rejects_replay(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $args = ['slice' => 'Ledger', 'action' => 'disable'];

        $token = $this->mcp()->callTool('toggle_slice', $args)['confirmation']['token'];
        $executed = $this->mcp()->callTool('toggle_slice', $args + ['confirmation_token' => $token]);
        $this->assertTrue($executed['success']);
        $this->assertSame(false, $this->heartActiveFlag());

        $replay = $this->mcp()->callTool('toggle_slice', $args + ['confirmation_token' => $token]);
        $this->assertFalse($replay['success']);
        $this->assertStringContainsString('already been used', $replay['issues'][0]['problem']);

        // Restore the fixture through a fresh proposal.
        $enable = ['slice' => 'Ledger', 'action' => 'enable'];
        $token2 = $this->mcp()->callTool('toggle_slice', $enable)['confirmation']['token'];
        $this->assertTrue($this->mcp()->callTool('toggle_slice', $enable + ['confirmation_token' => $token2])['success']);
        $this->assertSame(true, $this->heartActiveFlag());
    }

    public function test_mcp_read_tools_are_not_gated(): void
    {
        $result = $this->mcp()->callTool('list_slices', []);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function test_mcp_permission_refusal_precedes_any_proposal(): void
    {
        $this->actingAs($this->makeUser());

        $result = $this->mcp()->callTool('wipe_slice_data', ['slice' => 'Users']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('permission', $result['error']);
    }

    // ---- The Explorer seat

    protected function proposePayload(array $overrides = []): array
    {
        return $overrides + [
            'slice' => 'Ledger',
            'operation' => 'ledger.store',
            'uri' => '/ledger-entries',
            'args' => ['memo' => 'Phase C proof', 'state' => 'open', 'amount_cents' => '500'],
        ];
    }

    public function test_explorer_propose_returns_a_token_and_valid_spec_without_executing(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())
            ->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload())
            ->assertOk();

        $response->assertJsonPath('status', 'requires_confirmation');
        $token = $response->json('confirmation.token');
        $this->assertSame(2, substr_count($token, '.'));
        $this->assertSame([], ApprovalSpec::validate($response->json('spec')));
        $memo = collect($response->json('spec.components'))->firstWhere('name', 'memo');
        $this->assertSame('Phase C proof', $memo['value']);
    }

    public function test_explorer_execute_dispatches_the_confirmed_write(): void
    {
        $admin = $this->makeSuperAdmin();
        $token = $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload())
            ->json('confirmation.token');

        $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_execute'), [
                'confirmation_token' => $token,
                'slice' => 'Ledger',
                'operation' => 'ledger.store',
                'uri' => '/ledger-entries',
                'args' => ['memo' => 'Phase C proof', 'state' => 'open', 'amount_cents' => '500'],
            ])
            ->assertOk()
            ->assertJsonPath('executed', true)
            ->assertJsonPath('status', 201)
            ->assertJsonPath('response.created', true)
            ->assertJsonPath('response.memo', 'Phase C proof');
    }

    public function test_explorer_execute_dispatches_an_update_with_the_id_in_the_uri(): void
    {
        $admin = $this->makeSuperAdmin();
        $payload = [
            'slice' => 'Ledger',
            'operation' => 'ledger.update',
            'uri' => '/ledger-entries/7',
            'args' => ['memo' => 'Updated memo'],
        ];
        $token = $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_propose'), $payload)
            ->json('confirmation.token');

        $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_execute'), ['confirmation_token' => $token] + $payload)
            ->assertOk()
            ->assertJsonPath('executed', true)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('response.updated', '7')
            ->assertJsonPath('response.memo', 'Updated memo');
    }

    public function test_explorer_execute_rejects_altered_arguments(): void
    {
        $admin = $this->makeSuperAdmin();
        $token = $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload())
            ->json('confirmation.token');

        $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_execute'), [
                'confirmation_token' => $token,
                'slice' => 'Ledger',
                'operation' => 'ledger.store',
                'uri' => '/ledger-entries',
                'args' => ['memo' => 'ALTERED', 'state' => 'open', 'amount_cents' => '500'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('issues.0.field', 'arguments');
    }

    public function test_explorer_execute_rejects_replay(): void
    {
        $admin = $this->makeSuperAdmin();
        $token = $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload())
            ->json('confirmation.token');
        $execute = [
            'confirmation_token' => $token,
            'slice' => 'Ledger',
            'operation' => 'ledger.store',
            'uri' => '/ledger-entries',
            'args' => ['memo' => 'Phase C proof', 'state' => 'open', 'amount_cents' => '500'],
        ];

        $this->actingAs($admin)->postJson(route('laraslice.studio.confirm_execute'), $execute)->assertOk();
        $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_execute'), $execute)
            ->assertStatus(422)
            ->assertJsonPath('issues.0.field', 'confirmation_token');
    }

    public function test_explorer_propose_rejects_reads_inspect_only_and_unknown_slices(): void
    {
        $admin = $this->makeSuperAdmin();

        // A read operation executes directly in Phase B's flow;
        // it is not a proposable write.
        $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload(['operation' => 'ledger.index']))
            ->assertStatus(422)
            ->assertJsonPath('issues.0.field', 'operation');

        // A custom action is inspect-only in the Explorer: it
        // cannot be invoked there at all, so it cannot be
        // proposed either.
        $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload(['operation' => 'ledger.approve', 'uri' => '/ledger-entries/7/approve']))
            ->assertStatus(422)
            ->assertJsonPath('issues.0.field', 'operation');

        $this->actingAs($admin)
            ->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload(['slice' => 'NoSuchSlice']))
            ->assertStatus(404)
            ->assertJsonPath('issues.0.field', 'slice');
    }

    public function test_explorer_confirmation_endpoints_are_gated(): void
    {
        $this->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload())->assertUnauthorized();
        $this->postJson(route('laraslice.studio.confirm_execute'), ['confirmation_token' => 'x.y.z'])->assertUnauthorized();

        $this->actingAs($this->makeUser())
            ->postJson(route('laraslice.studio.confirm_propose'), $this->proposePayload())
            ->assertForbidden();
        $this->actingAs($this->makeUser())
            ->postJson(route('laraslice.studio.confirm_execute'), ['confirmation_token' => 'x.y.z'])
            ->assertForbidden();
    }

    public function test_explorer_page_offers_the_two_step_for_write_forms(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())
            ->get(route('laraslice.studio.explorer', ['slice' => 'Ledger']));

        $response->assertOk()
            ->assertSee('data-propose-url', false)
            ->assertSee('data-execute-url', false)
            ->assertSee('Confirm this write', false)
            ->assertSee('review & confirm')
            // The execute call must send the token under the
            // contract key the controller reads — the Phase C
            // goldfish found the page on the pre-fix key
            // (`token`), which no string-presence check on the
            // endpoints could catch.
            ->assertSee('confirmation_token: pending.token', false)
            ->assertDontSee('JSON.stringify({ token: pending.token', false);
    }
}
