@props([
    'name',
    'label' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => 'Select an option...',
    'quickAddUrl' => null,
    'quickAddTitle' => null,
    'required' => false,
    'class' => '',
])

@php
    $resolvedTitle = $quickAddTitle ?? ($label ? rtrim($label, ' *') : 'Record');
    $cleanTitle = trim(str_replace('+', '', $resolvedTitle));
    $currentVal = (string) old($name, $selected ?? '');
    $optionsArray = is_array($options) ? $options : (is_iterable($options) ? iterator_to_array($options) : []);

    // Resolve target table schema dynamically for accurate Quick-Add fields
    $baseName = str_ends_with($name, '_id') ? substr($name, 0, -3) : $name;
    $candidateTables = [\Illuminate\Support\Str::plural($baseName), $baseName];
    $targetTbl = null;
    $tableColumns = [];
    foreach ($candidateTables as $ct) {
        if (\Illuminate\Support\Facades\Schema::hasTable($ct)) {
            $targetTbl = $ct;
            $tableColumns = \Illuminate\Support\Facades\Schema::getColumnListing($ct);
            break;
        }
    }

    $excludedCols = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by', 'remember_token'];
    $editableCols = array_values(array_filter($tableColumns, fn ($c) => ! in_array($c, $excludedCols, true)));

    // Primary label column
    $primaryCol = null;
    foreach (['title', 'name', 'company_name', 'label'] as $cand) {
        if (in_array($cand, $editableCols, true)) {
            $primaryCol = $cand;
            break;
        }
    }
    if (! $primaryCol && ! empty($editableCols)) {
        $primaryCol = $editableCols[0];
    }

    $descCol = in_array('description', $editableCols, true) ? 'description' : (in_array('notes', $editableCols, true) ? 'notes' : null);
    $secondaryCols = array_values(array_filter($editableCols, fn ($c) => $c !== $primaryCol && $c !== $descCol));
@endphp

<script>
if (!window.LaraSliceDrawer) {
    window.LaraSliceDrawer = {
        slugify: function(text) {
            return (text || '').toString().toLowerCase().trim()
                .replace(/\s+/g, '-')
                .replace(/[^\w\-]+/g, '')
                .replace(/\-\-+/g, '-');
        },
        async submit(url, payload, selectId, token, onSuccess, onError) {
            try {
                const primaryVal = payload.name || payload.title;
                if (!primaryVal || !primaryVal.trim()) {
                    onError('Primary name/title is required.');
                    return;
                }
                if (!payload.slug && primaryVal) {
                    payload.slug = this.slugify(primaryVal);
                }
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token
                    },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (!response.ok) {
                    let msg = data.message;
                    if (!msg && data.errors) {
                        const firstKey = Object.keys(data.errors)[0];
                        msg = data.errors[firstKey][0];
                    }
                    onError(msg || 'Validation failed.');
                    return;
                }
                const newId = String(data.id || (data.item ? data.item.id : ''));
                const newLabel = data.label || data.name || data.title || (data.item ? (data.item.name || data.item.title) : ('#' + newId));
                
                const sel = document.getElementById(selectId);
                if (sel) {
                    const opt = new Option(newLabel, newId, true, true);
                    sel.add(opt);
                    sel.value = newId;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (onSuccess) onSuccess(newLabel, newId);
            } catch (err) {
                onError('Network error: ' + (err.message || 'Could not save record.'));
            }
        }
    };
}
</script>

<div x-data="{
    drawerOpen: false,
    isSubmitting: false,
    errorMessage: '',
    fields: {
        @if ($primaryCol) '{{ $primaryCol }}': '', @endif
        @foreach ($secondaryCols as $sc)
            '{{ $sc }}': '{{ $sc === 'status' ? 'draft' : ($sc === 'amount' ? '0.00' : '') }}',
        @endforeach
        @if ($descCol) '{{ $descCol }}': '', @endif
    },

    openDrawer() {
        this.errorMessage = '';
        @if ($primaryCol) this.fields['{{ $primaryCol }}'] = ''; @endif
        @foreach ($secondaryCols as $sc)
            this.fields['{{ $sc }}'] = '{{ $sc === 'status' ? 'draft' : ($sc === 'amount' ? '0.00' : '') }}';
        @endforeach
        @if ($descCol) this.fields['{{ $descCol }}'] = ''; @endif
        this.drawerOpen = true;
    },

    closeDrawer() {
        this.drawerOpen = false;
        this.errorMessage = '';
    },

    submitDrawer() {
        const self = this;
        self.isSubmitting = true;
        self.errorMessage = '';

        const payload = Object.assign({}, self.fields);
        @if ($primaryCol)
            const pVal = (self.fields['{{ $primaryCol }}'] || '').trim();
            if (!pVal) {
                self.isSubmitting = false;
                self.errorMessage = '{{ \Illuminate\Support\Str::headline($primaryCol) }} is required.';
                return;
            }
            payload.name = pVal;
            payload.title = pVal;
            if (!payload.slug) {
                payload.slug = window.LaraSliceDrawer.slugify(pVal);
            }
        @endif

        window.LaraSliceDrawer.submit(
            '{{ $quickAddUrl }}',
            payload,
            '{{ $name }}',
            '{{ csrf_token() }}',
            function(newLabel, newId) {
                self.isSubmitting = false;
                self.closeDrawer();
            },
            function(err) {
                self.isSubmitting = false;
                self.errorMessage = err;
            }
        );
    }
}" class="space-y-1.5 {{ $class }}">

    <!-- Label & Quick-Add Action Trigger -->
    <div class="flex items-center justify-between">
        @if ($label)
            <label for="{{ $name }}" class="flex items-center gap-2 text-sm leading-none font-medium select-none">
                <span>{{ $label }}</span>
                @if ($required)
                    <span class="text-destructive">*</span>
                @endif
            </label>
        @else
            <div></div>
        @endif

        @if ($quickAddUrl)
            <button type="button"
                    @click="openDrawer()"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:text-primary/80 transition-colors cursor-pointer group">
                <svg class="w-3.5 h-3.5 transition-transform duration-150 group-hover:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>
                <span>New {{ $cleanTitle }}</span>
            </button>
        @endif
    </div>

    <!-- Clean Native Select Input -->
    <select id="{{ $name }}"
            name="{{ $name }}"
            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            {{ $required ? 'required' : '' }}>
        <option value="">{{ $placeholder }}</option>
        @foreach ($optionsArray as $optId => $optLabel)
            <option value="{{ $optId }}" @selected((string)$optId === (string)$currentVal)>{{ $optLabel }}</option>
        @endforeach
    </select>

    <!-- Slide-Over Drawer for Instant Quick-Add (z-[80] sits above everything) -->
    @if ($quickAddUrl)
        <div x-show="drawerOpen"
             x-cloak
             @keydown.window.escape="closeDrawer()"
             class="fixed inset-0 z-[80] overflow-hidden"
             style="display: none;">
            
            <!-- Backdrop -->
            <div x-show="drawerOpen"
                 x-transition:enter="ease-in-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in-out duration-300"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @click="closeDrawer()"
                 class="fixed inset-0 bg-neutral-950/60 backdrop-blur-xs transition-opacity"></div>

            <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
                <div x-show="drawerOpen"
                     x-transition:enter="transform transition ease-in-out duration-300"
                     x-transition:enter-start="translate-x-full"
                     x-transition:enter-end="translate-x-0"
                     x-transition:leave="transform transition ease-in-out duration-300"
                     x-transition:leave-start="translate-x-0"
                     x-transition:leave-end="translate-x-full"
                     class="w-screen max-w-md bg-card border-l border-border shadow-2xl flex flex-col justify-between"
                     style="display: none;">
                    
                    <!-- Drawer Header -->
                    <div class="px-6 py-5 border-b border-border/80 bg-muted/30 flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-foreground flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-primary inline-block"></span>
                                <span>Create New {{ $cleanTitle }}</span>
                            </h3>
                            <p class="text-xs text-muted-foreground mt-0.5">Create and automatically select in dropdown.</p>
                        </div>
                        <button type="button"
                                @click="closeDrawer()"
                                class="p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/80 transition cursor-pointer">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Drawer Form Body -->
                    <div class="px-6 py-5 overflow-y-auto space-y-4 flex-1">
                        <!-- Validation error display -->
                        <div x-show="errorMessage"
                             x-transition
                             class="p-3 rounded-lg border border-destructive/30 bg-destructive/10 text-destructive text-xs font-medium flex items-start gap-2"
                             style="display: none;">
                            <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span x-text="errorMessage"></span>
                        </div>

                        <!-- Primary Name / Title Field -->
                        @if ($primaryCol)
                        <div class="space-y-1.5">
                            <label class="text-xs font-semibold text-foreground flex items-center gap-1">
                                <span>{{ $cleanTitle }} {{ \Illuminate\Support\Str::headline($primaryCol) }}</span>
                                <span class="text-destructive">*</span>
                            </label>
                            <input type="text"
                                   x-model="fields['{{ $primaryCol }}']"
                                   placeholder="Enter {{ strtolower(\Illuminate\Support\Str::headline($primaryCol)) }}..."
                                   autofocus
                                   required
                                   class="w-full px-3 py-2 text-xs bg-background border border-input rounded-lg shadow-2xs focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary">
                        </div>
                        @endif

                        <!-- Dynamically Generated Entity Fields (Matching Target Table Schema) -->
                        @if (!empty($secondaryCols))
                        <div class="grid grid-cols-2 gap-3">
                            @foreach ($secondaryCols as $sc)
                                @php
                                    $colLabel = \Illuminate\Support\Str::headline($sc);
                                    if (str_contains($sc, 'amount') || str_contains($sc, 'price') || str_contains($sc, 'cost') || str_contains($sc, 'total')) {
                                        $inputType = 'number';
                                        $step = '0.01';
                                    } elseif (str_contains($sc, 'date')) {
                                        $inputType = 'date';
                                        $step = null;
                                    } elseif (str_contains($sc, 'email')) {
                                        $inputType = 'email';
                                        $step = null;
                                    } elseif (str_contains($sc, 'phone')) {
                                        $inputType = 'tel';
                                        $step = null;
                                    } elseif (str_contains($sc, 'website') || str_contains($sc, 'url')) {
                                        $inputType = 'url';
                                        $step = null;
                                    } else {
                                        $inputType = 'text';
                                        $step = null;
                                    }
                                @endphp
                                <div class="space-y-1.5 {{ in_array($inputType, ['url', 'email']) ? 'col-span-2' : '' }}">
                                    <label class="text-xs font-semibold text-foreground">
                                        {{ $colLabel }}
                                    </label>
                                    @if ($sc === 'status')
                                        <select x-model="fields['status']"
                                                class="w-full px-3 py-2 text-xs bg-background border border-input rounded-lg shadow-2xs focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary">
                                            <option value="draft">Draft</option>
                                            <option value="pending">Pending</option>
                                            <option value="active">Active</option>
                                            <option value="paid">Paid</option>
                                            <option value="completed">Completed</option>
                                        </select>
                                    @else
                                        <input type="{{ $inputType }}"
                                               x-model="fields['{{ $sc }}']"
                                               @if ($step) step="{{ $step }}" @endif
                                               placeholder="{{ $colLabel }}..."
                                               class="w-full px-3 py-2 text-xs bg-background border border-input rounded-lg shadow-2xs focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @endif

                        <!-- Description / Notes Field -->
                        @if ($descCol)
                        <div class="space-y-1.5">
                            <label class="text-xs font-semibold text-foreground">
                                {{ \Illuminate\Support\Str::headline($descCol) }}
                            </label>
                            <textarea x-model="fields['{{ $descCol }}']"
                                      rows="3"
                                      placeholder="Optional notes or details..."
                                      class="w-full px-3 py-2 text-xs bg-background border border-input rounded-lg shadow-2xs focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary"></textarea>
                        </div>
                        @endif
                    </div>

                    <!-- Drawer Footer (Clean, Unobstructed, Above Copilot) -->
                    <div class="px-6 py-4 border-t border-border/80 bg-muted/20 flex items-center justify-end gap-3 shrink-0">
                        <button type="button"
                                @click="closeDrawer()"
                                class="px-3.5 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground hover:bg-muted rounded-lg transition cursor-pointer">
                            Cancel
                        </button>
                        <button type="button"
                                @click="submitDrawer()"
                                :disabled="isSubmitting"
                                class="px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-xs font-bold rounded-lg shadow transition flex items-center gap-2 cursor-pointer disabled:opacity-50">
                            <svg x-show="isSubmitting" class="animate-spin w-3.5 h-3.5 text-primary-foreground" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            <span x-text="isSubmitting ? 'Saving...' : 'Create {{ $cleanTitle }}'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>