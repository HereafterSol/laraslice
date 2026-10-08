<?php

namespace LaraSlice\Core\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Security\Access;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\LaraSliceServiceProvider;

class McpServer
{
    protected AiEngine $aiEngine;

    protected SliceManager $sliceManager;

    public function __construct(AiEngine $aiEngine, SliceManager $sliceManager)
    {
        $this->aiEngine = $aiEngine;
        $this->sliceManager = $sliceManager;
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

        $response = $this->handleRpc($payload);

        // Notifications get no JSON-RPC response
        return $response === null ? response()->json(null, 202) : response()->json($response);
    }

    /** Protocol versions this server implements, newest first. */
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

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
                'resources/list' => ['resources' => []],
                'resources/templates/list' => ['resourceTemplates' => []],
                'prompts/list' => ['prompts' => []],
                default => null,
            };
        } catch (\Throwable $e) {
            report($e);

            return $this->error($id, -32603, 'Internal error while handling '.$method);
        }

        if ($result === null) {
            return $this->error($id, -32601, "Method not found: {$method}");
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    protected function initializeResult(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'serverInfo' => [
                'name' => 'laraslice-mcp-server',
                'version' => LaraSliceServiceProvider::VERSION,
            ],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
        ];
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
     * whether the caller must repeat the target's name in a "confirm" argument.
     */
    private const WRITE_TOOLS = [
        'toggle_slice' => ['abilities' => ['system.slices.toggle', 'slice.toggle'], 'confirm' => false],
        'seed_slice' => ['abilities' => ['system.slices.seed', 'slice.seed'], 'confirm' => false],
        'scaffold_slice' => ['abilities' => ['studio.create'], 'confirm' => false],
        'wipe_slice_data' => ['abilities' => ['system.slices.wipe', 'slice.wipe'], 'confirm' => true],
        'destroy_slice' => ['abilities' => ['system.slices.delete', 'slice.delete'], 'confirm' => true],
        'prune_audit_logs' => ['abilities' => ['studio.wipe'], 'confirm' => true],
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

        if ($rule['confirm']) {
            $expected = $name === 'prune_audit_logs' ? 'prune' : (string) ($args['slice'] ?? $args['domain'] ?? '');
            if ($expected === '' || ! is_string($args['confirm'] ?? null) || strcasecmp($args['confirm'], $expected) !== 0) {
                return "Refusing {$name}: pass confirm=\"{$expected}\" to confirm this destructive action.";
            }
        }

        return null;
    }

    public function callTool(string $name, array $args): mixed
    {
        if ($refusal = $this->guardWriteTool($name, $args)) {
            return ['success' => false, 'error' => $refusal];
        }

        switch ($name) {
            case 'list_slices':
                $domain = $args['domain'] ?? null;
                $slices = $this->sliceManager->getActiveSlices();
                $output = [];
                foreach ($slices as $sName => $manifest) {
                    if ($domain && strcasecmp($manifest->domain ?? '', $domain) !== 0) {
                        continue;
                    }
                    $output[$sName] = [
                        'title' => $manifest->title ?? $sName,
                        'domain' => $manifest->domain ?? 'General',
                        'enabled' => $manifest->enabled ?? true,
                        'version' => $manifest->version ?? '1.0.0',
                        'permissions' => $manifest->permissions ?? [],
                    ];
                }

                return $output;

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
                $slice = $args['slice'] ?? 'Users';
                $tables = [];
                $allTables = array_column(Schema::getTables(), 'name');
                $sliceLower = strtolower($slice);

                foreach ($allTables as $tbl) {
                    if (str_contains(strtolower($tbl), $sliceLower) || ($sliceLower === 'users' && str_starts_with($tbl, 'user_'))) {
                        $tables[$tbl] = Schema::getColumnListing($tbl);
                    }
                }

                return [
                    'slice' => $slice,
                    'tables' => $tables,
                ];

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
