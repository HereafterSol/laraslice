<?php

namespace LaraSlice\Validation;

use Symfony\Component\Yaml\Yaml;

/**
 * Validates slice manifests against schemas/slice-v0.1.json rules.
 *
 * Lightweight checker focused on closed vocabularies (formatters, field types)
 * and structural requirements — no external JSON Schema library required.
 */
class SliceYamlValidator
{
    public const FIELD_TYPES = [
        'string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'timestamp',
    ];

    public const FORMATTERS = [
        'text',
        'text_with_badge',
        'status_pill',
        'date_time',
        'date_overdue',
        'money_breakdown',
        'progress_bar',
        'code_badge',
        'composite_user',
        'number',
    ];

    public const VIEWS = ['table', 'board', 'calendar', 'card', 'list'];

    public const INPUT_WIDGETS = [
        'text', 'textarea', 'select', 'radio', 'checkbox', 'switch',
        'date', 'datetime', 'date_picker', 'file', 'email', 'tel', 'number', 'hidden',
    ];

    /**
     * @return list<array{path: string, message: string}>
     */
    public function validateFile(string $path): array
    {
        if (! is_file($path)) {
            return [['path' => $path, 'message' => 'File not found']];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [['path' => '', 'message' => 'File is empty']];
        }

        try {
            if (str_ends_with(strtolower($path), '.json')) {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $data = Yaml::parse($raw);
            }
        } catch (\Throwable $e) {
            return [['path' => '', 'message' => 'Parse error: '.$e->getMessage()]];
        }

        if (! is_array($data)) {
            return [['path' => '', 'message' => 'Manifest must be a mapping/object']];
        }

        return $this->validate($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $data): array
    {
        $errors = [];

        if (! isset($data['name']) || ! is_string($data['name']) || $data['name'] === '') {
            $errors[] = ['path' => 'name', 'message' => 'Required string property "name" is missing or empty'];
        }

        if (isset($data['spec_version']) && ! in_array((string) $data['spec_version'], ['0.1', '0.1.0'], true)) {
            $errors[] = ['path' => 'spec_version', 'message' => 'spec_version must be "0.1" (got "'.$data['spec_version'].'")'];
        }

        if (isset($data['models'])) {
            if (! is_array($data['models'])) {
                $errors[] = ['path' => 'models', 'message' => 'models must be an array'];
            } else {
                foreach ($data['models'] as $i => $model) {
                    if (! is_array($model)) {
                        $errors[] = ['path' => "models[$i]", 'message' => 'model must be an object'];
                        continue;
                    }
                    if (empty($model['handle'])) {
                        $errors[] = ['path' => "models[$i].handle", 'message' => 'model.handle is required'];
                    }
                    if (empty($model['table'])) {
                        $errors[] = ['path' => "models[$i].table", 'message' => 'model.table is required'];
                    }
                    if (isset($model['fields']) && is_array($model['fields'])) {
                        foreach ($model['fields'] as $j => $field) {
                            if (is_array($field)) {
                                $errors = array_merge($errors, $this->validateField($field, "models[$i].fields[$j]"));
                            }
                        }
                    }
                }
            }
        }

        if (isset($data['fields']) && is_array($data['fields'])) {
            $isList = array_is_list($data['fields']);
            foreach ($data['fields'] as $key => $field) {
                if (! is_array($field)) {
                    $errors[] = ['path' => "fields.$key", 'message' => 'field must be an object'];
                    continue;
                }
                if (! isset($field['handle']) && ! isset($field['name']) && is_string($key) && ! $isList) {
                    $field['handle'] = $key;
                }
                $path = $isList ? "fields[$key]" : "fields.$key";
                $errors = array_merge($errors, $this->validateField($field, $path, allowMissingHandle: ! $isList && is_string($key)));
            }
        }

        $columns = $data['ui']['directory']['columns'] ?? null;
        if (is_array($columns)) {
            foreach ($columns as $i => $col) {
                if (! is_array($col)) {
                    $errors[] = ['path' => "ui.directory.columns[$i]", 'message' => 'column must be an object'];
                    continue;
                }
                $key = $col['key'] ?? $col['handle'] ?? null;
                if ($key === null || $key === '') {
                    $errors[] = ['path' => "ui.directory.columns[$i].key", 'message' => 'column.key (or handle) is required'];
                }
                if (isset($col['formatter'])) {
                    $fmt = (string) $col['formatter'];
                    if (! in_array($fmt, self::FORMATTERS, true)) {
                        $errors[] = [
                            'path' => "ui.directory.columns[$i].formatter",
                            'message' => "Invalid formatter \"$fmt\". Allowed: ".implode(', ', self::FORMATTERS),
                        ];
                    }
                }
                if (isset($col['html_template']) || isset($col['render']) || isset($col['blade'])) {
                    $errors[] = [
                        'path' => "ui.directory.columns[$i]",
                        'message' => 'HTML/render templates are not allowed in YAML (use formatter vocabulary only)',
                    ];
                }
            }
        }

        $views = $data['ui']['directory']['views'] ?? null;
        if (is_array($views)) {
            foreach ($views as $i => $view) {
                if (! in_array((string) $view, self::VIEWS, true)) {
                    $errors[] = [
                        'path' => "ui.directory.views[$i]",
                        'message' => "Invalid view \"$view\". Allowed: ".implode(', ', self::VIEWS),
                    ];
                }
            }
        }

        $defaultView = $data['ui']['directory']['default_view'] ?? null;
        if (is_string($defaultView) && ! in_array($defaultView, self::VIEWS, true)) {
            $errors[] = [
                'path' => 'ui.directory.default_view',
                'message' => "Invalid default_view \"$defaultView\". Allowed: ".implode(', ', self::VIEWS),
            ];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array{path: string, message: string}>
     */
    protected function validateField(array $field, string $path, bool $allowMissingHandle = false): array
    {
        $errors = [];
        $handle = $field['handle'] ?? $field['name'] ?? null;
        if (($handle === null || $handle === '') && ! $allowMissingHandle) {
            $errors[] = ['path' => "$path.handle", 'message' => 'field.handle is required'];
        }
        if (isset($field['type'])) {
            $type = (string) $field['type'];
            $aliases = [
                'int' => 'integer',
                'bool' => 'boolean',
                'float' => 'decimal',
                'double' => 'decimal',
                'bigint' => 'integer',
            ];
            $normalized = $aliases[$type] ?? $type;
            // Common mistake: UI widget used as storage type
            if (in_array($type, self::INPUT_WIDGETS, true) && ! in_array($type, self::FIELD_TYPES, true)) {
                $errors[] = [
                    'path' => "$path.type",
                    'message' => "\"$type\" is an input widget, not a data type. Use type: string (or other storage type) and input: $type",
                ];
            } elseif (! in_array($normalized, self::FIELD_TYPES, true)) {
                $errors[] = [
                    'path' => "$path.type",
                    'message' => "Invalid field type \"$type\". Allowed: ".implode(', ', self::FIELD_TYPES),
                ];
            }
        }

        foreach (['input', 'input_type'] as $inputKey) {
            if (! isset($field[$inputKey])) {
                continue;
            }
            $input = (string) $field[$inputKey];
            if (! in_array($input, self::INPUT_WIDGETS, true)) {
                $errors[] = [
                    'path' => "$path.$inputKey",
                    'message' => "Invalid input widget \"$input\". Allowed: ".implode(', ', self::INPUT_WIDGETS),
                ];
            }
        }

        return $errors;
    }

    public function schemaPath(): string
    {
        $candidates = [
            dirname(__DIR__, 2).'/schemas/slice-v0.1.json',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }
}
