<?php

namespace LaraSlice\Porting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

class SliceDataPortService
{
    /**
     * Framework-owned auth tables. The ETL NEVER wipe+replaces these: doing so
     * destroys the developer's RBAC (super-admin/super-dev roles, studio.*
     * permissions) and locks everyone out of the studios with a 403.
     *
     * Legacy auth data is only imported when setImportAuth(true) is called,
     * and then through normalized merge (never delete).
     */
    protected array $systemAuthTables = ['roles', 'permissions', 'permission_role', 'role_user'];

    /**
     * Tables merged by natural key instead of wipe+replace.
     * Format: table => unique key column. Existing rows are never deleted
     * or modified; only missing rows are inserted.
     */
    protected array $mergeTables = ['users' => 'email'];

    /**
     * Opt-in: import legacy roles/permissions through normalized merge.
     * Default false — auth tables are skipped to protect framework RBAC.
     */
    protected bool $importAuth = false;

    /**
     * Opt into normalized legacy auth import (roles/permissions merged by slug,
     * never deleted). Use when you deliberately want legacy business roles.
     */
    public function setImportAuth(bool $import): self
    {
        $this->importAuth = $import;
        return $this;
    }

    /**
     * Import data for the ported slices (slice-aware).
     * Uses the slice mapping to determine which tables to import,
     * instead of a hardcoded priority list.
     */
    public function importForSlices(
        array $sliceMapping,
        string $sourceConnection = 'laraslice_source',
        ?callable $progressCallback = null
    ): array {
        // Build table list from slice mapping
        $tablesToImport = [];
        foreach ($sliceMapping as $sliceName => $slice) {
            $rootTable = $slice['table'] ?? null;
            if ($rootTable) {
                $tablesToImport[] = $rootTable;
            }
            // Include child/related tables
            foreach ($slice['children'] ?? [] as $child) {
                if (isset($child['table'])) {
                    $tablesToImport[] = $child['table'];
                }
            }
            // Include pivot tables
            foreach ($slice['pivots'] ?? [] as $pivot) {
                $tablesToImport[] = $pivot;
            }
        }
        $tablesToImport = array_values(array_unique($tablesToImport));

        return $this->importTables($tablesToImport, $sourceConnection, $progressCallback);
    }

    /**
     * Stream specific tables from source to target database.
     */
    public function importTables(
        array $tables,
        string $sourceConnection = 'laraslice_source',
        ?callable $progressCallback = null
    ): array {
        $results = [];
        $totalRowsImported = 0;
        $skippedTables = [];

        try {
            $sourceConn = \Illuminate\Support\Facades\DB::connection($sourceConnection);
            $sourcePdo = $sourceConn->getPdo();
            $driver = $sourceConn->getDriverName();

            // Disable FK constraints (driver-aware)
            \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

            // Get source table list
            $sourceTables = $this->getSourceTableList($sourcePdo, $driver);

            // Report source tables not covered
            foreach ($sourceTables as $st) {
                if (! in_array($st, $tables, true)) {
                    $skippedTables[] = $st;
                }
            }

            foreach ($tables as $table) {
                if (! in_array($table, $sourceTables, true)) {
                    $results[$table] = ['status' => 'not_in_source', 'rows' => 0];
                    continue;
                }

                if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                    $results[$table] = ['status' => 'not_in_target', 'rows' => 0];
                    continue;
                }

                $imported = $this->importSingleTable($table, $sourcePdo, $driver, $progressCallback);
                $results[$table] = $imported;
                $totalRowsImported += $imported['rows'] ?? 0;
            }

            \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        } catch (Throwable $e) {
            $results['_error'] = $e->getMessage();
        }

        return [
            'results' => $results,
            'total_rows' => $totalRowsImported,
            'skipped_tables' => $skippedTables,
            'tables_imported' => count(array_filter($results, fn($r) => ($r['rows'] ?? 0) > 0)),
            'auth_reconciliation' => $this->reconcileAuthAfterImport(),
        ];
    }

    /**
     * Analyze source seeders and generate slice seeders for reference data.
     * Seeders contain valuable lookup data: currencies, countries, roles, tax rates, etc.
     */
    public function analyzeSeeders(string $sourcePath, array $sliceMapping): array
    {
        $seederDir = $sourcePath.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'seeders';
        $results = [];

        if (! \Illuminate\Support\Facades\File::isDirectory($seederDir)) {
            return $results;
        }

        foreach (\Illuminate\Support\Facades\File::files($seederDir) as $file) {
            $content = $file->getContents();
            $className = $file->getFilenameWithoutExtension();

            // Skip the main DatabaseSeeder (just calls others)
            if ($className === 'DatabaseSeeder') {
                continue;
            }

            // Extract table names from DB::table('xxx')->insert() or Model::create()
            $tables = [];
            if (preg_match_all("/DB::table\(\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
                $tables = array_merge($tables, $m[1]);
            }
            if (preg_match_all("/(\w+)::create\s*\(/", $content, $m)) {
                // Model::create — try to infer table from model name
                foreach ($m[1] as $model) {
                    $tables[] = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::pluralStudly($model));
                }
            }

            $tables = array_values(array_unique($tables));

            // Map to slices
            $mappedSlices = [];
            foreach ($tables as $table) {
                foreach ($sliceMapping as $sliceName => $slice) {
                    if (($slice['table'] ?? '') === $table) {
                        $mappedSlices[] = $sliceName;
                        break;
                    }
                }
            }

            if (! empty($tables)) {
                $results[$className] = [
                    'file' => $file->getFilename(),
                    'tables' => $tables,
                    'slices' => array_values(array_unique($mappedSlices)),
                    'is_reference_data' => $this->isReferenceDataSeeder($className, $tables),
                ];
            }
        }

        return $results;
    }

    /**
     * Check if a seeder contains reference/lookup data (safe to auto-import).
     */
    private function isReferenceDataSeeder(string $className, array $tables): bool
    {
        $referencePatterns = ['currenc', 'countr', 'role', 'permission', 'tax', 'status', 'type', 'setting', 'language', 'timezone'];
        $nameLower = strtolower($className);

        foreach ($referencePatterns as $pattern) {
            if (str_contains($nameLower, $pattern)) {
                return true;
            }
        }

        foreach ($tables as $table) {
            foreach ($referencePatterns as $pattern) {
                if (str_contains(strtolower($table), $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get table list from source database (driver-aware).
     */
    private function getSourceTableList($pdo, string $driver): array
    {
        if ($driver === 'mysql') {
            return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($driver === 'pgsql') {
            return $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($driver === 'sqlite') {
            return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        }
        return [];
    }

    /**
     * Import a single table with chunked streaming.
     */
    private function importSingleTable(string $table, $sourcePdo, string $driver, ?callable $progressCallback): array
    {
        // GUARD: framework auth tables are never wipe+replaced. A blind delete()
        // here destroys super-admin/super-dev roles and studio.* permissions,
        // locking every developer out of the studios (403).
        if (in_array($table, $this->systemAuthTables, true) && ! $this->importAuth) {
            return [
                'status' => 'skipped_system_auth',
                'rows' => 0,
                'note' => 'Framework auth table protected from overwrite. Call setImportAuth(true) to opt into normalized merge.',
            ];
        }

        // Opt-in: normalized legacy auth import (merge, never delete)
        if (in_array($table, $this->systemAuthTables, true) && $this->importAuth) {
            return $this->importNormalizedAuthTable($table, $sourcePdo, $driver, $progressCallback);
        }

        // Merge tables: existing rows are never deleted or modified
        if (isset($this->mergeTables[$table])) {
            return $this->importMergeTable($table, $this->mergeTables[$table], $sourcePdo, $driver, $progressCallback);
        }

        try {
            $countStmt = $sourcePdo->query("SELECT COUNT(*) FROM `{$table}`");
            $rowCount = (int) $countStmt->fetchColumn();

            if ($rowCount === 0) {
                return ['status' => 'empty', 'rows' => 0];
            }

            // Get target columns to prevent inserting into non-existent columns
            $targetColumns = \Illuminate\Support\Facades\Schema::getColumnListing($table);

            if ($driver === 'mysql') {
                $sourceColumns = $sourcePdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                // For other drivers, use target columns as-is
                $sourceColumns = $targetColumns;
            }

            $validColumns = array_values(array_intersect($sourceColumns, $targetColumns));

            if (empty($validColumns)) {
                return ['status' => 'no_matching_columns', 'rows' => 0];
            }

            $colsSql = implode(', ', array_map(fn($c) => "`{$c}`", $validColumns));

            // Clear target table
            \Illuminate\Support\Facades\DB::table($table)->delete();

            $chunkSize = 250;
            $offset = 0;
            $tableImported = 0;

            while ($offset < $rowCount) {
                $orderBy = in_array('id', $validColumns, true) ? 'ORDER BY `id`' : '';
                $rows = $sourcePdo->query("SELECT {$colsSql} FROM `{$table}` {$orderBy} LIMIT {$chunkSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);

                if (empty($rows)) {
                    break;
                }

                // Sanitize timestamps
                $sanitized = array_map(function ($row) {
                    foreach ($row as $k => $v) {
                        if ($v === '0000-00-00 00:00:00' || $v === '0000-00-00') {
                            $row[$k] = null;
                        }
                    }
                    return $row;
                }, $rows);

                \Illuminate\Support\Facades\DB::table($table)->insert($sanitized);
                $tableImported += count($rows);
                $offset += $chunkSize;

                if ($progressCallback) {
                    $progressCallback($table, $tableImported, $rowCount);
                }
            }

            return ['status' => 'imported', 'rows' => $tableImported];

        } catch (Throwable $e) {
            return ['status' => 'error', 'rows' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Legacy: Map of source tables to copy into target database.
     * @deprecated Use importForSlices() with slice mapping instead.
     */
    protected array $priorityTables = [
        // 1. Companies & Core Auth
        'companies',
        'roles',
        'permissions',
        'permission_role',
        'users',
        'role_user',
        'company_addresses',

        // 2. Reference & Taxonomies
        'currencies',
        'currency_format_settings',
        'countries',
        'flags',
        'teams', // Departments
        'designations',

        // 3. CRM & Clients
        'client_details',
        'client_contacts',
        'client_docs',
        'client_notes',
        'lead_pipelines',
        'lead_agents',
        'leads',
        'deals',

        // 4. Human Capital (HR)
        'employee_details',
        'emergency_contacts',
        'employee_docs',
        'employee_shifts',
        'employee_shift_schedules',
        'attendances',
        'leave_types',
        'leaves',
        'employee_leave_quotas',
        'holidays',
        'award_icons',
        'awards',
        'appreciations',

        // 5. Work Management
        'contracts',
        'contract_types',
        'contract_discussions',
        'project_category',
        'project_status_settings',
        'projects',
        'project_members',
        'project_milestones',
        'project_time_logs',
        'project_activity',
        'taskboard_columns',
        'task_category',
        'tasks',
        'task_users',

        // 6. Finance & Billing
        'invoice_settings',
        'invoices',
        'invoice_items',
        'payments',
        'estimates',
        'estimate_items',
        'proposals',
        'credit_notes',
        'credit_note_items',
        'expenses_category',
        'expenses',
        'bank_accounts',
        'bank_transactions',
        'taxes',
        'offline_payment_methods',
        'payment_gateway_credentials',

        // 7. Supply Chain
        'product_category',
        'products',
        'orders',
        'order_items',

        // 8. Service Desk & Communication
        'ticket_groups',
        'ticket_types',
        'ticket_channels',
        'ticket_agent_groups',
        'tickets',
        'ticket_replies',
        'ticket_activities',
        'events',
        'event_attendees',
        'notices',
        'knowledge_bases',
        'users_chat',
        'sticky_notes',

        // 9. Settings
        'smtp_settings',
        'theme_settings',
        'email_notification_settings',
        'push_notification_settings',
        'pusher_settings',
        'slack_settings',
        'gdpr_settings',
        'file_storage_settings',
        'database_backup_cron_settings',
        'database_backups',
        'custom_field_groups',
        'custom_fields',
        'language_settings',
        'module_settings',
        'modules',
        'dashboard_widgets',
    ];

    /**
     * Stream data from source database into target database.
     * @deprecated Use importForSlices() with slice mapping for slice-aware imports.
     * This method now delegates to importTables() using the legacy priority list.
     */
    public function importFromDatabase(
        string $sourceDb = 'erp',
        string $sourceHost = '127.0.0.1',
        int $sourcePort = 3306,
        string $sourceUser = 'root',
        string $sourcePass = '',
        ?callable $progressCallback = null
    ): array {
        // Handle argument order flexibility (host-first vs db-first)
        if ($sourceDb === '127.0.0.1' || $sourceDb === 'localhost') {
            $tempHost = $sourceDb;
            $tempPort = is_numeric($sourceHost) ? (int) $sourceHost : 3306;
            $sourceDb = is_string($sourcePort) ? (string) $sourcePort : 'erp';
            $sourceHost = $tempHost;
            $sourcePort = $tempPort;
        }

        $sourcePdo = new PDO("mysql:host={$sourceHost};port={$sourcePort};dbname={$sourceDb}", $sourceUser, $sourcePass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $results = [];
        $totalRowsImported = 0;
        $skippedTables = [];

        try {
            // Disable foreign key constraints in a driver-aware way (works on MySQL, SQLite, PgSQL).
            \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

            $sourceTables = $sourcePdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

            // Report source tables that are NOT covered by the priority list so
            // "full-data" migrations never silently drop tables.
            foreach ($sourceTables as $st) {
                if (! in_array($st, $this->priorityTables, true)) {
                    $skippedTables[] = $st;
                }
            }

            foreach ($this->priorityTables as $table) {
                if (! in_array($table, $sourceTables, true)) {
                    continue;
                }

                // Check if target table exists
                if (! Schema::hasTable($table)) {
                    continue;
                }

                // GUARD: framework auth tables are never wipe+replaced (see importSingleTable).
                if (in_array($table, $this->systemAuthTables, true) && ! $this->importAuth) {
                    $results[$table] = [
                        'status' => 'skipped_system_auth',
                        'rows' => 0,
                        'note' => 'Framework auth table protected from overwrite. Call setImportAuth(true) to opt into normalized merge.',
                    ];
                    continue;
                }

                // Merge tables: delegate to merge logic instead of wipe+replace.
                if (isset($this->mergeTables[$table])) {
                    $results[$table] = $this->importMergeTable($table, $this->mergeTables[$table], $sourcePdo, $driver, $progressCallback);
                    $totalRowsImported += $results[$table]['rows'] ?? 0;
                    continue;
                }

                // Opt-in normalized auth import.
                if (in_array($table, $this->systemAuthTables, true) && $this->importAuth) {
                    $results[$table] = $this->importNormalizedAuthTable($table, $sourcePdo, $driver, $progressCallback);
                    $totalRowsImported += $results[$table]['rows'] ?? 0;
                    continue;
                }

                try {
                    $countStmt = $sourcePdo->query("SELECT COUNT(*) FROM `{$table}`");
                    $rowCount = (int) $countStmt->fetchColumn();

                    if ($rowCount === 0) {
                        $results[$table] = ['status' => 'empty', 'rows' => 0];
                        continue;
                    }

                    // Get target column listing to prevent inserting into non-existent columns
                    $targetColumns = Schema::getColumnListing($table);
                    $sourceColsStmt = $sourcePdo->query("SHOW COLUMNS FROM `{$table}`");
                    $sourceColumns = $sourceColsStmt->fetchAll(PDO::FETCH_COLUMN);
                    $validColumns = array_values(array_intersect($sourceColumns, $targetColumns));

                    if (empty($validColumns)) {
                        continue;
                    }

                    $colsSql = implode(', ', array_map(fn($c) => "`{$c}`", $validColumns));

                    // Clear target table before streaming (DELETE avoids FK truncation lock)
                    DB::table($table)->delete();

                    // Chunked read from source and insert into target
                    $chunkSize = 250;
                    $offset = 0;
                    $tableImported = 0;

                    while ($offset < $rowCount) {
                        // ORDER BY keeps chunks deterministic when the table has a primary key.
                        $orderBy = in_array('id', $validColumns, true) ? 'ORDER BY `id`' : '';
                        $rowsStmt = $sourcePdo->query("SELECT {$colsSql} FROM `{$table}` {$orderBy} LIMIT {$chunkSize} OFFSET {$offset}");
                        $rows = $rowsStmt->fetchAll();

                        if (empty($rows)) {
                            break;
                        }

                        // Sanitize row data (fix timestamps / unquoted nulls)
                        $sanitized = array_map(function ($row) {
                            foreach ($row as $k => $v) {
                                if ($v === '0000-00-00 00:00:00' || $v === '0000-00-00') {
                                    $row[$k] = null;
                                }
                            }
                            return $row;
                        }, $rows);

                        DB::table($table)->insertOrIgnore($sanitized);
                        $tableImported += count($rows);
                        $offset += $chunkSize;
                    }

                    $totalRowsImported += $tableImported;
                    $results[$table] = ['status' => 'success', 'rows' => $tableImported];

                    if ($progressCallback) {
                        $progressCallback($table, $tableImported, $rowCount);
                    }
                } catch (Throwable $e) {
                    $results[$table] = ['status' => 'error', 'error' => $e->getMessage()];
                }
            }
        } finally {
            \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
        }

        return [
            'success' => true,
            'tables_imported' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'success')),
            'total_rows' => $totalRowsImported,
            'details' => $results,
            'skipped_tables' => array_values(array_unique($skippedTables)),
            'skipped_tables_note' => 'These source tables are not in the ETL priority list and were not imported. Extend SliceDataPortService::$priorityTables or import them manually.',
            'auth_reconciliation' => $this->reconcileAuthAfterImport(),
        ];
    }

    /**
     * Get source table columns for any driver (mysql, sqlite, pgsql).
     */
    protected function getSourceColumns($sourcePdo, string $driver, string $table): array
    {
        try {
            if ($driver === 'mysql') {
                return $sourcePdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            }
            if ($driver === 'sqlite') {
                $cols = [];
                foreach ($sourcePdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cols[] = $row['name'];
                }
                return $cols;
            }
            if ($driver === 'pgsql') {
                $stmt = $sourcePdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ?");
                $stmt->execute([$table]);
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Throwable $e) {
            // fall through
        }
        return [];
    }

    /**
     * Merge a table by natural key: existing rows are never deleted or modified,
     * only missing rows are inserted. Used for `users` so a data port can never
     * wipe the developer's own account.
     */
    protected function importMergeTable(
        string $table,
        string $keyColumn,
        $sourcePdo,
        string $driver,
        ?callable $progressCallback
    ): array {
        try {
            $targetColumns = \Illuminate\Support\Facades\Schema::getColumnListing($table);

            $sourceColumns = $this->getSourceColumns($sourcePdo, $driver, $table);

            $validColumns = array_values(array_intersect($sourceColumns, $targetColumns));

            if (empty($validColumns) || ! in_array($keyColumn, $validColumns, true)) {
                return ['status' => 'no_matching_columns', 'rows' => 0];
            }

            // Never carry over legacy primary keys: let the target assign fresh ids
            // so merged rows can never collide with existing rows.
            $insertColumns = array_values(array_filter($validColumns, fn($col) => $col !== 'id'));
            $colsSql = implode(', ', array_map(fn($col) => "`{$col}`", $validColumns));

            $countStmt = $sourcePdo->query("SELECT COUNT(*) FROM `{$table}`");
            $rowCount = (int) $countStmt->fetchColumn();

            $existingKeys = \Illuminate\Support\Facades\DB::table($table)->pluck($keyColumn)->all();
            $existingLookup = array_flip(array_map('strtolower', array_map('strval', $existingKeys)));

            $chunkSize = 250;
            $offset = 0;
            $imported = 0;
            $skippedExisting = 0;

            while ($offset < $rowCount) {
                $rows = $sourcePdo->query("SELECT {$colsSql} FROM `{$table}` LIMIT {$chunkSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) {
                    break;
                }

                $toInsert = [];
                foreach ($rows as $row) {
                    foreach ($row as $k => $v) {
                        if ($v === '0000-00-00 00:00:00' || $v === '0000-00-00') {
                            $row[$k] = null;
                        }
                    }
                    $key = strtolower((string) ($row[$keyColumn] ?? ''));
                    if ($key === '' || isset($existingLookup[$key])) {
                        $skippedExisting++;
                        continue;
                    }
                    unset($row['id']);
                    $toInsert[] = array_intersect_key($row, array_flip($insertColumns));
                    $existingLookup[$key] = true;
                }

                if (! empty($toInsert)) {
                    \Illuminate\Support\Facades\DB::table($table)->insert($toInsert);
                    $imported += count($toInsert);
                }

                $offset += $chunkSize;
                if ($progressCallback) {
                    $progressCallback($table, $imported, $rowCount);
                }
            }

            return [
                'status' => 'merged',
                'rows' => $imported,
                'skipped_existing' => $skippedExisting,
                'note' => "Merged by {$keyColumn}: {$imported} new rows inserted, {$skippedExisting} existing rows preserved.",
            ];
        } catch (Throwable $e) {
            return ['status' => 'error', 'rows' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Normalized legacy auth import (opt-in via setImportAuth(true)).
     * Legacy roles/permissions are merged by slug — existing framework rows are
     * never deleted or overwritten. Slugs are normalized; reserved slugs
     * (super-admin, super-dev) can never be created from legacy data.
     */
    protected function importNormalizedAuthTable(
        string $table,
        $sourcePdo,
        string $driver,
        ?callable $progressCallback
    ): array {
        try {
            if ($table === 'role_user' || $table === 'permission_role') {
                return $this->importRemappedPivot($table, $sourcePdo, $driver);
            }

            $targetColumns = \Illuminate\Support\Facades\Schema::getColumnListing($table);
            $hasSlug = in_array('slug', $targetColumns, true);

            $sourceColumns = $this->getSourceColumns($sourcePdo, $driver, $table);

            // Legacy tables often use `name` instead of `slug`
            $nameColumn = null;
            foreach (['slug', 'name', 'display_name'] as $candidate) {
                if (in_array($candidate, $sourceColumns, true)) {
                    $nameColumn = $candidate;
                    break;
                }
            }
            if (! $nameColumn) {
                return ['status' => 'no_matching_columns', 'rows' => 0];
            }

            $reserved = ['super-admin', 'super_admin', 'super-dev', 'super_dev'];
            $rows = $sourcePdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);

            $imported = 0;
            $skipped = 0;
            $now = date('Y-m-d H:i:s');

            foreach ($rows as $row) {
                $rawName = (string) ($row[$nameColumn] ?? '');
                $slug = \Illuminate\Support\Str::slug($rawName);
                if ($slug === '') {
                    $skipped++;
                    continue;
                }
                // Reserved slugs can never be minted from legacy data
                if (in_array($slug, $reserved, true)) {
                    $slug = 'legacy-' . $slug;
                }

                $exists = \Illuminate\Support\Facades\DB::table($table)->where('slug', $slug)->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }

                $payload = ['slug' => $slug, 'created_at' => $now, 'updated_at' => $now];
                if (in_array('name', $targetColumns, true)) {
                    $payload['name'] = $row['name'] ?? $row['display_name'] ?? $rawName;
                }
                if (in_array('description', $targetColumns, true) && isset($row['description'])) {
                    $payload['description'] = $row['description'];
                }

                \Illuminate\Support\Facades\DB::table($table)->insert($payload);
                $imported++;
            }

            return [
                'status' => 'normalized_merge',
                'rows' => $imported,
                'skipped_existing' => $skipped,
                'note' => "Normalized auth merge: {$imported} legacy rows imported as new slugs, {$skipped} skipped (existing/reserved). Framework roles untouched.",
            ];
        } catch (Throwable $e) {
            return ['status' => 'error', 'rows' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Remap legacy pivot rows (role_user, permission_role) onto target ids via
     * natural keys (user email, role/permission slug). Inserts are idempotent.
     */
    protected function importRemappedPivot(string $table, $sourcePdo, string $driver = 'mysql'): array
    {
        try {
            if ($table === 'role_user') {
                $sourceRows = $sourcePdo->query("SELECT user_id, role_id FROM role_user")->fetchAll(PDO::FETCH_ASSOC);

                // Legacy id -> natural key maps from source
                $emailById = [];
                foreach ($sourcePdo->query("SELECT id, email FROM users")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $emailById[$r['id']] = strtolower((string) $r['email']);
                }
                $roleNameById = [];
                $roleCols = $this->getSourceColumns($sourcePdo, $driver, 'roles');
                $roleNameCol = in_array('name', $roleCols, true) ? 'name' : (in_array('display_name', $roleCols, true) ? 'display_name' : null);
                if ($roleNameCol) {
                    foreach ($sourcePdo->query("SELECT id, `{$roleNameCol}` AS n FROM roles")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $roleNameById[$r['id']] = \Illuminate\Support\Str::slug((string) $r['n']);
                    }
                }

                // Target natural key -> id maps
                $userIdByEmail = [];
                foreach (\Illuminate\Support\Facades\DB::table('users')->select('id', 'email')->get() as $u) {
                    $userIdByEmail[strtolower((string) $u->email)] = $u->id;
                }
                $roleIdBySlug = [];
                foreach (\Illuminate\Support\Facades\DB::table('roles')->select('id', 'slug')->get() as $r) {
                    $roleIdBySlug[(string) $r->slug] = $r->id;
                }

                $linked = 0;
                $unmapped = 0;
                foreach ($sourceRows as $row) {
                    $email = $emailById[$row['user_id']] ?? null;
                    $roleSlug = $roleNameById[$row['role_id']] ?? null;
                    // Legacy 'admin' slug was normalized to 'legacy-admin' on import
                    if ($roleSlug && ! isset($roleIdBySlug[$roleSlug]) && isset($roleIdBySlug['legacy-' . $roleSlug])) {
                        $roleSlug = 'legacy-' . $roleSlug;
                    }
                    $targetUserId = $email ? ($userIdByEmail[$email] ?? null) : null;
                    $targetRoleId = $roleSlug ? ($roleIdBySlug[$roleSlug] ?? null) : null;

                    if (! $targetUserId || ! $targetRoleId) {
                        $unmapped++;
                        continue;
                    }

                    $exists = \Illuminate\Support\Facades\DB::table('role_user')
                        ->where('user_id', $targetUserId)
                        ->where('role_id', $targetRoleId)
                        ->exists();
                    if (! $exists) {
                        \Illuminate\Support\Facades\DB::table('role_user')->insert([
                            'user_id' => $targetUserId,
                            'role_id' => $targetRoleId,
                        ]);
                        $linked++;
                    }
                }

                return ['status' => 'remapped', 'rows' => $linked, 'unmapped' => $unmapped,
                    'note' => "role_user remapped via email/slug: {$linked} links created, {$unmapped} unmapped (user or role not present)."];
            }

            // permission_role: remap via permission slug (legacy perms were normalized on import)
            $sourceRows = $sourcePdo->query("SELECT permission_id, role_id FROM permission_role")->fetchAll(PDO::FETCH_ASSOC);
            return ['status' => 'skipped', 'rows' => 0,
                'note' => 'permission_role remap requires legacy permission slugs; assign permissions to imported roles in the UI.'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'rows' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Post-port RBAC reconciliation. Runs after every data import. Idempotent:
     * safe to run any number of times. Repairs the exact lockout scenario where
     * a legacy import destroyed roles/permissions:
     *  1. Ensures super-admin and super-dev roles exist with correct slugs.
     *  2. Re-seeds all framework permissions via SliceManager::syncPermissions().
     *  3. Re-attaches all permissions to both privileged roles.
     */
    public function reconcileAuthAfterImport(): array
    {
        $summary = ['roles_ensured' => [], 'permissions_synced' => false, 'errors' => []];

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('roles')) {
                return $summary;
            }

            $now = date('Y-m-d H:i:s');
            foreach ([
                'super-admin' => ['name' => 'Super Administrator', 'description' => 'Full unrestricted system-wide access to all slices and settings'],
                'super-dev' => ['name' => 'Platform Developer', 'description' => 'Framework developer master key: whole-system access including Slice Studio, Blueprint Studio and Porting Studio. Immune to legacy data ports.'],
            ] as $slug => $meta) {
                $exists = \Illuminate\Support\Facades\DB::table('roles')->where('slug', $slug)->exists();
                if (! $exists) {
                    // Repair: a legacy import may have wiped the role row entirely
                    \Illuminate\Support\Facades\DB::table('roles')->insert([
                        'slug' => $slug,
                        'name' => $meta['name'],
                        'description' => $meta['description'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $summary['roles_ensured'][] = $slug . ' (recreated)';
                } else {
                    $summary['roles_ensured'][] = $slug . ' (exists)';
                }

                // Repair: legacy rows may exist with the right name but empty slug
                // (e.g. Worksuite role id=1 name='admin'). Never touch them — the
                // framework roles above are matched strictly by slug.
            }

            // Re-seed every framework permission and re-attach to privileged roles.
            // SliceManager::syncPermissions() is idempotent (updateOrInsert).
            try {
                $sliceManager = app(\LaraSlice\Core\Discovery\SliceManager::class);
                if (method_exists($sliceManager, 'syncPermissions')) {
                    $sliceManager->syncPermissions();
                    $summary['permissions_synced'] = true;
                }
            } catch (Throwable $e) {
                $summary['errors'][] = 'syncPermissions: ' . $e->getMessage();
            }
        } catch (Throwable $e) {
            $summary['errors'][] = $e->getMessage();
        }

        return $summary;
    }
}
