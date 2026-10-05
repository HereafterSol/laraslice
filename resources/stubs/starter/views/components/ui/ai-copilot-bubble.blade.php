<div id="laraslice-copilot-container" class="fixed bottom-6 right-6 z-50 font-sans" x-data="larasliceCopilot()" x-cloak>
    <!-- Floating Trigger Bubble -->
    <div class="relative group" x-show="!isOpen">
        <!-- Pulse Glow -->
        <span class="absolute -inset-1 rounded-full bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 opacity-70 blur-md group-hover:opacity-100 animate-pulse transition duration-500"></span>
        
        <button 
            @click="toggleChat()" 
            type="button"
            class="relative flex items-center gap-2.5 px-4 py-3 rounded-full bg-slate-900 text-white shadow-2xl border border-indigo-500/40 hover:border-indigo-400 hover:scale-105 active:scale-95 transition-all duration-200">
            <div class="relative flex items-center justify-center w-7 h-7 rounded-full bg-gradient-to-tr from-indigo-500 to-pink-500 text-white font-bold text-xs shadow-inner">
                <svg class="w-4 h-4 animate-spin-slow" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-slate-900"></span>
            </div>
            <div class="text-left hidden sm:block">
                <div class="text-xs font-bold leading-tight tracking-wide flex items-center gap-1.5">
                    <span>AI Copilot</span>
                    <span class="text-[9px] px-1.5 py-0.2 rounded-full bg-indigo-500/30 text-indigo-300 border border-indigo-500/40 font-mono">OpenCode Free</span>
                </div>
                <div class="text-[10px] text-slate-400 leading-none">Ready &bull; Aware of Current Page</div>
            </div>
        </button>
    </div>

    <!-- Copilot Modal / Flyout Box -->
    <div 
        x-show="isOpen" 
        x-transition:enter="transition ease-out duration-300 transform"
        x-transition:enter-start="opacity-0 translate-y-8 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-200 transform"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-8 scale-95"
        class="flex flex-col w-[380px] sm:w-[470px] h-[640px] max-h-[88vh] bg-slate-900/95 backdrop-blur-xl border border-indigo-500/30 rounded-3xl shadow-2xl overflow-hidden">
        
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 bg-slate-950/80 border-b border-slate-800/80">
            <div class="flex items-center gap-2.5">
                <div class="relative flex items-center justify-center w-8 h-8 rounded-xl bg-gradient-to-tr from-indigo-600 to-purple-600 text-white font-bold text-xs shadow-md">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    <span class="absolute -bottom-0.5 -right-0.5 w-2 h-2 rounded-full bg-emerald-400 border border-slate-950"></span>
                </div>
                <div>
                    <div class="text-xs font-bold text-white flex items-center gap-1.5">
                        <span>LaraSlice AI Copilot</span>
                        <span class="text-[9px] font-mono px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">ONLINE</span>
                    </div>
                    <div class="text-[10px] text-slate-400 flex items-center gap-1">
                        <span class="truncate max-w-[210px]" x-text="'Route: ' + currentPath"></span>
                    </div>
                </div>
            </div>

            <!-- Header Actions -->
            <div class="flex items-center gap-1">
                <a 
                    href="{{ route('settings.ai') }}" 
                    title="AI Settings & Models" 
                    class="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </a>
                <button 
                    @click="clearHistory()" 
                    title="Clear Chat History" 
                    type="button" 
                    class="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
                <button 
                    @click="toggleChat()" 
                    type="button" 
                    class="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Chat Stream Body -->
        <div id="laraslice-copilot-messages" class="flex-1 p-4 overflow-y-auto space-y-3.5 text-xs text-slate-200">
            <!-- Page Intelligence Card (1-Paragraph Live Data Overview) -->
            <div x-show="pageOverview" class="p-3.5 rounded-2xl bg-indigo-950/50 border border-indigo-500/40 text-xs shadow-lg space-y-2 mb-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 font-bold text-indigo-200">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span x-text="pageOverview?.title || 'Page Intelligence'"></span>
                    </div>
                    <span class="text-[9px] font-mono px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 uppercase tracking-wider border border-indigo-500/30" x-text="pageOverview?.domain || 'Slice'"></span>
                </div>
                <p class="text-[11px] text-slate-300 leading-relaxed font-normal" x-text="pageOverview?.paragraph"></p>
                <div class="flex flex-wrap gap-1.5 pt-1.5 border-t border-indigo-500/20" x-show="pageOverview?.suggestions?.length">
                    <template x-for="sug in (pageOverview?.suggestions || [])" :key="sug">
                        <button 
                            type="button" 
                            @click="sendQuickPrompt(sug)" 
                            class="text-[10px] px-2.5 py-1 rounded-lg bg-slate-800/90 hover:bg-indigo-600 text-slate-200 hover:text-white border border-slate-700/80 transition active:scale-95 text-left flex items-center gap-1">
                            <span x-text="sug"></span>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Welcome Bot Message -->
            <div class="flex gap-2.5">
                <div class="w-6 h-6 rounded-lg bg-indigo-600/80 flex items-center justify-center shrink-0 text-white text-[10px] font-bold">
                    AI
                </div>
                <div class="bg-slate-800/90 border border-slate-700/60 rounded-2xl rounded-tl-sm p-3.5 text-slate-200 shadow-md max-w-[88%] leading-relaxed">
                    <p class="font-semibold text-white mb-1">Hello! I am your LaraSlice Copilot.</p>
                    <p class="text-slate-300 mb-2">Powered by <strong class="text-indigo-300">OpenCode Free Suite</strong>. I am fully aware of this page (<span class="font-mono text-[10px] text-pink-300" x-text="currentPath"></span>), live MySQL schemas, and relations.</p>
                    <div class="text-[11px] text-slate-400">Ask questions, query counts, or type <code class="text-indigo-300 font-mono bg-slate-900 px-1 py-0.5 rounded">/</code> for slash commands:</div>
                </div>
            </div>

            <!-- Page-Relevant Suggestions Chips (Dynamic & strictly page-aware) -->
            <div class="flex flex-wrap gap-1.5 pl-8" x-show="messages.length <= 1">
                <template x-for="sug in (pageOverview?.suggestions || ['📊 Live Count Telemetry', '🛠️ What tools are available?', '💡 /help'])" :key="sug">
                    <button 
                        type="button" 
                        @click="sendQuickPrompt(sug)" 
                        class="px-2.5 py-1.5 rounded-lg bg-indigo-950/60 hover:bg-indigo-900/80 text-indigo-200 border border-indigo-500/30 text-[11px] font-medium transition active:scale-95 text-left flex items-center gap-1.5">
                        <span x-text="sug"></span>
                    </button>
                </template>
            </div>

            <!-- Dynamic Messages -->
            <template x-for="(msg, index) in messages" :key="index">
                <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex gap-2.5'">
                    <!-- AI Avatar -->
                    <template x-if="msg.role === 'assistant'">
                        <div class="w-6 h-6 rounded-lg bg-indigo-600/80 flex items-center justify-center shrink-0 text-white text-[10px] font-bold">
                            AI
                        </div>
                    </template>

                    <!-- Bubble -->
                    <div 
                        :class="msg.role === 'user' 
                            ? 'bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-2xl rounded-tr-sm p-3 max-w-[85%] shadow-md' 
                            : 'bg-slate-800/90 border border-slate-700/60 text-slate-100 rounded-2xl rounded-tl-sm p-3.5 max-w-[92%] shadow-md leading-relaxed overflow-x-auto w-full'">
                        
                        <!-- Content Text / Markdown -->
                        <div class="prose prose-invert prose-xs" x-html="msg.htmlContent || formatMarkdown(msg.content)"></div>

                        <!-- Interactive Form & Dual Options (When msg.type === 'interactive_form') -->
                        <template x-if="msg.type === 'interactive_form'">
                            <div class="mt-3 pt-3 border-t border-slate-700/80 space-y-3">
                                
                                <!-- Dual Option Action Bar (When not yet inserted) -->
                                <div x-show="msg.status !== 'inserted'" class="flex flex-col sm:flex-row gap-2">
                                    <!-- Option A: Insert on My Behalf -->
                                    <button 
                                        type="button" 
                                        @click="insertOnBehalf(msg)" 
                                        :disabled="msg.status === 'submitting'"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-medium text-[11px] shadow-md hover:shadow-indigo-500/25 transition active:scale-95 disabled:opacity-50">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                        <span>⚡ Insert on My Behalf</span>
                                    </button>

                                    <!-- Option B: Toggle Interactive Form -->
                                    <button 
                                        type="button" 
                                        @click="msg.showForm = !msg.showForm" 
                                        :disabled="msg.status === 'submitting'"
                                        class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl bg-slate-700/80 hover:bg-slate-700 text-slate-100 hover:text-white font-medium text-[11px] border border-slate-600 transition active:scale-95 disabled:opacity-50">
                                        <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                        <span x-text="msg.showForm ? 'Hide Form' : '📝 Fill Form'"></span>
                                    </button>
                                </div>

                                <!-- Inline Interactive Form Container -->
                                <div x-show="msg.showForm && msg.status !== 'inserted'" class="p-3 rounded-xl bg-slate-900/90 border border-indigo-500/30 space-y-2.5">
                                    <div class="flex items-center justify-between border-b border-slate-800 pb-1.5">
                                        <span class="text-[11px] font-semibold text-indigo-300 flex items-center gap-1">
                                            <span>📝</span>
                                            <span>New <span x-text="msg.entity || 'Record'"></span> Specifications</span>
                                        </span>
                                        <span class="text-[9px] font-mono text-slate-400" x-text="msg.table"></span>
                                    </div>

                                    <!-- Dynamic Inputs with Relation Awareness -->
                                    <template x-for="field in (msg.fields || [])" :key="field">
                                        <div class="space-y-1">
                                            <div class="flex items-center justify-between">
                                                <label class="block text-[10px] font-mono uppercase tracking-wider text-slate-300" x-text="field.replace(/_/g, ' ')"></label>
                                                <span x-show="msg.relationsMeta && msg.relationsMeta[field]" class="text-[9px] text-pink-400 font-mono">Foreign Key</span>
                                            </div>
                                            
                                            <!-- Field Type: Relation Foreign Key Dropdown -->
                                            <template x-if="msg.relationsMeta && msg.relationsMeta[field]">
                                                <select 
                                                    x-model.number="msg.formData[field]" 
                                                    class="w-full bg-slate-950 border border-indigo-500/40 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-400">
                                                    <template x-for="opt in msg.relationsMeta[field].options" :key="opt.id">
                                                        <option :value="opt.id" x-text="opt.label"></option>
                                                    </template>
                                                </select>
                                            </template>

                                            <!-- Field Type: Status Dropdown -->
                                            <template x-if="!msg.relationsMeta?.[field] && field === 'status'">
                                                <select 
                                                    x-model="msg.formData[field]" 
                                                    class="w-full bg-slate-950 border border-slate-700/80 rounded-lg px-2 py-1 text-xs text-slate-200 focus:outline-none focus:border-indigo-500">
                                                    <option value="active">active</option>
                                                    <option value="inactive">inactive</option>
                                                    <option value="draft">draft</option>
                                                </select>
                                            </template>

                                            <!-- Field Type: Description Textarea -->
                                            <template x-if="!msg.relationsMeta?.[field] && field === 'description'">
                                                <textarea 
                                                    x-model="msg.formData[field]" 
                                                    rows="2" 
                                                    class="w-full bg-slate-950 border border-slate-700/80 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500" 
                                                    :placeholder="'Enter ' + field"></textarea>
                                            </template>

                                            <!-- Field Type: Numeric Inputs (Price, Quantity, Stock) -->
                                            <template x-if="!msg.relationsMeta?.[field] && (field === 'price' || field === 'stock_quantity' || field === 'quantity' || field === 'amount')">
                                                <input 
                                                    type="number" 
                                                    step="any"
                                                    x-model="msg.formData[field]" 
                                                    class="w-full bg-slate-950 border border-slate-700/80 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500" 
                                                    :placeholder="'Enter ' + field">
                                            </template>

                                            <!-- Field Type: Boolean Dropdown -->
                                            <template x-if="!msg.relationsMeta?.[field] && (field.startsWith('is_') || field.startsWith('has_'))">
                                                <select 
                                                    x-model.number="msg.formData[field]" 
                                                    class="w-full bg-slate-950 border border-slate-700/80 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                                                    <option :value="1">Yes (Active / 1)</option>
                                                    <option :value="0">No (Inactive / 0)</option>
                                                </select>
                                            </template>

                                            <!-- Field Type: Standard Text Input -->
                                            <template x-if="!msg.relationsMeta?.[field] && field !== 'status' && field !== 'description' && field !== 'price' && field !== 'stock_quantity' && field !== 'quantity' && field !== 'amount' && !field.startsWith('is_') && !field.startsWith('has_')">
                                                <input 
                                                    type="text" 
                                                    x-model="msg.formData[field]" 
                                                    class="w-full bg-slate-950 border border-slate-700/80 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500" 
                                                    :placeholder="'Enter ' + field">
                                            </template>
                                        </div>
                                    </template>

                                    <!-- Submit Button -->
                                    <div class="pt-1.5 flex justify-end gap-2">
                                        <button 
                                            type="button" 
                                            @click="submitFormRecord(msg)" 
                                            :disabled="msg.status === 'submitting'"
                                            class="w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-md transition active:scale-95 disabled:opacity-50">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <span>Save & Insert to MySQL</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Submitting Loader -->
                                <div x-show="msg.status === 'submitting'" class="p-3 rounded-xl bg-indigo-950/40 border border-indigo-500/40 flex items-center justify-center gap-2 text-indigo-300 text-xs">
                                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Writing record to MySQL database...</span>
                                </div>

                                <!-- Success Notification Card -->
                                <div x-show="msg.status === 'inserted'" class="p-3 rounded-xl bg-emerald-950/40 border border-emerald-500/40 space-y-2">
                                    <div class="flex items-center justify-between text-emerald-300 font-semibold text-xs">
                                        <div class="flex items-center gap-1.5">
                                            <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>Data Added Successfully!</span>
                                        </div>
                                        <span class="text-[9px] font-mono px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30" x-text="'ID #' + (msg.result?.id || '?')"></span>
                                    </div>
                                    <p class="text-[11px] text-slate-300" x-text="'Record was saved to ' + msg.table + '. Current table count is now ' + (msg.result?.total || '') + ' ' + (msg.entity || 'records') + '.'"></p>
                                    
                                    <!-- Summary Values -->
                                    <div class="p-2 rounded-lg bg-slate-950/80 border border-slate-800 text-[10px] space-y-1 font-mono">
                                        <template x-for="(val, key) in (msg.result?.summary || msg.formData || {})" :key="key">
                                            <div class="flex justify-between items-center text-slate-400" x-show="val && !key.includes('password')">
                                                <span class="text-slate-500" x-text="key + ':'"></span>
                                                <span class="text-slate-200 truncate max-w-[210px]" x-text="val"></span>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="flex items-center justify-between pt-1">
                                        <span class="text-[10px] text-emerald-400/90 font-medium">✓ Telemetry & audit trail logged</span>
                                        <button 
                                            type="button" 
                                            @click="window.location.reload()" 
                                            class="text-[10px] text-indigo-300 hover:text-white underline underline-offset-2">
                                            Refresh Page View ↻
                                        </button>
                                    </div>
                                </div>

                                <!-- Error State -->
                                <div x-show="msg.status === 'error'" class="p-2.5 rounded-xl bg-red-950/40 border border-red-500/40 text-red-300 text-xs space-y-1">
                                    <div class="font-semibold flex items-center gap-1">
                                        <span>⚠️</span>
                                        <span>Insertion Failed</span>
                                    </div>
                                    <p class="text-[11px] text-red-200" x-text="msg.errorMessage"></p>
                                </div>

                            </div>
                        </template>

                        <!-- Add Field Interactive Proposal Card -->
                        <template x-if="msg.type === 'add_field_proposal'">
                            <div class="mt-3 p-3.5 rounded-2xl bg-slate-900/90 border border-indigo-500/30 shadow-xl space-y-3 font-sans">
                                
                                <!-- Header -->
                                <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                                    <div class="flex items-center gap-2">
                                        <span class="p-1 rounded-lg bg-indigo-500/20 text-indigo-400">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                            </svg>
                                        </span>
                                        <div>
                                            <h5 class="text-xs font-bold text-white flex items-center gap-1.5">
                                                <span>New Field Migration</span>
                                                <span class="text-[10px] font-mono px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300" x-text="msg.table"></span>
                                            </h5>
                                            <p class="text-[10px] text-slate-400" x-text="'Slice: ' + msg.slice + ' • Non-breaking schema modification'"></p>
                                        </div>
                                    </div>
                                    <span class="text-[9px] font-mono uppercase px-2 py-0.5 rounded-md bg-purple-500/10 text-purple-300 border border-purple-500/20">
                                        LaraSlice Engine
                                    </span>
                                </div>

                                <!-- Field Configuration Form -->
                                <div x-show="msg.status === 'preview' || msg.status === 'editing' || msg.status === 'pending' || !msg.status" class="space-y-2.5 text-xs">
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-[10px] font-medium text-slate-400 mb-1">Field Handle (Column):</label>
                                            <input 
                                                type="text" 
                                                x-model="msg.field" 
                                                @input="msg.label = msg.field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())"
                                                class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-white font-mono text-xs focus:border-indigo-500 focus:outline-none"
                                                placeholder="e.g. emergency_contact"
                                            />
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-medium text-slate-400 mb-1">Data Type:</label>
                                            <select 
                                                x-model="msg.fieldType" 
                                                class="w-full px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-700 text-white font-mono text-xs focus:border-indigo-500 focus:outline-none">
                                                <option value="string">string (VARCHAR 255)</option>
                                                <option value="text">text (TEXT)</option>
                                                <option value="integer">integer (INT)</option>
                                                <option value="decimal">decimal (10,2)</option>
                                                <option value="boolean">boolean (TINYINT 1)</option>
                                                <option value="date">date (DATE)</option>
                                                <option value="timestamp">timestamp (DATETIME)</option>
                                                <option value="json">json (JSON)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between p-2 rounded-lg bg-slate-950/60 border border-slate-800">
                                        <div class="flex items-center gap-2">
                                            <input 
                                                type="checkbox" 
                                                :id="'nullable_' + idx" 
                                                x-model="msg.nullable" 
                                                class="rounded border-slate-700 text-indigo-600 focus:ring-0 bg-slate-900"
                                            />
                                            <label :for="'nullable_' + idx" class="text-[11px] text-slate-300 cursor-pointer">
                                                Nullable <span class="text-slate-500 font-mono">(allows existing rows without errors)</span>
                                            </label>
                                        </div>
                                        <span class="text-[10px] text-emerald-400 font-medium">Safe for Production</span>
                                    </div>

                                    <!-- Recommendations quick-select chips -->
                                    <template x-if="msg.recommendations && msg.recommendations.length > 0">
                                        <div class="pt-1">
                                            <div class="text-[10px] text-slate-400 mb-1.5 flex items-center justify-between">
                                                <span>💡 Enterprise Field Ideas for <code class="text-indigo-300" x-text="msg.table"></code>:</span>
                                                <span class="text-[9px] text-slate-500">Click to select</span>
                                            </div>
                                            <div class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto pr-1">
                                                <template x-for="rec in msg.recommendations" :key="rec.name">
                                                    <button 
                                                        type="button"
                                                        @click="msg.field = rec.name; msg.fieldType = rec.type; msg.label = rec.name.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())"
                                                        :class="msg.field === rec.name ? 'bg-indigo-600 text-white border-indigo-400' : 'bg-slate-800 text-slate-300 hover:bg-slate-700 border-slate-700'"
                                                        class="px-2 py-0.5 rounded text-[10px] font-mono border transition flex items-center gap-1">
                                                        <span x-text="'+ ' + rec.name"></span>
                                                        <span class="text-[8px] opacity-60" x-text="'(' + rec.type + ')'"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Existing Columns Preview Chips -->
                                    <template x-if="msg.currentCols && msg.currentCols.length > 0">
                                        <div class="pt-1 border-t border-slate-800">
                                            <div class="text-[9px] text-slate-500 mb-1">Current columns in <span class="font-mono text-slate-400" x-text="msg.table"></span>:</div>
                                            <div class="flex flex-wrap gap-1 max-h-16 overflow-y-auto">
                                                <template x-for="col in msg.currentCols" :key="col">
                                                    <span class="px-1.5 py-0.5 rounded bg-slate-950 text-slate-400 text-[9px] font-mono border border-slate-800" x-text="col"></span>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Action Button -->
                                    <div class="pt-2">
                                        <button 
                                            type="button" 
                                            @click="applyFieldMigration(msg)" 
                                            :disabled="!msg.field || msg.status === 'migrating'"
                                            class="w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-semibold text-xs shadow-lg transition active:scale-95 disabled:opacity-50">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                            </svg>
                                            <span>⚡ Apply Migration & Add Field Now</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Migrating Progress Loader -->
                                <div x-show="msg.status === 'migrating'" class="p-3.5 rounded-xl bg-indigo-950/40 border border-indigo-500/40 flex items-center justify-center gap-2 text-indigo-300 text-xs">
                                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span>Generating migration file & updating database schema...</span>
                                </div>

                                <!-- Success Notification Card -->
                                <div x-show="msg.status === 'migrated'" class="p-3.5 rounded-xl bg-emerald-950/40 border border-emerald-500/40 space-y-2">
                                    <div class="flex items-center justify-between text-emerald-300 font-semibold text-xs">
                                        <div class="flex items-center gap-1.5">
                                            <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>Field Added Successfully!</span>
                                        </div>
                                        <span class="text-[9px] font-mono px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30" x-text="'Column: ' + msg.field"></span>
                                    </div>
                                    <p class="text-[11px] text-slate-300" x-text="msg.migrationMessage || ('Column ' + msg.field + ' (' + msg.fieldType + ') was created in ' + msg.table + ' and migration executed.')"></p>
                                    
                                    <div class="flex items-center justify-between pt-1">
                                        <span class="text-[10px] text-emerald-400/90 font-medium">✨ Schema & Studio in sync</span>
                                        <button 
                                            type="button" 
                                            @click="window.location.reload()" 
                                            class="text-[10px] text-indigo-300 hover:text-white underline underline-offset-2">
                                            Refresh Studio View ⟳
                                        </button>
                                    </div>
                                </div>

                                <!-- Error State -->
                                <div x-show="msg.status === 'error'" class="p-2.5 rounded-xl bg-red-950/40 border border-red-500/40 text-red-300 text-xs space-y-1">
                                    <div class="font-semibold flex items-center gap-1">
                                        <span>⚠️</span>
                                        <span>Migration Failed</span>
                                    </div>
                                    <p class="text-[11px] text-red-200" x-text="msg.errorMessage"></p>
                                </div>

                            </div>
                        </template>

                    </div>
                </div>
            </template>

            <!-- Loading State -->
            <div class="flex gap-2.5 items-center" x-show="isLoading">
                <div class="w-6 h-6 rounded-lg bg-indigo-600/80 flex items-center justify-center shrink-0 text-white text-[10px] font-bold">
                    AI
                </div>
                <div class="bg-slate-800/90 border border-slate-700/60 rounded-2xl rounded-tl-sm px-4 py-3 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-indigo-400 animate-bounce"></span>
                    <span class="w-2 h-2 rounded-full bg-purple-400 animate-bounce [animation-delay:0.2s]"></span>
                    <span class="w-2 h-2 rounded-full bg-pink-400 animate-bounce [animation-delay:0.4s]"></span>
                    <span class="text-[11px] text-slate-400 ml-2">Reasoning with LaraSlice Engine...</span>
                </div>
            </div>
        </div>

        <!-- Floating Slash Command Palette -->
        <div 
            x-show="showSlashMenu" 
            x-transition
            @click.away="showSlashMenu = false"
            class="mx-3 mb-1 p-2 rounded-2xl bg-slate-950/95 border border-indigo-500/40 shadow-2xl backdrop-blur-md space-y-1 text-xs">
            <div class="px-2 py-1 text-[10px] font-mono uppercase tracking-wider text-indigo-300 font-semibold flex items-center justify-between border-b border-slate-800 pb-1">
                <span>⚡ LaraSlice Slash Commands</span>
                <span class="text-slate-500">ESC to close</span>
            </div>
            <div class="max-h-48 overflow-y-auto space-y-1 pt-1">
                <template x-for="cmd in filteredSlashCommands" :key="cmd.cmd">
                    <button 
                        type="button" 
                        @click="executeSlashCommand(cmd)"
                        class="w-full text-left px-2.5 py-1.5 rounded-xl hover:bg-indigo-600/30 text-slate-200 hover:text-white flex items-center justify-between group transition">
                        <div class="flex items-center gap-2">
                            <span class="text-sm" x-text="cmd.icon"></span>
                            <span class="font-mono font-semibold text-indigo-300 group-hover:text-indigo-200" x-text="cmd.label"></span>
                        </div>
                        <span class="text-[10px] text-slate-400" x-text="cmd.desc"></span>
                    </button>
                </template>
            </div>
        </div>

        <!-- Footer / Input Form -->
        <div class="p-3 bg-slate-950 border-t border-slate-800/80">
            <form @submit.prevent="sendMessage()" class="flex items-center gap-2">
                <input 
                    type="text" 
                    x-ref="chatInput"
                    x-model="inputQuery" 
                    @input="handleInput()"
                    @keydown.escape="showSlashMenu = false"
                    placeholder="Type a message or '/' for slash commands..." 
                    :disabled="isLoading"
                    class="flex-1 px-3.5 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:opacity-50 transition"
                />
                <button 
                    type="submit" 
                    :disabled="isLoading || !inputQuery.trim()" 
                    class="px-3.5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 disabled:opacity-40 text-white text-xs font-semibold flex items-center justify-center transition shadow-md active:scale-95">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                    </svg>
                </button>
            </form>
            <div class="flex items-center justify-between text-[10px] text-slate-500 mt-2 px-1">
                <span>Model: <strong class="text-slate-400">OpenCode AI Free</strong> (Zero Key Required)</span>
                <span class="font-mono text-indigo-400">LaraSlice v1.0</span>
            </div>
        </div>
    </div>
</div>

<script>
function larasliceCopilot() {
    return {
        isOpen: false,
        isLoading: false,
        inputQuery: '',
        showSlashMenu: false,
        currentPath: window.location.pathname + (window.location.hash || ''),
        pageOverview: null,
        messages: [],

        slashCommands: [
            { cmd: '/add ', label: '/add [name]', desc: 'Add record on current page', icon: '➕', needsParam: true },
            { cmd: '/count', label: '/count', desc: 'Live MySQL record count & telemetry', icon: '📊', needsParam: false },
            { cmd: '/schema', label: '/schema', desc: 'Inspect table schema & columns', icon: '📐', needsParam: false },
            { cmd: '/tools', label: '/tools', desc: 'List all available framework tools', icon: '🛠️', needsParam: false },
            { cmd: '/test', label: '/test', desc: 'Test real AI reasoning (2+2 = 4)', icon: '⚡', needsParam: false },
            { cmd: '/wipe', label: '/wipe', desc: 'Guide to wiping domain data safely', icon: '🗑️', needsParam: false },
            { cmd: '/help', label: '/help', desc: 'Show all available slash commands', icon: '💡', needsParam: false },
        ],

        get filteredSlashCommands() {
            let list = [];
            if (this.selectedSlice) {
                const s = this.selectedSlice;
                const rootT = s.tables_data?.[0]?.name || (s.name || '').toLowerCase();
                const childTables = Array.isArray(s.child_tables) ? s.child_tables : [];
                const allTables = [rootT, ...childTables.filter(t => t !== rootT)];

                // 1. Table schema & fields inspection commands
                allTables.forEach(t => {
                    list.push({
                        cmd: `/fields ${t}`,
                        label: `/fields ${t}`,
                        desc: `Inspect all columns, data types & keys for ${t}`,
                        icon: '📐',
                        needsParam: false
                    });
                });

                // 2. Add field to table commands
                allTables.forEach(t => {
                    list.push({
                        cmd: `/add-field ${t} `,
                        label: `/add-field ${t} [field]`,
                        desc: `Add new migration field to ${t}`,
                        icon: '➕',
                        needsParam: true
                    });
                });

                // 3. Slice architecture & relational commands
                list.push({
                    cmd: '/relations',
                    label: '/relations',
                    desc: `View aggregate relationships & foreign keys for ${s.title || s.name}`,
                    icon: '⚡',
                    needsParam: false
                });
                list.push({
                    cmd: '/permissions',
                    label: '/permissions',
                    desc: `View declared capabilities & auth gates for ${s.title || s.name}`,
                    icon: '🛡️',
                    needsParam: false
                });
                list.push({
                    cmd: '/nav',
                    label: '/nav',
                    desc: `View backend navigation menu, icon & route config`,
                    icon: '🧭',
                    needsParam: false
                });
                list.push({
                    cmd: '/logs',
                    label: '/logs',
                    desc: `View migration version history & security audit trail`,
                    icon: '📜',
                    needsParam: false
                });
                list.push({
                    cmd: `/suggest-fields ${rootT}`,
                    label: `/suggest-fields ${rootT}`,
                    desc: `Recommended enterprise fields for ${rootT}`,
                    icon: '💡',
                    needsParam: false
                });
                list.push({
                    cmd: `/seed ${s.name}`,
                    label: `/seed ${s.name}`,
                    desc: `Seed realistic test data for ${s.title || s.name}`,
                    icon: '🌱',
                    needsParam: false
                });
                list.push({
                    cmd: `/wipe ${s.name}`,
                    label: `/wipe ${s.name}`,
                    desc: `Truncate and purge records safely for ${s.title || s.name}`,
                    icon: '🗑️',
                    needsParam: false
                });
            }

            // Standard commands
            list.push(
                { cmd: '/add ', label: '/add [name]', desc: 'Add record on current page', icon: '➕', needsParam: true },
                { cmd: '/count', label: '/count', desc: 'Live MySQL record count & telemetry', icon: '📊', needsParam: false },
                { cmd: '/schema', label: '/schema', desc: 'Inspect current database schema', icon: '📐', needsParam: false },
                { cmd: '/tools', label: '/tools', desc: 'List all available framework tools', icon: '🛠️', needsParam: false },
                { cmd: '/help', label: '/help', desc: 'Show all available slash commands', icon: '❓', needsParam: false }
            );

            if (!this.inputQuery.startsWith('/')) return list;
            const q = this.inputQuery.toLowerCase().trim();
            if (q === '/') return list;
            return list.filter(c => c.cmd.toLowerCase().startsWith(q) || c.label.toLowerCase().includes(q) || c.desc.toLowerCase().includes(q));
        },

        handleInput() {
            if (this.inputQuery.startsWith('/') && !this.inputQuery.includes(' ')) {
                this.showSlashMenu = true;
            } else {
                this.showSlashMenu = false;
            }
        },

        executeSlashCommand(cmd) {
            this.showSlashMenu = false;
            if (cmd.needsParam) {
                this.inputQuery = cmd.cmd;
                this.$nextTick(() => {
                    if (this.$refs.chatInput) this.$refs.chatInput.focus();
                });
            } else {
                this.inputQuery = cmd.cmd;
                this.sendMessage();
            }
        },

        selectedSlice: null,

        applySliceContext(slice) {
            if (!slice) return;
            this.selectedSlice = slice;
            const sliceTitle = slice.title || slice.name;
            const rootTable = slice.tables_data?.[0]?.name || (slice.name || '').toLowerCase();
            const childTables = Array.isArray(slice.child_tables) ? slice.child_tables : [];
            const perms = (slice.permissions || []).map(p => typeof p === 'object' ? (p.slug || p.name) : p);

            const rootColCount = slice.tables_data?.[0]?.columns_count || slice.tables_data?.[0]?.columns?.length || '';
            const rootInfo = rootColCount ? `${rootTable} (${rootColCount} cols)` : rootTable;
            const childInfo = childTables.length > 0 ? childTables.join(', ') : 'None (Single-table)';

            this.pageOverview = {
                title: `Slice: ${sliceTitle} (${slice.version || 'v1.0.0'})`,
                domain: slice.domain || slice.name,
                paragraph: `Selected Slice **${sliceTitle}** [Domain: *${slice.domain || slice.name}*]. Primary root table: **\`${rootInfo}\`**. Child entities & tables (${childTables.length}): **\`${childInfo}\`**. Declared capabilities: **${perms.slice(0, 4).join(', ')}${perms.length > 4 ? '...' : ''}** (${perms.length} total). Navigation URL: \`${slice.navigation?.url || slice.navigation?.route || '/' + rootTable}\`.`,
                suggestions: [
                    `📋 Inspect ${slice.name} tables & schema`,
                    `➕ Add field to ${rootTable}`,
                    `🔐 Show ${slice.name} capabilities & gate`,
                    `⚡ Show ${slice.name} relations & child entities`
                ]
            };
        },

        init() {
            try {
                const saved = sessionStorage.getItem('laraslice_copilot_history');
                if (saved) {
                    this.messages = JSON.parse(saved);
                }
            } catch (e) {}

            window.addEventListener('hashchange', () => {
                this.currentPath = window.location.pathname + (window.location.hash || '');
                this.fetchPageOverview();
            });

            window.addEventListener('laraslice-slice-selected', (e) => {
                const slice = e.detail;
                if (!slice) return;
                this.applySliceContext(slice);
            });
        },

        toggleChat() {
            this.isOpen = !this.isOpen;
            if (this.isOpen) {
                this.currentPath = window.location.pathname + (window.location.hash || '');
                this.fetchPageOverview();
                this.scrollToBottom();
            }
        },

        clearHistory() {
            this.messages = [];
            sessionStorage.removeItem('laraslice_copilot_history');
        },

        async fetchPageOverview() {
            try {
                const res = await fetch('/laraslice/ai/page-overview?path=' + encodeURIComponent(this.currentPath), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data && data.overview) {
                        this.pageOverview = data.overview;
                    }
                }
            } catch (e) {}
        },

        sendQuickPrompt(promptText) {
            this.inputQuery = promptText;
            this.sendMessage();
        },

        async sendMessage() {
            const query = this.inputQuery.trim();
            if (!query || this.isLoading) return;

            this.showSlashMenu = false;

            // Push User Message
            this.messages.push({
                role: 'user',
                content: query,
                htmlContent: query.replace(/</g, "&lt;").replace(/>/g, "&gt;")
            });

            this.inputQuery = '';
            this.isLoading = true;
            this.scrollToBottom();

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const response = await fetch("{{ route('laraslice.ai.chat') }}", {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Current-Path': this.currentPath,
                    },
                    body: JSON.stringify({
                        message: query,
                        provider: 'opencode',
                        page_context: {
                            path: this.currentPath,
                            url: window.location.href,
                            title: document.title,
                            selected_slice: this.selectedSlice ? {
                                name: this.selectedSlice.name,
                                title: this.selectedSlice.title || this.selectedSlice.name,
                                domain: this.selectedSlice.domain,
                                version: this.selectedSlice.version,
                                root_table: this.selectedSlice.tables_data?.[0]?.name || (this.selectedSlice.name || '').toLowerCase(),
                                child_tables: this.selectedSlice.child_tables || [],
                                permissions: this.selectedSlice.permissions || [],
                                fields: Object.keys(this.selectedSlice.fields || {})
                            } : null
                        },
                        history: this.messages.slice(-6)
                    })
                });

                const data = await response.json();
                const replyText = data.reply || "I couldn't process that query.";

                this.messages.push({
                    role: 'assistant',
                    content: replyText,
                    type: data.type || 'text',
                    slice: data.slice || null,
                    table: data.table || null,
                    field: data.field || '',
                    label: data.label || '',
                    fieldType: data.fieldType || 'string',
                    nullable: data.nullable !== undefined ? data.nullable : true,
                    currentCols: data.currentCols || [],
                    recommendations: data.recommendations || [],
                    table: data.table || null,
                    entity: data.entity || null,
                    fields: data.fields || [],
                    sample: data.sample || {},
                    relationsMeta: data.relationsMeta || {},
                    formData: { ...(data.sample || {}) },
                    status: data.status || (data.type === 'add_field_proposal' ? 'preview' : 'pending'),
                    showForm: false,
                    result: null,
                    errorMessage: '',
                    htmlContent: this.formatMarkdown(replyText)
                });

                try {
                    sessionStorage.setItem('laraslice_copilot_history', JSON.stringify(this.messages.slice(-15)));
                } catch (e) {}

            } catch (err) {
                this.messages.push({
                    role: 'assistant',
                    content: "⚠️ Error contacting LaraSlice AI engine. Please verify the server connection.",
                    htmlContent: "<span class='text-red-400'>⚠️ Error contacting LaraSlice AI engine. Please verify the server connection.</span>"
                });
            } finally {
                this.isLoading = false;
                this.scrollToBottom();
            }
        },

                async applyFieldMigration(msg) {
            msg.status = 'migrating';
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') 
                           || document.querySelector('input[name="_token"]')?.value;

                const res = await fetch('/laraslice/wizard/add-field', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token || ''
                    },
                    body: JSON.stringify({
                        slice: msg.slice || this.selectedSlice?.name || 'Users',
                        field: msg.field,
                        type: msg.fieldType || 'string',
                        nullable: msg.nullable !== false,
                        migrate: true,
                        targetTable: msg.table
                    })
                });

                const data = await res.json();
                if (data.success) {
                    msg.status = 'migrated';
                    msg.migrationMessage = data.message || `Field '${msg.field}' added successfully!`;
                    window.dispatchEvent(new CustomEvent('laraslice-field-added', { detail: { table: msg.table, field: msg.field } }));
                } else {
                    msg.status = 'error';
                    msg.errorMessage = data.message || 'Failed to add field.';
                }
            } catch (err) {
                msg.status = 'error';
                msg.errorMessage = err.message || 'Network connection failed.';
            }
        },

        async insertOnBehalf(msg) {
            if (msg.status === 'submitting' || msg.status === 'inserted') return;
            msg.status = 'submitting';
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const response = await fetch("{{ route('laraslice.ai.record_create') }}", {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Current-Path': this.currentPath,
                    },
                    body: JSON.stringify({
                        table: msg.table,
                        data: msg.formData || msg.sample || {},
                        mode: 'auto'
                    })
                });
                const result = await response.json();
                if (result.success) {
                    msg.status = 'inserted';
                    msg.result = result;
                    this.fetchPageOverview();
                    try { sessionStorage.setItem('laraslice_copilot_history', JSON.stringify(this.messages.slice(-15))); } catch (e) {}
                } else {
                    msg.status = 'error';
                    msg.errorMessage = result.message || 'Failed to insert record.';
                }
            } catch (e) {
                msg.status = 'error';
                msg.errorMessage = e.message || 'Network error.';
            } finally {
                this.scrollToBottom();
            }
        },

        async submitFormRecord(msg) {
            if (msg.status === 'submitting' || msg.status === 'inserted') return;
            msg.status = 'submitting';
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const response = await fetch("{{ route('laraslice.ai.record_create') }}", {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Current-Path': this.currentPath,
                    },
                    body: JSON.stringify({
                        table: msg.table,
                        data: msg.formData || {},
                        mode: 'interactive_form'
                    })
                });
                const result = await response.json();
                if (result.success) {
                    msg.status = 'inserted';
                    msg.result = result;
                    this.fetchPageOverview();
                    try { sessionStorage.setItem('laraslice_copilot_history', JSON.stringify(this.messages.slice(-15))); } catch (e) {}
                } else {
                    msg.status = 'error';
                    msg.errorMessage = result.message || 'Failed to insert record.';
                }
            } catch (e) {
                msg.status = 'error';
                msg.errorMessage = e.message || 'Network error.';
            } finally {
                this.scrollToBottom();
            }
        },

        scrollToBottom() {
            setTimeout(() => {
                const container = document.getElementById('laraslice-copilot-messages');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            }, 60);
        },

        formatMarkdown(text) {
            if (!text) return '';
            let html = text;

            // Markdown table parser
            if (html.includes('|')) {
                const lines = html.split('\n');
                let inTable = false;
                let tableHtml = '<div class="overflow-x-auto my-2"><table class="w-full text-left border-collapse border border-slate-700 text-[11px]">';
                let newLines = [];

                for (let i = 0; i < lines.length; i++) {
                    const line = lines[i].trim();
                    if (line.startsWith('|') && line.endsWith('|')) {
                        if (line.includes(':---') || line.includes('---')) {
                            continue;
                        }
                        const cells = line.split('|').slice(1, -1);
                        if (!inTable) {
                            inTable = true;
                            tableHtml += '<thead><tr class="bg-slate-800/80 border-b border-slate-700">';
                            cells.forEach(c => tableHtml += `<th class="p-1.5 font-bold text-slate-200">${c.trim()}</th>`);
                            tableHtml += '</tr></thead><tbody>';
                        } else {
                            tableHtml += '<tr class="border-b border-slate-800/60 hover:bg-slate-800/40">';
                            cells.forEach(c => tableHtml += `<td class="p-1.5 text-slate-300">${c.trim()}</td>`);
                            tableHtml += '</tr>';
                        }
                    } else {
                        if (inTable) {
                            tableHtml += '</tbody></table></div>';
                            newLines.push(tableHtml);
                            tableHtml = '<div class="overflow-x-auto my-2"><table class="w-full text-left border-collapse border border-slate-700 text-[11px]">';
                            inTable = false;
                        }
                        newLines.push(line);
                    }
                }
                if (inTable) {
                    tableHtml += '</tbody></table></div>';
                    newLines.push(tableHtml);
                }
                html = newLines.join('\n');
            }

            // Headers
            html = html.replace(/^### (.*$)/gim, '<h4 class="text-xs font-bold text-indigo-300 mt-2 mb-1">$1</h4>');
            html = html.replace(/^## (.*$)/gim, '<h3 class="text-sm font-bold text-white mt-2.5 mb-1">$1</h3>');

            // Bold & Italic
            html = html.replace(/\*\*(.*?)\*\*/g, '<strong class="text-white font-semibold">$1</strong>');
            html = html.replace(/\*(.*?)\*/g, '<em class="text-slate-300">$1</em>');

            // Inline Code
            html = html.replace(/`([^`]+)`/g, '<code class="px-1.5 py-0.5 rounded bg-slate-950 border border-slate-700 text-pink-300 font-mono text-[10px]">$1</code>');

            // Fenced code blocks
            html = html.replace(/```([a-z]*)\n([\s\S]*?)```/g, '<pre class="my-2 p-2.5 rounded-lg bg-slate-950 border border-slate-800 text-[10px] font-mono text-emerald-300 overflow-x-auto"><code>$2</code></pre>');

            // Bullet Lists
            html = html.replace(/^\s*[-*•]\s+(.*$)/gim, '<li class="ml-3 text-slate-300 list-disc">$1</li>');

            // Newlines to <br> for remaining loose lines
            html = html.replace(/\n\n/g, '<div class="h-2"></div>');

            return html;
        }
    }
}
</script>
