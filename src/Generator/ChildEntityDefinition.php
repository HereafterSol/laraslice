<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use InvalidArgumentException;
use LaraSlice\Schema\FieldType;

final class ChildEntityDefinition
{
    private const TYPES = FieldType::COLUMN_METHODS;

    /** @return array{slice:string,plural_slice:string,child_table:string,foreign_key:string,fields:array<int,array{name:string,type:string,nullable:bool,required:bool,default:mixed}>} */
    public static function normalize(string $sliceName, string $tableName, string $relationType, ?string $foreignKey, array $fields): array
    {
        $slice = SliceName::canonical($sliceName);
        $pluralSlice = Str::plural($slice);
        $childTable = Str::snake(trim($tableName));
        $parentTable = Str::plural(Str::snake($slice));
        $foreignKey ??= Str::singular($parentTable).'_id';

        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $childTable)) {
            throw new InvalidArgumentException('Child table names must be lowercase snake_case identifiers.');
        }
        if ($childTable === $parentTable) {
            throw new InvalidArgumentException('A child entity must use a table name different from its parent.');
        }
        if ($relationType !== 'hasMany') {
            throw new InvalidArgumentException('Child entities currently require a hasMany relationship; choose a different builder for other relationship types.');
        }
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $foreignKey)) {
            throw new InvalidArgumentException('Foreign keys must be lowercase snake_case identifiers.');
        }
        if (count($fields) > 50) {
            throw new InvalidArgumentException('A child entity can define at most 50 fields.');
        }

        if ($fields === []) {
            $fields = [
                ['name' => 'name', 'type' => 'string', 'required' => true],
                ['name' => 'code', 'type' => 'string', 'nullable' => true],
                ['name' => 'status', 'type' => 'string', 'nullable' => true, 'default' => 'active'],
            ];
        }

        $normalized = [];
        $reserved = ['id', $foreignKey, 'created_at', 'updated_at', 'deleted_at'];

        foreach ($fields as $index => $field) {
            if (! is_array($field) || ! isset($field['name']) || ! is_string($field['name'])) {
                throw new InvalidArgumentException("Child field at index {$index} must include a string name.");
            }

            $name = Str::snake(trim($field['name']));
            if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) || in_array($name, $reserved, true) || isset($normalized[$name])) {
                throw new InvalidArgumentException("Invalid, reserved, or duplicate child field name '{$name}'.");
            }

            $rawType = $field['type'] ?? 'string';
            if (! is_string($rawType) || ! isset(self::TYPES[strtolower($rawType)])) {
                throw new InvalidArgumentException("Unsupported database type for child field '{$name}'.");
            }
            $type = self::TYPES[strtolower($rawType)];
            $inputType = strtolower($rawType);
            $options = $field['options'] ?? [];
            if ($inputType === 'enum') {
                if (! is_array($options) || $options === [] || count($options) > 50) {
                    throw new InvalidArgumentException("Enum field '{$name}' requires between 1 and 50 options.");
                }
                foreach ($options as $value => $label) {
                    if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $value) || ! is_string($label) || $label === '' || ! BladeSafeText::isSafe($label)) {
                        throw new InvalidArgumentException("Enum field '{$name}' has an invalid option.");
                    }
                }
            } elseif ($options !== []) {
                throw new InvalidArgumentException("Field '{$name}' options are only supported for enum fields.");
            }

            $nullable = $field['nullable'] ?? ! ($field['required'] ?? false);
            if (! is_bool($nullable) && ! in_array($nullable, [0, 1, '0', '1'], true)) {
                throw new InvalidArgumentException("Child field '{$name}' nullable must be true or false.");
            }
            $required = $field['required'] ?? ! (bool) $nullable;
            if (! is_bool($required) && ! in_array($required, [0, 1, '0', '1'], true)) {
                throw new InvalidArgumentException("Child field '{$name}' required must be true or false.");
            }
            if ((bool) $required === (bool) $nullable) {
                throw new InvalidArgumentException("Child field '{$name}' required and nullable settings conflict.");
            }

            $default = $field['default'] ?? null;
            if ($default !== null && ! is_scalar($default)) {
                throw new InvalidArgumentException("Child field '{$name}' default must be scalar or null.");
            }
            if (! FieldType::defaultMatches($type, $default)) {
                throw new InvalidArgumentException("Child field '{$name}' default does not match its database type.");
            }
            if ($inputType === 'enum' && $default !== null && ! array_key_exists($default, $options)) {
                throw new InvalidArgumentException("Child enum field '{$name}' default must match one of its options.");
            }
            $label = $field['label'] ?? Str::headline($name);
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 160) {
                throw new InvalidArgumentException("Child field '{$name}' label must contain 1 to 160 characters.");
            }
            if ($problem = BladeSafeText::problem($label, "Child field '{$name}' label")) {
                throw new InvalidArgumentException($problem);
            }

            $normalized[$name] = [
                'name' => $name,
                'type' => $type,
                'nullable' => (bool) $nullable,
                'required' => (bool) $required,
                'default' => $default,
                'input_type' => $inputType,
                'options' => $options,
                'label' => $label,
            ];
        }

        return [
            'slice' => $slice,
            'plural_slice' => $pluralSlice,
            'child_table' => $childTable,
            'foreign_key' => $foreignKey,
            'fields' => array_values($normalized),
        ];
    }
}
