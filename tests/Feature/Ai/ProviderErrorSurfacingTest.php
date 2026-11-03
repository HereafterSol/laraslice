<?php

namespace LaraSlice\Tests\Feature\Ai;

use Illuminate\Support\Facades\Http;
use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Tests\TestCase;

/**
 * A provider failure must SAY why. Before this, ProviderClient logged the
 * status and body but returned a bare null, so every caller (Copilot,
 * Studio drafting) could only tell the user "the provider did not answer" —
 * a real OpenCode free-quota 429 (verified live, Nov 2026) was
 * indistinguishable from a dead key or an outage.
 */
class ProviderErrorSurfacingTest extends TestCase
{
    private function invokeCompatible(AiEngine $engine, string $url): ?string
    {
        $ref = new \ReflectionMethod($engine, 'callOpenAiCompatible');

        return $ref->invokeArgs($engine, [$url, 'key', 'm', 'sys', 'hi']);
    }

    public function test_quota_error_is_surfaced_with_status_and_provider_message(): void
    {
        config(['laraslice.ai.http.retries' => 0]);
        Http::fake(['opencode.ai/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'FreeUsageLimitError', 'message' => 'Free usage limit reached.'],
        ], 429)]);

        $engine = app(AiEngine::class);
        $this->assertNull($this->invokeCompatible($engine, 'https://opencode.ai/zen/v1/chat/completions'));

        $error = $engine->lastProviderError();
        $this->assertNotNull($error);
        $this->assertStringContainsString('429', $error);
        $this->assertStringContainsString('Free usage limit reached.', $error);
    }

    public function test_a_later_success_clears_the_error(): void
    {
        config(['laraslice.ai.http.retries' => 0]);
        $engine = app(AiEngine::class);

        Http::fakeSequence('example.test/*')
            ->push(['error' => ['message' => 'bad key']], 401)
            ->push(['choices' => [['message' => ['content' => 'ok']]]]);

        $this->assertNull($this->invokeCompatible($engine, 'https://example.test/v1/chat'));
        $this->assertStringContainsString('401', (string) $engine->lastProviderError());

        $this->assertSame('ok', $this->invokeCompatible($engine, 'https://example.test/v1/chat'));
        $this->assertNull($engine->lastProviderError());
    }

    public function test_opencode_default_model_is_in_the_provider_catalogue(): void
    {
        $engine = app(AiEngine::class);
        $providers = collect($engine->getProviders())->keyBy('id');

        $this->assertContains(
            AiEngine::DEFAULT_MODELS['opencode'],
            $providers['opencode']['models'],
            'the default opencode model must be one of the models the settings page offers'
        );
        $this->assertSame(
            AiEngine::DEFAULT_MODELS['opencode'],
            $providers['opencode']['model']
        );
    }
}
