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
        $this->assertSame(['tools'], array_keys($known['result']['capabilities']));

        $unknown = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
        $this->assertSame(McpServer::PROTOCOL_VERSIONS[0], $unknown['result']['protocolVersion']);
    }

    public function test_notifications_get_no_response(): void
    {
        $this->assertNull($this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    }

    public function test_ping_and_empty_resource_and_prompt_lists(): void
    {
        $this->assertEquals((object) [], $this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'])['result']);
        $this->assertSame([], $this->rpc(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list'])['result']['resources']);
        $this->assertSame([], $this->rpc(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/list'])['result']['prompts']);
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
