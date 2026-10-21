@props([
    'name',
    'label' => 'Items',
    'fields' => [], // [['name' => 'title', 'label' => 'Title', 'type' => 'string', 'required' => false, 'options' => []], ...]
    'initialItems' => [[]],
    'minItems' => 0,
    'maxItems' => 50,
    'addButtonText' => 'Add Item',
    'class' => '',
])

<div x-data="{
    items: {{ json_encode(!empty($initialItems) ? $initialItems : [[]]) }},
    addItem() {
        if (this.items.length < {{ $maxItems }}) {
            this.items.push({});
        }
    },
    removeItem(idx) {
        if (this.items.length > {{ $minItems }}) {
            this.items.splice(idx, 1);
        }
    }
}" class="w-full space-y-3 {{ $class }}">
    <div class="flex items-center justify-between">
        <label class="text-xs font-semibold text-foreground tracking-tight flex items-center gap-2">
            <span>{{ $label }}</span>
            <span class="text-[10px] font-mono text-muted-foreground" x-text="'(' + items.length + ')'"></span>
        </label>
        <button type="button"
                @click="addItem()"
                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-primary bg-primary/10 hover:bg-primary/20 rounded-lg transition shadow-2xs cursor-pointer">
            <span>+</span>
            <span>{{ $addButtonText }}</span>
        </button>
    </div>

    {{-- Marker: tells the server this collection was submitted even when every row was removed --}}
    <input type="hidden" name="_repeaters[]" value="{{ $name }}">

    <!-- Items List -->
    <div class="space-y-2">
        <template x-for="(item, index) in items" :key="index">
            <div class="p-3 bg-card border border-border/60 rounded-xl shadow-xs flex items-start gap-3 transition hover:border-border">
                <span class="text-[10px] font-mono text-muted-foreground/60 pt-2" x-text="'#' + (index + 1)"></span>
                <input type="hidden" :name="'{{ $name }}[' + index + '][id]'" x-model="item.id">

                <!-- Fields Grid -->
                <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                    @foreach ($fields as $f)
                        @php $fType = $f['type'] ?? 'string'; @endphp
                        <div class="space-y-1">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">{{ $f['label'] ?? ucfirst($f['name']) }}@if (!empty($f['required'])) <span class="text-destructive">*</span>@endif</label>
                            @if (in_array($fType, ['select', 'enum'], true))
                                <select :name="'{{ $name }}[' + index + '][{{ $f['name'] }}]'"
                                        x-model="item.{{ $f['name'] }}"
                                        class="w-full px-2.5 py-1.5 text-xs bg-background border border-input rounded-lg focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                                    <option value="">—</option>
                                    @foreach (($f['options'] ?? []) as $optValue => $optLabel)
                                        <option value="{{ is_int($optValue) ? $optLabel : $optValue }}">{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif ($fType === 'text')
                                <textarea :name="'{{ $name }}[' + index + '][{{ $f['name'] }}]'"
                                          x-model="item.{{ $f['name'] }}"
                                          rows="2"
                                          placeholder="{{ $f['placeholder'] ?? '' }}"
                                          class="w-full px-2.5 py-1.5 text-xs bg-background border border-input rounded-lg focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs"></textarea>
                            @elseif ($fType === 'boolean')
                                <input type="hidden" :name="'{{ $name }}[' + index + '][{{ $f['name'] }}]'" value="0">
                                <input type="checkbox"
                                       :name="'{{ $name }}[' + index + '][{{ $f['name'] }}]'"
                                       value="1"
                                       x-model="item.{{ $f['name'] }}"
                                       class="mt-1.5 h-4 w-4 rounded border-input text-primary focus:ring-primary">
                            @else
                                <input type="{{ match ($fType) { 'integer', 'bigInteger', 'foreign_id', 'decimal', 'float' => 'number', 'date' => 'date', 'datetime', 'timestamp' => 'datetime-local', 'email' => 'email', default => 'text' } }}"
                                       @if (in_array($fType, ['decimal', 'float'], true)) step="any" @elseif (in_array($fType, ['integer', 'bigInteger', 'foreign_id'], true)) step="1" @endif
                                       :name="'{{ $name }}[' + index + '][{{ $f['name'] }}]'"
                                       x-model="item.{{ $f['name'] }}"
                                       placeholder="{{ $f['placeholder'] ?? '' }}"
                                       class="w-full px-2.5 py-1.5 text-xs bg-background border border-input rounded-lg focus:outline-none focus:ring-1 focus:ring-primary shadow-2xs">
                            @endif
                        </div>
                    @endforeach
                </div>

                <!-- Remove Button -->
                <button type="button"
                        @click="removeItem(index)"
                        title="Remove row"
                        class="p-1.5 text-muted-foreground hover:text-destructive hover:bg-destructive/10 rounded-lg transition mt-5 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
            </div>
        </template>

        <div x-show="items.length === 0" class="p-6 text-center border-2 border-dashed border-border/50 rounded-xl text-xs text-muted-foreground">
            No items added yet. Click "{{ $addButtonText }}" above to add the first row.
        </div>
    </div>
</div>
