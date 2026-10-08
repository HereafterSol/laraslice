<?php

namespace LaraSlice\Blueprint;

use Illuminate\Support\Str;
use LaraSlice\Generator\SliceName;

final class BlueprintPlanner
{
    /** @param array<string, mixed> $blueprint
     *  @return array<string, mixed>
     */
    public function plan(array $blueprint, string $slicesPath): array
    {
        $blueprint = (new BlueprintValidator())->validate($blueprint);
        $sliceClass = SliceName::canonical((string) $blueprint['name']);
        $domain = isset($blueprint['domain']) && is_string($blueprint['domain']) && trim($blueprint['domain']) !== ''
            ? trim($blueprint['domain'])
            : null;
        $domainFolder = $domain ? SliceName::domainSegment($domain) : null;
        $sliceDirectory = $domainFolder
            ? ($domainFolder . DIRECTORY_SEPARATOR . Str::plural($sliceClass))
            : Str::plural($sliceClass);
        $sliceTarget = rtrim($slicesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sliceDirectory;
        $models = $blueprint['models'];
        $root = current(array_filter($models, static fn (array $model): bool => ($model['root'] ?? false) === true));

        $files = [
            'slice.json',
            'slice.yaml',
            'Models/' . $sliceClass . '.php',
            'Schemas/' . $sliceClass . 'Schema.php',
            'Migrations/{timestamp}_create_' . Str::plural(Str::snake($sliceClass)) . '_table.php',
            'Contracts/' . $sliceClass . 'FormBusinessObject.php',
            'Contracts/' . $sliceClass . 'ListingBusinessObject.php',
            'Contracts/' . $sliceClass . 'FilterBusinessObject.php',
            'Services/' . $sliceClass . 'SliceService.php',
            'Controllers/' . $sliceClass . 'WebController.php',
            'Controllers/' . $sliceClass . 'ApiController.php',
            'Routes/web.php',
            'Routes/api.php',
            'Resources/views/index.blade.php',
            'Resources/views/form.blade.php',
        ];
        $fileChanges = [];

        foreach ($models as $model) {
            if (($model['root'] ?? false) === true) {
                continue;
            }

            $childClass = Str::studly(Str::singular($model['table']));
            $childPlural = Str::plural($childClass);
            $childPluralSnake = Str::snake($childPlural);
            foreach ([
                "Models/{$childClass}.php",
                "Contracts/{$childClass}FormBusinessObject.php",
                "Contracts/{$childClass}ListingBusinessObject.php",
                "Contracts/{$childClass}FilterBusinessObject.php",
                "Services/{$childClass}SliceService.php",
                "Controllers/{$childClass}WebController.php",
                "Controllers/{$childClass}ApiController.php",
                "Migrations/{timestamp}_create_{$model['table']}_table.php",
                "Resources/views/{$childPluralSnake}/index.blade.php",
                "Resources/views/{$childPluralSnake}/form.blade.php",
                "Resources/views/{$childPluralSnake}/create.blade.php",
                "Resources/views/{$childPluralSnake}/edit.blade.php",
            ] as $file) {
                $files[] = $file;
            }
        }

        $operations = [];
        foreach ($models as $model) {
            $table = $model['table'];
            $columns = [[
                'column' => 'id',
                'type' => 'id',
                'nullable' => false,
                'default' => null,
            ]];
            if (($model['root'] ?? false) === true) {
                $columns = array_merge($columns, [
                    ['column' => 'title', 'type' => 'string', 'nullable' => false, 'default' => null],
                    ['column' => 'description', 'type' => 'text', 'nullable' => true, 'default' => null],
                    ['column' => 'status', 'type' => 'string', 'nullable' => false, 'default' => 'draft'],
                ]);
            }
            foreach ($model['fields'] ?? [] as $field) {
                $storageType = match ($field['type']) {
                    'enum', 'email', 'url' => 'string',
                    default => $field['type'],
                };
                $required = $field['required'] ?? false;
                $columns[] = [
                    'column' => $field['handle'],
                    'type' => $storageType,
                    'field_type' => $field['type'],
                    'nullable' => $field['nullable'] ?? ! $required,
                    'required' => $required,
                    'default' => $field['default'] ?? ($field['type'] === 'boolean' ? false : null),
                    'options' => $field['options'] ?? [],
                ];
            }
            foreach ($model['relations'] ?? [] as $relation) {
                if (in_array($relation['type'], ['hasOne', 'hasMany'], true)) {
                    $targetModel = $this->findModel($models, $relation['model']);
                    $operations[] = [
                        'kind' => 'foreign_key',
                        'table' => $targetModel['table'],
                        'column' => $relation['foreign_key'],
                        'references' => $table . '.id',
                    ];
                } elseif ($relation['type'] === 'belongsTo') {
                    if (! in_array($relation['foreign_key'], array_column($columns, 'column'), true)) {
                        $columns[] = [
                            'column' => $relation['foreign_key'],
                            'type' => 'foreign_id',
                            'nullable' => false,
                            'default' => null,
                        ];
                    }
                    $operations[] = [
                        'kind' => 'foreign_key',
                        'table' => $table,
                        'column' => $relation['foreign_key'],
                        'references' => $this->findModel($models, $relation['model'])['table'] . '.id',
                    ];
                } elseif ($relation['type'] === 'belongsToMany') {
                    $operations[] = [
                        'kind' => 'pivot_table',
                        'table' => $relation['pivot_table'],
                        'models' => [$table, $this->findModel($models, $relation['model'])['table']],
                    ];
                }
            }

            $operations[] = [
                'kind' => 'create_table',
                'table' => $table,
                'columns' => array_merge($columns, [
                    ['column' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null],
                    ['column' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'default' => null],
                ]),
            ];
        }

        $uniqueOperations = [];
        foreach ($operations as $operation) {
            $key = json_encode($operation, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $uniqueOperations[$key] = $operation;
        }
        $operations = array_values($uniqueOperations);

        sort($files);
        $modelOrder = [];
        foreach ($models as $index => $model) {
            $modelOrder[$model['table']] = $index;
        }
        usort($operations, static function (array $a, array $b) use ($modelOrder): int {
            $order = ['create_table' => 0, 'pivot_table' => 1, 'foreign_key' => 2];
            $aOrder = $modelOrder[$a['table']] ?? PHP_INT_MAX;
            $bOrder = $modelOrder[$b['table']] ?? PHP_INT_MAX;
            return [$order[$a['kind']] ?? 99, $aOrder, $a['table']] <=> [$order[$b['kind']] ?? 99, $bOrder, $b['table']];
        });

        $files = array_map(static fn (string $file): string => str_replace('/', DIRECTORY_SEPARATOR, $file), $files);

        foreach ($models as $model) {
            $sourceClass = ($model['root'] ?? false) === true
                ? $sliceClass
                : Str::studly(Str::singular($model['table']));
            foreach ($model['relations'] ?? [] as $relation) {
                if (in_array($relation['type'], ['hasOne', 'hasMany', 'belongsToMany'], true)) {
                    $fileChanges[] = [
                        'path' => $sliceDirectory . DIRECTORY_SEPARATOR . 'Models/' . $sourceClass . '.php',
                        'reason' => "Add {$relation['type']} relation '{$relation['name']}'.",
                    ];
                }
                if (in_array($relation['type'], ['hasOne', 'hasMany'], true)) {
                    $childClass = Str::studly(Str::singular($this->findModel($models, $relation['model'])['table']));
                    $fileChanges[] = [
                        'path' => $sliceDirectory . DIRECTORY_SEPARATOR . 'Models/' . $childClass . '.php',
                        'reason' => 'Add inverse belongsTo relation and parent foreign key.',
                    ];
                }
            }
        }
        $fileChanges = array_map(static function (array $change): array {
            $change['path'] = str_replace('/', DIRECTORY_SEPARATOR, $change['path']);
            return $change;
        }, $fileChanges);

        $plan = [
            'blueprint' => [
                'name' => $blueprint['name'],
                'handle' => $blueprint['handle'],
                'schema_version' => $blueprint['schema_version'],
            ],
            'target' => $sliceTarget,
            'conflict' => file_exists($sliceTarget) || is_link($sliceTarget),
            'root_model' => $root['handle'] ?? null,
            'models' => array_map(static fn (array $model): array => [
                'handle' => $model['handle'],
                'table' => $model['table'],
                'field_count' => count($model['fields'] ?? []),
                'relation_count' => count($model['relations'] ?? []),
            ], $models),
            'database_operations' => $operations,
            'files' => array_map(static fn (string $file): string => $sliceDirectory . DIRECTORY_SEPARATOR . $file, $files),
            'file_changes' => $fileChanges,
            'execution' => 'plan_only_no_files_or_database_changes',
            'generation_status' => 'requires_apply_capability_check',
        ];
        $plan['plan_hash'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $plan;
    }

    private function findModel(array $models, string $handle): array
    {
        foreach ($models as $model) {
            if ($model['handle'] === $handle) {
                return $model;
            }
        }

        return [
            'handle' => $handle,
            'table'  => Str::plural(Str::snake($handle)),
        ];
    }
}
