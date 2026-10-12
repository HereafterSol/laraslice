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

        $classified = $this->classifyTables($tables);
        $sliceMapping = $this->buildSliceMapping($classified, $domain);

        $studioSlices = $this->buildStudioSlices($sliceMapping, $tables, $domain);
        $domainYaml = $this->generateDomainBlueprintYaml($studioSlices, $domain, [
            'source_path' => $cleanPath,
            'total_tables' => count($tables),
        ]);

        return [
            'source_path' => $cleanPath,
            'domain' => $domain,
            'total_tables' => count($tables),
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

        $domainYaml = [
            'domain' => $domain,
            'source_application' => basename($cleanPath),
            'port_mode' => $mode,
            'exported_at' => date('Y-m-d H:i:s'),
            'total_tables' => $inspection['total_tables'],
            'slices' => [],
        ];

        foreach ($sliceMapping as $sliceName => $info) {
            $sliceHandle = Str::snake($sliceName);
            $sliceData = [
                'name' => $sliceName,
                'handle' => $sliceHandle,
                'domain' => $domain,
                'type' => $info['type'],
                'primary_table' => $info['primary_table'],
                'child_tables' => $info['child_tables'],
                'permissions' => [
                    "{$sliceHandle}.view",
                    "{$sliceHandle}.create",
                    "{$sliceHandle}.edit",
                    "{$sliceHandle}.delete",
                ],
            ];
            $domainYaml['slices'][$sliceName] = $sliceData;
            $generatedSlices[] = $sliceName;
        }

        $domainYamlFile = $blueprintsDir.DIRECTORY_SEPARATOR.Str::slug($domain).'.domain.yaml';
        $richDomainYaml = $this->generateDomainBlueprintYaml($inspection['studio_slices'], $domain, $inspection);
        File::put($domainYamlFile, $richDomainYaml);

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
            'domain_blueprint' => $domainYamlFile,
            'domain_blueprint_yaml' => $richDomainYaml,
            'slices_generated' => $generatedSlices,
            'seeders_generated' => $seedersCreated,
            'files_migrated' => $filesMigrated,
            'bytes_migrated' => $bytesMigrated,
            'tables_count' => $inspection['total_tables'],
        ];
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
            if (preg_match($patternCreate, $content, $m)) {
                $tableName = $m[1];
                $cols = [];
                if (preg_match_all($patternCol, $content, $colMatches, PREG_SET_ORDER)) {
                    foreach ($colMatches as $cm) {
                        $cols[] = [
                            'name' => $cm[2],
                            'type' => $cm[1],
                        ];
                    }
                }
                $fks = [];
                if (preg_match_all($patternFk, $content, $fkMatches)) {
                    $fks = array_unique($fkMatches[1]);
                }

                $tables[$tableName] = [
                    'name' => $tableName,
                    'columns' => $cols,
                    'foreign_keys' => $fks,
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

        $taxonomyPattern = '/(types?|natures?|statuse?s?|modes?|regions?|genders?|provinces?|districts?|tehsils?|categories?|faqs?)$/i';
        $systemPattern = '/^(users|user_profiles|roles|permissions|otps|sms_logs|activity_logs|api_logs|api_users|jobs|cache|notifications|personal_access_tokens)$/i';

        foreach ($tables as $tName => $tInfo) {
            if (preg_match($systemPattern, $tName)) {
                $classified['system'][$tName] = $tInfo;
                continue;
            }

            if (str_contains($tName, '__') || (count($tInfo['foreign_keys']) >= 2 && count($tInfo['columns']) <= 4)) {
                $classified['pivots'][$tName] = $tInfo;
                continue;
            }

            // Primary core domain roots should never be taxonomies
            if (in_array($tName, ['complaints', 'hearings', 'notices', 'orders'], true)) {
                $classified['roots'][$tName] = $tInfo;
                continue;
            }

            if (preg_match($taxonomyPattern, $tName)) {
                $classified['taxonomies'][$tName] = $tInfo;
                continue;
            }

            // Check if table is a child of a primary domain root
            $isChild = false;
            foreach ($tInfo['foreign_keys'] as $fk) {
                $base = preg_replace('/_id$/', '', $fk);
                if (in_array($base, ['complaint', 'hearing', 'notice', 'order', 'requisition', 'report_sought'], true)) {
                    $isChild = true;
                    break;
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
                $parentBase = Str::studly(preg_replace('/_id$/', '', $fk));
                // e.g. complaint_id -> Complaints, hearing_id -> Hearings, notice_id -> Notices
                foreach (array_keys($mapping) as $sName) {
                    if (str_starts_with($sName, $parentBase) || str_starts_with($parentBase, $sName)) {
                        $mapping[$sName]['child_tables'][] = $cName;
                        $attached = true;
                        break;
                    }
                }
                if ($attached) break;
            }

            if (! $attached) {
                if (isset($mapping['Complaints'])) {
                    $mapping['Complaints']['child_tables'][] = $cName;
                } else {
                    $first = array_key_first($mapping);
                    if ($first) {
                        $mapping[$first]['child_tables'][] = $cName;
                    }
                }
            }
        }

        // 3. Taxonomies group into MasterData
        if (! empty($classified['taxonomies'])) {
            $taxTables = array_keys($classified['taxonomies']);
            $primaryTaxTable = array_shift($taxTables);
            $mapping['MasterData'] = [
                'type' => 'taxonomy_catalog',
                'taxonomy' => true,
                'primary_table' => $primaryTaxTable,
                'child_tables' => $taxTables,
                'description' => 'Shared statutory taxonomies, lookup registries, and regional catalogs',
            ];
        }

        // 4. Ensure primary core aggregate root (e.g. Complaints) is always at index 0
        if (isset($mapping['Complaints'])) {
            $complaintsEntry = $mapping['Complaints'];
            unset($mapping['Complaints']);
            $mapping = ['Complaints' => $complaintsEntry] + $mapping;
        }

        return $mapping;
    }

    private function generateTaxonomySeeders(array $inspection, string $domain): array
    {
        $created = [];
        $seederDir = app_path("Slices/{$domain}/MasterData/Database/Seeders");
        File::ensureDirectoryExists($seederDir);

        $className = Str::studly($domain).'MasterDataSeeder';
        $filePath = $seederDir.DIRECTORY_SEPARATOR.$className.'.php';

        $taxonomies = array_keys($inspection['classified']['taxonomies']);
        $sourceSeedersDir = ($inspection['source_path'] ?? '').DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'seeders';

        $code = "<?php\n\nnamespace App\\Slices\\{$domain}\\MasterData\\Database\\Seeders;\n\n";
        $code .= "use Illuminate\\Database\\Seeder;\n";
        $code .= "use Illuminate\\Support\\Facades\\DB;\n";
        $code .= "use Illuminate\\Support\\Facades\\Schema;\n\n";
        $code .= "class {$className} extends Seeder\n{\n";
        $code .= "    public function run(): void\n    {\n";
        $code .= "        // Auto-generated by LaraSlice Porting Engine\n";
        $code .= "        // Mode: system-base (Populates master dropdowns and taxonomies with 0 blank selects)\n\n";

        $seederMap = [
            'complaint_statuses' => ['ComplaintStatusSeeder.php', 'statuses'],
            'complaint_types' => ['ComplaintTypeSeeder.php', 'types'],
            'complaint_subtypes' => ['ComplaintSubtypeSeeder.php', 'subtypes'],
            'genders' => ['GendersSeeder.php', 'genders'],
            'harassment_natures' => ['HarassmentNatureSeeder.php', 'natures'],
            'employment_natures' => ['EmploymentNatureSeeder.php', 'natures'],
            'acquisition_modes' => ['AcquisitionModeSeeder.php', 'modes'],
            'decision_types' => ['DecisionTypeSeeder.php', 'decisionTypes'],
            'penalty_types' => ['PenaltyTypeSeeder.php', 'penaltyTypes'],
            'penalty_natures' => ['PenaltyNatureSeeder.php', 'penaltyNatures'],
            'property_types' => ['PropertyTypeSeeder.php', 'types'],
            'property_natures' => ['PropertyNatureSeeder.php', 'natures'],
            'organization_types' => ['OrganizationTypeSeeder.php', 'types'],
            'regions' => ['RegionsSeeder.php', 'regions'],
            'provinces' => ['LocationDataSeeder.php', 'provinces'],
            'districts' => ['LocationDataSeeder.php', 'districts'],
            'tehsils' => ['LocationDataSeeder.php', 'tehsils'],
            'hearing_types' => ['HearingTypeSeeder.php', 'hearingTypes'],
            'roles' => ['RolesSeeder.php', 'roles'],
        ];

        foreach ($taxonomies as $tbl) {
            $dataCode = null;
            if (isset($seederMap[$tbl]) && is_dir($sourceSeedersDir)) {
                [$sFile, $sVar] = $seederMap[$tbl];
                $fullPath = $sourceSeedersDir.DIRECTORY_SEPARATOR.$sFile;
                if (file_exists($fullPath)) {
                    $content = file_get_contents($fullPath);
                    if (preg_match('/\$'.preg_quote($sVar, '/').'\s*=\s*(\[[\s\S]*?\n\s*\]);/', $content, $m)) {
                        $dataCode = trim($m[1]);
                    }
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
                $code .= "            // Master taxonomy table: {$tbl}\n";
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

        foreach ($columns as $col) {
            $cName = $col['name'];
            if (in_array($cName, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

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
    public function buildStudioSlices(array $sliceMapping, array $tables, string $domain): array
    {
        $studioSlices = [];

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
            if ($sliceName === 'Complaints') {
                $permissions[] = 'complaints.hear';
                $permissions[] = 'complaints.forward';
                $permissions[] = 'complaints.decide';
            }

            // 4. Workflow (Statutory Judicial Adjudication lifecycle - Zero SLA, 100% Genuine Statuses)
            $workflow = null;
            if ($sliceName === 'Complaints') {
                $workflow = [
                    'enabled' => true,
                    'state_column' => 'complaint_status_id',
                    'states' => [
                        ['slug' => 'pending', 'label' => 'Pending', 'color' => 'amber'],
                        ['slug' => 'deficient', 'label' => 'Deficient', 'color' => 'rose'],
                        ['slug' => 'maintainability', 'label' => 'Maintainability', 'color' => 'blue'],
                        ['slug' => 'preliminary_hearing', 'label' => 'Preliminary Hearing', 'color' => 'purple'],
                        ['slug' => 'notice_to_respondent', 'label' => 'Notice to Respondent', 'color' => 'indigo'],
                        ['slug' => 'reply', 'label' => 'Reply', 'color' => 'cyan'],
                        ['slug' => 'regular_hearing', 'label' => 'Regular Hearing', 'color' => 'teal'],
                        ['slug' => 'evidence_of_parties', 'label' => 'Evidence of parties', 'color' => 'blue'],
                        ['slug' => 'cross_examination', 'label' => 'Cross Examination', 'color' => 'purple'],
                        ['slug' => 'reports', 'label' => 'Reports / For Report', 'color' => 'cyan'],
                        ['slug' => 'arguments', 'label' => 'Final Arguments', 'color' => 'teal'],
                        ['slug' => 'orders', 'label' => 'Orders', 'color' => 'orange'],
                        ['slug' => 'final_judgement', 'label' => 'Final judgement', 'color' => 'emerald'],
                        ['slug' => 'notice_for_implementation', 'label' => 'Notice for implementation', 'color' => 'indigo'],
                        ['slug' => 'order_implemented', 'label' => 'Order Implemented', 'color' => 'emerald'],
                        ['slug' => 'contempt', 'label' => 'Contempt', 'color' => 'rose'],
                        ['slug' => 'rejected', 'label' => 'Rejected', 'color' => 'slate'],
                    ],
                    'transitions' => [
                        ['slug' => 'mark_deficient', 'name' => 'Mark Deficient', 'from' => ['pending', 'maintainability'], 'to' => 'deficient', 'target_role' => 'assistant_registrar', 'requires_remarks' => true],
                        ['slug' => 'resubmit_petition', 'name' => 'Resubmit Petition', 'from' => ['deficient'], 'to' => 'pending', 'target_role' => 'complainant'],
                        ['slug' => 'scrutinize_maintainability', 'name' => 'Verify Maintainability', 'from' => ['pending'], 'to' => 'maintainability', 'target_role' => 'assistant_registrar'],
                        ['slug' => 'reject_petition', 'name' => 'Reject Petition', 'from' => ['maintainability', 'preliminary_hearing'], 'to' => 'rejected', 'target_role' => 'registrar', 'requires_remarks' => true],
                        ['slug' => 'schedule_preliminary', 'name' => 'Schedule Preliminary Hearing', 'from' => ['maintainability'], 'to' => 'preliminary_hearing', 'target_role' => 'registrar'],
                        ['slug' => 'issue_notice', 'name' => 'Issue Notice to Respondent', 'from' => ['maintainability', 'preliminary_hearing'], 'to' => 'notice_to_respondent', 'target_role' => 'registrar'],
                        ['slug' => 'submit_reply', 'name' => 'Submit Reply', 'from' => ['notice_to_respondent'], 'to' => 'reply', 'target_role' => 'assistant_registrar'],
                        ['slug' => 'conduct_regular_hearing', 'name' => 'Conduct Regular Hearing', 'from' => ['reply', 'preliminary_hearing'], 'to' => 'regular_hearing', 'target_role' => 'ombudsperson'],
                        ['slug' => 'record_evidence', 'name' => 'Record Evidence', 'from' => ['regular_hearing'], 'to' => 'evidence_of_parties', 'target_role' => 'ombudsperson'],
                        ['slug' => 'cross_examine', 'name' => 'Conduct Cross Examination', 'from' => ['evidence_of_parties'], 'to' => 'cross_examination', 'target_role' => 'advisor'],
                        ['slug' => 'seek_report', 'name' => 'Seek Inquiry Report', 'from' => ['regular_hearing', 'evidence_of_parties'], 'to' => 'reports', 'target_role' => 'ombudsperson'],
                        ['slug' => 'hear_arguments', 'name' => 'Hear Final Arguments', 'from' => ['cross_examination', 'reports', 'regular_hearing'], 'to' => 'arguments', 'target_role' => 'ombudsperson'],
                        ['slug' => 'reserve_orders', 'name' => 'Reserve Orders', 'from' => ['arguments'], 'to' => 'orders', 'target_role' => 'ombudsperson'],
                        ['slug' => 'pronounce_judgment', 'name' => 'Pronounce Final Judgement', 'from' => ['orders', 'arguments'], 'to' => 'final_judgement', 'target_role' => 'ombudsperson'],
                        ['slug' => 'issue_implementation_notice', 'name' => 'Notice for Implementation', 'from' => ['final_judgement'], 'to' => 'notice_for_implementation', 'target_role' => 'law_officer'],
                        ['slug' => 'mark_implemented', 'name' => 'Mark Order Implemented', 'from' => ['notice_for_implementation'], 'to' => 'order_implemented', 'target_role' => 'law_officer'],
                        ['slug' => 'initiate_contempt', 'name' => 'Initiate Contempt', 'from' => ['notice_for_implementation'], 'to' => 'contempt', 'target_role' => 'law_officer', 'requires_remarks' => true],
                    ],
                ];
            }

            // 4.1 Roles & Scoped RBAC (Statutory Judicial Adjudication Roles from Legacy RBAC)
            $roles = [];
            if ($sliceName === 'Complaints') {
                $roles = [
                    [
                        'slug' => 'super_admin',
                        'name' => 'Super Admin',
                        'description' => 'Apex system administration, security configurations, role assignment, and audit logs',
                        'seed' => true,
                        'seedUser' => true,
                        'permissions' => ['complaints.view', 'complaints.create', 'complaints.edit', 'complaints.delete', 'complaints.hear', 'complaints.forward', 'complaints.decide'],
                    ],
                    [
                        'slug' => 'admin',
                        'name' => 'Admin',
                        'description' => 'Operational case oversight, staff allocation, reporting dossiers, and institutional analytics',
                        'seed' => true,
                        'seedUser' => true,
                        'permissions' => ['complaints.view', 'complaints.create', 'complaints.edit', 'complaints.forward'],
                    ],
                    [
                        'slug' => 'complainant',
                        'name' => 'Complainant',
                        'description' => 'Aggrieved citizen registering grievance petition, submitting documents, and tracking case progress',
                        'seed' => true,
                        'seedUser' => false,
                        'permissions' => ['complaints.view', 'complaints.create'],
                    ],
                    [
                        'slug' => 'assistant_registrar',
                        'name' => 'Assistant Registrar',
                        'description' => 'Initial petition scrutiny, deficiency notices, diary numbering, and case dossier compilation',
                        'seed' => true,
                        'seedUser' => true,
                        'permissions' => ['complaints.view', 'complaints.create', 'complaints.edit', 'complaints.forward'],
                    ],
                    [
                        'slug' => 'registrar',
                        'name' => 'Registrar',
                        'description' => 'Maintainability determination, bench scheduling, summons & notice dispatch to respondents',
                        'seed' => true,
                        'seedUser' => true,
                        'permissions' => ['complaints.view', 'complaints.edit', 'complaints.forward'],
                    ],
                    [
                        'slug' => 'ombudsperson',
                        'name' => 'Ombudsperson',
                        'description' => 'Federal Ombudsperson presiding over judicial hearings, interim injunctions, and final decrees',
                        'seed' => true,
                        'seedUser' => true,
                        'permissions' => ['complaints.view', 'complaints.hear', 'complaints.decide'],
                    ],
                    [
                        'slug' => 'advisor',
                        'name' => 'Advisor',
                        'description' => 'Senior judicial advisory, legal research briefs, hearing assistance, and case analysis',
                        'seed' => true,
                        'seedUser' => true,
                        'permissions' => ['complaints.view', 'complaints.hear', 'complaints.forward'],
                    ],
                    [
                        'slug' => 'law_officer',
                        'name' => 'Law Officer',
                        'description' => 'Legal scrutiny, court compliance tracking, judgment implementation enforcement, and contempt review',
                        'seed' => true,
                        'seedUser' => true,
                        'permissions' => ['complaints.view', 'complaints.forward', 'complaints.decide'],
                    ],
                ];
            }

            // 5. Navigation Icon & Grouping
            $icon = match($sliceName) {
                'Complaints' => 'scale',
                'Attachments' => 'paperclip',
                'Representations' => 'file-text',
                'ReportSoughts' => 'search',
                'NoticeTitles', 'NoticeTemplates', 'Notices' => 'bell',
                'MasterData' => 'database',
                default => 'layers',
            };

            $blueprintObj = [
                'schema_version' => 1,
                'name' => $sliceName,
                'handle' => $sliceHandle,
                'domain' => $domain,
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

            // 6. Generate Slice YAML representation
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
            'generated_at' => date('Y-m-d H:i:s'),
            'slices' => array_map(function($s) {
                return $s['blueprint'];
            }, $studioSlices),
        ];

        return Yaml::dump($domainData, 8, 2);
    }

}
