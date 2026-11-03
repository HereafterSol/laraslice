<?php

namespace LaraSlice\Tests\Feature\Ai;

use LaraSlice\Core\Ai\McpServer;
use LaraSlice\LaraSliceServiceProvider;
use LaraSlice\Tests\TestCase;

class McpProtocolTest extends TestCase
{
    private function rpc(array $payload): ?array
    {
        return app(McpServer::class)->handleRpc($payload);
    }

    public function test_initialize_negotiates_the_protocol_version(): void
    {
        $known = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']]);
        $this->assertSame('2024-11-05', $known['result']['protocolVersion']);
        $this->assertSame(LaraSliceServiceProvider::VERSION, $known['result']['serverInfo']['version']);
        $this->assertSame(['tools', 'resources'], array_keys($known['result']['capabilities']));

        $unknown = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
        $this->assertSame(McpServer::PROTOCOL_VERSIONS[0], $unknown['result']['protocolVersion']);
    }

    public function test_notifications_get_no_response(): void
    {
        $this->assertNull($this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function test_ping_and_empty_prompt_list(): void
    {
        $this->assertEquals((object) [], $this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'])['result']);
        $this->assertSame([], $this->rpc(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/list'])['result']['prompts']);
    }

    public function test_dual_era_negotiation(): void
    {
        // 2026-07-28 stateless era negotiates like any version.
        $new = $this->rpc(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'initialize', 'params' => ['protocolVersion' => '2026-07-28']]);
        $this->assertSame('2026-07-28', $new['result']['protocolVersion']);

        // ...and via _meta, where the new era carries it.
        $meta = $this->rpc(['jsonrpc' => '2.0', 'id' => 12, 'method' => 'initialize', 'params' => ['_meta' => ['protocolVersion' => '2025-06-18']]]);
        $this->assertSame('2025-06-18', $meta['result']['protocolVersion']);

        // Default = newest supported.
        $this->assertSame('2026-07-28', McpServer::PROTOCOL_VERSIONS[0]);
    }

    public function test_resources_list_and_read(): void
    {
        $list = $this->rpc(['jsonrpc' => '2.0', 'id' => 13, 'method' => 'resources/list'])['result']['resources'];
        $uris = array_column($list, 'uri');
        $this->assertContains('laraslice://skills/index', $uris);
        $this->assertContains('laraslice://gates/definitions', $uris);
        $this->assertContains('laraslice://commands/index', $uris);

        $skills = $this->rpc(['jsonrpc' => '2.0', 'id' => 14, 'method' => 'resources/read', 'params' => ['uri' => 'laraslice://skills/index']]);
        $this->assertStringContainsString('laraslice', $skills['result']['contents'][0]['text']);

        $gates = $this->rpc(['jsonrpc' => '2.0', 'id' => 15, 'method' => 'resources/read', 'params' => ['uri' => 'laraslice://gates/definitions']]);
        $decoded = json_decode($gates['result']['contents'][0]['text'], true);
        $this->assertSame(['S', 'D', 'F', 'T', 'V'], array_column($decoded['gates'], 'id'));

        $unknown = $this->rpc(['jsonrpc' => '2.0', 'id' => 16, 'method' => 'resources/read', 'params' => ['uri' => 'laraslice://nope']]);
        $this->assertSame(-32602, $unknown['error']['code']);
    }

    public function test_tools_are_described_with_input_schemas(): void
    {
        $tools = $this->rpc(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list'])['result']['tools'];

        $this->assertNotEmpty($tools);
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('inputSchema', $tool, $tool['name']);
            $this->assertArrayNotHasKey('parameters', $tool);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }

        $destroy = collect($tools)->firstWhere('name', 'destroy_slice');
        $this->assertTrue($destroy['annotations']['destructiveHint']);
    }

    public function test_unknown_methods_and_tools_are_errors(): void
    {
        $this->assertSame(-32601, $this->rpc(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'nope'])['error']['code']);

        $call = $this->rpc(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'nope']]);
        $this->assertTrue($call['result']['isError']);
    }
}
