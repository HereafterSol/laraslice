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
        if (count($fields) > 50) {
            throw new SliceFieldDefinitionException('A slice can define at most 50 custom fields per generation.');
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

            $options = [];
            if ($type === 'select' && ! empty($definition['options'])) {
                $options = $definition['options'] ?? [];
                if (! is_array($options) || count($options) > 50) {
                    throw new SliceFieldDefinitionException("Select field '{$name}' requires between 1 and 50 options.");
                }
                foreach ($options as $value => $optionLabel) {
                    if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $value) || ! is_string($optionLabel) || ! BladeSafeText::isSafe($optionLabel)) {
                        throw new SliceFieldDefinitionException("Select field '{$name}' has an invalid option.");
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
            if ($type === 'select' && $default !== null && ! empty($options) && ! array_key_exists($default, $options)) {
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
                $type === 'select' && ! empty($options) ? 'in:'.implode(',', array_keys($options)) : null,
            ]));

            $schema = "Field::make('{$name}', '{$types[$type]['input']}')->label(".var_export($label, true).')';
            if ($required) {
                $schema .= '->required()';
            }
            if ($default !== null) {
                $schema .= '->default('.var_export($default, true).')';
            }
            if ($options) {
                $schema .= '->options('.var_export($options, true).')';
            }

            $definitions[$name] = [
                'name' => $name,
                'label' => $label,
                'rules' => $rules,
                'type' => $type,
                'schema' => $schema,
                'form' => $this->renderFormField($name, $label, $types[$type]['input'], $required, $default, $options),
                'migration' => $columnExpression.';',
                'default' => $default,
                'nullable' => $nullable,
                'options' => $options,
                'encrypted' => $encrypted,
                'relation' => is_array($definition['relation'] ?? null) ? $definition['relation'] : null,
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

    private function renderFormField(string $name, string $label, string $type, bool $required, mixed $default, array $options): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $requiredHtml = $required ? ' required' : '';
        $requiredMark = $required ? ' *' : '';
        $defaultExpression = var_export($default, true);
        $valueExpression = "old('{$name}', \$form->{$name} ?? {$defaultExpression})";

        if ($type === 'select' || $type === 'foreign_id' || str_ends_with($name, '_id')) {
            $baseRel = Str::replaceLast('_id', '', $name);
            $optionsVar = Str::camel(Str::plural($baseRel)).'Options';
            $quickStoreUrlVar = Str::camel(Str::plural($baseRel)).'QuickStoreUrl';
            $requiredBool = $required ? 'true' : 'false';
            $cleanTitle = str_ends_with($name, '_id') ? Str::headline($baseRel) : $safeLabel;

            if (empty($options) || str_ends_with($name, '_id') || $type === 'foreign_id') {
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
}
