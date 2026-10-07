<?php

namespace LaraSlice\Blueprint;

final class BlueprintValidator
{
    private const FIELD_TYPES = [
        'string', 'text', 'integer', 'bigInteger', 'foreign_id', 'decimal', 'float', 'boolean', 'date',
        'datetime', 'timestamp', 'enum', 'email', 'url', 'json',
    ];

    private const RELATION_TYPES = ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'];
    private const RESERVED_FIELDS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** @param array<string, mixed> $blueprint
     *  @return array<string, mixed> Validated blueprint.
     */
    public function validate(array $blueprint): array
    {
        $errors = [];
        $this->checkKeys($blueprint, ['schema_version', 'name', 'handle', 'description', 'author', 'version', 'models', 'tenant', 'domain', 'navigation', 'permissions'], 'blueprint', $errors);

        if (($blueprint['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version must be the integer 1.';
        }

        $name = $blueprint['name'] ?? null;
        if (! is_string($name) || trim($name) === '' || strlen($name) > 120) {
            $errors[] = 'name must be a non-empty string of at most 120 characters.';
        }

        if (! $this->isHandle($blueprint['handle'] ?? null)) {
            $errors[] = 'handle must be lowercase snake_case and start with a letter.';
        }

        $models = $blueprint['models'] ?? null;
        if (! is_array($models) || ! array_is_list($models) || $models === []) {
            $errors[] = 'models must be a non-empty list.';
            $models = [];
        }

        $modelHandles = [];
        $tables = [];
        $rootCount = 0;

        foreach ($models as $index => $model) {
            $path = "models.{$index}";
            if (! is_array($model)) {
                $errors[] = "{$path} must be a map.";
                continue;
            }
            $this->checkKeys($model, ['handle', 'table', 'root', 'fields', 'relations', 'tenant', 'timestamps', 'soft_deletes'], $path, $errors);

            if (isset($model['tenant']) && ! is_bool($model['tenant'])) {
                $errors[] = "{$path}.tenant must be a boolean.";
            }
            if (isset($model['timestamps']) && ! is_bool($model['timestamps'])) {
                $errors[] = "{$path}.timestamps must be a boolean.";
            }
            if (isset($model['soft_deletes']) && ! is_bool($model['soft_deletes'])) {
                $errors[] = "{$path}.soft_deletes must be a boolean.";
            }

            $modelHandle = $model['handle'] ?? null;
            if (! $this->isHandle($modelHandle)) {
                $errors[] = "{$path}.handle must be a lowercase snake_case identifier.";
            } elseif (isset($modelHandles[$modelHandle])) {
                $errors[] = "{$path}.handle duplicates model '{$modelHandle}'.";
            } else {
                $modelHandles[$modelHandle] = $index;
            }

            $table = $model['table'] ?? null;
            if (! $this->isHandle($table)) {
                $errors[] = "{$path}.table must be a lowercase snake_case identifier.";
            } elseif (isset($tables[$table])) {
                $errors[] = "{$path}.table duplicates table '{$table}'.";
            } else {
                $tables[$table] = $index;
            }

            if (($model['root'] ?? false) === true) {
                $rootCount++;
            } elseif (isset($model['root']) && ! is_bool($model['root'])) {
                $errors[] = "{$path}.root must be a boolean.";
            }

            $fields = $model['fields'] ?? [];
            if (! is_array($fields) || ! array_is_list($fields)) {
                $errors[] = "{$path}.fields must be a list.";
                $fields = [];
            }

            $fieldHandles = [];
            foreach ($fields as $fieldIndex => $field) {
                $fieldPath = "{$path}.fields.{$fieldIndex}";
                if (! is_array($field)) {
                    $errors[] = "{$fieldPath} must be a map.";
                    continue;
                }
                $this->checkKeys($field, ['handle', 'label', 'type', 'required', 'nullable', 'default', 'options', 'encrypted', 'width', 'length'], $fieldPath, $errors);

                $fieldHandle = $field['handle'] ?? null;
                if (! $this->isHandle($fieldHandle)) {
                    $errors[] = "{$fieldPath}.handle must be a lowercase snake_case identifier.";
                } elseif (isset($fieldHandles[$fieldHandle])) {
                    $errors[] = "{$fieldPath}.handle duplicates field '{$fieldHandle}'.";
                } elseif (in_array($fieldHandle, self::RESERVED_FIELDS, true)) {
                    $errors[] = "{$fieldPath}.handle is reserved for Laravel-managed columns.";
                } else {
                    $fieldHandles[$fieldHandle] = true;
                }

                if (! in_array($field['type'] ?? null, self::FIELD_TYPES, true)) {
                    $errors[] = "{$fieldPath}.type must be one of: " . implode(', ', self::FIELD_TYPES) . '.';
                }

                foreach (['required', 'nullable', 'encrypted'] as $booleanOption) {
                    if (isset($field[$booleanOption]) && ! is_bool($field[$booleanOption])) {
                        $errors[] = "{$fieldPath}.{$booleanOption} must be a boolean.";
                    }
                }
                if (isset($field['required'], $field['nullable']) && $field['required'] === $field['nullable']) {
                    $errors[] = "{$fieldPath}.required and nullable cannot both be true or both be false.";
                }

                if (isset($field['label']) && (! is_string($field['label']) || trim($field['label']) === '' || strlen($field['label']) > 160)) {
                    $errors[] = "{$fieldPath}.label must be a non-empty string of at most 160 characters.";
                } elseif (isset($field['label']) && ($problem = \LaraSlice\Generator\BladeSafeText::problem($field['label'], "{$fieldPath}.label"))) {
                    $errors[] = $problem;
                }

                if (isset($field['default']) && is_string($field['default']) && ($problem = \LaraSlice\Generator\BladeSafeText::problem($field['default'], "{$fieldPath}.default"))) {
                    $errors[] = $problem;
                }
                if (isset($field['default']) && ! is_scalar($field['default']) && $field['default'] !== null) {
                    $errors[] = "{$fieldPath}.default must be scalar or null.";
                }

                if (($field['type'] ?? null) === 'enum') {
                    $options = $field['options'] ?? null;
                    if (! is_array($options) || $options === []) {
                        $errors[] = "{$fieldPath}.options must be a non-empty map for enum fields.";
                    } else {
                        foreach ($options as $value => $label) {
                            if (! is_string($value) || $value === '' || ! is_string($label) || $label === '' || ! \LaraSlice\Generator\BladeSafeText::isSafe($label)) {
                                $errors[] = "{$fieldPath}.options must map non-empty string values to labels.";
                                break;
                            }
                        }
                    }
                } elseif (isset($field['options'])) {
                    $errors[] = "{$fieldPath}.options is only supported for enum fields.";
                }
            }

            $model['_validated_fields'] = $fieldHandles;
            $model['_path'] = $path;
            $models[$index] = $model;
        }

        if ($models !== [] && $rootCount !== 1) {
            $errors[] = 'Exactly one model must set root: true.';
        }

        $rootHandle = null;
        foreach ($models as $model) {
            if (is_array($model) && ($model['root'] ?? false) === true) {
                $rootHandle = $model['handle'] ?? null;
            }
        }

        if ($rootHandle !== null && isset($modelHandles[$rootHandle])) {
            $reachable = [$rootHandle => true];
            do {
                $previousCount = count($reachable);
                foreach ($models as $model) {
                    if (! is_array($model) || ! isset($reachable[$model['handle'] ?? ''])) {
                        continue;
                    }
                    $relations = $model['relations'] ?? [];
                    if (! is_array($relations)) {
                        continue;
                    }
                    foreach ($relations as $relation) {
                        if (! is_array($relation) || ! is_string($relation['model'] ?? null)) {
                            continue;
                        }
                        if (isset($modelHandles[$relation['model'] ?? ''])) {
                            $reachable[$relation['model']] = true;
                        }
                    }
                }
            } while (count($reachable) > $previousCount);

            foreach ($modelHandles as $modelHandle => $_index) {
                if (! isset($reachable[$modelHandle])) {
                    $errors[] = "Model '{$modelHandle}' is not reachable from the root model '{$rootHandle}'.";
                }
            }
        }
        foreach ($models as $model) {
            if (is_array($model) && ($model['handle'] ?? null) === $rootHandle) {
                foreach (['title', 'description', 'status'] as $baseField) {
                    if (isset($model['_validated_fields'][$baseField])) {
                        $errors[] = "Root model field '{$baseField}' conflicts with a field already generated by the base slice.";
                    }
                }
            }
        }

        foreach ($models as $index => $model) {
            if (! is_array($model)) {
                continue;
            }
            $path = $model['_path'] ?? "models.{$index}";
            $relations = $model['relations'] ?? [];
            if (! is_array($relations) || ! array_is_list($relations)) {
                $errors[] = "{$path}.relations must be a list.";
                continue;
            }

            $relationNames = [];
            foreach ($relations as $relationIndex => $relation) {
                $relationPath = "{$path}.relations.{$relationIndex}";
                if (! is_array($relation)) {
                    $errors[] = "{$relationPath} must be a map.";
                    continue;
                }
                $this->checkKeys($relation, ['name', 'type', 'model', 'foreign_key', 'pivot_table'], $relationPath, $errors);

                $relationName = $relation['name'] ?? null;
                if (! $this->isHandle($relationName)) {
                    $errors[] = "{$relationPath}.name must be a lowercase snake_case identifier.";
                } elseif (isset($relationNames[$relationName])) {
                    $errors[] = "{$relationPath}.name duplicates relation '{$relationName}'.";
                } else {
                    $relationNames[$relationName] = true;
                }

                $type = $relation['type'] ?? null;
                if (! in_array($type, self::RELATION_TYPES, true)) {
                    $errors[] = "{$relationPath}.type must be one of: " . implode(', ', self::RELATION_TYPES) . '.';
                    continue;
                }

                $targetHandle = $relation['model'] ?? null;
                $isInternal = is_string($targetHandle) && isset($modelHandles[$targetHandle]);
                if (! is_string($targetHandle) || (! $isInternal && ($relation['external'] ?? false) !== true && $type !== 'belongsTo')) {
                    $errors[] = "{$relationPath}.model must reference a model handle in this blueprint.";
                    continue;
                }

                if ($type === 'belongsTo') {
                    $foreignKey = $relation['foreign_key'] ?? null;
                    if (! $this->isHandle($foreignKey) || ! isset($model['_validated_fields'][$foreignKey])) {
                        $errors[] = "{$relationPath}.foreign_key must reference a field on the belongsTo model.";
                    } else {
                        $field = $this->findField($model, $foreignKey);
                        if (! in_array($field['type'] ?? null, ['foreign_id', 'integer'], true)) {
                            $errors[] = "{$relationPath}.foreign_key must use a foreign_id or integer field.";
                        }
                    }
                } elseif (in_array($type, ['hasOne', 'hasMany'], true)) {
                    $foreignKey = $relation['foreign_key'] ?? null;
                    if ($isInternal) {
                        $targetModel = $models[$modelHandles[$targetHandle]] ?? [];
                        if (! $this->isHandle($foreignKey) || ! isset($targetModel['_validated_fields'][$foreignKey])) {
                            $errors[] = "{$relationPath}.foreign_key must reference a field on the related model.";
                        } else {
                            $field = $this->findField($targetModel, $foreignKey);
                            if (! in_array($field['type'] ?? null, ['foreign_id', 'integer'], true)) {
                                $errors[] = "{$relationPath}.foreign_key must use a foreign_id or integer field.";
                            }
                        }
                    } else {
                        if (! $this->isHandle($foreignKey)) {
                            $errors[] = "{$relationPath}.foreign_key must be a valid foreign key identifier.";
                        }
                    }
                } elseif ($type === 'belongsToMany' && ! $this->isHandle($relation['pivot_table'] ?? null)) {
                    $errors[] = "{$relationPath}.pivot_table must be a lowercase snake_case identifier.";
                }
            }
        }

        if (isset($blueprint['description']) && (! is_string($blueprint['description']) || strlen($blueprint['description']) > 1000)) {
            $errors[] = 'description must be a string of at most 1000 characters.';
        }

        if (isset($blueprint['domain']) && (! is_string($blueprint['domain']) || trim($blueprint['domain']) === '' || strlen($blueprint['domain']) > 120)) {
            $errors[] = 'domain must be a string of at most 120 characters.';
        }

        if (isset($blueprint['navigation']) && ! is_array($blueprint['navigation'])) {
            $errors[] = 'navigation must be a map.';
        }

        if (isset($blueprint['permissions'])) {
            if (! is_array($blueprint['permissions'])) {
                $errors[] = 'permissions must be a list.';
            } else {
                foreach ($blueprint['permissions'] as $pIdx => $perm) {
                    $slug = is_array($perm) ? ($perm['key'] ?? $perm['slug'] ?? '') : (is_string($perm) ? $perm : '');
                    if (empty($slug)) {
                        $errors[] = "permissions.{$pIdx} must be a valid permission string or object with slug.";
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new BlueprintValidationException($errors);
        }

        foreach ($models as &$model) {
            unset($model['_validated_fields'], $model['_path']);
        }
        unset($model);
        $blueprint['models'] = $models;

        return $blueprint;
    }

    private function isHandle(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,62}$/', $value) === 1;
    }

    private function checkKeys(array $value, array $allowed, string $path, array &$errors): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $unknown) {
            $errors[] = "{$path}.{$unknown} is not supported by blueprint schema version 1.";
        }
    }

    private function findField(array $model, string $handle): array
    {
        foreach ($model['fields'] ?? [] as $field) {
            if (($field['handle'] ?? null) === $handle) {
                return $field;
            }
        }

        return [];
    }
}
