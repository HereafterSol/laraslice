<?php

namespace LaraSlice\Blueprint;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Ai\JevDecisionService;
use LaraSlice\Core\Security\Access;
use LaraSlice\Wizard\Middleware\AuthorizeStudio;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class BlueprintStudioController extends Controller
{
    public function __construct()
    {
        $this->middleware(AuthorizeStudio::class);
    }

    /**
     * Display the Blueprint Studio interface.
     */
    public function show(Request $request): mixed
    {
        $requestedSlice = $request->query('slice');
        // The value is used to build file paths and glob patterns, so allow plain slice names only
        if (! is_string($requestedSlice) || ! preg_match('/^[A-Za-z][A-Za-z0-9 _-]{0,80}$/', $requestedSlice)) {
            $requestedSlice = null;
        }
        $requestedTemplate = $request->query('template', 'service-desk');

        $source = '';
        $slicesPath = config('laraslice.slices_path', app_path('Slices'));

        // 1. If an existing slice is requested, try loading its slice.yaml
        if ($requestedSlice) {
            $sliceFile = $slicesPath.'/'.$requestedSlice.'/slice.yaml';
            if (! File::exists($sliceFile)) {
                $candidates = glob($slicesPath.'/*/'.$requestedSlice.'/slice.yaml') ?: [];
                if (! empty($candidates)) {
                    $sliceFile = $candidates[0];
                }
            }
            if (! File::exists($sliceFile) && File::isDirectory($slicesPath)) {
                $sliceDirName = basename($requestedSlice);
                foreach (File::allFiles($slicesPath) as $file) {
                    if ($file->getFilename() === 'slice.yaml' && strcasecmp(basename(dirname($file->getPathname())), $sliceDirName) === 0) {
                        $sliceFile = $file->getPathname();
                        break;
                    }
                }
            }

            if (File::exists($sliceFile)) {
                $source = File::get($sliceFile);
            } else {
                $jsonFile = $slicesPath.'/'.$requestedSlice.'/slice.json';
                if (! File::exists($jsonFile)) {
                    $candidates = glob($slicesPath.'/*/'.$requestedSlice.'/slice.json') ?: [];
                    if (! empty($candidates)) {
                        $jsonFile = $candidates[0];
                    }
                }
                if (! File::exists($jsonFile) && File::isDirectory($slicesPath)) {
                    $sliceDirName = basename($requestedSlice);
                    foreach (File::allFiles($slicesPath) as $file) {
                        if ($file->getFilename() === 'slice.json' && strcasecmp(basename(dirname($file->getPathname())), $sliceDirName) === 0) {
                            $jsonFile = $file->getPathname();
                            break;
                        }
                    }
                }
                if (File::exists($jsonFile)) {
                    $source = $this->manifestToYamlFile((string) $jsonFile, $requestedSlice);
                }
            }
        }

        // 2. Fallback to templates if no slice loaded
        if (empty($source)) {
            $source = $this->getTemplateContent($requestedTemplate);
        }

        // 3. Pre-parse YAML for high-fidelity visual designer rendering
        $blueprintData = null;
        if (! empty($source)) {
            try {
                $blueprintData = Yaml::parse($source);
            } catch (Throwable) {
                $blueprintData = null;
            }
        }

        // 4. Collect installed slices that have a slice.yaml or slice.json
        $installedSlices = $this->getInstalledSlices($slicesPath);

        // 5. Collect database tables for quick introspection
        $dbTables = $this->getDatabaseTables();

        return view('laraslice::blueprint-studio', [
            'source' => $source,
            'format' => 'yaml',
            'blueprintData' => $blueprintData,
            'installedSlices' => $installedSlices,
            'dbTables' => $dbTables,
            'currentSlice' => $requestedSlice,
            'currentTemplate' => $requestedTemplate,
            'allSlicesJson' => null,
            'activeSliceIdx' => 0,
        ]);
    }

    /**
     * Introspect an existing database table and return columns as schema fields.
     */
    public function introspect(Request $request): JsonResponse
    {
        $table = (string) $request->input('table');

        if (! $table || ! Schema::hasTable($table)) {
            return response()->json(['error' => "Table '{$table}' does not exist."], 404);
        }

        try {
            $columns = Schema::getColumns($table);
            $fields = [];

            foreach ($columns as $column) {
                $name = $column['name'] ?? '';
                if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                $typeName = strtolower($column['type_name'] ?? 'string');
                $type = match (true) {
                    str_contains($typeName, 'int') && ($column['type'] === 'tinyint(1)' || $typeName === 'bool' || $typeName === 'boolean') => 'boolean',
                    str_contains($typeName, 'int') => 'integer',
                    str_contains($typeName, 'text') => 'text',
                    str_contains($typeName, 'decimal') || str_contains($typeName, 'float') || str_contains($typeName, 'double') => 'decimal',
                    str_contains($typeName, 'date') && ! str_contains($typeName, 'time') => 'date',
                    str_contains($typeName, 'time') || str_contains($typeName, 'timestamp') => 'datetime',
                    str_contains($typeName, 'json') => 'json',
                    default => 'string',
                };

                $fields[] = [
                    'handle' => $name,
                    'label' => Str::headline($name),
                    'type' => $type,
                    'required' => ! ($column['nullable'] ?? false) && ($column['default'] === null),
                    'default' => $column['default'] ?? null,
                    'width' => in_array($type, ['text', 'json'], true) ? 100 : 50,
                ];
            }

            return response()->json([
                'table' => $table,
                'model' => Str::singular(Str::studly($table)),
                'fields' => $fields,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Plan blueprint generation and assess migration risk.
     */
    public function plan(
        Request $request,
        BlueprintLoader $loader,
        BlueprintValidator $validator,
        BlueprintPlanner $planner
    ): mixed {
        $input = $request->validate([
            'source' => ['required', 'string', 'max:1048576'],
            'format' => ['required', 'in:yaml,yml,json'],
            'slices_json' => ['nullable', 'string'],
            'active_slice_idx' => ['nullable', 'integer'],
        ]);

        $slicesPath = config('laraslice.slices_path', app_path('Slices'));
        $installedSlices = $this->getInstalledSlices($slicesPath);
        $dbTables = $this->getDatabaseTables();
        $allSlicesJson = $request->input('slices_json');
        $activeSliceIdx = (int) $request->input('active_slice_idx', 0);

        try {
            $blueprint = $validator->validate($loader->parse($input['source'], $input['format']));
            $plan = $planner->plan($blueprint, $slicesPath);
            $supported = true;
            $supportMessage = null;

            try {
                (new BlueprintApplier)->assertSupported($blueprint);
            } catch (Throwable $exception) {
                $supported = false;
                $supportMessage = $exception->getMessage();
            }

            $existingTables = [];
            foreach ($plan['database_operations'] as $operation) {
                if ($operation['kind'] === 'create_table' && Schema::hasTable($operation['table'])) {
                    $existingTables[] = $operation['table'];
                }
            }

            // Evaluate Migration Safety & Archetype via Jev Decision Service
            $jev = new JevDecisionService;
            $archetypeDecision = $jev->classifyArchetype($blueprint['description'] ?? $blueprint['name']);
            $riskDecision = $jev->evaluateMigrationRisk(
                $this->getDatabaseTables(),
                $plan['database_operations']
            );

            return view('laraslice::blueprint-studio', [
                'source' => $input['source'],
                'format' => $input['format'],
                'blueprint' => $blueprint,
                'plan' => $plan,
                'supported' => $supported,
                'supportMessage' => $supportMessage,
                'existingTables' => $existingTables,
                'installedSlices' => $installedSlices,
                'dbTables' => $dbTables,
                'archetypeDecision' => $archetypeDecision,
                'riskDecision' => $riskDecision,
                'allSlicesJson' => $allSlicesJson,
                'activeSliceIdx' => $activeSliceIdx,
            ]);
        } catch (Throwable $exception) {
            return view('laraslice::blueprint-studio', [
                'source' => $input['source'],
                'format' => $input['format'],
                'error' => $exception->getMessage(),
                'installedSlices' => $installedSlices,
                'dbTables' => $dbTables,
                'allSlicesJson' => $allSlicesJson,
                'activeSliceIdx' => $activeSliceIdx,
            ]);
        }
    }

    /**
     * Apply the reviewed blueprint and write slice files and slice.yaml.
     */
    public function apply(
        Request $request,
        BlueprintLoader $loader,
        BlueprintValidator $validator,
        BlueprintPlanner $planner
    ): mixed {
        $input = $request->validate([
            'source' => ['required', 'string', 'max:1048576'],
            'format' => ['required', 'in:yaml,yml,json'],
            'plan_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'confirm_apply' => ['accepted'],
            'auto_migrate' => ['nullable'],
            'slices_json' => ['nullable', 'string'],
            'active_slice_idx' => ['nullable', 'integer'],
        ]);

        $slicesPath = config('laraslice.slices_path', app_path('Slices'));
        $dbTables = $this->getDatabaseTables();
        $installedSlices = $this->getInstalledSlices($slicesPath);
        $allSlicesJson = $request->input('slices_json');
        $activeSliceIdx = (int) $request->input('active_slice_idx', 0);

        try {
            $blueprint = $validator->validate($loader->parse($input['source'], $input['format']));
            $plan = $planner->plan($blueprint, $slicesPath);

            if (! hash_equals($plan['plan_hash'], $input['plan_hash'])) {
                throw new RuntimeException('This review is out of date. Generate a new plan before applying.');
            }

            $target = (new BlueprintApplier(static fn (string $table): bool => Schema::hasTable($table)))
                ->apply($blueprint, $plan, $slicesPath, config('laraslice.slices_namespace', 'App\\Slices'));

            // Refresh installed slices list so the newly created slice appears immediately in the UI dropdown
            $installedSlices = $this->getInstalledSlices($slicesPath);

            // Auto-migration is opt-in and needs the separate studio.migrate permission
            $migrated = false;
            $migrationMessage = null;
            $mayMigrate = Access::allows($request->user(), ['studio.migrate', 'studio.*']);
            if ($request->boolean('auto_migrate', false) && ! $mayMigrate) {
                $migrationMessage = 'Migrations were not run: running migrations requires the studio.migrate permission.';
            } elseif ($request->boolean('auto_migrate', false)) {
                try {
                    Artisan::call('migrate', ['--force' => true]);
                    $migrationOutput = trim(Artisan::output());
                    $migrated = true;
                    $migrationMessage = ! empty($migrationOutput) ? $migrationOutput : 'Database migrations applied.';
                } catch (Throwable $e) {
                    $migrationMessage = 'Migration warning: '.$e->getMessage();
                }
            }

            // Calculate domain-scoped URL for the newly generated slice
            $domain = $blueprint['domain'] ?? null;
            $domainSlug = $domain ? Str::slug($domain) : null;
            $sliceSnake = Str::snake($blueprint['name']);
            $sliceRoutePath = ($domainSlug ? "{$domainSlug}/{$sliceSnake}" : $sliceSnake);
            $sliceUrl = url('/'.$sliceRoutePath);

            $successMsg = "Successfully generated vertical slice at {$target} with persisted slice.yaml!";
            if ($migrated) {
                $successMsg .= ' Database migrations were automatically executed.';
            } else {
                $successMsg .= " Click '⚡ Run Migrations' or run 'php artisan migrate' to apply database tables.";
            }

            return view('laraslice::blueprint-studio', [
                'source' => $input['source'],
                'format' => $input['format'],
                'success' => $successMsg,
                'sliceUrl' => $sliceUrl,
                'migrated' => $migrated,
                'migrationMessage' => $migrationMessage,
                'installedSlices' => $installedSlices,
                'dbTables' => $this->getDatabaseTables(),
                'currentSlice' => basename($target),
                'allSlicesJson' => $allSlicesJson,
                'activeSliceIdx' => $activeSliceIdx,
            ]);
        } catch (Throwable $exception) {
            return view('laraslice::blueprint-studio', [
                'source' => $input['source'],
                'format' => $input['format'],
                'error' => $exception->getMessage(),
                'installedSlices' => $installedSlices,
                'dbTables' => $dbTables,
                'allSlicesJson' => $allSlicesJson,
                'activeSliceIdx' => $activeSliceIdx,
            ]);
        }
    }

    /**
     * Run pending database migrations on demand from Blueprint Studio.
     */
    public function runMigrations(Request $request): mixed
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = trim(Artisan::output());
            $message = ! empty($output) ? $output : 'Database migrations executed successfully.';

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => $message]);
            }

            return redirect()->route('laraslice.wizard.blueprint')
                ->with('success', "⚡ Migrations executed successfully: {$message}");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }

            return redirect()->route('laraslice.wizard.blueprint')
                ->with('error', 'Migration failed: '.$e->getMessage());
        }
    }

    /**
     * Retrieve list of installed slices that have a slice.yaml.
     *
     * @return array<int, array{name: string, has_yaml: bool, path: string}>
     */
    private function getInstalledSlices(string $slicesPath): array
    {
        $installedSlices = [];
        if (File::isDirectory($slicesPath)) {
            foreach (File::directories($slicesPath) as $dir) {
                $sliceName = basename($dir);
                $yamlPath = $dir.'/slice.yaml';
                if (File::exists($yamlPath) || File::exists($dir.'/slice.json')) {
                    $installedSlices[] = [
                        'name' => $sliceName,
                        'has_yaml' => File::exists($yamlPath),
                        'path' => $yamlPath,
                    ];
                } else {
                    $domainName = basename($dir);
                    foreach (File::directories($dir) as $subDir) {
                        $subSliceName = basename($subDir);
                        $subYamlPath = $subDir.'/slice.yaml';
                        if (File::exists($subYamlPath) || File::exists($subDir.'/slice.json')) {
                            $installedSlices[] = [
                                'name' => $subSliceName,
                                'domain' => $domainName,
                                'has_yaml' => File::exists($subYamlPath),
                                'path' => $subYamlPath,
                            ];
                        }
                    }
                }
            }
        }

        return $installedSlices;
    }

    /**
     * Retrieve list of table names from current database connection.
     *
     * @return array<string>
     */
    private function getDatabaseTables(): array
    {
        try {
            $currentDb = config('database.connections.'.config('database.default').'.database');
            $tables = Schema::getTables();
            $result = [];
            foreach ($tables as $t) {
                $schema = is_array($t) ? ($t['schema'] ?? '') : ($t->schema ?? '');
                $name = is_array($t) ? ($t['name'] ?? '') : ($t->name ?? '');
                if ($name === '') {
                    continue;
                }
                if ($schema === '' || $schema === $currentDb) {
                    $result[] = $name;
                }
            }

            return array_values(array_unique($result));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Built-in studio templates (blueprints/templates), by name and alias.
     */
    private const TEMPLATES = [
        'hr-module' => 'hr-module',
        'departments' => 'hr-module',
        'shop-categories' => 'shop-categories',
        'categories' => 'shop-categories',
        'shop' => 'shop',
        'shop-products' => 'shop',
        'ecommerce_suite' => 'shop',
        'shop-orders' => 'shop-orders',
        'crm' => 'crm',
        'companies' => 'crm',
        'crm_suite' => 'crm',
        'billing_suite' => 'billing',
        'invoices' => 'billing',
        'blog' => 'blog',
        'articles' => 'blog',
    ];

    /**
     * The YAML of a built-in template; unknown names fall back to the service-desk example.
     */
    private function getTemplateContent(string $template): string
    {
        $root = dirname(__DIR__, 2).'/blueprints';
        $path = isset(self::TEMPLATES[$template])
            ? $root.'/templates/'.self::TEMPLATES[$template].'.slice.yaml'
            : $root.'/examples/service-desk.slice.yaml';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Convert an existing slice.json manifest into a valid slice.yaml representation.
     */
    private function manifestToYamlFile(string $jsonFile, string $sliceName): string
    {
        try {
            $manifest = json_decode((string) File::get($jsonFile), true) ?: [];
            $rootHandle = Str::singular(Str::snake($sliceName));
            $rootTable = $manifest['tables'][0] ?? Str::plural($rootHandle);
            $fields = [];
            foreach ($manifest['fields'] ?? [] as $k => $f) {
                $fHandle = is_string($k) ? $k : ($f['name'] ?? 'field');
                $fields[] = [
                    'handle' => $fHandle,
                    'label' => $f['label'] ?? Str::headline($fHandle),
                    'type' => $f['type'] ?? 'string',
                    'required' => ! ($f['nullable'] ?? true),
                ];
            }

            // Map relations defined on manifest
            $rootRelations = [];
            foreach ($manifest['relations'] ?? [] as $rel) {
                $targetModel = Str::singular(Str::snake($rel['model'] ?? $rel['table'] ?? ''));
                $relName = $rel['method'] ?? $rel['name'] ?? Str::camel($rel['table'] ?? '');
                $rootRelations[] = [
                    'name' => $relName,
                    'type' => $rel['type'] ?? 'hasMany',
                    'model' => $targetModel,
                    'foreign_key' => $rel['foreign_key'] ?? ($rootHandle.'_id'),
                ];
            }

            // Auto-detect belongsTo relationships from foreign_id fields
            foreach ($fields as $f) {
                if ($f['type'] === 'foreign_id' || str_ends_with($f['handle'], '_id')) {
                    $relModel = Str::singular(Str::snake(preg_replace('/_id$/', '', $f['handle'])));
                    $relName = Str::camel(preg_replace('/_id$/', '', $f['handle']));
                    $alreadyAdded = false;
                    foreach ($rootRelations as $rr) {
                        if (($rr['foreign_key'] ?? '') === $f['handle']) {
                            $alreadyAdded = true;
                            break;
                        }
                    }
                    if (! $alreadyAdded) {
                        $rootRelations[] = [
                            'name' => $relName,
                            'type' => 'belongsTo',
                            'model' => $relModel,
                            'foreign_key' => $f['handle'],
                        ];
                    }
                }
            }

            $models = [
                [
                    'handle' => $rootHandle,
                    'table' => $rootTable,
                    'root' => true,
                    'timestamps' => true,
                    'fields' => $fields,
                    'relations' => $rootRelations,
                ],
            ];

            // Introspect additional tables / child entities
            $tables = $manifest['tables'] ?? [];
            for ($i = 1; $i < count($tables); $i++) {
                $childTable = $tables[$i];
                $childHandle = Str::singular(Str::snake($childTable));
                $childFields = [];
                $childRelations = [];

                if (Schema::hasTable($childTable)) {
                    try {
                        $columns = Schema::getColumns($childTable);
                        foreach ($columns as $col) {
                            $cName = $col['name'] ?? '';
                            if (in_array($cName, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                                continue;
                            }
                            $cTypeName = strtolower($col['type_name'] ?? '');
                            $cType = match (true) {
                                str_contains($cTypeName, 'int') && ($col['type'] === 'tinyint(1)' || str_contains($cTypeName, 'bool')) => 'boolean',
                                str_contains($cTypeName, 'int') && str_ends_with($cName, '_id') => 'foreign_id',
                                str_contains($cTypeName, 'int') => 'integer',
                                str_contains($cTypeName, 'dec') || str_contains($cTypeName, 'float') || str_contains($cTypeName, 'double') => 'decimal',
                                str_contains($cTypeName, 'text') => 'text',
                                str_contains($cTypeName, 'date') || str_contains($cTypeName, 'time') => 'datetime',
                                default => 'string',
                            };

                            $childFields[] = [
                                'handle' => $cName,
                                'label' => Str::headline($cName),
                                'type' => $cType,
                                'required' => ! ($col['nullable'] ?? true),
                            ];

                            if (str_ends_with($cName, '_id')) {
                                $targetModel = Str::singular(Str::snake(preg_replace('/_id$/', '', $cName)));
                                $childRelations[] = [
                                    'name' => Str::camel(preg_replace('/_id$/', '', $cName)),
                                    'type' => 'belongsTo',
                                    'model' => $targetModel,
                                    'foreign_key' => $cName,
                                ];
                            }
                        }
                    } catch (Throwable) {
                    }
                }

                if (empty($childFields)) {
                    $childFields[] = [
                        'handle' => $rootHandle.'_id',
                        'label' => Str::headline($rootHandle),
                        'type' => 'foreign_id',
                        'required' => true,
                    ];
                    $childRelations[] = [
                        'name' => Str::camel($rootHandle),
                        'type' => 'belongsTo',
                        'model' => $rootHandle,
                        'foreign_key' => $rootHandle.'_id',
                    ];
                }

                $models[] = [
                    'handle' => $childHandle,
                    'table' => $childTable,
                    'root' => false,
                    'timestamps' => true,
                    'fields' => $childFields,
                    'relations' => $childRelations,
                ];
            }

            $perms = $manifest['permissions'] ?? [];
            if (empty($perms)) {
                $perms = [
                    "{$rootHandle}.view",
                    "{$rootHandle}.create",
                    "{$rootHandle}.edit",
                    "{$rootHandle}.delete",
                ];
            }

            $bp = [
                'schema_version' => 1,
                'name' => $manifest['name'] ?? Str::headline($sliceName),
                'handle' => Str::snake($sliceName),
                'domain' => $manifest['domain'] ?? null,
                'author' => $manifest['author'] ?? 'LaraSlice Team',
                'version' => $manifest['version'] ?? '1.0.0',
                'description' => $manifest['description'] ?? '',
                'permissions' => $perms,
                'models' => $models,
            ];

            return Yaml::dump(array_filter($bp), 10, 2);
        } catch (Throwable) {
            return '';
        }
    }
}
