<?php

namespace LaraSlice\Blueprint;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use LaraSlice\Core\Ai\JevDecisionService;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Security\Access;
use LaraSlice\Generator\SliceName;
use LaraSlice\Porting\SlicePortService;
use LaraSlice\Support\BlueprintLibrary;
use LaraSlice\Support\SystemMind;
use LaraSlice\Support\SystemOperate;
use LaraSlice\Wizard\Middleware\AuthorizeStudio;
use LaraSlice\Wizard\WizardController;
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
        // ONE Studio: the canonical surface is the Dev Studio shell, Design mode.
        $qs = $request->getQueryString();
        $target = route('laraslice.studio').'?mode=design'.($qs ? '&'.$qs : '');

        return redirect($target);
    }

    /**
     * Build the Blueprint Studio view (shared by the standalone wrapper and
     * the Dev Studio Design panel).
     */
    private function buildStudioView(Request $request): mixed
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
                $jsonFile = $slicesPath.'/'.$requestedSlice.'/slice.yaml';
                if (! File::exists($jsonFile)) {
                    $candidates = glob($slicesPath.'/*/'.$requestedSlice.'/slice.yaml') ?: [];
                    if (! empty($candidates)) {
                        $jsonFile = $candidates[0];
                    }
                }
                if (! File::exists($jsonFile) && File::isDirectory($slicesPath)) {
                    $sliceDirName = basename($requestedSlice);
                    foreach (File::allFiles($slicesPath) as $file) {
                        if ($file->getFilename() === 'slice.yaml' && strcasecmp(basename(dirname($file->getPathname())), $sliceDirName) === 0) {
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

        // 4. Collect installed slices that have a slice.yaml
        $installedSlices = $this->getInstalledSlices($slicesPath);

        // 5. Collect database tables for quick introspection
        $dbTables = $this->getDatabaseTables();

        // 6. Preload default architecture plan if available
        $portPlan = null;
        $portPlanFile = base_path('docs/porting-architecture-plan.json');
        if (File::exists($portPlanFile)) {
            try {
                $portPlan = json_decode(File::get($portPlanFile), true);
            } catch (Throwable) {
            }
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
            } catch (Throwable) {
            }
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
                    $sliceName = $sl['name'] ?? 'Slice '.($sIdx + 1);
                    $sliceSource = $sl['source'] ?? '';
                    if (empty($sliceSource) && isset($sl['blueprint'])) {
                        $sliceSource = Yaml::dump($sl['blueprint'], 4, 2);
                    }

                    $sliceParsed = $loader->parse($sliceSource, 'yaml');
                    $sliceParsed = $this->sanitizeBlueprint($sliceParsed);
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
                $archetypeDecision = $jev->classifyArchetype("Domain Suite for {$domainName} containing ".count($slicesList).' vertical slices');
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
            $parsed = $this->sanitizeBlueprint($parsed);
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
                $generatedPlans = [];
                $applier = new BlueprintApplier(static fn (string $table): bool => Schema::hasTable($table));
                $namespace = config('laraslice.slices_namespace', 'App\\Slices');

                foreach ($slicesList as $sIdx => $sl) {
                    $sliceSource = $sl['source'] ?? '';
                    if (empty($sliceSource) && isset($sl['blueprint'])) {
                        $sliceSource = Yaml::dump($sl['blueprint'], 4, 2);
                    }
                    $sliceParsed = $loader->parse($sliceSource, 'yaml');
                    $sliceParsed = $this->sanitizeBlueprint($sliceParsed);
                    $validBp = $validator->validate($sliceParsed);
                    $sPlan = $planner->plan($validBp, $slicesPath);
                    $target = $applier->apply($validBp, $sPlan, $slicesPath, $namespace, $overwriteExisting);
                    $generatedTargets[] = $target;
                    $generatedPlans[] = $sPlan;
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
                    // OBS-A: executeMigrations records per-folder
                    // failures as output text, so verify the
                    // outcome it claims — every planned table of
                    // every slice in the suite must exist, or the
                    // apply fails loudly (outside the warning
                    // downgrade above, on purpose).
                    if ($migrated) {
                        $this->assertPlannedTablesExist($generatedPlans, $generatedTargets);
                    }
                }

                $successMsg = 'Successfully generated all '.count($generatedTargets)." vertical slices in [{$domainName}] domain suite with persisted slice.yaml files!";
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
            $parsed = $this->sanitizeBlueprint($parsed);
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
                // OBS-A: run the applied slice's own migrations
                // and verify its planned tables exist. A failure
                // throws to the outer catch (error view) — the
                // CLI's F9 contract — instead of the old behaviour:
                // a default-path-only migrate that reported
                // success while the slice's table was never
                // created, with real failures downgraded to a
                // "warning" under a success headline.
                $migrationOutput = $this->migrateAppliedSlice($target, $plan);
                $migrated = true;
                $migrationMessage = $migrationOutput !== '' ? $migrationOutput : 'Database migrations applied.';
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
    /**
     * Migrate one Studio-applied slice and PROVE its planned
     * tables exist before the caller may report success — the
     * Studio twin of the CLI apply's F9 fix. The plain migrate
     * call only walks the app's default path; a generated
     * slice's migrations live in its own Migrations directory,
     * which is not on it, so the Studio used to print
     * "migrations were automatically executed" while the
     * slice's table was never created (OBS-A, Phase 2). A
     * failure here throws: the apply reports an error, never a
     * success with a warning, exactly like the CLI.
     */
    protected function migrateAppliedSlice(string $target, array $plan): string
    {
        $outputs = [];
        Artisan::call('migrate', ['--force' => true]);
        $out = trim(Artisan::output());
        if ($out !== '') {
            $outputs[] = $out;
        }

        $sliceMigrations = $target.'/Migrations';
        if (is_dir($sliceMigrations)) {
            Artisan::call('migrate', [
                '--path' => $sliceMigrations,
                '--realpath' => true,
                '--force' => true,
            ]);
            $out = trim(Artisan::output());
            if ($out !== '') {
                $outputs[] = $out;
            }
        }

        $this->assertPlannedTablesExist([$plan], [$target]);

        return implode("\n", $outputs);
    }

    /**
     * Every create_table the plans promised must exist now (and
     * every pivot_table a slice migration exists for). Anything
     * missing is a loud failure with the CLI's wording — a slice
     * whose table does not exist is NOT usable.
     *
     * @param  list<array<string, mixed>>  $plans
     * @param  list<string|null>  $targets
     */
    protected function assertPlannedTablesExist(array $plans, array $targets): void
    {
        $missing = [];
        foreach ($plans as $i => $plan) {
            $target = $targets[$i] ?? null;
            foreach ($plan['database_operations'] ?? [] as $operation) {
                $kind = $operation['kind'] ?? null;
                if ($kind === 'create_table' && ! Schema::hasTable((string) $operation['table'])) {
                    $missing[] = (string) $operation['table'];
                }
                if ($kind === 'pivot_table' && $target !== null
                    && $this->sliceHasMigrationFor($target, (string) $operation['table'])
                    && ! Schema::hasTable((string) $operation['table'])) {
                    $missing[] = (string) $operation['table'];
                }
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('Migration reported success but these planned tables do not exist: '.implode(', ', array_values(array_unique($missing))).'. The slice is NOT usable — apply failed.');
        }
    }

    private function sliceHasMigrationFor(string $target, string $table): bool
    {
        $dir = $target.'/Migrations';
        if (! is_dir($dir)) {
            return false;
        }
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            if (str_contains(basename($file), $table)) {
                return true;
            }
        }

        return false;
    }

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
                } catch (Throwable $pe) {
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
                    } catch (Throwable $e) {
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
                } catch (Throwable $e) {
                }
            }
        }

        $sliceSeederPath = app_path("Slices/{$domainName}/MasterData/Database/Seeders/".Str::studly($domainName).'MasterDataSeeder.php');
        if (file_exists($sliceSeederPath)) {
            require_once $sliceSeederPath;
            $sliceClass = "App\\Slices\\{$domainName}\\MasterData\\Database\\Seeders\\".Str::studly($domainName).'MasterDataSeeder';
            if (class_exists($sliceClass)) {
                try {
                    (new $sliceClass)->run();
                    $seeded[] = $sliceClass;
                } catch (Throwable $e) {
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
        // Recursive: real installs nest slices under suite/domain/
        // area folders (app/Slices/ERP/Adjudication/Finance/Invoices),
        // so a two-level scan finds nothing on exactly the apps the
        // Studio exists to serve. A directory holding a slice.yaml
        // IS a slice; its domain is the relative parent path.
        $installedSlices = [];
        if (File::isDirectory($slicesPath)) {
            $base = rtrim($slicesPath, DIRECTORY_SEPARATOR);
            foreach (File::allFiles($slicesPath) as $file) {
                if ($file->getFilename() !== 'slice.yaml') {
                    continue;
                }
                $dir = $file->getPath();
                $relative = trim(str_replace($base, '', dirname($dir)), DIRECTORY_SEPARATOR);
                $installedSlices[] = [
                    'name' => basename($dir),
                    'domain' => $relative !== '' ? $relative : null,
                    'has_yaml' => true,
                    'path' => $file->getPathname(),
                ];
            }
            usort($installedSlices, fn ($a, $b) => [$a['domain'] ?? '', $a['name']] <=> [$b['domain'] ?? '', $b['name']]);
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

    /** Example blueprints under blueprints/examples (and suites/). */
    private const EXAMPLE_FILES = [
        'kitchen-sink' => 'kitchen-sink.slice.yaml',
        'kitchen_sink' => 'kitchen-sink.slice.yaml',
        'service-desk' => 'service-desk.slice.yaml',
        'project-portfolio' => 'project-portfolio.slice.yaml',
        'crm-companies' => 'suites/crm-companies.slice.yaml',
        'crm-contacts' => 'suites/crm-contacts.slice.yaml',
        'crm-deals' => 'suites/crm-deals.slice.yaml',
        'hr-employees' => 'suites/hr-employees.slice.yaml',
        'hr-leave-requests' => 'suites/hr-leave-requests.slice.yaml',
        'shop-products-example' => 'suites/shop-products.slice.yaml',
        'shop-orders-example' => 'suites/shop-orders.slice.yaml',
    ];

    /**
     * The YAML of a built-in template or example; unknown names fall back to service-desk.
     */
    private function getTemplateContent(string $template): string
    {
        $root = dirname(__DIR__, 2).'/blueprints';

        if (isset(self::EXAMPLE_FILES[$template])) {
            $path = $root.'/examples/'.self::EXAMPLE_FILES[$template];
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        $path = isset(self::TEMPLATES[$template])
            ? $root.'/templates/'.self::TEMPLATES[$template].'.slice.yaml'
            : $root.'/examples/service-desk.slice.yaml';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Convert an existing JSON manifest into a valid slice.yaml representation.
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
                    'id' => 'adjudication',
                    'name' => '⚖️ Dispute Adjudication',
                    'badge' => '7 Slices: Complaints, Hearings, Notices, etc.',
                    'domain' => 'Adjudication',
                    'source_path' => '',
                    'description' => 'Statutory dispute adjudication: Citizen Intake, Scrutiny, Hearings, Notices, and Speaking Orders',
                ],
                [
                    'id' => 'erp',
                    'name' => '🏢 ERP Enterprise Suite',
                    'badge' => '358 tables → 23 Slices (15k records)',
                    'domain' => 'ERP',
                    'source_path' => '',
                    'description' => 'Comprehensive enterprise suite with Projects, Tasks, Invoices, HR, CRM, Tickets and Unified Settings Hub',
                ],
                [
                    'id' => 'healthcare',
                    'name' => '🏥 Healthcare & Clinical EMR',
                    'badge' => 'Patients, Encounters, Prescriptions',
                    'domain' => 'Healthcare',
                    'source_path' => '',
                    'description' => 'Clinical practice & patient management: Patient Registry, Consultations, Clinical Notes, Labs, Pharmacy',
                ],
                [
                    'id' => 'ecommerce',
                    'name' => '🛒 E-Commerce & Retail',
                    'badge' => 'Products, Orders, Customers, Inventory',
                    'domain' => 'Ecommerce',
                    'source_path' => '',
                    'description' => 'Multi-channel commerce: Catalog, Inventory, Cart, Checkout, Orders, Shipping, Subscriptions',
                ],
                [
                    'id' => 'edtech',
                    'name' => '🎓 EdTech & Learning (LMS)',
                    'badge' => 'Courses, Lessons, Enrollments',
                    'domain' => 'EdTech',
                    'source_path' => '',
                    'description' => 'Educational delivery: Curricula, Course Modules, Quizzes, Grading, Student Progress, Certifications',
                ],
                [
                    'id' => 'fintech',
                    'name' => '💳 FinTech & Core Banking',
                    'badge' => 'Accounts, Transactions, Ledger',
                    'domain' => 'FinTech',
                    'source_path' => '',
                    'description' => 'Financial operations: Double-entry ledger, Wallets, Escrow, Transfers, AML screening, Statements',
                ],
            ],
        ]);
    }

    /**
     * Render the dedicated Porting Studio page (mobile-responsive).
     * Replaces the cramped modal with a full workspace for port analysis.
     */
    public function portingStudio()
    {
        return view('laraslice::porting-studio');
    }

    /**
     * Inspect or load architecture plan for legacy application porting.
     */
    public function portPlan(Request $request, SlicePortService $portService): JsonResponse
    {
        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        $preset = (string) $request->input('preset', '');
        $source = (string) $request->input('source', '');
        $domain = (string) $request->input('domain', '');
        $planJson = (string) $request->input('plan_json', '');

        // Resolve preset defaults if source/domain are empty
        if ($preset === 'adjudication' || strtolower($domain) === 'adjudication') {
            if (empty($domain)) {
                $domain = 'Adjudication';
            }
        } elseif ($preset === 'erp' || strtolower($domain) === 'erp') {
            if (empty($domain)) {
                $domain = 'ERP';
            }
        } elseif (! empty($preset) && empty($domain)) {
            $domain = ucfirst($preset);
        }

        if (empty($domain)) {
            $domain = 'Core';
        }

        // Git repository source: clone first, then analyze the local clone
        $sourceType = (string) $request->input('source_type', 'local');
        $gitBranch = (string) $request->input('git_branch', '');
        // Token is used in-memory for the clone only — never stored or returned
        $gitToken = (string) $request->input('git_token', '');
        $clonedFrom = null;

        try {
            // 1. Direct JSON passed from developer
            if (! empty($planJson)) {
                $plan = json_decode($planJson, true);
                if (! is_array($plan) || ! isset($plan['modules'])) {
                    throw new \InvalidArgumentException('Invalid JSON Architecture Plan format.');
                }

                return response()->json(['success' => true, 'plan' => $plan]);
            }

            // 2. Load ERP preset example plan if requested and installed
            if ($preset === 'erp' && empty($source)) {
                $examplePlan = base_path('docs/porting-architecture-plan.json');
                if (File::exists($examplePlan)) {
                    $plan = json_decode(File::get($examplePlan), true);

                    return response()->json(['success' => true, 'plan' => $plan, 'example' => true]);
                }
            }

            // 3. Dynamic inspection & plan generation
            if ($sourceType === 'git') {
                if (empty($source)) {
                    throw new \InvalidArgumentException('Enter a Git repository URL to clone.');
                }
                $source = $portService->cloneGitSource(
                    $source,
                    $gitBranch !== '' ? $gitBranch : null,
                    $gitToken !== '' ? $gitToken : null,
                    (bool) $request->input('git_keep', false)
                );
                $clonedFrom = $request->input('source'); // original URL, token never included
            }

            if (empty($source) || ! File::isDirectory($source)) {
                throw new \InvalidArgumentException("Source application directory not found: [{$source}]. Please provide a valid local project path or Git URL.");
            }

            $inspection = $portService->inspect($source, $domain);
            $plan = $portService->generateArchitecturePlan($inspection, $domain);
            if (empty($plan['domain_blueprint_yaml'])) {
                $plan['domain_blueprint_yaml'] = $inspection['domain_blueprint_yaml'] ?? $portService->generateDomainBlueprintYaml($inspection['studio_slices'] ?? [], $domain, $inspection);
            }

            return response()->json([
                'success' => true,
                'plan' => $plan,
                'inspection' => $inspection,
                'cloned_from' => $clonedFrom,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get the directory where porting presets are stored.
     */
    protected function portPresetDir(): string
    {
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.'porting-presets');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Save an analyzed porting plan as a named reusable preset.
     */
    public function savePortPreset(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $plan = $request->input('plan');

        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Preset name is required.'], 422);
        }
        if (! is_array($plan)) {
            return response()->json(['success' => false, 'message' => 'Plan data is required.'], 422);
        }

        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'preset-'.time();
        }

        $data = [
            'name' => $name,
            'slug' => $slug,
            'source_type' => $request->input('source_type', 'local'),
            'source' => $request->input('source', ''), // URL or path, never a token
            'git_branch' => $request->input('git_branch', ''),
            'domain' => $request->input('domain', 'ERP'),
            'created_at' => now()->toIso8601String(),
            'plan' => $plan,
        ];

        File::put(
            $this->portPresetDir().DIRECTORY_SEPARATOR.$slug.'.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return response()->json(['success' => true, 'slug' => $slug, 'name' => $name]);
    }

    /**
     * List saved porting presets (metadata only, not full plans).
     */
    public function listPortPresets(): JsonResponse
    {
        $presets = [];
        foreach (File::glob($this->portPresetDir().DIRECTORY_SEPARATOR.'*.json') as $file) {
            $data = json_decode(File::get($file), true);
            if (is_array($data)) {
                $presets[] = [
                    'slug' => $data['slug'] ?? basename($file, '.json'),
                    'name' => $data['name'] ?? basename($file, '.json'),
                    'source_type' => $data['source_type'] ?? 'local',
                    'source' => $data['source'] ?? '',
                    'git_branch' => $data['git_branch'] ?? '',
                    'domain' => $data['domain'] ?? 'ERP',
                    'created_at' => $data['created_at'] ?? null,
                ];
            }
        }

        return response()->json(['success' => true, 'presets' => $presets]);
    }

    /**
     * Load a saved porting preset (full plan).
     */
    public function loadPortPreset(string $slug): JsonResponse
    {
        $file = $this->portPresetDir().DIRECTORY_SEPARATOR.basename($slug).'.json';
        if (! File::exists($file)) {
            return response()->json(['success' => false, 'message' => 'Preset not found.'], 404);
        }

        $data = json_decode(File::get($file), true);

        return response()->json(['success' => true, 'preset' => $data]);
    }

    /**
     * Delete a saved porting preset.
     */
    public function deletePortPreset(string $slug): JsonResponse
    {
        $file = $this->portPresetDir().DIRECTORY_SEPARATOR.basename($slug).'.json';
        if (File::exists($file)) {
            File::delete($file);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get the directory where cloned git sources are cached.
     */
    protected function portSourceCacheDir(): string
    {
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.'porting-sources');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Read cached-source metadata (repo URL, branch, keep flag).
     */
    protected function readSourceMeta(string $dir): array
    {
        $metaFile = $dir.DIRECTORY_SEPARATOR.'.porting-meta.json';
        if (File::exists($metaFile)) {
            $meta = json_decode(File::get($metaFile), true);
            if (is_array($meta)) {
                return $meta;
            }
        }

        return [];
    }

    /**
     * List cached git sources with size, age, and keep status.
     */
    public function listCachedSources(): JsonResponse
    {
        $sources = [];
        $cacheDir = $this->portSourceCacheDir();

        foreach (File::directories($cacheDir) as $dir) {
            if (! File::isDirectory($dir.DIRECTORY_SEPARATOR.'.git')) {
                continue;
            }
            $meta = $this->readSourceMeta($dir);
            $key = basename($dir);

            // Calculate directory size
            $size = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $size += $file->getSize();
            }

            $sources[] = [
                'key' => $key,
                'repo_url' => $meta['repo_url'] ?? '(unknown)',
                'branch' => $meta['branch'] ?? null,
                'keep' => (bool) ($meta['keep'] ?? false),
                'cloned_at' => $meta['cloned_at'] ?? null,
                'size_mb' => round($size / 1048576, 1),
            ];
        }

        return response()->json(['success' => true, 'sources' => $sources]);
    }

    /**
     * Toggle the "keep" flag on a cached source (skips 24h auto-expiry).
     */
    public function keepCachedSource(Request $request, string $key): JsonResponse
    {
        $dir = $this->portSourceCacheDir().DIRECTORY_SEPARATOR.basename($key);
        if (! File::isDirectory($dir)) {
            return response()->json(['success' => false, 'message' => 'Cached source not found.'], 404);
        }

        $meta = $this->readSourceMeta($dir);
        $meta['keep'] = (bool) $request->input('keep', true);
        File::put($dir.DIRECTORY_SEPARATOR.'.porting-meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        return response()->json(['success' => true, 'keep' => $meta['keep']]);
    }

    /**
     * Delete a cached git source.
     */
    public function deleteCachedSource(string $key): JsonResponse
    {
        $dir = $this->portSourceCacheDir().DIRECTORY_SEPARATOR.basename($key);
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Inspect a legacy application directory and return table classification & slice mapping.
     */
    /**
     * Fast pre-flight for Port analysis: how large is this
     * source, and where will its schema come from? Counting
     * only — a regex scan of migrations, one information_schema
     * count for a configured source connection, or a CREATE
     * TABLE scan of SQL dumps — so a bad source fails in a
     * second instead of after a minute of silence, and the
     * panel can state the real scope before the full analysis
     * starts. Mirrors inspect()'s source priority.
     */
    public function portAnalyzeScope(Request $request): JsonResponse
    {
        $source = (string) $request->input('source', '');
        $cleanPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $source), DIRECTORY_SEPARATOR);
        if ($cleanPath === '' || ! File::isDirectory($cleanPath)) {
            return response()->json(['success' => false, 'message' => "Source application directory not found: [{$source}]. Please provide a valid local project path or Git URL."], 422);
        }

        // Same priority as inspect(): a configured source
        // connection is the truth, migrations next, dumps last.
        $sourceConn = config('laraslice.porting.source_connection');
        if ($sourceConn) {
            try {
                $conn = \Illuminate\Support\Facades\DB::connection($sourceConn);
                $row = $conn->selectOne('select count(*) as c from information_schema.tables where table_schema = ?', [$conn->getDatabaseName()]);

                return response()->json(['success' => true, 'tables' => (int) ($row->c ?? 0), 'kind' => 'database']);
            } catch (Throwable) {
                // Fall through — inspect() will surface the real error.
            }
        }

        $migDir = $cleanPath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        if (File::isDirectory($migDir)) {
            $tables = [];
            foreach (File::files($migDir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                if (preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $text, $m)) {
                    foreach ($m[1] as $name) {
                        $tables[$name] = true;
                    }
                }
            }
            if ($tables !== []) {
                return response()->json(['success' => true, 'tables' => count($tables), 'kind' => 'migrations']);
            }
        }

        $tables = [];
        $scanned = 0;
        $walk = function (string $dir, int $depth) use (&$walk, &$tables, &$scanned): void {
            if ($depth > 3 || $scanned >= 200) {
                return;
            }
            foreach ((array) @scandir($dir) as $entry) {
                if ($entry === '.' || $entry === '..' || $scanned >= 200) {
                    continue;
                }
                $path = $dir.DIRECTORY_SEPARATOR.$entry;
                if (is_dir($path)) {
                    if (! in_array($entry, ['vendor', 'node_modules', '.git'], true)) {
                        $walk($path, $depth + 1);
                    }
                } elseif (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'sql') {
                    $scanned++;
                    $text = (string) @file_get_contents($path);
                    if (preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?[`"\[]?([A-Za-z0-9_]+)/i', $text, $m)) {
                        foreach ($m[1] as $name) {
                            $tables[$name] = true;
                        }
                    }
                }
            }
        };
        $walk($cleanPath, 0);
        if ($tables !== []) {
            return response()->json(['success' => true, 'tables' => count($tables), 'kind' => 'sql dumps']);
        }

        return response()->json(['success' => true, 'tables' => null, 'kind' => 'unknown']);
    }

    private function portPlanPath(): string
    {
        return storage_path('app/laraslice/port-plans/latest.json');
    }

    /**
     * Retain the latest analysis on the server, so leaving
     * Port and coming back restores the reviewed plan instead
     * of a blank panel and a lost minute of analysis. Working
     * state in storage — the plan becomes declarations only
     * through Execute, exactly as before.
     */
    public function portPlanRetain(Request $request): JsonResponse
    {
        $plan = $request->input('plan');
        if (! is_array($plan) || $plan === []) {
            return response()->json(['success' => false, 'message' => 'A plan is required to retain it.'], 422);
        }
        $path = $this->portPlanPath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode([
            'source' => (string) $request->input('source', ''),
            'domain' => (string) $request->input('domain', ''),
            'total_tables' => $request->input('total_tables'),
            'retained_at' => date('Y-m-d H:i:s'),
            'plan' => $plan,
        ], JSON_PRETTY_PRINT));

        return response()->json(['success' => true, 'retained' => true]);
    }

    public function portPlanLatest(): JsonResponse
    {
        $path = $this->portPlanPath();
        if (! is_file($path)) {
            return response()->json(['success' => true, 'found' => false]);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['plan']) || ! is_array($data['plan'])) {
            return response()->json(['success' => true, 'found' => false]);
        }

        return response()->json(['success' => true, 'found' => true] + $data);
    }

    public function portInspect(Request $request, SlicePortService $portService): JsonResponse
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
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Execute the porting and migration pipeline for a legacy application.
     */
    public function portExecute(Request $request, SlicePortService $portService): JsonResponse
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $planInput = $request->input('plan');
        $source = (string) $request->input('source', '');
        $domain = (string) $request->input('domain', 'ERP');
        $mode = (string) $request->input('mode', 'system-base');
        $withFiles = (bool) $request->input('with_files', false);
        $importData = (bool) $request->input('import_data', true);
        // Opt-in: normalized legacy roles/permissions merge. Framework auth tables
        // (roles/permissions) stay protected by default; users merge by email.
        $importAuth = (bool) $request->input('import_auth', false);

        try {
            if (! empty($planInput)) {
                $plan = is_array($planInput) ? $planInput : json_decode($planInput, true);
                if (! is_array($plan)) {
                    return response()->json(['success' => false, 'message' => 'Invalid plan payload (expected JSON object).'], 422);
                }
                $result = $portService->executeFromPlan($plan, $importData, $withFiles, $importAuth);
            } else {
                if ($source === '') {
                    return response()->json(['success' => false, 'message' => 'Provide a plan from Analyze or a source path.'], 422);
                }
                $result = $portService->execute($source, $domain, $mode, $withFiles, $importData, null, $importAuth);
            }

            $created = $result['slices_created'] ?? $result['slices_generated'] ?? [];
            $createdCount = is_array($created) ? count($created) : 0;
            $errors = $result['errors'] ?? [];
            $errorCount = is_array($errors) ? count($errors) : 0;
            $ok = ($result['success'] ?? true) && $errorCount === 0;

            $message = $ok
                ? "Porting finished: {$createdCount} slice(s) written to disk."
                : "Porting finished with {$errorCount} error(s); {$createdCount} slice(s) written.";

            // Never let a data import look silently successful: tables
            // whose target did not exist imported nothing, and an import
            // without the auth opt-in leaves users with no roles.
            $dataImport = $result['data_import'] ?? null;
            if (is_array($dataImport)) {
                $missing = $dataImport['missing_target_tables'] ?? [];
                if (is_array($missing) && $missing !== []) {
                    $message .= ' WARNING: data import skipped '.count($missing).' table(s) whose target tables do not exist ('
                        .implode(', ', $missing).') — those rows were NOT imported; run migrations and re-run the import.';
                }
                if (! empty($dataImport['auth_warning'])) {
                    $message .= ' WARNING: '.$dataImport['auth_warning'];
                }
            }

            return response()->json([
                'success' => $ok,
                'message' => $message,
                'result' => $result,
                'slices_created_count' => $createdCount,
                'errors_count' => $errorCount,
            ], $ok ? 200 : 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Sanitize and auto-repair blueprint data before validation.
     * Ensures reserved columns are stripped from fields, required/nullable booleans are consistent,
     * child models are connected to the root aggregate model, and catalog/settings slices are recognized.
     *
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>
     */
    public function sanitizeBlueprint(array $blueprint): array
    {
        if (! isset($blueprint['models']) || ! is_array($blueprint['models'])) {
            return $blueprint;
        }

        $sliceName = $blueprint['name'] ?? '';
        if (preg_match('/(setting|master|catalog|reference)/i', $sliceName)) {
            $blueprint['taxonomy'] = true;
        }

        $reserved = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $rootHandle = null;
        $modelHandles = [];

        // 1. First pass: normalize handles, sanitize fields
        foreach ($blueprint['models'] as $idx => &$model) {
            if (! is_array($model)) {
                continue;
            }

            if (isset($model['handle']) && is_string($model['handle'])) {
                $model['handle'] = Str::snake($model['handle']);
            } else {
                $model['handle'] = 'model_'.$idx;
            }

            $mHandle = $model['handle'];
            $modelHandles[$mHandle] = $idx;

            if (($model['root'] ?? false) === true) {
                if ($rootHandle === null) {
                    $rootHandle = $mHandle;
                } else {
                    $model['root'] = false;
                }
            }

            // Sanitize fields
            if (isset($model['fields']) && is_array($model['fields'])) {
                $cleanFields = [];
                $seenHandles = [];

                foreach ($model['fields'] as $f) {
                    if (! is_array($f)) {
                        continue;
                    }

                    $fHandle = isset($f['handle']) && is_string($f['handle']) ? Str::snake($f['handle']) : null;
                    if (! $fHandle || in_array($fHandle, $reserved, true) || isset($seenHandles[$fHandle])) {
                        continue;
                    }
                    $seenHandles[$fHandle] = true;
                    $f['handle'] = $fHandle;

                    $rawType = strtolower((string) ($f['type'] ?? 'string'));
                    if (str_ends_with($fHandle, '_id')) {
                        $canonicalType = 'foreign_id';
                    } else {
                        $canonicalType = match ($rawType) {
                            'string', 'varchar', 'char', 'tinytext' => 'string',
                            'text', 'mediumtext', 'longtext' => 'text',
                            'integer', 'int', 'mediumint', 'smallint', 'tinyint' => 'integer',
                            'biginteger', 'bigint' => 'bigInteger',
                            'foreign_id', 'foreignid' => 'foreign_id',
                            'decimal', 'numeric' => 'decimal',
                            'float', 'double', 'real' => 'float',
                            'boolean', 'bool' => 'boolean',
                            'date' => 'date',
                            'datetime' => 'datetime',
                            'timestamp' => 'timestamp',
                            'enum' => (! empty($f['options']) && is_array($f['options']) ? 'enum' : 'string'),
                            'email' => 'email',
                            'url' => 'url',
                            'json' => 'json',
                            default => 'string',
                        };
                    }
                    $f['type'] = $canonicalType;

                    if ($f['type'] === 'enum' && (empty($f['options']) || ! is_array($f['options']))) {
                        $f['type'] = 'string';
                        unset($f['options']);
                    }

                    $req = $f['required'] ?? null;
                    $nll = $f['nullable'] ?? null;

                    if ($req === null && $nll === null) {
                        $f['required'] = false;
                        $f['nullable'] = true;
                    } elseif ($req !== null && $nll !== null && (bool) $req === (bool) $nll) {
                        if ((bool) $req) {
                            $f['required'] = true;
                            $f['nullable'] = false;
                        } else {
                            $f['required'] = false;
                            $f['nullable'] = true;
                        }
                    } elseif ($req !== null) {
                        $f['required'] = (bool) $req;
                        $f['nullable'] = ! (bool) $req;
                    } else {
                        $f['nullable'] = (bool) $nll;
                        $f['required'] = ! (bool) $nll;
                    }

                    $cleanFields[] = $f;
                }
                $model['fields'] = $cleanFields;
            }

            // Normalize existing relations
            if (isset($model['relations']) && is_array($model['relations'])) {
                $cleanRelations = [];
                foreach ($model['relations'] as $rel) {
                    if (! is_array($rel)) {
                        continue;
                    }
                    if (isset($rel['model']) && is_string($rel['model'])) {
                        $rel['model'] = Str::snake($rel['model']);
                    }
                    if (isset($rel['name']) && is_string($rel['name'])) {
                        $rel['name'] = Str::snake($rel['name']);
                    }
                    $cleanRelations[] = $rel;
                }
                $model['relations'] = $cleanRelations;
            } else {
                $model['relations'] = [];
            }
        }
        unset($model);

        // Ensure exactly one root model
        if ($rootHandle === null && count($blueprint['models']) > 0) {
            $blueprint['models'][0]['root'] = true;
            $rootHandle = $blueprint['models'][0]['handle'];
        }

        // 2. Second pass: ensure child model reachability & valid foreign keys
        $isCatalog = ($blueprint['taxonomy'] ?? false) === true;
        if ($rootHandle !== null && count($blueprint['models']) > 1 && ! $isCatalog) {
            $rootIndex = $modelHandles[$rootHandle] ?? 0;
            $rootModel = &$blueprint['models'][$rootIndex];
            $rootTable = $rootModel['table'] ?? $rootHandle.'s';
            $rootTableSingular = Str::singular($rootTable);

            foreach ($blueprint['models'] as $idx => &$childModel) {
                if (! is_array($childModel) || $idx === $rootIndex) {
                    continue;
                }

                $childHandle = $childModel['handle'];
                $childFields = array_column($childModel['fields'] ?? [], 'handle');

                // Find best foreign key pointing to root or another parent model
                $bestFk = null;
                $parentModelHandle = $rootHandle;

                if (in_array($rootHandle.'_id', $childFields, true)) {
                    $bestFk = $rootHandle.'_id';
                } elseif (in_array($rootTableSingular.'_id', $childFields, true)) {
                    $bestFk = $rootTableSingular.'_id';
                } else {
                    foreach ($modelHandles as $otherHandle => $otherIdx) {
                        if ($otherIdx === $idx) {
                            continue;
                        }
                        if (in_array($otherHandle.'_id', $childFields, true)) {
                            $bestFk = $otherHandle.'_id';
                            $parentModelHandle = $otherHandle;
                            break;
                        }
                    }
                }

                // If still no foreign key found, inject rootHandle_id into child model fields
                if (! $bestFk) {
                    $bestFk = $rootHandle.'_id';
                    $childModel['fields'][] = [
                        'handle' => $bestFk,
                        'label' => Str::headline($rootHandle),
                        'type' => 'foreign_id',
                        'required' => false,
                        'nullable' => true,
                        'width' => 50,
                    ];
                } else {
                    foreach ($childModel['fields'] as &$cf) {
                        if (($cf['handle'] ?? '') === $bestFk) {
                            $cf['type'] = 'foreign_id';
                            break;
                        }
                    }
                    unset($cf);
                }

                // Add hasMany on the parent model
                $pIdx = $modelHandles[$parentModelHandle] ?? $rootIndex;
                $parentModel = &$blueprint['models'][$pIdx];
                $parentRelations = $parentModel['relations'] ?? [];
                $alreadyHas = false;
                foreach ($parentRelations as $pr) {
                    if (($pr['model'] ?? '') === $childHandle) {
                        $alreadyHas = true;
                        break;
                    }
                }
                if (! $alreadyHas) {
                    $relName = Str::plural($childHandle);
                    $parentRelations[] = [
                        'name' => $relName,
                        'type' => 'hasMany',
                        'model' => $childHandle,
                        'foreign_key' => $bestFk,
                    ];
                    $parentModel['relations'] = $parentRelations;
                }

                // Add belongsTo on child model
                $childRelations = $childModel['relations'] ?? [];
                $alreadyHasBelongsTo = false;
                foreach ($childRelations as $cr) {
                    if (($cr['model'] ?? '') === $parentModelHandle) {
                        $alreadyHasBelongsTo = true;
                        break;
                    }
                }
                if (! $alreadyHasBelongsTo) {
                    $childRelations[] = [
                        'name' => $parentModelHandle,
                        'type' => 'belongsTo',
                        'model' => $parentModelHandle,
                        'foreign_key' => $bestFk,
                    ];
                    $childModel['relations'] = $childRelations;
                }
            }
            unset($childModel);
        }

        return $blueprint;
    }

    /**
     * ONE Studio shell. Modes are panels; only Port is mounted in Phase 1.
     */
    public function shell(Request $request): mixed
    {
        $mode = (string) $request->query('mode', '');
        $sliceCount = 0;
        try {
            $manager = app(SliceManager::class);
            $sliceCount = count($manager->discoveredSlices());
        } catch (Throwable $e) {
            $sliceCount = 0;
        }

        return view('laraslice::studio-shell', [
            'initialMode' => $mode,
            'hasSlices' => $sliceCount > 0,
            'sliceCount' => $sliceCount,
        ]);
    }

    /**
     * Lazy panel HTML. Phase 1 serves Port only.
     */
    /** Operate mode actions: run a backup, generate the manual (R4). */
    public function operateBackup(Request $request): mixed
    {
        $created = SystemOperate::runBackup();

        return response()->json(['created' => $created, 'backups' => SystemOperate::backups()]);
    }

    public function operateManual(Request $request): mixed
    {
        return response()->json(['markdown' => SystemOperate::manualMarkdown()]);
    }

    /**
     * The built-in studio presets (example suites), served
     * lazily: the Design panel fetches them only when a
     * preset is actually picked, instead of shipping ~155 KB
     * of example data inside every panel load.
     */
    public function blueprintPresets(Request $request): mixed
    {
        $path = dirname(__DIR__, 2).'/blueprints/studio-presets.json';
        if (! is_file($path)) {
            return response()->json([]);
        }

        return response((string) file_get_contents($path), 200, ['Content-Type' => 'application/json']);
    }

    /**
     * The js-yaml parser as a shared, cacheable package asset.
     * It used to be inlined into the Design panel (~39 KB on
     * every panel load); the panel lazy-loads it once per page
     * through window.__loadJsYaml instead.
     */
    public function blueprintJsYaml(): mixed
    {
        $path = dirname(__DIR__, 2).'/resources/assets/js-yaml.min.js';
        if (! is_file($path)) {
            return response('// js-yaml asset missing from the package.', 404, ['Content-Type' => 'application/javascript; charset=utf-8']);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * The Design editor's component factory as a shared,
     * cacheable package asset (~113 KB). It used to be
     * inlined into every Design panel load; the Studio
     * shell now loads it once (window.__loadDesignFactory)
     * and awaits it before initializing the panel.
     */
    public function blueprintDesignFactory(): mixed
    {
        $path = dirname(__DIR__, 2).'/resources/assets/design-factory.js';
        if (! is_file($path)) {
            return response('// design factory asset missing from the package.', 404, ['Content-Type' => 'application/javascript; charset=utf-8']);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * The team blueprint library: named blueprints kept as
     * files under porting/blueprints/, so they travel with
     * the project in Git and land in Declaration History
     * like every other declaration. Reads are open to the
     * Studio; writes go through the guard group, like all
     * Studio mutations.
     */
    public function blueprintLibraryIndex(): JsonResponse
    {
        return response()->json(['blueprints' => (new BlueprintLibrary)->all()]);
    }

    public function blueprintLibraryShow(string $slug): JsonResponse
    {
        $entry = (new BlueprintLibrary)->get($slug);
        if ($entry === null) {
            return response()->json(['message' => "No library blueprint named [{$slug}]."], 404);
        }

        return response()->json($entry);
    }

    public function blueprintLibraryStore(Request $request): JsonResponse
    {
        try {
            $entry = (new BlueprintLibrary)->save(
                (string) $request->input('name', ''),
                (string) $request->input('yaml', ''),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($entry);
    }

    public function blueprintLibraryDestroy(string $slug): JsonResponse
    {
        if (! (new BlueprintLibrary)->delete($slug)) {
            return response()->json(['message' => "No library blueprint named [{$slug}]."], 404);
        }

        return response()->json(['deleted' => true]);
    }

    private const BRIEF_DRAFT_PROMPT = <<<'PROMPT'
You are LaraSlice's blueprint drafter. Convert the user's app brief into ONE LaraSlice blueprint (schema v1) and a short Mind seed.
Output ONLY two ```yaml code blocks, in this order, with no explanation before, between or after them. The FIRST block is the blueprint. The SECOND block is the Mind seed.
Blueprint shape:
schema_version: 1
name: <StudlyCase plural, e.g. Appointments>
handle: <snake_case plural>
domain: <StudlyCase domain, e.g. Clinic>
description: <one sentence from the brief>
models:
  - handle: <snake_case singular>
    name: <StudlyCase singular>
    table: <snake_case plural>
    root: true
    fields:
      - handle: <snake_case>
        label: <Human Label>
        type: <string|text|integer|decimal|boolean|date|datetime|timestamp>
        required: <true|false>
        nullable: <true|false>
        width: 50
    relations:
      - name: <related model handle>
        type: belongsTo
        model: <related model handle>
        foreign_key: <model>_id
Mind seed shape:
purpose: <one or two sentences — what this system is for and who it serves, in the brief's own terms>
actors:
  - name: <Actor Name>
    kind: <internal|external>
    description: <one line>
journeys:
  - name: <Journey Name>
    actor: <one of the actors above>
    steps:
      - <plain step>
      - <plain step>
    outcome: <one line>
Rules: 1-3 models (the main records named in the brief); 4-10 fields per model; every model gets a name or title field; foreign keys are integer fields named <model>_id; EVERY foreign key also needs a relations entry on the model that holds it (type belongsTo, as in the shape above) — without one the referenced model is unreachable and the blueprint is invalid; exactly one model has root: true (the main record); do not invent modules the brief never mentions. Mind seed: name every kind of user the brief mentions or clearly implies (kind internal for staff and operators, external for customers, patients, members and the public); 1-3 journeys in the brief's own terms, steps in plain words.
Example — for the brief "A library tracks books and their authors. A book has a title, a genre, a published year and an author. An author has a name, a nationality and a birth year." the valid output is:
```yaml
schema_version: 1
name: Books
handle: books
domain: Library
description: Track library books and their authors.
models:
  - handle: book
    name: Book
    table: books
    root: true
    fields:
      - handle: title
        label: Title
        type: string
        required: true
        nullable: false
        width: 50
      - handle: genre
        label: Genre
        type: string
        required: true
        nullable: false
        width: 50
      - handle: published_year
        label: Published Year
        type: integer
        required: true
        nullable: false
        width: 50
      - handle: author_id
        label: Author
        type: integer
        required: true
        nullable: false
        width: 50
    relations:
      - name: author
        type: belongsTo
        model: author
        foreign_key: author_id
  - handle: author
    name: Author
    table: authors
    root: false
    fields:
      - handle: name
        label: Name
        type: string
        required: true
        nullable: false
        width: 50
      - handle: nationality
        label: Nationality
        type: string
        required: false
        nullable: true
        width: 50
      - handle: birth_year
        label: Birth Year
        type: integer
        required: false
        nullable: true
        width: 50
```
and its Mind seed:
```yaml
purpose: A small library system for tracking the book collection and who wrote each book, so staff can catalogue books and find them quickly.
actors:
  - name: Librarian
    kind: internal
    description: Catalogues books and keeps the collection organised.
journeys:
  - name: Catalogue a book
    actor: Librarian
    steps:
      - Add the book with its title and genre
      - Link the book to its author
      - Save and check it appears in the list
    outcome: The book is in the catalogue, linked to its author.
```
PROMPT;

    private const MIND_JOURNEY_PROPOSE_PROMPT = <<<'PROMPT'
You are LaraSlice's Mind editor. The user wants to ADD journeys to their system's Mind (system.yaml). You are given the Mind's purpose, its actors, its existing journey names, and the user's instruction.
Output ONLY a ```json code block — no explanation — holding an array of journey objects to ADD:
[{"name": "<Journey Name>", "actor": "<one of the existing actors, exactly as named>", "steps": ["<plain step>", "<plain step>"], "outcome": "<one line>"}]
Rules: propose 1-3 journeys; only ADD — never repeat an existing journey name; the actor must be one of the listed actors, exactly as named; steps in plain words drawn from the instruction; if the instruction does not describe a journey, output an empty array [].
PROMPT;

    /**
     * BRD → drafted blueprint: the Studio Home/kickoff brief
     * becomes a starting blueprint via the connected AI
     * provider. The reply is extracted, parsed and passed
     * through the same BlueprintValidator as any hand-written
     * blueprint; anything less is reported plainly, never
     * faked. The draft lands in the Design editor as an
     * unsaved draft — nothing is written to disk here.
     */
    public function draftFromBrief(Request $request): mixed
    {
        $brief = trim((string) $request->input('brief', ''));
        $length = mb_strlen($brief);
        if ($length < 20 || $length > 8000) {
            return response()->json(['drafted' => false, 'reason' => 'A brief of 20–8000 characters is needed to draft from.'], 422);
        }

        $engine = app(\LaraSlice\Core\Ai\AiEngine::class);
        $provider = $engine->getActiveProvider();
        $key = $engine->providerKey($provider);
        if ($key === null || $key === '') {
            return response()->json(['drafted' => false, 'reason' => "No AI provider is connected (active provider: {$provider}). Connect one under Settings → AI, then draft again — nothing was generated."]);
        }

        $reply = $engine->callCustomProvider(self::BRIEF_DRAFT_PROMPT."\n\nBRIEF:\n".$brief, [], ['path' => '/laraslice/studio'], $provider);
        if ($reply === null || trim($reply) === '') {
            $providerError = null;
            try {
                $providerError = $engine->lastProviderError();
            } catch (\Throwable) {
                $providerError = null; // a stubbed engine in tests may not carry it
            }

            return response()->json(['drafted' => false, 'reason' => 'The AI provider did not answer. Check its connection under Settings → AI and try again.'.($providerError !== null && $providerError !== '' ? ' Provider said: '.$providerError : '')]);
        }

        preg_match_all('/```(?:yaml)?\s*\n(.*?)```/s', $reply, $blocks);
        $fences = array_map('trim', $blocks[1] ?? []);
        $yaml = $fences[0] ?? null;
        if (($yaml === null || $yaml === '') && str_starts_with(trim($reply), 'schema_version')) {
            $yaml = trim($reply);
        }
        if ($yaml === null || $yaml === '') {
            return response()->json(['drafted' => false, 'reason' => 'The AI reply did not contain a blueprint. Try a clearer brief that names the main records and their fields.']);
        }

        try {
            $parsed = Yaml::parse($yaml);
            if (! is_array($parsed)) {
                throw new \RuntimeException('The drafted YAML is not a blueprint map.');
            }
            (new BlueprintValidator)->validate($parsed);
        } catch (\Throwable $e) {
            // Surface the first DETAIL line ("- Model 'x' is not reachable…"),
            // not the generic "Blueprint is invalid:" header — the header alone
            // once hid the one fixable error a real provider draft had.
            $lines = array_values(array_filter(array_map('trim', explode("\n", $e->getMessage())), fn ($line) => $line !== ''));
            $first = $lines[0] ?? $e->getMessage();
            if (str_ends_with($first, ':') && isset($lines[1])) {
                $first = ltrim($lines[1], '- ');
            }

            return response()->json(['drafted' => false, 'reason' => 'The drafted blueprint did not validate: '.$first]);
        }

        // The optional SECOND block is a Mind seed (purpose, actors,
        // journeys) drafted from the same brief. It is stashed as
        // working state beside the blueprint drafts; the user can
        // apply it as the app's Mind from the kickoff card, and only
        // when no Mind exists yet. A seed that does not parse never
        // sinks the draft — the blueprint is the star.
        $mind = null;
        if (isset($fences[1])) {
            try {
                $seed = Yaml::parse($fences[1]);
                if (is_array($seed)
                    && is_string($seed['purpose'] ?? null) && trim($seed['purpose']) !== ''
                    && is_array($seed['actors'] ?? null) && $seed['actors'] !== []) {
                    $handle = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($parsed['handle'] ?? ''));
                    if ($handle !== '') {
                        $seedPath = storage_path('app/laraslice/drafts/'.$handle.'.mind.json');
                        if (! is_dir(dirname($seedPath))) {
                            mkdir(dirname($seedPath), 0775, true);
                        }
                        file_put_contents($seedPath, json_encode([
                            'seed' => $seed,
                            'system_name' => (string) ($parsed['name'] ?? 'System'),
                        ], JSON_PRETTY_PRINT));
                        $mind = [
                            'purpose' => trim($seed['purpose']),
                            'actors' => array_values(array_filter(array_map(fn ($a) => is_array($a) ? (string) ($a['name'] ?? '') : '', $seed['actors']))),
                            'journeys' => array_values(array_filter(array_map(fn ($j) => is_array($j) ? (string) ($j['name'] ?? '') : '', (array) ($seed['journeys'] ?? [])))),
                        ];
                    }
                }
            } catch (\Throwable) {
                $mind = null;
            }
        }

        return response()->json(['drafted' => true, 'source' => $yaml, 'name' => (string) ($parsed['name'] ?? ''), 'handle' => (string) ($parsed['handle'] ?? ''), 'mind' => $mind]);
    }

    /**
     * Apply a stashed brief Mind seed as the app's Mind
     * (porting/system.yaml). Refused when a Mind already
     * exists — an existing Mind is reviewed and edited in
     * Plan, never overwritten by a draft.
     */
    public function applyMindSeed(Request $request): mixed
    {
        $handle = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $request->input('handle', ''));
        if ($handle === '') {
            return response()->json(['applied' => false, 'reason' => 'A draft handle is required.'], 422);
        }
        $seedPath = storage_path('app/laraslice/drafts/'.$handle.'.mind.json');
        if (! is_file($seedPath)) {
            return response()->json(['applied' => false, 'reason' => 'No Mind seed is waiting for this draft. Draft from a brief first.'], 404);
        }
        $stash = json_decode((string) file_get_contents($seedPath), true);
        $seed = is_array($stash['seed'] ?? null) ? $stash['seed'] : null;
        if ($seed === null) {
            return response()->json(['applied' => false, 'reason' => 'The saved Mind seed is unreadable. Draft again.'], 422);
        }

        $written = SystemMind::applyBriefSeed($seed, (string) ($stash['system_name'] ?? 'System'));
        if ($written === null) {
            return response()->json(['applied' => false, 'reason' => 'This app already has a Mind (system.yaml). A brief seed never overwrites it — review and edit the Mind in Plan mode instead.'], 409);
        }

        return response()->json(['applied' => true]);
    }

    /**
     * Copilot propose-mode for the Mind: the AI proposes
     * journeys to ADD, as Plan-form lines. Nothing is saved
     * here — the lines land in the Plan form, and the existing
     * diff-before-save (preview, then Save) is the approval.
     * Proposals are validated against the real Mind: the actor
     * must already exist and the journey must be new.
     */
    public function proposeMindJourneys(Request $request): mixed
    {
        $instruction = trim((string) $request->input('instruction', ''));
        if (mb_strlen($instruction) < 8 || mb_strlen($instruction) > 1000) {
            return response()->json(['proposed' => false, 'reason' => 'Describe the journey to add in a sentence or two (8–1000 characters).'], 422);
        }

        $mind = SystemMind::load();
        if ($mind === null) {
            return response()->json(['proposed' => false, 'reason' => 'This app has no Mind yet. Seed one from a brief in Design first — proposals add to an existing Mind.']);
        }

        $engine = app(\LaraSlice\Core\Ai\AiEngine::class);
        $provider = $engine->getActiveProvider();
        $key = $engine->providerKey($provider);
        if ($key === null || $key === '') {
            return response()->json(['proposed' => false, 'reason' => "No AI provider is connected (active provider: {$provider}). Connect one under Settings → AI to propose with AI — or type the journey line directly in the field above, in the format shown."]);
        }

        $actors = array_values(array_filter(array_map(fn ($a) => (string) ($a['name'] ?? ''), (array) ($mind['actors'] ?? []))));
        $existing = array_values(array_filter(array_map(fn ($j) => (string) ($j['name'] ?? ''), (array) ($mind['journeys'] ?? []))));
        $brief = 'PURPOSE: '.(string) ($mind['system']['purpose'] ?? '')."\nACTORS: ".implode(', ', $actors)."\nEXISTING JOURNEYS: ".implode(', ', $existing)."\nINSTRUCTION: ".$instruction;

        $reply = $engine->callCustomProvider(self::MIND_JOURNEY_PROPOSE_PROMPT."\n\n".$brief, [], ['path' => '/laraslice/studio'], $provider);
        if ($reply === null || trim($reply) === '') {
            $providerError = null;
            try {
                $providerError = $engine->lastProviderError();
            } catch (\Throwable) {
                $providerError = null;
            }

            return response()->json(['proposed' => false, 'reason' => 'The AI provider did not answer. Check its connection under Settings → AI and try again.'.($providerError !== null && $providerError !== '' ? ' Provider said: '.$providerError : '')]);
        }

        $json = null;
        if (preg_match('/```(?:json)?\s*\n(.*?)```/s', $reply, $m)) {
            $json = trim($m[1]);
        } elseif (str_starts_with(trim($reply), '[')) {
            $json = trim($reply);
        }
        $items = $json !== null ? json_decode($json, true) : null;
        if (! is_array($items)) {
            return response()->json(['proposed' => false, 'reason' => 'The AI reply did not contain a journey proposal. Try naming the actor and the steps.']);
        }

        $proposals = [];
        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $actor = trim((string) ($item['actor'] ?? ''));
            $steps = array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) ($item['steps'] ?? [])), fn ($s) => $s !== ''));
            $outcome = trim((string) ($item['outcome'] ?? ''));
            if ($name === '' || $steps === [] || ! in_array($actor, $actors, true)) {
                continue; // unknown actor or empty journey — never proposed
            }
            if (in_array(strtolower($name), array_map('strtolower', $existing), true)) {
                continue; // already in the Mind — proposals only ADD
            }
            $proposals[] = ['name' => $name, 'actor' => $actor, 'steps' => $steps, 'outcome' => $outcome];
            $lines[] = $name.' | '.$actor.' | '.implode(' > ', $steps).' | '.$outcome;
            if (count($proposals) >= 3) {
                break;
            }
        }

        if ($proposals === []) {
            return response()->json(['proposed' => false, 'reason' => 'The AI did not propose an addable journey from that instruction. Name one of the Mind\'s actors ('.implode(', ', $actors).') and the steps they take.']);
        }

        return response()->json(['proposed' => true, 'journeys' => $proposals, 'lines' => $lines]);
    }

    /**
     * Design drafts: a blueprint being designed in the Studio
     * can be saved server-side and resumed later, instead of
     * evaporating when the developer navigates away. Drafts
     * are working state (storage), never declarations — the
     * slice itself changes only through Manage/generation.
     */
    private function draftPath(string $handle): ?string
    {
        $handle = preg_replace('/[^a-z0-9_\-]/i', '', $handle);
        if ($handle === '' || $handle === null) {
            return null;
        }

        return storage_path('app/laraslice/drafts/'.$handle.'.blueprint.yaml');
    }

    public function blueprintDraft(Request $request): mixed
    {
        $path = $this->draftPath((string) $request->query('handle', ''));
        if ($path === null || ! is_file($path)) {
            return response()->json(['exists' => false]);
        }

        return response()->json([
            'exists' => true,
            'source' => (string) file_get_contents($path),
            'saved_at' => date('Y-m-d H:i:s', (int) filemtime($path)),
        ]);
    }

    /**
     * Engine-truth validation for the Design editor's status badge.
     * Runs the exact front half of plan() (parse → sanitize → the
     * engine's BlueprintValidator) so the Studio's verdict can never
     * diverge from slice:blueprint:plan again (Day-2 drill F8: the
     * in-browser check only parsed YAML, while the engine rejected
     * the Studio's own emitted length: '255'). Pure check — no writes.
     */
    public function blueprintValidate(Request $request, BlueprintLoader $loader, BlueprintValidator $validator): mixed
    {
        $input = $request->validate([
            'source' => ['required', 'string', 'max:1048576'],
            'format' => ['nullable', 'in:yaml,yml,json'],
        ]);

        try {
            $parsed = $loader->parse($input['source'], $input['format'] ?? 'yaml');
            $parsed = $this->sanitizeBlueprint($parsed);
            $validator->validate($parsed);

            return response()->json(['valid' => true, 'errors' => []]);
        } catch (BlueprintValidationException $exception) {
            return response()->json(['valid' => false, 'errors' => $exception->errors()]);
        } catch (Throwable $exception) {
            return response()->json(['valid' => false, 'errors' => [$exception->getMessage()]]);
        }
    }

    public function blueprintDraftSave(Request $request): mixed
    {
        $path = $this->draftPath((string) $request->input('handle', ''));
        $source = (string) $request->input('source', '');
        if ($path === null || trim($source) === '' || strlen($source) > 524288) {
            return response()->json(['saved' => false, 'message' => 'A draft needs a slice handle and a non-empty blueprint (max 512 KB).'], 422);
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $source);

        return response()->json(['saved' => true, 'saved_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Plan impact view: what the Mind's journeys touch in the
     * built system, computed from the same declarations
     * slice:flow renders (journey steps linked to slices by
     * their navigation labels — a labelled heuristic, exactly
     * as the App Flow artefact states). A journey with no
     * linked step is declared intent with no surface yet.
     *
     * When the request carries the Plan form's journeys text, a
     * candidate impact is computed alongside: the same journeys
     * parsed by the form parser Plan saves with, so the editor
     * sees — before saving — which journeys the edit adds,
     * removes, or leaves without a surface.
     */
    public function planImpact(Request $request): mixed
    {
        $mind = SystemMind::load();
        if ($mind === null) {
            return response()->json(['has_mind' => false, 'journeys' => [], 'pages' => 0, 'sections' => 0, 'candidate' => null]);
        }

        $pages = \LaraSlice\Support\AppFlow::pagesFromSlices(app(SliceManager::class)->discoveredSlices());
        $summarize = function (array $m) use ($pages): array {
            $flow = \LaraSlice\Support\AppFlow::build($m, $pages);
            $rows = [];
            foreach ($flow['journeys'] as $journey) {
                $linked = array_values(array_filter($journey['steps'], fn ($s) => $s['slice'] !== null));
                $rows[] = [
                    'name' => $journey['name'],
                    'actor' => $journey['actor'],
                    'steps' => count($journey['steps']),
                    'linked' => count($linked),
                    'slices' => array_values(array_unique(array_map(fn ($s) => $s['slice'], $linked))),
                    'covered' => $linked !== [],
                ];
            }

            return ['rows' => $rows, 'pages' => count($pages), 'sections' => count($flow['sections'])];
        };

        $current = $summarize($mind);
        $payload = [
            'has_mind' => true,
            'journeys' => $current['rows'],
            'pages' => $current['pages'],
            'sections' => $current['sections'],
            'candidate' => null,
        ];

        if ($request->has('journeys')) {
            $candidate = $mind;
            $candidate['journeys'] = SystemMind::applyForm($mind, ['journeys' => (string) $request->input('journeys', '')])['journeys'] ?? [];
            $cand = $summarize($candidate);
            $curNames = array_map(fn ($r) => $r['name'], $current['rows']);
            $candNames = array_map(fn ($r) => $r['name'], $cand['rows']);
            $payload['candidate'] = [
                'journeys' => $cand['rows'],
                'added' => array_values(array_diff($candNames, $curNames)),
                'removed' => array_values(array_diff($curNames, $candNames)),
            ];
        }

        return response()->json($payload);
    }

    /** Plan mode: diff-before-save for the Mind (system.yaml). */
    public function planPreview(Request $request): mixed
    {
        $mind = SystemMind::load() ?? ['system' => ['name' => config('app.name')]];
        $updated = SystemMind::applyForm($mind, $request->only(['purpose', 'actors', 'roles', 'journeys', 'metrics', 'operations']));
        $before = SystemMind::path() !== null ? (string) file_get_contents(SystemMind::path()) : '';

        return response()->json(['diff' => SystemMind::diff($before, SystemMind::render($updated))]);
    }

    public function planSave(Request $request): mixed
    {
        $mind = SystemMind::load() ?? ['system' => ['name' => config('app.name')]];
        $updated = SystemMind::applyForm($mind, $request->only(['purpose', 'actors', 'roles', 'journeys', 'metrics', 'operations']));
        $path = SystemMind::path() ?? SystemMind::defaultPath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, SystemMind::render($updated));

        return response()->json(['saved' => true, 'path' => $path]);
    }

    public function panel(string $mode, Request $request): mixed
    {
        // Panels are fragments the Studio shell fetches (it sends
        // X-Requested-With). A direct visit — refresh, deep link,
        // bookmark — would show a naked unstyled fragment, so send
        // it to the shell in the same mode instead.
        if (! $request->ajax()) {
            return redirect(route('laraslice.studio').'?mode='.$mode);
        }

        if ($mode === 'plan') {
            $mind = SystemMind::load();

            return view('laraslice::partials.plan-panel', [
                'mind' => $mind,
                'mindPath' => SystemMind::path(),
                'form' => $mind !== null ? SystemMind::toForm($mind) : ['purpose' => '', 'actors' => '', 'roles' => '', 'journeys' => '', 'metrics' => '', 'operations' => ''],
            ]);
        }

        if ($mode === 'operate') {
            $mind = SystemMind::load();

            return view('laraslice::partials.operate-panel', [
                'health' => SystemOperate::health(),
                'backups' => SystemOperate::backups(),
                'backupPolicy' => $mind['operations']['backups'] ?? null,
                'sync' => $mind['operations']['sync'] ?? null,
                'audit' => SystemOperate::recentAudit(),
                'access' => SystemOperate::accessReview(),
                'quality' => SystemOperate::quality(),
                'git' => SystemOperate::git(),
            ]);
        }

        if ($mode === 'port') {
            return view('laraslice::partials.port-panel');
        }

        if ($mode === 'schema') {
            $view = app(WizardController::class)->schemaStudioView();
            if ($view instanceof View) {
                return view('laraslice::partials.schema-panel', $view->getData());
            }

            return $view;
        }

        if ($mode === 'create' || $mode === 'manage') {
            return view('laraslice::partials.wizard-panel', [
                'initialTab' => $mode === 'manage' ? 'studio' : 'wizard',
            ]);
        }

        if ($mode === 'design') {
            $view = $this->buildStudioView($request);
            if ($view instanceof View) {
                return view('laraslice::partials.design-panel', $view->getData());
            }

            return $view;
        }

        abort(404);
    }

    /**
     * The Porting Studio modal markup, served on first open.
     * The modal partial is pure Alpine markup over the Design
     * component's state (it uses no Blade variables), so it
     * renders standalone here and joins the component's scope
     * when the factory injects it into the panel's slot —
     * its ~100 KB no longer rides in every Design fragment.
     */
    public function blueprintPortingModal(): mixed
    {
        return view('laraslice::partials.porting-modal');
    }
}
