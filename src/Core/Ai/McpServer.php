<?php

namespace LaraSlice\Core\Ai;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\SliceGenerator;

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
        $payload = $request->json()->all();
        if (empty($payload)) {
            $payload = [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => $request->input('method', 'tools/list'),
                'params'  => $request->input('params', []),
            ];
        }

        $response = $this->handleRpc($payload);
        return response()->json($response);
    }

    /**
     * Process a JSON-RPC 2.0 request payload.
     */
    public function handleRpc(array $payload): array
    {
        $id = $payload['id'] ?? null;
        $method = $payload['method'] ?? '';
        $params = $payload['params'] ?? [];

        switch ($method) {
            case 'initialize':
                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'protocolVersion' => '2024-11-05',
                        'serverInfo'      => [
                            'name'    => 'laraslice-mcp-server',
                            'version' => '1.0.0',
                        ],
                        'capabilities'    => [
                            'tools'     => ['listChanged' => true],
                            'resources' => ['subscribe' => false],
                            'prompts'   => ['listChanged' => false],
                        ],
                    ],
                ];

            case 'notifications/initialized':
                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => ['status' => 'ready'],
                ];

            case 'tools/list':
                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'tools' => $this->aiEngine->getAvailableTools(),
                    ],
                ];

            case 'tools/call':
                $toolName = $params['name'] ?? '';
                $arguments = $params['arguments'] ?? [];
                $callResult = $this->callTool($toolName, $arguments);

                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => is_string($callResult) ? $callResult : json_encode($callResult, JSON_PRETTY_PRINT),
                            ],
                        ],
                        'isError' => false,
                    ],
                ];

            default:
                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'error'   => [
                        'code'    => -32601,
                        'message' => "Method not found: {$method}",
                    ],
                ];
        }
    }

    /**
     * Execute an MCP tool.
     */
    public function callTool(string $name, array $args): mixed
    {
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
                        'title'        => $manifest->title ?? $sName,
                        'domain'       => $manifest->domain ?? 'General',
                        'enabled'      => $manifest->enabled ?? true,
                        'version'      => $manifest->version ?? '1.0.0',
                        'permissions'  => $manifest->permissions ?? [],
                    ];
                }
                return $output;

            case 'toggle_slice':
                $slice = $args['slice'] ?? null;
                $domain = $args['domain'] ?? null;
                $action = $args['action'] ?? 'toggle';

                $cmdArgs = [];
                if ($slice) $cmdArgs['slice'] = $slice;
                if ($domain) $cmdArgs['--domain'] = $domain;
                if ($action === 'enable') $cmdArgs['--enable'] = true;
                if ($action === 'disable') $cmdArgs['--disable'] = true;

                $exitCode = Artisan::call('slice:toggle', $cmdArgs);
                return [
                    'success' => $exitCode === 0,
                    'output'  => Artisan::output(),
                ];

            case 'wipe_slice_data':
                $slice = $args['slice'] ?? null;
                $domain = $args['domain'] ?? null;

                $cmdArgs = ['--force' => true];
                if ($slice) $cmdArgs['slice'] = $slice;
                if ($domain) $cmdArgs['--domain'] = $domain;

                $exitCode = Artisan::call('slice:wipe', $cmdArgs);
                return [
                    'success' => $exitCode === 0,
                    'output'  => Artisan::output(),
                ];

            case 'seed_slice':
                $slice = $args['slice'] ?? null;
                $domain = $args['domain'] ?? null;
                $count = (int) ($args['count'] ?? 10);

                $cmdArgs = ['--count' => $count];
                if ($slice) $cmdArgs['slice'] = $slice;
                if ($domain) $cmdArgs['--domain'] = $domain;

                $exitCode = Artisan::call('slice:seed', $cmdArgs);
                return [
                    'success' => $exitCode === 0,
                    'output'  => Artisan::output(),
                ];

            case 'destroy_slice':
                $slice = $args['slice'] ?? null;
                $domain = $args['domain'] ?? null;
                $mode = $args['mode'] ?? 'complete';

                $cmdArgs = [
                    '--mode'  => $mode,
                    '--force' => true,
                ];
                if ($slice) $cmdArgs['slice'] = $slice;
                if ($domain) $cmdArgs['--domain'] = $domain;

                $exitCode = Artisan::call('slice:destroy', $cmdArgs);
                return [
                    'success' => $exitCode === 0,
                    'output'  => Artisan::output(),
                ];

            case 'get_slice_schema':
                $slice = $args['slice'] ?? 'Users';
                $tables = [];
                $allTables = DB::select('SHOW TABLES');
                $sliceLower = strtolower($slice);

                foreach ($allTables as $t) {
                    $tbl = current((array)$t);
                    if (str_contains(strtolower($tbl), $sliceLower) || ($sliceLower === 'users' && str_starts_with($tbl, 'user_'))) {
                        $tables[$tbl] = Schema::getColumnListing($tbl);
                    }
                }

                return [
                    'slice'  => $slice,
                    'tables' => $tables,
                ];

            case 'scaffold_slice':
                $name = $args['name'] ?? 'Feature';
                $workflow = (bool) ($args['workflow'] ?? false);
                $flutter = (bool) ($args['flutter'] ?? false);

                $generator = new SliceGenerator();
                $dir = $generator->generate($name, [], $workflow);

                return [
                    'success'   => true,
                    'slice'     => $name,
                    'directory' => $dir,
                    'workflow'  => $workflow,
                ];

            case 'query_database_metrics':
                return $this->aiEngine->getSystemContext()['database_metrics'];

            case 'prune_audit_logs':
                $days = (int) ($args['days'] ?? 90);
                $cmdArgs = ['--days' => $days, '--force' => true];
                if (!empty($args['slice'])) {
                    $cmdArgs['--slice'] = $args['slice'];
                }
                $exitCode = Artisan::call('laraslice:audit:prune', $cmdArgs);
                return [
                    'success' => $exitCode === 0,
                    'output'  => Artisan::output(),
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
