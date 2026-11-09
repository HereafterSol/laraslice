<?php

namespace LaraSlice\Core\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Metadata\LadderTier;
use LaraSlice\Core\Metadata\MetadataIssues;
use LaraSlice\Core\Metadata\MetadataProjector;
use LaraSlice\Core\Security\Access;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\LaraSliceServiceProvider;

class McpServer
{
    protected AiEngine $aiEngine;

    protected SliceManager $sliceManager;

    protected MetadataProjector $projector;

    public function __construct(AiEngine $aiEngine, SliceManager $sliceManager, ?MetadataProjector $projector = null)
    {
        $this->aiEngine = $aiEngine;
        $this->sliceManager = $sliceManager;
        // The discovery ladder (Proposals #1–#3): MCP reads the
        // same runtime projection the Studio endpoints serve —
        // one path, one payload, no parallel registry walk.
        $this->projector = $projector ?? new MetadataProjector($sliceManager);
    }

    /**
     * Handle HTTP MCP requests (JSON-RPC 2.0 or REST).
     */
    public function handle(Request $request): JsonResponse
    {
        // JSON-RPC bodies only: never build a call from query strings or form fields,
        // which a plain link or cross-site form could supply.
        $payload = $request->isJson() ? $request->json()->all() : [];
        if (empty($payload) || ! is_string($payload['method'] ?? null)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Expected a JSON-RPC 2.0 request body.'],
            ], 400);
        }

        // 2026-era clients carry the protocol version in the
        // MCP-Protocol-Version header instead of an initialize
        // handshake; fold it into _meta so negotiation sees it.
        $headerVersion = $request->header('MCP-Protocol-Version');
        if (is_string($headerVersion) && $headerVersion !== '') {
            $payload['params'] = is_array($payload['params'] ?? null) ? $payload['params'] : [];
            $meta = is_array($payload['params']['_meta'] ?? null) ? $payload['params']['_meta'] : [];
            $payload['params']['_meta'] = array_merge($meta, ['protocolVersion' => $headerVersion]);
        }

        $response = $this->handleRpc($payload);

        // Notifications get no JSON-RPC response
        return $response === null ? response()->json(null, 202) : response()->json($response);
    }

    /**
     * Protocol versions this server implements, newest first.
     * Dual-era (R&D P1): the 2026-07-28 stateless shape is
     * accepted alongside the 2025-06-18 handshake era — every
     * request is self-contained either way, so one handler
     * serves both.
     */
    public const PROTOCOL_VERSIONS = ['2026-07-28', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * Process a JSON-RPC 2.0 message. Returns null for notifications, which get no response.
     */
    public function handleRpc(array $payload): ?array
    {
        $isNotification = ! array_key_exists('id', $payload);
        $id = $payload['id'] ?? null;
        $method = is_string($payload['method'] ?? null) ? $payload['method'] : '';
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        if ($isNotification) {
            // notifications/initialized, notifications/cancelled, ...: acknowledge silently
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initializeResult($params),
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->toolDefinitions()],
                'tools/call' => $this->toolCallResult($params),
                'resources/list' => ['resources' => $this->resourceDefinitions()],
                'resources/read' => $this->resourceReadResult($params),
                'resources/templates/list' => ['resourceTemplates' => []],
                'prompts/list' => ['prompts' => []],
                default => null,
            };
        } catch (\Throwable $e) {
            report($e);

            return $this->error($id, -32603, 'Internal error while handling '.$method);
        }

        if (is_array($result) && isset($result['__error'])) {
            return $this->error($id, $result['__error']['code'], $result['__error']['message']);
        }

        if ($result === null) {
            return $this->error($id, -32601, "Method not found: {$method}");
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    protected function initializeResult(array $params): array
    {
        $requested = $params['protocolVersion']
            ?? (is_array($params['_meta'] ?? null) ? ($params['_meta']['protocolVersion'] ?? null) : null);

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'serverInfo' => [
                'name' => 'laraslice-mcp-server',
                'version' => LaraSliceServiceProvider::VERSION,
            ],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
            ],
            // The server teaches the confirmation protocol up
            // front (the reference design's move): a client that
            // follows it never has to discover the flow from a
            // refusal.
            'instructions' => 'Write tools (toggle_slice, seed_slice, scaffold_slice, wipe_slice_data, '
                .'destroy_slice, prune_audit_logs) are TWO-PHASE. A call without a confirmation_token '
                .'does not execute: it returns status "requires_confirmation" with a human-readable '
                .'proposal (spec) and a signed confirmation_token. Show the proposal to the user; only '
                .'if the user approves, repeat the call with exactly the same arguments plus '
                .'"confirmation_token". Tokens expire (see ttl_seconds in the proposal), are bound to '
                .'the exact arguments and the proposing user, and are single-use. Read tools '
                .'(list_slices, get_slice_schema, query_database_metrics, get_page_context) execute '
                .'immediately.',
        ];
    }

    /**
     * MCP resources (R&D P1): the always-useful indexes an
     * agent needs before touching tools — skills, gates,
     * docs, slices and commands — served read-only.
     *
     * @return list<array{uri: string, name: string, description: string, mimeType: string}>
     */
    protected function resourceDefinitions(): array
    {
        return [
            ['uri' => 'laraslice://skills/index', 'name' => 'Skill index', 'description' => 'The LaraSlice skill family: names and one-line purposes.', 'mimeType' => 'text/markdown'],
            ['uri' => 'laraslice://gates/definitions', 'name' => 'Gate definitions', 'description' => 'The five verification gates (S/D/F/T/V) plus the drift and conformance gates, with their JSON output contract.', 'mimeType' => 'application/json'],
            ['uri' => 'laraslice://docs/index', 'name' => 'Docs index', 'description' => 'Package documentation files and their titles.', 'mimeType' => 'text/markdown'],
            ['uri' => 'laraslice://slices/index', 'name' => 'Slice index', 'description' => 'The slices discovered in this application.', 'mimeType' => 'application/json'],
            ['uri' => 'laraslice://commands/index', 'name' => 'Command index', 'description' => 'Every slice:/laraslice:/workflow: command with its synopsis (the slice:index data).', 'mimeType' => 'application/json'],
        ];
    }

    protected function resourceReadResult(array $params): array
    {
        $uri = is_string($params['uri'] ?? null) ? $params['uri'] : '';
        $known = array_column($this->resourceDefinitions(), 'mimeType', 'uri');
        if (! isset($known[$uri])) {
            return ['__error' => ['code' => -32602, 'message' => "Unknown resource: {$uri}"]];
        }
        $text = match ($uri) {
            'laraslice://skills/index' => $this->skillsIndexText(),
            'laraslice://gates/definitions' => $this->gatesDefinitionsJson(),
            'laraslice://docs/index' => $this->docsIndexText(),
            'laraslice://slices/index' => $this->slicesIndexJson(),
            'laraslice://commands/index' => (string) json_encode(\LaraSlice\Support\CommandIndex::collect(), JSON_PRETTY_PRINT),
            default => '',
        };

        return ['contents' => [['uri' => $uri, 'mimeType' => $known[$uri], 'text' => $text]]];
    }

    protected function skillsIndexText(): string
    {
        $root = dirname(__DIR__, 3).'/skills';
        $lines = ['# LaraSlice skills', ''];
        if (is_dir($root)) {
            foreach (glob($root.'/*/SKILL.md') ?: [] as $file) {
                $head = (string) file_get_contents($file);
                $name = basename(dirname($file));
                $description = '';
                if (preg_match('/^description:\s*(.+)$/m', $head, $m)) {
                    $description = trim($m[1]);
                } elseif (preg_match('/^#\s+(.+)$/m', $head, $m)) {
                    $description = trim($m[1]);
                }
                $lines[] = "- **{$name}** — {$description}";
            }
        }
        if (count($lines) === 2) {
            $lines[] = '(no skills directory ships with this install)';
        }

        return implode("\n", $lines)."\n";
    }

    protected function gatesDefinitionsJson(): string
    {
        return (string) json_encode([
            'gates' => [
                ['id' => 'S', 'name' => 'Structure', 'question' => 'Does the page carry the same sections and fields as the source?', 'example' => 'The Employees hub has the same four cards and the same table columns.'],
                ['id' => 'D', 'name' => 'Data', 'question' => 'Do the numbers and values match the source data?', 'example' => 'Total employees 12, and the PKR totals agree to the paisa.'],
                ['id' => 'F', 'name' => 'Furniture', 'question' => 'Do the filters, menus and row actions work like the source?', 'example' => 'The Paid filter narrows 19 invoices to 14, like the source.'],
                ['id' => 'T', 'name' => 'Tasks', 'question' => 'Can the user finish the real job end to end?', 'example' => 'Create an invoice, send it, and see the payment recorded.'],
                ['id' => 'V', 'name' => 'Visual', 'question' => 'Does it look like the source (for a replica) or like the design system (for a fresh build)?', 'example' => 'Same layout, spacing and colours under the declared kit.'],
            ],
            'command_gates' => [
                ['command' => 'slice:drift', 'json' => 'slice:drift --format=json', 'shape' => '{errors, warnings, findings: [{kind, severity, message, fix}]}'],
                ['command' => 'slice:conformance', 'json' => 'slice:conformance --format=json', 'shape' => '{pages, conforming, percent, per_page, unrecorded: [{entry, fix}], open: [{entry, fix}], approved, stale, lint_findings}'],
            ],
            'rule' => 'A gate is only as high as its evidence: status equals the lowest UNVERIFIED gate. Findings are fixed at the declaration layer (Mind/Body/Heart), never in generated output.',
        ], JSON_PRETTY_PRINT);
    }

    protected function docsIndexText(): string
    {
        $root = dirname(__DIR__, 3).'/docs';
        $lines = ['# LaraSlice docs', ''];
        if (is_dir($root)) {
            foreach (glob($root.'/*.md') ?: [] as $file) {
                $title = basename($file);
                if (preg_match('/^#\s+(.+)$/m', (string) file_get_contents($file), $m)) {
                    $title .= ' — '.trim($m[1]);
                }
                $lines[] = '- '.$title;
            }
        }
        if (count($lines) === 2) {
            $lines[] = '(no docs directory ships with this install)';
        }

        return implode("\n", $lines)."\n";
    }

    protected function slicesIndexJson(): string
    {
        // The projector's index, identical in facts to the
        // list_slices tool and the HTTP endpoint (one ladder).
        return (string) json_encode($this->projector->index(), JSON_PRETTY_PRINT);
    }

    /**
     * Tool definitions in MCP form: the JSON schema goes under "inputSchema".
     */
    protected function toolDefinitions(): array
    {
        return array_map(function (array $tool): array {
            $definition = [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'inputSchema' => $tool['inputSchema'] ?? $tool['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
            ];
            if (! empty($tool['annotations'])) {
                $definition['annotations'] = $tool['annotations'];
            }

            return $definition;
        }, $this->aiEngine->getAvailableTools());
    }

    protected function toolCallResult(array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $callResult = $this->callTool($name, $arguments);
        } catch (\Throwable $e) {
            report($e);
            $callResult = ['success' => false, 'error' => "The {$name} tool failed: ".$e->getMessage()];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => is_string($callResult) ? $callResult : json_encode($callResult, JSON_PRETTY_PRINT),
            ]],
            'isError' => is_array($callResult) && (isset($callResult['error']) || ($callResult['success'] ?? true) === false),
        ];
    }

    protected function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * Tools that change code or data: the permissions they need over HTTP, and
     * whether they are destructive (stated on the confirmation proposal).
     *
     * This registry is also the confirmation gate's coverage
     * list (Proposal #4): every tool named here is two-phase —
     * a call without a valid confirmation token returns a
     * proposal instead of executing. The list is explicit in
     * code, NOT derived from the metadata projection's
     * operations (which are not a census — see Phase A).
     */
    private const WRITE_TOOLS = [
        'toggle_slice' => ['abilities' => ['system.slices.toggle', 'slice.toggle'], 'destructive' => false],
        'seed_slice' => ['abilities' => ['system.slices.seed', 'slice.seed'], 'destructive' => false],
        'scaffold_slice' => ['abilities' => ['studio.create'], 'destructive' => false],
        'wipe_slice_data' => ['abilities' => ['system.slices.wipe', 'slice.wipe'], 'destructive' => true],
        'destroy_slice' => ['abilities' => ['system.slices.delete', 'slice.delete'], 'destructive' => true],
        'prune_audit_logs' => ['abilities' => ['studio.wipe'], 'destructive' => true],
    ];

    /**
     * Refusal message for a write tool, or null when the call may proceed.
     */
    protected function guardWriteTool(string $name, array $args): ?string
    {
        $rule = self::WRITE_TOOLS[$name] ?? null;
        if ($rule === null) {
            return null;
        }

        if (app()->environment('production') && ! config('laraslice.wizard.allow_in_production', false)) {
            return "The {$name} tool is disabled in production.";
        }

        // Over HTTP the caller is a signed-in user; the local stdio server has none
        $user = auth()->user();
        if ($user && ! Access::allows($user, array_merge($rule['abilities'], ['studio.*']))) {
            return "You do not have permission to use the {$name} tool.";
        }

        return null;
    }

    /**
     * The two-phase gate for a write tool (Proposal #4).
     * Returns null when the call may execute (verified token);
     * otherwise the response the caller gets instead: the
     * proposal payload (no token presented) or the per-issue
     * rejection (token presented but not valid for this call).
     */
    protected function confirmationGate(string $name, array $args): ?array
    {
        $confirmer = app(WriteConfirmer::class);
        $userId = auth()->id() !== null ? (int) auth()->id() : null;

        $token = $args['confirmation_token'] ?? null;
        $callArgs = $args;
        unset($callArgs['confirmation_token']);

        if (! is_string($token) || $token === '') {
            return $confirmer->proposeMcp($name, $callArgs, $userId, (bool) (self::WRITE_TOOLS[$name]['destructive'] ?? false));
        }

        $verdict = $confirmer->verifyMcp($token, $name, $callArgs, $userId);

        return ($verdict['ok'] ?? false) ? null : $verdict['result'];
    }

    public function callTool(string $name, array $args): mixed
    {
        if ($refusal = $this->guardWriteTool($name, $args)) {
            return ['success' => false, 'error' => $refusal];
        }

        // Proposal #4: write tools are two-phase. The ability
        // and production guards above run in BOTH phases; the
        // gate below decides whether this call proposes or —
        // with a verified token — executes.
        if (isset(self::WRITE_TOOLS[$name])) {
            $gate = $this->confirmationGate($name, $args);
            if ($gate !== null) {
                return $gate;
            }
        }

        switch ($name) {
            case 'list_slices':
                // Ladder rung 1: the projector's compact index —
                // the same payload the HTTP endpoint serves
                // (identity + declared counts, discovery totals),
                // paged at the configured tier's page size.
                return $this->projector->index([
                    'domain' => isset($args['domain']) && is_string($args['domain']) ? $args['domain'] : null,
                    'page' => isset($args['page']) ? (int) $args['page'] : 1,
                    'per_page' => (int) LadderTier::current()['per_page'],
                ]);

            case 'toggle_slice':
                $slice = $args['slice'] ?? null;
                $domain = $args['domain'] ?? null;
                $action = $args['action'] ?? 'toggle';

                $cmdArgs = [];
                if ($slice) {
                    $cmdArgs['slice'] = $slice;
                }
                if ($domain) {
                    $cmdArgs['--domain'] = $domain;
                }
                if ($action === 'enable') {
                    $cmdArgs['--enable'] = true;
                }
                if ($action === 'disable') {
                    $cmdArgs['--disable'] = true;
                }

                $exitCode = Artisan::call('slice:toggle', $cmdArgs);

                return [
                    'success' => $exitCode === 0,
                    'output' => Artisan::output(),
                ];

            case 'wipe_slice_data':
                $slice = $args['slice'] ?? null;
                $domain = $args['domain'] ?? null;

                $cmdArgs = ['--force' => true];
                if ($slice) {
                    $cmdArgs['slice'] = $slice;
                }
                if ($domain) {
                    $cmdArgs['--domain'] = $domain;
                }

                $exitCode = Artisan::call('slice:wipe', $cmdArgs);

                return [
                    'success' => $exitCode === 0,
                    'output' => Artisan::output(),
                ];

            case 'seed_slice':
                $slice = $args['slice'] ?? null;
                $domain = $args['domain'] ?? null;
                $count = (int) ($args['count'] ?? 10);

                $cmdArgs = ['--count' => $count];
                if ($slice) {
                    $cmdArgs['slice'] = $slice;
                }
                if ($domain) {
                    $cmdArgs['--domain'] = $domain;
                }

                $exitCode = Artisan::call('slice:seed', $cmdArgs);

                return [
                    'success' => $exitCode === 0,
                    'output' => Artisan::output(),
                ];

            case 'destroy_slice':
                $slice = $args['slice'] ?? null;
                $domain = $args['domain'] ?? null;
                $mode = $args['mode'] ?? 'complete';

                $cmdArgs = [
                    '--mode' => $mode,
                    '--force' => true,
                ];
                if ($slice) {
                    $cmdArgs['slice'] = $slice;
                }
                if ($domain) {
                    $cmdArgs['--domain'] = $domain;
                }

                $exitCode = Artisan::call('slice:destroy', $cmdArgs);

                return [
                    'success' => $exitCode === 0,
                    'output' => Artisan::output(),
                ];

            case 'get_slice_schema':
                // Ladder rung 2: the per-slice schema contract
                // (Proposal #2) — the projector's describe core,
                // versioned. NOT a database introspection: the
                // Heart is the truth, the schema is its
                // projection. Rejections are per-issue and
                // readable so the caller can correct and retry.
                $slice = isset($args['slice']) && is_string($args['slice']) ? trim($args['slice']) : '';
                if ($slice === '') {
                    return MetadataIssues::missingArgument('slice', 'A slice id, name or handle listed by the metadata index (see list_slices).');
                }
                $schema = $this->projector->schema($slice, ! empty($args['ui']));
                if ($schema === null) {
                    return MetadataIssues::unknownSlice($slice);
                }

                return $schema;

            case 'scaffold_slice':
                $name = $args['name'] ?? 'Feature';
                $workflow = (bool) ($args['workflow'] ?? false);
                $flutter = (bool) ($args['flutter'] ?? false);

                $generator = new SliceGenerator;
                $dir = $generator->generate($name, [], $workflow);

                return [
                    'success' => true,
                    'slice' => $name,
                    'directory' => $dir,
                    'workflow' => $workflow,
                ];

            case 'query_database_metrics':
                return $this->aiEngine->getSystemContext()['database_metrics'];

            case 'prune_audit_logs':
                $days = (int) ($args['days'] ?? 90);
                $cmdArgs = ['--days' => $days, '--force' => true];
                if (! empty($args['slice'])) {
                    $cmdArgs['--slice'] = $args['slice'];
                }
                $exitCode = Artisan::call('laraslice:audit:prune', $cmdArgs);

                return [
                    'success' => $exitCode === 0,
                    'output' => Artisan::output(),
                ];

            case 'get_page_context':
                $path = $args['path'] ?? '/';

                return $this->aiEngine->getSystemContext($path);

            default:
                return [
                    'error' => "Unknown tool: {$name}",
                ];
        }
    }
}
