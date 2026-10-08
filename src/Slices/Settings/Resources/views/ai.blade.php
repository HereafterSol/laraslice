@extends('layouts.app')

@section('title', 'AI Copilot & Model Settings')

@section('content')
<script>
function aiSettingsApp() {
    return {
        activeTab: (window.location.hash ? window.location.hash.substring(1) : '') || 'providers',
        selectedProvider: '{{ $activeProvider }}',
        testLoading: false,
        testResult: null,

        setTab(tab) {
            this.activeTab = tab;
            window.location.hash = tab;
        },

        async runTest(prov) {
            this.testLoading = true;
            this.testResult = null;
            try {
                const res = await fetch('{{ route('laraslice.ai.test') }}', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ provider: prov || this.selectedProvider })
                });
                this.testResult = await res.json();
            } catch (e) {
                this.testResult = { 
                    success: false, 
                    message: 'Network error contacting test endpoint: ' + (e ? e.message : 'Unknown error') 
                };
            } finally {
                this.testLoading = false;
            }
        }
    };
}
</script>

<div class="w-full max-w-6xl mx-auto space-y-6" x-data="aiSettingsApp()">
    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:text-primary transition-colors">Dashboard</a>
        <span>/</span>
        <a href="{{ route('settings.smtp') }}" class="hover:text-primary transition-colors">Settings</a>
        <span>/</span>
        <span class="text-foreground font-medium">AI Copilot & Models</span>
    </div>

    <!-- Top Action Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <div class="size-8 rounded-xl bg-gradient-to-tr from-indigo-500 to-pink-500 text-white flex items-center justify-center shadow-sm">
                    <x-lucide-bot class="size-5" />
                </div>
                <span>AI Copilot & Model Settings</span>
            </h1>
            <p class="text-sm text-muted-foreground">Configure AI providers, LLM models, API credentials, real-time database reasoning, and interactive questionnaire behavior</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button type="button" @click="runTest(selectedProvider)" variant="outline" size="sm" x-bind:disabled="testLoading">
                <template x-if="!testLoading">
                    <div class="flex items-center gap-1.5">
                        <x-lucide-zap class="size-4 text-amber-500" />
                        <span>Test Active Model</span>
                    </div>
                </template>
                <template x-if="testLoading">
                    <div class="flex items-center gap-1.5">
                        <span class="size-3.5 rounded-full border-2 border-primary border-t-transparent animate-spin"></span>
                        <span>Pinging AI...</span>
                    </div>
                </template>
            </x-ui.button>
            <x-ui.button href="{{ route('laraslice.wizard.schema_studio') }}" as="a" variant="secondary" size="sm">
                <x-lucide-layout-grid class="size-4 mr-1.5" />
                <span>Schema Studio</span>
            </x-ui.button>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-success/30 bg-success/10 text-success text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- Test Result Alert (Dynamic) -->
    <div x-show="testResult" x-transition class="p-4 rounded-xl border text-xs shadow-sm flex items-start justify-between gap-3"
         :class="testResult && testResult.success ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-destructive/30 bg-destructive/10 text-destructive'">
        <div class="flex items-start gap-2.5">
            <x-lucide-activity class="size-4 shrink-0 mt-0.5" />
            <div>
                <div class="font-bold flex items-center gap-2">
                    <span x-text="testResult && testResult.success ? 'Diagnostic Test Passed' : 'Diagnostic Test Failed'"></span>
                    <span class="px-1.5 py-0.2 rounded bg-black/30 font-mono text-[10px]" x-text="testResult ? testResult.latency : ''"></span>
                </div>
                <p class="mt-1 whitespace-pre-wrap font-mono text-[11px]" x-text="testResult ? testResult.message : ''"></p>
            </div>
        </div>
        <button type="button" @click="testResult = null" class="text-muted-foreground hover:text-foreground">
            <x-lucide-x class="size-4" />
        </button>
    </div>

    <!-- Quick Telemetry Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-card border border-border shadow-xs flex items-center gap-3">
            <div class="size-10 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center shrink-0">
                <x-lucide-cpu class="size-5" />
            </div>
            <div>
                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Active Provider</span>
                <p class="text-sm font-bold text-foreground capitalize" x-text="selectedProvider === 'opencode' ? 'OpenCode Free' : selectedProvider"></p>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-card border border-border shadow-xs flex items-center gap-3">
            <div class="size-10 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center shrink-0">
                <x-lucide-database class="size-5" />
            </div>
            <div>
                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Live Telemetry</span>
                <p class="text-sm font-bold text-emerald-600">Active ({{ $context['database_metrics']['users_total'] ?? 0 }} Users)</p>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-card border border-border shadow-xs flex items-center gap-3">
            <div class="size-10 rounded-xl bg-purple-500/10 text-purple-500 flex items-center justify-center shrink-0">
                <x-lucide-help-circle class="size-5" />
            </div>
            <div>
                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Clarifying Questions</span>
                <p class="text-sm font-bold text-purple-600">(3-Step)</p>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-card border border-border shadow-xs flex items-center gap-3">
            <div class="size-10 rounded-xl bg-pink-500/10 text-pink-500 flex items-center justify-center shrink-0">
                <x-lucide-message-square class="size-5" />
            </div>
            <div>
                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Floating Bubble</span>
                <p class="text-sm font-bold text-pink-600">Enabled</p>
            </div>
        </div>
    </div>

    <!-- Main Settings Card with Tabs -->
    <x-ui.card class="shadow-sm bg-card border border-border overflow-hidden">
        <!-- Navigation Tabs -->
        <div class="flex items-center gap-2 border-b border-border px-6 pt-3 bg-muted/20 overflow-x-auto">
            <button type="button" @click="setTab('providers')" 
                    class="pb-3 px-3 text-xs font-semibold border-b-2 transition-colors flex items-center gap-2"
                    :class="activeTab === 'providers' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'">
                <x-lucide-cpu class="size-4" />
                <span>AI Providers & Models</span>
            </button>
            <button type="button" @click="setTab('behavior')" 
                    class="pb-3 px-3 text-xs font-semibold border-b-2 transition-colors flex items-center gap-2"
                    :class="activeTab === 'behavior' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'">
                <x-lucide-sliders class="size-4" />
                <span>Copilot Reasoning & Telemetry</span>
            </button>
            <button type="button" @click="setTab('mcp')" 
                    class="pb-3 px-3 text-xs font-semibold border-b-2 transition-colors flex items-center gap-2"
                    :class="activeTab === 'mcp' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'">
                <x-lucide-terminal class="size-4" />
                <span>MCP Server & IDE Tools</span>
            </button>
        </div>

        <form action="{{ route('settings.ai.save') }}" method="POST" class="p-6 space-y-6">
            @csrf

            <!-- TAB 1: PROVIDERS & MODELS -->
            <div x-show="activeTab === 'providers'" class="space-y-6">
                <div>
                    <h3 class="text-sm font-bold text-foreground">Select Active AI Engine</h3>
                    <p class="text-xs text-muted-foreground">OpenCode AI Free is ready out of the box with zero configuration. You can also connect your own enterprise API keys.</p>
                </div>

                <!-- Provider Selector Radio Grid -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach ($providers as $prov)
                    <label class="relative flex flex-col p-4 rounded-xl border cursor-pointer transition-all duration-200"
                           :class="selectedProvider === '{{ $prov['id'] }}' ? 'border-primary bg-primary/5 ring-1 ring-primary shadow-xs' : 'border-border bg-card/60 hover:bg-muted/40'">
                        <input type="radio" name="default_provider" value="{{ $prov['id'] }}" x-model="selectedProvider" class="sr-only" />
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-bold text-xs text-foreground flex items-center gap-1.5">
                                @if($prov['id'] === 'opencode')
                                    <x-lucide-sparkles class="size-3.5 text-emerald-500" />
                                @elseif($prov['id'] === 'openai')
                                    <x-lucide-box class="size-3.5 text-indigo-500" />
                                @elseif($prov['id'] === 'gemini')
                                    <x-lucide-sun class="size-3.5 text-blue-500" />
                                @elseif($prov['id'] === 'anthropic')
                                    <x-lucide-feather class="size-3.5 text-amber-500" />
                                @elseif($prov['id'] === 'openrouter')
                                    <x-lucide-layers class="size-3.5 text-purple-500" />
                                @else
                                    <x-lucide-server class="size-3.5 text-slate-500" />
                                @endif
                                <span>{{ $prov['name'] }}</span>
                            </span>
                            @if(!empty($prov['is_free']))
                                <x-ui.badge variant="success" class="text-[10px] font-mono">Free</x-ui.badge>
                            @else
                                <x-ui.badge variant="outline" class="text-[10px] font-mono">API Key</x-ui.badge>
                            @endif
                        </div>
                        <p class="text-[11px] text-muted-foreground leading-relaxed">{{ $prov['description'] }}</p>
                    </label>
                    @endforeach
                </div>

                <!-- Provider Specific Credentials -->
                <div class="space-y-4 pt-4 border-t border-border/60">
                    <h4 class="text-xs font-bold text-foreground uppercase tracking-wider">Provider Credentials & Parameters</h4>

                    <!-- OpenAI -->
                    <div x-show="selectedProvider === 'openai'" class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-muted/20 border border-border">
                        <div class="space-y-1.5">
                            <x-ui.label for="openai_api_key">OpenAI API Key</x-ui.label>
                            <x-ui.input id="openai_api_key" name="openai_api_key" type="password" value="" autocomplete="new-password" placeholder="{{ !empty($settings['openai_api_key_set']) ? 'Saved key hidden. Leave blank to keep it' : 'sk-...' }}" />
                        </div>
                        <div class="space-y-1.5">
                            <x-ui.label for="openai_model">Model Selection</x-ui.label>
                            <select id="openai_model" name="openai_model" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="gpt-4o-mini" {{ ($settings['openai_model'] ?? '') === 'gpt-4o-mini' ? 'selected' : '' }}>GPT-4o Mini (Fast &amp; Cost-Effective)</option>
                                <option value="gpt-4o" {{ ($settings['openai_model'] ?? '') === 'gpt-4o' ? 'selected' : '' }}>GPT-4o</option>
                                <option value="gpt-5.4-mini" {{ ($settings['openai_model'] ?? '') === 'gpt-5.4-mini' ? 'selected' : '' }}>GPT-5.4 Mini</option>
                            </select>
                        </div>
                    </div>

                    <!-- Google Gemini -->
                    <div x-show="selectedProvider === 'gemini'" class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-muted/20 border border-border">
                        <div class="space-y-1.5">
                            <x-ui.label for="gemini_api_key">Google AI Studio API Key</x-ui.label>
                            <x-ui.input id="gemini_api_key" name="gemini_api_key" type="password" value="" autocomplete="new-password" placeholder="{{ !empty($settings['gemini_api_key_set']) ? 'Saved key hidden. Leave blank to keep it' : 'AIzaSy...' }}" />
                        </div>
                        <div class="space-y-1.5">
                            <x-ui.label for="gemini_model">Model Selection</x-ui.label>
                            <select id="gemini_model" name="gemini_model" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="gemini-3.8-flash" {{ ($settings['gemini_model'] ?? '') === 'gemini-3.8-flash' ? 'selected' : '' }}>Gemini 3.8 Flash</option>
                                <option value="gemini-3.7-flash" {{ ($settings['gemini_model'] ?? '') === 'gemini-3.7-flash' ? 'selected' : '' }}>Gemini 3.7 Flash</option>
                                <option value="gemini-2.5-pro" {{ ($settings['gemini_model'] ?? '') === 'gemini-2.5-pro' ? 'selected' : '' }}>Gemini 2.5 Pro</option>
                            </select>
                        </div>
                    </div>

                    <!-- Anthropic -->
                    <div x-show="selectedProvider === 'anthropic'" class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-muted/20 border border-border">
                        <div class="space-y-1.5">
                            <x-ui.label for="anthropic_api_key">Anthropic API Key</x-ui.label>
                            <x-ui.input id="anthropic_api_key" name="anthropic_api_key" type="password" value="" autocomplete="new-password" placeholder="{{ !empty($settings['anthropic_api_key_set']) ? 'Saved key hidden. Leave blank to keep it' : 'sk-ant-...' }}" />
                        </div>
                        <div class="space-y-1.5">
                            <x-ui.label for="anthropic_model">Model Selection</x-ui.label>
                            <select id="anthropic_model" name="anthropic_model" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="claude-opus-5-5" {{ ($settings['anthropic_model'] ?? '') === 'claude-opus-5-5' ? 'selected' : '' }}>Claude Opus 5.5</option>
                                <option value="claude-sonnet-5-5" {{ ($settings['anthropic_model'] ?? '') === 'claude-sonnet-5-5' ? 'selected' : '' }}>Claude Sonnet 5.5 (Faster)</option>
                                <option value="claude-haiku-4-5" {{ ($settings['anthropic_model'] ?? '') === 'claude-haiku-4-5' ? 'selected' : '' }}>Claude Haiku 4.5 (Fastest)</option>
                            </select>
                        </div>
                    </div>

                    <!-- OpenRouter -->
                    <div x-show="selectedProvider === 'openrouter'" class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-muted/20 border border-border">
                        <div class="space-y-1.5">
                            <x-ui.label for="openrouter_api_key">OpenRouter API Key</x-ui.label>
                            <x-ui.input id="openrouter_api_key" name="openrouter_api_key" type="password" value="" autocomplete="new-password" placeholder="{{ !empty($settings['openrouter_api_key_set']) ? 'Saved key hidden. Leave blank to keep it' : 'sk-or-...' }}" />
                        </div>
                        <div class="space-y-1.5">
                            <x-ui.label for="openrouter_model">Model Selection</x-ui.label>
                            <x-ui.input id="openrouter_model" name="openrouter_model" value="{{ old('openrouter_model', $settings['openrouter_model']) }}" placeholder="meta-llama/llama-3.3-70b-instruct" />
                        </div>
                    </div>

                    <!-- Ollama -->
                    <div x-show="selectedProvider === 'ollama'" class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-muted/20 border border-border">
                        <div class="space-y-1.5">
                            <x-ui.label for="ollama_endpoint">Ollama Endpoint URL</x-ui.label>
                            <x-ui.input id="ollama_endpoint" name="ollama_endpoint" value="{{ old('ollama_endpoint', $settings['ollama_endpoint']) }}" placeholder="http://localhost:11434" />
                        </div>
                        <div class="space-y-1.5">
                            <x-ui.label for="ollama_model">Local Model Tag</x-ui.label>
                            <x-ui.input id="ollama_model" name="ollama_model" value="{{ old('ollama_model', $settings['ollama_model']) }}" placeholder="deepseek-r1:8b" />
                        </div>
                    </div>

                                        <!-- OpenCode Free & API Credentials -->
                    <div x-show="selectedProvider === 'opencode'" class="p-5 rounded-xl bg-emerald-500/5 border border-emerald-500/30 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <x-lucide-sparkles class="size-4 text-emerald-500" />
                                <span class="text-xs font-bold text-foreground">OpenCode Zen — 6 Free High-Speed Models</span>
                            </div>
                            <x-ui.badge variant="success" class="text-[10px] font-mono">Official OpenCode API</x-ui.badge>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <x-ui.label for="opencode_api_key">OpenCode API Key</x-ui.label>
                                <x-ui.input id="opencode_api_key" name="opencode_api_key" type="password" value="" autocomplete="new-password" placeholder="{{ !empty($settings['opencode_api_key_set']) ? 'Saved key hidden. Leave blank to keep it' : 'sk-...' }}" />
                                <p class="text-[10px] text-muted-foreground">Connected to official OpenCode Zen inference gateway.</p>
                            </div>
                            <div class="space-y-1.5">
                                <x-ui.label for="opencode_model">Free Model Selection (6 Models)</x-ui.label>
                                <select id="opencode_model" name="opencode_model" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                    <option value="space-bunny-free" {{ ($settings['opencode_model'] ?? 'space-bunny-free') === 'space-bunny-free' ? 'selected' : '' }}>space-bunny-free (Space Bunny - Fast Reasoning) ⭐ Recommended</option>
                                    <option value="mimo-v2.6-flash-free" {{ ($settings['opencode_model'] ?? '') === 'mimo-v2.6-flash-free' ? 'selected' : '' }}>mimo-v2.6-flash-free (Mimo 2.6 Flash)</option>
                                    <option value="mimo-v2.5-free" {{ ($settings['opencode_model'] ?? '') === 'mimo-v2.5-free' ? 'selected' : '' }}>mimo-v2.5-free (Mimo 2.5)</option>
                                    <option value="ling-3.1-flash-free" {{ ($settings['opencode_model'] ?? '') === 'ling-3.1-flash-free' ? 'selected' : '' }}>ling-3.1-flash-free (Ling 3.1 Flash)</option>
                                    <option value="nemotron-3.5-lightning-free" {{ ($settings['opencode_model'] ?? '') === 'nemotron-3.5-lightning-free' ? 'selected' : '' }}>nemotron-3.5-lightning-free (Nemotron 3.5)</option>
                                    <option value="muse-spark-1.3-contributor-free" {{ ($settings['opencode_model'] ?? '') === 'muse-spark-1.3-contributor-free' ? 'selected' : '' }}>muse-spark-1.3-contributor-free (Muse Spark 1.3)</option>
                                    <option value="fledge-alpha-free" {{ ($settings['opencode_model'] ?? '') === 'fledge-alpha-free' ? 'selected' : '' }}>fledge-alpha-free (Fledge Alpha)</option>
                                    <option value="longcat-2.5-preview-free" {{ ($settings['opencode_model'] ?? '') === 'longcat-2.5-preview-free' ? 'selected' : '' }}>longcat-2.5-preview-free (LongCat 2.5)</option>
                                </select>
                                <p class="text-[10px] text-muted-foreground">Select from OpenCode's free high-speed models.</p>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-emerald-500/20 flex items-center justify-between">
                            <span class="text-[11px] text-muted-foreground">✅ Test with prompt: <em>"What is 2+2?"</em> or ask framework questions.</span>
                            <x-ui.button type="button" @click="runTest('opencode')" variant="outline" size="sm" class="text-xs shrink-0">
                                <x-lucide-zap class="size-3.5 mr-1 text-emerald-500" />
                                <span>Test 2+2 Connection</span>
                            </x-ui.button>
                        </div>
                    </div>
                </div>

            <!-- TAB 2: BEHAVIOR & REASONING -->
            <div x-show="activeTab === 'behavior'" class="space-y-5">
                <div>
                    <h3 class="text-sm font-bold text-foreground">Copilot Reasoning & Framework Telemetry</h3>
                    <p class="text-xs text-muted-foreground">Control how the Copilot inspects MySQL database metrics, route context, and scaffolding questionnaires.</p>
                </div>

                <div class="space-y-4">
                    <label class="flex items-start gap-3 p-4 rounded-xl border border-border bg-card/60 cursor-pointer hover:bg-muted/30 transition">
                        <input type="checkbox" name="allow_telemetry" value="1" {{ $settings['allow_telemetry'] ? 'checked' : '' }} class="mt-0.5 rounded border-input" />
                        <div>
                            <span class="font-bold text-xs text-foreground block">Allow Live Database Telemetry</span>
                            <span class="text-[11px] text-muted-foreground">Allows the Copilot to answer queries like <em>"How many users do we have?"</em> by running safe count and schema inspections against the active database.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-4 rounded-xl border border-border bg-card/60 cursor-pointer hover:bg-muted/30 transition">
                        <input type="checkbox" name="clarifying_wizard" value="1" {{ $settings['clarifying_wizard'] ? 'checked' : '' }} class="mt-0.5 rounded border-input" />
                        <div>
                            <span class="font-bold text-xs text-foreground block">Interactive Clarifying Questions Wizard (EmDash Build Style)</span>
                            <span class="text-[11px] text-muted-foreground">When generating complex suites (e.g. ERP, CRM, E-Commerce), Copilot renders a 3-step questionnaire with multi-select checkboxes before scaffolding files.</span>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 p-4 rounded-xl border border-border bg-card/60 cursor-pointer hover:bg-muted/30 transition">
                        <input type="checkbox" name="floating_bubble" value="1" {{ $settings['floating_bubble'] ? 'checked' : '' }} class="mt-0.5 rounded border-input" />
                        <div>
                            <span class="font-bold text-xs text-foreground block">Display Floating Chat Bubble</span>
                            <span class="text-[11px] text-muted-foreground">Renders the interactive bottom-right floating bubble on all admin screens with real-time current page detection.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- TAB 3: MCP & IDE TOOLS -->
            <div x-show="activeTab === 'mcp'" class="space-y-5">
                <div>
                    <h3 class="text-sm font-bold text-foreground">Model Context Protocol (MCP) Server</h3>
                    <p class="text-xs text-muted-foreground">Connect Cursor, Antigravity, and Claude Code directly to your LaraSlice framework.</p>
                </div>

                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs font-mono text-emerald-300">
                    <div class="text-slate-400 mb-2">// In .cursor/mcp.json</div>
                    <pre><code>{
  "mcpServers": {
    "laraslice": {
      "command": "php",
      "args": ["artisan", "laraslice:mcp"]
    }
  }
}</code></pre>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="p-3 rounded-lg border border-border bg-card/40 text-xs">
                        <span class="font-bold text-foreground block">php artisan laraslice:mcp --test</span>
                        <span class="text-muted-foreground text-[11px]">Run MCP self-test and verify 13 registered tools.</span>
                    </div>
                    <div class="p-3 rounded-lg border border-border bg-card/40 text-xs">
                        <span class="font-bold text-foreground block">php artisan laraslice:skill:publish</span>
                        <span class="text-muted-foreground text-[11px]">Publish AI skill to .agents and .cursor directories.</span>
                    </div>
                </div>
            </div>

            <!-- Submit Button Bar -->
            <div class="flex items-center justify-between pt-5 border-t border-border">
                <x-ui.button type="button" @click="runTest(selectedProvider)" variant="outline">
                    <x-lucide-zap class="size-4 mr-1.5 text-amber-500" />
                    <span>Run Connection Test</span>
                </x-ui.button>

                <div class="flex items-center gap-3">
                    <x-ui.button href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" as="a" variant="outline">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="submit">
                        Save AI Settings
                    </x-ui.button>
                </div>
            </div>
        </form>
    </x-ui.card>
</div>
@endsection
