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

        $tables = $this->extractTablesFromMigrations($migDir);

        if (empty($tables)) {
            $tables = $this->extractTablesFromDatabase();
        }

        $tenancy = $this->detectMultiTenancy($tables);
        $classified = $this->classifyTables($tables);
        $sliceMapping = $this->buildSliceMapping($classified, $domain);

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
    public function execute(string $sourcePath, string $domain = 'Adjudication', string $mode = 'system-base', bool $withFiles = false): array
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
            'Notices' => 'ServiceDesk',
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
            'FinancialAccounting' => ['invoice', 'payment', 'expense', 'estimate', 'credit_note', 'tax', 'bank', 'currency', 'transaction', 'recurring', 'quotation', 'subscription'],
            'HumanCapital' => ['employee', 'attendance', 'leave', 'holiday', 'shift', 'designation', 'department', 'appreciation', 'award', 'emergency_contact', 'passport', 'visa'],
            'SalesCRM' => ['lead', 'deal', 'proposal', 'pipeline', 'client', 'contract', 'leadboard'],
            'Work' => ['project', 'task', 'timelog', 'time_log', 'milestone', 'sub_task', 'taskboard'],
            'SupplyChain' => ['product', 'order', 'cart', 'item', 'inventory', 'warehouse', 'vendor'],
            'ServiceDesk' => ['ticket', 'discussion', 'knowledge', 'faq', 'event', 'notice'],
            'Settings' => ['company', 'setting', 'theme', 'role', 'permission', 'custom_field', 'custom_link', 'log', 'backup', 'translate', 'language', 'social_auth', 'pusher', 'slack'],
            'ReferenceData' => ['countries', 'currencies', 'master_data', 'reference_data'],
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
    public function scaffoldSlices(array $inspection, string $app = 'ERP'): array
    {
        $generator = new \LaraSlice\Generator\SliceGenerator;
        $created = [];
        $errors = [];

        $sliceMapping = $inspection['slice_mapping'];
        $tables = $inspection['tables'];
        $multitenant = $inspection['tenancy']['enabled'] ?? false;
        $tenantKey = $inspection['tenancy']['tenant_key'] ?? 'company_id';

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
                    'domain' => $module,
                    'table' => $primaryTable,
                    'multitenant' => $multitenant,
                    'tenant_key' => $tenantKey,
                    'description' => $info['description'] ?? "Vertical slice for {$sliceName}",
                ];

                $sliceDir = $generator->generate($sliceName, $generatorFields, false, $options);
                $sliceNamespace = "App\\Slices\\{$app}\\{$module}\\{$sliceName}";

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
                if (in_array($t, ['migrations', 'failed_jobs', 'personal_access_tokens'], true)) {
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

        $taxonomyPattern = '/(types?|natures?|statuse?s?|modes?|regions?|genders?|provinces?|districts?|tehsils?|categories?|faqs?|priorities?|currencies?|departments?|designations?|grades?|units?|sources?|stages?|channels?)$/i';
        $systemPattern = '/^(users|user_profiles|roles|permissions|otps|sms_logs|activity_logs|api_logs|api_users|jobs|cache|notifications|personal_access_tokens|failed_jobs|sessions|password_resets|telescope_|pulse_)/i';

        // 1. Calculate in-degrees (foreign key references from other tables)
        $inDegrees = [];
        $knownTableSingulars = [];
        foreach ($tables as $tName => $tInfo) {
            $inDegrees[$tName] = 0;
            $knownTableSingulars[Str::singular($tName)] = $tName;
        }

        $ignoredInDegreeKeys = ['company', 'tenant', 'organization', 'account', 'user', 'created_by', 'updated_by', 'deleted_by', 'status', 'type', 'currency'];

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

        // 2. Classify each table
        foreach ($tables as $tName => $tInfo) {
            if (preg_match($systemPattern, $tName)) {
                $classified['system'][$tName] = $tInfo;
                continue;
            }

            // Pivot table detection
            if (str_contains($tName, '__') || (count($tInfo['foreign_keys']) >= 2 && count($tInfo['columns']) <= 5)) {
                $classified['pivots'][$tName] = $tInfo;
                continue;
            }

            // High incoming reference roots should never be taxonomies even if named categories/types
            $hasHighInbound = ($inDegrees[$tName] ?? 0) >= 3;

            // Taxonomy detection
            if (! $hasHighInbound && preg_match($taxonomyPattern, $tName)) {
                $classified['taxonomies'][$tName] = $tInfo;
                continue;
            }

            // Canonical enterprise business entities are NEVER demoted to child tables
            $canonicalRoots = [
                'projects', 'tasks', 'contracts', 'project_time_logs',
                'leads', 'deals', 'client_details',
                'invoices', 'payments', 'estimates', 'proposals', 'credit_notes', 'expenses', 'bank_accounts',
                'employee_details', 'attendances', 'leaves', 'employee_shift_schedules', 'teams', 'designations', 'appreciations',
                'products', 'orders',
                'tickets', 'events', 'notices', 'knowledge_bases',
                'users_chat', 'sticky_notes',
                'global_settings',
            ];

            // Determine if table is a child entity of an existing root
            $isChild = false;
            if (! in_array($tName, $canonicalRoots, true)) {
                foreach ($tInfo['foreign_keys'] as $fk) {
                    $base = preg_replace('/_id$/', '', $fk);
                    if (in_array($base, $ignoredInDegreeKeys, true)) {
                        continue;
                    }
                    if (isset($knownTableSingulars[$base]) && $knownTableSingulars[$base] !== $tName) {
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

        // 4. Sort slices by child count descending (most important core aggregate root first, MasterData last)
        uasort($mapping, function ($a, $b) {
            if (($a['taxonomy'] ?? false) && ! ($b['taxonomy'] ?? false)) return 1;
            if (! ($a['taxonomy'] ?? false) && ($b['taxonomy'] ?? false)) return -1;
            return count($b['child_tables'] ?? []) <=> count($a['child_tables'] ?? []);
        });

        return $mapping;
    }

    private function generateTaxonomySeeders(array $inspection, string $domain): array
    {
        $created = [];
        $seederDir = app_path("Slices/{$domain}/MasterData/Database/Seeders");
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
                        ['slug' => 'cancel', 'name' => 'Cancel', 'from' => ['draft', 'pending', 'in_progress'], 'to' => 'cancelled', 'target_role' => 'admin'],
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
    public function generateDomainBlueprintYaml(array $studioSlices, string $domain, array $inspection): string
    {
        $domainData = [
            'schema_version' => 1,
            'domain' => $domain,
            'source_application' => basename($inspection['source_path']),
            'total_tables' => $inspection['total_tables'],
            'total_slices' => count($studioSlices),
            'tenancy' => $inspection['tenancy'] ?? ['enabled' => false],
            'generated_at' => date('Y-m-d H:i:s'),
            'slices' => array_map(function($s) {
                return $s['blueprint'];
            }, $studioSlices),
        ];

        return Yaml::dump($domainData, 8, 2);
    }

    /**
     * Generate a complete, exportable JSON Architecture Plan and Sidebar Preview.
     */
    public function generateArchitecturePlan(array $inspection, string $domain = 'ERP'): array
    {
        // 1. If ERP preset plan file exists, load and enrich with live row counts
        $examplePlanFile = base_path('docs/porting-architecture-plan.json');
        if (strtolower($domain) === 'erp' && File::exists($examplePlanFile)) {
            try {
                $plan = json_decode(File::get($examplePlanFile), true);
                if (is_array($plan) && isset($plan['modules'])) {
                    return $plan;
                }
            } catch (Throwable) {}
        }

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
                'default_open' => in_array(strtolower($mName), ['work', 'salescrm', 'finance'], true),
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
            'sidebar_preview' => [
                'title' => "{$domain} Enterprise Navigation",
                'theme' => 'BlatUI Dashboard-01',
                'groups' => $sidebarGroups,
            ],
            'modules' => $formattedModules,
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
    public function executeFromPlan(array $plan, bool $importData = false, bool $withFiles = false): array
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $domain = $plan['domain'] ?? 'ERP';
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
                    $options = [
                        'app' => $domain,
                        'domain' => $moduleName,
                        'table' => $rootTable,
                        'multitenant' => $multitenant,
                        'tenant_key' => $tenantKey,
                        'description' => $slice['description'] ?? "Vertical slice for {$sliceName}",
                        'force' => true,
                        'ui' => $uiConfig,
                    ];

                    $sliceDir = $generator->generate($sliceName, $fields, false, $options);
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
     */
    public function queryDatabaseRowCounts(): array
    {
        $counts = [];
        try {
            // First check legacy erp database
            $pdo = null;
            try {
                $pdo = new \PDO('mysql:host=127.0.0.1;dbname=erp', 'root', '');
            } catch (Throwable) {
                $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
            }

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
     * Get column definitions from database or fallback.
     */
    public function getColumnsForTable(string $table): array
    {
        $cols = [];
        try {
            // Check legacy erp database first
            $pdo = null;
            try {
                $pdo = new \PDO('mysql:host=127.0.0.1;dbname=erp', 'root', '');
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                $rawCols = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rawCols as $rc) {
                    $cols[] = [
                        'name' => $rc['Field'],
                        'type' => $rc['Type'],
                    ];
                }
                return $cols;
            } catch (Throwable) {}

            if (Schema::hasTable($table)) {
                $raw = Schema::getColumns($table);
                foreach ($raw as $rc) {
                    $cols[] = [
                        'name' => $rc['name'] ?? '',
                        'type' => $rc['type_name'] ?? 'string',
                    ];
                }
            }
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
                    ['label' => 'Active / In Progress', 'value' => (int) ($recordsCount * 0.7), 'change' => 'Standard flow', 'icon' => 'play-circle', 'color' => 'blue'],
                    ['label' => 'Attention / Pending', 'value' => (int) ($recordsCount * 0.2), 'change' => 'Action required', 'icon' => 'alert-circle', 'color' => 'amber'],
                    ['label' => 'Completed / Closed', 'value' => (int) ($recordsCount * 0.1), 'change' => 'Archived', 'icon' => 'check-circle', 'color' => 'emerald'],
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

}
