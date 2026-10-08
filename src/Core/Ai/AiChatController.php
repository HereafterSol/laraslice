<?php

namespace LaraSlice\Core\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LaraSlice\Core\Security\Access;
use LaraSlice\Slices\Settings\Models\Setting;

class AiChatController
{
    protected AiEngine $aiEngine;

    public function __construct(AiEngine $aiEngine)
    {
        $this->aiEngine = $aiEngine;
    }

    /** Providers whose API key is stored (encrypted) in the settings table. */
    private const KEYED_PROVIDERS = ['opencode', 'openai', 'gemini', 'anthropic', 'openrouter'];

    /**
     * Display AI Copilot & Model Settings view.
     */
    public function settings(): View
    {
        $this->authorizeAi(['ai.settings.view', 'ai.settings.edit', 'settings.view']);

        $providers = $this->aiEngine->getProviders();
        $activeProvider = $this->aiEngine->getActiveProvider();

        $settings = [
            'default_provider' => $activeProvider,
            'opencode_model' => $this->aiEngine->providerModel('opencode', 'space-bunny-free'),
            'openai_model' => $this->aiEngine->providerModel('openai', AiEngine::DEFAULT_MODELS['openai']),
            'gemini_model' => $this->aiEngine->providerModel('gemini', AiEngine::DEFAULT_MODELS['gemini']),
            'anthropic_model' => $this->aiEngine->providerModel('anthropic', AiEngine::DEFAULT_MODELS['anthropic']),
            'openrouter_model' => $this->aiEngine->providerModel('openrouter', 'meta-llama/llama-3.3-70b-instruct'),
            'ollama_endpoint' => $this->aiEngine->getSetting('ai.ollama_endpoint', 'http://localhost:11434'),
            'ollama_model' => $this->aiEngine->providerModel('ollama', 'deepseek-r1:8b'),
            'allow_telemetry' => $this->aiEngine->getSetting('ai.allow_telemetry', 'true') === 'true',
            'clarifying_wizard' => $this->aiEngine->getSetting('ai.clarifying_wizard', 'true') === 'true',
            'floating_bubble' => $this->aiEngine->getSetting('ai.floating_bubble', 'true') === 'true',
        ];

        // Stored keys are never sent back to the browser; the form only learns whether one is set.
        foreach (self::KEYED_PROVIDERS as $provider) {
            $settings["{$provider}_api_key"] = '';
            $settings["{$provider}_api_key_set"] = ! empty($this->aiEngine->providerKey($provider));
        }

        $context = $this->aiEngine->getSystemContext('/admin/settings/ai');

        return view('laraslice::ai-settings', compact('providers', 'activeProvider', 'settings', 'context'));
    }

    /**
     * Save AI Copilot and provider settings into settings table.
     * API keys are stored encrypted; a blank key field keeps the stored key.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorizeAi(['ai.settings.edit', 'settings.edit']);

        $validated = $request->validate([
            'default_provider' => 'required|string|in:opencode,openai,gemini,anthropic,openrouter,ollama',
            'opencode_api_key' => 'nullable|string|max:500',
            'opencode_model' => 'nullable|string|max:150',
            'openai_api_key' => 'nullable|string|max:500',
            'openai_model' => 'nullable|string|max:150',
            'gemini_api_key' => 'nullable|string|max:500',
            'gemini_model' => 'nullable|string|max:150',
            'anthropic_api_key' => 'nullable|string|max:500',
            'anthropic_model' => 'nullable|string|max:150',
            'openrouter_api_key' => 'nullable|string|max:500',
            'openrouter_model' => 'nullable|string|max:150',
            'ollama_endpoint' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//i'],
            'ollama_model' => 'nullable|string|max:150',
            'allow_telemetry' => 'nullable',
            'clarifying_wizard' => 'nullable',
            'floating_bubble' => 'nullable',
        ]);

        foreach (self::KEYED_PROVIDERS as $provider) {
            if (! empty($validated["{$provider}_api_key"])) {
                Setting::set("ai.{$provider}_api_key", $validated["{$provider}_api_key"], 'ai', true);
            } elseif ($request->boolean("{$provider}_api_key_clear")) {
                Setting::set("ai.{$provider}_api_key", '', 'ai', true);
            }
        }

        $plain = [
            'ai.default_provider' => $validated['default_provider'],
            'ai.opencode_model' => $validated['opencode_model'] ?? 'space-bunny-free',
            'ai.openai_model' => $validated['openai_model'] ?? AiEngine::DEFAULT_MODELS['openai'],
            'ai.gemini_model' => $validated['gemini_model'] ?? AiEngine::DEFAULT_MODELS['gemini'],
            'ai.anthropic_model' => $validated['anthropic_model'] ?? AiEngine::DEFAULT_MODELS['anthropic'],
            'ai.openrouter_model' => $validated['openrouter_model'] ?? 'meta-llama/llama-3.3-70b-instruct',
            'ai.ollama_endpoint' => $validated['ollama_endpoint'] ?? 'http://localhost:11434',
            'ai.ollama_model' => $validated['ollama_model'] ?? 'deepseek-r1:8b',
            'ai.allow_telemetry' => $request->has('allow_telemetry') ? 'true' : 'false',
            'ai.clarifying_wizard' => $request->has('clarifying_wizard') ? 'true' : 'false',
            'ai.floating_bubble' => $request->has('floating_bubble') ? 'true' : 'false',
        ];

        foreach ($plain as $k => $v) {
            Setting::set($k, (string) $v, 'ai');
        }

        return redirect()->route('settings.ai')->with('success', "AI Settings successfully updated! Active provider: {$validated['default_provider']}");
    }

    /**
     * Run diagnostic test on the selected provider.
     */
    public function test(Request $request): JsonResponse
    {
        $provider = $request->input('provider', 'opencode');
        $result = $this->aiEngine->testProvider($provider);

        return response()->json($result);
    }

    /**
     * Handle incoming chat message from floating bubble or Studio Copilot.
     */
    public function chat(Request $request): JsonResponse
    {
        $message = (string) $request->input('message', '');
        $pageContext = (array) $request->input('page_context', [
            'path' => $request->header('X-Current-Path', '/'),
        ]);
        $provider = null; // always the provider configured in AI settings
        $history = (array) $request->input('history', []);

        if (trim($message) === '') {
            return response()->json([
                'success' => false,
                'reply' => 'Please provide a message or question.',
            ], 422);
        }

        $response = $this->aiEngine->chat($message, $pageContext, $provider, $history);

        return response()->json($response);
    }

    /**
     * Get available AI providers (OpenCode Free at top).
     */
    public function providers(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'providers' => $this->aiEngine->getProviders(),
            'default' => $this->aiEngine->getActiveProvider(),
        ]);
    }

    /**
     * Get live telemetry and framework context.
     */
    public function context(Request $request): JsonResponse
    {
        $path = $request->query('path', '/');

        return response()->json([
            'success' => true,
            'context' => $this->aiEngine->getSystemContext($path),
        ]);
    }

    /**
     * Save AI settings or custom provider keys via REST API.
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => 'required|string|in:opencode,openai,gemini,anthropic,openrouter,ollama',
            'api_key' => 'nullable|string|max:500',
        ]);
        $provider = $validated['provider'];

        if (! empty($validated['api_key']) && in_array($provider, self::KEYED_PROVIDERS, true)) {
            Setting::set("ai.{$provider}_api_key", $validated['api_key'], 'ai', true);
        }

        Setting::set('ai.default_provider', $provider, 'ai');

        return response()->json([
            'success' => true,
            'message' => "AI settings updated. Active provider: {$provider}",
        ]);
    }

    /**
     * Abort unless the signed-in user is a super-admin or holds one of the abilities.
     *
     * @param  array<int, string>  $abilities
     */
    private function authorizeAi(array $abilities): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if (! Access::allows($user, $abilities)) {
            abort(403, 'Unauthorized access to AI Copilot Settings.');
        }
    }

    /**
     * Get real-time 1-paragraph overview and data metrics for current page.
     */
    public function pageOverview(Request $request): JsonResponse
    {
        $path = $request->input('path', $request->header('X-Current-Path', '/'));
        $overview = $this->aiEngine->getPageOverview($path);

        return response()->json([
            'success' => true,
            'overview' => $overview,
        ]);
    }

    /**
     * Insert a record directly into application table via AI Copilot.
     */
    public function createRecord(Request $request): JsonResponse
    {
        $table = (string) $request->input('table', '');
        $data = (array) $request->input('data', []);
        $mode = (string) $request->input('mode', 'manual');

        if (empty($table)) {
            return response()->json(['success' => false, 'message' => 'Table name is required.'], 422);
        }

        $result = $this->aiEngine->createRecord($table, $data, $mode);

        return response()->json($result);
    }
}
