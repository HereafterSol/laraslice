<?php

namespace LaraSlice\Blueprint;

use LaraSlice\Generator\BladeSafeText;
use LaraSlice\Schema\FieldType;

final class BlueprintValidator
{
    private const FIELD_TYPES = [
        'string', 'text', 'integer', 'bigInteger', 'foreign_id', 'decimal', 'float', 'boolean', 'date',
        'datetime', 'timestamp', 'enum', 'email', 'url', 'json',
    ];

    private const RELATION_TYPES = ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'];

    private const RESERVED_FIELDS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    public const ALLOWED_FORMATTERS = [
        'code_badge', 'composite_user', 'status_pill', 'money_breakdown', 'progress_bar', 'lines',
        'date_time', 'date_overdue', 'text', 'text_with_badge', 'number', 'money', 'badge',
    ];

    /** @param array<string, mixed> $blueprint
     * @return array<string, mixed> Validated blueprint.
     */
    public function validate(array $blueprint): array
    {
        $errors = [];
        $blueprint = $this->normalizeIncomingBlueprint($blueprint);

        $this->checkKeys($blueprint, [
            'schema_version', 'spec_version', 'name', 'handle', 'title', 'description', 'author', 'version',
            'models', 'fields', 'tenant', 'domain', 'app', 'module', 'super_domain', 'sub_domain',
            'table_prefix', 'multitenant', 'tenant_key', 'navigation', 'permissions', 'workflow', 'roles',
            'icon', 'priority', 'taxonomy', 'ui', 'dependencies', 'porting_notes', 'table_source',
            'enabled', 'table',
        ], 'blueprint', $errors);

        $sv = $blueprint['schema_version'] ?? null;
        $spec = $blueprint['spec_version'] ?? null;
        $okSchema = in_array($sv, [1, 2, '1', '2', 0.1, '0.1', '0.1.0'], true)
            || in_array((string) $spec, ['0.1', '0.1.0'], true);
        if (! $okSchema && $sv !== null) {
            $errors[] = 'schema_version must be 1, 2, or 0.1 (Slice YAML v0.1 via spec_version).';
        }
        if ($sv === null && $spec === null) {
            // default ok for legacy apply
            $blueprint['schema_version'] = 1;
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
            $this->checkKeys($model, ['handle', 'table', 'root', 'fields', 'relations', 'tenant', 'timestamps', 'soft_deletes', 'description', 'name', 'multitenant', 'tenant_key'], $path, $errors);

            if (isset($model['tenant']) && ! is_bool($model['tenant'])) {
                $errors[] = "{$path}.tenant must be a boolean.";
            } elseif (($model['tenant'] ?? false) === true) {
                $errors[] = "{$path}.tenant is not supported yet; remove it or set it to false.";
            }
            if (isset($model['timestamps']) && ! is_bool($model['timestamps'])) {
                $errors[] = "{$path}.timestamps must be a boolean.";
            } elseif (($model['timestamps'] ?? true) === false) {
                $errors[] = "{$path}.timestamps cannot be false; generated tables always have timestamps.";
            }
            if (isset($model['soft_deletes']) && ! is_bool($model['soft_deletes'])) {
                $errors[] = "{$path}.soft_deletes must be a boolean.";
            }

            $modelHandle = $model['handle'] ?? null;
            if (is_string($modelHandle) && preg_match('/^[A-Z][A-Za-z0-9_]{0,62}$/', $modelHandle)) {
                $modelHandle = \Illuminate\Support\Str::snake($modelHandle);
                $model['handle'] = $modelHandle;
            }
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
                $this->checkKeys($field, ['handle', 'label', 'type', 'required', 'nullable', 'default', 'options', 'options_from', 'encrypted', 'width', 'length', 'input_type', 'input', 'optionsText', 'hidden', 'searchable', 'name', 'show_in_form', 'show_in_list', 'rules', 'relation', 'span', 'is_searchable'], $fieldPath, $errors);

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
                    $errors[] = "{$fieldPath}.type must be one of: ".implode(', ', self::FIELD_TYPES).'.';
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
                } elseif (isset($field['label']) && ($problem = BladeSafeText::problem($field['label'], "{$fieldPath}.label"))) {
                    $errors[] = $problem;
                }

                if (isset($field['default']) && is_string($field['default']) && ($problem = BladeSafeText::problem($field['default'], "{$fieldPath}.default"))) {
                    $errors[] = $problem;
                }
                if (isset($field['default']) && ! is_scalar($field['default']) && $field['default'] !== null) {
                    $errors[] = "{$fieldPath}.default must be scalar or null.";
                } elseif (isset($field['default'], $field['type']) && ! $this->defaultMatchesType($field['default'], $field['type'])) {
                    $errors[] = "{$fieldPath}.default does not match the {$field['type']} type.";
                }

                $type = $field['type'] ?? null;
                if (isset($field['length'])) {
                    $length = $field['length'];
                    $validLength = match (true) {
                        in_array($type, ['string', 'email', 'url'], true) => is_int($length) && $length >= 1 && $length <= 65535,
                        in_array($type, ['decimal', 'float'], true) => is_string($length) && preg_match('/^\d{1,2}\s*,\s*\d{1,2}$/', $length) === 1,
                        default => false,
                    };
                    if (! $validLength) {
                        $errors[] = "{$fieldPath}.length must be 1-65535 for string, email and url fields, or \"precision,scale\" for decimal and float fields.";
                    }
                }
                if (($field['encrypted'] ?? false) === true && ! FieldType::isEncryptable($type)) {
                    $errors[] = "{$fieldPath}.encrypted is only supported for string, text, email, url and json fields.";
                }

                // Options belong to enum fields AND to fields rendered
                // as a select (type stays the storage type; the shipped
                // service-desk example pairs type: string + input: select
                // + options). An enum has no other option source, so its
                // map is required; a select input may instead be
                // relational (an FK select draws options from records),
                // so its map is validated when present, not required.
                $isEnum = ($field['type'] ?? null) === 'enum';
                $isSelect = ($field['input'] ?? null) === 'select';
                if ($isEnum || $isSelect) {
                    $options = $field['options'] ?? null;
                    if ($isEnum && (! is_array($options) || $options === [])) {
                        $errors[] = "{$fieldPath}.options must be a non-empty map for enum fields.";
                    } elseif (is_array($options)) {
                        foreach ($options as $value => $label) {
                            if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $value) || ! is_string($label) || $label === '' || ! BladeSafeText::isSafe($label)) {
                                $errors[] = "{$fieldPath}.options must map values (letters, numbers, _ . -) to non-empty labels.";
                                break;
                            }
                        }
                    }
                } elseif (isset($field['options'])) {
                    $errors[] = "{$fieldPath}.options is only supported for enum fields and select inputs.";
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

        $isCatalog = ($blueprint['taxonomy'] ?? false) === true
            || str_contains(strtolower($blueprint['name'] ?? ''), 'master')
            || str_contains(strtolower($blueprint['name'] ?? ''), 'catalog');

        if (! $isCatalog && $rootHandle !== null && isset($modelHandles[$rootHandle])) {
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
        // title, description and status may be redefined; the generator uses the definition instead of its default

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
                $this->checkKeys($relation, ['name', 'type', 'model', 'foreign_key', 'pivot_table', 'external', 'table'], $relationPath, $errors);
                if (isset($relation['external']) && ! is_bool($relation['external'])) {
                    $errors[] = "{$relationPath}.external must be a boolean.";
                }
                if (isset($relation['table']) && ! $this->isHandle($relation['table'])) {
                    $errors[] = "{$relationPath}.table must be a lowercase snake_case table name.";
                }

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
                    $errors[] = "{$relationPath}.type must be one of: ".implode(', ', self::RELATION_TYPES).'.';

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
                    if (! $this->isHandle($foreignKey)) {
                        $errors[] = "{$relationPath}.foreign_key must be a lowercase snake_case identifier.";
                    } elseif (isset($model['_validated_fields'][$foreignKey])) {
                        $field = $this->findField($model, $foreignKey);
                        if (! in_array($field['type'] ?? null, ['foreign_id', 'integer', 'bigInteger', 'string'], true)) {
                            $errors[] = "{$relationPath}.foreign_key must use a foreign_id or integer field.";
                        }
                    }
                } elseif (in_array($type, ['hasOne', 'hasMany'], true)) {
                    $foreignKey = $relation['foreign_key'] ?? null;
                    if (! $this->isHandle($foreignKey)) {
                        $errors[] = "{$relationPath}.foreign_key must be a lowercase snake_case identifier.";
                    } elseif ($isInternal) {
                        $targetModel = $models[$modelHandles[$targetHandle]] ?? [];
                        if (! isset($targetModel['_validated_fields'][$foreignKey])) {
                            $errors[] = "{$relationPath}.foreign_key must reference a field on the related model.";
                        } else {
                            $field = $this->findField($targetModel, $foreignKey);
                            if (! in_array($field['type'] ?? null, ['foreign_id', 'integer', 'bigInteger', 'string'], true)) {
                                $errors[] = "{$relationPath}.foreign_key must use a foreign_id or integer field.";
                            }
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

                if (isset($blueprint['ui'])) {
            $this->validateUiBlock($blueprint['ui'], $blueprint, $errors);
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
    /**
     * Validate an isolated sub-part of a blueprint.
     */
    public function validatePart(string $part, mixed $data): mixed
    {
        $errors = [];
        match ($part) {
            'ui' => $this->validateUiBlock($data, [], $errors, 'ui'),
            'directory' => $this->validateUiBlock(['directory' => $data], [], $errors, 'directory'),
            'forms' => $this->validateUiBlock(['forms' => $data], [], $errors, 'forms'),
            default => throw new \InvalidArgumentException("Unsupported part '{$part}'. Allowed: ui, directory, forms."),
        };

        if ($errors !== []) {
            throw new BlueprintValidationException($errors);
        }

        return $data;
    }

    /**
     * Validate the declarative UI presentation, directory, and form layouts.
     */
    public function validateUiBlock(mixed $ui, array $blueprint, array &$errors, string $path = 'ui'): void
    {
        if (! is_array($ui)) {
            $errors[] = "{$path} must be a map.";

            return;
        }

        $this->checkKeys($ui, ['presentation', 'directory', 'forms', 'workspace', 'dashboard', 'show'], $path, $errors);

        // 1. Presentation
        if (isset($ui['presentation'])) {
            if (! is_array($ui['presentation'])) {
                $errors[] = "{$path}.presentation must be a map.";
            } else {
                $this->checkKeys($ui['presentation'], ['create', 'edit', 'show', 'drawer_width'], "{$path}.presentation", $errors);
                foreach (['create', 'edit'] as $mode) {
                    if (isset($ui['presentation'][$mode]) && ! in_array($ui['presentation'][$mode], ['drawer', 'page'], true)) {
                        $errors[] = "{$path}.presentation.{$mode} must be either 'drawer' or 'page'.";
                    }
                }
            }
        }

        // 2. Directory
        if (isset($ui['directory'])) {
            if (! is_array($ui['directory'])) {
                $errors[] = "{$path}.directory must be a map.";
            } else {
                $this->checkKeys($ui['directory'], ['columns', 'quick_filters', 'sidebar_filters', 'row_actions', 'searchable', 'search_placeholder', 'summary_cards', 'bulk_actions', 'views', 'default_view', 'inline_edit'], "{$path}.directory", $errors);

                if (isset($ui['directory']['columns'])) {
                    if (! is_array($ui['directory']['columns'])) {
                        $errors[] = "{$path}.directory.columns must be a list.";
                    } else {
                        foreach ($ui['directory']['columns'] as $colIdx => $col) {
                            $colPath = "{$path}.directory.columns.{$colIdx}";
                            if (! is_array($col)) {
                                $errors[] = "{$colPath} must be a map.";

                                continue;
                            }
                            $this->checkKeys($col, ['handle', 'key', 'label', 'formatter', 'sortable', 'subtitle_handle', 'avatar_handle', 'badge_from', 'breakdown', 'inferred_from', 'type', 'class', 'html'], $colPath, $errors);
                            if (empty($col['handle']) && ! empty($col['key'])) {
                                $col['handle'] = $col['key'];
                            }
                            if (empty($col['handle']) || ! is_string($col['handle'])) {
                                $errors[] = "{$colPath}.handle must be a non-empty string.";
                            }
                            if (empty($col['label']) || ! is_string($col['label'])) {
                                $errors[] = "{$colPath}.label must be a non-empty string.";
                            }
                            if (isset($col['formatter']) && ! in_array($col['formatter'], self::ALLOWED_FORMATTERS, true)) {
                                $errors[] = "{$colPath}.formatter '{$col['formatter']}' is invalid. Allowed: ".implode(', ', self::ALLOWED_FORMATTERS).'.';
                            }
                            if (isset($col['sortable']) && ! is_bool($col['sortable'])) {
                                $errors[] = "{$colPath}.sortable must be a boolean.";
                            }
                        }
                    }
                }

                if (isset($ui['directory']['quick_filters'])) {
                    if (! is_array($ui['directory']['quick_filters'])) {
                        $errors[] = "{$path}.directory.quick_filters must be a list.";
                    } else {
                        foreach ($ui['directory']['quick_filters'] as $fIdx => $f) {
                            $fPath = "{$path}.directory.quick_filters.{$fIdx}";
                            if (! is_array($f)) {
                                $errors[] = "{$fPath} must be a map.";

                                continue;
                            }
                            $this->checkKeys($f, ['handle', 'label', 'is_searchable', 'options_from'], $fPath, $errors);
                            if (empty($f['handle']) || ! is_string($f['handle'])) {
                                $errors[] = "{$fPath}.handle must be a non-empty string.";
                            }
                            if (empty($f['label']) || ! is_string($f['label'])) {
                                $errors[] = "{$fPath}.label must be a non-empty string.";
                            }
                            if (isset($f['is_searchable']) && ! is_bool($f['is_searchable'])) {
                                $errors[] = "{$fPath}.is_searchable must be a boolean.";
                            }
                        }
                    }
                }

                if (isset($ui['directory']['sidebar_filters'])) {
                    if (! is_array($ui['directory']['sidebar_filters'])) {
                        $errors[] = "{$path}.directory.sidebar_filters must be a map or list.";
                    } else {
                        foreach ($ui['directory']['sidebar_filters'] as $fIdx => $f) {
                            $fPath = "{$path}.directory.sidebar_filters.{$fIdx}";
                            if (! is_array($f)) {
                                $errors[] = "{$fPath} must be a map.";

                                continue;
                            }
                            $this->checkKeys($f, ['handle', 'label', 'type', 'is_searchable', 'options_from'], $fPath, $errors);
                            if (empty($f['handle']) || ! is_string($f['handle'])) {
                                $errors[] = "{$fPath}.handle must be a non-empty string.";
                            }
                            if (empty($f['label']) || ! is_string($f['label'])) {
                                $errors[] = "{$fPath}.label must be a non-empty string.";
                            }
                        }
                    }
                }

                if (isset($ui['directory']['row_actions'])) {
                    if (! is_array($ui['directory']['row_actions'])) {
                        $errors[] = "{$path}.directory.row_actions must be a map.";
                    } else {
                        $this->checkKeys($ui['directory']['row_actions'], ['display', 'items'], "{$path}.directory.row_actions", $errors);
                        if (isset($ui['directory']['row_actions']['items']) && is_array($ui['directory']['row_actions']['items'])) {
                            foreach ($ui['directory']['row_actions']['items'] as $actIdx => $act) {
                                $actPath = "{$path}.directory.row_actions.items.{$actIdx}";
                                if (is_array($act)) {
                                    $this->checkKeys($act, ['handle', 'label', 'icon', 'placement', 'type', 'danger'], $actPath, $errors);
                                }
                            }
                        }
                    }
                }
            }
        }

        // 3. Forms
        if (isset($ui['forms'])) {
            if (! is_array($ui['forms'])) {
                $errors[] = "{$path}.forms must be a map.";
            } else {
                $this->checkKeys($ui['forms'], ['sections', 'presentation', 'mode', 'drawer_width'], "{$path}.forms", $errors);

                if (isset($ui['forms']['sections'])) {
                    if (! is_array($ui['forms']['sections'])) {
                        $errors[] = "{$path}.forms.sections must be a list or map.";
                    } else {
                        foreach ($ui['forms']['sections'] as $secIdx => $sec) {
                            $secPath = "{$path}.forms.sections.{$secIdx}";
                            if (! is_array($sec)) {
                                $errors[] = "{$secPath} must be a map.";

                                continue;
                            }
                            $this->checkKeys($sec, ['key', 'title', 'grid', 'fields'], $secPath, $errors);
                            if (empty($sec['key']) || ! is_string($sec['key'])) {
                                $errors[] = "{$secPath}.key must be a non-empty string.";
                            }
                            if (empty($sec['title']) || ! is_string($sec['title'])) {
                                $errors[] = "{$secPath}.title must be a non-empty string.";
                            }
                            $grid = $sec['grid'] ?? 3;
                            if (! is_int($grid) || ! in_array($grid, [1, 2, 3, 4], true)) {
                                $errors[] = "{$secPath}.grid must be an integer (1, 2, 3, or 4).";
                            }
                            if (isset($sec['fields'])) {
                                if (! is_array($sec['fields'])) {
                                    $errors[] = "{$secPath}.fields must be a list.";
                                } else {
                                    foreach ($sec['fields'] as $fldIdx => $fld) {
                                        $fldPath = "{$secPath}.fields.{$fldIdx}";
                                        if (! is_array($fld)) {
                                            $errors[] = "{$fldPath} must be a map.";

                                            continue;
                                        }
                                        $this->checkKeys($fld, ['handle', 'span', 'is_searchable', 'help', 'type', 'label', 'repeater_fields', 'add_button_text', 'model', 'table', 'foreign_key', 'document'], $fldPath, $errors);
                                        if (empty($fld['handle']) || ! is_string($fld['handle'])) {
                                            $errors[] = "{$fldPath}.handle must be a non-empty string.";
                                        }
                                        if (isset($fld['span'])) {
                                            if (! is_int($fld['span']) || $fld['span'] < 1 || $fld['span'] > 4) {
                                                $errors[] = "{$fldPath}.span must be an integer between 1 and 4.";
                                            }
                                        }
                                        if (isset($fld['is_searchable']) && ! is_bool($fld['is_searchable'])) {
                                            $errors[] = "{$fldPath}.is_searchable must be a boolean.";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // 4. Workspace (Tabs)
        if (isset($ui['workspace'])) {
            if (! is_array($ui['workspace'])) {
                $errors[] = "{$path}.workspace must be a map.";
            } else {
                $this->checkKeys($ui['workspace'], ['tabs'], "{$path}.workspace", $errors);
                if (isset($ui['workspace']['tabs']) && is_array($ui['workspace']['tabs'])) {
                    foreach ($ui['workspace']['tabs'] as $tIdx => $t) {
                        $tPath = "{$path}.workspace.tabs.{$tIdx}";
                        if (is_array($t)) {
                            $this->checkKeys($t, ['key', 'label', 'icon', 'relation', 'badge'], $tPath, $errors);
                        }
                    }
                }
            }
        }
    }

    /**
     * Accept Slice YAML v0.1 (spec_version) and hybrid pilot shapes; map to schema keys the applier expects.
     *
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>
     */
    private function normalizeIncomingBlueprint(array $blueprint): array
    {
        if (isset($blueprint['spec_version']) && ! isset($blueprint['schema_version'])) {
            $blueprint['schema_version'] = 1;
        }

        if (empty($blueprint['handle']) && ! empty($blueprint['name']) && is_string($blueprint['name'])) {
            $blueprint['handle'] = \Illuminate\Support\Str::snake($blueprint['name']);
        }

        // Map-style fields: → models[0].fields list
        if (empty($blueprint['models']) && ! empty($blueprint['fields']) && is_array($blueprint['fields'])) {
            $list = [];
            foreach ($blueprint['fields'] as $k => $f) {
                if (! is_array($f)) {
                    continue;
                }
                $handle = $f['handle'] ?? $f['name'] ?? (is_string($k) ? $k : null);
                if (! $handle) {
                    continue;
                }
                $list[] = array_merge($f, [
                    'handle' => $handle,
                    'label' => $f['label'] ?? \Illuminate\Support\Str::headline((string) $handle),
                    'type' => $f['type'] ?? 'string',
                    'required' => (bool) ($f['required'] ?? ! ($f['nullable'] ?? true)),
                ]);
            }
            $table = $blueprint['table'] ?? \Illuminate\Support\Str::snake(\Illuminate\Support\Str::plural($blueprint['handle'] ?? 'items'));
            $blueprint['models'] = [[
                'handle' => $blueprint['handle'] ?? 'item',
                'table' => is_string($table) ? $table : 'items',
                'root' => true,
                'fields' => $list,
            ]];
        }

        // Column key → handle; expand directory.views
        if (isset($blueprint['ui']['directory']['columns']) && is_array($blueprint['ui']['directory']['columns'])) {
            foreach ($blueprint['ui']['directory']['columns'] as $i => $col) {
                if (! is_array($col)) {
                    continue;
                }
                if (empty($col['handle']) && ! empty($col['key'])) {
                    $blueprint['ui']['directory']['columns'][$i]['handle'] = $col['key'];
                    $col['handle'] = $col['key'];
                }
                if (empty($col['label']) && ! empty($col['handle'] ?? $col['key'] ?? null)) {
                    $blueprint['ui']['directory']['columns'][$i]['label'] = \Illuminate\Support\Str::headline((string) ($col['handle'] ?? $col['key']));
                }
            }
        }

        // Field input alias
        if (isset($blueprint['models']) && is_array($blueprint['models'])) {
            foreach ($blueprint['models'] as $mi => $model) {
                if (! is_array($model['fields'] ?? null)) {
                    continue;
                }
                foreach ($model['fields'] as $fi => $field) {
                    if (! is_array($field)) {
                        continue;
                    }
                    if (isset($field['input']) && ! isset($field['input_type'])) {
                        $blueprint['models'][$mi]['fields'][$fi]['input_type'] = $field['input'];
                    }
                }
            }
        }

        return $blueprint;
    }

    private function isHandle(mixed $value): bool

    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,62}$/', $value) === 1;
    }

    private function defaultMatchesType(mixed $default, string $type): bool
    {
        return match ($type) {
            'integer', 'bigInteger', 'foreign_id' => is_int($default),
            'decimal', 'float' => is_int($default) || is_float($default) || (is_string($default) && is_numeric($default)),
            'boolean' => is_bool($default),
            default => is_string($default),
        };
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
