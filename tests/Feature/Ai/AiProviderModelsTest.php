<?php

namespace LaraSlice\Tests\Feature\Ai;

use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Tests\TestCase;

class AiProviderModelsTest extends TestCase
{
    private const RETIRED = ['claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022', 'gemini-1.5-flash', 'gpt-4-turbo'];

    public function test_provider_defaults_and_choices_use_current_models(): void
    {
        $providers = collect(app(AiEngine::class)->getProviders())->keyBy('id');

        $this->assertSame('claude-opus-5-5', $providers['anthropic']['model']);
        $this->assertSame('gemini-3.8-flash', $providers['gemini']['model']);

        foreach ($providers as $provider) {
            $this->assertContains($provider['model'], $provider['models'], "{$provider['id']} default must be one of its choices");
            $this->assertSame([], array_values(array_intersect(self::RETIRED, $provider['models'])), $provider['id']);
        }
    }

    public function test_settings_page_offers_current_models_only(): void
    {
        $html = $this->actingAs($this->makeSuperAdmin())->get('/admin/settings/ai')->assertOk()->getContent();

        $this->assertStringContainsString('value="claude-opus-5-5" selected', $html);
        foreach (self::RETIRED as $model) {
            $this->assertStringNotContainsString("value=\"{$model}\"", $html);
        }
    }

    public function test_anthropic_replies_skip_leading_thinking_blocks(): void
    {
        $response = ['content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'abc'],
            ['type' => 'text', 'text' => 'Paris'],
        ]];

        $this->assertSame('Paris', AiEngine::anthropicText($response));
        $this->assertNull(AiEngine::anthropicText(['content' => [['type' => 'thinking', 'thinking' => '']]]));
    }
}
