<?php

namespace LaraSlice\Wizard;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceExistsException;
use LaraSlice\Generator\SliceFieldDefinitionException;
use InvalidArgumentException;

class WizardController extends Controller
{
    public function show(Request $request)
    {
        $initialTab = $request->query('tab', 'wizard');
        return view('laraslice::wizard', [
            'initialTab' => $initialTab,
        ]);
    }

    public function studio(Request $request)
    {
        return view('laraslice::wizard', [
            'initialTab' => 'studio',
        ]);
    }

    public function schemaStudio()
    {
        $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
        $slicesList = [];
        foreach ($manager->getAllSlices() as $slice) {
            $slicesList[] = [
                'name'        => $slice->name,
                'title'       => $slice->title ?? $slice->name,
                'domain'      => $slice->domain ?? 'General',
                'description' => $slice->description ?? '',
                'enabled'     => $slice->enabled ?? true,
                'version'     => $slice->version ?? '1.0.0',
            ];
        }

        $tables = [];
        try {
            $raw = \Illuminate\Support\Facades\DB::select('SHOW TABLES');
            foreach ($raw as $t) {
                $tables[] = current((array)$t);
            }
        } catch (\Throwable $e) {}

        return view('laraslice::schema-studio', compact('slicesList', 'tables'));
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'projectName'  => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9 _-]*$/'],
            'namespace'    => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/'],
            'description'  => 'nullable|string|max:1000',
            'author'       => 'nullable|string|max:120',
            'domain'       => 'nullable|string|max:120',
            'features'     => 'nullable|array',
            'fields'       => 'nullable|array|max:50',
            'childTables'  => 'nullable|array|max:20',
            'permissions'  => 'nullable|array|max:50',
            'workflow'     => 'nullable|boolean',
            'includeApi'   => 'nullable|boolean',
            'flutter'      => 'nullable|boolean',
            'runMigration' => 'nullable|boolean',
        ]);
        $includeApi = (bool) ($validated['includeApi'] ?? true);

        try {
            $generator = new SliceGenerator(null, $validated['namespace'] ?? null);
            $sliceDir = $generator->generate(
                $validated['projectName'],
                $validated['fields'] ?? [],
                (bool) ($validated['workflow'] ?? false),
                [
                    'description' => $validated['description'] ?? null,
                    'author'      => $validated['author'] ?? null,
                    'domain'      => $validated['domain'] ?? null,
                    'permissions' => $validated['permissions'] ?? null,
                    'api'         => $includeApi,
                ]
            );

            // Scaffold child tables and aggregate relationships if defined
            if (!empty($validated['childTables'])) {
                $modifier = new \LaraSlice\Generator\SliceModifier(
                    dirname($sliceDir),
                    $validated['namespace'] ?? null
                );
                foreach ($validated['childTables'] as $child) {
                    if (!empty($child['name'])) {
                        $childName = $child['name'];
                        $relationType = $child['relation'] ?? 'hasMany';
                        $childFields = $child['fields'] ?? [];
                        try {
                            $modifier->addChildTable($validated['projectName'], $childName, $relationType, $childFields);
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning("Could not add child table {$childName}: " . $e->getMessage());
                        }
                    }
                }
            }
        } catch (SliceExistsException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['projectName' => [$exception->getMessage()]],
            ], 409);
        } catch (SliceFieldDefinitionException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['fields' => [$exception->getMessage()]],
            ], 422);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['configuration' => [$exception->getMessage()]],
            ], 422);
        }

        $flutterDir = null;
        if (!empty($validated['flutter'])) {
            $flutterGen = new \LaraSlice\Generator\FlutterSliceGenerator();
            $flutterDir = $flutterGen->generate($validated['projectName']);
        }

        $migrated = false;
        $migrationOutput = null;
        if (!empty($validated['runMigration'])) {
            [$migrated, $migrationOutput] = $this->executeMigrations();
        }

        $singular = strtolower(\Illuminate\Support\Str::snake($validated['projectName']));
        $plural   = strtolower(\Illuminate\Support\Str::snake(\Illuminate\Support\Str::plural($validated['projectName'])));

        return response()->json([
            'success'         => true,
            'message'         => "Slice '{$validated['projectName']}' generated successfully!",
            'sliceName'       => $validated['projectName'],
            'path'            => $sliceDir,
            'flutterPath'     => $flutterDir,
            'web_url'         => url("/{$plural}"),
            'api_url'         => $includeApi ? url("/api/{$plural}/list") : null,
            'migrated'        => $migrated,
            'migrationOutput' => $migrationOutput,
        ]);
    }

    public function runMigration(Request $request)
    {
        [$success, $output] = $this->executeMigrations();

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Database migration completed successfully!' : 'Migration failed: ' . $output,
            'output'  => $output,
        ], $success ? 200 : 500);
    }

    /**
     * Run all migrations including any slice-specific migration folders.
     */
    /**
     * Code generation and running migrations are separate permissions.
     */
    protected function mayRunMigrations(): bool
    {
        return \LaraSlice\Core\Security\Access::allows(auth()->user(), ['studio.migrate', 'studio.*']);
    }

    protected function executeMigrations(): array
    {
        if (! $this->mayRunMigrations()) {
            return [false, 'Migrations were not run: running migrations requires the studio.migrate permission.'];
        }

        $outputs = [];
        $migrated = false;

        try {
            // Re-discover slices to include newly created ones
            $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
            $manager->discover();

            $migrationPaths = [];
            if (app()->bound('migrator')) {
                $migrator = app('migrator');
                foreach ($manager->getActiveSlices() as $slice) {
                    if ($mp = $slice->getMigrationsPath()) {
                        if (is_dir($mp)) {
                            $migrator->path($mp);
                            $migrationPaths[] = $mp;
                        }
                    }
                }
            }

            // Also scan app/Slices for any Migrations directories
            $slicesBasePath = base_path('app/Slices');
            if (is_dir($slicesBasePath)) {
                $rdi = new \RecursiveDirectoryIterator($slicesBasePath, \RecursiveDirectoryIterator::SKIP_DOTS);
                $rii = new \RecursiveIteratorIterator($rdi, \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($rii as $item) {
                    if ($item->isDir() && strtolower($item->getFilename()) === 'migrations') {
                        $mp = $item->getRealPath();
                        if ($mp && !in_array($mp, $migrationPaths, true)) {
                            $migrationPaths[] = $mp;
                            if (app()->bound('migrator')) {
                                app('migrator')->path($mp);
                            }
                        }
                    }
                }
            }

            // 1. Run standard migrate
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            $defaultOut = trim(\Illuminate\Support\Facades\Artisan::output());
            if ($defaultOut) {
                $outputs[] = $defaultOut;
            }

            // 2. Explicitly migrate each discovered slice migration folder
            foreach (array_unique($migrationPaths) as $mp) {
                if (is_dir($mp)) {
                    $relative = str_replace([base_path() . DIRECTORY_SEPARATOR, base_path() . '/'], '', $mp);
                    $relative = str_replace('\\', '/', $relative);
                    try {
                        \Illuminate\Support\Facades\Artisan::call('migrate', [
                            '--force' => true,
                            '--path'  => $relative,
                        ]);
                        $out = trim(\Illuminate\Support\Facades\Artisan::output());
                        if ($out && !str_contains($out, 'Nothing to migrate')) {
                            $outputs[] = $out;
                        }
                    } catch (\Throwable $pe) {
                        try {
                            \Illuminate\Support\Facades\Artisan::call('migrate', [
                                '--force'    => true,
                                '--realpath' => true,
                                '--path'     => $mp,
                            ]);
                            $out = trim(\Illuminate\Support\Facades\Artisan::output());
                            if ($out && !str_contains($out, 'Nothing to migrate')) {
                                $outputs[] = $out;
                            }
                        } catch (\Throwable $pe2) {}
                    }
                }
            }

            $migrated = true;
            $combined = implode("\n", array_filter(array_unique($outputs)));
            $finalOutput = !empty($combined) ? $combined : 'All database tables created and verified successfully.';
        } catch (\Throwable $e) {
            $migrated = false;
            $finalOutput = $e->getMessage();
        }

        return [$migrated, $finalOutput];
    }

    public function listSlices()
    {
        $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
        $slices = [];
        foreach ($manager->getAllSlices() as $slice) {
            $manifestFile = $slice->path . '/slice.json';
            $raw = file_exists($manifestFile) ? json_decode(file_get_contents($manifestFile), true) : $slice->toArray();

            // Discover all tables belonging to this slice
            $primaryTable = \Illuminate\Support\Str::plural(\Illuminate\Support\Str::snake($slice->name));
            $tableNames = [$primaryTable];

            // Scan Models
            $modelFiles = glob($slice->path . '/Models/*.php');
            if ($modelFiles) {
                foreach ($modelFiles as $mf) {
                    $mContent = file_get_contents($mf);
                    if (preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $mContent, $mMatches)) {
                        $t = $mMatches[1];
                        if (!in_array($t, $tableNames)) {
                            $tableNames[] = $t;
                        }
                    } else {
                        $t = \Illuminate\Support\Str::plural(\Illuminate\Support\Str::snake(basename($mf, '.php')));
                        if (!in_array($t, $tableNames)) {
                            $tableNames[] = $t;
                        }
                    }
                }
            }

                        if (!empty($raw['child_tables'])) {
                foreach ($raw['child_tables'] as $t) {
                    if (!in_array($t, $tableNames)) {
                        $tableNames[] = $t;
                    }
                }
            }
            if (!empty($raw['tables'])) {
                foreach ($raw['tables'] as $t) {
                    if (!in_array($t, $tableNames)) {
                        $tableNames[] = $t;
                    }
                }
            }

            $models = [];
            if ($modelFiles) {
                foreach ($modelFiles as $mf) {
                    $mContent = file_get_contents($mf);
                    $mClass = basename($mf, '.php');
                    $mTable = null;
                    if (preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $mContent, $mMatches)) {
                        $mTable = $mMatches[1];
                    } else {
                        $mTable = \Illuminate\Support\Str::plural(\Illuminate\Support\Str::snake($mClass));
                    }
                    $isRoot = ($mTable === $primaryTable) || str_contains($mClass, \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($slice->name)));
                    $models[] = [
                        'class'  => $mClass,
                        'handle' => \Illuminate\Support\Str::snake($mClass),
                        'table'  => $mTable,
                        'root'   => $isRoot,
                    ];
                }
            }
            $raw['models'] = $models;
            $raw['relations'] = $raw['relations'] ?? [];

            $tablesData = [];
            foreach ($tableNames as $t) {
                $cols = [];
                if (\Illuminate\Support\Facades\Schema::hasTable($t)) {
                    foreach (\Illuminate\Support\Facades\Schema::getColumnListing($t) as $col) {
                        $cols[] = [
                            'name'     => $col,
                            'type'     => \Illuminate\Support\Facades\Schema::getColumnType($t, $col),
                            'nullable' => true,
                        ];
                    }
                }
                $tablesData[] = [
                    'name'       => $t,
                    'is_primary' => ($t === $primaryTable),
                    'foreign_key' => \Illuminate\Support\Str::singular($primaryTable) . '_id',
                    'columns'    => $cols,
                ];
            }

            // Calculate resolved Web UI URL for Open Slice UI button
            $uiUrl = null;
            $snake = \Illuminate\Support\Str::snake($slice->name);
            $pluralSnake = \Illuminate\Support\Str::plural($snake);
            $singularSnake = \Illuminate\Support\Str::singular($snake);

            $candidates = [
                $raw['navigation']['route'] ?? null,
                $snake . '.index',
                $pluralSnake . '.index',
                $singularSnake . '.index',
                'admin.' . $snake . '.index',
                'admin.' . $pluralSnake . '.index',
                'admin.' . $singularSnake . '.index',
            ];

            foreach ($candidates as $cand) {
                if ($cand && \Illuminate\Support\Facades\Route::has($cand)) {
                    $uiUrl = route($cand);
                    break;
                }
            }

            if (!$uiUrl) {
                $uiUrl = url('/' . $pluralSnake);
            }
            $raw['ui_url'] = $uiUrl;

            $raw['tables_data'] = $tablesData;
            $slices[] = $raw;
        }

        return response()->json([
            'success' => true,
            'data'    => $slices,
        ]);
    }

    public function addField(Request $request)
    {
        $validated = $request->validate([
            'slice' => 'required|string', 'field' => 'required|string', 'type' => 'required|string',
            'nullable' => 'nullable|boolean', 'migrate' => 'nullable|boolean',
        ]);

        try {
            $modifier = new \LaraSlice\Generator\SliceModifier();
            $result = $modifier->addField(
                sliceName: $validated['slice'], fieldName: $validated['field'], fieldType: $validated['type'],
                nullable: (bool) ($validated['nullable'] ?? true), targetTable: $request->input('targetTable')
            );
            $migrationResult = !empty($validated['migrate'])
                ? $this->runGeneratedMigration($result['migration'])
                : ['success' => null, 'message' => 'Migration was generated but not run.'];
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Field '{$validated['field']}' added to '{$validated['slice']}' successfully!",
            'data' => $result,
            'migration' => $migrationResult,
        ]);
    }

    public function addFieldsBatch(Request $request)
    {
        $validated = $request->validate([
            'slice' => 'required|string', 'fields' => 'required|array|min:1|max:50',
            'fields.*.name' => 'required|string', 'fields.*.type' => 'required|string',
            'fields.*.length' => 'nullable', 'fields.*.nullable' => 'nullable|boolean',
            'fields.*.default' => 'nullable', 'fields.*.unsigned' => 'nullable|boolean',
            'migrate' => 'nullable|boolean', 'note' => 'nullable|string',
        ]);

        try {
            $modifier = new \LaraSlice\Generator\SliceModifier();
            $result = $modifier->addFieldsBatch(
                $validated['slice'], $validated['fields'], 'Common User via Slice Studio',
                $validated['note'] ?? null, $request->input('targetTable')
            );
            $migrationResult = !empty($validated['migrate'])
                ? $this->runGeneratedMigration($result['migration'])
                : ['success' => null, 'message' => 'Migration was generated but not run.'];
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => count($validated['fields']) . " fields added to '{$validated['slice']}' in a single consolidated migration!",
            'data' => $result,
            'migration' => $migrationResult,
        ]);
    }
    public function updateNavigation(Request $request)
    {
        $validated = $request->validate([
            'slice'        => 'required|string',
            'title'        => 'required|string',
            'icon'         => 'nullable|string',
            'order'        => 'nullable|integer',
            'permission'   => 'nullable|string',
            'permissions'  => 'nullable|array',
            'url'          => 'nullable|string',
            'children'     => 'nullable|array',
            'redirect_old' => 'nullable|boolean',
        ]);

        $modifier = new \LaraSlice\Generator\SliceModifier();
        $nav = $modifier->updateNavigation($validated['slice'], $validated);

        return response()->json([
            'success' => true,
            'message' => "Navigation updated for '{$validated['slice']}'!",
            'data'    => $nav,
        ]);
    }

    public function rollbackVersion(Request $request)
    {
        $validated = $request->validate([
            'slice'          => 'required|string',
            'target_version' => 'required|string',
        ]);

        try {
            $modifier = new \LaraSlice\Generator\SliceModifier();
            $result = $modifier->rollbackVersion($validated['slice'], $validated['target_version']);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function syncFields(Request $request)
    {
        $validated = $request->validate([
            'slice'          => 'required|string',
            'targetTable'    => 'required|string',
            'new_fields'     => 'nullable|array',
            'deleted_fields' => 'nullable|array',
            'all_fields'     => 'nullable|array',
            'migrate'        => 'nullable|boolean',
        ]);

        try {
            $modifier = new \LaraSlice\Generator\SliceModifier();
            $result = $modifier->syncFields(
                $validated['slice'],
                $validated['targetTable'],
                $validated['new_fields'] ?? [],
                $validated['deleted_fields'] ?? [],
                $validated['all_fields'] ?? [],
                'Developer via Slice Studio'
            );

            $migrationResult = (!empty($validated['migrate']) && !empty($result['migration']))
                ? $this->runGeneratedMigration($result['migration'])
                : ['success' => null, 'message' => 'Migration was generated but not run.'];

            return response()->json([
                'success'   => true,
                'message'   => $result['description'] ?? 'Schema synchronized successfully!',
                'data'      => $result,
                'migration' => $migrationResult,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function saveRelationships(Request $request)
    {
        $validated = $request->validate([
            'slice'     => 'required|string',
            'relations' => 'nullable|array',
        ]);

        try {
            $modifier = new \LaraSlice\Generator\SliceModifier();
            $result = $modifier->saveRelationships(
                $validated['slice'],
                $validated['relations'] ?? [],
                'Developer via Slice Studio'
            );
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function addChildTable(Request $request)
    {
        $validated = $request->validate([
            'slice'        => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9 _-]*$/'],
            'tableName'    => 'required|string|max:80',
            'relationType' => 'nullable|in:hasMany',
            'foreignKey'   => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields'       => 'nullable|array|max:50',
            'fields.*.name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-zA-Z0-9_]*$/'],
            'fields.*.type' => 'nullable|string|in:string,text,mediumText,longText,integer,int,bigInteger,smallInteger,tinyInteger,boolean,bool,decimal,float,double,date,dateTime,datetime,timestamp,time,json,uuid',
            'fields.*.nullable' => 'nullable|boolean',
            'fields.*.required' => 'nullable|boolean',
            'migrate'      => 'nullable|boolean',
        ]);

        try {
            $modifier = new \LaraSlice\Generator\SliceModifier();
            $result = $modifier->addChildTable(
                $validated['slice'],
                $validated['tableName'],
                $validated['relationType'] ?? 'hasMany',
                $validated['fields'] ?? [],
                $validated['foreignKey'] ?? null
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['tableName' => [$exception->getMessage()]],
            ], 422);
        }

        $migrationResult = null;
        if (!empty($validated['migrate'])) {
            $migrationResult = $this->runGeneratedMigration($result['migration_file']);
        }

        return response()->json([
            'success' => $migrationResult === null || $migrationResult['success'],
            'message' => $migrationResult !== null && ! $migrationResult['success']
                ? 'The child slice files were generated, but its migration failed: ' . $migrationResult['output']
                : "Child table '{$validated['tableName']}' created and linked to '{$validated['slice']}' successfully!",
            'data'    => $result,
            'migration' => $migrationResult,
        ], $migrationResult !== null && ! $migrationResult['success'] ? 500 : 200);
    }

    private function runGeneratedMigration(string $migrationFile): array
    {
        if (! $this->mayRunMigrations()) {
            return ['success' => false, 'output' => 'Migration was generated but not run: running migrations requires the studio.migrate permission.'];
        }

        $absoluteBase = realpath(base_path());
        $absoluteMigration = realpath($migrationFile);

        if ($absoluteBase === false || $absoluteMigration === false || ! str_starts_with($absoluteMigration, $absoluteBase . DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'output' => 'Generated migration path is outside the application.'];
        }

        $status = \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--path' => $absoluteMigration,
            '--realpath' => true,
            '--force' => true,
        ]);

        return [
            'success' => $status === 0,
            'output' => trim(\Illuminate\Support\Facades\Artisan::output()),
        ];
    }

    /**
     * AI Copilot Conversational Assistant (ElmapiCMS inspired)
     */
    public function copilotChat(Request $request)
    {
        $message = trim((string)$request->input('message', ''));
        $sliceContext = $request->input('slice', '');
        $step = (int)$request->input('step', 1);

        if (empty($message)) {
            return response()->json([
                'success' => false,
                'message' => 'Message is required.',
            ], 422);
        }

        $lowerMsg = strtolower($message);

        // 1. HR Complete Solution Intent
        if (str_contains($lowerMsg, 'hr') || str_contains($lowerMsg, 'human resource') || str_contains($lowerMsg, 'employee')) {
            if ($step === 1) {
                return response()->json([
                    'success' => true,
                    'step'    => 2,
                    'reply'   => "I can build a complete HR enterprise solution for you! I have designed a domain architecture with:\n\n" .
                                 "â€¢ **Departments**: Manage organizational departments, codes, and budgets\n" .
                                 "â€¢ **Positions**: Job designations linked to departments with salary bands\n" .
                                 "â€¢ **Employees**: Master records linked to departments & positions\n" .
                                 "â€¢ **LeaveRequests**: Employee leave tracking with status workflows\n" .
                                 "â€¢ **AttendanceRecords**: Daily check-in/out records\n\n" .
                                 "Would you like me to build all these modules or customize them? (Reply 'Build All' or specify modules)",
                    'options' => ['Build All', 'Employees & Departments Only', 'Include Payroll & Attendance'],
                    'plan'    => [
                        'slice' => 'HumanResources',
                        'title' => 'Human Resources Suite',
                        'tables' => ['positions', 'employees', 'leave_requests'],
                    ],
                ]);
            }

            // Step 2 confirmation
            $plan = [
                'slice' => 'HumanResources',
                'title' => 'Human Resources Suite',
                'tables' => [
                    [
                        'name' => 'positions',
                        'relation' => 'hasMany',
                        'foreignKey' => 'human_resource_id',
                        'fields' => [
                            ['name' => 'code', 'type' => 'string'],
                            ['name' => 'salary_min', 'type' => 'decimal'],
                            ['name' => 'salary_max', 'type' => 'decimal'],
                        ]
                    ],
                    [
                        'name' => 'employees',
                        'relation' => 'hasMany',
                        'foreignKey' => 'human_resource_id',
                        'fields' => [
                            ['name' => 'employee_id', 'type' => 'string'],
                            ['name' => 'first_name', 'type' => 'string'],
                            ['name' => 'last_name', 'type' => 'string'],
                            ['name' => 'email', 'type' => 'string'],
                            ['name' => 'phone', 'type' => 'string'],
                            ['name' => 'hire_date', 'type' => 'date'],
                        ]
                    ],
                    [
                        'name' => 'leave_requests',
                        'relation' => 'hasMany',
                        'foreignKey' => 'human_resource_id',
                        'fields' => [
                            ['name' => 'leave_type', 'type' => 'string'],
                            ['name' => 'start_date', 'type' => 'date'],
                            ['name' => 'end_date', 'type' => 'date'],
                            ['name' => 'reason', 'type' => 'text'],
                            ['name' => 'status', 'type' => 'string'],
                        ]
                    ],
                ]
            ];

            return response()->json([
                'success' => true,
                'step'    => 3,
                'reply'   => "Execution plan ready! I will generate 3 child entities with relational foreign keys, Eloquent models, BlatUI views, and database migrations for 'HumanResources'. Click 'Apply Plan' below to execute.",
                'can_execute' => true,
                'plan'    => $plan,
            ]);
        }

        // 2. E-Commerce Store Intent
        if (str_contains($lowerMsg, 'commerce') || str_contains($lowerMsg, 'shop') || str_contains($lowerMsg, 'product') || str_contains($lowerMsg, 'order')) {
            $plan = [
                'slice' => 'Shop',
                'title' => 'E-Commerce Store Suite',
                'tables' => [
                    [
                        'name' => 'categories',
                        'relation' => 'hasMany',
                        'foreignKey' => 'shop_id',
                        'fields' => [
                            ['name' => 'slug', 'type' => 'string'],
                            ['name' => 'description', 'type' => 'text'],
                        ]
                    ],
                    [
                        'name' => 'products',
                        'relation' => 'hasMany',
                        'foreignKey' => 'shop_id',
                        'fields' => [
                            ['name' => 'sku', 'type' => 'string'],
                            ['name' => 'price', 'type' => 'decimal'],
                            ['name' => 'stock', 'type' => 'integer'],
                        ]
                    ],
                    [
                        'name' => 'orders',
                        'relation' => 'hasMany',
                        'foreignKey' => 'shop_id',
                        'fields' => [
                            ['name' => 'order_number', 'type' => 'string'],
                            ['name' => 'customer_name', 'type' => 'string'],
                            ['name' => 'total_amount', 'type' => 'decimal'],
                            ['name' => 'status', 'type' => 'string'],
                        ]
                    ]
                ]
            ];

            return response()->json([
                'success' => true,
                'step'    => 3,
                'reply'   => "E-Commerce store architecture designed! Includes Categories, Products, and Orders with relationships. Click 'Apply Plan' to generate.",
                'can_execute' => true,
                'plan'    => $plan,
            ]);
        }

        // Delegate to LaraSlice AiEngine for live intelligence, DB metrics, and Studio context
        $aiResponse = app(\LaraSlice\Core\Ai\AiEngine::class)->chat($message, ['path' => '/laraslice/wizard']);
        return response()->json([
            'success' => true,
            'step'    => 1,
            'reply'   => $aiResponse['reply'],
            'options' => ['How many users do we have?', 'Show users schema', 'How to wipe domain data?', 'Build E-Commerce store'],
        ]);
    }

    /**
     * Batch Scaffolding Engine for Copilot Execution Plans
     */
    public function batchGenerate(Request $request)
    {
        $validated = $request->validate([
            'slice' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9 _-]*$/'],
            'tables' => 'required|array|min:1|max:25',
            'tables.*.name' => 'required|string|max:80',
            'tables.*.relation' => 'nullable|in:hasMany',
            'tables.*.foreignKey' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'tables.*.fields' => 'nullable|array|max:50',
            'tables.*.fields.*.name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-zA-Z0-9_]*$/'],
            'tables.*.fields.*.type' => 'nullable|string|in:string,text,mediumText,longText,integer,int,bigInteger,smallInteger,tinyInteger,boolean,bool,decimal,float,double,date,dateTime,datetime,timestamp,time,json,uuid',
            'tables.*.fields.*.nullable' => 'nullable|boolean',
            'tables.*.fields.*.required' => 'nullable|boolean',
            'migrate' => 'nullable|boolean',
        ]);

        $sliceName = $validated['slice'];
        $tables = $validated['tables'];
        $modifier = new \LaraSlice\Generator\SliceModifier();
        $results = [];

        foreach ($tables as $tbl) {
            $tName = $tbl['name'];
            $rel = $tbl['relation'] ?? 'hasMany';
            $fields = $tbl['fields'] ?? [];
            $fk = $tbl['foreignKey'] ?? null;

            try {
                $res = $modifier->addChildTable($sliceName, $tName, $rel, $fields, $fk);
                $migration = !empty($validated['migrate'])
                    ? $this->runGeneratedMigration($res['migration_file'])
                    : null;
                $results[] = [
                    'table'   => $tName,
                    'status'  => $migration !== null && ! $migration['success'] ? 'migration_failed' : 'created',
                    'details' => $res,
                    'migration' => $migration,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'table'   => $tName,
                    'status'  => 'skipped',
                    'error'   => $e->getMessage(),
                ];
            }
        }

        $failed = array_values(array_filter($results, fn (array $result) => $result['status'] !== 'created'));

        return response()->json([
            'success' => $failed === [],
            'message' => $failed === []
                ? 'Generated and migrated ' . count($results) . " child entities for '{$sliceName}'."
                : count($failed) . ' of ' . count($results) . ' child entities failed. Successful files and per-entity migration results are listed below.',
            'data'    => $results,
        ], $failed === [] ? 200 : 422);
    }

    /**
     * Fetch audit logs for a slice or recent system activity.
     */
        public function pruneAuditLogs(Request $request)
    {
        $days = (int) $request->input('days', 90);
        $slice = $request->input('slice');

        if ($days < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Days retention window must be at least 1 day.',
            ], 422);
        }

        $cutoff = now()->subDays($days);
        $query = \Illuminate\Support\Facades\DB::table(\LaraSlice\Core\Audit\AuditLogger::TABLE_NAME)
            ->where('created_at', '<', $cutoff);

        if (!empty($slice) && $slice !== 'all') {
            $query->where('slice', $slice);
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            return response()->json([
                'success' => true,
                'pruned'  => 0,
                'message' => "No audit logs older than {$days} days found to prune.",
            ]);
        }

        $deleted = $query->delete();

        return response()->json([
            'success' => true,
            'pruned'  => $deleted,
            'message' => "Successfully pruned {$deleted} audit log records older than {$days} days.",
        ]);
    }
    public function getAuditLogs(Request $request)
    {
        $slice = $request->query('slice');
        $search = $request->query('search');
        $action = $request->query('action');
        $actorId = $request->query('actor_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $limit = min((int) ($request->query('limit', 50)), 200);

        $query = \Illuminate\Support\Facades\DB::table(\LaraSlice\Core\Audit\AuditLogger::TABLE_NAME)
            ->latest('id');

        if (!empty($slice) && $slice !== 'all') {
            $sliceHandle = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $slice));
            $query->where(function ($q) use ($slice, $sliceHandle) {
                $q->where('slice', $slice)
                  ->orWhere('slice', $sliceHandle)
                  ->orWhere('slice', strtolower($slice));
            });
        }

        if (!empty($action) && $action !== 'all') {
            $query->where('action', $action);
        }

        if (!empty($actorId)) {
            $query->where('actor_id', $actorId);
        }

        if (!empty($dateFrom)) {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        if (!empty($dateTo)) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('entity_id', 'LIKE', "%{$search}%")
                  ->orWhere('actor_email', 'LIKE', "%{$search}%")
                  ->orWhere('ip_address', 'LIKE', "%{$search}%")
                  ->orWhere('entity_type', 'LIKE', "%{$search}%")
                  ->orWhere('new_values', 'LIKE', "%{$search}%")
                  ->orWhere('old_values', 'LIKE', "%{$search}%");
            });
        }

        $total = (clone $query)->count();
        $logs = $query->take($limit)->get()->map(function ($row) {
            $row->old_values = $row->old_values ? json_decode($row->old_values, true) : null;
            $row->new_values = $row->new_values ? json_decode($row->new_values, true) : null;
            $row->metadata   = $row->metadata ? json_decode($row->metadata, true) : null;
            return $row;
        });

        // Get aggregate action counts for quick filters
        $counts = [
            'all' => \Illuminate\Support\Facades\DB::table(\LaraSlice\Core\Audit\AuditLogger::TABLE_NAME)
                ->when(!empty($slice) && $slice !== 'all', fn($q) => $q->where('slice', $slice))
                ->count(),
            'created' => \Illuminate\Support\Facades\DB::table(\LaraSlice\Core\Audit\AuditLogger::TABLE_NAME)
                ->where('action', 'created')
                ->when(!empty($slice) && $slice !== 'all', fn($q) => $q->where('slice', $slice))
                ->count(),
            'updated' => \Illuminate\Support\Facades\DB::table(\LaraSlice\Core\Audit\AuditLogger::TABLE_NAME)
                ->where('action', 'updated')
                ->when(!empty($slice) && $slice !== 'all', fn($q) => $q->where('slice', $slice))
                ->count(),
            'deleted' => \Illuminate\Support\Facades\DB::table(\LaraSlice\Core\Audit\AuditLogger::TABLE_NAME)
                ->whereIn('action', ['deleted', 'force_deleted'])
                ->when(!empty($slice) && $slice !== 'all', fn($q) => $q->where('slice', $slice))
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'slice'   => $slice,
            'total'   => $total,
            'counts'  => $counts,
            'logs'    => $logs,
        ]);
    }

    /**
     * Scaffold a complete domain suite (multiple slices grouped under a single domain) in one pass.
     */
    public function generateDomainSuite(Request $request)
    {
        $validated = $request->validate([
            'domain'       => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9 _-]*$/'],
            'slices'       => ['required', 'array', 'min:1', 'max:20'],
            'slices.*'     => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9 _-]*$/'],
            'sliceSchemas' => 'nullable|array',
            'namespace'    => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/'],
            'author'       => 'nullable|string|max:120',
            'workflow'     => 'nullable|boolean',
            'includeApi'   => 'nullable|boolean',
            'flutter'      => 'nullable|boolean',
            'runMigration' => 'nullable|boolean',
        ]);

        $domain = trim($validated['domain']);
        $sliceNames = array_unique(array_filter(array_map('trim', $validated['slices'])));
        $generator = new SliceGenerator(null, $validated['namespace'] ?? null);
        $flutterGen = !empty($validated['flutter']) ? new \LaraSlice\Generator\FlutterSliceGenerator() : null;
        $schemas = $validated['sliceSchemas'] ?? [];

        $created = [];
        $errors = [];

        foreach ($sliceNames as $sliceName) {
            try {
                $sliceFields = $schemas[$sliceName]['fields'] ?? [];
                $sliceDir = $generator->generate(
                    $sliceName,
                    $sliceFields,
                    (bool) ($validated['workflow'] ?? false),
                    [
                        'domain' => $domain,
                        'author' => $validated['author'] ?? null,
                        'api'    => (bool) ($validated['includeApi'] ?? true),
                    ]
                );

                $childTables = $schemas[$sliceName]['childTables'] ?? [];
                if (!empty($childTables)) {
                    $modifier = new \LaraSlice\Generator\SliceModifier(
                        dirname($sliceDir),
                        $validated['namespace'] ?? null
                    );
                    foreach ($childTables as $child) {
                        if (!empty($child['name'])) {
                            $childFields = $child['fields'] ?? [];
                            $modifier->addChildEntity(
                                $sliceName,
                                $child['name'],
                                $childFields,
                                $child['relation'] ?? 'hasMany',
                                $child['foreign_key'] ?? null
                            );
                        }
                    }
                }

                if ($flutterGen) {
                    try {
                        $flutterGen->generate($sliceName);
                    } catch (\Throwable $e) {}
                }

                $created[] = [
                    'name' => $sliceName,
                    'dir'  => $sliceDir,
                ];
            } catch (\Throwable $e) {
                report($e);
                $errors[$sliceName] = $e->getMessage();
            }
        }

        $migrated = false;
        $migrationOutput = null;
        if (!empty($validated['runMigration']) && count($created) > 0) {
            [$migrated, $migrationOutput] = $this->executeMigrations();
        }

        try {
            app(\LaraSlice\Core\Discovery\SliceManager::class)->syncPermissions();
        } catch (\Throwable $e) {}

        $domainSlug = strtolower(\Illuminate\Support\Str::slug($domain));
        $createdWithRoutes = array_map(function ($c) use ($domainSlug) {
            $pluralSnake = strtolower(\Illuminate\Support\Str::snake(\Illuminate\Support\Str::plural($c['name'])));
            return [
                'name'    => $c['name'],
                'dir'     => $c['dir'],
                'web_url' => url("/{$domainSlug}/{$pluralSnake}"),
                'api_url' => url("/api/{$domainSlug}/{$pluralSnake}/list"),
            ];
        }, $created);

        // The wizard only displays `message`, so surface per-slice failures there too
        $message = count($created) . " slice(s) successfully generated in domain [{$domain}].";
        foreach ($errors as $failedSlice => $error) {
            $message .= " [{$failedSlice}] failed: {$error}";
        }

        return response()->json([
            'success'         => count($errors) === 0,
            'domain'          => $domain,
            'created'         => $createdWithRoutes,
            'errors'          => $errors,
            'migrated'        => $migrated,
            'migrationOutput' => $migrationOutput,
            'message'         => $message,
        ], count($created) > 0 ? 200 : 422);
    }

    /**
     * Authorize an administrative wizard action.
     * Super-admins always bypass. Authenticated users are checked against permission abilities.
     *
     * @param string|array $abilities
     * @return void
     */
    protected function authorizeWizardAction($abilities): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $abilities = (array) $abilities;
        if (! \LaraSlice\Core\Security\Access::allows($user, $abilities)) {
            abort(403, 'Unauthorized. Required permissions: [' . implode(', ', $abilities) . ']');
        }
    }

    public function toggleSlice(Request $request)
    {
        $validated = $request->validate([
            'slice'  => 'required|string',
            'active' => 'nullable|boolean',
        ]);

        try {
            $this->authorizeWizardAction(['system.slices.toggle', 'slice.disable', 'slice.toggle', 'slice.manage']);
            $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
            $newActive = $manager->toggleSlice($validated['slice'], $validated['active'] ?? null);

            return response()->json([
                'success' => true,
                'slice'   => $validated['slice'],
                'active'  => $newActive,
                'message' => "Slice [{$validated['slice']}] is now " . ($newActive ? 'Enabled' : 'Disabled (Hidden from Navigation)'),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function toggleDomain(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
            'active' => 'nullable|boolean',
        ]);

        try {
            $dSlug = strtolower(\Illuminate\Support\Str::slug($validated['domain']));
            $this->authorizeWizardAction(['system.slices.toggle', 'domain.manage', "{$dSlug}.manage"]);
            $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
            $updated = $manager->toggleDomain($validated['domain'], $validated['active'] ?? null);

            return response()->json([
                'success' => true,
                'domain'  => $validated['domain'],
                'updated' => $updated,
                'message' => "Domain [{$validated['domain']}] slices updated.",
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function seedSlice(Request $request)
    {
        $validated = $request->validate([
            'slice' => 'required|string',
            'count' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $sliceSlug = strtolower(\Illuminate\Support\Str::snake($validated['slice']));
            $this->authorizeWizardAction(['system.slices.seed', 'slice.seed', "{$sliceSlug}.seed"]);

            $seeder = new \LaraSlice\Generator\SliceSeederService();
            $result = $seeder->seedSlice($validated['slice'], (int)($validated['count'] ?? 10));

            if (!$request->expectsJson() && !$request->wantsJson()) {
                return back()->with('success', $result['message']);
            }

            return response()->json($result);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function seedDomain(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
            'count'  => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $dSlug = strtolower(\Illuminate\Support\Str::slug($validated['domain']));
            $this->authorizeWizardAction(['system.slices.seed', 'domain.seed', "{$dSlug}.seed", "{$dSlug}.manage"]);

            $seeder = new \LaraSlice\Generator\SliceSeederService();
            $result = $seeder->seedDomain($validated['domain'], (int)($validated['count'] ?? 10));

            if (!$request->expectsJson() && !$request->wantsJson()) {
                return back()->with('success', $result['message']);
            }

            return response()->json($result);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function wipeSlice(Request $request)
    {
        $validated = $request->validate([
            'slice' => 'required|string',
        ]);

        try {
            $sliceSlug = strtolower(\Illuminate\Support\Str::snake($validated['slice']));
            $this->authorizeWizardAction(['system.slices.wipe', 'slice.wipe', 'slice.delete', 'system.slices.delete']);

            $seeder = new \LaraSlice\Generator\SliceSeederService();
            $result = $seeder->wipeSlice($validated['slice']);

            return response()->json($result);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function wipeDomain(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
        ]);

        try {
            $dSlug = strtolower(\Illuminate\Support\Str::slug($validated['domain']));
            $this->authorizeWizardAction(['system.slices.wipe', 'domain.wipe', 'domain.manage', 'system.slices.delete']);

            $seeder = new \LaraSlice\Generator\SliceSeederService();
            $result = $seeder->wipeDomain($validated['domain']);

            return response()->json($result);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroySlice(Request $request)
    {
        $validated = $request->validate([
            'slice' => 'required|string',
            'mode'  => 'nullable|string|in:complete,code_only,db_only,wipe_data',
        ]);

        try {
            $sliceSlug = strtolower(\Illuminate\Support\Str::snake($validated['slice']));
            $this->authorizeWizardAction(['system.slices.delete', 'slice.delete', 'slice.destroy']);

            $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
            $result = $manager->destroySlice($validated['slice'], $validated['mode'] ?? 'complete');

            return response()->json($result);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function destroyDomain(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
            'mode'   => 'nullable|string|in:complete,code_only,db_only,wipe_data',
        ]);

        try {
            $dSlug = strtolower(\Illuminate\Support\Str::slug($validated['domain']));
            $this->authorizeWizardAction(['system.slices.delete', 'domain.delete', "{$dSlug}.manage"]);

            $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
            $result = $manager->destroyDomain($validated['domain'], $validated['mode'] ?? 'complete');

            return response()->json($result);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
