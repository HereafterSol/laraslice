{{--
    Custom fields form section (closure completion, 8 Nov 2026):
    renders the fields bound to the slice's model by its
    custom_field_groups declaration, as the source's
    custom-field form component does — one input per field
    named custom_fields_data[{name}_{id}], required fields
    marked, stored values prefilled on edit. Renders nothing
    when the model carries no fields. Written by
    BaseSliceWebController (customFieldViewData) and persisted
    by LaraSlice\Support\CustomFields.
--}}
@if(!empty($customFields ?? []))
        <x-ui.card class="bg-card border border-border shadow-xs overflow-hidden" x-data="{ lsSectionOpen: true }">
            <x-ui.card-header class="border-b border-border/60 bg-muted/20 px-6 py-4 relative">
                <div class="flex items-center gap-2">
                    <x-lucide-layers class="size-4 text-primary" />
                    <x-ui.card-title class="text-base font-bold text-foreground">Custom Fields</x-ui.card-title>
                </div>
                <x-ui.card-description class="text-xs">Additional fields defined for this module</x-ui.card-description>
                <button type="button" @click="lsSectionOpen = !lsSectionOpen" class="absolute right-4 top-4 inline-flex items-center justify-center size-7 rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground transition-colors cursor-pointer" :class="lsSectionOpen ? '' : '-rotate-90'" aria-label="Toggle section">
                    <x-lucide-chevron-down class="size-4 transition-transform" />
                </button>
            </x-ui.card-header>
            <x-ui.card-content class="p-6" x-show="lsSectionOpen">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5" data-ls-section-grid>
                @foreach($customFields as $cfField)
                    @php
                        $cfKey = \LaraSlice\Support\CustomFields::inputKey($cfField);
                        $cfInput = 'custom_fields_data['.$cfKey.']';
                        $cfStored = $customFieldValues['field_'.$cfField['id']] ?? null;
                        $cfValue = old('custom_fields_data.'.$cfKey, $cfStored);
                        $cfRequired = (($cfField['required'] ?? 'no') === 'yes');
                        $cfOptions = $cfField['options_list'] ?? [];
                    @endphp
                    @if($cfField['type'] === 'textarea')
                    <div class="space-y-1.5 md:col-span-2">
                        <x-ui.label for="{{ $cfInput }}">{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        <x-ui.textarea id="{{ $cfInput }}" name="{{ $cfInput }}" rows="3">{{ $cfValue ?? '' }}</x-ui.textarea>
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @elseif($cfField['type'] === 'select')
                    <div class="space-y-1.5">
                        <x-ui.label for="{{ $cfInput }}">{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        <select id="{{ $cfInput }}" name="{{ $cfInput }}" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring" @if($cfRequired) required @endif>
                            <option value="">Select {{ $cfField['label'] }}</option>
                            @foreach($cfOptions as $cfIndex => $cfOption)
                            <option value="{{ $cfIndex }}" @selected((string) $cfValue === (string) $cfIndex)>{{ $cfOption }}</option>
                            @endforeach
                        </select>
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @elseif($cfField['type'] === 'radio')
                    <div class="space-y-1.5">
                        <x-ui.label>{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        <div class="flex flex-wrap items-center gap-4 pt-1">
                            @foreach($cfOptions as $cfIndex => $cfOption)
                            <label class="inline-flex items-center gap-1.5 text-sm">
                                <input type="radio" name="{{ $cfInput }}" value="{{ $cfOption }}" @checked((string) $cfValue === (string) $cfOption) class="h-4 w-4 border-gray-300 text-primary" />
                                <span>{{ $cfOption }}</span>
                            </label>
                            @endforeach
                        </div>
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @elseif($cfField['type'] === 'checkbox')
                    <div class="space-y-1.5">
                        <x-ui.label>{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        @php $cfChosen = $cfValue !== null && $cfValue !== '' ? explode(', ', (string) $cfValue) : []; @endphp
                        <div class="flex flex-wrap items-center gap-4 pt-1">
                            @foreach($cfOptions as $cfIndex => $cfOption)
                            <label class="inline-flex items-center gap-1.5 text-sm">
                                <input type="checkbox" name="{{ $cfInput }}[]" value="{{ $cfOption }}" @checked(in_array($cfOption, $cfChosen, true)) class="h-4 w-4 rounded border-gray-300 text-primary" />
                                <span>{{ $cfOption }}</span>
                            </label>
                            @endforeach
                        </div>
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @elseif($cfField['type'] === 'file')
                    <div class="space-y-1.5">
                        <x-ui.label for="{{ $cfInput }}">{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        <input type="hidden" name="{{ $cfInput }}" value="{{ $cfStored ?? '' }}" />
                        <input id="{{ $cfInput }}" type="file" name="{{ $cfInput }}" class="flex w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        @if(!empty($cfStored))
                        <p class="text-xs text-muted-foreground">Current file: {{ $cfStored }}</p>
                        @endif
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @elseif($cfField['type'] === 'date')
                    <div class="space-y-1.5">
                        <x-ui.label for="{{ $cfInput }}">{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        <x-ui.input id="{{ $cfInput }}" type="date" name="{{ $cfInput }}" value="{{ $cfValue ?? '' }}" :required="$cfRequired" />
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @elseif($cfField['type'] === 'number')
                    <div class="space-y-1.5">
                        <x-ui.label for="{{ $cfInput }}">{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        <x-ui.input id="{{ $cfInput }}" type="number" name="{{ $cfInput }}" value="{{ $cfValue ?? '' }}" :required="$cfRequired" />
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @elseif($cfField['type'] === 'password')
                    <div class="space-y-1.5">
                        <x-ui.label for="{{ $cfInput }}">{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        <x-ui.input id="{{ $cfInput }}" type="password" name="{{ $cfInput }}" value="{{ $cfValue ?? '' }}" :required="$cfRequired" />
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @else
                    <div class="space-y-1.5">
                        <x-ui.label for="{{ $cfInput }}">{{ $cfField['label'] }}{{ $cfRequired ? ' *' : '' }}</x-ui.label>
                        <x-ui.input id="{{ $cfInput }}" type="text" name="{{ $cfInput }}" value="{{ $cfValue ?? '' }}" :required="$cfRequired" />
                        @error('custom_fields_data.'.$cfKey) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                    @endif
                @endforeach
                </div>
            </x-ui.card-content>
        </x-ui.card>
@endif
