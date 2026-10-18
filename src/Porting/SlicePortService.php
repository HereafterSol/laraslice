<?php

namespace LaraSlice\Porting;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class SlicePortService
{
    /**
     * Framework infrastructure and queue/cache plumbing tables that must NEVER
     * be treated as domain slices or clutter the navigation/sidebar.
     */
    public const FRAMEWORK_INFRASTRUCTURE_TABLES = [
        'migrations',
        'password_reset_tokens',
        'password_resets',
        'job_batches',
        'jobs',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
        'personal_access_tokens',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
        'pulse_entries',
        'pulse_aggregates',
        'pulse_values',
        'horizon_jobs',
    ];

    /**
     * Clone a Git repository (GitHub, GitLab, or any Git host) into a local
     * cache directory so it can be analyzed like a local source path.
     *
     * Supports:
     *  - Public repos (no token needed)
     *  - Private repos via personal access token (GitHub or GitLab)
     *  - Branch / tag selection (defaults to the repo's default branch)
     *
     * The token is injected into the clone URL in-memory only — it is never
     * written to disk, never logged, and never returned to the caller.
     *
     * @param  string  $repoUrl  e.g. https://github.com/acme/legacy-erp.git
     * @param  string|null  $branch  Branch or tag to check out (null = default)
     * @param  string|null  $token  Personal access token for private repos
     * @return string  Local path of the cloned repository
     *
     * @throws \InvalidArgumentException on invalid URL or failed clone
     */
    public function cloneGitSource(string $repoUrl, ?string $branch = null, ?string $token = null, bool $keep = false): string
    {
        $repoUrl = trim($repoUrl);

        if (! preg_match('#^https?://[^\\s/]+/.+#i', $repoUrl)) {
            throw new \InvalidArgumentException('Invalid repository URL. Use an https:// URL, e.g. https://github.com/acme/legacy-erp.git');
        }

        // Normalize: strip trailing .git for the cache key but keep for cloning
        $cacheKey = md5(strtolower(preg_replace('#\\.git$#i', '', $repoUrl)).'|'.($branch ?? 'default'));

        $cacheDir = storage_path('app'.DIRECTORY_SEPARATOR.'porting-sources');
        if (! File::isDirectory($cacheDir)) {
            File::makeDirectory($cacheDir, 0755, true);
        }

        $targetDir = $cacheDir.DIRECTORY_SEPARATOR.$cacheKey;

        // Reuse a fresh-enough clone (same branch, < 24h old) to avoid re-cloning.
        // Skipped when the source is marked "keep" — those are managed manually.
        if (File::isDirectory($targetDir.DIRECTORY_SEPARATOR.'.git')) {
            $meta = $this->readCloneMeta($targetDir);
            if (! ($meta['keep'] ?? false)) {
                $headFile = $targetDir.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'FETCH_HEAD';
                if (File::exists($headFile) && (time() - File::lastModified($headFile)) < 86400) {
                    return $targetDir;
                }
            } else {
                return $targetDir;
            }
            File::deleteDirectory($targetDir);
        }

        // Build an authenticated clone URL in-memory only (never persisted/logged)
        $cloneUrl = $repoUrl;
        if ($token !== null && $token !== '') {
            $parts = parse_url($repoUrl);
            $host = $parts['host'] ?? '';
            // GitHub and GitLab both accept the token as the username with empty password,
            // or as oauth2:<token>. Use the x-access-token form which works on both.
            $auth = 'x-access-token:'.rawurlencode(trim($token)).'@';
            $cloneUrl = ($parts['scheme'] ?? 'https').'://'.$auth.$host.($parts['path'] ?? '');
            if (! empty($parts['query'])) {
                $cloneUrl .= '?'.$parts['query'];
            }
        }

        $branchArg = ($branch !== null && trim($branch) !== '')
            ? ' --branch '.escapeshellarg(trim($branch))
            : '';

        // Shallow clone: faster, and enough for static analysis
        $cmd = 'git clone --depth 1'.$branchArg.' '.escapeshellarg($cloneUrl).' '.escapeshellarg($targetDir).' 2>&1';

        // Mask any token from the command string used in error messages
        $safeCmd = preg_replace('#x-access-token:[^@]+@#', 'x-access-token:***@', $cmd);

        $output = [];
        $exitCode = 0;
        // Use a sanitized environment so the token never leaks via process listing
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || ! File::isDirectory($targetDir)) {
            $hint = implode("\n", array_slice($output, -5));
            // Scrub any accidental token echo from git's error output
            $hint = preg_replace('#x-access-token:[^@\\s]+@#', 'x-access-token:***@', $hint);
            throw new \InvalidArgumentException(
                "Could not clone repository. Check the URL, branch name, and token permissions.\n{$hint}\n(Command: {$safeCmd})"
            );
        }

        // Write metadata (repo URL, branch, keep flag) — never the token
        $this->writeCloneMeta($targetDir, [
            'repo_url' => preg_replace('#\\.git$#i', '', $repoUrl),
            'branch' => $branch,
            'keep' => $keep,
            'cloned_at' => date('c'),
        ]);

        return $targetDir;
    }

    /**
     * Read clone metadata (never contains a token).
     */
    protected function readCloneMeta(string $dir): array
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
     * Write clone metadata (never contains a token).
     */
    protected function writeCloneMeta(string $dir, array $meta): void
    {
        File::put($dir.DIRECTORY_SEPARATOR.'.porting-meta.json', json_encode($meta, JSON_PRETTY_PRINT));
    }

    /**
     * Inspect a legacy application directory and return comprehensive schema & slice mapping.
     */
    public function inspect(string $sourcePath, string $domain = 'Adjudication'): array
    {
        $cleanPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $sourcePath), DIRECTORY_SEPARATOR);

        if (! File::isDirectory($cleanPath)) {
            if ($cleanPath === '' || $cleanPath === '.' || $cleanPath === 'here') {
                $cleanPath = base_path();
            } else {
                throw new \InvalidArgumentException("Source project directory not found: {$sourcePath}");
            }
        }

        $migDir = $cleanPath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $seedDir = $cleanPath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'seeders';

        // SOURCE OF TRUTH PRIORITY:
        // 1. Live source database (via --source-* connection options) = most accurate
        // 2. Migrations (for metadata: types, FKs, migration filenames)
        // 3. Current app database (fallback with warning)
        $sourceConn = config('laraslice.porting.source_connection');
        $dbTables = [];
        $migTables = $this->extractTablesFromMigrations($migDir);

        if ($sourceConn) {
            // Live source DB is the source of truth
            $dbTables = $this->extractTablesFromSourceDatabase($sourceConn);
            $tables = $this->reconcileTableSources($dbTables, $migTables, $sourceConn);
        } elseif (! empty($migTables)) {
            // Migrations available, no live DB — use migrations but check for gaps
            $tables = $migTables;
            // Mark all as migration-sourced
            foreach ($tables as $name => $t) {
                $tables[$name]['table_source'] = 'migrations';
            }
        } else {
            // No migrations, no source DB — fallback to current app DB with warning
            $tables = $this->extractTablesFromDatabase();
            foreach ($tables as $name => $t) {
                $tables[$name]['table_source'] = 'app_database_fallback';
            }
        }

        $tenancy = $this->detectMultiTenancy($tables);
        $classified = $this->classifyTables($tables);
        $mappingResult = $this->buildSliceMapping($classified, $domain);
        $sliceMapping = $mappingResult['mapping'];
        $unmappedPivots = $mappingResult['unmapped_pivots'];

        $studioSlices = $this->buildStudioSlices($sliceMapping, $tables, $domain, [
            'multitenant' => $tenancy['enabled'],
            'tenant_key' => $tenancy['tenant_key'],
        ]);

        $domainYaml = $this->generateDomainBlueprintYaml($studioSlices, $domain, [
            'source_path' => $cleanPath,
            'total_tables' => count($tables),
            'tenancy' => $tenancy,
        ]);

        return [
            'source_path' => $cleanPath,
            'domain' => $domain,
            'total_tables' => count($tables),
            'tenancy' => $tenancy,
            'tables' => $tables,
            'classified' => $classified,
            'slice_mapping' => $sliceMapping,
            'unmapped_pivots' => $unmappedPivots,
            'studio_slices' => $studioSlices,
            'domain_blueprint_yaml' => $domainYaml,
            'has_seeders' => File::isDirectory($seedDir) && count(File::files($seedDir)) > 0,
            'has_storage_files' => File::isDirectory($cleanPath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public'),
        ];
    }

    /**
     * Detect if the legacy schema is a multi-tenant application (e.g. company_id, tenant_id).
     */
    public function detectMultiTenancy(array $tables): array
    {
        $tenantKeyCounts = [
            'company_id' => 0,
            'tenant_id' => 0,
            'organization_id' => 0,
            'account_id' => 0,
        ];

        $totalTables = count($tables);
        if ($totalTables === 0) {
            return ['enabled' => false, 'tenant_key' => null, 'coverage_percentage' => 0];
        }

        foreach ($tables as $tName => $tInfo) {
            $colNames = array_column($tInfo['columns'] ?? [], 'name');
            foreach (array_keys($tenantKeyCounts) as $key) {
                if (in_array($key, $colNames, true)) {
                    $tenantKeyCounts[$key]++;
                }
            }
        }

        arsort($tenantKeyCounts);
        $topKey = array_key_first($tenantKeyCounts);
        $topCount = $tenantKeyCounts[$topKey] ?? 0;
        $coverage = round(($topCount / $totalTables) * 100, 1);

        // If >= 15% of tables contain company_id / tenant_id and at least 3 occurrences, it's multi-tenant
        $enabled = ($topCount >= 3 && $coverage >= 15.0);

        return [
            'enabled' => $enabled,
            'tenant_key' => $enabled ? $topKey : null,
            'affected_tables' => $topCount,
            'total_tables' => $totalTables,
            'coverage_percentage' => $coverage,
        ];
    }

    /**
     * Execute the porting process: generate blueprints, seeders, and optionally copy files.
     */
    public function execute(string $sourcePath, string $domain = 'Adjudication', string $mode = 'system-base', bool $withFiles = false, bool $importData = false, ?array $sourceConnection = null, bool $importAuth = false): array
    {
        $inspection = $this->inspect($sourcePath, $domain);
        $cleanPath = $inspection['source_path'];
        $sliceMapping = $inspection['slice_mapping'];

        $generatedSlices = [];
        $blueprintsDir = config('laraslice.blueprints_path', base_path('blueprints/domains'));
        File::ensureDirectoryExists($blueprintsDir);

        $domainYamlFile = $blueprintsDir.DIRECTORY_SEPARATOR.Str::slug($domain).'.domain.yaml';
        $richDomainYaml = $this->generateDomainBlueprintYaml($inspection['studio_slices'], $domain, $inspection);
        File::put($domainYamlFile, $richDomainYaml);

        foreach (array_keys($sliceMapping) as $sName) {
            $generatedSlices[] = $sName;
        }

        $seedersCreated = [];
        if ($mode === 'system-base' || $mode === 'full-data') {
            $seedersCreated = $this->generateTaxonomySeeders($inspection, $domain);
        }

        $filesMigrated = 0;
        $bytesMigrated = 0;
        if ($withFiles) {
            $srcStorage = $cleanPath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';
            $destStorage = storage_path('app/public');
            if (File::isDirectory($srcStorage)) {
                File::ensureDirectoryExists($destStorage);
                foreach (File::allFiles($srcStorage) as $file) {
                    $rel = $file->getRelativePathname();
                    $dest = $destStorage.DIRECTORY_SEPARATOR.$rel;
                    File::ensureDirectoryExists(dirname($dest));
                    File::copy($file->getPathname(), $dest);
                    $filesMigrated++;
                    $bytesMigrated += $file->getSize();
                }
            }
        }

        $dataImport = null;
        if ($importData) {
            try {
                $dataPortService = new SliceDataPortService;
                $dataPortService->setImportAuth($importAuth);
                $dataImport = $dataPortService->importFromDatabase(
                    $sourceConnection['database'] ?? 'erp',
                    $sourceConnection['host'] ?? '127.0.0.1',
                    (int) ($sourceConnection['port'] ?? 3306),
                    $sourceConnection['username'] ?? 'root',
                    $sourceConnection['password'] ?? ''
                );
            } catch (\Throwable $e) {
                $dataImport = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'success' => true,
            'domain' => $domain,
            'mode' => $mode,
            'tenancy' => $inspection['tenancy'],
            'domain_blueprint' => $domainYamlFile,
            'domain_blueprint_yaml' => $richDomainYaml,
            'slices_generated' => $generatedSlices,
            'seeders_generated' => $seedersCreated,
            'files_migrated' => $filesMigrated,
            'bytes_migrated' => $bytesMigrated,
            'tables_count' => $inspection['total_tables'],
            'unmapped_pivots' => $inspection['unmapped_pivots'] ?? [],
            'data_import' => $dataImport,
        ];
    }

    /**
     * Detect Bounded Domain (Module) for an aggregate slice based on enterprise clustering rules.
     */
    public function detectModuleForSlice(string $sliceName, string $primaryTable): string
    {
        $directMap = [
            'Projects' => 'Work',
            'Tasks' => 'Work',
            'Contracts' => 'Work',
            'ProjectTimeLogs' => 'Work',
            'TimeLogs' => 'Work',

            'Leads' => 'SalesCRM',
            'Deals' => 'SalesCRM',
            'ClientDetails' => 'SalesCRM',
            'Clients' => 'SalesCRM',

            'Invoices' => 'FinancialAccounting',
            'Payments' => 'FinancialAccounting',
            'Estimates' => 'FinancialAccounting',
            'Proposals' => 'FinancialAccounting',
            'CreditNotes' => 'FinancialAccounting',
            'Expenses' => 'FinancialAccounting',
            'BankAccounts' => 'FinancialAccounting',

            'EmployeeDetails' => 'HumanCapital',
            'Employees' => 'HumanCapital',
            'Attendances' => 'HumanCapital',
            'Attendance' => 'HumanCapital',
            'Leaves' => 'HumanCapital',
            'EmployeeShiftSchedules' => 'HumanCapital',
            'ShiftRoster' => 'HumanCapital',
            'Teams' => 'HumanCapital',
            'Departments' => 'HumanCapital',
            'Designations' => 'HumanCapital',
            'Appreciations' => 'HumanCapital',

            'Products' => 'SupplyChain',
            'Orders' => 'SupplyChain',

            'Tickets' => 'ServiceDesk',
            'Events' => 'ServiceDesk',
            'Notices' => 'LegalRegistry',
            'NoticeTitles' => 'LegalRegistry',
            'KnowledgeBases' => 'ServiceDesk',

            'UsersChat' => 'Operations',
            'Messages' => 'Operations',
            'StickyNotes' => 'Operations',

            'GlobalSettings' => 'Settings',
            'Settings' => 'Settings',
        ];

        if (isset($directMap[$sliceName])) {
            return $directMap[$sliceName];
        }

        $clusters = [
            'CaseManagement' => ['complaint', 'case', 'dispute', 'claim', 'matter', 'grievance', 'petition', 'accused', 'respondent', 'complainant', 'approval'],
            'JudicialHearings' => ['hearing', 'cause_list', 'decision', 'verdict', 'penalty', 'sentence', 'decree', 'ruling', 'appeal', 'order_case'],
            'LegalRegistry' => ['notice', 'summons', 'citation', 'docket', 'representation', 'brief', 'scrutiny', 'maintainability', 'report_sought'],
            'FinancialAccounting' => ['invoice', 'payment', 'expense', 'estimate', 'credit_note', 'tax', 'bank', 'currency', 'transaction', 'recurring', 'quotation', 'subscription'],
            'HumanCapital' => ['employee', 'attendance', 'leave', 'holiday', 'shift', 'designation', 'department', 'appreciation', 'award', 'emergency_contact', 'passport', 'visa'],
            'SalesCRM' => ['lead', 'deal', 'proposal', 'pipeline', 'client', 'contract', 'leadboard'],
            'Work' => ['project', 'task', 'timelog', 'time_log', 'milestone', 'sub_task', 'taskboard'],
            'SupplyChain' => ['product', 'order', 'cart', 'item', 'inventory', 'warehouse', 'vendor'],
            'ServiceDesk' => ['ticket', 'discussion', 'knowledge', 'faq', 'event'],
            'Settings' => ['company', 'setting', 'theme', 'role', 'permission', 'custom_field', 'custom_link', 'log', 'backup', 'translate', 'language', 'social_auth', 'pusher', 'slack', 'otp', 'api_user', 'api_log', 'activity_log', 'token', 'job_batch', 'cache'],
            'ReferenceData' => ['countries', 'currencies', 'master_data', 'reference_data', 'province', 'district', 'tehsil', 'region', 'gender', 'organization', 'office', 'nature', 'status', 'subtype', 'category'],
        ];

        $lower = strtolower($sliceName . '_' . $primaryTable);
        foreach ($clusters as $domain => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $domain;
                }
            }
        }

        return 'Operations';
    }

    /**
     * Scaffold physical vertical slices directly into app/Slices/{App}/{Domain}/{Slice}.
     */
    public function scaffoldSlices(array $inspection, string $app = 'ERP', ?string $superDomain = null): array
    {
        $generator = new \LaraSlice\Generator\SliceGenerator;
        $created = [];
        $errors = [];

        $sliceMapping = $inspection['slice_mapping'];
        $tables = $inspection['tables'];
        $multitenant = $inspection['tenancy']['enabled'] ?? false;
        $tenantKey = $inspection['tenancy']['tenant_key'] ?? 'company_id';
        $sourcePath = $inspection['source_path'] ?? '';

        // Detect workflows, business logic, and dead tables from source
        $workflows = $sourcePath ? $this->detectWorkflows($sourcePath, $sliceMapping) : [];
        $businessLogic = $sourcePath ? $this->detectBusinessLogic($sourcePath, $sliceMapping) : [];
        $deadTables = $sourcePath ? $this->detectDeadTables($tables, $sourcePath) : [];

        // Convert detected workflows to generator's expected format
        $workflowConfigs = [];
        foreach ($workflows as $sliceName => $wf) {
            $states = [];
            foreach ($wf['states'] as $state) {
                $states[] = [
                    'slug' => $state,
                    'label' => \Illuminate\Support\Str::headline($state),
                    'initial' => $state === ($wf['initial_state'] ?? 'draft'),
                ];
            }
            $transitions = [];
            foreach ($wf['transitions'] as $t) {
                $transitions[] = [
                    'slug' => \Illuminate\Support\Str::snake($t['name']),
                    'name' => $t['label'],
                    'from' => ['draft'], // Conservative: allow from draft
                    'to' => $t['to_state'],
                ];
            }
            $workflowConfigs[$sliceName] = [
                'enabled' => true,
                'slug' => \Illuminate\Support\Str::slug($sliceName),
                'name' => "{$sliceName} Workflow",
                'state_field' => 'status',
                'initial_state' => $wf['initial_state'] ?? 'draft',
                'states' => $states,
                'transitions' => $transitions,
            ];
        }

        foreach ($sliceMapping as $sliceName => $info) {
            try {
                $primaryTable = $info['primary_table'];
                $module = $this->detectModuleForSlice($sliceName, $primaryTable);
                $cols = $tables[$primaryTable]['columns'] ?? [];
                $fields = $this->convertColumnsToFields($cols);

                $generatorFields = [];
                $seenFields = [];
                foreach ($fields as $f) {
                    $fName = $f['handle'];
                    if ($fName === $tenantKey || in_array($fName, ['id', 'created_at', 'updated_at', 'deleted_at'], true) || isset($seenFields[$fName])) {
                        continue;
                    }
                    $seenFields[$fName] = true;

                    $type = match($f['type']) {
                        'integer' => 'integer',
                        'boolean' => 'boolean',
                        'datetime', 'date' => 'timestamp',
                        'decimal' => 'decimal',
                        'text' => 'text',
                        default => 'string',
                    };

                    $generatorFields[] = [
                        'name' => $fName,
                        'label' => $f['label'],
                        'type' => $type,
                        'nullable' => true,
                        'rules' => ['nullable'],
                    ];
                }

                $options = [
                    'app' => $app,
                    'super_domain' => $superDomain,
                    'domain' => $module,
                    'table' => $primaryTable,
                    'multitenant' => $multitenant,
                    'tenant_key' => $tenantKey,
                    'description' => $info['description'] ?? "Vertical slice for {$sliceName}",
                    // v1.6.2: Generate canonical ui: block from real inspected fields
                    'ui' => $this->buildUiFromInspectedFields($sliceName, $fields, $tenantKey),
                    // Workflow: pass detected state machine to generator
                    'workflowConfig' => $workflowConfigs[$sliceName] ?? null,
                ];

                $sliceDir = $generator->generate($sliceName, $generatorFields, ! empty($workflowConfigs[$sliceName]), $options);
                $nsParts = [];
                if ($superDomain) {
                    $nsParts[] = \Illuminate\Support\Str::studly($superDomain);
                }
                $nsParts[] = \Illuminate\Support\Str::studly($app);
                $nsParts[] = \Illuminate\Support\Str::studly($module);
                $nsParts[] = $sliceName;
                $sliceNamespace = 'App\\Slices\\'.implode('\\', $nsParts);

                // Scaffold child tables associated with this aggregate slice
                $childTables = $info['child_tables'] ?? [];
                $scaffoldedChildren = [];
                foreach ($childTables as $childTable) {
                    if (isset($tables[$childTable])) {
                        $cCols = $tables[$childTable]['columns'] ?? [];
                        $this->scaffoldChildTable($sliceDir, $sliceNamespace, $sliceName, $primaryTable, $childTable, $cCols, $multitenant, $tenantKey);
                        $scaffoldedChildren[] = $childTable;
                    }
                }

                // Enrich slice.yaml with the child models so the manifest describes the full aggregate.
                if (! empty($scaffoldedChildren)) {
                    $this->enrichSliceYamlWithChildren($sliceDir, $primaryTable, $scaffoldedChildren, $tables, $multitenant, $tenantKey, $businessLogic[$sliceName] ?? []);
                }

                $created[$sliceName] = [
                    'slice' => $sliceName,
                    'module' => $module,
                    'app' => $app,
                    'table' => $primaryTable,
                    'child_tables' => $scaffoldedChildren,
                    'path' => $sliceDir,
                ];
            } catch (\Throwable $e) {
                $errors[$sliceName] = $e->getMessage();
            }
        }

        return [
            'created' => $created,
            'errors' => $errors,
        ];
    }

    /**
     * Scaffold migration and Eloquent model for an aggregate child table.
     */
    protected function scaffoldChildTable(
        string $sliceDir,
        string $sliceNamespace,
        string $sliceName,
        string $parentTable,
        string $childTable,
        array $cols,
        bool $multitenant,
        string $tenantKey
    ): void {
        $childModelName = Str::studly(Str::singular($childTable));
        $timestamp = date('Y_m_d_His', time() + 2);

        // 1. Generate child migration
        $fields = $this->convertColumnsToFields($cols);
        $migBody = '';
        $seen = [];
        foreach ($fields as $f) {
            $fn = $f['handle'];
            if ($fn === 'id' || $fn === 'created_at' || $fn === 'updated_at' || $fn === 'deleted_at' || isset($seen[$fn])) {
                continue;
            }
            if ($fn === $tenantKey && $multitenant) {
                continue;
            }
            $seen[$fn] = true;
            $t = match($f['type']) {
                'integer' => 'unsignedBigInteger',
                'boolean' => 'boolean',
                'datetime', 'date' => 'timestamp',
                'decimal' => 'decimal',
                'text' => 'text',
                default => 'string',
            };
            $migBody .= "\n            \$table->{$t}('{$fn}')->nullable();";
        }

        $tenantCol = $multitenant ? "\n            \$table->unsignedBigInteger('{$tenantKey}')->nullable()->index();" : '';

        $migrationContent = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('{$childTable}')) {
            return;
        }

        Schema::create('{$childTable}', function (Blueprint \$table) {
            \$table->id();{$tenantCol}{$migBody}
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$childTable}');
    }
};
PHP;

        File::ensureDirectoryExists($sliceDir . '/Migrations');
        File::put($sliceDir . "/Migrations/{$timestamp}_create_{$childTable}_table.php", $migrationContent);

        // 2. Generate child model
        $tenantTraitUse = $multitenant ? "\nuse LaraSlice\\Core\\Tenancy\\Traits\\HasTenant;" : '';
        $tenantTrait = $multitenant ? "    use HasTenant;\n" : '';
        $parentModelName = Str::studly(Str::singular($parentTable));

        $childModelContent = <<<PHP
<?php

namespace {$sliceNamespace}\\Models;

use Illuminate\Database\Eloquent\Model;{$tenantTraitUse}

class {$childModelName} extends Model
{
{$tenantTrait}    protected \$table = '{$childTable}';
    protected \$guarded = [];

    public function {$parentModelName}(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return \$this->belongsTo({$parentModelName}::class);
    }
}
PHP;

        File::ensureDirectoryExists($sliceDir . '/Models');
        File::put($sliceDir . "/Models/{$childModelName}.php", $childModelContent);
    }

    /**
     * Enrich a ported slice's slice.yaml with its child (aggregate) models so the
     * manifest describes the full aggregate — not just the root model.
     */
    protected function enrichSliceYamlWithChildren(
        string $sliceDir,
        string $primaryTable,
        array $childTables,
        array $tables,
        bool $multitenant,
        string $tenantKey,
        array $portingNotes = []
    ): void {
        $yamlPath = $sliceDir.'/slice.yaml';
        if (! File::exists($yamlPath)) {
            return;
        }

        try {
            $yaml = \Symfony\Component\Yaml\Yaml::parseFile($yamlPath);
        } catch (\Throwable) {
            return;
        }
        if (! is_array($yaml)) {
            return;
        }

        $existingHandles = [];
        foreach ($yaml['models'] ?? [] as $m) {
            $existingHandles[] = $m['handle'] ?? null;
        }

        $rootHandle = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::singular($primaryTable));
        $relations = [];

        foreach ($childTables as $childTable) {
            $childHandle = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::singular($childTable));
            if (in_array($childHandle, $existingHandles, true)) {
                continue;
            }

            $fields = [];
            $seen = [];
            foreach ($this->convertColumnsToFields($tables[$childTable]['columns'] ?? []) as $f) {
                $fn = $f['handle'];
                if ($fn === 'id' || isset($seen[$fn])) {
                    continue;
                }
                if ($fn === $tenantKey && $multitenant) {
                    continue;
                }
                $seen[$fn] = true;
                $fields[] = [
                    'handle' => $fn,
                    'label' => $f['label'] ?? \Illuminate\Support\Str::headline($fn),
                    'type' => $f['type'],
                    'required' => false,
                ];
            }

            $fk = \Illuminate\Support\Str::singular($primaryTable).'_id';
            // Only wire the relation if the FK column actually exists on the child.
            // Lookup tables (e.g. invoice_statuses with only name/code) are scaffolded
            // as standalone models but do not belong in this aggregate's manifest.
            $childFieldHandles = array_column($fields, 'handle');
            if (! in_array($fk, $childFieldHandles, true)) {
                continue;
            }

            $yaml['models'][] = [
                'handle' => $childHandle,
                'table' => $childTable,
                'root' => false,
                'fields' => $fields,
                'relations' => [
                    [
                        'name' => $rootHandle,
                        'type' => 'belongsTo',
                        'model' => $rootHandle,
                        'foreign_key' => $fk,
                    ],
                ],
            ];
            $relations[] = [
                'name' => $childHandle,
                'type' => 'hasMany',
                'model' => $childHandle,
                'foreign_key' => $fk,
            ];
        }

        // Attach hasMany relations to the root model.
        foreach ($yaml['models'] as &$m) {
            if (($m['handle'] ?? null) === $rootHandle) {
                $m['relations'] = array_merge($m['relations'] ?? [], $relations);
                break;
            }
        }
        unset($m);

        // Add porting notes documenting what was NOT portable
        if (! empty($portingNotes)) {
            $yaml['porting_notes'] = $portingNotes;
        }

        File::put($yamlPath, \Symfony\Component\Yaml\Yaml::dump($yaml, 10, 2));
    }

    /**
     * Extract tables from the configured SOURCE database connection.
     * This is the source of truth for projects that don't use migrations.
     */
    private function extractTablesFromSourceDatabase(string $connection): array
    {
        $tables = [];
        try {
            $conn = \Illuminate\Support\Facades\DB::connection($connection);
            $pdo = $conn->getPdo();
            $driver = $conn->getDriverName();

            $tableNames = [];
            if ($driver === 'mysql') {
                $stmt = $pdo->query('SHOW TABLES');
                $tableNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } elseif ($driver === 'pgsql') {
                $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                $tableNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } elseif ($driver === 'sqlite') {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                $tableNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            } else {
                // Portable fallback
                $tableNames = \Illuminate\Support\Facades\Schema::connection($connection)->getTableListing();
            }

            foreach ($tableNames as $t) {
                if (in_array(strtolower($t), self::FRAMEWORK_INFRASTRUCTURE_TABLES, true)) {
                    continue;
                }

                $cols = [];
                $fks = [];
                try {
                    $rawCols = \Illuminate\Support\Facades\Schema::connection($connection)->getColumns($t);
                    foreach ($rawCols as $rc) {
                        $colName = $rc['name'] ?? '';
                        $cols[] = [
                            'name' => $colName,
                            'type' => $rc['type_name'] ?? 'string',
                        ];
                        if (str_ends_with($colName, '_id') && ! in_array($colName, ['id', 'uuid', 'tax_id', 'nid'], true)) {
                            $fks[] = $colName;
                        }
                    }
                } catch (Throwable $e) {}

                $tables[$t] = [
                    'name' => $t,
                    'columns' => $cols,
                    'foreign_keys' => array_values(array_unique($fks)),
                    'migration' => 'database_live',
                    'table_source' => 'source_database',
                ];
            }
        } catch (Throwable $e) {}

        return $tables;
    }

    /**
     * Reconcile live database tables with migration-parsed tables.
     * DB is source of truth; migrations provide metadata enrichment.
     */
    private function reconcileTableSources(array $dbTables, array $migTables, string $connection): array
    {
        $tables = [];

        // Start with DB tables (source of truth)
        foreach ($dbTables as $name => $dbTable) {
            $tables[$name] = $dbTable;

            // Enrich with migration metadata if available
            if (isset($migTables[$name])) {
                $mig = $migTables[$name];
                $tables[$name]['migration'] = $mig['migration'] ?? 'database_live';
                $tables[$name]['table_source'] = 'source_database+migrations';

                // Merge FKs from migrations (more reliable than _id convention)
                if (! empty($mig['foreign_keys'])) {
                    $tables[$name]['foreign_keys'] = array_values(array_unique(
                        array_merge($tables[$name]['foreign_keys'] ?? [], $mig['foreign_keys'])
                    ));
                }
            } else {
                // Table in DB but not in migrations = manual schema or legacy
                $tables[$name]['table_source'] = 'source_database_only';
                $tables[$name]['porting_note'] = 'Table exists in live database but has no migration file. May be legacy or manually created.';
            }
        }

        // Tables in migrations but NOT in live DB = pending/dead
        foreach ($migTables as $name => $migTable) {
            if (! isset($tables[$name])) {
                $tables[$name] = $migTable;
                $tables[$name]['table_source'] = 'migrations_only';
                $tables[$name]['porting_note'] = 'Table defined in migrations but not found in live database. May be pending migration or dead code.';
            }
        }

        return $tables;
    }

    private function extractTablesFromMigrations(string $migDir): array
    {
        $tables = [];
        if (! File::isDirectory($migDir)) {
            return $tables;
        }

        $q = chr(39);
        $dq = chr(34);
        $patternCreate = "/Schema::create\\s*\\(\\s*[" . $q . $dq . "]([^" . $q . $dq . "]+)[" . $q . $dq . "]/";
        $patternCol = "/\\\$table->([a-zA-Z0-9_]+)\\s*\\(\\s*[" . $q . $dq . "]([^" . $q . $dq . "]+)[" . $q . $dq . "]/";
        $patternFk = "/(?:foreignId|foreign)\\s*\\(\\s*[" . $q . $dq . "]([^" . $q . $dq . "]+)[" . $q . $dq . "]\\)/";

        foreach (File::files($migDir) as $file) {
            $content = $file->getContents();
            if (! preg_match_all($patternCreate, $content, $createMatches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $count = count($createMatches[0]);
            for ($i = 0; $i < $count; $i++) {
                $tableName = $createMatches[1][$i][0];
                if (in_array(strtolower($tableName), self::FRAMEWORK_INFRASTRUCTURE_TABLES, true)) {
                    continue;
                }
                $startPos = $createMatches[0][$i][1];
                $endPos = ($i + 1 < $count) ? $createMatches[0][$i + 1][1] : strlen($content);
                $blockContent = substr($content, $startPos, $endPos - $startPos);

                $cols = [];
                $fks = [];

                if (preg_match_all($patternCol, $blockContent, $colMatches, PREG_SET_ORDER)) {
                    foreach ($colMatches as $cm) {
                        $colType = $cm[1];
                        $colName = $cm[2];
                        $cols[] = [
                            'name' => $colName,
                            'type' => $colType,
                        ];

                        // Implicit convention-based foreign key
                        if (str_ends_with($colName, '_id') && ! in_array($colName, ['id', 'uuid', 'tax_id', 'nid'], true)) {
                            $fks[] = $colName;
                        }
                    }
                }

                if (preg_match_all($patternFk, $blockContent, $fkMatches)) {
                    foreach ($fkMatches[1] as $explicitFk) {
                        $fks[] = $explicitFk;
                    }
                }

                $tables[$tableName] = [
                    'name' => $tableName,
                    'columns' => $cols,
                    'foreign_keys' => array_values(array_unique($fks)),
                    'migration' => $file->getFilename(),
                ];
            }
        }

        return $tables;
    }

    private function extractTablesFromDatabase(): array
    {
        $tables = [];
        try {
            $dbTables = Schema::getTableListing();
            foreach ($dbTables as $t) {
                if (in_array(strtolower($t), self::FRAMEWORK_INFRASTRUCTURE_TABLES, true)) {
                    continue;
                }
                $cols = [];
                try {
                    $rawCols = Schema::getColumns($t);
                    foreach ($rawCols as $rc) {
                        $cols[] = [
                            'name' => $rc['name'] ?? '',
                            'type' => $rc['type_name'] ?? 'string',
                        ];
                    }
                } catch (Throwable $e) {}

                $tables[$t] = [
                    'name' => $t,
                    'columns' => $cols,
                    'foreign_keys' => [],
                    'migration' => 'database_live',
                ];
            }
        } catch (Throwable $e) {}

        return $tables;
    }

        private function classifyTables(array $tables): array
    {
        $classified = [
            'roots' => [],
            'children' => [],
            'taxonomies' => [],
            'pivots' => [],
            'system' => [],
        ];

        $taxonomyPattern = '/(types?|natures?|statuse?s?|modes?|regions?|genders?|provinces?|districts?|tehsils?|categories?|faqs?|priorities?|currencies?|departments?|designations?|grades?|units?|sources?|stages?|channels?|concerned_organizations?)$/i';
        $systemPattern = '/^(users|user_profiles|roles|permissions|otps|sms_logs|activity_logs|api_logs|api_users|jobs|job_batches|failed_jobs|cache|cache_locks|sessions|notifications|personal_access_tokens|password_resets?(_tokens)?|telescope_|pulse_|horizon_)/i';

        // 1. Calculate in-degrees (foreign key references from other tables)
        $inDegrees = [];
        $knownTableSingulars = [];
        foreach ($tables as $tName => $tInfo) {
            $inDegrees[$tName] = 0;
            $knownTableSingulars[Str::singular($tName)] = $tName;
        }

        $ignoredInDegreeKeys = ['company', 'tenant', 'organization', 'account', 'user', 'created_by', 'updated_by', 'deleted_by', 'status', 'type', 'currency', 'from_user', 'to_user'];

        foreach ($tables as $tName => $tInfo) {
            foreach ($tInfo['foreign_keys'] as $fk) {
                $base = preg_replace('/_id$/', '', $fk);
                if (in_array($base, $ignoredInDegreeKeys, true)) {
                    continue;
                }
                if (isset($knownTableSingulars[$base])) {
                    $targetTable = $knownTableSingulars[$base];
                    $inDegrees[$targetTable] = ($inDegrees[$targetTable] ?? 0) + 1;
                }
            }
        }

        // Canonical enterprise business entities are NEVER demoted to child or taxonomy tables
        $canonicalRoots = [
            'complaints', 'hearings', 'notices', 'representations', 'report_soughts', 'notice_titles',
            'projects', 'tasks', 'contracts', 'project_time_logs',
            'leads', 'deals', 'client_details', 'clients',
            'invoices', 'payments', 'estimates', 'proposals', 'credit_notes', 'expenses', 'bank_accounts',
            'employee_details', 'employees', 'attendances', 'leaves', 'employee_shift_schedules', 'teams', 'designations', 'appreciations',
            'products', 'orders',
            'tickets', 'events', 'knowledge_bases',
            'users_chat', 'sticky_notes',
            'global_settings',
        ];

        // 2. Classify each table
        foreach ($tables as $tName => $tInfo) {
            if (preg_match($systemPattern, $tName) || in_array(strtolower($tName), self::FRAMEWORK_INFRASTRUCTURE_TABLES, true)) {
                $classified['system'][$tName] = $tInfo;
                continue;
            }

            // Pivot table detection: tenant, audit, and actor keys are not relationship FKs.
            // Furthermore, a table is only a pure pivot if it has NO domain/operational columns.
            $actorOrTenantFkPattern = '/^(company_id|tenant_id|organization_id|account_id|created_by|updated_by|user_id|from_user_id|to_user_id|assigned_to|assigned_by|actor_id)$/';
            $relationshipFks = array_values(array_filter(
                $tInfo['foreign_keys'],
                fn ($fk) => ! preg_match($actorOrTenantFkPattern, $fk)
            ));

            $nonFkColumns = array_filter($tInfo['columns'] ?? [], function($c) {
                $cn = is_array($c) ? ($c['name'] ?? '') : ($c->name ?? '');
                return !in_array($cn, ['id', 'created_at', 'updated_at', 'deleted_at'], true) && !str_ends_with($cn, '_id');
            });

            if (str_contains($tName, '__') || (count($relationshipFks) >= 2 && count($nonFkColumns) === 0)) {
                $classified['pivots'][$tName] = $tInfo;
                continue;
            }

            $isCanonicalRoot = in_array($tName, $canonicalRoots, true);

            // Taxonomy detection: lookup tables match taxonomy pattern
            if (! $isCanonicalRoot && preg_match($taxonomyPattern, $tName)) {
                $classified['taxonomies'][$tName] = $tInfo;
                continue;
            }

            // Determine if table is a child entity of an existing root
            $isChild = false;
            if (! $isCanonicalRoot) {
                foreach ($tInfo['foreign_keys'] as $fk) {
                    $base = preg_replace('/_id$/', '', $fk);
                    if (in_array($base, $ignoredInDegreeKeys, true)) {
                        continue;
                    }
                    if (isset($knownTableSingulars[$base])) {
                        $parentTable = $knownTableSingulars[$base];
                        if (($inDegrees[$parentTable] ?? 0) > ($inDegrees[$tName] ?? 0)) {
                            $isChild = true;
                            break;
                        }
                    }
                }
            }

            if ($isChild) {
                $classified['children'][$tName] = $tInfo;
            } else {
                $classified['roots'][$tName] = $tInfo;
            }
        }

        return $classified;
    }

    private function buildSliceMapping(array $classified, string $domain): array
    {
        $mapping = [];
        $ignoredFkKeys = ['company', 'tenant', 'organization', 'account', 'user', 'created_by', 'updated_by', 'deleted_by', 'status', 'type', 'currency'];

        // 1. Core Primary Roots
        foreach ($classified['roots'] as $rName => $rInfo) {
            $sliceName = Str::studly($rName);
            $mapping[$sliceName] = [
                'type' => 'aggregate_root',
                'primary_table' => $rName,
                'child_tables' => [],
                'description' => "Manages {$rName} records and associated child items",
            ];
        }

        // 2. Attach children to corresponding root slice
        foreach ($classified['children'] as $cName => $cInfo) {
            $attached = false;
            foreach ($cInfo['foreign_keys'] as $fk) {
                $rawBase = preg_replace('/_id$/', '', $fk);
                if (in_array($rawBase, $ignoredFkKeys, true)) {
                    continue;
                }
                $parentBase = Str::studly($rawBase);

                // Pass 1: Exact singular/plural match (e.g. project_id -> Projects, task_id -> Tasks)
                foreach (array_keys($mapping) as $sName) {
                    if (strcasecmp($sName, $parentBase) === 0 
                        || strcasecmp($sName, Str::plural($parentBase)) === 0 
                        || strcasecmp(Str::singular($sName), $parentBase) === 0) {
                        $mapping[$sName]['child_tables'][] = $cName;
                        $attached = true;
                        break;
                    }
                }
                if ($attached) break;

                // Pass 2: Prefix match only if no exact slice exists
                foreach (array_keys($mapping) as $sName) {
                    if (str_starts_with($sName, $parentBase) || str_starts_with($parentBase, Str::singular($sName))) {
                        $mapping[$sName]['child_tables'][] = $cName;
                        $attached = true;
                        break;
                    }
                }
                if ($attached) break;
            }

            // Fallback: name prefix match (e.g. project_time_logs -> Projects, invoice_items -> Invoices)
            if (! $attached) {
                $cPrefix = Str::studly(explode('_', $cName)[0] ?? '');
                foreach (array_keys($mapping) as $sName) {
                    if (strcasecmp($sName, $cPrefix) === 0 
                        || strcasecmp($sName, Str::plural($cPrefix)) === 0 
                        || strcasecmp(Str::singular($sName), $cPrefix) === 0) {
                        $mapping[$sName]['child_tables'][] = $cName;
                        $attached = true;
                        break;
                    }
                }
                if (! $attached) {
                    foreach (array_keys($mapping) as $sName) {
                        if (str_starts_with($sName, $cPrefix) || str_starts_with($cPrefix, Str::singular($sName))) {
                            $mapping[$sName]['child_tables'][] = $cName;
                            $attached = true;
                            break;
                        }
                    }
                }
            }

            // Fallback 2: Bundle auxiliary settings into the Settings hub instead of polluting the sidebar
            if (! $attached) {
                if (str_ends_with($cName, '_settings') || str_contains($cName, 'backup') || str_contains($cName, 'custom_field') || in_array($cName, ['log_time_for', 'language_settings', 'company_addresses', 'social_auth_settings', 'file_storage_settings', 'theme_settings'], true)) {
                    $settingsSlice = 'GlobalSettings';
                    if (! isset($mapping[$settingsSlice])) {
                        $mapping[$settingsSlice] = [
                            'type' => 'aggregate_root',
                            'primary_table' => 'global_settings',
                            'child_tables' => [],
                            'description' => 'Unified Enterprise Settings Hub',
                        ];
                    }
                    $mapping[$settingsSlice]['child_tables'][] = $cName;
                    $attached = true;
                }
            }

            // If still unattached, promote to its own slice rather than force-bundling into unrelated slices
            if (! $attached) {
                $sliceName = Str::studly($cName);
                $mapping[$sliceName] = [
                    'type' => 'aggregate_root',
                    'primary_table' => $cName,
                    'child_tables' => [],
                    'description' => "Manages {$cName} operational records",
                ];
            }
        }

        // 3. Attach taxonomies to their owning entity slice by name affinity (e.g. lead_sources -> Leads, leave_types -> Leaves)
        $unattachedTaxonomies = [];
        foreach ($classified['taxonomies'] as $tName => $tInfo) {
            $attached = false;
            $tPrefix = Str::studly(explode('_', $tName)[0] ?? '');
            foreach (array_keys($mapping) as $sName) {
                if (strcasecmp($sName, $tPrefix) === 0 
                    || strcasecmp($sName, Str::plural($tPrefix)) === 0 
                    || strcasecmp(Str::singular($sName), $tPrefix) === 0) {
                    $mapping[$sName]['child_tables'][] = $tName;
                    $attached = true;
                    break;
                }
            }
            if (! $attached) {
                $unattachedTaxonomies[] = $tName;
            }
        }

        // Only truly cross-cutting / global taxonomies (e.g. countries, currencies) group into ReferenceData
        if (! empty($unattachedTaxonomies)) {
            $primaryTaxTable = array_shift($unattachedTaxonomies);
            $catalogName = ($domain === 'Adjudication') ? 'MasterData' : 'ReferenceData';
            $mapping[$catalogName] = [
                'type' => 'taxonomy_catalog',
                'taxonomy' => true,
                'primary_table' => $primaryTaxTable,
                'child_tables' => $unattachedTaxonomies,
                'description' => 'Shared statutory taxonomies, lookup registries, and regional catalogs',
            ];
        }

        // 3b. Surface pivot tables: attach to owning slice via FK affinity, track unmapped ones
        // (previously pivots were classified but silently dropped from the plan)
        $unmappedPivots = [];
        foreach ($classified['pivots'] ?? [] as $pName => $pInfo) {
            $attached = false;
            foreach ($pInfo['foreign_keys'] ?? [] as $fk) {
                $rawBase = preg_replace('/_id$/', '', $fk);
                $parentBase = Str::studly($rawBase);
                foreach (array_keys($mapping) as $sName) {
                    if (strcasecmp($sName, $parentBase) === 0
                        || strcasecmp($sName, Str::plural($parentBase)) === 0
                        || strcasecmp(Str::singular($sName), $parentBase) === 0) {
                        $mapping[$sName]['pivot_tables'][] = $pName;
                        $attached = true;
                        break;
                    }
                }
                if ($attached) break;
            }
            if (! $attached) {
                $unmappedPivots[] = $pName;
            }
        }

        // 4. Sort slices by child count descending (most important core aggregate root first, MasterData last)
        uasort($mapping, function ($a, $b) {
            if (($a['taxonomy'] ?? false) && ! ($b['taxonomy'] ?? false)) return 1;
            if (! ($a['taxonomy'] ?? false) && ($b['taxonomy'] ?? false)) return -1;
            return count($b['child_tables'] ?? []) <=> count($a['child_tables'] ?? []);
        });

        return ['mapping' => $mapping, 'unmapped_pivots' => $unmappedPivots];
    }

    private function generateTaxonomySeeders(array $inspection, string $domain): array
    {
        $created = [];
        // Derive the catalog slice name from the actual mapping (MasterData for
        // Adjudication, ReferenceData otherwise) instead of hardcoding MasterData.
        $catalogName = 'MasterData';
        foreach ($inspection['slice_mapping'] ?? [] as $sName => $info) {
            if (($info['type'] ?? '') === 'taxonomy_catalog') {
                $catalogName = $sName;
                break;
            }
        }
        $seederDir = app_path("Slices/{$domain}/{$catalogName}/Database/Seeders");
        File::ensureDirectoryExists($seederDir);

        $className = Str::studly($domain).'MasterDataSeeder';
        $filePath = $seederDir.DIRECTORY_SEPARATOR.$className.'.php';

        $taxonomies = array_keys($inspection['classified']['taxonomies'] ?? []);
        $sourceSeedersDir = ($inspection['source_path'] ?? '').DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'seeders';

        // Dynamically index existing seeders in source project directory
        $availableSeeders = [];
        if (File::isDirectory($sourceSeedersDir)) {
            foreach (File::files($sourceSeedersDir) as $sFile) {
                $fname = $sFile->getFilename();
                $lowerName = strtolower(str_replace(['TableSeeder.php', 'Seeder.php', '_'], '', $fname));
                $availableSeeders[$lowerName] = $sFile->getPathname();
            }
        }

        $code = "<?php\n\nnamespace App\\Slices\\{$domain}\\MasterData\\Database\\Seeders;\n\n";
        $code .= "use Illuminate\\Database\\Seeder;\n";
        $code .= "use Illuminate\\Support\\Facades\\DB;\n";
        $code .= "use Illuminate\\Support\\Facades\\Schema;\n\n";
        $code .= "class {$className} extends Seeder\n{\n";
        $code .= "    public function run(): void\n    {\n";
        $code .= "        // Auto-generated by LaraSlice Porting Engine\n";
        $code .= "        // Mode: system-base (Populates master dropdowns and taxonomies with 0 blank selects)\n\n";

        foreach ($taxonomies as $tbl) {
            $cleanedTbl = strtolower(str_replace(['_', 'tbl'], '', $tbl));
            $cleanedSingular = strtolower(str_replace(['_', 'tbl'], '', Str::singular($tbl)));
            $dataCode = null;

            // Search if a seeder file exists for this taxonomy
            $matchedFile = $availableSeeders[$cleanedTbl] ?? $availableSeeders[$cleanedSingular] ?? null;
            if ($matchedFile && file_exists($matchedFile)) {
                $content = file_get_contents($matchedFile);
                if (preg_match('/\$[a-zA-Z0-9_]+\s*=\s*(\[[\s\S]*?\n\s*\]);/', $content, $m)) {
                    $dataCode = trim($m[1]);
                }
            }

            $code .= "        if (Schema::hasTable('{$tbl}')) {\n";
            if ($dataCode) {
                $code .= "            \$rawData = {$dataCode};\n";
                $code .= "            \$insertRows = [];\n";
                $code .= "            foreach (\$rawData as \$k => \$v) {\n";
                $code .= "                if (is_array(\$v) && isset(\$v['id'])) {\n";
                $code .= "                    \$insertRows[] = \$v;\n";
                $code .= "                } elseif (is_array(\$v)) {\n";
                $code .= "                    \$insertRows[] = array_merge(['id' => \$k], \$v);\n";
                $code .= "                } elseif (is_string(\$v) || is_numeric(\$v)) {\n";
                $code .= "                    \$insertRows[] = ['id' => \$k, 'name' => (string) \$v];\n";
                $code .= "                }\n";
                $code .= "            }\n";
                $code .= "            if (! empty(\$insertRows)) {\n";
                $code .= "                foreach (array_chunk(\$insertRows, 100) as \$chunk) {\n";
                $code .= "                    DB::table('{$tbl}')->insertOrIgnore(\$chunk);\n";
                $code .= "                }\n";
                $code .= "            }\n";
            } else {
                // Ensure zero blank dropdowns by providing baseline active options
                $singularLabel = Str::headline(Str::singular($tbl));
                $code .= "            if (DB::table('{$tbl}')->count() === 0) {\n";
                $code .= "                \$cols = Schema::getColumnListing('{$tbl}');\n";
                $code .= "                \$defaults = [];\n";
                $code .= "                if (in_array('name', \$cols)) \$defaults['name'] = 'Standard {$singularLabel}';\n";
                $code .= "                if (in_array('title', \$cols)) \$defaults['title'] = 'Standard {$singularLabel}';\n";
                $code .= "                if (in_array('slug', \$cols)) \$defaults['slug'] = 'standard';\n";
                $code .= "                if (in_array('code', \$cols)) \$defaults['code'] = 'STD';\n";
                $code .= "                if (in_array('is_active', \$cols)) \$defaults['is_active'] = 1;\n";
                $code .= "                if (in_array('status', \$cols)) \$defaults['status'] = 'active';\n";
                $code .= "                if (! empty(\$defaults)) {\n";
                $code .= "                    DB::table('{$tbl}')->insertOrIgnore(\$defaults);\n";
                $code .= "                }\n";
                $code .= "            }\n";
            }
            $code .= "        }\n\n";
        }

        $code .= "    }\n}\n";

        File::put($filePath, $code);
        $created[] = $filePath;

        $hostSeederDir = database_path('seeders');
        File::ensureDirectoryExists($hostSeederDir);
        $hostSeederPath = $hostSeederDir.DIRECTORY_SEPARATOR.$className.'.php';
        File::put($hostSeederPath, str_replace("namespace App\\Slices\\{$domain}\\MasterData\\Database\\Seeders;", "namespace Database\\Seeders;", $code));
        $created[] = $hostSeederPath;

        return $created;
    }

    /**
     * Convert migration columns into Studio Field objects with UI and Form widgets.
     */
    protected function convertColumnsToFields(array $columns): array
    {
        $fields = [];
        $typeMap = [
            'string' => 'string',
            'varchar' => 'string',
            'char' => 'string',
            'text' => 'text',
            'mediumText' => 'text',
            'longText' => 'text',
            'integer' => 'integer',
            'bigInteger' => 'integer',
            'tinyInteger' => 'boolean',
            'smallInteger' => 'integer',
            'unsignedBigInteger' => 'integer',
            'unsignedInteger' => 'integer',
            'boolean' => 'boolean',
            'date' => 'datetime',
            'dateTime' => 'datetime',
            'timestamp' => 'datetime',
            'decimal' => 'decimal',
            'float' => 'decimal',
            'double' => 'decimal',
            'json' => 'json',
            'foreignId' => 'integer',
        ];

        $seen = [];
        foreach ($columns as $col) {
            $cName = $col['name'];
            if (in_array($cName, ['id', 'created_at', 'updated_at', 'deleted_at']) || isset($seen[$cName])) {
                continue;
            }
            $seen[$cName] = true;

            $rawType = $col['type'] ?? 'string';
            $studioType = $typeMap[$rawType] ?? 'string';

            // Determine input widget & UI control
            $inputType = 'text';
            if (preg_match('/(file|document|attachment|affidavit|cnic_copy|photo|image|signature|receipt)/i', $cName)) {
                $inputType = 'file';
                $studioType = 'string';
            } elseif ($studioType === 'text') {
                $inputType = 'textarea';
            } elseif ($studioType === 'boolean') {
                $inputType = 'switch';
            } elseif ($studioType === 'datetime') {
                $inputType = 'datetime';
            } elseif (str_ends_with($cName, '_id')) {
                $inputType = 'select';
            } elseif (preg_match('/(email|mail)/i', $cName)) {
                $inputType = 'email';
            } elseif (preg_match('/(phone|mobile|cell|contact)/i', $cName)) {
                $inputType = 'tel';
            }

            $fields[] = [
                'handle' => $cName,
                'label' => Str::headline($cName),
                'type' => $studioType,
                'input_type' => $inputType,
                'required' => false,
                'nullable' => true,
                'width' => in_array($inputType, ['textarea', 'file']) ? 100 : 50,
                'optionsText' => '',
            ];
        }

        return $fields;
    }

    /**
     * Build rich multi-slice structures directly consumable by Blueprint Studio Alpine.js.
     */
    public function buildStudioSlices(array $sliceMapping, array $tables, string $domain, array $options = []): array
    {
        $studioSlices = [];
        $multitenant = $options['multitenant'] ?? false;
        $tenantKey = $options['tenant_key'] ?? 'company_id';

        foreach ($sliceMapping as $sliceName => $info) {
            $sliceHandle = Str::snake($sliceName);
            $primaryTable = $info['primary_table'];
            $childTables = $info['child_tables'] ?? [];
            $models = [];
            $allRelations = [];

            // 1. Primary Aggregate Root Model
            $primaryCols = $tables[$primaryTable]['columns'] ?? [];
            $primaryFields = $this->convertColumnsToFields($primaryCols);
            $rootModelHandle = Str::snake(Str::singular($primaryTable));

            $isTaxonomy = ($info['type'] ?? '') === 'taxonomy_catalog' || ($info['taxonomy'] ?? false) === true;
            $rootRelations = [];

            if (! $isTaxonomy) {
                foreach ($childTables as $childTable) {
                    $childHandle = Str::snake(Str::singular($childTable));
                    $fk = Str::singular($primaryTable) . '_id';
                    $rootRelations[] = [
                        'name' => Str::snake($childTable),
                        'type' => 'hasMany',
                        'model' => $childHandle,
                        'foreign_key' => $fk,
                    ];
                    $allRelations[] = [
                        'type' => 'hasMany',
                        'source' => $primaryTable,
                        'target' => $childTable,
                        'source_model' => $rootModelHandle,
                        'model' => $childHandle,
                        'foreign_key' => $fk,
                    ];
                }
            }

            $models[] = [
                'name' => Str::studly(Str::singular($primaryTable)),
                'handle' => $rootModelHandle,
                'table' => $primaryTable,
                'root' => true,
                'timestamps' => true,
                'soft_deletes' => true,
                'multitenant' => $multitenant,
                'tenant_key' => $tenantKey,
                'description' => "Primary aggregate root for {$sliceName}",
                'fields' => $primaryFields,
                'relations' => $rootRelations,
            ];

            // 2. Child Models & Relationships
            foreach ($childTables as $childTable) {
                $childCols = $tables[$childTable]['columns'] ?? [];
                $childFields = $this->convertColumnsToFields($childCols);
                $childHandle = Str::snake(Str::singular($childTable));
                $fk = Str::singular($primaryTable) . '_id';

                $childRelations = $isTaxonomy ? [] : [
                    [
                        'name' => $rootModelHandle,
                        'type' => 'belongsTo',
                        'model' => $rootModelHandle,
                        'foreign_key' => $fk,
                    ],
                ];

                $models[] = [
                    'name' => Str::studly(Str::singular($childTable)),
                    'handle' => $childHandle,
                    'table' => $childTable,
                    'root' => false,
                    'timestamps' => true,
                    'soft_deletes' => false,
                    'multitenant' => $multitenant,
                    'tenant_key' => $tenantKey,
                    'description' => "Child entity of {$sliceName}",
                    'fields' => $childFields,
                    'relations' => $childRelations,
                ];
            }

            // 3. Permissions & Security
            $permissions = [
                "{$sliceHandle}.view",
                "{$sliceHandle}.create",
                "{$sliceHandle}.edit",
                "{$sliceHandle}.delete",
            ];
            if (in_array($sliceHandle, ['invoices', 'expenses', 'leaves', 'contracts', 'petitions'], true)) {
                $permissions[] = "{$sliceHandle}.approve";
            }
            if (in_array($sliceHandle, ['invoices', 'projects', 'tasks', 'attendances', 'reports'], true)) {
                $permissions[] = "{$sliceHandle}.export";
            }

            // 4. Workflow Lifecycle (Dynamic detection based on status fields)
            $workflow = null;
            $statusField = null;
            foreach ($primaryFields as $f) {
                if ($f['handle'] === 'status' || str_ends_with($f['handle'], '_status') || str_ends_with($f['handle'], '_status_id')) {
                    $statusField = $f['handle'];
                    break;
                }
            }
            if ($statusField) {
                $workflow = [
                    'enabled' => true,
                    'state_column' => $statusField,
                    'states' => [
                        ['slug' => 'draft', 'label' => 'Draft', 'color' => 'slate'],
                        ['slug' => 'pending', 'label' => 'Pending', 'color' => 'amber'],
                        ['slug' => 'in_progress', 'label' => 'In Progress', 'color' => 'blue'],
                        ['slug' => 'completed', 'label' => 'Completed', 'color' => 'emerald'],
                        ['slug' => 'cancelled', 'label' => 'Cancelled', 'color' => 'rose'],
                    ],
                    'transitions' => [
                        ['slug' => 'submit', 'name' => 'Submit', 'from' => ['draft'], 'to' => 'pending', 'target_role' => 'manager'],
                        ['slug' => 'start', 'name' => 'Start', 'from' => ['pending'], 'to' => 'in_progress', 'target_role' => 'manager'],
                        ['slug' => 'complete', 'name' => 'Complete', 'from' => ['in_progress'], 'to' => 'completed', 'target_role' => 'admin'],
                        ['slug' => 'cancel', 'name' => 'Cancel', 'from' => ['draft', 'pending', 'in_progress'], 'to' => 'cancelled', 'target_role' => 'admin', 'requires_remarks' => true],
                    ],
                ];
            }

            // 5. Scoped RBAC Roles
            $roles = [
                [
                    'slug' => 'super_admin',
                    'name' => 'Super Admin',
                    'description' => "Full apex access to {$sliceName} and system administration",
                    'seed' => true,
                    'seedUser' => true,
                    'permissions' => $permissions,
                ],
                [
                    'slug' => 'admin',
                    'name' => 'Admin',
                    'description' => "Administrative oversight and operational management for {$sliceName}",
                    'seed' => true,
                    'seedUser' => true,
                    'permissions' => $permissions,
                ],
                [
                    'slug' => 'manager',
                    'name' => 'Manager',
                    'description' => "Operational workflow management for {$sliceName}",
                    'seed' => true,
                    'seedUser' => false,
                    'permissions' => ["{$sliceHandle}.view", "{$sliceHandle}.create", "{$sliceHandle}.edit"],
                ],
                [
                    'slug' => 'user',
                    'name' => 'Standard User',
                    'description' => "Standard access to view and create {$sliceName}",
                    'seed' => true,
                    'seedUser' => false,
                    'permissions' => ["{$sliceHandle}.view", "{$sliceHandle}.create"],
                ],
            ];

            // 6. Navigation Icon & Grouping
            $icon = match(strtolower($sliceName)) {
                'projects' => 'briefcase',
                'tasks' => 'check-square',
                'timelogs', 'projecttimelogs' => 'clock',
                'invoices', 'estimates', 'proposals' => 'file-text',
                'payments' => 'credit-card',
                'expenses' => 'receipt',
                'bankaccounts' => 'landmark',
                'creditnotes' => 'file-minus',
                'clients', 'clientdetails' => 'users',
                'leads' => 'target',
                'deals' => 'dollar-sign',
                'contracts' => 'file-text',
                'employees', 'employeedetails' => 'user-check',
                'attendances' => 'calendar-check',
                'leaves' => 'calendar-off',
                'shifts', 'employeeshifts' => 'calendar',
                'organization', 'teams' => 'network',
                'products' => 'box',
                'orders' => 'shopping-cart',
                'procurement' => 'truck',
                'tickets' => 'life-buoy',
                'notices' => 'bell',
                'events' => 'calendar-event',
                'knowledgebases' => 'book-open',
                'messages', 'userschat' => 'message-square',
                'stickynotes' => 'sticky-note',
                'settings', 'globalsettings' => 'settings',
                'referencedata', 'masterdata' => 'database',
                default => 'layers',
            };

            $blueprintObj = [
                'schema_version' => 1,
                'name' => $sliceName,
                'handle' => $sliceHandle,
                'domain' => $domain,
                'multitenant' => $multitenant,
                'tenant_key' => $tenantKey,
                'description' => $info['description'] ?? "Vertical slice for {$sliceName}",
                'permissions' => $permissions,
                'models' => $models,
                'workflow' => $workflow,
                'roles' => $roles,
                'navigation' => [
                    'label' => $sliceName,
                    'icon' => $icon,
                    'group' => $domain,
                    'route' => Str::slug($domain) . '/' . Str::slug($sliceHandle),
                    // Akaunting-style: numeric ordering for easy insertion, permission-gated
                    'order' => $info['nav_order'] ?? 50,
                    'permission' => Str::snake(Str::singular($sliceName)).'.view',
                ],
            ];

            // 7. Generate Slice YAML representation
            $sourceYaml = Yaml::dump($blueprintObj, 8, 2);

            $studioSlices[] = [
                'name' => $sliceName,
                'handle' => $sliceHandle,
                'domain' => $domain,
                'blueprint' => $blueprintObj,
                'allRelations' => $allRelations,
                'source' => $sourceYaml,
            ];
        }

        return $studioSlices;
    }

    /**
     * Generate complete unified domain blueprint YAML.
     */
    public function generateDomainBlueprintYaml(array $studioSlices, string $domain, array $inspection = []): string
    {
        $domainData = [
            // NOTE: This is a PORT ANALYSIS REPORT, not an appliable blueprint.
            // It documents what slice:port discovered. Per-slice slice.yaml files
            // (schema v2) are the canonical appliable manifests — validate them
            // with `php artisan slice:blueprint:validate app/Slices/<Domain>/<Slice>/slice.yaml`.
            'kind' => 'laraslice-port-analysis-report',
            'schema_version' => 1,
            'domain' => $domain,
            'source_application' => basename($inspection['source_path'] ?? 'legacy-app'),
            'total_tables' => $inspection['total_tables'] ?? count($studioSlices),
            'total_slices' => count($studioSlices),
            'tenancy' => $inspection['tenancy'] ?? ['enabled' => false],
            'generated_at' => date('Y-m-d H:i:s'),
            'slices' => array_map(function($s) {
                return $s['blueprint'] ?? $s;
            }, $studioSlices),
        ];

        return Yaml::dump($domainData, 8, 2);
    }

    /**
     * Generate a complete, exportable JSON Architecture Plan and Sidebar Preview.
     */
    /**
     * Analyze source system's navigation structure.
     * Detects how the source groups menu items (dropdowns, order, icons, permissions).
     */
    
    /**
     * Merge proposed module sidebar with authentic source navigation (legacy menu.blade / x-menu-item sidebars).
     *
     * @param  list<array<string, mixed>>  $proposedGroups
     * @return array<string, mixed>
     */
    protected function buildSidebarPreviewForPlan(string $domain, array $proposedGroups, string $sourcePath): array
    {
        $sourceNav = $this->analyzeSourceNavigation($sourcePath);
        $sourceGroups = $sourceNav['groups'] ?? [];

        $previewGroups = $proposedGroups;
        if (! empty($sourceGroups)) {
            $previewGroups = [];
            foreach ($sourceGroups as $g) {
                $items = [];
                foreach ($g['children'] ?? [] as $c) {
                    $items[] = [
                        'label' => $c['label'] ?? 'Item',
                        'icon' => $c['icon'] ?? 'circle',
                        'route' => $c['route'] ?? '#',
                        'slice' => $c['slice'] ?? null,
                        'badge' => null,
                        'description' => null,
                    ];
                }
                if ($items === [] && ! empty($g['route'])) {
                    $items[] = [
                        'label' => $g['label'] ?? $g['name'] ?? 'Item',
                        'icon' => $g['icon'] ?? 'circle',
                        'route' => $g['route'],
                        'slice' => $g['slice'] ?? null,
                    ];
                }
                $previewGroups[] = [
                    'name' => $g['label'] ?? $g['name'] ?? 'Group',
                    'icon' => $g['icon'] ?? 'folder',
                    'collapsible' => ($g['type'] ?? '') === 'dropdown',
                    'default_open' => true,
                    'modules' => $g['modules'] ?? [],
                    'permissions' => $g['permissions'] ?? [],
                    'items' => $items,
                ];
            }
            $hasDash = false;
            foreach ($previewGroups as $pg) {
                foreach ($pg['items'] ?? [] as $it) {
                    if (str_contains(strtolower((string) ($it['route'] ?? '')), 'dashboard')) {
                        $hasDash = true;
                        break 2;
                    }
                }
            }
            if (! $hasDash && isset($proposedGroups[0])) {
                array_unshift($previewGroups, $proposedGroups[0]);
            }
        }

        return [
            'title' => "{$domain} Enterprise Navigation",
            'theme' => 'BlatUI Dashboard-01',
            'groups' => $previewGroups,
            'source_navigation' => $sourceNav,
            'navigation_note' => ! empty($sourceGroups)
                ? 'Sidebar compiled from source menu (x-menu-item / blade). Module gates and permissions preserved for Studio customization.'
                : 'Source menu not detected — sidebar derived from slice modules. Point --source-path at the legacy app root for 1:1 nav.',
        ];
    }

public function analyzeSourceNavigation(string $sourcePath): array
    {
        $navigation = [
            'groups' => [],
            'contexts' => [],
            'source' => 'not_detected',
        ];

        // Strategy 1: Laravel menu listeners (Akaunting pattern)
        $menuListeners = [
            $sourcePath.'/app/Listeners/Menu/ShowInAdmin.php',
            $sourcePath.'/app/Listeners/Menu/ShowInPortal.php',
        ];

        foreach ($menuListeners as $listenerFile) {
            if (! file_exists($listenerFile)) {
                continue;
            }

            $content = file_get_contents($listenerFile);
            $isPortal = str_contains($listenerFile, 'Portal');
            $groups = $this->parseMenuListener($content);

            if (! empty($groups)) {
                $navigation['source'] = 'menu_listener';
                $navigation['groups'] = array_merge(
                    $navigation['groups'],
                    array_map(fn($g) => array_merge($g, ['context' => $isPortal ? 'portal' : 'admin']), $groups)
                );
            }
        }

        // Strategy 2: Config-based menu (config/menu.php with items)
        $configMenu = $sourcePath.'/config/menu.php';
        if (empty($navigation['groups']) && file_exists($configMenu)) {
            $groups = $this->parseConfigMenu($configMenu);
            if (! empty($groups)) {
                $navigation['source'] = 'config_menu';
                $navigation['groups'] = $groups;
            }
        }

        // Strategy 3: Blade sidebar with hardcoded links & role-based items
        if (empty($navigation['groups'])) {
            $bladeNav = $this->parseBladeNavigation($sourcePath);
            if (! empty($bladeNav['items'])) {
                $navigation['source'] = 'blade_templates';
                $navigation['groups'] = $bladeNav['items'];
                $navigation['contexts'] = $bladeNav['contexts'];
            }
        }

        return $navigation;
    }

    /**
     * Parse Akaunting-style menu listener for navigation groups.
     */
    private function parseMenuListener(string $content): array
    {
        $groups = [];

        // Match menu items with order and icon
        if (preg_match_all('/\$menu->(route|dropdown)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\$title\s*,\s*\[\]\s*,\s*(\d+)\s*,\s*\[[^\]]*\'icon\'\s*=>\s*[\'"]([^\'"]+)[\'"]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $type = $m[1];
                $route = $m[2];
                $order = (int) $m[3];
                $icon = $m[4];
                $groups[] = [
                    'label' => ucfirst($route),
                    'route' => $route,
                    'order' => $order,
                    'icon' => $icon,
                    'type' => $type,
                    'children' => [],
                ];
            }
        }

        usort($groups, fn($a, $b) => $a['order'] <=> $b['order']);
        return $groups;
    }

    /**
     * Parse config/menu.php for navigation items.
     */
    private function parseConfigMenu(string $configFile): array
    {
        $groups = [];
        try {
            $config = include $configFile;
            if (isset($config['items']) && is_array($config['items'])) {
                foreach ($config['items'] as $idx => $item) {
                    $groups[] = [
                        'label' => $item['title'] ?? $item['label'] ?? "Item {$idx}",
                        'route' => $item['route'] ?? $item['url'] ?? '#',
                        'order' => $item['order'] ?? ($idx * 10),
                        'icon' => $item['icon'] ?? 'circle',
                        'type' => isset($item['children']) ? 'dropdown' : 'route',
                        'children' => $item['children'] ?? [],
                    ];
                }
            }
        } catch (Throwable $e) {}

        return $groups;
    }

    /**
     * Parse Blade component menus (x-menu-item / x-sub-menu-item) (x-menu-item / x-sub-menu-item).
     * Gated by user_modules() and $sidebarUserPermissions.
     *
     * @return array{groups: list<array>, contexts: list<string>}
     */
    private function parseXMenuItemBlade(string $rawContent, string $context, string $filePath): array
    {
        $groups = [];
        $contexts = [$context];

        // Parent menu blocks: <x-menu-item icon="..." :text="__('app.menu.xxx')"> ... </x-menu-item>
        // Non-greedy body until closing tag (approximate; nested components are sub-items only)
        $parentPattern = '/<x-menu-item\b([^>]*)>(.*?)<\/x-menu-item>/is';
        if (! preg_match_all($parentPattern, $rawContent, $parents, PREG_SET_ORDER)) {
            // Single-level links only
            $parents = [];
        }

        $routeToSlice = static function (string $route): string {
            $base = explode('.', $route)[0] ?? $route;
            $base = str_replace(['-', '_'], ' ', $base);

            return \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($base));
        };

        $labelFromTextAttr = static function (string $attrs): string {
            if (preg_match('/:text\s*=\s*"__\(\s*[\'"]([^\'"]+)[\'"]\s*\)"/', $attrs, $m)) {
                $key = $m[1];
                $parts = explode('.', $key);

                return \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', end($parts)));
            }
            if (preg_match('/:text\s*=\s*"([^"]+)"/', $attrs, $m)) {
                return trim($m[1]);
            }
            if (preg_match('/text\s*=\s*"([^"]+)"/', $attrs, $m)) {
                return trim($m[1]);
            }

            return 'Menu';
        };

        $iconFromAttrs = static function (string $attrs): string {
            if (preg_match('/\bicon\s*=\s*"([^"]+)"/', $attrs, $m)) {
                return $m[1];
            }

            return 'circle';
        };

        foreach ($parents as $p) {
            $attrs = $p[1];
            $body = $p[2];
            $label = $labelFromTextAttr($attrs);
            $icon = $iconFromAttrs($attrs);
            $children = [];

            // Sub items
            if (preg_match_all('/<x-sub-menu-item\b([^>]*)\s*\/?>/i', $body, $subs, PREG_SET_ORDER)) {
                foreach ($subs as $s) {
                    $sa = $s[1];
                    $route = null;
                    if (preg_match('/:link\s*=\s*"route\(\s*[\'"]([^\'"]+)[\'"]/', $sa, $rm)) {
                        $route = $rm[1];
                    } elseif (preg_match('/route\(\s*[\'"]([^\'"]+)[\'"]/', $sa, $rm)) {
                        $route = $rm[1];
                    }
                    if (! $route || $route === 'logout') {
                        continue;
                    }
                    $childLabel = $labelFromTextAttr($sa);
                    $children[] = [
                        'label' => $childLabel,
                        'route' => $route,
                        'slice' => $routeToSlice($route),
                        'icon' => 'circle',
                        'type' => 'route',
                    ];
                }
            }

            // Direct link on parent (no children)
            $parentRoute = null;
            if (preg_match('/:link\s*=\s*"route\(\s*[\'"]([^\'"]+)[\'"]/', $attrs, $rm)) {
                $parentRoute = $rm[1];
            }

            // Module / permission hints from preceding 500 chars
            $pos = strpos($rawContent, $p[0]);
            $window = $pos !== false ? substr($rawContent, max(0, $pos - 600), 600) : '';
            $modules = [];
            if (preg_match_all('/in_array\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*user_modules\(\)/', $window, $mm)) {
                $modules = array_values(array_unique($mm[1]));
            }
            $permissions = [];
            if (preg_match_all('/\$sidebarUserPermissions\[[\'"]([^\'"]+)[\'"]\]/', $window, $pm)) {
                $permissions = array_values(array_unique($pm[1]));
            }
            $roles = [];
            if (preg_match('/in_array\(\s*[\'"]client[\'"]\s*,\s*user_roles\(\)/', $window)) {
                // often negated for HR — mark exclude_client when !in_array('client'
                if (preg_match('/!\s*in_array\(\s*[\'"]client[\'"]\s*,\s*user_roles\(\)/', $window)) {
                    $roles[] = 'exclude:client';
                }
            }

            $groups[] = [
                'label' => $label,
                'name' => $label,
                'icon' => $icon,
                'type' => ! empty($children) ? 'dropdown' : 'route',
                'route' => $parentRoute,
                'slice' => $parentRoute ? $routeToSlice($parentRoute) : null,
                'children' => $children,
                'modules' => $modules,
                'permissions' => $permissions,
                'roles' => $roles,
                'context' => $context,
                'source_file' => basename($filePath),
            ];
        }

        // Orphan sub-menu items (if any outside parents)
        if (empty($groups) && preg_match_all('/<x-sub-menu-item\b([^>]*)\s*\/?>/i', $rawContent, $subs, PREG_SET_ORDER)) {
            $children = [];
            foreach ($subs as $s) {
                $sa = $s[1];
                if (preg_match('/:link\s*=\s*"route\(\s*[\'"]([^\'"]+)[\'"]/', $sa, $rm)) {
                    $route = $rm[1];
                    $children[] = [
                        'label' => $labelFromTextAttr($sa),
                        'route' => $route,
                        'slice' => $routeToSlice($route),
                        'type' => 'route',
                    ];
                }
            }
            if ($children) {
                $groups[] = [
                    'label' => 'Main',
                    'name' => 'Main',
                    'type' => 'dropdown',
                    'children' => $children,
                    'context' => $context,
                ];
            }
        }

        return ['groups' => $groups, 'contexts' => $contexts];
    }

    /**
     * Parse Blade templates for authentic navigation components, human labels, and role constraints.
     */
    private function parseBladeNavigation(string $sourcePath): array
    {
        $sidebarFiles = [];
        $viewsDir = $sourcePath . '/resources/views';
        if (! is_dir($viewsDir)) {
            return ['items' => [], 'contexts' => []];
        }

        // 1. Canonical sidebars + ERP-class menu.blade.php (benchmark for gated nav)
        $candidatePatterns = [
            $viewsDir.'/sections/menu.blade.php',
            $viewsDir.'/sections/sidebar.blade.php',
            $viewsDir.'/components/admin/sidebar.blade.php',
            $viewsDir.'/components/complainant/sidebar.blade.php',
            $viewsDir.'/components/sidebar.blade.php',
            $viewsDir.'/layouts/sidebar.blade.php',
            $viewsDir.'/partials/sidebar.blade.php',
            $viewsDir.'/menu.blade.php',
        ];
        foreach ($candidatePatterns as $p) {
            if (file_exists($p)) {
                $sidebarFiles[] = $p;
            }
        }

        if (empty($sidebarFiles)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $f) {
                $fn = strtolower($f->getFilename());
                if ((str_contains($fn, 'sidebar') || $fn === 'menu.blade.php') && str_ends_with($fn, '.blade.php')) {
                    $sidebarFiles[] = $f->getPathname();
                    if (count($sidebarFiles) >= 8) {
                        break;
                    }
                }
            }
        }

        $iconMap = [
            'dashboard' => 'layout-dashboard',
            'complain' => 'inbox',
            'opencomplains' => 'folder-open',
            'order&casefiles' => 'file-signature',
            'hearingorderprocessing' => 'file-text',
            'hearingmanagment' => 'calendar-check',
            'causelist' => 'calendar',
            'decided-cases' => 'archive',
            'notices' => 'bell',
            'faq' => 'help-circle',
            'usermanagment' => 'users',
            'api' => 'cpu',
            'activity' => 'activity',
            'profile' => 'user',
            'logout' => 'log-out',
        ];

        $items = [];
        $contexts = [];

        foreach ($sidebarFiles as $filePath) {
            $rawContent = file_get_contents($filePath);
            $context = 'admin';
            if (str_contains(strtolower($filePath), 'complainant')) {
                $context = 'complainant';
            } elseif (str_contains(strtolower($filePath), 'portal') || str_contains(strtolower($filePath), 'client')) {
                $context = 'portal';
            }

            // x-menu-item / Blat-style component menus (ERP-class benchmark)
            if (str_contains($rawContent, 'x-menu-item') || str_contains($rawContent, 'x-sub-menu-item')) {
                $ws = $this->parseXMenuItemBlade($rawContent, $context, $filePath);
                foreach ($ws['groups'] as $g) {
                    $items[] = $g;
                }
                if (! empty($ws['contexts'])) {
                    $contexts = array_values(array_unique(array_merge($contexts, $ws['contexts'])));
                }
                if (! empty($ws['groups'])) {
                    continue; // prefer component parse over generic <li> for this file
                }
            }

            // Split into <li> elements with optional @if wrap
            $liPattern = chr(47) . '(?:@if\s*\((.*?)\)\s*)?<li>\s*<a\s+[^>]*href=["\x27]{{\s*route\(\s*["\x27]([^"\x27]+)["\x27](?:\s*,\s*[^)]+)?\s*\)\s*}}["\x27][^>]*>(.*?)<\/a>\s*<\/li>(?:\s*@endif)?' . chr(47) . 's';
            if (preg_match_all($liPattern, $rawContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $cond = trim($m[1] ?? '');
                    $route = trim($m[2]);
                    $innerHtml = $m[3];

                    // Skip non-nav routes
                    if (in_array($route, ['logout'], true)) continue;

                    // Extract roles from condition: in_array($role, ['Assistant Registrar', ...])
                    $roles = [];
                    if ($cond) {
                        if (preg_match('/in_array\s*\(\s*\$role\s*,\s*\[(.*?)\]\s*\)/', $cond, $rm)) {
                            preg_match_all('/["\x27]([^"\x27]+)["\x27]/', $rm[1], $roleMatches);
                            $roles = $roleMatches[1] ?? [];
                        } elseif (preg_match('/\$role\s*===?\s*["\x27]([^"\x27]+)["\x27]/', $cond, $rm)) {
                            $roles = [$rm[1]];
                        }
                    }

                    // Extract label from <span ...>Label</span>
                    $label = '';
                    if (preg_match('/<span[^>]*>(.*?)<\/span>/s', $innerHtml, $sm)) {
                        $label = trim(strip_tags($sm[1]));
                    }
                    if (empty($label)) {
                        if (preg_match('/alt=["\x27]([^"\x27]+)["\x27]/', $innerHtml, $am)) {
                            $label = trim($am[1]);
                        }
                    }
                    if (empty($label)) {
                        $label = ucfirst(str_replace(['.', '_', '-'], ' ', $route));
                    }

                    // Extract icon from image or Lucide tag
                    $icon = 'circle';
                    if (preg_match('/(?:assets\/|\/)([a-zA-Z0-9_\-&]+)\.(?:svg|png|webp|jpg)/i', $innerHtml, $im)) {
                        $rawIcon = strtolower($im[1]);
                        foreach ($iconMap as $key => $targetIcon) {
                            if (str_contains($rawIcon, $key)) {
                                $icon = $targetIcon;
                                break;
                            }
                        }
                    } elseif (preg_match('/<x-lucide-([a-z0-9-]+)/i', $innerHtml, $lm)) {
                        $icon = strtolower($lm[1]);
                    }

                    $navEntry = [
                        'label' => $label,
                        'route' => $route,
                        'icon' => $icon,
                        'context' => $context,
                        'roles' => $roles,
                        'order' => count($items) * 10,
                        'type' => 'route',
                        'children' => [],
                    ];

                    $items[] = $navEntry;
                    $contexts[$context][] = $navEntry;
                }
            } else {
                // Fallback for simple blade routes
                if (preg_match_all('/route\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $rawContent, $m)) {
                    foreach (array_unique($m[1]) as $route) {
                        if (str_contains($route, 'create') || str_contains($route, 'edit') || str_contains($route, 'destroy') || in_array($route, ['logout'], true)) {
                            continue;
                        }
                        $navEntry = [
                            'label' => ucfirst(str_replace(['.', '_', '-'], ' ', $route)),
                            'route' => $route,
                            'order' => count($items) * 10,
                            'icon' => 'circle',
                            'type' => 'route',
                            'context' => $context,
                            'roles' => [],
                            'children' => [],
                        ];
                        $items[] = $navEntry;
                        $contexts[$context][] = $navEntry;
                    }
                }
            }
        }

        return ['items' => $items, 'contexts' => $contexts];
    }

    /**
     * Analyze available data sources for porting.
     * Gives developers the choice: seeders (may be outdated) vs real data (always current).
     */
    public function analyzeDataSources(string $sourcePath, array $sliceMapping): array
    {
        $sources = [
            'seeders' => [],
            'real_data' => [
                'available' => false,
                'note' => 'Configure --source-* database options to enable real data porting.',
            ],
            'recommendation' => '',
        ];

        // Check for seeders
        $seederDir = $sourcePath.'/database/seeders';
        if (is_dir($seederDir)) {
            $dataService = app(SliceDataPortService::class);
            $seederAnalysis = $dataService->analyzeSeeders($sourcePath, $sliceMapping);

            foreach ($seederAnalysis as $className => $info) {
                $sources['seeders'][] = [
                    'class' => $className,
                    'file' => $info['file'],
                    'tables' => $info['tables'],
                    'slices' => $info['slices'],
                    'is_reference_data' => $info['is_reference_data'],
                    'recommendation' => $info['is_reference_data']
                        ? 'Safe to auto-import (reference data)'
                        : 'Review before importing (may contain test data)',
                ];
            }
        }

        // Check if source DB is configured
        $sourceConn = config('laraslice.porting.source_connection');
        if ($sourceConn) {
            try {
                $conn = \Illuminate\Support\Facades\DB::connection($sourceConn);
                $conn->getPdo();
                $sources['real_data'] = [
                    'available' => true,
                    'connection' => $sourceConn,
                    'note' => 'Live database is the source of truth. Always up-to-date.',
                ];
            } catch (Throwable $e) {
                $sources['real_data'] = [
                    'available' => false,
                    'note' => 'Source connection configured but unreachable: '.$e->getMessage(),
                ];
            }
        }

        // Recommendation
        $hasSeeders = ! empty($sources['seeders']);
        $hasRealData = $sources['real_data']['available'] ?? false;

        if ($hasRealData && $hasSeeders) {
            $sources['recommendation'] = 'Both available. Use real data for transactional tables (always current). Use seeders for reference data (currencies, roles, etc.) if real data is empty.';
        } elseif ($hasRealData) {
            $sources['recommendation'] = 'Real data available. Use it — always up-to-date.';
        } elseif ($hasSeeders) {
            $sources['recommendation'] = 'Only seeders available. Review seeder data for freshness before importing.';
        } else {
            $sources['recommendation'] = 'No data sources detected. Configure --source-* or add seeders.';
        }

        return $sources;
    }

    public function generateArchitecturePlan(array $inspection, string $domain = 'Core'): array
    {
        // NOTE: The static ERP example plan is intentionally NOT auto-loaded here.
        // The web controller serves example plans explicitly per preset; this method
        // always generates from the live inspection so plans never masquerade as analysis.

        // 2. Dynamic generation for arbitrary legacy projects
        $sliceMapping = $inspection['slice_mapping'] ?? [];
        $tables = $inspection['tables'] ?? [];
        $totalTables = $inspection['total_tables'] ?? count($tables);
        $tenancy = $inspection['tenancy'] ?? ['enabled' => false, 'tenant_key' => null];
        $rowCounts = $this->queryDatabaseRowCounts();

        $modulesMap = [];
        $sidebarGroups = [
            [
                'name' => 'Overview',
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'icon' => 'layout-dashboard',
                        'route' => '/' . Str::slug($domain) . '/dashboard',
                        'badge' => null,
                        'description' => 'System executive KPIs, workload summaries, and analytics',
                    ],
                ],
            ],
        ];

        foreach ($sliceMapping as $sliceName => $info) {
            $primaryTable = $info['primary_table'];
            $moduleName = $this->detectModuleForSlice($sliceName, $primaryTable);
            $childTables = $info['child_tables'] ?? [];
            $sliceHandle = Str::snake($sliceName);

            $childDefinitions = [];
            foreach ($childTables as $cTable) {
                $childDefinitions[] = [
                    'table' => $cTable,
                    'records' => $rowCounts[$cTable] ?? 0,
                    'model' => Str::studly(Str::singular($cTable)),
                    'relation' => 'hasMany',
                ];
            }

            $models = [Str::studly(Str::singular($primaryTable))];
            foreach ($childTables as $cTable) {
                $models[] = Str::studly(Str::singular($cTable));
            }

            $sliceObj = [
                'name' => $sliceName,
                'handle' => $sliceHandle,
                'root_table' => $primaryTable,
                'records_count' => $rowCounts[$primaryTable] ?? 0,
                'child_tables' => $childDefinitions,
                'models' => array_unique($models),
                'permissions' => [
                    "{$sliceHandle}.view",
                    "{$sliceHandle}.create",
                    "{$sliceHandle}.edit",
                    "{$sliceHandle}.delete",
                ],
                'pages' => $this->generateDefaultPagesSpecification($sliceName, $sliceHandle, $primaryTable, $rowCounts[$primaryTable] ?? 0, $childDefinitions),
            ];

            $modulesMap[$moduleName][] = $sliceObj;
        }

        $formattedModules = [];
        foreach ($modulesMap as $mName => $slices) {
            $mSlug = Str::slug($mName);
            $icon = match(strtolower($mName)) {
                'work', 'projectexecution' => 'briefcase',
                'salescrm', 'crm' => 'target',
                'financialaccounting', 'finance' => 'landmark',
                'humancapital', 'hr' => 'user-check',
                'supplychain' => 'shopping-cart',
                'servicedesk' => 'life-buoy',
                'operations' => 'message-square',
                'settings', 'administration' => 'settings',
                'referencedata', 'masterdata' => 'database',
                'casemanagement' => 'inbox',
                'judicialhearings' => 'gavel',
                'legalregistry' => 'file-signature',
                default => 'layers',
            };

            $formattedModules[] = [
                'name' => $mName,
                'slug' => $mSlug,
                'icon' => $icon,
                'description' => "Domain bounded context for {$mName}",
                'slices' => $slices,
            ];

            // Add to sidebar groups
            $sidebarItems = [];
            foreach ($slices as $s) {
                $badge = $s['records_count'] > 0 ? $s['records_count'] : null;
                $sidebarItems[] = [
                    'slice' => $s['name'],
                    'label' => Str::headline($s['name']),
                    'icon' => match(strtolower($s['name'])) {
                        'projects' => 'briefcase',
                        'tasks' => 'check-square',
                        'invoices', 'estimates' => 'file-text',
                        'payments' => 'credit-card',
                        'employees' => 'user-check',
                        'attendances' => 'calendar-check',
                        'leaves' => 'calendar-off',
                        'clients' => 'users',
                        'leads' => 'target',
                        'tickets' => 'life-buoy',
                        'settings' => 'settings',
                        'complaints' => 'inbox',
                        'hearings' => 'calendar-check',
                        'notices' => 'bell',
                        'representations' => 'shield-alert',
                        'reportsoughts' => 'file-search',
                        'faqs' => 'help-circle',
                        'masterdata', 'referencedata' => 'database',
                        default => 'circle',
                    },
                    'route' => '/' . Str::slug($domain) . '/' . $mSlug . '/' . $s['handle'],
                    'badge' => $badge,
                    'tabs' => array_merge(['Overview'], array_map(function($c) {
                        return Str::headline($c['table']);
                    }, array_slice($s['child_tables'], 0, 5))),
                ];
            }

            $sidebarGroups[] = [
                'name' => Str::headline($mName),
                'icon' => $icon,
                'collapsible' => true,
                'default_open' => in_array(strtolower($mName), ['work', 'salescrm', 'finance', 'casemanagement', 'judicialhearings'], true),
                'items' => $sidebarItems,
            ];
        }

        $topMigrations = [];
        arsort($rowCounts);
        foreach (array_slice($rowCounts, 0, 40) as $tbl => $cnt) {
            $topMigrations[] = ['table' => $tbl, 'rows' => $cnt];
        }

        return [
            '$schema' => 'https://laraslice.dev/schemas/porting-architecture-plan.v1.json',
            'version' => '1.0.0',
            'generated_at' => date('Y-m-d\TH:i:s\Z'),
            'domain' => $domain,
            'source_application' => $inspection['source_path'] ?? base_path(),
            'target_framework' => 'LaraSlice VSA (Laravel 12 + Blade + Alpine.js + Tailwind CSS v4 + BlatUI)',
            'summary' => [
                'total_legacy_tables' => $totalTables,
                'total_canonical_slices' => count($sliceMapping),
                'total_modules' => count($formattedModules),
                'total_records_detected' => array_sum($rowCounts),
                'multi_tenancy' => $tenancy,
                'eliminated_sidebar_clutter' => 'Consolidated auxiliary configuration tables into a single Unified Settings Hub.',
            ],
            'sidebar_preview' => $this->buildSidebarPreviewForPlan($domain, $sidebarGroups, $inspection['source_path'] ?? ''),
            'modules' => $formattedModules,
            'studio_slices' => $inspection['studio_slices'] ?? [],
            'domain_blueprint_yaml' => $inspection['domain_blueprint_yaml'] ?? $this->generateDomainBlueprintYaml($inspection['studio_slices'] ?? [], $domain, $inspection),
            'journeys' => $this->analyzeUserJourneys($inspection['source_path'] ?? '', $domain),
            'data_sources' => $this->analyzeDataSources($inspection['source_path'] ?? '', $sliceMapping),
            'etl_data_pipeline' => [
                'source_connection' => 'mysql (legacy database)',
                'target_connection' => 'mysql (current database)',
                'execution_strategy' => 'Foreign key checks disabled during streaming; records imported in dependency-aware batches with auto-id preservation.',
                'top_tables_to_migrate' => $topMigrations,
            ],
            'page_architecture_guide' => $this->getPageArchitectureGuide(),
        ];
    }

    /**
     * Execute full vertical slice generation and migration directly from a structured Architecture Plan.
     */
    public function executeFromPlan(array $plan, bool $importData = false, bool $withFiles = false, bool $importAuth = false): array
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $domain = $plan['domain'] ?? 'Core';
        $modules = $plan['modules'] ?? [];
        $multitenant = $plan['summary']['multi_tenancy']['enabled'] ?? false;
        $tenantKey = $plan['summary']['multi_tenancy']['tenant_key'] ?? 'company_id';

        $generator = new \LaraSlice\Generator\SliceGenerator;
        $createdSlices = [];
        $scaffoldedChildren = [];
        $errors = [];

        foreach ($modules as $module) {
            $moduleName = $module['name'] ?? 'Core';
            $slices = $module['slices'] ?? [];

            foreach ($slices as $slice) {
                $sliceName = $slice['name'];
                $rootTable = $slice['root_table'];
                $childTables = $slice['child_tables'] ?? [];

                try {
                    $fields = $this->getFieldsForTable($rootTable, $tenantKey);

                    $uiConfig = $this->buildCanonicalUiFromSlice($slice, $sliceName, $rootTable);
                    $wf = $slice['workflow'] ?? null;
                    if (empty($wf) || empty($wf['states'])) {
                        $statusCol = null;
                        foreach ($fields as $f) {
                            $h = is_array($f) ? ($f['handle'] ?? '') : ($f->handle ?? '');
                            if ($h === 'status' || str_ends_with($h, '_status') || str_ends_with($h, '_status_id')) {
                                $statusCol = $h;
                                break;
                            }
                        }
                        if ($statusCol) {
                            $wf = [
                                'enabled' => true,
                                'state_column' => $statusCol,
                                'states' => [
                                    ['slug' => 'draft', 'label' => 'Draft', 'color' => 'slate'],
                                    ['slug' => 'pending', 'label' => 'Pending', 'color' => 'amber'],
                                    ['slug' => 'in_progress', 'label' => 'In Progress', 'color' => 'blue'],
                                    ['slug' => 'completed', 'label' => 'Completed', 'color' => 'emerald'],
                                    ['slug' => 'cancelled', 'label' => 'Cancelled', 'color' => 'rose'],
                                ],
                                'transitions' => [
                                    ['slug' => 'submit', 'name' => 'Submit', 'from' => ['draft'], 'to' => 'pending', 'target_role' => 'manager'],
                                    ['slug' => 'start', 'name' => 'Start', 'from' => ['pending'], 'to' => 'in_progress', 'target_role' => 'manager'],
                                    ['slug' => 'complete', 'name' => 'Complete', 'from' => ['in_progress'], 'to' => 'completed', 'target_role' => 'admin'],
                                    ['slug' => 'cancel', 'name' => 'Cancel', 'from' => ['draft', 'pending', 'in_progress'], 'to' => 'cancelled', 'target_role' => 'admin', 'requires_remarks' => true],
                                ],
                            ];
                        }
                    }
                    $options = [
                        'app' => $domain,
                        'domain' => $moduleName,
                        'table' => $rootTable,
                        'multitenant' => $multitenant,
                        'tenant_key' => $tenantKey,
                        'description' => $slice['description'] ?? "Vertical slice for {$sliceName}",
                        'force' => true,
                        'ui' => $uiConfig,
                        'workflowConfig' => $wf,
                    ];

                    $sliceDir = $generator->generate($sliceName, $fields, ! empty($wf) || ! empty($options['ui']), $options);

                    // Materialize workflow into DB engine so /admin/workflows lists ported machines
                    if (! empty($wf) && is_array($wf)) {
                        try {
                            $engine = app(\LaraSlice\Slices\Workflows\Services\WorkflowEngineService::class);
                            $studly = \LaraSlice\Generator\SliceName::canonical($sliceName);
                            $plural = Str::plural($studly);
                            $appFolder = \LaraSlice\Generator\SliceName::domainSegment($domain);
                            $moduleFolder = \LaraSlice\Generator\SliceName::domainSegment($moduleName);
                            $modelClass = "App\\Slices\\{$appFolder}\\{$moduleFolder}\\{$plural}\\Models\\{$studly}";
                            $engine->syncWorkflowFromManifest($wf, $sliceName, class_exists($modelClass) ? $modelClass : null);
                        } catch (\Throwable $syncEx) {
                            $errors[$sliceName.'_workflow'] = $syncEx->getMessage();
                        }
                    }
                    $studlyName = \LaraSlice\Generator\SliceName::canonical($sliceName);
                    $pluralName = Str::plural($studlyName);
                    $appFolder = \LaraSlice\Generator\SliceName::domainSegment($domain);
                    $moduleFolder = \LaraSlice\Generator\SliceName::domainSegment($moduleName);
                    $sliceNamespace = "App\\Slices\\{$appFolder}\\{$moduleFolder}\\{$pluralName}";

                    foreach ($childTables as $childDef) {
                        $childTable = is_array($childDef) ? ($childDef['table'] ?? '') : (string) $childDef;
                        if (! empty($childTable)) {
                            $childCols = $this->getColumnsForTable($childTable);
                            $this->scaffoldChildTable($sliceDir, $sliceNamespace, $sliceName, $rootTable, $childTable, $childCols, $multitenant, $tenantKey);
                            $scaffoldedChildren[] = $childTable;
                        }
                    }

                    $createdSlices[$sliceName] = [
                        'slice' => $sliceName,
                        'module' => $moduleName,
                        'app' => $domain,
                        'root_table' => $rootTable,
                        'child_tables' => $childTables,
                        'path' => $sliceDir,
                    ];
                } catch (\Throwable $e) {
                    $errors[$sliceName] = $e->getMessage();
                }
            }
        }

        // Run migrations
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            $errors['migrations'] = $e->getMessage();
        }

        // Run ETL data import if requested
        $dataImportResult = null;
        if ($importData) {
            try {
                $sourceDb = $plan['source_db'] ?? 'erp';
                $dataPortService = new SliceDataPortService;
                $dataPortService->setImportAuth($importAuth);
                $dataImportResult = $dataPortService->importFromDatabase($sourceDb);
            } catch (\Throwable $e) {
                $errors['data_import'] = $e->getMessage();
            }
        }

        return [
            'success' => empty($errors),
            'domain' => $domain,
            'slices_created' => $createdSlices,
            'child_tables_created' => $scaffoldedChildren,
            'errors' => $errors,
            'data_import' => $dataImportResult,
        ];
    }

    /**
     * Query live database row counts for accurate preview badges.
     *
     * Uses the configured porting source connection when available, otherwise the
     * application's default database connection. Never falls back to a different
     * database silently — counts always reflect the connection actually queried.
     */
    public function queryDatabaseRowCounts(?string $connection = null): array
    {
        $counts = [];
        try {
            $conn = $connection
                ?? config('laraslice.porting.source_connection')
                ?? config('database.default');
            $pdo = \Illuminate\Support\Facades\DB::connection($conn)->getPdo();

            if ($pdo) {
                $stmt = $pdo->query('SHOW TABLES');
                $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($tables as $t) {
                    try {
                        $c = (int) $pdo->query("SELECT count(1) FROM `{$t}`")->fetchColumn();
                        if ($c > 0) {
                            $counts[$t] = $c;
                        }
                    } catch (Throwable) {}
                }
            }
        } catch (Throwable) {}

        return $counts;
    }

    /**
     * Introspect or build default generator fields for a table.
     */
    public function getFieldsForTable(string $table, string $tenantKey = 'company_id'): array
    {
        $cols = $this->getColumnsForTable($table);
        $fields = [];
        $seen = [];

        foreach ($cols as $c) {
            $cName = $c['name'] ?? '';
            if (empty($cName) || $cName === $tenantKey || in_array($cName, ['id', 'created_at', 'updated_at', 'deleted_at'], true) || isset($seen[$cName])) {
                continue;
            }
            $seen[$cName] = true;

            $type = match($c['type'] ?? 'string') {
                'integer', 'bigint', 'int' => 'integer',
                'boolean', 'tinyint' => 'boolean',
                'date', 'datetime', 'timestamp' => 'timestamp',
                'decimal', 'float', 'double' => 'decimal',
                'text', 'longtext' => 'text',
                default => 'string',
            };

            $fields[] = [
                'name' => $cName,
                'label' => Str::headline($cName),
                'type' => $type,
                'nullable' => true,
                'rules' => ['nullable'],
            ];
        }

        if (empty($fields)) {
            $fields[] = [
                'name' => 'name',
                'label' => 'Name',
                'type' => 'string',
                'nullable' => true,
                'rules' => ['nullable'],
            ];
        }

        return $fields;
    }

    /**
     * Get column definitions from the configured source database.
     */
    public function getColumnsForTable(string $table, ?string $connection = null): array
    {
        $cols = [];
        try {
            $conn = $connection
                ?? config('laraslice.porting.source_connection')
                ?? config('database.default');
            $pdo = \Illuminate\Support\Facades\DB::connection($conn)->getPdo();
            $driver = \Illuminate\Support\Facades\DB::connection($conn)->getDriverName();

            if ($driver === 'mysql') {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                $rawCols = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rawCols as $rc) {
                    $cols[] = [
                        'name' => $rc['Field'],
                        'type' => $rc['Type'],
                    ];
                }
                return $cols;
            }

            // Portable fallback via the schema builder for non-MySQL drivers.
            foreach (\Illuminate\Support\Facades\Schema::connection($conn)->getColumns($table) as $sc) {
                $cols[] = [
                    'name' => $sc['name'],
                    'type' => $sc['type_name'] ?? $sc['type'] ?? 'string',
                ];
            }
            return $cols;
        } catch (Throwable) {}

        return $cols;
    }

    /**
     * Standard 4-Step UI Porting Methodology Guide for AI and Developers.
     */
    public function getPageArchitectureGuide(): array
    {
        return [
            'title' => 'Standard LaraSlice VSA 4-Step UI Porting Methodology',
            'summary' => 'A structured blueprint for transforming raw relational database tables into clean, responsive, enterprise-grade BlatUI interfaces (Blade + Alpine.js + Tailwind CSS v4 + Lucide icons) with zero Metronic or Livewire dependencies.',
            'steps' => [
                [
                    'step' => 1,
                    'name' => 'Listing Page (Index)',
                    'pattern' => 'Filter Bar + KPI Ribbon + BlatUI Table + Pagination & Quick Actions',
                    'blatui_components' => ['stats-ribbon', 'filter-bar', 'data-table', 'badge', 'avatar-stack', 'dropdown-menu'],
                    'checklist' => [
                        'Extract active filter dimensions from legacy forms (status, date range, client_id, employee_id).',
                        'Calculate 4 top-level aggregate KPI cards (Total, In-Flight, Blocked/Overdue, Completed).',
                        'Render responsive BlatUI table with sortable columns, color-coded badges, and avatar stacks.',
                        'Provide bulk action toolbar (Export CSV, Batch Status Transition, Delete Selected).'
                    ]
                ],
                [
                    'step' => 2,
                    'name' => 'Detail Workspace (Show)',
                    'pattern' => 'Entity Header Ribbon + Progress Track + Alpine Sub-Tabs + Aggregate Panels',
                    'blatui_components' => ['page-header', 'tabs', 'tab-panel', 'embedded-table', 'activity-timeline', 'dropzone'],
                    'checklist' => [
                        'Consolidate legacy ajax/ sub-views into canonical horizontal/vertical tabs.',
                        'Manage reactive tab switching using pure Alpine.js (x-data="{ activeTab: \'overview\' }").',
                        'Render overview card with primary metadata, progress bar, and executive metrics.',
                        'Render child aggregate lists (Milestones, Tasks, Timelogs, Invoices) via embedded BlatUI tables.',
                        'Provide quick-add slide-overs or modals for child items without context switching.'
                    ]
                ],
                [
                    'step' => 3,
                    'name' => 'Reactive Form (Create / Edit)',
                    'pattern' => 'Structured Fieldset Cards + Searchable Selects + Drag-and-Drop Dropzone',
                    'blatui_components' => ['card', 'input', 'select-searchable', 'textarea', 'toggle', 'file-dropzone'],
                    'checklist' => [
                        'Group legacy input fields into logical fieldsets (General, Schedule, Financials, Permissions).',
                        'Replace legacy jQuery Select2 with native BlatUI searchable selects.',
                        'Implement Alpine-powered dynamic validation and server-side FormRequest validation rules.',
                        'Include drag-and-drop file upload dropzone for document attachments.'
                    ]
                ],
                [
                    'step' => 4,
                    'name' => 'Workflow & Transitions',
                    'pattern' => 'Status Pill + Transition Action Menu + Confirmation Modal + Audit Trail',
                    'blatui_components' => ['badge', 'modal', 'activity-timeline', 'button-group'],
                    'checklist' => [
                        'Map state column to discrete status badges and allowed transitions.',
                        'Guard state machine changes with Laravel authorization policies and roles.',
                        'Dispatch domain events on transition (e.g. ProjectFinished, InvoiceSent, TicketResolved).',
                        'Log every transition automatically in the slice activity audit table.'
                    ]
                ]
            ]
        ];
    }

    /**
     * Generate dynamic 4-step page specifications for an arbitrary slice.
     */
    public function generateDefaultPagesSpecification(string $name, string $handle, string $rootTable, int $recordsCount, array $childTables, ?array $workflow = null): array
    {
        $titleSingular = rtrim($name, 's');
        if (substr($name, -3) === 'ies') $titleSingular = substr($name, 0, -3) . 'y';

        $pages = [
            'index' => [
                'title' => "{$name} Directory",
                'description' => "High-density enterprise data table with real-time KPI ribbon and interactive query filters for {$name}.",
                'stats_ribbon' => [
                    ['label' => "Total {$name}", 'value' => $recordsCount, 'change' => 'Operational records', 'icon' => 'folder', 'color' => 'primary'],
                ],
                'filter_bar' => [
                    'search_placeholder' => "Search {$name} by code, title, or keyword...",
                    'filters' => [
                        ['name' => 'status', 'label' => 'All Statuses', 'type' => 'select', 'options' => $workflow['states'] ?? ['active', 'inactive', 'archived']],
                        ['name' => 'date_range', 'label' => 'Date Timeline', 'type' => 'daterange'],
                    ],
                ],
                'columns' => [
                    ['field' => 'id', 'label' => '#', 'sortable' => true, 'type' => 'number'],
                    ['field' => 'name', 'label' => "{$titleSingular} Name", 'sortable' => true, 'type' => 'title_with_subtitle'],
                    ['field' => 'created_at', 'label' => 'Created On', 'sortable' => true, 'type' => 'date'],
                    ['field' => 'status', 'label' => 'Status', 'sortable' => true, 'type' => 'badge'],
                ],
                'primary_actions' => [
                    ['label' => "New {$titleSingular}", 'icon' => 'plus', 'route' => "{$handle}.create", 'variant' => 'primary'],
                    ['label' => 'Export CSV', 'icon' => 'download', 'route' => "{$handle}.export", 'variant' => 'outline'],
                ],
            ],
            'show' => [
                'title' => "{$titleSingular} Workspace",
                'layout' => 'aggregate-detail-tabs',
                'header' => [
                    'title_field' => 'name',
                    'status_field' => 'status',
                    'actions' => [
                        ['label' => 'Edit', 'icon' => 'edit', 'route' => "{$handle}.edit"],
                        ['label' => 'Delete', 'icon' => 'trash', 'route' => "{$handle}.destroy", 'variant' => 'destructive'],
                    ],
                ],
                'sub_tabs' => [
                    [
                        'id' => 'overview',
                        'label' => 'Overview',
                        'icon' => 'layout-dashboard',
                        'component' => "{$handle}-overview-panel",
                        'description' => 'Primary specifications, metadata, status indicators, and operational metrics.',
                    ],
                ],
            ],
            'forms' => [
                'create' => [
                    'title' => "Create {$titleSingular}",
                    'layout' => '2-column-grid',
                    'sections' => [
                        ['name' => 'General Information', 'fields' => ['name', 'description']],
                        ['name' => 'Status & Settings', 'fields' => ['status']],
                    ],
                ],
                'edit' => [
                    'title' => "Edit {$titleSingular}",
                    'layout' => '2-column-grid',
                    'sections' => [
                        ['name' => 'General Information', 'fields' => ['name', 'description']],
                        ['name' => 'Status & Settings', 'fields' => ['status']],
                    ],
                ],
            ],
            'workflow' => $workflow ?: [
                'state_column' => 'status',
                'states' => [
                    ['id' => 'draft', 'label' => 'Draft', 'color' => 'slate', 'icon' => 'circle'],
                    ['id' => 'active', 'label' => 'Active', 'color' => 'emerald', 'icon' => 'check-circle'],
                    ['id' => 'archived', 'label' => 'Archived', 'color' => 'zinc', 'icon' => 'archive'],
                ],
                'transitions' => [
                    ['from' => 'draft', 'to' => 'active', 'trigger' => 'Activate'],
                    ['from' => 'active', 'to' => 'archived', 'trigger' => 'Archive'],
                ],
            ],
        ];

        foreach ($childTables as $ct) {
            $tableName = is_array($ct) ? ($ct['table'] ?? '') : (string) $ct;
            $recs = is_array($ct) ? ($ct['records'] ?? 0) : 0;
            if (! empty($tableName)) {
                $tabLabel = ucwords(str_replace(['_', $rootTable . '_'], [' ', ''], $tableName));
                $tabId = str_replace(['_', '-'], '', strtolower($tabLabel));
                $pages['show']['sub_tabs'][] = [
                    'id' => $tabId,
                    'label' => $tabLabel,
                    'badge' => $recs > 0 ? $recs : null,
                    'icon' => 'layers',
                    'child_table' => $tableName,
                    'component' => "{$handle}-{$tabId}-table",
                    'description' => "Child aggregate records from {$tableName} table ({$recs} records).",
                ];
            }
        }

        return $pages;
    }

    /**
     * Build canonical declarative UI schema for a ported slice.
     */
    public function buildCanonicalUiFromSlice(array $slice, string $sliceName, string $rootTable): array
    {
        $pages = $slice['pages'] ?? [];
        $cols = [];
        if (! empty($pages['index']['columns'])) {
            foreach ($pages['index']['columns'] as $c) {
                $fType = $c['type'] ?? 'text';
                $formatter = match($fType) {
                    'title_with_subtitle', 'avatar_stack', 'composite_user' => 'composite_user',
                    'badge', 'badge_outline', 'code_badge' => 'code_badge',
                    'priority_pill', 'status_pill', 'badge_pill' => 'status_pill',
                    'date_with_relative', 'date_time', 'date' => 'date_time',
                    'money_breakdown', 'currency' => 'money_breakdown',
                    'progress_bar' => 'progress_bar',
                    default => 'text',
                };
                $fieldKey = $c['field'] ?? $c['key'] ?? 'name';
                $cleanHandle = str_contains($fieldKey, '.') ? explode('.', $fieldKey)[0] : $fieldKey;
                $cols[] = [
                    'handle' => $cleanHandle,
                    'label' => $c['label'] ?? Str::headline($cleanHandle),
                    'formatter' => $formatter,
                    'sortable' => (bool) ($c['sortable'] ?? true),
                ];
            }
        }

        $quickFilters = [];
        if (! empty($pages['index']['filter_bar']['filters'])) {
            foreach ($pages['index']['filter_bar']['filters'] as $flt) {
                $fName = $flt['name'] ?? $flt['handle'] ?? 'status';
                $quickFilters[] = [
                    'handle' => $fName,
                    'label' => $flt['label'] ?? Str::headline($fName),
                    'is_searchable' => ($flt['type'] ?? '') === 'select' || isset($flt['source']),
                ];
            }
        }

        $sections = [];
        if (! empty($pages['forms']['create']['sections'])) {
            foreach ($pages['forms']['create']['sections'] as $sIdx => $sec) {
                $sFields = [];
                foreach ($sec['fields'] ?? [] as $f) {
                    $fHandle = is_array($f) ? ($f['handle'] ?? $f['name'] ?? '') : (string) $f;
                    $isSearchable = is_array($f) ? (!empty($f['is_searchable']) || !empty($f['searchable'])) : (str_contains($fHandle, '_id') || in_array($fHandle, ['user_id', 'client_id', 'project_id', 'department_id', 'category_id']));
                    $sFields[] = [
                        'handle' => $fHandle,
                        'span' => 1,
                        'is_searchable' => $isSearchable,
                    ];
                }
                $sections[] = [
                    'key' => Str::slug($sec['name'] ?? "section_{$sIdx}"),
                    'title' => $sec['name'] ?? "Section " . ($sIdx + 1),
                    'grid' => 2,
                    'fields' => $sFields,
                ];
            }
        }

        // Add repeater fields for hasMany relations (e.g., invoice line items)
        foreach ($relations as $rel) {
            if (($rel['type'] ?? '') === 'hasMany') {
                $relName = $rel['name'] ?? '';
                if ($relName) {
                    $sections[] = [
                        'key' => 'items_'.$relName,
                        'title' => \Illuminate\Support\Str::headline($relName),
                        'grid' => 1,
                        'fields' => [
                            [
                                'handle' => $relName,
                                'type' => 'repeater',
                                'label' => \Illuminate\Support\Str::headline($relName),
                                // Repeater sub-fields will be populated from child table columns
                                'repeater_fields' => [],
                                'add_button_text' => 'Add ' . \Illuminate\Support\Str::singular(\Illuminate\Support\Str::headline($relName)),
                            ],
                        ],
                    ];
                }
            }
        }

        return [
            'presentation' => [
                'create' => 'drawer',
                'edit' => 'drawer',
            ],
            'directory' => [
                'columns' => $cols,
                'quick_filters' => $quickFilters,
                'sidebar_filters' => [],
                'row_actions' => [
                    'display' => 'hybrid',
                    'items' => [
                        ['handle' => 'view', 'label' => 'View', 'icon' => 'eye', 'placement' => 'inline', 'type' => 'drawer'],
                        ['handle' => 'edit', 'label' => 'Edit', 'icon' => 'pencil', 'placement' => 'dropdown', 'type' => 'drawer'],
                        ['handle' => 'delete', 'label' => 'Delete', 'icon' => 'trash-2', 'placement' => 'dropdown', 'type' => 'delete', 'danger' => true],
                    ],
                ],
            ],
            'forms' => [
                'mode' => 'drawer',
                'drawer_width' => 'max-w-3xl',
                'sections' => $sections,
            ],
        ];
    }

    /**
     * Build the v1.6.2 canonical `ui:` block from REAL inspected fields.
     *
     * Unlike generateDefaultPagesSpecification() which uses hardcoded placeholder
     * columns, this infers directory columns, formatters, and form sections from
     * the actual database columns discovered during inspection.
     *
     * Formatter inference rules:
     * - amount/total/price/balance → money_breakdown
     * - status/state → status_pill
     * - *_at, date* → date_time
     * - code/number fields → code_badge
     * - email → text (with mailto in UI)
     * - *_id (FK) → text (resolves to related name in UI)
     */
    public function buildUiFromInspectedFields(string $sliceName, array $fields, string $tenantKey = 'company_id', array $relations = []): array
    {
        $columns = [];
        $formFields = [];
        $quickFilters = [];
        $sidebarFilters = [];
        $summaryCards = [];
        $searchableFields = [];

        foreach ($fields as $f) {
            $handle = $f['handle'] ?? $f['name'] ?? '';
            if (in_array($handle, ['id', $tenantKey, 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $label = $f['label'] ?? \Illuminate\Support\Str::headline($handle);
            $type = $f['type'] ?? 'string';
            $lower = strtolower($handle);

            // Infer formatter from field name/type
            $formatter = 'text';
            $isMoney = str_contains($lower, 'amount') || str_contains($lower, 'total') || str_contains($lower, 'price') || str_contains($lower, 'balance') || $type === 'decimal';
            $isStatus = $lower === 'status' || str_contains($lower, 'status_') || $lower === 'state';
            $isDate = str_ends_with($lower, '_at') || str_starts_with($lower, 'date') || in_array($type, ['datetime', 'date', 'timestamp'], true);

            if ($isMoney) {
                $formatter = 'money_breakdown';
            } elseif ($isStatus) {
                $formatter = 'status_pill';
            } elseif ($isDate) {
                $formatter = 'date_time';
            } elseif ($lower === 'code' || str_ends_with($lower, '_code') || $lower === 'invoice_number' || $lower === 'order_number') {
                $formatter = 'code_badge';
            } elseif (str_contains($lower, 'email')) {
                $formatter = 'text'; // UI renders mailto link
            } elseif (str_contains($lower, 'progress') || str_contains($lower, 'percent')) {
                $formatter = 'progress_bar';
            }

            // Directory columns: first 6 meaningful fields
            if (count($columns) < 6) {
                $columns[] = [
                    'handle' => $handle,
                    'label' => $label,
                    'formatter' => $formatter,
                    'sortable' => true,
                ];
            }

            // Quick filters: status/enum fields become tab-style quick filters
            if ($isStatus && count($quickFilters) < 4) {
                $quickFilters[] = [
                    'handle' => $handle,
                    'label' => $label,
                    'is_searchable' => false,
                    'options_from' => 'distinct:'.$handle,
                ];
            }

            // Sidebar filters: date ranges and FK lookups
            if ($isDate && count($sidebarFilters) < 2) {
                $sidebarFilters[] = [
                    'handle' => $handle,
                    'label' => $label,
                    'type' => 'date_range',
                ];
            } elseif ((str_ends_with($handle, '_id') || str_ends_with($handle, '_by')) && count($sidebarFilters) < 4) {
                $sidebarFilters[] = [
                    'handle' => $handle,
                    'label' => $label,
                    'type' => 'select',
                    'options_from' => 'relation:'.$handle,
                ];
            }

            // Summary cards: money fields become KPI cards (like Akaunting's Receivables/Payables)
            if ($isMoney && count($summaryCards) < 3) {
                // Avoid "Total Total Amount" when label already contains Total/Amount
                $cardLabel = preg_match('/\b(total|amount|balance)\b/i', $label) ? $label : 'Total '.$label;
                $summaryCards[] = [
                    'handle' => $handle,
                    'label' => $cardLabel,
                    'aggregate' => 'sum',
                    'formatter' => 'money',
                ];
            }

            // Searchable: text-like fields
            if (in_array($type, ['string', 'text'], true) && ! $isStatus && count($searchableFields) < 5) {
                // Skip long text blobs, IDs, and codes already shown as badges
                if (! str_contains($lower, 'password') && ! str_contains($lower, 'token') && strlen($handle) < 30) {
                    $searchableFields[] = $handle;
                }
            }

            // Form fields: all fields with smart searchable flags
            $isSearchable = str_ends_with($handle, '_id') || in_array($handle, ['category_id', 'customer_id', 'user_id', 'company_id', 'contact_id'], true);
            $formFields[] = [
                'handle' => $handle,
                'span' => in_array($type, ['text'], true) ? 2 : 1,
                'is_searchable' => $isSearchable,
            ];
        }

        // Add a count summary card if we have no money fields (every list benefits from a total)
        if (empty($summaryCards)) {
            $summaryCards[] = [
                'handle' => 'id',
                'label' => 'Total Records',
                'aggregate' => 'count',
                'formatter' => 'number',
            ];
        }

        // Group form fields into logical sections
        $sections = [];
        $currentSection = ['key' => 'general', 'title' => 'General Information', 'grid' => 2, 'fields' => []];
        foreach ($formFields as $ff) {
            $currentSection['fields'][] = $ff;
            // Start new section every 6 fields for readability
            if (count($currentSection['fields']) >= 6) {
                $sections[] = $currentSection;
                $currentSection = ['key' => 'additional_'.count($sections), 'title' => 'Additional Details', 'grid' => 2, 'fields' => []];
            }
        }
        if (! empty($currentSection['fields'])) {
            $sections[] = $currentSection;
        }

        // Add repeater fields for hasMany relations (e.g., invoice line items)
        foreach ($relations as $rel) {
            if (($rel['type'] ?? '') === 'hasMany') {
                $relName = $rel['name'] ?? '';
                if ($relName) {
                    $sections[] = [
                        'key' => 'items_'.$relName,
                        'title' => \Illuminate\Support\Str::headline($relName),
                        'grid' => 1,
                        'fields' => [
                            [
                                'handle' => $relName,
                                'type' => 'repeater',
                                'label' => \Illuminate\Support\Str::headline($relName),
                                // Repeater sub-fields will be populated from child table columns
                                'repeater_fields' => [],
                                'add_button_text' => 'Add ' . \Illuminate\Support\Str::singular(\Illuminate\Support\Str::headline($relName)),
                            ],
                        ],
                    ];
                }
            }
        }

        return [
            'presentation' => [
                'create' => 'drawer',
                'edit' => 'drawer',
            ],
            'directory' => [
                'columns' => $columns,
                'searchable' => $searchableFields,
                'search_placeholder' => 'Search '.strtolower($sliceName).'...',
                'quick_filters' => $quickFilters,
                'sidebar_filters' => $sidebarFilters,
                'summary_cards' => $summaryCards,
                'bulk_actions' => [
                    ['handle' => 'export', 'label' => 'Export Selected', 'type' => 'native', 'icon' => 'download'],
                    ['handle' => 'delete', 'label' => 'Delete Selected', 'type' => 'native', 'icon' => 'trash-2', 'danger' => true],
                    ['handle' => 'submit', 'label' => 'Submit', 'type' => 'workflow', 'transition' => 'submit', 'icon' => 'send'],
                    ['handle' => 'approve', 'label' => 'Approve', 'type' => 'workflow', 'transition' => 'approve', 'icon' => 'check'],
                    ['handle' => 'reject', 'label' => 'Reject', 'type' => 'workflow', 'transition' => 'reject', 'icon' => 'x', 'requires_remarks' => true, 'danger' => true],
                ],
                'row_actions' => [
                    'display' => 'dropdown',
                    'items' => [
                        ['handle' => 'view', 'label' => 'View', 'icon' => 'eye', 'placement' => 'inline'],
                        ['handle' => 'edit', 'label' => 'Edit', 'icon' => 'pencil', 'placement' => 'dropdown'],
                        ['handle' => 'delete', 'label' => 'Delete', 'icon' => 'trash-2', 'placement' => 'dropdown', 'danger' => true],
                    ],
                ],
            ],
            'forms' => [
                'sections' => $sections,
            ],
        ];
    }

    /**
     * Detect dead tables — tables with no active Eloquent model in the source.
     *
     * Legacy systems often have v1 tables that are no longer used (e.g., Akaunting's
     * 2017 `invoices` tables vs the live `documents` table). Porting dead tables
     * as features misleads developers.
     *
     * @return array List of dead table names
     */
    public function detectDeadTables(array $tables, string $sourcePath): array
    {
        $dead = [];
        $modelsDir = $sourcePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models';

        if (! \Illuminate\Support\Facades\File::isDirectory($modelsDir)) {
            return [];
        }

        // Collect all model table names from source
        $activeTables = [];
        foreach (glob($modelsDir.DIRECTORY_SEPARATOR.'*.php') as $modelFile) {
            $content = @file_get_contents($modelFile);
            if ($content && preg_match("/protected\s+\\\$table\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
                $activeTables[] = $m[1];
            } else {
                // Infer from class name (e.g., Document.php → documents)
                $base = basename($modelFile, '.php');
                $activeTables[] = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::plural($base));
            }
        }

        // Also check Models subdirectories
        foreach (glob($modelsDir.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*.php') as $modelFile) {
            $content = @file_get_contents($modelFile);
            if ($content && preg_match("/protected\s+\\\$table\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
                $activeTables[] = $m[1];
            }
        }

        foreach (array_keys($tables) as $table) {
            if (! in_array($table, $activeTables, true)) {
                $dead[] = $table;
            }
        }

        return $dead;
    }

    /**
     * Detect business logic in source controllers — non-CRUD methods that
     * need manual implementation.
     *
     * @return array Map of slice name → list of porting notes
     */
    public function detectBusinessLogic(string $sourcePath, array $sliceMapping): array
    {
        $notes = [];
        $controllersDir = $sourcePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Controllers';

        if (! \Illuminate\Support\Facades\File::isDirectory($controllersDir)) {
            return [];
        }

        $crudMethods = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy', '__construct'];

        foreach ($sliceMapping as $sliceName => $info) {
            $sliceNotes = [];
            $primaryTable = $info['primary_table'] ?? '';

            // Find controller files that might relate to this slice
            $searchTerms = [strtolower($sliceName), str_replace('_', '', strtolower($primaryTable))];

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($controllersDir)) as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                $filename = strtolower($file->getFilename());
                $matched = false;
                foreach ($searchTerms as $term) {
                    if (str_contains($filename, $term)) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    continue;
                }

                $content = @file_get_contents($file->getPathname());
                if (! $content) {
                    continue;
                }

                // Find public methods that aren't CRUD
                preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $matches);
                foreach ($matches[1] as $method) {
                    if (! in_array($method, $crudMethods, true)) {
                        $sliceNotes[] = "Source controller ".basename($file->getPathname())." has custom method `{$method}()` — implement manually in {$sliceName}Service";
                    }
                }

                // Detect common business patterns
                if (str_contains($content, 'PDF') || str_contains($content, 'dompdf') || str_contains($content, '->pdf(')) {
                    $sliceNotes[] = "Source generates PDF documents — implement PDF generation manually";
                }
                if (preg_match('/Mail::|->mail\(|Mailable/', $content)) {
                    $sliceNotes[] = "Source sends emails — implement mail integration manually";
                }
                if (str_contains($content, 'Stripe') || str_contains($content, 'PayPal') || str_contains($content, 'gateway')) {
                    $sliceNotes[] = "Source integrates payment gateways — implement payment integration manually";
                }
            }

            if (! empty($sliceNotes)) {
                $notes[$sliceName] = array_unique($sliceNotes);
            }
        }

        return $notes;
    }

    /**
     * Analyze source UI templates for a table — extract form fields, columns, and structure.
     *
     * Looks for Blade/Vue templates related to the table and extracts:
     * - Form field names from x-form components
     * - Table columns from index views
     * - Section grouping from component structure
     *
     * @return array UI structure with fields, sections, and detected patterns
     */
    public function analyzeSourceUi(string $sourcePath, string $table): array
    {        $viewsDir = $sourcePath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
        if (! \Illuminate\Support\Facades\File::isDirectory($viewsDir)) {
            return ['fields' => [], 'sections' => [], 'has_modals' => false];
        }

        $singular = \Illuminate\Support\Str::singular($table);
        $fields = [];
        $sections = [];
        $hasModals = false;

        // Search for view files related to this table
        $patterns = [$table, $singular, str_replace('_', '-', $table)];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsDir)) as $file) {
            if ($file->isDir()) {
                continue;
            }
            // getExtension() returns 'php' for 'file.blade.php', so check the full filename
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $path = strtolower($file->getPathname());
            $matched = false;
            foreach ($patterns as $pattern) {
                // Match table name in path segments (e.g., /documents/, /document-, components/documents/)
                if (str_contains($path, '/'.$pattern.'/') || str_contains($path, '/'.$pattern.'-') || str_contains($path, '/'.$pattern.'.')) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                continue;
            }

            $content = @file_get_contents($file->getPathname());
            if (! $content) {
                continue;
            }

            // Extract form field names from x-form components
            preg_match_all('/x-form\.[a-z.]+\s+[^>]*name="([^"]+)"/', $content, $fieldMatches);
            foreach ($fieldMatches[1] as $fieldName) {
                if (! in_array($fieldName, $fields, true) && ! str_starts_with($fieldName, '_')) {
                    $fields[] = $fieldName;
                }
            }

            // Detect modals
            if (str_contains($path, 'modal') || str_contains($content, 'x-modal')) {
                $hasModals = true;
            }

            // Detect section structure from component names
            if (preg_match('/components\/'.preg_quote($singular, '/').'\/form\/(\w+)/', $path, $m)) {
                $section = $m[1];
                if (! in_array($section, $sections, true)) {
                    $sections[] = $section;
                }
            }
        }

        return [
            'fields' => array_slice($fields, 0, 30), // Limit to 30 fields
            'sections' => $sections,
            'has_modals' => $hasModals,
            'field_count' => count($fields),
        ];
    }

    /**
     * Detect workflow state machines in source controllers.
     *
     * Looks for `mark*` methods (e.g., markSent, markCancelled, markPaid) which
     * indicate state transitions. Also detects status column values from
     * language files or constants.
     *
     * @return array Map of slice name → workflow definition with states and transitions
     */
    public function detectWorkflows(string $sourcePath, array $sliceMapping): array
    {
        $workflows = [];
        $controllersDir = $sourcePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Controllers';

        if (! \Illuminate\Support\Facades\File::isDirectory($controllersDir)) {
            return [];
        }

        foreach ($sliceMapping as $sliceName => $info) {
            $primaryTable = $info['primary_table'] ?? '';
            $searchTerms = [strtolower($sliceName), str_replace('_', '', strtolower($primaryTable))];

            $transitions = [];
            $states = [];

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($controllersDir)) as $file) {
                if ($file->isDir() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $filename = strtolower($file->getFilename());
                $matched = false;
                foreach ($searchTerms as $term) {
                    if (str_contains($filename, $term)) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    continue;
                }

                $content = @file_get_contents($file->getPathname());
                if (! $content) {
                    continue;
                }

                // Find mark* methods (markSent, markCancelled, markPaid, etc.)
                preg_match_all('/public\s+function\s+(mark\w+)\s*\(/', $content, $matches);
                foreach ($matches[1] as $method) {
                    // Convert markSent → sent, markCancelled → cancelled
                    $targetState = strtolower(preg_replace('/^mark/', '', $method));
                    $transitions[] = [
                        'name' => $method,
                        'label' => \Illuminate\Support\Str::headline($method),
                        'to_state' => $targetState,
                    ];
                    if (! in_array($targetState, $states, true)) {
                        $states[] = $targetState;
                    }
                }

                // Also look for status transitions in events
                preg_match_all('/Document(\w+)\(/', $content, $eventMatches);
                foreach ($eventMatches[1] as $event) {
                    if (str_starts_with($event, 'Marked')) {
                        $state = strtolower(substr($event, 6));
                        if (! in_array($state, $states, true)) {
                            $states[] = $state;
                        }
                    }
                }
            }

            if (! empty($transitions)) {
                // Always include draft as the initial state
                if (! in_array('draft', $states, true)) {
                    array_unshift($states, 'draft');
                }
                $workflows[$sliceName] = [
                    'states' => $states,
                    'transitions' => $transitions,
                    'initial_state' => 'draft',
                ];
            }
        }

        return $workflows;
    }

    /**
     * Analyze user journeys in the source system.
     *
     * Identifies the key personas and their workflows:
     * - Onboarding: How new users get started (wizard, setup steps)
     * - End user: Daily workflows (create invoice → send → get paid)
     * - Admin: System management (users, settings, reports)
     * - Client/Portal: External user journeys (view invoice → pay)
     *
     * @return array Map of journey name → steps with slice mapping
     */
    public function analyzeUserJourneys(string $sourcePath, string $domain = 'Core'): array
    {
        $journeys = [];
        $controllerDir = $sourcePath.'/app/Http/Controllers';

        $availableControllers = [];
        if (is_dir($controllerDir)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($controllerDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $base = $file->getBasename('.php');
                    $availableControllers[strtolower($base)] = $base;
                }
            }
        }

        // Helper to find controller if exists
        $findCtrl = function (array $names) use ($availableControllers): ?string {
            foreach ($names as $n) {
                $lower = strtolower($n);
                if (isset($availableControllers[$lower])) {
                    return $availableControllers[$lower];
                }
            }
            return null;
        };

        // If process-core / complaint-adjudication domain signature, build operational process journeys
        $isProcessCoreExplicit = strtolower($domain) === 'adjudication' || strtolower($domain) === 'processcore' || strtolower($domain) === 'complaintmanagement' || strtolower($domain) === 'legal' || strtolower($domain) === 'dispute';
        $isProcessCoreSignature = $findCtrl(['ComplaintController']) && ($findCtrl(['CauseListController']) || $findCtrl(['NoticeController']));
        if (($isProcessCoreExplicit || $isProcessCoreSignature) && ! empty($availableControllers)) {
            return $this->buildProcessCoreJourneys($sourcePath, $availableControllers, $domain);
        }

        // If not ERP domain and does not contain all ERP multi-pillar signatures, dynamically discover generic journeys
        // ERP-class benchmark: operational journeys from real nav — not BRD essays
        $isErpExplicit = strtolower($domain) === 'erp';
        $isErpSignature = $findCtrl(['ProjectController', 'ProjectsController']) && $findCtrl(['EmployeeController', 'EmployeeDetailsController']) && $findCtrl(['InvoiceController', 'PaymentController']);
        $isErp = $isErpExplicit || ($domain === 'Core' && $isErpSignature);
        if (! $isErp && ! empty($availableControllers)) {
            return $this->discoverGenericUserJourneys($sourcePath, $availableControllers, $domain);
        }

        return $this->buildOperationalJourneysFromSource($sourcePath, $domain, $availableControllers);

    }

    /**
     * Slim, executable user journeys for studios (persona → steps → slice/action).
     * Driven by source navigation when present; ERP personas (typical multi-tenant suite):
     * admin / employee / client.
     *
     * @param  array<string, string>  $availableControllers
     * @return array<string, array<string, mixed>>
     */
    protected function buildOperationalJourneysFromSource(string $sourcePath, string $domain, array $availableControllers): array
    {
        $nav = $this->analyzeSourceNavigation($sourcePath);
        $groups = $nav['groups'] ?? [];

        $stepsFromNav = [];
        foreach ($groups as $g) {
            $children = $g['children'] ?? [];
            if ($children === [] && ! empty($g['route'])) {
                $children = [['label' => $g['label'] ?? $g['name'] ?? 'Item', 'route' => $g['route'], 'slice' => $g['slice'] ?? null]];
            }
            foreach ($children as $c) {
                $slice = $c['slice'] ?? null;
                if (! $slice && ! empty($c['route'])) {
                    $base = explode('.', (string) $c['route'])[0];
                    $slice = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular(str_replace(['-', '_'], ' ', $base)));
                }
                if (! $slice) {
                    continue;
                }
                $stepsFromNav[] = [
                    'slice' => $slice,
                    'action' => 'index',
                    'label' => $c['label'] ?? $slice,
                    'route' => $c['route'] ?? null,
                    'group' => $g['label'] ?? $g['name'] ?? null,
                ];
            }
        }

        // Fallback steps if nav empty — ERP pillars from controllers
        if ($stepsFromNav === []) {
            $fallback = [
                'ProjectController' => 'Projects',
                'TaskController' => 'Tasks',
                'InvoiceController' => 'Invoices',
                'ClientController' => 'Clients',
                'EmployeeController' => 'Employees',
                'LeadController' => 'Leads',
                'TicketController' => 'Tickets',
                'ContractController' => 'Contracts',
                'PaymentController' => 'Payments',
                'EstimateController' => 'Estimates',
            ];
            foreach ($fallback as $ctrl => $slice) {
                if (isset($availableControllers[strtolower($ctrl)])) {
                    $stepsFromNav[] = ['slice' => $slice, 'action' => 'index', 'label' => $slice];
                }
            }
        }

        $take = static function (array $steps, array $names) {
            $set = array_map('strtolower', $names);
            $out = [];
            foreach ($steps as $s) {
                if (in_array(strtolower($s['slice']), $set, true)) {
                    $out[] = $s;
                }
            }

            return $out ?: array_slice($steps, 0, 6);
        };

        $adminSteps = $stepsFromNav;
        $employeeSteps = $take($stepsFromNav, ['Tasks', 'Projects', 'Timelogs', 'Attendances', 'Attendance', 'Tickets', 'Leaves', 'Leave']);
        $clientSteps = $take($stepsFromNav, ['Invoices', 'Estimates', 'Contracts', 'Projects', 'Tickets', 'Payments', 'Clients']);

        return [
            'schema' => 'laraslice.journeys.v2',
            'domain' => $domain,
            'source' => $nav['source'] ?? 'derived',
            'admin' => [
                'persona' => 'admin',
                'label' => 'Admin',
                'entry' => 'dashboard',
                'description' => 'Full module access — matches typical ERP admin role',
                'steps' => array_slice($adminSteps, 0, 24),
            ],
            'employee' => [
                'persona' => 'employee',
                'label' => 'Employee',
                'entry' => 'dashboard',
                'description' => 'Work execution — tasks, time, tickets',
                'steps' => array_slice($employeeSteps, 0, 12),
            ],
            'client' => [
                'persona' => 'client',
                'label' => 'Client',
                'entry' => 'portal',
                'description' => 'Portal-scoped — invoices, contracts, projects',
                'steps' => array_slice($clientSteps, 0, 12),
            ],
        ];
    }


/**
     * Build process-core operational journeys for complaint/adjudication domains.
     * Persona steps derived from source nav and controllers — not BRD essays.
     */
    /**
     * Process-core / complaint-adjudication: slim operational journeys (not BRD essays).
     * Personas: admin (case officers), complainant (portal), mobile API surface noted as entry.
     *
     * @param  array<string, string>  $availableControllers
     * @return array<string, array<string, mixed>>
     */
    protected function buildProcessCoreJourneys(string $sourcePath, array $availableControllers, string $domain = 'Adjudication'): array
    {
        $nav = $this->analyzeSourceNavigation($sourcePath);
        $steps = [];
        foreach ($nav['groups'] ?? [] as $g) {
            foreach ($g['children'] ?? [] as $c) {
                $slice = $c['slice'] ?? null;
                if (! $slice && ! empty($c['route'])) {
                    $base = explode('.', (string) $c['route'])[0];
                    $slice = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular(str_replace(['-', '_'], ' ', $base)));
                }
                if ($slice) {
                    $steps[] = [
                        'slice' => $slice,
                        'action' => 'index',
                        'label' => $c['label'] ?? $slice,
                        'route' => $c['route'] ?? null,
                    ];
                }
            }
        }

        $ctrlMap = [
            'complaintcontroller' => ['slice' => 'Complaints', 'action' => 'index'],
            'causelistcontroller' => ['slice' => 'CauseLists', 'action' => 'index'],
            'noticecontroller' => ['slice' => 'Notices', 'action' => 'index'],
            'usercontroller' => ['slice' => 'Users', 'action' => 'index'],
            'dashboardcontroller' => ['slice' => 'Dashboard', 'action' => 'index'],
        ];
        if ($steps === []) {
            foreach ($availableControllers as $lower => $base) {
                if (isset($ctrlMap[$lower])) {
                    $steps[] = array_merge($ctrlMap[$lower], ['label' => $ctrlMap[$lower]['slice']]);
                }
            }
            if ($steps === []) {
                $steps[] = ['slice' => 'Complaints', 'action' => 'index', 'label' => 'Complaints'];
            }
        }

        $complaintSteps = array_values(array_filter($steps, fn ($s) => str_contains(strtolower($s['slice']), 'complaint') || str_contains(strtolower($s['slice']), 'cause') || str_contains(strtolower($s['slice']), 'hearing') || str_contains(strtolower($s['slice']), 'notice')));
        if ($complaintSteps === []) {
            $complaintSteps = [
                ['slice' => 'Complaints', 'action' => 'index', 'label' => 'Complaints'],
                ['slice' => 'Complaints', 'action' => 'show', 'label' => 'Case detail'],
                ['slice' => 'Complaints', 'action' => 'transition', 'label' => 'Status forward', 'transition' => null],
            ];
        }

        return [
            'schema' => 'laraslice.journeys.v2',
            'domain' => $domain,
            'source' => $nav['source'] ?? 'controllers',
            'admin' => [
                'persona' => 'admin',
                'label' => 'Admin / Case Officer',
                'entry' => 'admin',
                'description' => 'Case processing — complaints, cause list, notices',
                'steps' => array_slice($steps ?: $complaintSteps, 0, 20),
            ],
            'complainant' => [
                'persona' => 'complainant',
                'label' => 'Complainant',
                'entry' => 'portal',
                'description' => 'Citizen portal — submit and track complaints',
                'steps' => array_slice($complaintSteps, 0, 8),
            ],
            'mobile' => [
                'persona' => 'mobile',
                'label' => 'Mobile / API',
                'entry' => 'api',
                'description' => 'Mobile app and third-party API surfaces',
                'steps' => [
                    ['slice' => 'Complaints', 'action' => 'index', 'label' => 'My complaints'],
                    ['slice' => 'Complaints', 'action' => 'store', 'label' => 'File complaint'],
                ],
            ],
        ];
    }

    protected function discoverGenericUserJourneys(string $sourcePath, array $availableControllers, string $domain): array
    {
        // Same executable contract as ERP — nav-driven when possible, controller fallback
        return $this->buildOperationalJourneysFromSource($sourcePath, $domain, $availableControllers);
    }

}
