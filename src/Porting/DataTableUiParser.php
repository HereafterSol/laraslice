<?php

namespace LaraSlice\Porting;

use Illuminate\Support\Str;

/**
 * Phase 2 — classify source *DataTable.php into Slice YAML v0.1 formatter vocabulary.
 *
 * Static pattern matching only. Never executes closures. Never emits HTML into YAML.
 * Unclassifiable columns are skipped (logged in "skipped").
 *
 * @see docs/SLICE_YAML_V0.1.md
 */
class DataTableUiParser
{
    /** @var array<string, string> */
    protected array $sliceAliases = [
        'Tasks' => 'TasksDataTable',
        'Task' => 'TasksDataTable',
        'Projects' => 'ProjectsDataTable',
        'Project' => 'ProjectsDataTable',
        'Invoices' => 'InvoicesDataTable',
        'Invoice' => 'InvoicesDataTable',
        'EmployeeDetails' => 'EmployeesDataTable',
        'EmployeeDetail' => 'EmployeesDataTable',
        'Employees' => 'EmployeesDataTable',
        'Employee' => 'EmployeesDataTable',
        'LeadContacts' => 'LeadContactDataTable',
        'LeadContact' => 'LeadContactDataTable',
        'CreditNotes' => 'CreditNotesDataTable',
        'CreditNote' => 'CreditNotesDataTable',
        'Estimates' => 'EstimatesDataTable',
        'Estimate' => 'EstimatesDataTable',
        'Payments' => 'PaymentsDataTable',
        'Payment' => 'PaymentsDataTable',
        'TimeLogs' => 'TimeLogsDataTable',
        'TimeLog' => 'TimeLogsDataTable',
        'Leaves' => 'LeaveDataTable',
        'Leave' => 'LeaveDataTable',
        'Tickets' => 'TicketDataTable',
        'Ticket' => 'TicketDataTable',
        'Designations' => 'DesignationDataTable',
        'Designation' => 'DesignationDataTable',
        'Departments' => 'DepartmentDataTable',
        'Department' => 'DepartmentDataTable',
        'Holidays' => 'HolidayDataTable',
        'Holiday' => 'HolidayDataTable',
        'Expenses' => 'ExpensesDataTable',
        'Expense' => 'ExpensesDataTable',
    ];

    /** Closed formatter list aligned with Slice YAML v0.1 */
    public const FORMATTERS = [
        'text',
        'text_with_badge',
        'status_pill',
        'date_time',
        'date_overdue',
        'money_breakdown',
        'progress_bar',
        'code_badge',
        'composite_user',
        'number',
    ];

    /**
     * @return array{columns: array<string, array>, filters: list, computed: list, skipped: list, files: list}
     */
    public function parseDirectory(string $sourcePath): array
    {
        $dir = $this->resolveDataTablesDir($sourcePath);
        $result = [
            'columns' => [],
            'filters' => [],
            'computed' => [],
            'skipped' => [],
            'files' => [],
        ];
        if ($dir === null) {
            return $result;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*DataTable.php') ?: [] as $file) {
            $partial = $this->parseFile($file);
            $result['files'][] = basename($file);
            foreach ($partial['columns'] as $handle => $meta) {
                $result['columns'][$handle] = $meta;
            }
            foreach ($partial['filters'] as $f) {
                $result['filters'][] = $f;
            }
            foreach ($partial['computed'] as $c) {
                $result['computed'][] = $c;
            }
            foreach ($partial['skipped'] as $s) {
                $result['skipped'][] = $s;
            }
        }

        return $result;
    }

    /**
     * @return array{columns: array<string, array>, filters: list, computed: list, skipped: list, file: ?string, get_columns?: list}
     */
    public function parseForSlice(string $sourcePath, string $sliceName): array
    {
        $empty = ['columns' => [], 'filters' => [], 'computed' => [], 'skipped' => [], 'file' => null, 'get_columns' => []];
        $file = $this->resolveDataTableFile($sourcePath, $sliceName);
        if ($file === null) {
            return $empty;
        }
        $parsed = $this->parseFile($file);
        $parsed['file'] = basename($file);

        return $parsed;
    }

    public function resolveDataTablesDir(string $sourcePath): ?string
    {
        $base = rtrim($sourcePath, '/\\');
        foreach ([
            $base.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'DataTables',
            $base.DIRECTORY_SEPARATOR.'DataTables',
            $base,
        ] as $dir) {
            if (is_dir($dir) && glob($dir.DIRECTORY_SEPARATOR.'*DataTable.php')) {
                return $dir;
            }
        }

        return null;
    }

    public function resolveDataTableFile(string $sourcePath, string $sliceName): ?string
    {
        $candidates = [];
        $alias = $this->sliceAliases[$sliceName] ?? null;
        if ($alias) {
            $candidates[] = $alias.'.php';
        }
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $sliceName)));
        $candidates[] = $studly.'DataTable.php';
        if (! str_ends_with($studly, 's')) {
            $candidates[] = $studly.'sDataTable.php';
        }
        if (str_ends_with($studly, 'Details')) {
            $baseStem = substr($studly, 0, -7);
            $candidates[] = $baseStem.'DataTable.php';
            $candidates[] = $baseStem.'sDataTable.php';
        } elseif (str_ends_with($studly, 'Detail')) {
            $baseStem = substr($studly, 0, -6);
            $candidates[] = $baseStem.'DataTable.php';
            $candidates[] = $baseStem.'sDataTable.php';
        }

        $base = rtrim($sourcePath, '/\\');
        $roots = [
            $base.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'DataTables',
            $base.DIRECTORY_SEPARATOR.'DataTables',
            $base,
        ];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach ($candidates as $name) {
                $path = $root.DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * @return array{columns: array<string, array>, filters: list, computed: list, skipped: list, get_columns: list}
     */
    public function parseFile(string $filePath): array
    {
        $src = @file_get_contents($filePath);
        if ($src === false || $src === '') {
            return ['columns' => [], 'filters' => [], 'computed' => [], 'skipped' => [], 'get_columns' => []];
        }

        $columns = [];
        $filters = [];
        $computed = [];
        $skipped = [];
        $get_columns = $this->parseGetColumns($src);

        if (preg_match_all('/->\s*editColumn\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*,/s', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $handle = $match[0];
                $body = $this->extractClosureBody($src, $match[1]);
                $classification = $this->classifyBody($handle, $body, 'editColumn');
                if ($classification === null) {
                    $skipped[] = ['handle' => $handle, 'reason' => 'unclassified_editColumn'];

                    continue;
                }
                $columns[$handle] = $classification;
            }
        }

        if (preg_match_all('/->\s*addColumn\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*,/s', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $match) {
                $handle = $match[0];
                if (in_array($handle, ['action', 'check', 'checkbox'], true)) {
                    continue;
                }
                $body = $this->extractClosureBody($src, $match[1]);
                $computed[] = $handle;
                $classification = $this->classifyBody($handle, $body, 'addColumn');
                if ($classification === null) {
                    $skipped[] = ['handle' => $handle, 'reason' => 'unclassified_addColumn'];

                    continue;
                }
                if (! isset($columns[$handle]) || ($columns[$handle]['confidence'] ?? '') === 'low') {
                    $columns[$handle] = $classification;
                }
            }
        }

        if (preg_match_all('/->\s*filterColumn\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*,/s', $src, $m)) {
            foreach ($m[1] as $handle) {
                $filters[] = ['handle' => $handle, 'source' => 'filterColumn'];
            }
        }

        if (preg_match('/->\s*rawColumns\s*\(\s*(?:array_merge\s*\(\s*)?\[([^\]]+)\]/s', $src, $rm)) {
            if (preg_match_all('/[\'"]([a-zA-Z0-9_]+)[\'"]/', $rm[1], $rm2)) {
                foreach ($rm2[1] as $handle) {
                    if (isset($columns[$handle])) {
                        $columns[$handle]['confidence'] = 'high';
                        $columns[$handle]['html'] = true;
                    } elseif (! in_array($handle, ['action', 'check'], true)) {
                        $nameGuess = $this->classifyByHandleName($handle);
                        if ($nameGuess !== null) {
                            $columns[$handle] = array_merge($nameGuess, [
                                'source' => 'rawColumns+name',
                                'confidence' => 'medium',
                            ]);
                        }
                    }
                }
            }
        }

        return compact('columns', 'filters', 'computed', 'skipped', 'get_columns');
    }

    /**
     * Extract columns defined in getColumns() method of Yajra DataTable.
     *
     * @return list<array{handle: string, visible: bool, title: ?string, raw: string}>
     */
    public function parseGetColumns(string $src): array
    {
        if (! preg_match('/(?:public|protected|private)?\s*function\s+getColumns\s*\([^\)]*\)\s*[:\w\s]*\{/i', $src, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $startPos = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($src);
        $body = '';
        for ($i = $startPos; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $body = substr($src, $startPos, $i - $startPos);
                    break;
                }
            }
        }

        if ($body === '') {
            return [];
        }

        // Strip single line comments so commented-out columns are ignored
        $body = preg_replace('~//.*?$~m', '', $body);

        $columns = [];
        $seen = [];

        // 1. Associative array entries: e.g. __('app.name') => ['data' => 'name', ...]
        if (preg_match_all('/(?:(?:__\(|[\'"])(.*?)(?:\)|[\'"])\s*=>\s*)?\[([^\]]+)\]/s', $body, $arrayMatches, PREG_SET_ORDER)) {
            foreach ($arrayMatches as $match) {
                $inner = $match[2];

                $dataHandle = null;
                if (preg_match('/[\'"]data[\'"]\s*=>\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $inner, $dm)) {
                    $dataHandle = $dm[1];
                } elseif (preg_match('/[\'"]name[\'"]\s*=>\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $inner, $nm)) {
                    $dataHandle = $nm[1];
                }

                if (! $dataHandle || isset($seen[$dataHandle])) {
                    continue;
                }

                if (in_array($dataHandle, ['DT_RowIndex', 'check', 'checkbox', 'action'], true)) {
                    continue;
                }

                $visible = true;
                if (preg_match('/[\'"]visible[\'"]\s*=>\s*([^,\n\r]+)/', $inner, $vm)) {
                    $val = trim($vm[1]);
                    if ($val === 'false' || $val === '0') {
                        $visible = false;
                    }
                }

                $title = null;
                if (preg_match('/[\'"]title[\'"]\s*=>\s*(?:__\([\'"]([^\'"]+)[\'"]\)|[\'"]([^\'"]+)[\'"])/', $inner, $tm)) {
                    $titleRaw = $tm[1] ?: $tm[2];
                    $title = $this->formatTitle($titleRaw);
                }

                $seen[$dataHandle] = true;
                $columns[] = [
                    'handle' => $dataHandle,
                    'visible' => $visible,
                    'title' => $title,
                    'raw' => trim($inner),
                ];
            }
        }

        // 2. Column::make / Column::computed
        if (preg_match_all('/Column::(make|computed)\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"](?:\s*,\s*(?:__\([\'"]([^\'"]+)[\'"]\)|[\'"]([^\'"]+)[\'"]))?\s*\)/', $body, $colMatches, PREG_SET_ORDER)) {
            foreach ($colMatches as $cm) {
                $dataHandle = $cm[2];
                if (in_array($dataHandle, ['DT_RowIndex', 'check', 'checkbox', 'action'], true) || isset($seen[$dataHandle])) {
                    continue;
                }
                $titleRaw = $cm[3] ?: ($cm[4] ?? null);
                $title = $titleRaw ? $this->formatTitle($titleRaw) : null;

                $seen[$dataHandle] = true;
                $columns[] = [
                    'handle' => $dataHandle,
                    'visible' => true,
                    'title' => $title,
                    'raw' => $cm[0],
                ];
            }
        }

        return $columns;
    }

    protected function formatTitle(string $raw): string
    {
        $base = str_contains($raw, '.') ? basename(str_replace('.', '/', $raw)) : $raw;
        $headline = Str::headline($base);

        // Normalize acronyms
        return preg_replace('/\bId\b/', 'ID', $headline);
    }

    protected function extractClosureBody(string $src, int $nameOffset): string
    {
        $slice = substr($src, $nameOffset, 8000);
        $fnPos = stripos($slice, 'function');
        if ($fnPos === false) {
            return substr($slice, 0, 500);
        }
        $fromFn = substr($slice, $fnPos);
        $bracePos = strpos($fromFn, '{');
        if ($bracePos === false) {
            return substr($fromFn, 0, 500);
        }
        $depth = 0;
        $len = strlen($fromFn);
        for ($i = $bracePos; $i < $len && $i < 6000; $i++) {
            $ch = $fromFn[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($fromFn, $bracePos, $i - $bracePos + 1);
                }
            }
        }

        return substr($fromFn, $bracePos, 1500);
    }

    /**
     * @return array{handle: string, formatter: string, source: string, confidence: string, inline_edit?: bool}|null
     */
    protected function classifyBody(string $handle, string $body, string $via): ?array
    {
        $lower = strtolower($body);
        $h = strtolower($handle);

        if (
            (str_contains($lower, 'currency_format') || str_contains($lower, 'amountpaid') || str_contains($lower, 'amountdue'))
            && (str_contains($lower, 'paid') || str_contains($lower, 'amount_paid'))
        ) {
            return $this->hint($handle, 'money_breakdown', $via, 'high', [
                'breakdown' => [
                    'total' => in_array($h, ['total', 'amount', 'grand_total'], true) ? $handle : 'total',
                    'paid' => 'paid_amount',
                    'unpaid' => 'due_amount',
                ],
            ]);
        }

        if (
            str_contains($lower, 'progress-bar')
            || str_contains($lower, 'progressbar')
            || str_contains($lower, 'completion_percent')
            || (str_contains($lower, 'progress') && str_contains($lower, 'width'))
        ) {
            return $this->hint($handle, 'progress_bar', $via, 'high');
        }

        if (
            (str_contains($lower, 'text-danger') || str_contains($lower, 'ispast') || str_contains($lower, 'is_past'))
            && (str_contains($lower, 'due') || str_contains($lower, 'deadline') || str_contains($h, 'due') || str_contains($h, 'deadline') || str_contains($lower, 'date'))
        ) {
            return $this->hint($handle, 'date_overdue', $via, 'high');
        }

        if (
            str_contains($lower, '<img') || str_contains($lower, 'avatar') || str_contains($lower, 'user-image') || str_contains($lower, 'userimage')
            || str_contains($lower, 'components.employee') || str_contains($lower, 'components.user')
        ) {
            if (
                str_contains($h, 'user') || str_contains($h, 'member') || str_contains($h, 'assignee')
                || str_contains($h, 'employee') || str_contains($lower, 'member') || str_contains($lower, 'user')
                || $h === 'name' || $h === 'full_name'
            ) {
                return $this->hint($handle, 'composite_user', $via, 'high');
            }
        }

        // Inline editable select (e.g. role assignment dropdown in employee table)
        if (
            str_contains($lower, 'assign_role')
            || (str_contains($lower, '<select') && (str_contains($lower, 'select-picker') || str_contains($lower, 'form-control')))
        ) {
            return $this->hint($handle, 'text', $via, 'high', ['inline_edit' => true]);
        }

        // Title/heading with badges appended (private, pin, labels, timer) → text_with_badge
        // Must run BEFORE status_pill: label_color/badge HTML on a title is not a status column.
        $titleHandles = ['heading', 'title', 'name', 'subject', 'project_name', 'task', 'label'];
        $isTitleHandle = in_array($h, $titleHandles, true) || str_ends_with($h, '_name') && ! str_contains($h, 'status');
        if ($isTitleHandle && (str_contains($lower, 'badge') || str_contains($lower, 'priority') || str_contains($lower, 'label_color'))) {
            $badgeFrom = 'priority';
            if (str_contains($lower, 'label') && ! str_contains($lower, 'priority')) {
                $badgeFrom = 'labels';
            }

            return $this->hint($handle, 'text_with_badge', $via, 'high', ['badge_from' => $badgeFrom]);
        }

        // Status column: the cell *is* a state (select, board column, status name) — not title chrome
        $isStatusHandle = str_contains($h, 'status') || in_array($h, ['board_column', 'state', 'stage'], true);
        if (
            str_contains($lower, 'change-status')
            || str_contains($lower, 'status_name')
            || str_contains($lower, 'fa-circle')
            || str_contains($lower, 'text-light-green')
            || str_contains($lower, 'text-red')
            || ($isStatusHandle && (
                str_contains($lower, 'label_color')
                || str_contains($lower, 'board_column')
                || str_contains($lower, 'badge')
                || str_contains($lower, 'text-dark-green')
                || str_contains($lower, 'select-picker')
            ))
            || (str_contains($lower, 'board_column') && $isStatusHandle)
        ) {
            return $this->hint($handle, 'status_pill', $via, 'high');
        }

        if (
            str_contains($h, 'short_code') || str_contains($h, 'invoice_number') || $h === 'code'
            || str_ends_with($h, '_code') || str_ends_with($h, '_id')
            || (str_contains($lower, 'route(') && (str_contains($lower, 'show') || str_contains($lower, 'view')))
        ) {
            if (! str_contains($lower, '<select') && ! str_contains($lower, '<img') && ! str_contains($lower, 'components.employee')) {
                return $this->hint($handle, 'code_badge', $via, 'high');
            }
        }

        return $this->classifyByHandleName($handle);
    }

    /**
     * @return array{handle: string, formatter: string, source: string, confidence: string}|null
     */
    protected function classifyByHandleName(string $handle): ?array
    {
        $h = strtolower($handle);
        if (in_array($h, ['due_date', 'deadline', 'due_on'], true) || str_ends_with($h, '_due')) {
            return $this->hint($handle, 'date_overdue', 'name', 'low');
        }
        if (str_contains($h, 'progress') || str_contains($h, 'completion')) {
            return $this->hint($handle, 'progress_bar', 'name', 'low');
        }
        if ($h === 'status' || str_ends_with($h, '_status') || $h === 'board_column') {
            return $this->hint($handle, 'status_pill', 'name', 'low');
        }
        if (in_array($h, ['total', 'amount', 'grand_total'], true)) {
            return $this->hint($handle, 'money_breakdown', 'name', 'low');
        }
        if (str_contains($h, 'user') || str_contains($h, 'member') || str_contains($h, 'assignee')) {
            return $this->hint($handle, 'composite_user', 'name', 'low');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{handle: string, formatter: string, source: string, confidence: string}
     */
    protected function hint(string $handle, string $formatter, string $source, string $confidence, array $extra = []): array
    {
        if (! in_array($formatter, self::FORMATTERS, true)) {
            $formatter = 'text';
        }

        return array_merge([
            'handle' => $handle,
            'formatter' => $formatter,
            'source' => $source,
            'confidence' => $confidence,
        ], $extra);
    }

    /**
     * Merge DataTable findings into Phase-1 ui config. High/medium confidence overrides name guesses.
     *
     * @param  array<string, mixed>  $ui
     * @param  array<string, mixed>  $hints
     * @return array<string, mixed>
     */
    public function mergeIntoUi(array $ui, array $hints): array
    {
        $hintCols = $hints['columns'] ?? [];
        $getCols = array_values(array_filter($hints['get_columns'] ?? [], fn ($c) => ($c['visible'] ?? true) === true));

        $ui['directory'] = $ui['directory'] ?? [];
        $ui['directory']['columns'] = $ui['directory']['columns'] ?? [];

        // If getColumns() discovered visible columns, use them as the primary definition of the directory grid
        if (! empty($getCols)) {
            $newColumns = [];
            $inlineEdits = $ui['directory']['inline_edit'] ?? [];
            foreach ($getCols as $gc) {
                $handle = $gc['handle'];
                $meta = $hintCols[$handle] ?? $this->classifyByHandleName($handle) ?? ['formatter' => 'text'];
                $formatter = $meta['formatter'] ?? 'text';
                $label = $gc['title'] ?? Str::headline($handle);
                $isInline = ($meta['inline_edit'] ?? false) === true;
                if ($isInline && ! in_array($handle, $inlineEdits, true)) {
                    $inlineEdits[] = $handle;
                }

                $colDef = [
                    'handle' => $handle,
                    'key' => $handle,
                    'label' => $label,
                    'formatter' => $formatter,
                    'type' => $formatter,
                    'sortable' => true,
                    'inferred_from' => $meta['source'] ?? 'getColumns',
                ];
                if (! empty($meta['breakdown'])) {
                    $colDef['breakdown'] = $meta['breakdown'];
                }
                if (! empty($meta['badge_from'])) {
                    $colDef['badge_from'] = $meta['badge_from'];
                }
                if ($isInline) {
                    $colDef['inline_edit'] = true;
                }
                $newColumns[] = $colDef;
            }
            $ui['directory']['columns'] = $newColumns;
            $ui['directory']['inline_edit'] = $inlineEdits;
        } elseif (! empty($hintCols)) {
            $byHandle = [];
            foreach ($ui['directory']['columns'] as $i => $col) {
                $h = $col['handle'] ?? $col['key'] ?? null;
                if ($h) {
                    $byHandle[$h] = $i;
                }
            }

            foreach ($hintCols as $handle => $meta) {
                $formatter = $meta['formatter'] ?? null;
                if (! $formatter || ! in_array($formatter, self::FORMATTERS, true)) {
                    continue;
                }
                if (isset($byHandle[$handle])) {
                    $i = $byHandle[$handle];
                    $ui['directory']['columns'][$i]['formatter'] = $formatter;
                    $ui['directory']['columns'][$i]['type'] = $formatter;
                    $ui['directory']['columns'][$i]['inferred_from'] = $meta['source'] ?? 'datatable';
                    if (! empty($meta['breakdown'])) {
                        $ui['directory']['columns'][$i]['breakdown'] = $meta['breakdown'];
                    }
                    if (! empty($meta['badge_from'])) {
                        $ui['directory']['columns'][$i]['badge_from'] = $meta['badge_from'];
                    }
                } else {
                    $ui['directory']['columns'][] = [
                        'handle' => $handle,
                        'key' => $handle,
                        'label' => Str::headline($handle),
                        'formatter' => $formatter,
                        'type' => $formatter,
                        'sortable' => false,
                        'inferred_from' => $meta['source'] ?? 'datatable',
                    ];
                }
            }
        }

        foreach ($hints['filters'] ?? [] as $f) {
            $h = $f['handle'] ?? null;
            if (! $h) {
                continue;
            }
            $exists = false;
            foreach ($ui['directory']['sidebar_filters'] ?? [] as $sf) {
                if (($sf['handle'] ?? '') === $h) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $ui['directory']['sidebar_filters'] = $ui['directory']['sidebar_filters'] ?? [];
                $ui['directory']['sidebar_filters'][] = [
                    'handle' => $h,
                    'label' => Str::headline($h),
                    'type' => 'text',
                    'inferred_from' => 'filterColumn',
                ];
            }
        }

        return $ui;
    }
}
