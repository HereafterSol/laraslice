<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Custom fields runtime (closure programme completion, 8 Nov 2026).
 *
 * The source system lets an admin define extra fields per module
 * (Settings → Custom Fields): a `custom_field_groups` row binds a
 * group of `custom_fields` to a model, module create/edit forms
 * render the group's fields as `custom_fields_data[field_{id}]`
 * inputs, values persist per record in `custom_fields_data`
 * (model class + model_id + custom_field_id), and record detail
 * pages render them as label/value rows. The carried tables are
 * the declaration — this class is the framework's single
 * read/write path for them, mirroring the source's
 * CustomFieldsTrait / CustomFieldsRequestTrait semantics:
 *
 *  - field types: text, number, password, textarea, select,
 *    radio, date, checkbox, file;
 *  - select stores the INDEX into the field's values list (the
 *    source's form select is keyed by index and its show view
 *    resolves the label back through the list); radio stores the
 *    literal value; checkbox stores the chosen values joined
 *    with ', ';
 *  - a required field ('yes') validates as required on save;
 *  - a file value is an uploaded file stored under
 *    `custom_fields/`; replacing it deletes the old file;
 *  - dates are stored as Y-m-d.
 *
 * Group binding: the group's `model` column carries the model
 * class name as the SOURCE app knew it (App\Models\Project).
 * Ported apps name their models differently
 * (App\Slices\...\Models\Project), so binding resolves by model
 * BASENAME — the module identity both apps agree on — with a
 * company preference when several groups share a basename.
 *
 * Every read is guarded: an app without the tables (fresh
 * install, test app) simply has no custom fields, never an
 * error.
 */
class CustomFields
{
    /** @var array<int, string> */
    public const TYPES = ['text', 'number', 'password', 'textarea', 'select', 'radio', 'date', 'checkbox', 'file'];

    /** The source's upload whitelist for custom-field files. */
    public const FILE_MIMES = 'pdf,doc,docx,jpg,jpeg,png,webp,xls,xlsx,zip,rar,txt,svg,ppt,pptx,mp4,mp3,avi,flv,wmv,3gp,webm,psd,ai,eps,indd,ttf,otf,woff,woff2,7z';

    public static function tablesExist(): bool
    {
        try {
            return Schema::hasTable('custom_field_groups')
                && Schema::hasTable('custom_fields')
                && Schema::hasTable('custom_fields_data');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The group bound to a model class, or null. The returned
     * array is the group row as an array.
     */
    public static function groupForModel(string $modelClass): ?array
    {
        if (! self::tablesExist()) {
            return null;
        }
        $base = class_basename($modelClass);
        try {
            $groups = DB::table('custom_field_groups')->orderBy('id')->get();
        } catch (\Throwable) {
            return null;
        }
        $companyId = null;
        try {
            $companyId = SettingsUi::companyRow()->id ?? null;
        } catch (\Throwable) {
            $companyId = null;
        }
        $fallback = null;
        foreach ($groups as $group) {
            $model = (string) ($group->model ?? '');
            if ($model === $modelClass || class_basename($model) === $base) {
                if ($companyId !== null && isset($group->company_id) && (int) $group->company_id === (int) $companyId) {
                    return (array) $group;
                }
                $fallback ??= (array) $group;
            }
        }

        return $fallback;
    }

    /**
     * The bound model's field definitions, in declaration
     * order, each with its decoded option list under
     * `options_list` (for select / radio / checkbox).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fieldsForModel(string $modelClass): array
    {
        $group = self::groupForModel($modelClass);
        if ($group === null) {
            return [];
        }
        try {
            $rows = DB::table('custom_fields')
                ->where('custom_field_group_id', $group['id'])
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }
        $fields = [];
        foreach ($rows as $row) {
            $field = (array) $row;
            $field['options_list'] = self::decodeValues($field['values'] ?? null);
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Decode a field's stored `values` (a JSON list for
     * select/radio/checkbox; a plain string otherwise) into a
     * plain list of option strings.
     *
     * @return array<int, string>
     */
    public static function decodeValues(mixed $values): array
    {
        if (is_string($values) && trim($values) !== '') {
            $decoded = json_decode($values, true);
            if (is_array($decoded)) {
                return array_values(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $decoded));
            }

            return [$values];
        }
        if (is_array($values)) {
            return array_values(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $values));
        }

        return [];
    }

    /** The request/storage key a field submits under: {name}_{id}. */
    public static function inputKey(array $field): string
    {
        return (string) $field['name'].'_'.(int) $field['id'];
    }

    /**
     * Stored values for one record, keyed `field_{id}` — the
     * source's getCustomFieldsData() shape.
     *
     * @return array<string, ?string>
     */
    public static function valuesFor(string $modelClass, int $modelId): array
    {
        $fields = self::fieldsForModel($modelClass);
        if ($fields === []) {
            return [];
        }
        $ids = array_map(static fn ($f) => (int) $f['id'], $fields);
        try {
            $rows = DB::table('custom_fields_data')
                ->where('model', $modelClass)
                ->where('model_id', $modelId)
                ->whereIn('custom_field_id', $ids)
                ->get();
        } catch (\Throwable) {
            return [];
        }
        $values = [];
        foreach ($rows as $row) {
            $values['field_'.(int) $row->custom_field_id] = $row->value !== null ? (string) $row->value : null;
        }

        return $values;
    }

    /**
     * Validation rules for a save, keyed the way the request
     * nests them (`custom_fields_data.{name}_{id}`) — the
     * source's CustomFieldsRequestTrait: a required field is
     * required; a required (or present) file must be an
     * allowed upload.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    public static function rules(array $fields): array
    {
        $rules = [];
        foreach ($fields as $field) {
            $key = 'custom_fields_data.'.self::inputKey($field);
            if (($field['required'] ?? 'no') === 'yes') {
                $rules[$key] = ($field['type'] ?? '') === 'file'
                    ? 'required|file|mimes:'.self::FILE_MIMES
                    : 'required';
            } elseif (($field['type'] ?? '') === 'file') {
                $rules[$key] = 'nullable|file|mimes:'.self::FILE_MIMES;
            }
        }

        return $rules;
    }

    /** @param  array<int, array<string, mixed>>  $fields */
    public static function ruleAttributes(array $fields): array
    {
        $attributes = [];
        foreach ($fields as $field) {
            if (($field['required'] ?? 'no') === 'yes') {
                $attributes['custom_fields_data.'.self::inputKey($field)] = (string) $field['label'];
            }
        }

        return $attributes;
    }

    /**
     * Persist submitted values for a saved record — the
     * source's updateCustomFieldData(): one row per field,
     * updated in place when present, inserted otherwise.
     * Fields absent from the submission are left untouched
     * (a partial update must not wipe values), except that a
     * rendered form submits every field, so absence means the
     * caller did not render custom fields at all.
     *
     * @param  array<string, mixed>  $input   the request's custom_fields_data values
     * @param  array<string, mixed>  $files   the request's custom_fields_data uploads
     */
    public static function persist(string $modelClass, int $modelId, array $input, array $files = []): void
    {
        $fields = self::fieldsForModel($modelClass);
        if ($fields === []) {
            return;
        }
        foreach ($fields as $field) {
            $key = self::inputKey($field);
            $type = (string) ($field['type'] ?? 'text');
            $hasInput = array_key_exists($key, $input);
            $hasFile = isset($files[$key]) && is_object($files[$key]) && method_exists($files[$key], 'isValid') && $files[$key]->isValid();
            if (! $hasInput && ! $hasFile) {
                continue;
            }
            $existing = null;
            try {
                $existing = DB::table('custom_fields_data')
                    ->where('model', $modelClass)
                    ->where('model_id', $modelId)
                    ->where('custom_field_id', (int) $field['id'])
                    ->first();
            } catch (\Throwable) {
                $existing = null;
            }
            if ($type === 'file') {
                if ($hasFile) {
                    $stored = $files[$key]->store('custom_fields');
                    $value = is_string($stored) ? basename($stored) : '';
                    if ($existing !== null && is_string($existing->value) && $existing->value !== '' && $existing->value !== $value) {
                        try {
                            Storage::delete('custom_fields/'.$existing->value);
                        } catch (\Throwable) {
                            // A missing old file must not fail the save.
                        }
                    }
                } else {
                    // The hidden input carries the current filename.
                    $value = is_scalar($input[$key] ?? null) ? (string) $input[$key] : '';
                }
            } elseif ($type === 'checkbox') {
                $chosen = $input[$key] ?? [];
                $value = is_array($chosen)
                    ? implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $chosen))
                    : (is_scalar($chosen) ? (string) $chosen : '');
            } else {
                $raw = $input[$key] ?? null;
                $value = is_scalar($raw) ? (string) $raw : '';
            }
            try {
                if ($existing !== null) {
                    DB::table('custom_fields_data')->where('id', $existing->id)->update(['value' => $value]);
                } else {
                    DB::table('custom_fields_data')->insert([
                        'custom_field_id' => (int) $field['id'],
                        'model_id' => $modelId,
                        'model' => $modelClass,
                        'value' => $value,
                    ]);
                }
            } catch (\Throwable) {
                // Persistence must not break the record's own save
                // path after the fact; the row simply stays absent.
            }
        }
    }

    /**
     * Render a stored value the way the source's show component
     * does: empty is '--', a select resolves its stored index
     * back to the option label, a date renders in the app's
     * date format.
     */
    public static function displayValue(array $field, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '--';
        }
        $raw = (string) $raw;
        switch ((string) ($field['type'] ?? 'text')) {
            case 'select':
                $options = $field['options_list'] ?? self::decodeValues($field['values'] ?? null);

                return $options[(int) $raw] ?? $raw;
            case 'date':
                try {
                    return \Illuminate\Support\Carbon::parse($raw)->format(self::appDateFormat());
                } catch (\Throwable) {
                    return $raw;
                }
            default:
                return $raw;
        }
    }

    /** The app date format the source formats dates with (company()->date_format). */
    public static function appDateFormat(): string
    {
        try {
            $format = SettingsUi::boundRow('global_settings')->date_format ?? null;
            if (is_string($format) && $format !== '') {
                // The source stores PHP-style formats (d-m-Y);
                // values already in that shape pass through.
                return $format;
            }
        } catch (\Throwable) {
            // fall through to the default
        }

        return 'Y-m-d';
    }

    /**
     * The source's slug rule for a field name: slug of the
     * label, made unique inside the group with -1, -2, …
     * suffixes (CustomField::generateUniqueSlug).
     */
    public static function uniqueSlug(string $label, int $groupId, ?int $excludeId = null): string
    {
        $slug = Str::slug($label);
        if ($slug === '') {
            $slug = 'field';
        }
        $candidate = $slug;
        $i = 1;
        while (self::slugTaken($candidate, $groupId, $excludeId)) {
            $candidate = $slug.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    protected static function slugTaken(string $slug, int $groupId, ?int $excludeId): bool
    {
        try {
            $query = DB::table('custom_fields')
                ->where('name', $slug)
                ->where('custom_field_group_id', $groupId);
            if ($excludeId !== null) {
                $query->where('id', '<>', $excludeId);
            }

            return $query->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
