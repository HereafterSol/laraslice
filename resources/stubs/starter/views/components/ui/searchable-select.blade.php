@props([
    'name' => null,
    'options' => [],
    'value' => '',
    'placeholder' => 'Select an option...',
    'searchPlaceholder' => 'Search options...',
    'empty' => 'No matching results',
    'required' => false,
    'disabled' => false,
    'multiple' => false,
    'id' => null,
    'includeAll' => false,
    'allLabel' => 'All',
])

@php
    $opts = collect($options)->map(function ($label, $val) {
        if (is_array($label)) {
            return [
                'value' => (string) ($label['value'] ?? $label['id'] ?? $val),
                'label' => (string) ($label['label'] ?? $label['name'] ?? $label['title'] ?? $val),
            ];
        }
        if (is_object($label)) {
            return [
                'value' => (string) ($label->value ?? $label->id ?? $val),
                'label' => (string) ($label->label ?? $label->name ?? $label->title ?? $val),
            ];
        }
        return [
            'value' => (string) $val,
            'label' => (string) $label,
        ];
    })->values();

    if ($includeAll) {
        $opts->prepend([
            'value' => '',
            'label' => $allLabel,
        ]);
    }

    $formattedOptions = $opts->all();
@endphp

<div {{ $attributes->twMerge('w-full relative') }}>
    <x-ui.combobox
        :name="$name"
        :options="$formattedOptions"
        :value="$value"
        :placeholder="$placeholder"
        :search-placeholder="$searchPlaceholder"
        :empty="$empty"
        :disabled="$disabled"
        :multiple="$multiple"
        :id="$id"
        trigger="button"
        width="w-full"
    />
</div>