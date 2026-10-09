<?php

namespace LaraSlice\Core\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LaraSlice\LaraSliceServiceProvider;

/**
 * HTTP clients for the chat providers the copilot supports: OpenAI-compatible APIs
 * (OpenAI, Gemini, OpenRouter, OpenCode), the Anthropic Messages API and Ollama.
 * Every call returns the reply text, or null when the provider could not answer.
 */
class ProviderClient
{
    /**
     * Standard OpenAI-compatible Chat API call.
     */
    public function openAiCompatible(string $url, string $apiKey, string $model, string $systemPrompt, string $message, array $history = []): ?string
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($this->sanitizeHistory($history) as $h) {
            $messages[] = $h;
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1200,
        ];

        $data = $this->postJson($url, $payload, ['Authorization' => "Bearer {$apiKey}"]);

        return $data['choices'][0]['message']['content'] ?? null;
    }

    /**
     * POST a JSON payload to a provider and return the decoded response, or null on failure.
     * Connection errors, 429 and 5xx responses are retried; failures are logged without secrets.
     */
    protected function postJson(string $url, array $payload, array $headers = [], ?int $timeout = null): ?array
    {
        $timeout ??= (int) config('laraslice.ai.http.timeout', 60);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->withUserAgent('LaraSlice/'.LaraSliceServiceProvider::VERSION)
                ->connectTimeout(min(10, $timeout))
                ->timeout($timeout)
                ->retry(
                    (int) config('laraslice.ai.http.retries', 2),
                    fn (int $attempt) => $attempt * 500,
                    fn (\Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException
                            && ($e->response->status() === 429 || $e->response->serverError())),
                    throw: false
                )
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('LaraSlice AI request failed', [
                'host' => parse_url($url, PHP_URL_HOST),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('LaraSlice AI provider returned an error', [
                'host' => parse_url($url, PHP_URL_HOST),
                'status' => $response->status(),
                'error' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    /**
     * Keep the last few client-supplied turns, limited to user/assistant roles and bounded length.
     * A client must never be able to inject a system message.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function sanitizeHistory(array $history): array
    {
        $clean = [];
        foreach (array_slice($history, -4) as $h) {
            if (! is_array($h) || ! is_string($h['content'] ?? null) || trim($h['content']) === '') {
                continue;
            }
            $clean[] = [
                'role' => ($h['role'] ?? null) === 'assistant' ? 'assistant' : 'user',
                'content' => mb_substr($h['content'], 0, 4000),
            ];
        }

        return $clean;
    }

    /**
     * Anthropic Claude Messages API call.
     */
    public function anthropic(string $apiKey, string $model, string $systemPrompt, string $message, array $history = []): ?string
    {
        $messages = $this->sanitizeHistory($history);
        $messages[] = ['role' => 'user', 'content' => $message];

        $payload = [
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => $messages,
            // Current Claude models think before answering; leave room for both
            'max_tokens' => 4096,
        ];

        $data = $this->postJson('https://api.anthropic.com/v1/messages', $payload, [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ]);

        return $data === null ? null : self::anthropicText($data);
    }

    /**
     * The answer text of a Messages API response. Current Claude models return thinking
     * blocks before the text, so the reply is never simply content[0].
     */
    public static function anthropicText(array $response): ?string
    {
        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text !== '' ? $text : null;
    }

    /**
     * Ollama local API call.
     */
    public function ollama(string $endpoint, string $model, string $systemPrompt, string $message, array $history = []): ?string
    {
        $url = rtrim($endpoint, '/').'/api/chat';
        $messages = [['role' => 'system', 'content' => $systemPrompt], ...$this->sanitizeHistory($history)];
        $messages[] = ['role' => 'user', 'content' => $message];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ];

        $data = $this->postJson($url, $payload);

        return $data['message']['content'] ?? null;
    }
}
