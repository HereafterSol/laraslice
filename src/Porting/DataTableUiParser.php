<?php

namespace LaraSlice\Porting;

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
     * @return array{columns: array<string, array>, filters: list, computed: list, skipped: list, file: ?string}
     */
    public function parseForSlice(string $sourcePath, string $sliceName): array
    {
        $empty = ['columns' => [], 'filters' => [], 'computed' => [], 'skipped' => [], 'file' => null];
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
        $base = rtrim($sourcePath, "/\\");
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

        $base = rtrim($sourcePath, "/\\");
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
     * @return array{columns: array<string, array>, filters: list, computed: list, skipped: list}
     */
    public function parseFile(string $filePath): array
    {
        $src = @file_get_contents($filePath);
        if ($src === false || $src === '') {
            return ['columns' => [], 'filters' => [], 'computed' => [], 'skipped' => []];
        }

        $columns = [];
        $filters = [];
        $computed = [];
        $skipped = [];

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

        return compact('columns', 'filters', 'computed', 'skipped');
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
     * @return array{handle: string, formatter: string, source: string, confidence: string}|null
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
        ) {
            if (
                str_contains($h, 'user') || str_contains($h, 'member') || str_contains($h, 'assignee')
                || str_contains($h, 'employee') || str_contains($lower, 'member') || str_contains($lower, 'user')
            ) {
                return $this->hint($handle, 'composite_user', $via, 'high');
            }
        }

        if (
            str_contains($lower, 'change-status')
            || str_contains($lower, 'label_color')
            || str_contains($lower, 'fa-circle')
            || str_contains($lower, 'board_column')
            || str_contains($lower, 'status_name')
            || (
                str_contains($h, 'status')
                && (str_contains($lower, 'badge') || str_contains($lower, 'text-red') || str_contains($lower, 'text-success') || str_contains($lower, 'text-dark-green'))
            )
        ) {
            return $this->hint($handle, 'status_pill', $via, 'high');
        }

        if ((str_contains($h, 'heading') || $h === 'name' || $h === 'title') && (str_contains($lower, 'priority') || str_contains($lower, 'badge'))) {
            return $this->hint($handle, 'text_with_badge', $via, 'medium', ['badge_from' => 'priority']);
        }

        if (str_contains($h, 'short_code') || str_contains($h, 'invoice_number') || $h === 'code') {
            return $this->hint($handle, 'code_badge', $via, 'medium');
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
        if ($hintCols === []) {
            return $ui;
        }

        $ui['directory'] = $ui['directory'] ?? [];
        $ui['directory']['columns'] = $ui['directory']['columns'] ?? [];

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
                    'label' => \Illuminate\Support\Str::headline($handle),
                    'formatter' => $formatter,
                    'type' => $formatter,
                    'sortable' => false,
                    'inferred_from' => $meta['source'] ?? 'datatable',
                ];
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
                    'label' => \Illuminate\Support\Str::headline($h),
                    'type' => 'text',
                    'inferred_from' => 'filterColumn',
                ];
            }
        }

        return $ui;
    }
}
