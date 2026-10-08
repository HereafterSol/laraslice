<?php

namespace LaraSlice\Blueprint;

use Illuminate\Support\Str;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceModifier;
use RuntimeException;
use Throwable;

/** Applies the deliberately narrow first version of validated blueprints. */
final class BlueprintApplier
{
    public function __construct(private readonly ?\Closure $tableExists = null)
    {
    }

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
        $rootClass = \LaraSlice\Generator\SliceName::canonical($blueprint['name']);
        $rootTable = Str::plural(Str::snake($rootClass));
        if ($root['table'] !== $rootTable) {
            throw new RuntimeException("Blueprint apply v1 requires the root model table to match the slice name ({$rootTable}).");
        }


        foreach ($blueprint['models'] as $model) {
            if (($model['root'] ?? false) === true) {
                continue;
            }
            $hasRootBelongsTo = false;
            foreach ($model['relations'] ?? [] as $relation) {
                if ($relation['type'] === 'belongsTo' && $relation['model'] === $root['handle']) {
                    $hasRootBelongsTo = true;
                    break;
                }
            }
            if (! $hasRootBelongsTo) {
                throw new RuntimeException("Child model [{$model['handle']}] must have an inverse belongsTo relation to the root model [{$root['handle']}].");
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
                    throw new RuntimeException("Blueprint apply requires a valid snake_case relation name for belongsTo relation.");
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
                throw new RuntimeException('Blueprint apply supports only direct child models related to the root.');
            }
            if (($model['soft_deletes'] ?? false) === true) {
                throw new RuntimeException("Blueprint apply does not support soft_deletes on child model [{$model['handle']}].");
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

        foreach ($root['fields'] ?? [] as $field) {
            if (! in_array($field['type'], ['string', 'text', 'integer', 'bigInteger', 'decimal', 'float', 'boolean', 'date', 'datetime', 'timestamp', 'enum', 'email', 'url', 'json', 'foreign_id'], true)) {
                throw new RuntimeException("Blueprint apply does not support root field type [{$field['type']}].");
            }
        }
    }

    public function apply(array $blueprint, array $plan, string $slicesPath, string $namespace): string
    {
        $this->assertSupported($blueprint);
        if (($plan['conflict'] ?? true) || ! hash_equals((string) ($plan['plan_hash'] ?? ''), $this->hashPlan($plan))) {
            throw new RuntimeException('The reviewed plan is stale or its destination is no longer available. Generate and review a fresh plan.');
        }

        if (! is_dir($slicesPath) && ! mkdir($slicesPath, 0755, true) && ! is_dir($slicesPath)) {
            throw new RuntimeException("Unable to create slices directory [{$slicesPath}].");
        }
        $target = $plan['target'];
        if (file_exists($target) || is_link($target)) {
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

        $staging = $slicesPath . DIRECTORY_SEPARATOR . '.laraslice-blueprint-' . bin2hex(random_bytes(8));
        $stagingSlices = $staging;
        try {
            $generatedSliceDir = (new SliceGenerator($stagingSlices, $namespace))->generate($blueprint['name'], $rootFields, false, [
                'description' => $blueprint['description'] ?? '',
                'domain' => $blueprint['domain'] ?? null,
                'api' => true,
                'soft_deletes' => (bool) ($root['soft_deletes'] ?? false),
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
                    $renamed = $this->copyDirectory($sliceDirectory, $target);
                }
            }

            if (! $renamed) {
                throw new RuntimeException("Unable to publish the generated slice to [{$target}].");
            }

            return $target;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $this->removeDirectory($staging);
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
        $manifestPath = $sliceDirectory . DIRECTORY_SEPARATOR . 'slice.json';
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
            $manifest['navigation']['url'] ??= '/' . $domainSlug . '/' . $pluralSnake;
        }
        if (! empty($blueprint['navigation']) && is_array($blueprint['navigation'])) {
            $manifest['navigation'] = array_merge($manifest['navigation'] ?? [], $blueprint['navigation']);
        }
        if (! empty($blueprint['permissions']) && is_array($blueprint['permissions'])) {
            $manifest['permissions'] = $blueprint['permissions'];
        }
        $manifest['tables'] = array_values(array_map(static fn (array $model): string => $model['table'], $models));
        $manifest['fields'] = array_map(static fn (array $field): array => [
            'name' => $field['handle'],
            'label' => $field['label'] ?? Str::headline($field['handle']),
            'type' => $field['type'],
            'nullable' => $field['nullable'] ?? ! ($field['required'] ?? false),
            'default' => $field['default'] ?? null,
            'options' => $field['options'] ?? [],
        ], $root['fields'] ?? []);
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);

        $yamlPath = $sliceDirectory . DIRECTORY_SEPARATOR . 'slice.yaml';
        file_put_contents($yamlPath, \Symfony\Component\Yaml\Yaml::dump($blueprint, 10, 2), LOCK_EX);

        try {
            if (function_exists('app') && app()->bound(\LaraSlice\Core\Discovery\SliceManager::class)) {
                app(\LaraSlice\Core\Discovery\SliceManager::class)->syncPermissions();
            }
        } catch (\Throwable) {}
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
                throw new RuntimeException('Generated invalid PHP in ' . $file->getPathname() . ': ' . $exception->getMessage(), previous: $exception);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    private function copyDirectory(string $source, string $destination): bool
    {
        if (! is_dir($destination) && ! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            return false;
        }

        $dir = opendir($source);
        if ($dir === false) {
            return false;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $srcPath = $source . DIRECTORY_SEPARATOR . $file;
            $dstPath = $destination . DIRECTORY_SEPARATOR . $file;
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
        return true;
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
