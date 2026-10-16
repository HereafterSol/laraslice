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
use LaraSlice\Generator\SliceName;
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

        // 6. Preload default architecture plan if available
        $portPlan = null;
        $portPlanFile = base_path('docs/porting-architecture-plan.json');
        if (File::exists($portPlanFile)) {
            try {
                $portPlan = json_decode(File::get($portPlanFile), true);
            } catch (Throwable) {}
        }

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
            'portPlan' => $portPlan,
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
            'plan_scope' => ['nullable', 'in:domain,slice'],
        ]);

        $slicesPath = config('laraslice.slices_path', app_path('Slices'));
        $installedSlices = $this->getInstalledSlices($slicesPath);
        $dbTables = $this->getDatabaseTables();
        $allSlicesJson = $request->input('slices_json');
        $activeSliceIdx = (int) $request->input('active_slice_idx', 0);
        $planScope = $request->input('plan_scope');

        $slicesList = [];
        if (! empty($allSlicesJson)) {
            try {
                $decoded = json_decode($allSlicesJson, true);
                if (is_array($decoded) && count($decoded) > 0) {
                    $slicesList = $decoded;
                }
            } catch (Throwable) {}
        }

        if ($planScope === null) {
            $planScope = count($slicesList) > 1 ? 'domain' : 'slice';
        }

        try {
            if ($planScope === 'domain' && count($slicesList) > 1) {
                // Execute domain-wide plan for all slices in the suite
                $slicePlans = [];
                $allCompiledModels = [];
                $allDatabaseOps = [];
                $allFiles = [];
                $allExistingTables = [];
                $allSupported = true;
                $allSupportMessages = [];
                $anyConflict = false;
                $domainName = null;
                $applier = new BlueprintApplier(static fn (string $table): bool => Schema::hasTable($table));

                foreach ($slicesList as $sIdx => $sl) {
                    $sliceName = $sl['name'] ?? "Slice ".($sIdx + 1);
                    $sliceSource = $sl['source'] ?? '';
                    if (empty($sliceSource) && isset($sl['blueprint'])) {
                        $sliceSource = Yaml::dump($sl['blueprint'], 4, 2);
                    }

                    $sliceParsed = $loader->parse($sliceSource, 'yaml');
                    if (isset($sliceParsed['models']) && is_array($sliceParsed['models'])) {
                        foreach ($sliceParsed['models'] as &$m) {
                            if (isset($m['handle']) && is_string($m['handle'])) {
                                $m['handle'] = Str::snake($m['handle']);
                            }
                        }
                        unset($m);
                    }

                    $validBp = $validator->validate($sliceParsed);
                    if (! $domainName && ! empty($validBp['domain'])) {
                        $domainName = $validBp['domain'];
                    }

                    $sPlan = $planner->plan($validBp, $slicesPath);
                    $sSupported = true;
                    $sSupportMessage = null;

                    try {
                        $applier->assertSupported($validBp);
                    } catch (Throwable $e) {
                        $sSupported = false;
                        $sSupportMessage = $e->getMessage();
                        $allSupported = false;
                        $allSupportMessages[] = "[{$sliceName}]: {$sSupportMessage}";
                    }

                    if ($sPlan['conflict']) {
                        $anyConflict = true;
                    }

                    foreach ($sPlan['database_operations'] as $op) {
                        if ($op['kind'] === 'create_table' && Schema::hasTable($op['table'])) {
                            $allExistingTables[] = $op['table'];
                        }
                        $allDatabaseOps[] = $op;
                    }

                    foreach ($sPlan['files'] as $f) {
                        $allFiles[] = $f;
                    }

                    foreach ($sPlan['models'] as $m) {
                        $m['slice_name'] = $sliceName;
                        $m['slice_handle'] = $validBp['handle'] ?? Str::snake($sliceName);
                        $allCompiledModels[] = $m;
                    }

                    $slicePlans[] = [
                        'name' => $sliceName,
                        'handle' => $validBp['handle'] ?? Str::snake($sliceName),
                        'target' => $sPlan['target'],
                        'conflict' => $sPlan['conflict'],
                        'supported' => $sSupported,
                        'support_message' => $sSupportMessage,
                        'models_count' => count($sPlan['models']),
                        'files_count' => count($sPlan['files']),
                        'database_operations_count' => count($sPlan['database_operations']),
                        'plan_hash' => $sPlan['plan_hash'],
                        'models' => $sPlan['models'],
                        'files' => $sPlan['files'],
                        'database_operations' => $sPlan['database_operations'],
                    ];
                }

                // Deduplicate operations
                $uniqueOps = [];
                foreach ($allDatabaseOps as $op) {
                    $k = json_encode($op, JSON_UNESCAPED_SLASHES);
                    $uniqueOps[$k] = $op;
                }
                $allDatabaseOps = array_values($uniqueOps);
                $allExistingTables = array_values(array_unique($allExistingTables));
                $allFiles = array_values(array_unique($allFiles));

                $domainName = $domainName ?: ($slicesList[0]['domain'] ?? 'Domain');
                $domainFolder = SliceName::domainSegment($domainName);
                $domainTarget = rtrim($slicesPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$domainFolder;

                $jev = new JevDecisionService;
                $archetypeDecision = $jev->classifyArchetype("Domain Suite for {$domainName} containing ".count($slicesList)." vertical slices");
                $riskDecision = $jev->evaluateMigrationRisk(
                    $this->getDatabaseTables(),
                    $allDatabaseOps
                );

                $domainPlanHash = hash('sha256', json_encode([
                    'domain' => $domainName,
                    'slices' => array_column($slicePlans, 'plan_hash'),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                $plan = [
                    'is_domain_plan' => true,
                    'domain_name' => $domainName,
                    'slices_count' => count($slicesList),
                    'target' => $domainTarget.' ('.count($slicesList).' Slices in ['.$domainName.'])',
                    'conflict' => $anyConflict,
                    'models' => $allCompiledModels,
                    'database_operations' => $allDatabaseOps,
                    'files' => $allFiles,
                    'slice_plans' => $slicePlans,
                    'plan_hash' => $domainPlanHash,
                ];

                return view('laraslice::blueprint-studio', [
                    'source' => $input['source'],
                    'format' => $input['format'],
                    'blueprint' => $slicesList[$activeSliceIdx]['blueprint'] ?? ['name' => $domainName, 'domain' => $domainName],
                    'plan' => $plan,
                    'supported' => $allSupported,
                    'supportMessage' => ! empty($allSupportMessages) ? implode('; ', $allSupportMessages) : null,
                    'existingTables' => $allExistingTables,
                    'installedSlices' => $installedSlices,
                    'dbTables' => $dbTables,
                    'archetypeDecision' => $archetypeDecision,
                    'riskDecision' => $riskDecision,
                    'allSlicesJson' => $allSlicesJson,
                    'activeSliceIdx' => $activeSliceIdx,
                    'planScope' => 'domain',
                ]);
            }

            // Single Slice Plan
            $parsed = $loader->parse($input['source'], $input['format']);
            if (isset($parsed['models']) && is_array($parsed['models'])) {
                foreach ($parsed['models'] as &$m) {
                    if (isset($m['handle']) && is_string($m['handle'])) {
                        $m['handle'] = Str::snake($m['handle']);
                    }
                }
                unset($m);
            }
            $blueprint = $validator->validate($parsed);
            $plan = $planner->plan($blueprint, $slicesPath);
            $plan['is_domain_plan'] = false;
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
                'planScope' => 'slice',
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
                'planScope' => $planScope,
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
            'overwrite_existing' => ['nullable'],
            'slices_json' => ['nullable', 'string'],
            'active_slice_idx' => ['nullable', 'integer'],
            'plan_scope' => ['nullable', 'in:domain,slice'],
        ]);

        $slicesPath = config('laraslice.slices_path', app_path('Slices'));
        $dbTables = $this->getDatabaseTables();
        $installedSlices = $this->getInstalledSlices($slicesPath);
        $allSlicesJson = $request->input('slices_json');
        $activeSliceIdx = (int) $request->input('active_slice_idx', 0);
        $planScope = $request->input('plan_scope');
        $overwriteExisting = $request->boolean('overwrite_existing', false);

        try {
            if ($planScope === 'domain' && ! empty($allSlicesJson)) {
                $slicesList = json_decode($allSlicesJson, true) ?: [];
                $generatedTargets = [];
                $applier = new BlueprintApplier(static fn (string $table): bool => Schema::hasTable($table));
                $namespace = config('laraslice.slices_namespace', 'App\\Slices');

                foreach ($slicesList as $sIdx => $sl) {
                    $sliceSource = $sl['source'] ?? '';
                    if (empty($sliceSource) && isset($sl['blueprint'])) {
                        $sliceSource = Yaml::dump($sl['blueprint'], 4, 2);
                    }
                    $sliceParsed = $loader->parse($sliceSource, 'yaml');
                    if (isset($sliceParsed['models']) && is_array($sliceParsed['models'])) {
                        foreach ($sliceParsed['models'] as &$m) {
                            if (isset($m['handle']) && is_string($m['handle'])) {
                                $m['handle'] = Str::snake($m['handle']);
                            }
                        }
                        unset($m);
                    }
                    $validBp = $validator->validate($sliceParsed);
                    $sPlan = $planner->plan($validBp, $slicesPath);
                    $target = $applier->apply($validBp, $sPlan, $slicesPath, $namespace, $overwriteExisting);
                    $generatedTargets[] = $target;
                }

                $installedSlices = $this->getInstalledSlices($slicesPath);

                $migrated = false;
                $migrationMessage = null;
                $domainName = $slicesList[0]['domain'] ?? 'Domain';
                $mayMigrate = Access::allows($request->user(), ['studio.migrate', 'studio.*']);
                if ($request->boolean('auto_migrate', false) && ! $mayMigrate) {
                    $migrationMessage = 'Migrations were not run: running migrations requires the studio.migrate permission.';
                } elseif ($request->boolean('auto_migrate', false)) {
                    try {
                        $outputs = $this->executeMigrations();
                        $seeded = $this->runDomainSeeders($domainName);
                        $migrated = true;
                        $migrationMessage = ! empty($outputs) ? implode("\n", $outputs) : 'Database migrations applied.';
                        if (! empty($seeded)) {
                            $migrationMessage .= ' Master data seeders executed.';
                        }
                    } catch (Throwable $e) {
                        $migrationMessage = 'Migration warning: '.$e->getMessage();
                    }
                }

                $successMsg = "Successfully generated all ".count($generatedTargets)." vertical slices in [{$domainName}] domain suite with persisted slice.yaml files!";
                if ($migrated) {
                    $successMsg .= ' Database migrations were automatically executed.';
                } else {
                    $successMsg .= " Click '⚡ Run Migrations' or run 'php artisan migrate' to apply database tables.";
                }

                return view('laraslice::blueprint-studio', [
                    'source' => $input['source'],
                    'format' => $input['format'],
                    'blueprint' => $slicesList[$activeSliceIdx]['blueprint'] ?? ['name' => $domainName, 'domain' => $domainName],
                    'installedSlices' => $installedSlices,
                    'dbTables' => $this->getDatabaseTables(),
                    'success' => $successMsg,
                    'migrationMessage' => $migrationMessage,
                    'generatedSliceUrl' => url('/'.Str::slug($domainName)),
                    'allSlicesJson' => $allSlicesJson,
                    'activeSliceIdx' => $activeSliceIdx,
                ]);
            }

            $parsed = $loader->parse($input['source'], $input['format']);
            if (isset($parsed['models']) && is_array($parsed['models'])) {
                foreach ($parsed['models'] as &$m) {
                    if (isset($m['handle']) && is_string($m['handle'])) {
                        $m['handle'] = Str::snake($m['handle']);
                    }
                }
                unset($m);
            }
            $blueprint = $validator->validate($parsed);
            $plan = $planner->plan($blueprint, $slicesPath);

            if (! hash_equals($plan['plan_hash'], $input['plan_hash'])) {
                throw new RuntimeException('This review is out of date. Generate a new plan before applying.');
            }

            $target = (new BlueprintApplier(static fn (string $table): bool => Schema::hasTable($table)))
                ->apply($blueprint, $plan, $slicesPath, config('laraslice.slices_namespace', 'App\\Slices'), $overwriteExisting);

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
            $outputs = $this->executeMigrations();
            $message = ! empty($outputs) ? implode("\n", $outputs) : 'Database migrations executed successfully.';

            // Also seed any master data if present
            foreach (glob(app_path('Slices/*'), GLOB_ONLYDIR) as $domainDir) {
                $this->runDomainSeeders(basename($domainDir));
            }

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => $message]);
            }

            return redirect()->route('laraslice.wizard.blueprint')
                ->with('success', "⚡ Migrations & master data executed successfully: {$message}");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }

            return redirect()->route('laraslice.wizard.blueprint')
                ->with('error', 'Migration failed: '.$e->getMessage());
        }
    }

    /**
     * Discover and execute all core and slice migrations across the project.
     *
     * @return array<int, string>
     */
    protected function executeMigrations(): array
    {
        $outputs = [];
        $slicesBasePath = config('laraslice.slices_path', app_path('Slices'));
        $migrationPaths = [];

        if (is_dir($slicesBasePath)) {
            $rdi = new \RecursiveDirectoryIterator($slicesBasePath, \RecursiveDirectoryIterator::SKIP_DOTS);
            $rii = new \RecursiveIteratorIterator($rdi, \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($rii as $item) {
                if ($item->isDir() && strtolower($item->getFilename()) === 'migrations') {
                    $mp = $item->getRealPath();
                    if ($mp && ! in_array($mp, $migrationPaths, true)) {
                        $migrationPaths[] = $mp;
                    }
                }
            }
        }

        // 1. Run standard migrate
        Artisan::call('migrate', ['--force' => true]);
        $defaultOut = trim(Artisan::output());
        if ($defaultOut && ! str_contains($defaultOut, 'Nothing to migrate')) {
            $outputs[] = $defaultOut;
        }

        // 2. Explicitly migrate each discovered slice migration folder
        foreach (array_unique($migrationPaths) as $mp) {
            if (is_dir($mp)) {
                $relative = str_replace([base_path().DIRECTORY_SEPARATOR, base_path().'/'], '', $mp);
                $relative = str_replace('\\', '/', $relative);
                try {
                    Artisan::call('migrate', [
                        '--force' => true,
                        '--path' => $relative,
                    ]);
                    $out = trim(Artisan::output());
                    if ($out && ! str_contains($out, 'Nothing to migrate')) {
                        $outputs[] = $out;
                    }
                } catch (\Throwable $pe) {
                    try {
                        Artisan::call('migrate', [
                            '--force' => true,
                            '--realpath' => true,
                            '--path' => $mp,
                        ]);
                        $out = trim(Artisan::output());
                        if ($out && ! str_contains($out, 'Nothing to migrate')) {
                            $outputs[] = $out;
                        }
                    } catch (\Throwable $e) {
                        $outputs[] = 'Error in '.$relative.': '.$e->getMessage();
                    }
                }
            }
        }

        return $outputs;
    }

    /**
     * Seed master data taxonomies and domain roles so dropdowns are pre-populated.
     */
    protected function runDomainSeeders(string $domainName): array
    {
        $seeded = [];
        $seederClass = 'Database\\Seeders\\'.Str::studly($domainName).'MasterDataSeeder';
        $hostSeederFile = database_path('seeders/'.Str::studly($domainName).'MasterDataSeeder.php');
        if (file_exists($hostSeederFile)) {
            require_once $hostSeederFile;
            if (class_exists($seederClass)) {
                try {
                    (new $seederClass)->run();
                    $seeded[] = $seederClass;
                } catch (\Throwable $e) {
                }
            }
        }

        $sliceSeederPath = app_path("Slices/{$domainName}/MasterData/Database/Seeders/".Str::studly($domainName)."MasterDataSeeder.php");
        if (file_exists($sliceSeederPath)) {
            require_once $sliceSeederPath;
            $sliceClass = "App\\Slices\\{$domainName}\\MasterData\\Database\\Seeders\\".Str::studly($domainName)."MasterDataSeeder";
            if (class_exists($sliceClass)) {
                try {
                    (new $sliceClass)->run();
                    $seeded[] = $sliceClass;
                } catch (\Throwable $e) {
                }
            }
        }

        return $seeded;
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

    /**
     * Return available porting presets and examples.
     */
    public function portPresets(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'presets' => [
                [
                    'id' => 'erp',
                    'name' => 'ERP Enterprise Suite',
                    'badge' => '358 tables → 23 Slices (15k records)',
                    'domain' => 'ERP',
                    'source_path' => 'e:/laragon/www/erp',
                    'description' => 'Comprehensive enterprise suite with Projects, Tasks, Invoices, HR, CRM, Tickets and Unified Settings Hub',
                ],
                [
                    'id' => 'fospah',
                    'name' => 'FOSPAH Judicial Case Management',
                    'badge' => 'Petitions, Scrutiny & Cause Lists',
                    'domain' => 'Legal',
                    'source_path' => 'e:/laragon/www/wwf-old',
                    'description' => 'Statutory judicial case workflow: Citizen Intake, Scrutiny, Hearings and Speaking Orders',
                ],
            ],
        ]);
    }

    /**
     * Inspect or load architecture plan for legacy application porting.
     */
    public function portPlan(Request $request, \LaraSlice\Porting\SlicePortService $portService): JsonResponse
    {
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        $preset = (string) $request->input('preset', '');
        $source = (string) $request->input('source', '');
        $domain = (string) $request->input('domain', 'ERP');
        $planJson = (string) $request->input('plan_json', '');

        try {
            // 1. Direct JSON passed from developer
            if (! empty($planJson)) {
                $plan = json_decode($planJson, true);
                if (! is_array($plan) || ! isset($plan['modules'])) {
                    throw new \InvalidArgumentException('Invalid JSON Architecture Plan format.');
                }
                return response()->json(['success' => true, 'plan' => $plan]);
            }

            // 2. Load ERP preset example plan if requested
            if ($preset === 'erp' || (empty($source) && strtolower($domain) === 'erp')) {
                $examplePlan = base_path('docs/porting-architecture-plan.json');
                if (\Illuminate\Support\Facades\File::exists($examplePlan)) {
                    $plan = json_decode(\Illuminate\Support\Facades\File::get($examplePlan), true);
                    return response()->json(['success' => true, 'plan' => $plan]);
                }
            }

            // 3. Dynamic inspection & plan generation
            $inspection = $portService->inspect($source, $domain);
            $plan = $portService->generateArchitecturePlan($inspection, $domain);

            return response()->json([
                'success' => true,
                'plan' => $plan,
                'inspection' => $inspection,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Inspect a legacy application directory and return table classification & slice mapping.
     */
    public function portInspect(Request $request, \LaraSlice\Porting\SlicePortService $portService): JsonResponse
    {
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        $source = (string) $request->input('source', '');
        $domain = (string) $request->input('domain', 'ERP');

        try {
            $data = $portService->inspect($source, $domain);
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Execute the porting and migration pipeline for a legacy application.
     */
    public function portExecute(Request $request, \LaraSlice\Porting\SlicePortService $portService): JsonResponse
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $planInput = $request->input('plan');
        $source = (string) $request->input('source', '');
        $domain = (string) $request->input('domain', 'ERP');
        $mode = (string) $request->input('mode', 'system-base');
        $withFiles = (bool) $request->input('with_files', false);
        $importData = (bool) $request->input('import_data', true);

        try {
            if (! empty($planInput)) {
                $plan = is_array($planInput) ? $planInput : json_decode($planInput, true);
                $result = $portService->executeFromPlan($plan, $importData, $withFiles);
            } else {
                $result = $portService->execute($source, $domain, $mode, $withFiles, $importData);
            }

            return response()->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

}
