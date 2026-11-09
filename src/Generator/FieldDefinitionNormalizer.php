<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use LaraSlice\Schema\FieldType;

/**
 * Turns the field definitions given to SliceGenerator (CLI, Slice Studio, blueprints) into
 * everything the generated slice needs per field.
 */
final class FieldDefinitionNormalizer
{
    /**
     * Validate and normalize user field input before the generator creates any files: column,
     * validation rules, schema call, form markup and DTO type for each field.
     */
    public function normalize(array $fields): array
    {
        if (count($fields) > 250) {
            throw new SliceFieldDefinitionException('A slice can define at most 250 custom fields per generation.');
        }

        $definitions = [];
        $reserved = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $types = [
            'string' => ['migration' => 'string', 'validation' => 'string', 'input' => 'text'],
            'text' => ['migration' => 'text', 'validation' => 'string', 'input' => 'textarea'],
            'integer' => ['migration' => 'integer', 'validation' => 'integer', 'input' => 'number'],
            'decimal' => ['migration' => 'decimal', 'validation' => 'numeric', 'input' => 'decimal'],
            'boolean' => ['migration' => 'boolean', 'validation' => 'boolean', 'input' => 'boolean'],
            'date' => ['migration' => 'date', 'validation' => 'date', 'input' => 'date'],
            'datetime' => ['migration' => 'dateTime', 'validation' => 'date', 'input' => 'datetime-local'],
            'email' => ['migration' => 'string', 'validation' => 'email', 'input' => 'email'],
            'select' => ['migration' => 'string', 'validation' => 'string', 'input' => 'select'],
            'foreign_id' => ['migration' => 'unsignedBigInteger', 'validation' => 'integer', 'input' => 'select'],
            'url' => ['migration' => 'string', 'validation' => 'url', 'input' => 'url'],
            'json' => ['migration' => 'json', 'validation' => 'json', 'input' => 'textarea'],
            'float' => ['migration' => 'float', 'validation' => 'numeric', 'input' => 'decimal'],
            'bigInteger' => ['migration' => 'bigInteger', 'validation' => 'integer', 'input' => 'number'],
            'timestamp' => ['migration' => 'timestamp', 'validation' => 'date', 'input' => 'datetime-local'],
        ];

        foreach ($fields as $index => $definition) {
            if (! is_array($definition) || ! isset($definition['name']) || ! is_string($definition['name'])) {
                throw new SliceFieldDefinitionException("Custom field at index {$index} must include a string name.");
            }

            $name = $definition['name'];
            $type = $definition['type'] ?? 'string';
            if (! is_string($type)) {
                throw new SliceFieldDefinitionException("Field '{$name}' must use a supported text type name.");
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                throw new SliceFieldDefinitionException("Invalid field name '{$name}'. Use lowercase snake_case identifiers.");
            }
            if (in_array($name, $reserved, true) || isset($definitions[$name])) {
                throw new SliceFieldDefinitionException("Field '{$name}' is reserved or duplicated.");
            }
            if (! isset($types[$type])) {
                throw new SliceFieldDefinitionException("Unsupported type '{$type}' for field '{$name}'.");
            }

            $label = $definition['label'] ?? Str::headline($name);
            if (! is_string($label) || mb_strlen($label) > 120) {
                throw new SliceFieldDefinitionException("Field '{$name}' must have a text label up to 120 characters.");
            }
            if ($problem = BladeSafeText::problem($label, "Field '{$name}' label")) {
                throw new SliceFieldDefinitionException($problem);
            }

            // Declared options make ANY field a select in the form and a
            // labeled value in listings — the storage type stays as declared
            // (e.g. a MySQL enum ported as string + options). Previously only
            // the literal 'select' type kept its options; string+options
            // fields silently lost them and rendered as free text.
            $options = [];
            if (! empty($definition['options'])) {
                $options = $definition['options'] ?? [];
                if (! is_array($options) || count($options) > 50) {
                    throw new SliceFieldDefinitionException("Field '{$name}' requires between 1 and 50 options when options are declared.");
                }
                foreach ($options as $value => $optionLabel) {
                    // Spaces are legal in option VALUES (real-world enum
                    // vocabularies store them — a project status of
                    // "in progress" must be declarable). Quotes, angle
                    // brackets and commas stay excluded: a comma would
                    // corrupt the generated `in:` validation rule and the
                    // rest endanger the rendered markup.
                    if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_. -]{1,80}$/', $value) || ! is_string($optionLabel) || ! BladeSafeText::isSafe($optionLabel)) {
                        throw new SliceFieldDefinitionException("Field '{$name}' has an invalid option.");
                    }
                }
            }

            $requiredInput = $definition['required'] ?? null;
            if ($requiredInput !== null && ! is_bool($requiredInput) && ! in_array($requiredInput, [0, 1, '0', '1'], true)) {
                throw new SliceFieldDefinitionException("Field '{$name}' required must be true or false.");
            }

            $nullableInput = $definition['nullable'] ?? ! (bool) $requiredInput;
            if (! is_bool($nullableInput) && ! in_array($nullableInput, [0, 1, '0', '1'], true)) {
                throw new SliceFieldDefinitionException("Field '{$name}' nullable must be true or false.");
            }
            if ($requiredInput !== null && (bool) $requiredInput === (bool) $nullableInput) {
                throw new SliceFieldDefinitionException("Field '{$name}' required and nullable settings conflict.");
            }
            $nullable = (bool) $nullableInput;
            $required = ! $nullable;
            $default = FieldType::coerceDefault($type, $definition['default'] ?? null) ?? ($type === 'boolean' ? false : null);
            if (! is_null($default) && ! is_scalar($default)) {
                throw new SliceFieldDefinitionException("Field '{$name}' default must be a scalar value or null.");
            }
            if (is_string($default) && ($problem = BladeSafeText::problem($default, "Field '{$name}' default"))) {
                throw new SliceFieldDefinitionException($problem);
            }
            if (! FieldType::defaultMatches($type, $default)) {
                throw new SliceFieldDefinitionException("Field '{$name}' has a default value that does not match its {$type} type.");
            }
            if ($default !== null && ! empty($options) && ! str_ends_with($name, '_id') && ! array_key_exists($default, $options)) {
                throw new SliceFieldDefinitionException("Field '{$name}' default must match one of its select option values.");
            }

            $encrypted = (bool) ($definition['encrypted'] ?? false);
            if ($encrypted && ! FieldType::isEncryptable($type)) {
                throw new SliceFieldDefinitionException("Field '{$name}' cannot be encrypted; only text-like fields support encryption.");
            }

            $length = $definition['length'] ?? null;
            // Forms (the Slice Studio wizard) send lengths as strings: "" means none, "50" means 50
            if ($length === '') {
                $length = null;
            } elseif (is_string($length) && ctype_digit($length)) {
                $length = (int) $length;
            }
            $maxLength = 255;
            $column = $types[$type]['migration'];
            if ($encrypted) {
                // Ciphertext is much longer than the value, so encrypted columns are always text
                $columnExpression = "\$table->text('{$name}')";
            } elseif (in_array($type, ['decimal', 'float'], true)) {
                [$precision, $scale] = [12, 2];
                if ($length !== null) {
                    if (! is_string($length) || ! preg_match('/^(\d{1,2})\s*,\s*(\d{1,2})$/', $length, $m) || (int) $m[2] > (int) $m[1]) {
                        throw new SliceFieldDefinitionException("Field '{$name}' length must be \"precision,scale\", e.g. \"12,2\".");
                    }
                    [$precision, $scale] = [(int) $m[1], (int) $m[2]];
                }
                $columnExpression = "\$table->decimal('{$name}', {$precision}, {$scale})";
            } elseif (in_array($column, ['string'], true) && $length !== null) {
                if (! is_int($length) || $length < 1 || $length > 65535) {
                    throw new SliceFieldDefinitionException("Field '{$name}' length must be between 1 and 65535.");
                }
                $maxLength = $length;
                $columnExpression = "\$table->string('{$name}', {$length})";
            } elseif ($length !== null) {
                throw new SliceFieldDefinitionException("Field '{$name}' does not support a length.");
            } else {
                $columnExpression = "\$table->{$column}('{$name}')";
            }
            if ($nullable) {
                $columnExpression .= '->nullable()';
            }
            if ($default !== null) {
                $columnExpression .= '->default('.var_export($default, true).')';
            }
            if ($type === 'foreign_id') {
                $references = $definition['references'] ?? null;
                if ($references !== null && ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', (string) $references)) {
                    throw new SliceFieldDefinitionException("Field '{$name}' references an invalid table name.");
                }
                // A real constraint only when the referenced table is known; always an index
                $columnExpression = $references !== null
                    ? "\$table->foreignId('{$name}')".($nullable ? '->nullable()' : '')."->constrained('{$references}')->".($nullable ? 'nullOnDelete()' : 'restrictOnDelete()')
                    : $columnExpression.'->index()';
            }

            $rules = array_values(array_filter([
                $nullable ? 'nullable' : 'required',
                $types[$type]['validation'],
                in_array($type, ['string', 'email', 'select', 'url'], true) ? 'max:'.$maxLength : null,
                ! empty($options) && ! str_ends_with($name, '_id') ? 'in:'.implode(',', array_keys($options)) : null,
            ]));

            // Form hygiene (Body v2 rules): the input for a
            // date-domain column is a date input even when the
            // storage type is datetime — people pick days, not
            // timestamps, for leave_date / start_date / *_on.
            // The date-domain names are the ones source systems
            // consistently use for day-granularity values:
            // *_date, *_on, valid *_till, date_of_birth, a bare
            // `date`, `deadline`, `dob` (the comparative test
            // found every one of these rendered datetime-local
            // on the ported ERP).
            $input = $types[$type]['input'];
            if (in_array($type, ['datetime', 'timestamp'], true) && self::isDateDomainName($name)) {
                $input = 'date';
            }

            $schema = "Field::make('{$name}', '{$input}')->label(".var_export($label, true).')';
            if ($required) {
                $schema .= '->required()';
            }
            if ($default !== null) {
                $schema .= '->default('.var_export($default, true).')';
            }
            if ($options) {
                $schema .= '->options('.var_export($options, true).')';
            }

            // Visibility flags SURVIVE normalization (they were
            // silently dropped, which made manifest show_in_form
            // edits no-ops). System/audit columns (added_by,
            // approved_*, event_id, *_by) default to hidden from
            // forms; an explicit show_in_form in the manifest
            // still wins — the Heart is edited deliberately.
            $hidden = ! empty($definition['hidden']);
            $showInForm = isset($definition['show_in_form'])
                ? (bool) $definition['show_in_form']
                : ! $hidden && ! self::isSystemField($name);

            $definitions[$name] = [
                'name' => $name,
                'label' => $label,
                'rules' => $rules,
                'type' => $type,
                'schema' => $schema,
                'form' => $this->renderFormField($name, $label, ! empty($options) ? 'select' : $input, $required, $default, $options, is_string($definition['relation'] ?? null) ? $definition['relation'] : null),
                'migration' => $columnExpression.';',
                'default' => $default,
                'nullable' => $nullable,
                'options' => $options,
                'encrypted' => $encrypted,
                // Declared Eloquent cast (the field-level
                // `cast` key, absorbed vocabulary 7 Nov):
                // carried verbatim so SliceGenerator can
                // emit it into the model's $casts.
                'cast' => isset($definition['cast']) && is_string($definition['cast']) && $definition['cast'] !== '' ? $definition['cast'] : null,
                // Declared length, carried verbatim like `cast`:
                // for decimal/float fields this is the "p,s"
                // precision the migration expression above was
                // baked from — SliceGenerator records it in the
                // manifest so a regen from the Heart keeps the
                // precision instead of reverting to (12,2).
                'length' => $length,
                'relation' => is_array($definition['relation'] ?? null) ? $definition['relation'] : null,
                'hidden' => $hidden,
                'show_in_form' => $showInForm,
                'show_in_list' => isset($definition['show_in_list']) ? (bool) $definition['show_in_list'] : ! $hidden,
                'system' => self::isSystemField($name),
            ];
        }

        return array_values($definitions);
    }

    /**
     * A typed DTO property declaration whose default always matches its type.
     *
     * Built-in columns without a custom definition use $fallbackType / $fallbackDefault.
     */
    public function dtoProperty(string $name, ?array $field, string $fallbackType = '?string', mixed $fallbackDefault = null): string
    {
        if ($field === null) {
            return "public {$fallbackType} \${$name} = ".var_export($fallbackDefault, true).';';
        }

        $default = $field['default'] ?? null;
        [$type, $default] = match ($field['type']) {
            'boolean' => ['bool', (bool) $default],
            'integer', 'foreign_id', 'bigInteger' => ['?int', $default === null ? null : (int) $default],
            'decimal', 'float' => ['?float', $default === null ? null : (float) $default],
            default => [! $field['nullable'] && $default !== null ? 'string' : '?string', $default === null ? null : (string) $default],
        };

        if ($type === 'string' && $default === null) {
            $default = '';
        }

        return "public {$type} \${$name} = ".var_export($default, true).';';
    }

    private function renderFormField(string $name, string $label, string $type, bool $required, mixed $default, array $options, ?string $relation = null): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $requiredHtml = $required ? ' required' : '';
        $requiredMark = $required ? ' *' : '';
        $defaultExpression = var_export($default, true);
        $valueExpression = "old('{$name}', \$form->{$name} ?? {$defaultExpression})";

        // A declared relation makes ANY field a relationship
        // picker — including source columns that break the *_id
        // convention (award_to → users, project_admin → users):
        // the controller's options resolver synthesizes the
        // relation's conventional options var for exactly these
        // declarations, so the form must ask for it by that name.
        // The bare *_id suffix is NOT enough on its own: a
        // string-typed *_id is a human code, not a foreign key
        // (employee_details.employee_id stores 'EMP-12') — forcing
        // it into a picker leaves the stored code unmatched by any
        // option, so an untouched edit submits '' and wipes it
        // (Gate T A2d). Real string FKs declare their relation.
        $suffixPicker = str_ends_with($name, '_id') && ! in_array($type, ['text', 'string', 'textarea'], true);
        if ($type === 'select' || $type === 'foreign_id' || $suffixPicker || $relation !== null) {
            $baseRel = str_ends_with($name, '_id') ? Str::replaceLast('_id', '', $name) : ($relation ?? $name);
            $optionsVar = Str::camel(Str::plural($baseRel)).'Options';
            $quickStoreUrlVar = Str::camel(Str::plural($baseRel)).'QuickStoreUrl';
            $requiredBool = $required ? 'true' : 'false';
            $cleanTitle = str_ends_with($name, '_id') ? Str::headline($baseRel) : $safeLabel;

            if (empty($options) || str_ends_with($name, '_id') || $type === 'foreign_id' || $relation !== null) {
                return <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.combobox-relationship
                        name="{$name}"
                        label="{$cleanTitle}"
                        :options="\${$optionsVar} ?? []"
                        :selected="{$valueExpression}"
                        placeholder="Select {$cleanTitle}..."
                        :quickAddUrl="\${$quickStoreUrlVar} ?? null"
                        quickAddTitle="{$cleanTitle}"
                        :required="{$requiredBool}"
                    />
                    @error('{$name}') <p class="text-xs text-destructive font-medium">{{ \$message }}</p> @enderror
                </div>
BLADE;
            }

            $optionHtml = '<option value="">Select '.$safeLabel.'...</option>';
            foreach ($options as $value => $optionLabel) {
                $displayLabel = ($optionLabel === $value) ? Str::headline($optionLabel) : $optionLabel;
                $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safeOptionLabel = htmlspecialchars($displayLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $optionHtml .= "\n                    <option value=\"{$safeValue}\" @selected({$valueExpression} === '{$safeValue}')>{$safeOptionLabel}</option>";
            }

            return <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="{$name}">{$safeLabel}{$requiredMark}</x-ui.label>
                    <select id="{$name}" name="{$name}" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"{$requiredHtml}>
{$optionHtml}
                    </select>
                    @error('{$name}') <p class="text-xs text-destructive font-medium">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        if ($type === 'textarea') {
            return <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="{$name}">{$safeLabel}{$requiredMark}</x-ui.label>
                    <x-ui.textarea id="{$name}" name="{$name}" rows="3"{$requiredHtml}>{{ old('{$name}', \$form->{$name} ?? '') }}</x-ui.textarea>
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        if ($type === 'boolean') {
            return <<<BLADE
                <div class="flex items-center justify-between p-3 rounded-xl border bg-card/60">
                    <x-ui.label for="{$name}">{$safeLabel}</x-ui.label>
                    <input type="hidden" name="{$name}" value="0">
                    <input type="checkbox" id="{$name}" name="{$name}" value="1" @checked(old('{$name}', \$form->{$name} ?? false)) class="h-4 w-4 rounded border-gray-300 text-primary" />
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        // Date-family inputs accept only their own wire format
        // (Y-m-d / Y-m-d\TH:i): a stored datetime string rendered
        // raw is invalid, so the browser shows the field empty
        // and an untouched edit submits '' — silently wiping the
        // stored date (comparative Gate T A2d: employee joining
        // dates nulled by an unrelated edit). Format the value
        // exactly as BladeFieldInjector does.
        if ($type === 'date' || $type === 'datetime-local') {
            $format = $type === 'date' ? 'Y-m-d' : 'Y-m-d\\TH:i';
            $valueExpression = "old('{$name}', ! empty(\$form->{$name}) ? \\Illuminate\\Support\\Carbon::parse(\$form->{$name})->format('{$format}') : {$defaultExpression})";
        }

        $inputType = $type === 'decimal' ? 'number' : $type;
        $step = $type === 'decimal' ? ' step="0.01"' : '';

        return <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="{$name}">{$safeLabel}{$requiredMark}</x-ui.label>
                    <x-ui.input id="{$name}" type="{$inputType}" name="{$name}" value="{{ {$valueExpression} }}"{$step}{$requiredHtml} />
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
    }

    /**
     * Day-granularity field names: a bare `date`, `deadline`
     * or `dob`, or any *_date / *_on / *_till / *_birth name.
     * Time-of-day names (logged_at, clock_in_at, *_at) are
     * deliberately NOT date-domain — they keep their
     * datetime-local input.
     */
    public static function isDateDomainName(string $name): bool
    {
        return in_array($name, ['date', 'deadline', 'dob'], true)
            || preg_match('/(_date|_on|_till|_birth)$/', $name) === 1;
    }

    /**
     * Audit/system columns that exist for the record's
     * bookkeeping, never for a person to type: author stamps
     * (*_by), approval stamps, cross-system event links, and
     * server-generated public-link tokens (hash).
     * Mirrors the Body v2 system_fields rule.
     */
    public static function isSystemField(string $name): bool
    {
        return in_array($name, ['added_by', 'last_updated_by', 'created_by', 'updated_by', 'approved_by', 'approved_at', 'event_id', 'ip_address', 'hash'], true)
            || str_ends_with($name, '_by')
            // Server-stamped network values (clock_in_ip,
            // clock_out_ip, login_ip, …): the server records
            // them (cf. the clock punch); a human never types
            // them into a form.
            || str_ends_with($name, '_ip');
    }
}
