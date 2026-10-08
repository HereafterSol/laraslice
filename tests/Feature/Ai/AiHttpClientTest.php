<?php

namespace LaraSlice\Tests\Feature\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Tests\TestCase;

class AiHttpClientTest extends TestCase
{
    private function invoke(string $method, array $args): ?string
    {
        $engine = app(AiEngine::class);
        $ref = new \ReflectionMethod($engine, $method);

        return $ref->invokeArgs($engine, $args);
    }

    public function test_anthropic_reply_is_read_through_the_http_client(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'thinking', 'thinking' => '...'], ['type' => 'text', 'text' => 'Hello']],
        ])]);

        $this->assertSame('Hello', $this->invoke('callAnthropic', ['key', 'claude-opus-5-5', 'sys', 'hi']));

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'key')
            && $request['model'] === 'claude-opus-5-5'
            && $request['system'] === 'sys');
    }

    public function test_server_errors_are_retried(): void
    {
        config(['laraslice.ai.http.retries' => 2]);
        Http::fakeSequence('example.test/*')
            ->push('busy', 503)
            ->push(['choices' => [['message' => ['content' => 'ok']]]]);

        $this->assertSame('ok', $this->invoke('callOpenAiCompatible', ['https://example.test/v1/chat', 'key', 'm', 'sys', 'hi']));
        Http::assertSentCount(2);
    }

    public function test_client_errors_are_logged_without_the_key_and_not_retried(): void
    {
        Log::spy();
        Http::fake(['example.test/*' => Http::response(['error' => 'bad key'], 401)]);

        $this->assertNull($this->invoke('callOpenAiCompatible', ['https://example.test/v1/chat', 'secret-key', 'm', 'sys', 'hi']));
        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $ctx['status'] === 401
            && ! str_contains(json_encode($ctx), 'secret-key'))->once();
    }

    public function test_connection_failures_return_null(): void
    {
        config(['laraslice.ai.http.retries' => 1]);
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->assertNull($this->invoke('callOllama', ['http://localhost:11434', 'llama', 'sys', 'hi']));
    }
}
