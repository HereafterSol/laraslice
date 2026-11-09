{{--
    Custom Fields tab panel (closure completion, 8 Nov 2026):
    the record's custom-field values as label/value rows — the
    source's custom-field-show component semantics: empty is
    '--', a select resolves its stored index to the option
    label, a date renders in the app date format, a file links
    to the stored upload. Data is prepared by
    BaseSliceWebController::appendCustomFieldsTab.
--}}
@php
    $cfFields = $rtab['fields'] ?? [];
    $cfValues = $rtab['values'] ?? [];
@endphp
<div class="rounded-xl border border-border overflow-hidden bg-card">
    @forelse($cfFields as $cfField)
        @php $cfRaw = $cfValues['field_'.$cfField['id']] ?? null; @endphp
        <div class="flex items-start gap-4 px-4 py-2.5 {{ !$loop->last ? 'border-b border-border/50' : '' }}">
            <span class="w-48 shrink-0 text-xs font-semibold text-muted-foreground">{{ $cfField['label'] }}</span>
            @if(($cfField['type'] ?? '') === 'file' && !empty($cfRaw))
            <a href="{{ asset('storage/custom_fields/'.$cfRaw) }}" class="text-xs text-primary hover:underline" download>Download file</a>
            @else
            <span class="text-xs text-foreground">{{ \LaraSlice\Support\CustomFields::displayValue($cfField, $cfRaw) }}</span>
            @endif
        </div>
    @empty
        <p class="px-4 py-6 text-xs text-muted-foreground">No custom fields defined for this module.</p>
    @endforelse
</div>
