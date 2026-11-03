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
     * THE directory-column formatter vocabulary — the single
     * source every surface reads: this renderer (the match
     * below), the blueprint validator, and the Studio's
     * column-type pickers. A formatter the runtime cannot
     * render must not validate, and one it renders must
     * never be called invalid. (Before this registry, the
     * validator kept its own list and falsely rejected the
     * plain 'date' formatter working slices declare.)
     *
     * @var list<string>
     */
    public const FORMATTERS = [
        'text', 'number', 'code_badge', 'composite_user', 'user_stack',
        'status_pill', 'money', 'money_breakdown', 'text_with_badge',
        'badge', 'icon_text', 'progress_bar', 'timer', 'lines',
        'duration', 'date', 'date_weekday', 'date_time', 'date_overdue',
    ];

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
    public static function from(iterable $items, array $columns, ?callable $extra = null, ?string $table = null): array
    {
        $rows = [];
        $itemsArray = is_array($items) ? $items : iterator_to_array($items);

        if ($table !== null && $table !== '') {
            // Parent-table context lets value resolvers find
            // conventional pivots/relations for plain (non-model)
            // listing rows, which carry no table knowledge.
            $columns = array_map(static function ($column) use ($table) {
                if (is_array($column) && empty($column['parent_table'])) {
                    $column['parent_table'] = $table;
                }

                return $column;
            }, $columns);
        }

        // Pre-resolve foreign key lookups in a single batch query per FK column
        $fkMaps = static::resolveForeignKeysBatch($itemsArray, $columns);
        // Declared aggregate columns (a summed value from a
        // related table — a task's logged minutes) resolve in one
        // grouped query per column, never per row.
        $aggMaps = static::resolveAggregatesBatch($itemsArray, $columns);
        // Thread-activity badges (a ticket's New / New
        // response marker) resolve in one pass over the
        // replies table, never per row.
        $threadMaps = static::resolveThreadBadgesBatch($itemsArray, $columns);

        foreach ($itemsArray as $item) {
            $row = [];
            foreach ($columns as $column) {
                $key = $column['key'];
                $val = data_get($item, $key);
                if ($val === null && in_array($key, ['role', 'user_role'], true)) {
                    // Ported profile slices carry the role on the linked
                    // user, not on the record — resolve it so the inline
                    // role select (and its read-only form) shows a value.
                    $val = static::resolveUserRoleName($item);
                }
                if ($val === null && $key === 'email') {
                    $val = static::userColumnFallback($item, 'email');
                }
                if ($val === null && in_array($key, ['created_at', 'updated_at', 'deleted_at'], true)) {
                    // Listing BOs carry the audit stamps in
                    // camelCase (BaseListingBusinessObject) —
                    // a created_at column read only the snake
                    // key and rendered an em dash (QA5).
                    $val = data_get($item, lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key)))));
                }
                if (! empty($column['value_from'] ?? null)) {
                    // Declared value source: the displayed value
                    // lives on a related record, not on this row
                    // (a leave's Paid flag is its TYPE's paid flag
                    // in the source — the row's own column is a
                    // stale copy). Resolved through relations,
                    // then the FK-convention walk for listing BOs.
                    $vf = data_get($item, (string) $column['value_from']);
                    if ($vf === null) {
                        $vf = DirectoryUi::resolveDottedByForeignKey($item, (string) $column['value_from']);
                    }
                    if ($vf !== null) {
                        $val = $vf;
                    }
                }
                if (is_array($column['compute'] ?? null)) {
                    // Declared computed value (a timesheet's
                    // earnings = minutes x the employee's rate):
                    // a product of resolved paths, optionally
                    // scaled. Replaces the row's own value.
                    $val = static::computeValue($item, $column['compute']);
                }
                $row[$key . '__raw'] = is_scalar($val) ? (string)$val : '';

                // 1. If column has a custom callback formatter.
                // Strings are formatter NAMES, never callables: a
                // name that happens to match a PHP function ('date')
                // must not be invoked as one.
                if (isset($column['formatter']) && ! is_string($column['formatter']) && is_callable($column['formatter'])) {
                    static::applyProgress($row, $key, $column, $item, $column['formatter']($val, $item));
                    continue;
                }

                // 2. Named string formatters
                $fmt = $column['formatter'] ?? null;
                if (is_string($fmt) && $fmt !== 'text') {
                    $row[$key] = match ($fmt) {
                        'code_badge' => static::codeBadge($val),
                        'composite_user' => static::resolveCompositeUser($item, $key, $val, $column),
                        'user_stack' => static::resolveUserStack($item, $key, $val, $column),
                        'status_pill' => static::statusPill((string) ($val ?: data_get($item, 'user.status') ?: static::userColumnFallback($item, 'status') ?: '')),
                        'money' => static::money($val, $item),
                        'money_breakdown' => static::moneyBreakdown(
                            $val,
                            data_get($item, $column['breakdown']['paid'] ?? 'paid_amount') ?? data_get($item, 'paid'),
                            static::moneyPrefix($item),
                            data_get($item, $column['breakdown']['unpaid'] ?? 'due_amount', null)
                        ),
                        'text_with_badge' => static::textWithBadge(
                            $val,
                            ($column['badge_from'] ?? null) === 'thread_activity'
                                ? ($threadMaps[$key][(int) data_get($item, 'id')] ?? null)
                                : data_get($item, $column['badge_from'] ?? 'priority'),
                            (string) ($column['badge_from'] ?? 'priority'),
                            static::declaredSubtitle($item, $column)
                        ),
                        'badge' => static::badgeFor($val, $item, $column, $fkMaps[$key] ?? []),
                        'icon_text' => static::iconText($val, $item, $column, $fkMaps[$key] ?? []),
                        'progress_bar' => static::progressBar((float) $val),
                        'timer' => static::resolveTimer($item),
                        'lines' => static::resolveLines($item, $column),
                        'duration' => static::durationCell($val, $item, $column, $aggMaps[$key] ?? null),
                        'date' => static::formatDateValue($val, 'date'),
                        'date_weekday' => static::formatDateValue($val, 'date_weekday'),
                        'date_time' => static::formatDateValue($val, 'datetime'),
                        'date_overdue' => static::dateOverdue($val),
                        default => static::display($val),
                    };
                    static::applyProgress($row, $key, $column, $item, $row[$key] ?? '');
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
                static::applyProgress($row, $key, $column, $item, $row[$key] ?? '');
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
     * A column may declare `progress_field`: the named field's
     * percentage renders as the source-style bar (percent
     * centered on the track) ABOVE the cell's own content —
     * the original list idiom of progress over the status
     * control (QA6, 25 Oct 2026).
     */
    private static function applyProgress(array &$row, string $key, array $column, mixed $item, mixed $cell): void
    {
        $row[$key] = $cell;
        $field = $column['progress_field'] ?? null;
        if (! is_string($field) || $field === '') {
            return;
        }
        $pv = data_get($item, $field);
        if (is_numeric($pv)) {
            // The bar rides on its OWN row key, not baked into
            // the cell HTML: an inline-edit cell must be able
            // to hide the pill (the select replaces it) without
            // losing the bar above the editor.
            $row[$key.'__progress'] = static::progressBar((float) $pv, null, true);
        }
    }

    /**
     * Render a rich composite user avatar + name + subtitle cell with zero extra dependencies.
     */
    /**
     * Render an enterprise monospace code badge (e.g. #EMP-01, INV-2024).
     */
    /**
     * Colored pill for a label whose color lives on the record
     * (declared `color_from`, e.g. a leave type's own color) —
     * the source renders such taxonomies as solid badges in the
     * taxonomy's color, never as plain text.
     */
    public static function badgeFor(mixed $val, mixed $item, array $column, array $fkMap): string
    {
        $label = (string) ($fkMap[$val] ?? $val);
        $color = ! empty($column['color_from']) ? data_get($item, (string) $column['color_from']) : null;
        if ($color === null && ! empty($column['color_from'] ?? null)) {
            // The colour may live on the related record (a leave
            // type's own colour) — walk the FK chain for it.
            $color = DirectoryUi::resolveDottedByForeignKey($item, (string) $column['color_from']);
        }

        return static::badge($label, $color);
    }

    /**
     * Icon-tile + label cell (the source's award cell: a
     * tinted tile carrying the related record's icon, then
     * its name). Icon and colour resolve from the related
     * record via declared dotted paths (icon_from /
     * color_from, FK-walked for listing rows); the label is
     * the FK display name. Icon names pass through a small
     * source-iconset -> Lucide map; unknown names fall back
     * to a generic award glyph.
     */
    public static function iconText(mixed $val, mixed $item, array $column, array $fkMap): string
    {
        $label = (string) ($fkMap[$val] ?? $val ?? '');
        if (trim($label) === '') {
            return DirectoryUi::emptyDash();
        }
        $color = ! empty($column['color_from'] ?? null)
            ? (data_get($item, (string) $column['color_from']) ?? DirectoryUi::resolveDottedByForeignKey($item, (string) $column['color_from']))
            : null;
        $color = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($color)) ? trim($color) : null;
        $icon = ! empty($column['icon_from'] ?? null)
            ? (data_get($item, (string) $column['icon_from']) ?? DirectoryUi::resolveDottedByForeignKey($item, (string) $column['icon_from']))
            : null;
        $icon = is_string($icon) ? (self::ICON_NAME_MAP[strtolower(trim($icon))] ?? strtolower(trim($icon))) : 'award';
        if (! preg_match('/^[a-z0-9-]+$/', $icon)) {
            $icon = 'award';
        }
        $tileStyle = $color !== null
            ? ' style="background-color: ' . $color . '1f; color: ' . $color . '"'
            : '';
        $tileClass = $color !== null
            ? 'inline-flex size-9 items-center justify-center rounded-lg shrink-0'
            : 'inline-flex size-9 items-center justify-center rounded-lg shrink-0 bg-muted text-muted-foreground';

        return '<span class="inline-flex items-center gap-2.5"><span class="' . $tileClass . '"' . $tileStyle . '>'
            . self::lucideIcon($icon, 'size-5')
            . '</span><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></span>';
    }

    /** Source iconset names that differ from Lucide's. */
    private const ICON_NAME_MAP = [
        'hand-thumbs-up' => 'thumbs-up',
        'cup-hot' => 'coffee',
        'airplane' => 'plane',
    ];

    private static array $iconSvgCache = [];

    private static function lucideIcon(string $name, string $class = ''): string
    {
        $key = $name . '|' . $class;
        if (! isset(self::$iconSvgCache[$key])) {
            try {
                $svg = \Illuminate\Support\Facades\Blade::render('<x-lucide-' . $name . ($class !== '' ? ' class="' . $class . '"' : '') . ' />');
            } catch (\Throwable) {
                $svg = '';
            }
            self::$iconSvgCache[$key] = $svg;
        }

        return self::$iconSvgCache[$key];
    }

    public static function badge(string $label, mixed $color = null): string
    {
        if (trim($label) === '') {
            return DirectoryUi::emptyDash();
        }
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $color = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($color)) ? trim($color) : null;
        if ($color === null) {
            return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-muted text-foreground">' . $label . '</span>';
        }

        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold text-white" style="background-color: ' . $color . '">' . $label . '</span>';
    }

    public static function codeBadge(mixed $val): string
    {
        $str = htmlspecialchars((string) ($val ?? DirectoryUi::emptyDash()), ENT_QUOTES, 'UTF-8');
        return '<span class="ls-code-badge inline-flex items-center px-2 py-0.5 rounded font-mono text-[11px] font-bold bg-muted/80 text-foreground border border-border/60">' . $str . '</span>';
    }

    /**
     * A column's declared subtitle path (subtitle_from), resolved
     * directly or through the FK-convention walk — shared by the
     * cell renderers that carry a subtitle line.
     */
    public static function declaredSubtitle(mixed $item, array $column): ?string
    {
        if (empty($column['subtitle_from'] ?? null)) {
            return null;
        }
        $subtitle = data_get($item, (string) $column['subtitle_from']);
        if ($subtitle === null) {
            $subtitle = DirectoryUi::resolveDottedByForeignKey($item, (string) $column['subtitle_from']);
        }
        if (is_object($subtitle)) {
            $subtitle = $subtitle->name ?? null;
        }

        return is_scalar($subtitle) && (string) $subtitle !== '' ? (string) $subtitle : null;
    }

    /**
     * Evaluate a declared `compute` spec: {product: [path, ...],
     * divide?: number}. Each path resolves like value_from
     * (direct, then the FK-convention walk); a non-numeric
     * operand counts as 0.
     */
    public static function computeValue(mixed $item, array $spec): float
    {
        $result = 1.0;
        foreach ((array) ($spec['product'] ?? []) as $path) {
            $v = data_get($item, (string) $path);
            if ($v === null) {
                $v = DirectoryUi::resolveDottedByForeignKey($item, (string) $path);
            }
            $result *= is_numeric($v) ? (float) $v : 0.0;
        }
        $divide = $spec['divide'] ?? null;
        if (is_numeric($divide) && (float) $divide !== 0.0) {
            $result /= (float) $divide;
        }

        return $result;
    }

    /**
     * Gracefully resolve composite user avatar, name, and subtitle from item.
     */
    public static function resolveCompositeUser(mixed $item, string $key, mixed $val, array $column): string
    {
        // A column that declares value_from has already said
        // "the person is not this row" (a deal's agent lives
        // behind agent_id -> lead_agents -> user_id): a
        // numeric resolved value is that person's id, and
        // the row's own name must not win the chain below.
        $declaredForeignPerson = ! empty($column['value_from'] ?? null) && is_numeric($val);
        $name = $declaredForeignPerson ? null : (
            data_get($item, 'user.name')
            ?? data_get($item, 'name')
            ?? (is_object($val) ? ($val->name ?? null) : null)
            ?? (is_string($val) && !empty($val) && !is_numeric($val) ? $val : null)
        );

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

        // A declared subtitle path wins (subtitle_from: e.g. a
        // client column's client.clientDetail.company_name — the
        // company under the client's name in the source lists);
        // it resolves through relations, then through the
        // FK-convention walk when a middle segment is not an
        // Eloquent relation.
        $subtitle = null;
        if (! empty($column['subtitle_from'] ?? null)) {
            $subtitle = data_get($item, (string) $column['subtitle_from']);
            if ($subtitle === null) {
                $subtitle = DirectoryUi::resolveDottedByForeignKey($item, (string) $column['subtitle_from']);
            }
            if (is_object($subtitle)) {
                $subtitle = $subtitle->name ?? null;
            }
        }

        // Designation beats email as the identity subtitle (the source
        // lists show the person's designation under their name). The
        // designation_id lookup must run BEFORE the email fallbacks —
        // previously any resolved email short-circuited it.
        $subtitle = $subtitle ?? data_get($item, 'designation.name')
            ?? data_get($item, 'designation_name')
            ?? data_get($item, 'designation');
        if (is_object($subtitle)) {
            $subtitle = $subtitle->name ?? null;
        }
        if (is_numeric($subtitle) || empty($subtitle)) {
            $desigId = is_numeric(data_get($item, 'designation_id')) ? (int) data_get($item, 'designation_id') : null;
            if (! $desigId) {
                // Rows about a person (a leave, a timesheet)
                // carry only a user FK; the designation lives
                // on the person's employee detail record. The
                // FK column is not always named user_id (an
                // appreciation's is award_to) — the cell's own
                // value is the person id then.
                $uid = is_numeric(data_get($item, 'user_id')) ? (int) data_get($item, 'user_id') : (is_numeric($val) ? (int) $val : null);
                if ($uid) {
                    try {
                        $desigId = DB::table('employee_details')->where('user_id', $uid)->value('designation_id');
                    } catch (\Throwable $e) {}
                }
            }
            if ($desigId) {
                try {
                    $subtitle = DB::table('designations')->where('id', $desigId)->value('name');
                } catch (\Throwable $e) {}
            }
        }
        $subtitle = $subtitle
            ?? data_get($item, 'company_name')
            ?? data_get($item, 'client_company')
            ?? data_get($item, 'company')
            ?? data_get($item, 'user.email')
            ?? data_get($item, 'email');

        $avatarUrl = data_get($item, 'user.avatar_url')
            ?? data_get($item, 'avatar_url')
            ?? data_get($item, 'user.image');

        $viewUrl = $column['view_url'] ?? (isset($item->id) && \Illuminate\Support\Facades\Route::has('employees.show') ? route('employees.show', $item->id) : null);

        return static::compositeUser($name, $subtitle, $avatarUrl, $viewUrl);
    }

    /**
     * A column value that lives on the record's linked user (ported
     * profile slices keep email/status on users, not on the profile
     * table). Resolved by user_id with a single lookup.
     */
    public static function userColumnFallback(mixed $item, string $column): mixed
    {
        try {
            $userId = is_numeric(data_get($item, 'user_id')) ? (int) data_get($item, 'user_id') : null;
            if ($userId === null && is_object($item) && isset($item->id) && data_get($item, 'email')) {
                $userId = (int) $item->id;
            }
            if ($userId === null) {
                return null;
            }

            return DB::table('users')->where('id', $userId)->value($column);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The linked user's current role label (role_user → roles), for
     * role columns on ported profile slices whose records carry no
     * role attribute of their own. Mirrors the source systems' rule:
     * the current role is the user's highest role id; the label is
     * the role's display name where the schema carries one.
     */
    public static function resolveUserRoleName(mixed $item): ?string
    {
        try {
            $userId = is_numeric(data_get($item, 'user_id')) ? (int) data_get($item, 'user_id') : null;
            if ($userId === null) {
                return null;
            }
            $hasDisplay = \Illuminate\Support\Facades\Schema::hasColumn('roles', 'display_name');
            $row = DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $userId)
                ->orderByDesc('role_user.role_id')
                ->first($hasDisplay ? ['roles.display_name', 'roles.name'] : ['roles.name']);
            if (! $row) {
                return null;
            }
            $label = ($hasDisplay && $row->display_name) ? (string) $row->display_name : (string) $row->name;

            return str_contains($label, ' ') ? $label : Str::headline($label);
        } catch (\Throwable $e) {
            return null;
        }
    }
    /**
     * Labeled-lines cell: stacked "Label: value" rows declared
     * per column (`lines: [{label, from, case?}]`), for meta-pair
     * cells like a ticket's Others (Agent: X / Priority: Y) that
     * are facts about the row, not a person or a single value.
     * Values resolve by data_get path on the row; a line whose
     * value is empty is skipped, and an all-empty cell is an
     * em dash.
     */
    public static function resolveLines(mixed $item, array $column): string
    {
        $out = [];
        foreach ((array) ($column['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $v = data_get($item, (string) ($line['from'] ?? ''));
            if (($v === null || $v === '') && str_contains((string) ($line['from'] ?? ''), '.')) {
                // Dotted paths walk the FK conventions like
                // every other declared path (Agent: agent.name
                // on a listing row that carries only agent_id).
                // A line may pin its hop table (`table: users`)
                // where the convention is genuinely ambiguous —
                // a ticket's agent_id is a user, while a deal's
                // agent_id is an agent record.
                $v = ! empty($line['table'])
                    ? static::resolveViaTable($item, (string) ($line['from'] ?? ''), (string) $line['table'])
                    : DirectoryUi::resolveDottedByForeignKey($item, (string) ($line['from'] ?? ''));
            }
            if ($v === null || $v === '' || ! is_scalar($v)) {
                continue;
            }
            $v = (string) $v;
            if (($line['case'] ?? '') === 'headline') {
                $v = \Illuminate\Support\Str::headline($v);
            }
            $label = (string) ($line['label'] ?? '');
            $out[] = '<div class="text-[13px] leading-5 text-foreground">'
                .($label !== '' ? '<span class="text-muted-foreground">'.e($label).':</span> ' : '')
                .e($v).'</div>';
        }

        return $out === [] ? DirectoryUi::emptyDash() : '<div class="flex flex-col">'.implode('', $out).'</div>';
    }

    /**
     * Resolve a dotted path through one pinned table: the
     * first segment names the FK column ({segment}_id) and
     * the rest of the path reads off that table's row.
     */
    public static function resolveViaTable(mixed $item, string $path, string $table): mixed
    {
        try {
            $segments = explode('.', $path);
            $head = (string) array_shift($segments);
            $fkValue = data_get($item, $head.'_id');
            if ($fkValue === null || $segments === []
                || ! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                return null;
            }
            $row = DB::table($table)->where('id', $fkValue)->first();

            return $row !== null ? data_get($row, implode('.', $segments)) : null;
        } catch (\Throwable $e) {
            return null;
        }
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

        return DirectoryUi::emptyDash();
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

        $itemId = $item instanceof \Illuminate\Database\Eloquent\Model ? $item->getKey() : data_get($item, 'id');
        $itemTable = $item instanceof \Illuminate\Database\Eloquent\Model ? $item->getTable() : ($column['parent_table'] ?? null);
        if ($entries === [] && $itemId && is_string($itemTable) && $itemTable !== '') {
            // Ported rows rarely carry their member collection; the
            // membership lives in a conventional pivot —
            // {singular}_members (project_members, team_members, …)
            // or {singular}_users (task_users, …).
            try {
                $table = $itemTable;
                $fk = Str::singular($table).'_id';
                $pivot = null;
                foreach ([Str::singular($table).'_members', Str::singular($table).'_users'] as $candidate) {
                    if (\Illuminate\Support\Facades\Schema::hasTable($candidate)
                        && \Illuminate\Support\Facades\Schema::hasColumn($candidate, $fk)
                        && \Illuminate\Support\Facades\Schema::hasColumn($candidate, 'user_id')) {
                        $pivot = $candidate;
                        break;
                    }
                }
                if ($pivot !== null) {
                    $ids = DB::table($pivot)->where($fk, $itemId)->pluck('user_id')->all();
                    if ($ids !== []) {
                        foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'name', 'email']) as $u) {
                            $entries[] = ['name' => $u->name, 'email' => $u->email, 'avatar_url' => null];
                        }
                    }
                }
            } catch (\Throwable $e) {
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
            return '<span class="text-muted-foreground">'.DirectoryUi::emptyDash().'</span>';
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
        if (trim($status) === '') {
            return DirectoryUi::emptyDash();
        }
        $statusKey = strtolower(trim($status));
        $label = htmlspecialchars($label ?: ucwords(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8');

        $colorMap = [
            'active' => 'emerald',
            'enabled' => 'emerald',
            'paid' => 'emerald',
            'completed' => 'emerald',
            'complete' => 'emerald',
            'accepted' => 'emerald',
            'approved' => 'emerald',
            'present' => 'emerald',
            'pending' => 'amber',
            'in_progress' => 'amber',
            'partially_paid' => 'amber',
            'waiting' => 'amber',
            'inactive' => 'rose',
            'disabled' => 'rose',
            'declined' => 'rose',
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

        return '<span class="ls-status-pill inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold border ' . $pillColor . '"><span class="size-1.5 rounded-full ' . $dotColor . '"></span>' . $label . '</span>';
    }

    /**
     * Render formatted financial total with paid and due breakdown.
     */
    /**
     * Batch-resolve declared aggregate columns:
     * `aggregate: {table, sum, foreign_key?}` — one grouped SUM
     * query per column over the page's row ids. The foreign key
     * defaults to the conventional singular(parent_table)_id.
     *
     * @return array<string, array<int, float>> column key => row id => sum
     */
    public static function resolveAggregatesBatch(array $items, array $columns): array
    {
        $maps = [];
        $ids = [];
        foreach ($items as $item) {
            $id = $item instanceof \Illuminate\Database\Eloquent\Model ? $item->getKey() : data_get($item, 'id');
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            return $maps;
        }
        foreach ($columns as $column) {
            $agg = $column['aggregate'] ?? null;
            if (! is_array($agg) || empty($agg['table']) || empty($agg['sum'])) {
                continue;
            }
            $table = (string) $agg['table'];
            $sum = (string) $agg['sum'];
            $fk = (string) ($agg['foreign_key'] ?? '');
            if ($fk === '' && ! empty($column['parent_table'])) {
                $fk = Str::singular((string) $column['parent_table']).'_id';
            }
            if ($fk === '') {
                continue;
            }
            try {
                if (! \Illuminate\Support\Facades\Schema::hasTable($table)
                    || ! \Illuminate\Support\Facades\Schema::hasColumn($table, $fk)
                    || ! \Illuminate\Support\Facades\Schema::hasColumn($table, $sum)) {
                    continue;
                }
                $maps[(string) $column['key']] = DB::table($table)
                    ->whereIn($fk, $ids)
                    ->groupBy($fk)
                    ->pluck(DB::raw("SUM({$sum})"), $fk)
                    ->map(fn ($v) => (float) $v)
                    ->all();
            } catch (\Throwable $e) {
                // An unresolvable aggregate renders zeroes, never a 500.
            }
        }

        return $maps;
    }

    /**
     * Thread-activity badges for text_with_badge columns
     * declaring `badge_from: thread_activity` + a `thread:
     * {table, key}` spec (the replies table + its FK to the
     * row). The semantics are the source ticket badge's:
     * more than one reply, not all from the latest replier,
     * and the latest reply is not the viewer's own → "new
     * response"; a single reply, or every reply from one
     * person → "new"; anything else → no badge. One query
     * per column over the page's ids.
     *
     * @return array<string, array<int, string>> column key => row id => badge
     */
    public static function resolveThreadBadgesBatch(array $items, array $columns): array
    {
        $maps = [];
        $ids = [];
        foreach ($items as $item) {
            $id = $item instanceof \Illuminate\Database\Eloquent\Model ? $item->getKey() : data_get($item, 'id');
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            return $maps;
        }
        foreach ($columns as $column) {
            if (($column['badge_from'] ?? null) !== 'thread_activity') {
                continue;
            }
            $thread = $column['thread'] ?? null;
            $table = is_array($thread) ? (string) ($thread['table'] ?? '') : '';
            $fk = is_array($thread) ? (string) ($thread['key'] ?? '') : '';
            if ($table === '' || $fk === '') {
                continue;
            }
            try {
                if (! \Illuminate\Support\Facades\Schema::hasTable($table)
                    || ! \Illuminate\Support\Facades\Schema::hasColumn($table, $fk)
                    || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'user_id')) {
                    continue;
                }
                $me = null;
                try {
                    $me = auth()->id();
                } catch (\Throwable $e) {
                    $me = null;
                }
                // "Latest" mirrors the source relation's
                // ->latest() (created_at DESC, no tiebreak):
                // the FIRST row the engine returns per thread
                // wins, exactly as it does in the source —
                // seeded replies share one timestamp, so the
                // tiebreak is part of the rendered truth.
                $orderColumn = \Illuminate\Support\Facades\Schema::hasColumn($table, 'created_at') ? 'created_at' : 'id';
                $stats = [];
                foreach (DB::table($table)->whereIn($fk, $ids)->orderByDesc($orderColumn)->get([$fk, 'user_id']) as $reply) {
                    $owner = (int) $reply->{$fk};
                    $stats[$owner]['total'] = ($stats[$owner]['total'] ?? 0) + 1;
                    $stats[$owner]['latest'] ??= (int) $reply->user_id;
                    $stats[$owner]['by'][(int) $reply->user_id] = ($stats[$owner]['by'][(int) $reply->user_id] ?? 0) + 1;
                }
                $badges = [];
                foreach ($ids as $id) {
                    $total = $stats[$id]['total'] ?? 0;
                    $latest = $stats[$id]['latest'] ?? null;
                    $selfCount = $latest !== null ? ($stats[$id]['by'][$latest] ?? 0) : 0;
                    if ($total > 1 && $total !== $selfCount && $latest !== null && $latest !== $me) {
                        $badges[$id] = 'new response';
                    } elseif ($total === 1 || $total === $selfCount) {
                        $badges[$id] = 'new';
                    }
                }
                $maps[(string) $column['key']] = $badges;
            } catch (\Throwable $e) {
                // An unresolvable thread renders no badges, never a 500.
            }
        }

        return $maps;
    }

    /**
     * Duration cell fed by a declared aggregate (summed minutes
     * from a related table) or by a numeric value on the row
     * itself. The format is the source's compact human form:
     * 0 → "0s", 180 → "3h", 90 → "1h 30m", 45 → "45m".
     */
    public static function durationCell(mixed $val, mixed $item, array $column, ?array $aggMap): string
    {
        if (is_array($column['aggregate'] ?? null)) {
            $id = $item instanceof \Illuminate\Database\Eloquent\Model ? $item->getKey() : data_get($item, 'id');
            $minutes = $id !== null ? (float) ($aggMap[(int) $id] ?? 0) : 0.0;

            return static::durationMinutes((int) round($minutes));
        }
        if (is_numeric($val)) {
            return static::durationMinutes((int) round((float) $val));
        }

        return DirectoryUi::emptyDash();
    }

    public static function durationMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0s';
        }
        $h = intdiv($minutes, 60);
        $r = $minutes % 60;
        if ($h > 0 && $r > 0) {
            return "{$h}h {$r}m";
        }

        return $h > 0 ? "{$h}h" : "{$r}m";
    }

    /**
     * Plain money cell: currency code + amount at 2 decimals
     * (the source's list format, e.g. "PKR881.00"). The code
     * comes from the record's own currency when it carries one,
     * else the Mind's declared localization currency.
     */
    /**
     * The display prefix for a record's money: the record's
     * own currency string when it carries one, else its
     * currency row's SYMBOL (the source renders the symbol —
     * for PKR the symbol is the text "PKR" itself, for GBP
     * it is "£"), else the deployment's declared code.
     */
    public static function moneyPrefix(mixed $item): string
    {
        $currency = $item !== null ? data_get($item, 'currency') : null;
        if (is_string($currency) && $currency !== '' && ! is_numeric($currency)) {
            return $currency;
        }
        $walked = $item !== null
            ? DirectoryUi::resolveDottedByForeignKey($item, 'currency.currency_symbol')
            : null;

        return is_string($walked) && $walked !== ''
            ? $walked
            : DirectoryUi::currencyCode();
    }

    public static function money(mixed $val, mixed $item = null): string
    {
        if ($val === null || $val === '' || ! is_numeric($val)) {
            return DirectoryUi::emptyDash();
        }

        return htmlspecialchars(static::moneyPrefix($item), ENT_QUOTES, 'UTF-8').number_format((float) $val, 2);
    }

    public static function moneyBreakdown(float|int|string|null $total, float|int|string|null $paid = null, float|int|string|null $currency = 'PKR', float|int|string|null $due = null): string
    {
        // Real ported data has null amounts (drafts, hollow imports); a
        // formatter must render the empty placeholder, never crash the list.
        if ($total === null || $total === '') {
            return DirectoryUi::emptyDash();
        }
        $numTotal = (float) $total;
        // Documents store only ONE side of the split (invoices
        // persist due_amount; the paid side is total − due).
        // Whichever side is missing is derived from the other:
        // paid + due always reconciles to the total.
        $numDue = $due !== null && $due !== '' ? (float) $due : 0.0;
        $numPaid = $paid !== null && $paid !== ''
            ? (float) $paid
            : max(0, $numTotal - $numDue);
        if ($due === null || $due === '') {
            $numDue = max(0, $numTotal - $numPaid);
        }
        if (is_numeric($currency)) {
            $currency = 'PKR'; // currency_id — display code fallback
        }
        $curr = htmlspecialchars($currency ?: '', ENT_QUOTES, 'UTF-8');

        $totalFormatted = $curr . number_format($numTotal, 2);
        $paidFormatted = $curr . number_format($numPaid, 2);
        $dueFormatted = $curr . number_format($numDue, 2);

        // The source's document-total stack: three labelled
        // lines, always all three (a settled invoice still
        // prints "Unpaid: PKR0.00").
        return '<div class="text-xs space-y-0.5">' .
            '<div class="text-foreground">Total: <span class="font-bold">' . $totalFormatted . '</span></div>' .
            '<div class="text-emerald-600 dark:text-emerald-400 font-medium">Paid: ' . $paidFormatted . '</div>' .
            '<div class="text-rose-600 dark:text-rose-400 font-medium">Unpaid: ' . $dueFormatted . '</div>' .
        '</div>';
    }

    /**
     * Render an enterprise progress bar with percentage indicator.
     */
    /**
     * Title/name cell with priority (or other) badge — ERP Tasks parity.
     */
    public static function textWithBadge(mixed $text, mixed $badgeVal = null, string $kind = 'priority', ?string $subtitle = null): string
    {
        $label = htmlspecialchars((string) ($text ?? DirectoryUi::emptyDash()), ENT_QUOTES, 'UTF-8');
        $badge = strtolower(trim((string) ($badgeVal ?? '')));
        if ($badge === '' && ($subtitle === null || $subtitle === '')) {
            return '<span class="text-sm font-medium text-foreground">'.$label.'</span>';
        }
        if ($badge === '') {
            return '<div class="flex flex-col min-w-0"><span class="text-sm font-medium text-foreground truncate">'.$label
                .'</span><span class="text-[11px] text-muted-foreground truncate">'
                .htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8').'</span></div>';
        }
        $colors = match ($badge) {
            'high', 'urgent', 'critical' => 'bg-rose-500/15 text-rose-600 border-rose-500/30',
            'medium', 'normal' => 'bg-amber-500/15 text-amber-700 border-amber-500/30',
            'low', 'new' => 'bg-emerald-500/15 text-emerald-700 border-emerald-500/30',
            'new response' => 'bg-sky-500/15 text-sky-700 border-sky-500/30',
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
            return '<span class="text-muted-foreground">'.DirectoryUi::emptyDash().'</span>';
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
        $label = $dt->format('d-m-Y');
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

        public static function progressBar(float|int $percent, ?string $label = null, bool $compact = false): string
    {
        $pct = max(0, min(100, (int) round($percent)));
        $lbl = $label !== null ? htmlspecialchars($label, ENT_QUOTES, 'UTF-8') : ($pct . '%');

        // Source thresholds, read off the original list
        // (QA6, 25 Oct 2026): 44/45/48 render red, 50/55/74
        // amber, 97 green.
        $barColor = match (true) {
            $pct >= 90 => 'bg-emerald-500',
            $pct >= 50 => 'bg-amber-500',
            default => 'bg-rose-500',
        };
        if ($compact) {
            return '<div class="relative w-full min-w-[110px] max-w-[170px] h-[22px] rounded-sm bg-slate-200 dark:bg-slate-700 overflow-hidden mb-1.5">'
                .'<div class="h-full '.$barColor.'" style="width: '.$pct.'%"></div>'
                .'<span class="absolute inset-0 flex items-center justify-center text-[11px] font-bold text-slate-700 dark:text-slate-100">'.$pct.'%</span></div>';
        }

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
                    // Clients are users in this system family (the
                    // client record's name lives on the users table).
                    'client' => ['users', 'client_details', 'clients'],
                    'board_column' => ['taskboard_columns', 'board_columns'],
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
                    foreach ($labelCol === null ? ['name', 'title', 'team_name', 'company_name', 'label', 'display_name', 'full_name', 'column_name', 'invoice_number', 'order_number', 'sku', 'reference', 'code'] : [] as $candidate) {
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

            // Convention could not name the table (domain-prefixed
            // tables like project_categories, taskboard_columns):
            // learn it from the record's own relation method —
            // the generated model knows its belongsTo target even
            // when the table name is not guessable.
            if ($targetTable === null) {
                foreach ($items as $firstItem) {
                    if (! $firstItem instanceof Model) {
                        continue;
                    }
                    $relName = Str::camel($base);
                    if (! method_exists($firstItem, $relName)) {
                        continue;
                    }
                    try {
                        $relation = $firstItem->{$relName}();
                        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                            $relTable = $relation->getRelated()->getTable();
                            $cols = Schema::getColumnListing($relTable);
                            foreach (['name', 'title', 'team_name', 'company_name', 'label', 'display_name', 'full_name', 'column_name', 'invoice_number', 'order_number', 'sku', 'reference', 'code'] as $candidate) {
                                if (in_array($candidate, $cols, true)) {
                                    $labelCol = $candidate;
                                    break;
                                }
                            }
                            if ($labelCol === null) {
                                foreach ($cols as $candidate) {
                                    if (str_ends_with((string) $candidate, '_name')) {
                                        $labelCol = (string) $candidate;
                                        break;
                                    }
                                }
                            }
                            if ($labelCol !== null) {
                                $targetTable = $relTable;
                            }
                        }
                    } catch (\Throwable $e) {
                        // Relation not resolvable — keep the raw id.
                    }
                    break;
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
        foreach (['name', 'title', 'heading', 'subject', 'team_name', 'company_name', 'label', 'display_name', 'full_name', 'invoice_number', 'order_number', 'sku', 'reference', 'code'] as $cand) {
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
        // An empty date is an em dash, never "today" — DateTimeImmutable('')
        // silently parses as now, which fabricated current dates for
        // null date cells (e.g. listing BOs missing a declared column).
        // The marker itself is kit-sensitive (DirectoryUi::emptyDash).
        if ($value === null || $value === '') {
            return DirectoryUi::emptyDash();
        }
        try {
            $dt = $value instanceof \DateTimeInterface
                ? $value
                : new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return (string) static::display($value);
        }

        if ($fieldType === 'date_weekday') {
            // The source's leave-list idiom: 01-02-2024 (Thursday).
            return $dt->format('d-m-Y (l)');
        }

        // A datetime sitting exactly at midnight is a date in disguise
        // (the common case for ported date columns) — never show ", 00:00".
        if ($fieldType === 'date' || $dt->format('H:i:s') === '00:00:00') {
            return $dt->format('d-m-Y');
        }

        if ($fieldType === 'datetime') {
            // Stored values are UTC; the source renders clock
            // times in the deployment's declared timezone (the
            // Mind's localization slot), as "08-02-2024 07:25 pm".
            try {
                $dt = $dt->setTimezone(new \DateTimeZone(DirectoryUi::displayTimezone()));
            } catch (\Throwable $e) {
                // An unresolvable zone keeps the stored wall time.
            }

            return $dt->format('d-m-Y h:i a');
        }

        return $dt->format('d-m-Y, h:i A');
    }

    public static function display(mixed $value): string|int|float
    {
        return match (true) {
            $value === null, $value === '' => DirectoryUi::emptyDash(),
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