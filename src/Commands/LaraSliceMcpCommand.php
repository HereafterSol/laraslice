<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Ai\McpServer;

class LaraSliceMcpCommand extends Command
{
    protected $signature = 'laraslice:mcp 
                            {--transport=stdio : Transport mechanism: stdio or sse}
                            {--test : Run a self-test of the MCP server tool registry}';

    protected $description = 'Start the LaraSlice Model Context Protocol (MCP) server for Cursor, Antigravity, and Claude AI agents';

    public function handle(McpServer $mcpServer): int
    {
        if ($this->option('test')) {
            $this->info("🔍 Running LaraSlice MCP Server Self-Test...");
            $tools = $mcpServer->callTool('list_slices', []);
            $metrics = $mcpServer->callTool('query_database_metrics', []);
            $this->line("✅ Tools registered and active.");
            $this->line("📊 Database Users: " . ($metrics['users_total'] ?? 0));
            $this->line("📦 Slices Discovered: " . count($tools));
            return Command::SUCCESS;
        }

        // Stdio Transport for IDE Agents (Cursor, Claude, Antigravity)
        if (ob_get_level()) {
            ob_end_clean();
        }

        $stdin = fopen('php://stdin', 'r');
        if (! $stdin) {
            $this->error('Failed to open STDIN for MCP server.');
            return Command::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $payload = json_decode($line, true);
            if (! is_array($payload)) {
                $err = [
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    'error'   => ['code' => -32700, 'message' => 'Parse error'],
                ];
                echo json_encode($err) . "\n";
                flush();
                continue;
            }

            $response = $mcpServer->handleRpc($payload);
            echo json_encode($response) . "\n";
            flush();
        }

        fclose($stdin);
        return Command::SUCCESS;
    }
}
