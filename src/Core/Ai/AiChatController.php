<?php

namespace LaraSlice\Core\Ai;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

class AiChatController
{
    protected AiEngine $aiEngine;

    public function __construct(AiEngine $aiEngine)
    {
        $this->aiEngine = $aiEngine;
    }

    /**
     * Display AI Copilot & Model Settings view.
     */
    public function settings(): View
    {
        // Check permission if user is authenticated
        if (auth()->check()) {
            $user = auth()->user();
            $canAccess = ($user->email === config('laraslice.super_admin_email', 'admin@laraslice.com')) ||
                (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) ||
                (method_exists($user, 'hasPermission') && ($user->hasPermission('ai.settings.view') || $user->hasPermission('settings.view'))) ||
                (method_exists($user, 'can') && ($user->can('ai.settings.view') || $user->can('settings.view')));

            if (!$canAccess) {
                abort(403, 'Unauthorized access to AI Copilot Settings.');
            }
        }

        $providers = $this->aiEngine->getProviders();
        $activeProvider = $this->aiEngine->getActiveProvider();
        
        $settings = [
            'default_provider' => $activeProvider,
            'opencode_api_key' => $this->aiEngine->getSetting('ai.opencode_api_key', '') ?: env('OPENCODE_API_KEY', 'sk-5bR4ae9ul9VzQbUyQRzmBR7S7hkgIekoGhbhheJoH5G3eD5xH8WeJgP8Ld1nw6om'),
            'opencode_model'   => $this->aiEngine->getSetting('ai.opencode_model', 'space-bunny-free') ?: env('OPENCODE_MODEL', 'space-bunny-free'),
            'openai_api_key'   => $this->aiEngine->getSetting('ai.openai_api_key', ''),
            'openai_model'     => $this->aiEngine->getSetting('ai.openai_model', 'gpt-4o-mini'),
            'gemini_api_key'   => $this->aiEngine->getSetting('ai.gemini_api_key', ''),
            'gemini_model'     => $this->aiEngine->getSetting('ai.gemini_model', 'gemini-2.5-flash'),
            'anthropic_api_key'=> $this->aiEngine->getSetting('ai.anthropic_api_key', ''),
            'anthropic_model'  => $this->aiEngine->getSetting('ai.anthropic_model', 'claude-3-5-sonnet-20241022'),
            'openrouter_api_key'=> $this->aiEngine->getSetting('ai.openrouter_api_key', ''),
            'openrouter_model' => $this->aiEngine->getSetting('ai.openrouter_model', 'meta-llama/llama-3.3-70b-instruct'),
            'ollama_endpoint'  => $this->aiEngine->getSetting('ai.ollama_endpoint', 'http://localhost:11434'),
            'ollama_model'     => $this->aiEngine->getSetting('ai.ollama_model', 'deepseek-r1:8b'),
            'allow_telemetry'  => $this->aiEngine->getSetting('ai.allow_telemetry', 'true') === 'true',
            'clarifying_wizard'=> $this->aiEngine->getSetting('ai.clarifying_wizard', 'true') === 'true',
            'floating_bubble'  => $this->aiEngine->getSetting('ai.floating_bubble', 'true') === 'true',
        ];

        $context = $this->aiEngine->getSystemContext('/admin/settings/ai');

        return view('laraslice::ai-settings', compact('providers', 'activeProvider', 'settings', 'context'));
    }

    /**
     * Save AI Copilot and provider settings into settings table.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_provider'   => 'required|string',
            'opencode_api_key'   => 'nullable|string',
            'opencode_model'     => 'nullable|string',
            'openai_api_key'     => 'nullable|string',
            'openai_model'       => 'nullable|string',
            'gemini_api_key'     => 'nullable|string',
            'gemini_model'       => 'nullable|string',
            'anthropic_api_key'  => 'nullable|string',
            'anthropic_model'    => 'nullable|string',
            'openrouter_api_key' => 'nullable|string',
            'openrouter_model'   => 'nullable|string',
            'ollama_endpoint'    => 'nullable|string',
            'ollama_model'       => 'nullable|string',
            'allow_telemetry'    => 'nullable',
            'clarifying_wizard'  => 'nullable',
            'floating_bubble'    => 'nullable',
        ]);

        $keys = [
            'ai.default_provider'   => $validated['default_provider'],
            'ai.opencode_api_key'   => $validated['opencode_api_key'] ?? '',
            'ai.opencode_model'     => $validated['opencode_model'] ?? 'space-bunny-free',
            'ai.openai_api_key'     => $validated['openai_api_key'] ?? '',
            'ai.openai_model'       => $validated['openai_model'] ?? 'gpt-4o-mini',
            'ai.gemini_api_key'     => $validated['gemini_api_key'] ?? '',
            'ai.gemini_model'       => $validated['gemini_model'] ?? 'gemini-2.5-flash',
            'ai.anthropic_api_key'  => $validated['anthropic_api_key'] ?? '',
            'ai.anthropic_model'    => $validated['anthropic_model'] ?? 'claude-3-5-sonnet-20241022',
            'ai.openrouter_api_key' => $validated['openrouter_api_key'] ?? '',
            'ai.openrouter_model'   => $validated['openrouter_model'] ?? 'meta-llama/llama-3.3-70b-instruct',
            'ai.ollama_endpoint'    => $validated['ollama_endpoint'] ?? 'http://localhost:11434',
            'ai.ollama_model'       => $validated['ollama_model'] ?? 'deepseek-r1:8b',
            'ai.allow_telemetry'    => $request->has('allow_telemetry') ? 'true' : 'false',
            'ai.clarifying_wizard'  => $request->has('clarifying_wizard') ? 'true' : 'false',
            'ai.floating_bubble'    => $request->has('floating_bubble') ? 'true' : 'false',
        ];

        foreach ($keys as $k => $v) {
            DB::table('settings')->updateOrInsert(
                ['key' => $k],
                [
                    'group'      => 'ai',
                    'value'      => (string) $v,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
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
        $provider = $request->input('provider');
        $history = (array) $request->input('history', []);

        if (trim($message) === '') {
            return response()->json([
                'success' => false,
                'reply'   => 'Please provide a message or question.',
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
            'success'   => true,
            'providers' => $this->aiEngine->getProviders(),
            'default'   => $this->aiEngine->getActiveProvider(),
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
        $provider = $request->input('provider', 'opencode');
        $apiKey = $request->input('api_key');

        if ($apiKey && in_array($provider, ['openai', 'gemini', 'anthropic', 'openrouter'], true)) {
            $keyName = "ai.{$provider}_api_key";
            DB::table('settings')->updateOrInsert(
                ['key' => $keyName],
                [
                    'group'      => 'ai',
                    'value'      => $apiKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'ai.default_provider'],
            [
                'group'      => 'ai',
                'value'      => $provider,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "AI settings updated. Active provider: {$provider}",
        ]);
    }
    /**
     * Get real-time 1-paragraph overview and data metrics for current page.
     */
    public function pageOverview(Request $request): JsonResponse
    {
        $path = $request->input('path', $request->header('X-Current-Path', '/'));
        $overview = $this->aiEngine->getPageOverview($path);

        return response()->json([
            'success'  => true,
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
