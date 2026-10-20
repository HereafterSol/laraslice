<?php

namespace LaraSlice\Blueprint;

use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceModifier;
use LaraSlice\Generator\SliceName;
use LaraSlice\Generator\SliceSeederService;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;
use LaraSlice\Support\Directory;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/** Applies the deliberately narrow first version of validated blueprints. */
final class BlueprintApplier
{
    public function __construct(private readonly ?\Closure $tableExists = null) {}

    public function assertSupported(array $blueprint): void
    {
        $root = null;
        foreach ($blueprint['models'] as $model) {
            if (($model['root'] ?? false) === true) {
                $root = $model;
            }
        }
        if ($root === null) {
            throw new RuntimeException('The blueprint has no root model.');
        }
        $isCatalog = ($blueprint['taxonomy'] ?? false) === true
            || str_contains(strtolower($blueprint['name'] ?? ''), 'master')
            || str_contains(strtolower($blueprint['name'] ?? ''), 'catalog');

        if (! $isCatalog) {
            $rootClass = SliceName::canonical($blueprint['name']);
            $rootTable = Str::plural(Str::snake($rootClass));
            if (! empty($root['table']) && ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $root['table'])) {
                throw new RuntimeException("Blueprint apply requires a valid table name for the root model ({$root['table']}).");
            }

            $blueprintModelHandles = array_flip(array_column($blueprint['models'], 'handle'));
            foreach ($blueprint['models'] as $model) {
                if (($model['root'] ?? false) === true) {
                    continue;
                }
                $hasValidBelongsTo = false;
                foreach ($model['relations'] ?? [] as $relation) {
                    if ($relation['type'] === 'belongsTo' && (
                        $relation['model'] === $root['handle'] ||
                        isset($blueprintModelHandles[$relation['model'] ?? ''])
                    )) {
                        $hasValidBelongsTo = true;
                        break;
                    }
                }
                if (! $hasValidBelongsTo) {
                    throw new RuntimeException("Child model [{$model['handle']}] must have an inverse belongsTo relation to an aggregate model.");
                }
            }

            foreach ($root['relations'] ?? [] as $relation) {
                if ($relation['type'] === 'hasMany') {
                    $child = $this->model($blueprint['models'], $relation['model']);
                    if (empty($relation['name']) || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $relation['name'])) {
                        throw new RuntimeException("Blueprint apply requires a valid snake_case relation name for child table [{$child['table']}].");
                    }
                } elseif ($relation['type'] === 'belongsTo') {
                    if (empty($relation['name']) || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $relation['name'])) {
                        throw new RuntimeException('Blueprint apply requires a valid snake_case relation name for belongsTo relation.');
                    }
                } else {
                    throw new RuntimeException("Blueprint apply does not support root relation type [{$relation['type']}].");
                }
            }

            $childHandles = [];
            foreach ($root['relations'] ?? [] as $relation) {
                if ($relation['type'] === 'hasMany') {
                    $childHandles[$relation['model']] = $relation['foreign_key'];
                }
            }
            foreach ($blueprint['models'] as $model) {
                if (($model['root'] ?? false) === true) {
                    continue;
                }
                if (! isset($childHandles[$model['handle']])) {
                    $isNestedChild = false;
                    foreach ($blueprint['models'] as $pm) {
                        foreach ($pm['relations'] ?? [] as $pr) {
                            if ($pr['type'] === 'hasMany' && $pr['model'] === $model['handle']) {
                                $isNestedChild = true;
                                $childHandles[$model['handle']] = $pr['foreign_key'];
                                break 2;
                            }
                        }
                    }
                    if (! $isNestedChild) {
                        throw new RuntimeException("Child model [{$model['handle']}] must be related within the aggregate root.");
                    }
                }
                foreach ($model['fields'] ?? [] as $field) {
                    if ($field['handle'] === $childHandles[$model['handle']]) {
                        continue;
                    }
                    if (! in_array($field['type'], ['string', 'text', 'integer', 'bigInteger', 'decimal', 'float', 'boolean', 'date', 'datetime', 'timestamp', 'enum', 'json', 'email', 'url', 'foreign_id'], true)) {
                        throw new RuntimeException("Blueprint apply does not support child field type [{$field['type']}].");
                    }
                    if (($field['encrypted'] ?? false) === true) {
                        throw new RuntimeException("Blueprint apply supports encrypted fields on the root model only (child field [{$field['handle']}]).");
                    }
                }
            }
        }

        foreach ($root['fields'] ?? [] as $field) {
            if (! in_array($field['type'], ['string', 'text', 'integer', 'bigInteger', 'decimal', 'float', 'boolean', 'date', 'datetime', 'timestamp', 'enum', 'email', 'url', 'json', 'foreign_id'], true)) {
                throw new RuntimeException("Blueprint apply does not support root field type [{$field['type']}].");
            }
        }
    }

    public function apply(array $blueprint, array $plan, string $slicesPath, string $namespace, bool $allowOverwrite = false): string
    {
        $this->assertSupported($blueprint);
        if ((($plan['conflict'] ?? true) && ! $allowOverwrite) || ! hash_equals((string) ($plan['plan_hash'] ?? ''), $this->hashPlan($plan))) {
            throw new RuntimeException('The reviewed plan is stale or its destination is no longer available. Generate and review a fresh plan.');
        }

        if (! is_dir($slicesPath) && ! mkdir($slicesPath, 0755, true) && ! is_dir($slicesPath)) {
            throw new RuntimeException("Unable to create slices directory [{$slicesPath}].");
        }
        $target = $plan['target'];
        if (! $allowOverwrite && (file_exists($target) || is_link($target))) {
            throw new RuntimeException("Destination [{$target}] already exists; no files were changed.");
        }
        if ($this->tableExists !== null) {
            foreach ($plan['database_operations'] as $operation) {
                if ($operation['kind'] === 'create_table' && ($this->tableExists)($operation['table'])) {
                    throw new RuntimeException("Database table [{$operation['table']}] already exists; apply stopped before writing files.");
                }
            }
        }

        $models = $blueprint['models'];
        $root = current(array_filter($models, static fn (array $model): bool => ($model['root'] ?? false) === true));
        $belongsTo = [];
        foreach ($root['relations'] ?? [] as $relation) {
            if ($relation['type'] === 'belongsTo') {
                $relatedModel = $this->findModel($models, $relation['model']);
                $belongsTo[$relation['foreign_key']] = [
                    'relation' => ['name' => $relation['name'], 'model' => $relation['model']],
                    'references' => $relation['table'] ?? $relatedModel['table'] ?? null,
                ];
            }
        }

        $rootFields = [];
        foreach ($root['fields'] ?? [] as $field) {
            $type = $field['type'] === 'enum' ? 'select' : $field['type'];
            $required = $field['required'] ?? false;
            $rootFields[] = [
                'name' => $field['handle'],
                'label' => $field['label'] ?? Str::headline($field['handle']),
                'type' => $type,
                'required' => $required,
                'nullable' => $field['nullable'] ?? ! $required,
                'default' => $field['default'] ?? ($type === 'boolean' ? false : null),
                'options' => $field['options'] ?? [],
                'length' => $field['length'] ?? null,
                'encrypted' => $field['encrypted'] ?? false,
                'relation' => $belongsTo[$field['handle']]['relation'] ?? null,
                'references' => $belongsTo[$field['handle']]['references'] ?? null,
            ];
        }

        $staging = $slicesPath.DIRECTORY_SEPARATOR.'.laraslice-blueprint-'.bin2hex(random_bytes(8));
        $stagingSlices = $staging;
        try {
            $includeWorkflow = ! empty($blueprint['workflow']);
            $generatedSliceDir = (new SliceGenerator($stagingSlices, $namespace))->generate($blueprint['name'], $rootFields, $includeWorkflow, [
                'description' => $blueprint['description'] ?? '',
                'domain' => $blueprint['domain'] ?? null,
                'api' => true,
                'soft_deletes' => (bool) ($root['soft_deletes'] ?? false),
                'workflowConfig' => $blueprint['workflow'] ?? null,
                'roles' => $blueprint['roles'] ?? [],
            ]);

            foreach ($root['relations'] ?? [] as $relation) {
                if ($relation['type'] !== 'hasMany') {
                    continue;
                }
                $child = $this->model($models, $relation['model']);
                $childFields = [];
                foreach ($child['fields'] ?? [] as $field) {
                    if ($field['handle'] === $relation['foreign_key']) {
                        continue;
                    }
                    $required = $field['required'] ?? false;
                    $childFields[] = [
                        'name' => $field['handle'],
                        'label' => $field['label'] ?? Str::headline($field['handle']),
                        'type' => $field['type'],
                        'required' => $required,
                        'nullable' => $field['nullable'] ?? ! $required,
                        'default' => $field['default'] ?? null,
                        'options' => $field['options'] ?? [],
                    ];
                }
                (new SliceModifier($stagingSlices, $namespace))->addChildTable(
                    $blueprint['name'], $child['table'], 'hasMany', $childFields, $relation['foreign_key']
                );
            }

            $sliceDirectory = $generatedSliceDir;
            $this->normalizeManifest($sliceDirectory, $blueprint, $models);
            $this->lintPhpFiles($sliceDirectory);

            $renamed = false;
            if (! file_exists($target) && ! is_link($target)) {
                $parent = dirname($target);
                if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                    throw new RuntimeException("Unable to create parent directory for slice: {$parent}");
                }
                gc_collect_cycles();
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    if (@rename($sliceDirectory, $target)) {
                        $renamed = true;
                        break;
                    }
                    usleep(25000);
                }
                if (! $renamed) {
                    $renamed = Directory::copy($sliceDirectory, $target);
                }
            }

            if (! $renamed) {
                throw new RuntimeException("Unable to publish the generated slice to [{$target}].");
            }

            return $target;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            Directory::remove($staging);
        }
    }

    private function hashPlan(array $plan): string
    {
        unset($plan['plan_hash']);

        return hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function model(array $models, string $handle): array
    {
        foreach ($models as $model) {
            if ($model['handle'] === $handle) {
                return $model;
            }
        }
        throw new RuntimeException("Model [{$handle}] is missing from the validated blueprint.");
    }

    private function normalizeManifest(string $sliceDirectory, array $blueprint, array $models): void
    {
        $manifestPath = $sliceDirectory.DIRECTORY_SEPARATOR.'slice.yaml';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $root = current(array_filter($models, static fn (array $model): bool => ($model['root'] ?? false) === true));
        $manifest['blueprint'] = ['schema_version' => 1, 'handle' => $blueprint['handle']];
        $manifest['description'] = $blueprint['description'] ?? $manifest['description'];
        if (! empty($blueprint['domain'])) {
            $manifest['domain'] = $blueprint['domain'];
            $manifest['navigation'] ??= [];
            $manifest['navigation']['group'] = $blueprint['domain'];
            $domainSlug = Str::slug($blueprint['domain']);
            $pluralSnake = Str::snake($blueprint['name']);
            $manifest['navigation']['url'] ??= '/'.$domainSlug.'/'.$pluralSnake;
        }
        if (! empty($blueprint['navigation']) && is_array($blueprint['navigation'])) {
            $manifest['navigation'] = array_merge($manifest['navigation'] ?? [], $blueprint['navigation']);
        }
        // Persona gates from blueprint root (YAML-centric)
        if (! empty($blueprint['roles_gate']) && is_array($blueprint['roles_gate'])) {
            $manifest['navigation']['roles'] = $blueprint['roles_gate'];
        }
        if (! empty($blueprint['personas']) && is_array($blueprint['personas']) && empty($manifest['navigation']['roles'])) {
            $manifest['navigation']['roles'] = $blueprint['personas'];
        }
        // Settings / taxonomy never pollute main sidebar
        if (in_array(($blueprint['type'] ?? ''), ['settings_hub', 'settings', 'taxonomy'], true)) {
            $manifest['navigation']['visible'] = false;
        }
        if (! empty($blueprint['permissions']) && is_array($blueprint['permissions'])) {
            $manifest['permissions'] = $blueprint['permissions'];
        }
        if (! empty($blueprint['workflow'])) {
            $manifest['workflow'] = $blueprint['workflow'];
        }
        if (! empty($blueprint['roles'])) {
            $manifest['roles'] = $blueprint['roles'];
        }
        // UI directory (columns, filters, row_actions, bulk_actions) — Studio source of truth
        if (! empty($blueprint['ui']) && is_array($blueprint['ui'])) {
            $manifest['ui'] = $blueprint['ui'];
        }
        // Manifest version + append-only version_history (GitOps ledger in the file)
        $manifest['version'] = $blueprint['version'] ?? ($manifest['version'] ?? '1.0.0');
        $manifest['schema_version'] = $blueprint['schema_version'] ?? ($manifest['schema_version'] ?? 1);
        $history = is_array($manifest['version_history'] ?? null) ? $manifest['version_history'] : [];
        $history[] = [
            'version' => $manifest['version'],
            'at' => date('c'),
            'description' => 'Blueprint apply: models/workflow/ui synchronized to code + DB',
            'source' => 'blueprint_applier',
        ];
        // Keep last 50 entries
        $manifest['version_history'] = array_slice($history, -50);
        $manifest['tables'] = array_values(array_map(static fn (array $model): string => $model['table'], $models));
        $manifest['fields'] = ManifestRepository::fieldMap(array_map(static fn (array $field): array => [
            'name' => $field['handle'],
            'label' => $field['label'] ?? Str::headline($field['handle']),
            'type' => $field['type'],
            'nullable' => $field['nullable'] ?? ! ($field['required'] ?? false),
            'default' => $field['default'] ?? null,
            'options' => $field['options'] ?? [],
        ], $root['fields'] ?? []));
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);

        $yamlPath = $sliceDirectory.DIRECTORY_SEPARATOR.'slice.yaml';
        file_put_contents($yamlPath, Yaml::dump($blueprint, 10, 2), LOCK_EX);

        // Seed declared roles and demo users immediately
        if (! empty($manifest['roles']) && is_array($manifest['roles'])) {
            try {
                $seeder = new SliceSeederService;
                $seeder->seedCustomRolesAndUsers($manifest['roles']);
            } catch (Throwable) {
            }
        }

        // Synchronize workflow into database state machine engine
        if (! empty($manifest['workflow']) && is_array($manifest['workflow'])) {
            try {
                if (function_exists('app') && app()->bound(WorkflowEngineService::class)) {
                    $engine = app(WorkflowEngineService::class);
                    $rootModel = current(array_filter($models, static fn (array $model): bool => ($model['root'] ?? false) === true));
                    $modelClass = null;
                    if ($rootModel) {
                        $modelName = Str::studly(Str::singular($rootModel['handle'] ?? $blueprint['name']));
                        $domainPart = ! empty($blueprint['domain']) ? Str::studly(Str::slug($blueprint['domain'])).'\\' : '';
                        $modelClass = config('laraslice.slices_namespace', 'App\\Slices').'\\'.$domainPart.Str::studly($blueprint['name']).'\\Models\\'.$modelName;
                    }
                    $engine->syncWorkflowFromManifest($manifest['workflow'], $blueprint['name'], $modelClass);
                }
            } catch (Throwable) {
            }
        }

        try {
            if (function_exists('app') && app()->bound(SliceManager::class)) {
                app(SliceManager::class)->syncPermissions();
            }
        } catch (Throwable) {
        }
    }

    private function lintPhpFiles(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            try {
                token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE);
            } catch (\ParseError $exception) {
                throw new RuntimeException('Generated invalid PHP in '.$file->getPathname().': '.$exception->getMessage(), previous: $exception);
            }
        }
    }


    /** A model from this blueprint by handle, or null for external models. */
    private function findModel(array $models, string $handle): ?array
    {
        foreach ($models as $model) {
            if (($model['handle'] ?? null) === $handle) {
                return $model;
            }
        }

        return null;
    }
}
