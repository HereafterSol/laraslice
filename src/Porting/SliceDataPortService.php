<?php

namespace LaraSlice\Porting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

class SliceDataPortService
{
    /**
     * Map of source tables to copy into target database.
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

        // Temporarily disable foreign key constraints in target DB
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        try {
            $sourceTables = $sourcePdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

            foreach ($this->priorityTables as $table) {
                if (! in_array($table, $sourceTables, true)) {
                    continue;
                }

                // Check if target table exists
                if (! Schema::hasTable($table)) {
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
                        $rowsStmt = $sourcePdo->query("SELECT {$colsSql} FROM `{$table}` LIMIT {$chunkSize} OFFSET {$offset}");
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
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        return [
            'success' => true,
            'tables_imported' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'success')),
            'total_rows' => $totalRowsImported,
            'details' => $results,
        ];
    }
}
