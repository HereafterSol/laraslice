<?php

namespace LaraSlice\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Builds plain rows for the BlatUI <x-ui.data-table> component, which renders every
 * cell as text on the client. Each column key is read with data_get(), so models,
 * listing objects and arrays all work, and values are flattened to display strings.
 * Automatically resolves foreign keys (e.g. invoice_id, company_id) to parent display names.
 */
class DataTableRows
{
    /**
     * Cache for resolved table display columns: table_name => label_column
     * @var array<string, string|null>
     */
    protected static array $labelColumnCache = [];

    /**
     * @param  iterable<mixed>  $items
     * @param  array<int, array{key: string}>  $columns
     * @param  (callable(mixed): array<string, mixed>)|null  $extra  Extra non-column keys per row (e.g. edit_url)
     * @return list<array<string, mixed>>
     */
    public static function from(iterable $items, array $columns, ?callable $extra = null): array
    {
        $rows = [];
        $itemsArray = is_array($items) ? $items : iterator_to_array($items);

        // Pre-resolve foreign key lookups in a single batch query per FK column
        $fkMaps = static::resolveForeignKeysBatch($itemsArray, $columns);

        foreach ($itemsArray as $item) {
            $row = [];
            foreach ($columns as $column) {
                $key = $column['key'];
                $val = data_get($item, $key);
                $row[$key . '__raw'] = is_scalar($val) ? (string)$val : '';

                // 1. If column has a custom callback formatter.
                // Strings are formatter NAMES, never callables: a
                // name that happens to match a PHP function ('date')
                // must not be invoked as one.
                if (isset($column['formatter']) && ! is_string($column['formatter']) && is_callable($column['formatter'])) {
                    $row[$key] = $column['formatter']($val, $item);
                    continue;
                }

                // 2. Named string formatters
                $fmt = $column['formatter'] ?? null;
                if (is_string($fmt) && $fmt !== 'text') {
                    $row[$key] = match ($fmt) {
                        'code_badge' => static::codeBadge($val),
                        'composite_user' => static::resolveCompositeUser($item, $key, $val, $column),
                        'user_stack' => static::resolveUserStack($item, $key, $val, $column),
                        'status_pill' => static::statusPill((string) ($val ?: data_get($item, 'user.status') ?: 'active')),
                        'money_breakdown' => static::moneyBreakdown(
                            $val,
                            data_get($item, $column['breakdown']['paid'] ?? 'paid_amount', data_get($item, 'paid', 0)),
                            data_get($item, 'currency', data_get($item, 'currency_id', 'PKR')),
                            data_get($item, $column['breakdown']['unpaid'] ?? 'due_amount', null)
                        ),
                        'text_with_badge' => static::textWithBadge(
                            $val,
                            data_get($item, $column['badge_from'] ?? 'priority'),
                            (string) ($column['badge_from'] ?? 'priority')
                        ),
                        'progress_bar' => static::progressBar((float) $val),
                        'timer' => static::resolveTimer($item),
                        'date' => static::formatDateValue($val, 'date'),
                        'date_time' => static::formatDateValue($val, 'datetime'),
                        'date_overdue' => static::dateOverdue($val),
                        default => static::display($val),
                    };
                    continue;
                }

                // 3a. Declared option labels (enum-style fields) beat raw values
                if (! empty($column['options']) && is_array($column['options']) && is_scalar($val)
                    && isset($column['options'][(string) $val])) {
                    $row[$key] = $column['options'][(string) $val];
                // 3b. Foreign key lookup batch mapping
                } elseif (isset($fkMaps[$key]) && is_scalar($val) && isset($fkMaps[$key][$val])) {
                    $row[$key] = (string) $fkMaps[$key][$val];
                } elseif (str_ends_with($key, '_id') && $key !== 'id' && $item instanceof Model) {
                    $relName = Str::camel(substr($key, 0, -3));
                    if ($item->relationLoaded($relName) && ($relModel = $item->getRelation($relName))) {
                        $row[$key] = static::modelDisplayName($relModel);
                    } else {
                        $row[$key] = static::display($val);
                    }
                } elseif ($val instanceof Model) {
                    $row[$key] = static::modelDisplayName($val);
                } elseif (in_array($column['field_type'] ?? null, ['date', 'datetime', 'timestamp'], true)
                    && (is_string($val) || $val instanceof \DateTimeInterface)) {
                    $row[$key] = static::formatDateValue($val, (string) $column['field_type']);
                } else {
                    $row[$key] = static::display($val);
                }
            }

            // Every row carries the record's key under a reserved
            // name so row-level JS (inline updates, transitions)
            // can address the record even when `id` is not one of
            // the declared columns.
            if ($item instanceof Model) {
                $row['__id'] = $item->getKey();
            } elseif (is_object($item) && isset($item->id)) {
                $row['__id'] = $item->id;
            }

            if ($extra !== null) {
                $row = array_merge($row, $extra($item));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Render a rich composite user avatar + name + subtitle cell with zero extra dependencies.
     */
    /**
     * Render an enterprise monospace code badge (e.g. #EMP-01, INV-2024).
     */
    public static function codeBadge(mixed $val): string
    {
        $str = htmlspecialchars((string) ($val ?? '—'), ENT_QUOTES, 'UTF-8');
        return '<span class="inline-flex items-center px-2 py-0.5 rounded font-mono text-[11px] font-bold bg-muted/80 text-foreground border border-border/60">' . $str . '</span>';
    }

    /**
     * Gracefully resolve composite user avatar, name, and subtitle from item.
     */
    public static function resolveCompositeUser(mixed $item, string $key, mixed $val, array $column): string
    {
        $name = data_get($item, 'user.name')
            ?? data_get($item, 'name')
            ?? (is_object($val) ? ($val->name ?? null) : null)
            ?? (is_string($val) && !empty($val) && !is_numeric($val) ? $val : null);

        // If name resolved to a raw foreign key number (e.g. user_id = 14)
        if (empty($name) || is_numeric($name)) {
            $lookupId = is_numeric($val) ? (int) $val : (is_numeric(data_get($item, 'user_id')) ? (int) data_get($item, 'user_id') : null);
            if ($lookupId) {
                try {
                    $u = DB::table('users')->where('id', $lookupId)->first(['name', 'email']);
                    if ($u) {
                        $name = $u->name;
                        if (!data_get($item, 'email')) {
                            $item->email = $u->email;
                        }
                    }
                } catch (\Throwable $e) {}
            }
        }

        $name = $name ?: 'Unknown';

        $subtitle = data_get($item, 'designation.name')
            ?? data_get($item, 'designation_name')
            ?? data_get($item, 'designation')
            ?? data_get($item, 'user.email')
            ?? data_get($item, 'email');

        if (is_numeric($subtitle) || empty($subtitle)) {
            $desigId = is_numeric(data_get($item, 'designation_id')) ? (int) data_get($item, 'designation_id') : null;
            if ($desigId) {
                try {
                    $subtitle = DB::table('designations')->where('id', $desigId)->value('name');
                } catch (\Throwable $e) {}
            }
        }

        $avatarUrl = data_get($item, 'user.avatar_url')
            ?? data_get($item, 'avatar_url')
            ?? data_get($item, 'user.image');

        $viewUrl = $column['view_url'] ?? (isset($item->id) && \Illuminate\Support\Facades\Route::has('employees.show') ? route('employees.show', $item->id) : null);

        return static::compositeUser($name, $subtitle, $avatarUrl, $viewUrl);
    }
    /**
     * Resolve an avatar stack (assignees / members) from a
     * collection-ish value: models, arrays, id lists, or a
     * comma-separated name string. Ids are batch-resolved
     * against the users table when possible.
     */
    /**
     * List timer cell (P11 extension): the listing BO exposes
     * timer_start_url / timer_stop_url / timer_running (+ an
     * optional timer_elapsed label); the cell renders the actual
     * start/stop forms so a timer can be driven from the list,
     * exactly like the source system's timer column.
     */
    public static function resolveTimer(mixed $item): string
    {
        $startUrl = data_get($item, 'timer_start_url');
        $stopUrl = data_get($item, 'timer_stop_url');
        $running = (bool) data_get($item, 'timer_running');
        $token = csrf_token();
        if ($running && is_string($stopUrl) && $stopUrl !== '') {
            $elapsed = data_get($item, 'timer_elapsed');
            $label = is_string($elapsed) && $elapsed !== '' ? 'Stop · '.e($elapsed) : 'Stop';

            return '<form method="POST" action="'.e($stopUrl).'" class="inline"><input type="hidden" name="_token" value="'.e($token).'" />'
                .'<button type="submit" class="inline-flex items-center gap-1 rounded-md bg-destructive/10 px-2 py-1 text-[11px] font-semibold text-destructive hover:bg-destructive/20 cursor-pointer">'.$label.'</button></form>';
        }
        if (is_string($startUrl) && $startUrl !== '') {
            return '<form method="POST" action="'.e($startUrl).'" class="inline"><input type="hidden" name="_token" value="'.e($token).'" />'
                .'<button type="submit" class="inline-flex items-center gap-1 rounded-md bg-primary/10 px-2 py-1 text-[11px] font-semibold text-primary hover:bg-primary/20 cursor-pointer">Start</button></form>';
        }

        return '—';
    }

    public static function resolveUserStack(mixed $item, string $key, mixed $val, array $column): string
    {
        $raw = $val;
        if ($raw === null || $raw === '') {
            $raw = data_get($item, $column['relation'] ?? $key);
        }

        $entries = [];
        if ($raw instanceof \Illuminate\Support\Collection) {
            $raw = $raw->all();
        }
        if (is_string($raw) && str_contains($raw, ',')) {
            $raw = array_map('trim', explode(',', $raw));
        }
        if (is_scalar($raw) && $raw !== '') {
            $raw = [$raw];
        }
        if (is_array($raw)) {
            $ids = [];
            foreach ($raw as $entry) {
                if (is_numeric($entry)) {
                    $ids[] = (int) $entry;
                }
            }
            $byId = [];
            if ($ids !== []) {
                try {
                    foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'name', 'email']) as $u) {
                        $byId[(int) $u->id] = ['name' => $u->name, 'email' => $u->email];
                    }
                } catch (\Throwable $e) {
                }
            }
            foreach ($raw as $entry) {
                if (is_numeric($entry)) {
                    $found = $byId[(int) $entry] ?? null;
                    $entries[] = ['name' => $found['name'] ?? ('User #'.$entry), 'email' => $found['email'] ?? null, 'avatar_url' => null];
                } elseif (is_string($entry)) {
                    $entries[] = ['name' => $entry, 'email' => null, 'avatar_url' => null];
                } else {
                    $entries[] = [
                        'name' => data_get($entry, 'name') ?? 'Unknown',
                        'email' => data_get($entry, 'email'),
                        'avatar_url' => data_get($entry, 'avatar_url') ?? data_get($entry, 'image'),
                    ];
                }
            }
        }

        return static::userStack($entries);
    }

    /**
     * Render up to four overlapping avatar circles plus a +N
     * overflow chip; the full name list rides the title attr.
     */
    public static function userStack(array $entries): string
    {
        if ($entries === []) {
            return '<span class="text-muted-foreground">—</span>';
        }

        $names = array_map(fn ($e) => (string) ($e['name'] ?? 'Unknown'), $entries);
        $title = htmlspecialchars(implode(', ', $names), ENT_QUOTES, 'UTF-8');
        $shown = array_slice($entries, 0, 4);
        $extra = count($entries) - count($shown);

        $html = '<div class="flex items-center -space-x-2" title="'.$title.'">';
        foreach ($shown as $entry) {
            $name = htmlspecialchars((string) ($entry['name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
            $avatarUrl = $entry['avatar_url'] ?? null;
            if ($avatarUrl) {
                $html .= '<img src="'.htmlspecialchars((string) $avatarUrl, ENT_QUOTES, 'UTF-8').'" alt="'.$name.'" class="size-8 rounded-full object-cover shrink-0 ring-2 ring-card relative hover:z-10" />';
            } else {
                $initials = static::initialsOf((string) ($entry['name'] ?? '?'));
                $html .= '<div class="size-8 rounded-full bg-primary/10 text-primary font-semibold text-[11px] flex items-center justify-center shrink-0 ring-2 ring-card relative hover:z-10">'.$initials.'</div>';
            }
        }
        if ($extra > 0) {
            $html .= '<div class="size-8 rounded-full bg-muted text-muted-foreground font-semibold text-[11px] flex items-center justify-center shrink-0 ring-2 ring-card relative hover:z-10">+'. $extra .'</div>';
        }

        return $html.'</div>';
    }

    /** First + last word initials (e.g. 'Oswald Lemke' -> 'OL'). */
    public static function initialsOf(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = strtoupper(substr($parts[0] ?? '?', 0, 1));
        $last = count($parts) > 1 ? strtoupper(substr((string) end($parts), 0, 1)) : strtoupper(substr($parts[0] ?? '?', 1, 1));

        return $first.$last;
    }

    public static function compositeUser(?string $name, ?string $subtitle = null, ?string $avatarUrl = null, ?string $viewUrl = null): string
    {
        $name = htmlspecialchars($name ?: 'Unknown', ENT_QUOTES, 'UTF-8');
        $subtitle = $subtitle ? htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') : '';
        $initials = static::initialsOf($name);

        $avatarHtml = $avatarUrl
            ? '<img src="'.htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8').'" alt="'.$name.'" class="size-8 rounded-full object-cover shrink-0 ring-1 ring-border" />'
            : '<div class="size-8 rounded-full bg-primary/10 text-primary font-semibold text-xs flex items-center justify-center shrink-0 ring-1 ring-border">'.$initials.'</div>';

        $titleHtml = $viewUrl
            ? '<a href="'.htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8').'" class="font-semibold text-foreground hover:text-primary transition-colors block truncate max-w-[200px]">'.$name.'</a>'
            : '<span class="font-semibold text-foreground block truncate max-w-[200px]">'.$name.'</span>';

        $subHtml = $subtitle ? '<span class="text-xs text-muted-foreground block truncate max-w-[200px]">'.$subtitle.'</span>' : '';

        return '<div class="flex items-center gap-2.5 py-0.5">' . $avatarHtml . '<div class="min-w-0">' . $titleHtml . $subHtml . '</div></div>';
    }

    /**
     * Render an enterprise semantic status pill with pulsing/colored dot and rounded pill background.
     */
    public static function statusPill(string $status, ?string $label = null, ?string $color = null): string
    {
        $statusKey = strtolower(trim($status));
        $label = htmlspecialchars($label ?: ucwords(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8');

        $colorMap = [
            'active' => 'emerald',
            'enabled' => 'emerald',
            'paid' => 'emerald',
            'completed' => 'emerald',
            'approved' => 'emerald',
            'present' => 'emerald',
            'pending' => 'amber',
            'in_progress' => 'amber',
            'partially_paid' => 'amber',
            'waiting' => 'amber',
            'inactive' => 'rose',
            'disabled' => 'rose',
            'unpaid' => 'rose',
            'cancelled' => 'rose',
            'rejected' => 'rose',
            'absent' => 'rose',
            'overdue' => 'rose',
            'draft' => 'slate',
            'archived' => 'slate',
            'holiday' => 'sky',
            'leave' => 'sky',
        ];

        $resolved = $color ?: ($colorMap[$statusKey] ?? 'indigo');

        $dotColor = match ($resolved) {
            'emerald', 'green', 'success' => 'bg-emerald-500',
            'rose', 'red', 'danger' => 'bg-rose-500',
            'amber', 'yellow', 'warning' => 'bg-amber-500',
            'sky', 'blue', 'info' => 'bg-sky-500',
            'purple' => 'bg-purple-500',
            'indigo' => 'bg-indigo-500',
            default => 'bg-slate-400',
        };

        $pillColor = match ($resolved) {
            'emerald', 'green', 'success' => 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800',
            'rose', 'red', 'danger' => 'bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border-rose-200 dark:border-rose-800',
            'amber', 'yellow', 'warning' => 'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800',
            'sky', 'blue', 'info' => 'bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-400 border-sky-200 dark:border-sky-800',
            'purple' => 'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-400 border-purple-200 dark:border-purple-800',
            'indigo' => 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400 border-indigo-200 dark:border-indigo-800',
            default => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700',
        };

        return '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold border ' . $pillColor . '"><span class="size-1.5 rounded-full ' . $dotColor . '"></span>' . $label . '</span>';
    }

    /**
     * Render formatted financial total with paid and due breakdown.
     */
    public static function moneyBreakdown(float|int|string|null $total, float|int|string $paid = 0, float|int|string|null $currency = 'PKR', float|int|string|null $due = null): string
    {
        // Real ported data has null amounts (drafts, hollow imports); a
        // formatter must render the empty placeholder, never crash the list.
        if ($total === null || $total === '') {
            return '\u2014';
        }
        $numTotal = (float) $total;
        $numPaid = (float) $paid;
        $numDue = $due !== null && $due !== '' ? (float) $due : max(0, $numTotal - $numPaid);
        if (is_numeric($currency)) {
            $currency = 'PKR'; // currency_id — display code fallback
        }
        $curr = htmlspecialchars($currency ?: '', ENT_QUOTES, 'UTF-8');

        $totalFormatted = $curr . number_format($numTotal, 2);
        $paidFormatted = $curr . number_format($numPaid, 2);
        $dueFormatted = $curr . number_format($numDue, 2);

        return '<div class="text-xs space-y-0.5">' .
            '<div class="font-bold text-foreground">' . $totalFormatted . '</div>' .
            '<div class="flex items-center gap-2 text-[11px]">' .
                '<span class="text-emerald-600 dark:text-emerald-400 font-medium">Paid: ' . $paidFormatted . '</span>' .
                ($numDue > 0 ? '<span class="text-rose-600 dark:text-rose-400 font-medium">Due: ' . $dueFormatted . '</span>' : '') .
            '</div>' .
        '</div>';
    }

    /**
     * Render an enterprise progress bar with percentage indicator.
     */
    /**
     * Title/name cell with priority (or other) badge — ERP Tasks parity.
     */
    public static function textWithBadge(mixed $text, mixed $badgeVal = null, string $kind = 'priority'): string
    {
        $label = htmlspecialchars((string) ($text ?? '—'), ENT_QUOTES, 'UTF-8');
        $badge = strtolower(trim((string) ($badgeVal ?? '')));
        if ($badge === '') {
            return '<span class="text-sm font-medium text-foreground">'.$label.'</span>';
        }
        $colors = match ($badge) {
            'high', 'urgent', 'critical' => 'bg-rose-500/15 text-rose-600 border-rose-500/30',
            'medium', 'normal' => 'bg-amber-500/15 text-amber-700 border-amber-500/30',
            'low' => 'bg-emerald-500/15 text-emerald-700 border-emerald-500/30',
            default => 'bg-muted text-muted-foreground border-border',
        };
        $badgeLabel = htmlspecialchars(ucfirst($badge), ENT_QUOTES, 'UTF-8');

        return '<div class="flex flex-col gap-0.5 min-w-0"><span class="text-sm font-medium text-foreground truncate">'.$label
            .'</span><span class="inline-flex w-fit items-center px-1.5 py-0.5 rounded text-[10px] font-bold border '.$colors.'">'.$badgeLabel.'</span></div>';
    }

    /**
     * Date cell with overdue / today / future coloring (ERP Tasks due_date parity).
     */
    public static function dateOverdue(mixed $val): string
    {
        if ($val === null || $val === '') {
            return '<span class="text-muted-foreground">—</span>';
        }
        try {
            if ($val instanceof \DateTimeInterface) {
                $dt = \Illuminate\Support\Carbon::instance(\DateTimeImmutable::createFromInterface($val));
            } else {
                $dt = \Illuminate\Support\Carbon::parse((string) $val);
            }
        } catch (\Throwable $e) {
            return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
        }
        $label = $dt->format('M j, Y');
        $end = $dt->copy()->endOfDay();
        if ($end->isPast()) {
            $cls = 'text-rose-600 dark:text-rose-400 font-semibold';
            $hint = 'Overdue';
        } elseif ($dt->isToday()) {
            $cls = 'text-amber-600 dark:text-amber-400 font-semibold';
            $hint = 'Due today';
        } else {
            $cls = 'text-foreground';
            $hint = '';
        }
        $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $title = $hint !== '' ? ' title="'.htmlspecialchars($hint, ENT_QUOTES, 'UTF-8').'"' : '';

        return '<span class="text-xs '.$cls.'"'.$title.'>'.$safe.'</span>';
    }

        public static function progressBar(float|int $percent, ?string $label = null): string
    {
        $pct = max(0, min(100, (int) round($percent)));
        $lbl = $label !== null ? htmlspecialchars($label, ENT_QUOTES, 'UTF-8') : ($pct . '%');

        $barColor = match (true) {
            $pct >= 100 => 'bg-emerald-500',
            $pct >= 60 => 'bg-primary',
            $pct >= 30 => 'bg-amber-500',
            default => 'bg-rose-500',
        };

        return '<div class="w-full min-w-[100px] max-w-[160px] space-y-1">' .
            '<div class="flex items-center justify-between text-[11px] font-semibold text-muted-foreground">' .
                '<span>Progress</span><span>' . $lbl . '</span>' .
            '</div>' .
            '<div class="w-full h-2 rounded-full bg-muted overflow-hidden">' .
                '<div class="h-full rounded-full transition-all duration-300 ' . $barColor . '" style="width: ' . $pct . '%"></div>' .
            '</div>' .
        '</div>';
    }

    /**
     * Batch resolve display names for foreign key columns across all items.
     *
     * @param  array<int, mixed>  $items
     * @param  array<int, array{key: string}>  $columns
     * @return array<string, array<int|string, string>>
     */
    protected static function resolveForeignKeysBatch(array $items, array $columns): array
    {
        $fkMaps = [];

        foreach ($columns as $column) {
            $key = $column['key'];
            if (! str_ends_with($key, '_id') || $key === 'id') {
                continue;
            }

            $base = substr($key, 0, -3);
            // A declared label_table wins: real schemas name tables
            // the FK convention cannot guess (board_column_id ->
            // taskboard_columns).
            $candidateTables = ! empty($column['label_table'])
                ? [(string) $column['label_table']]
                : match ($base) {
                    'department' => ['teams', 'departments', 'department'],
                    default => [Str::plural($base), $base],
                };

            $targetTable = null;
            $labelCol = null;

            foreach ($candidateTables as $tbl) {
                if (array_key_exists($tbl, static::$labelColumnCache)) {
                    $labelCol = static::$labelColumnCache[$tbl];
                    if ($labelCol !== null) {
                        $targetTable = $tbl;
                        break;
                    }
                } elseif (Schema::hasTable($tbl)) {
                    $cols = Schema::getColumnListing($tbl);
                    if (! empty($column['label_column']) && in_array((string) $column['label_column'], $cols, true)) {
                        $labelCol = (string) $column['label_column'];
                    }
                    foreach ($labelCol === null ? ['name', 'title', 'team_name', 'company_name', 'label', 'display_name', 'full_name', 'invoice_number', 'order_number', 'sku', 'reference', 'code'] : [] as $candidate) {
                        if (in_array($candidate, $cols, true)) {
                            $labelCol = $candidate;
                            break;
                        }
                    }
                    // Convention fallback: the label often lives in a *_name
                    // column (leave_types.type_name, statuses.status_name) —
                    // prefer the FK segment's own *_name, then any *_name.
                    if ($labelCol === null) {
                        $segmentName = Str::afterLast($base, '_') . '_name';
                        $nameCols = array_values(array_filter(
                            $cols,
                            static fn ($cl) => str_ends_with($cl, '_name')
                        ));
                        foreach (array_merge([$segmentName], $nameCols) as $candidate) {
                            if (in_array($candidate, $cols, true)) {
                                $labelCol = $candidate;
                                break;
                            }
                        }
                    }
                    static::$labelColumnCache[$tbl] = $labelCol;
                    if ($labelCol !== null) {
                        $targetTable = $tbl;
                        break;
                    }
                }
            }

            if ($targetTable && $labelCol) {
                // Collect all unique IDs for this key across items
                $ids = [];
                foreach ($items as $item) {
                    $v = data_get($item, $key);
                    if (is_numeric($v) && $v > 0) {
                        $ids[] = (int) $v;
                    }
                }
                $ids = array_unique($ids);

                if (! empty($ids)) {
                    try {
                        $fkMaps[$key] = DB::table($targetTable)
                            ->whereIn('id', $ids)
                            ->pluck($labelCol, 'id')
                            ->all();
                    } catch (\Throwable $e) {
                        // Safe fallback if table read fails
                        $fkMaps[$key] = [];
                    }
                }
            }
        }

        return $fkMaps;
    }

    /**
     * Get display name from an Eloquent model.
     */
    protected static function modelDisplayName(Model $model): string
    {
        foreach (['name', 'title', 'team_name', 'company_name', 'label', 'display_name', 'full_name', 'invoice_number', 'order_number', 'sku', 'reference', 'code'] as $cand) {
            if (isset($model->{$cand}) && filled($model->{$cand})) {
                return (string) $model->{$cand};
            }
        }
        // Convention fallback: any *_name attribute (type_name, status_name, ...)
        foreach (array_keys($model->getAttributes()) as $cand) {
            if (str_ends_with($cand, '_name') && filled($model->{$cand})) {
                return (string) $model->{$cand};
            }
        }

        return (string) ($model->getKey() ?? '—');
    }

    /**
     * Human date display for date/datetime-typed fields shown without an
     * explicit formatter (raw storage format never reaches the listing).
     */
    public static function formatDateValue(mixed $value, string $fieldType): string
    {
        try {
            $dt = $value instanceof \DateTimeInterface
                ? $value
                : new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return (string) static::display($value);
        }

        // A datetime sitting exactly at midnight is a date in disguise
        // (the common case for ported date columns) — never show ", 00:00".
        if ($fieldType === 'date' || $dt->format('H:i:s') === '00:00:00') {
            return $dt->format('d M Y');
        }

        return $dt->format('d M Y, H:i');
    }

    public static function display(mixed $value): string|int|float
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_int($value), is_float($value) => $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value), is_object($value) && ! method_exists($value, '__toString') => json_encode($value),
            default => (string) $value,
        };
    }
}