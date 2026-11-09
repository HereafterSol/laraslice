<?php

namespace LaraSlice\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Audit\AuditLogger;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Security\Access;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolve directory column config (formatters) at request time from the
 * slice's registered path — works for path-repo, vendor, and published installs.
 */
final class DirectoryUi
{
    /** @var list<string> */
    public const HTML_FORMATTERS = [
        'code_badge', 'composite_user', 'user_stack', 'status_pill', 'money_breakdown', 'lines',
        'progress_bar', 'badge', 'text_with_badge', 'date_overdue', 'timer', 'icon_text'];

    /**
     * Merge ui.directory.columns from the live slice manifest over $fallback.
     *
     * @param  list<array<string, mixed>>  $fallback
     * @return list<array<string, mixed>>
     */
    /**
     * Option-level filter criteria (fidelity vocabulary): a
     * declared filter option may carry `criteria` — a map of
     * field => {op, value} conditions that REPLACE the plain
     * handle equality when that option is chosen. This is how
     * computed facets are declared: the source's "Overdue"
     * status (deadline in the past, not finished/canceled) and
     * its progress buckets (completion between two bounds).
     * Returns the condition list for the base service's
     * __conditions criterion, or null when the chosen option
     * carries no criteria (plain equality applies). Value
     * tokens: $today (Y-m-d), $now (datetime), $user (id).
     *
     * @return list<array{field: string, op: string, value: mixed}>|null
     */
    public static function optionConditions(array $declared, mixed $value, ?int $userId = null): ?array
    {
        foreach ((array) ($declared['options'] ?? []) as $option) {
            if (! is_array($option) || ! array_key_exists('value', $option)
                || (string) $option['value'] !== (string) $value
                || empty($option['criteria']) || ! is_array($option['criteria'])) {
                continue;
            }
            $resolve = static function ($v) use ($userId) {
                return match ($v) {
                    '$today' => date('Y-m-d'),
                    '$now' => date('Y-m-d H:i:s'),
                    '$user' => $userId,
                    default => $v,
                };
            };
            $conditions = [];
            foreach ($option['criteria'] as $field => $cond) {
                if (! is_array($cond) || empty($cond['op'])) {
                    continue;
                }
                $raw = $cond['value'] ?? null;
                $conditions[] = [
                    'field' => (string) $field,
                    'op' => (string) $cond['op'],
                    'value' => is_array($raw) ? array_map($resolve, $raw) : $resolve($raw),
                ];
            }

            return $conditions === [] ? null : $conditions;
        }

        return null;
    }

    public static function columnsForSlice(string $sliceName, array $fallback = []): array
    {
        $manifest = self::readManifest($sliceName);
        $dirCols = $manifest['ui']['directory']['columns'] ?? null;
        if (! is_array($dirCols) || $dirCols === []) {
            return $fallback;
        }

        $fieldIndex = self::fieldIndex($manifest);

        // The slice's root table rides every column as
        // parent_table: value resolvers (conventional pivots,
        // declared aggregates) key off it, and directories
        // generated before the row pipeline took a table
        // argument still get the context from here.
        $rootTable = null;
        foreach ((array) ($manifest['models'] ?? []) as $modelDef) {
            if (is_array($modelDef) && ! empty($modelDef['table'])
                && (! isset($modelDef['root']) || $modelDef['root'])) {
                $rootTable = (string) $modelDef['table'];
                break;
            }
        }
        if ($rootTable === null) {
            foreach ((array) ($manifest['models'] ?? []) as $modelDef) {
                if (is_array($modelDef) && ! empty($modelDef['table'])) {
                    $rootTable = (string) $modelDef['table'];
                    break;
                }
            }
        }

        $columns = [];
        foreach ($dirCols as $c) {
            if (! is_array($c)) {
                continue;
            }
            $key = $c['key'] ?? $c['handle'] ?? null;
            if (! $key) {
                continue;
            }
            $fmt = $c['formatter'] ?? $c['type'] ?? 'text';
            $label = (string) ($c['label'] ?? Str::headline((string) $key));
            // Display labels drop the storage suffix: "Leave Type Id" -> "Leave Type".
            if (str_ends_with((string) $key, '_id') && $key !== 'id') {
                $label = (string) preg_replace('/\s+Id$/i', '', $label);
            }
            $def = $fieldIndex[(string) $key] ?? [];
            $options = self::normalizeOptions($c['options'] ?? null);
            if ($options === []) {
                $options = self::normalizeOptions($def['options'] ?? null);
            }
            if ($options === []) {
                // The value set may live only in the directory's
                // filter declarations (literal options, or
                // options_from: distinct:<col>) — the inline
                // editor and other consumers need it on the
                // column itself.
                $options = self::filterDeclaredOptions($manifest, (string) $key, $sliceName);
            }
            if ($options === []) {
                // An FK column's value set is its target table's
                // rows (board_column_id -> taskboard_columns):
                // resolve id => label so inline editors on FK
                // columns are never blank.
                $options = self::foreignKeyOptions($c, (string) $key);
            }
            if ($options === [] && in_array((string) $key, ['role', 'user_role'], true)) {
                // Role editors on ported slices declare no options;
                // the value set is the app's roles table by convention.
                $options = self::roleOptions();
            }
            $columns[] = array_filter([
                'key' => $key,
                'label' => $label,
                'class' => $c['class'] ?? null,
                'sortable' => $c['sortable'] ?? true,
                'alwaysVisible' => ! empty($c['always_visible']),
                'formatter' => $fmt,
                'html' => in_array($fmt, self::HTML_FORMATTERS, true),
                'badge_from' => $c['badge_from'] ?? null,
                'thread' => $c['thread'] ?? null,
                'progress_field' => $c['progress_field'] ?? null,
                'subtitle_from' => $c['subtitle_from'] ?? null,
                'value_from' => $c['value_from'] ?? null,
                'compute' => $c['compute'] ?? null,
                'icon_from' => $c['icon_from'] ?? null,
                'breakdown' => $c['breakdown'] ?? null,
                'label_table' => $c['label_table'] ?? null,
                'label_column' => $c['label_column'] ?? null,
                'color_from' => $c['color_from'] ?? null,
                'aggregate' => $c['aggregate'] ?? null,
                'parent_table' => $rootTable,
                'relation' => $c['relation'] ?? null,
                'lines' => $c['lines'] ?? null,
                'options' => $options !== [] ? $options : null,
                'field_type' => isset($def['type']) && is_string($def['type']) ? $def['type'] : null,
            ], fn ($v) => $v !== null);
        }

        return $columns !== [] ? $columns : $fallback;
    }

    /**
     * Build display rows for a record's detail (show) view from the slice
     * manifest — the detail-page counterpart of columnsForSlice(). Values go
     * through the same DataTableRows pipeline as listings, so FK labels
     * resolve, declared option labels replace raw values, and dates render
     * human-readably instead of dumping raw model attributes (tenant keys,
     * raw ids, storage timestamps) onto the page.
     *
     * @return list<array{label: string, value: string, html: bool}>
     */
    /**
     * The record's own label for detail headers (QA5, 25 Oct
     * 2026): the generated chain (title → name → full_name →
     * heading → subject) missed the records whose identity is a
     * {thing}_name or a document number, so project and invoice
     * pages titled themselves "Project #1" / "Invoice #1" while
     * the record's real name sat in the fields below. Candidates
     * first, then any {thing}_name attribute, then null (the
     * caller keeps its "Slice #id" fallback).
     */
    public static function recordLabel(mixed $record): ?string
    {
        if (! is_object($record)) {
            return null;
        }
        foreach (['title', 'name', 'full_name', 'heading', 'subject', 'label'] as $candidate) {
            $value = data_get($record, $candidate);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }
        // A {thing}_name beats a code/number: a project is
        // titled by its name, not its short code.
        $attrs = method_exists($record, 'getAttributes')
            ? array_keys($record->getAttributes())
            : array_keys(get_object_vars($record));
        foreach ($attrs as $attr) {
            if (str_ends_with((string) $attr, '_name')) {
                $value = data_get($record, (string) $attr);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }
        foreach (['invoice_number', 'order_number', 'code', 'reference', 'number'] as $candidate) {
            $value = data_get($record, $candidate);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Identity subtitle for a detail header: the slice may declare
     * ui.show.identity_fields as `from` paths on the record (its own
     * attributes or dotted relation paths, e.g. designation +
     * department for a person). Resolved values join with ' • '.
     * Null when nothing is declared or nothing resolves — callers
     * keep their generic fallback.
     */
    public static function identitySubtitle(string $sliceName, mixed $record): ?string
    {
        if (! is_object($record)) {
            return null;
        }
        $manifest = self::readManifest($sliceName);
        $paths = $manifest['ui']['show']['identity_fields'] ?? null;
        if (! is_array($paths) || $paths === []) {
            return null;
        }
        $parts = [];
        foreach ($paths as $path) {
            $from = is_array($path) ? ($path['from'] ?? null) : $path;
            if (! is_string($from) || $from === '') {
                continue;
            }
            try {
                $value = data_get($record, $from);
            } catch (\Throwable $e) {
                $value = null;
            }
            if (is_scalar($value) && (string) $value !== '') {
                $parts[] = (string) $value;
            }
        }

        return $parts !== [] ? implode(' • ', $parts) : null;
    }

    /**
     * The detail card's heading: declared per slice
     * (ui.show.detail_title), generic otherwise.
     */
    public static function detailTitle(string $sliceName): string
    {
        $manifest = self::readManifest($sliceName);
        $title = $manifest['ui']['show']['detail_title'] ?? null;

        return is_string($title) && $title !== '' ? $title : 'Attribute Parameters';
    }

    /**
     * The empty-value marker. House rendering uses an em
     * dash; the replica kit renders the source's double
     * hyphen — a kit-level rendering difference applied at
     * the formatter seam (detail rows, pairs, anniversaries).
     */
    public static function emptyDash(): string
    {
        try {
            return Kit::current() === 'replica' ? '--' : '—';
        } catch (\Throwable $e) {
            return '—';
        }
    }

    /**
     * The deployment's display timezone, declared in the Mind
     * (localization.timezone) — stored datetimes are UTC and are
     * presented in the company's timezone, the way the source
     * systems render them. Falls back to the app timezone.
     */
    public static function displayTimezone(): string
    {
        try {
            $mind = SystemMind::load();
            $tz = is_array($mind) ? ($mind['localization']['timezone'] ?? null) : null;
            if (is_string($tz) && $tz !== '') {
                return $tz;
            }
        } catch (\Throwable $e) {
            // No readable Mind: fall through to the app timezone.
        }
        try {
            $app = function_exists('config') ? config('app.timezone') : null;
        } catch (\Throwable $e) {
            $app = null;
        }

        return is_string($app) && $app !== '' ? $app : 'UTC';
    }

    /**
     * The deployment's currency code, declared in the Mind
     * (localization.currency); the fallback matches the money
     * display convention already used by DataTableRows.
     */
    public static function currencyCode(): string
    {
        try {
            $mind = SystemMind::load();
            $code = is_array($mind) ? ($mind['localization']['currency'] ?? null) : null;
            if (is_string($code) && $code !== '') {
                return $code;
            }
        } catch (\Throwable $e) {
            // No readable Mind: fall through to the convention.
        }

        return 'PKR';
    }

    /**
     * A recurring annual date (a joining date shown as a work
     * anniversary) rendered as its next occurrence, humanized —
     * "6 months from now". The derivation is declared per field
     * (type: anniversary); the stored date itself never changes.
     */
    private static function anniversaryText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::emptyDash();
        }
        try {
            $date = \Illuminate\Support\Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            return self::emptyDash();
        }
        $next = $date->copy()->setYear(\Illuminate\Support\Carbon::now()->year);
        if ($next->isPast()) {
            $next->addYear();
        }

        return $next->diffForHumans();
    }

    /**
     * The declared presentation of the detail card (ui.show
     * .detail_style): 'grid' (default) is the two-column small-caps
     * grid; 'list' is the source hubs' definition list — one row
     * per field, muted label left, value right, under the declared
     * detail_title.
     */
    public static function detailStyle(string $sliceName): string
    {
        $manifest = self::readManifest($sliceName);

        return ($manifest['ui']['show']['detail_style'] ?? 'grid') === 'list' ? 'list' : 'grid';
    }

    /**
     * Whether the slice declared `ui.show.detail_fields: none` —
     * its overview carries no detail card at all (a fully
     * widget-composed hub). Views gate the card on this because
     * an empty detailRows() otherwise falls back to a raw
     * attribute grid.
     */
    public static function detailsSuppressed(string $sliceName): bool
    {
        $manifest = self::readManifest($sliceName);

        return ($manifest['ui']['show']['detail_fields'] ?? null) === 'none';
    }

    public static function detailRows(string $sliceName, mixed $record): array
    {
        $manifest = self::readManifest($sliceName);

        // H3: a declared profile composition wins outright. Entries
        // name a label and a `from` path resolved against the record —
        // its own attributes or dotted relation paths
        // (employeeDetail.designation.name, user.email) — so a detail
        // page can present a curated profile that spans the record's
        // relations instead of one table's columns.
        $declared = $manifest['ui']['show']['detail_fields'] ?? null;
        // (Suppression is also exposed as detailsSuppressed() —
        // the views gate the whole detail card on it, since an
        // empty row set otherwise falls back to a raw attribute
        // grid.)
        // A declared 'none' suppresses the detail card entirely —
        // hubs whose overview is fully composed of widgets (the
        // project hub: progress, charts, and a summary text card)
        // carry no field grid at all.
        if ($declared === 'none') {
            return [];
        }
        if (is_array($declared) && $declared !== [] && is_object($record)) {
            $rows = [];
            foreach ($declared as $entry) {
                if (! is_array($entry) || empty($entry['from'])) {
                    continue;
                }
                // A declared path that cannot resolve (a relation the
                // data model lacks) renders as an em dash — a broken
                // declaration must never break the detail page.
                try {
                    $value = data_get($record, (string) $entry['from']);
                } catch (\Throwable $e) {
                    $value = null;
                }
                // Profile delegation: a bare field the record itself
                // does not carry may live on its linked user (the
                // profile tables — employee_details, client_details —
                // store identity on the user row; the source's detail
                // blades read it through that link).
                if ($value === null && ! str_contains((string) $entry['from'], '.')) {
                    try {
                        $value = data_get($record, 'user.'.(string) $entry['from']);
                    } catch (\Throwable $e) {
                        $value = null;
                    }
                }
                $type = (string) ($entry['type'] ?? 'text');
                if ($type === 'datetime' && $value !== null && $value !== '') {
                    try {
                        $dtv = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);
                        $value = $dtv->setTimezone(new \DateTimeZone(self::displayTimezone()));
                    } catch (\Throwable $e) {
                        // Keep the raw value; formatting falls through.
                    }
                }
                $text = match ($type) {
                    'date', 'datetime' => DataTableRows::formatDateValue($value, $type),
                    'money' => is_numeric($value)
                        ? self::currencyCode().rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.')
                        : (string) ($value ?? self::emptyDash()),
                    'anniversary' => self::anniversaryText($value),
                    'status' => (string) ($value ?? self::emptyDash()),
                    default => (string) DataTableRows::display($value),
                };
                // A declared value map translates stored codes to
                // their presented labels (gender male → Male, locale
                // en → English) — presentation vocabulary belongs to
                // the declaration, not to a formatter's guess.
                if (is_array($entry['map'] ?? null) && is_scalar($value)
                    && array_key_exists((string) $value, $entry['map'])) {
                    $text = (string) $entry['map'][(string) $value];
                }
                $rows[] = [
                    'label' => (string) ($entry['label'] ?? Str::headline((string) $entry['from'])),
                    'value' => $text === '' ? self::emptyDash() : $text,
                    'html' => false,
                ];
            }
            if ($rows !== []) {
                return $rows;
            }
        }

        $fields = self::fieldIndex($manifest);
        $attributes = is_object($record) && method_exists($record, 'getAttributes')
            ? $record->getAttributes()
            : (is_array($record) ? $record : []);
        if ($fields === [] || $attributes === []) {
            return [];
        }

        // System/audit columns never reach a detail page (the same
        // hygiene law as forms, Tier 0): ownership stamps, approval
        // stamps and tenant keys are machinery, not profile content.
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'company_id', 'tenant_id', 'organization_id', 'workflow_state_entered_at',
            'added_by', 'last_updated_by', 'created_by', 'updated_by', 'approved_by', 'approved_at', 'event_id', 'password', 'remember_token'];
        $columns = [];
        foreach ($fields as $name => $def) {
            $name = (string) $name;
            if (in_array($name, $skip, true) || str_ends_with($name, '_by') || str_ends_with($name, '_ip') || ! array_key_exists($name, $attributes) || ! empty($def['hidden'])) {
                continue;
            }
            $label = (string) ($def['label'] ?? Str::headline($name));
            if (str_ends_with($name, '_id') && $name !== 'id') {
                $label = (string) preg_replace('/\s+Id$/', '', $label);
            }
            $options = self::normalizeOptions($def['options'] ?? null);
            $isStatus = in_array($name, ['status', 'state', 'stage'], true);
            $fieldType = isset($def['type']) && is_string($def['type']) ? $def['type'] : null;
            $formatter = 'text';
            if ($isStatus) {
                $formatter = 'status_pill';
            } elseif (in_array($fieldType, ['boolean', 'bool'], true)) {
                // Raw storage 0/1 never reaches a detail page.
                $formatter = static fn ($v) => $v ? 'Yes' : 'No';
            }
            $columns[] = array_filter([
                'key' => $name,
                'label' => $label,
                'formatter' => $formatter,
                'html' => $isStatus,
                'options' => $options !== [] ? $options : null,
                'field_type' => $fieldType,
            ], fn ($v) => $v !== null);
        }
        if ($columns === []) {
            return [];
        }

        $row = DataTableRows::from([$record], $columns)[0] ?? [];
        $rows = [];
        foreach ($columns as $col) {
            $rows[] = [
                'label' => $col['label'],
                'value' => (string) ($row[$col['key']] ?? '—'),
                'html' => ! empty($col['html']),
            ];
        }

        return $rows;
    }

    /**
     * Resolve the related-record tabs for a slice's detail (show) page.
     *
     * Sources, in precedence order, deduplicated by relation name:
     *  1. `ui.show.tabs` entries declared with `type: relation`;
     *  2. the root model's `hasMany` relations in the manifest;
     *  3. repeater (line-item) form fields — aggregate children are the
     *     record's most important related records.
     *
     * Declared `type: audit` tabs (e.g. an Activity tab) are first-class
     * too: they carry no relation; with a record they gain the record's
     * audit-trail `entries` (AuditLogger, keyed by model class + id)
     * and a `count` — an empty trail renders the panel's empty state,
     * never a silently missing tab.
     *
     * Curation (hub tab curation, 27 Oct 2026): `ui.show.exclude_tabs`
     * is a list of relation names and/or tab handles whose tabs must
     * NOT render — the exclusion wins over every source above,
     * declared tabs included. A declared tab carrying `hidden: true`
     * is likewise suppressed, together with its inferred twin (the
     * exclusion is registered under both its relation and its handle).
     *
     * When a record is supplied and exposes the relation method, each tab
     * gains a `count` so the detail page reads as a hub, not a flat grid.
     * (F3: this replaces a generated-template fallback that read the
     * manifest as JSON and silently found nothing once manifests became
     * YAML-only.)
     *
     * @return list<array{handle: string, label: string, type: string, relation: string, count?: int}>
     */
    public static function relatedTabs(string $sliceName, mixed $record = null): array
    {
        return self::relatedTabsFromManifest(self::readManifest($sliceName), $record);
    }

    /**
     * Resolve a declared tab post (ui.show.tabs[].post) to the
     * child slice's store URL. The declaration names the child
     * slice, the body field, and the foreign key the parent
     * record fills. The route name prefix is read from the
     * child slice's own routes file (the same source
     * SliceManager registers declared routes from), and the
     * form is offered only when the current user holds the
     * child slice's create ability — its store controller
     * authorizes again on submit regardless.
     *
     * @param  array<string, mixed>  $post
     * @return array{post: array<string, mixed>, post_url: string}|null
     */
    public static function tabPost(array $post): ?array
    {
        $sliceName = (string) ($post['slice'] ?? '');
        $field = (string) ($post['field'] ?? '');
        $foreignKey = (string) ($post['foreign_key'] ?? '');
        if ($sliceName === '' || $field === '' || $foreignKey === '') {
            return null;
        }

        try {
            $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
            $slice = $manager->getSlice($sliceName);
            if ($slice === null) {
                return null;
            }
            $routesPath = $slice->getRoutesPath();
            $webFile = $routesPath ? $routesPath.'/web.php' : null;
            if (! $webFile || ! is_file($webFile)) {
                return null;
            }
            if (! preg_match("/Route::prefix\\('([^']+)'\\)->name\\('([^']+)\\.'\\)/", (string) file_get_contents($webFile), $m)) {
                return null;
            }
            $namePrefix = $m[2];
            if (! \Illuminate\Support\Facades\Route::has($namePrefix.'.store')) {
                return null;
            }
            $user = auth()->user();
            if ($user !== null) {
                foreach ((array) ($slice->raw['permissions'] ?? []) as $permission) {
                    if (is_string($permission) && str_ends_with($permission, '.create') && ! $user->can($permission)) {
                        return null;
                    }
                }
            }

            return [
                'post' => [
                    'field' => $field,
                    'foreign_key' => $foreignKey,
                    'label' => (string) ($post['label'] ?? 'Add'),
                    'button' => (string) ($post['button'] ?? 'Post'),
                    'placeholder' => (string) ($post['placeholder'] ?? ''),
                ],
                'post_url' => route($namePrefix.'.store'),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The declared companion-account creation for a details slice
     * (account_creation: {foreign_key: user_id, role: employee}):
     * the create form additionally collects a login account
     * (name/email/password) and the store creates the user — with
     * the declared role — in the same step, exactly like the
     * source's single Add Employee / Add Client form. Null when
     * the slice declares none.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{foreign_key: string, role: string}|null
     */
    public static function accountCreationFromManifest(array $manifest): ?array
    {
        $decl = $manifest['account_creation'] ?? null;
        if (! is_array($decl) || empty($decl['role'])) {
            return null;
        }

        return [
            'foreign_key' => (string) ($decl['foreign_key'] ?? 'user_id'),
            'role' => (string) $decl['role'],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{handle: string, label: string, type: string, relation: string, count?: int}>
     */
    public static function relatedTabsFromManifest(array $manifest, mixed $record = null): array
    {
        $tabs = [];
        $seen = [];
        $exclude = self::excludedTabKeys($manifest);
        $add = static function (string $relation, ?string $label, ?string $handle = null, array $extras = []) use (&$tabs, &$seen, &$exclude): void {
            // Curation: ui.show.exclude_tabs (and hidden declarations,
            // which register here) suppress a tab by relation OR by
            // handle — the exclusion wins over every tab source.
            $effectiveHandle = $handle !== null && $handle !== '' ? $handle : $relation;
            if (isset($exclude[$relation]) || isset($exclude[$effectiveHandle])) {
                return;
            }
            // Dedupe is by RELATION for plain tabs (a declared
            // tab, a hasMany relation and a repeater naming the
            // same relation are one tab). A RENDER-MODE tab
            // (board / gantt) is an additional presentation of
            // its relation, keyed by its declared handle — a
            // table and a board over one relation are two tabs.
            $isRenderMode = in_array($extras['render'] ?? 'table', ['board', 'gantt', 'calendar', 'thread', 'stack'], true);
            $key = $isRenderMode ? ($handle !== null && $handle !== '' ? $handle : $relation) : $relation;
            if ($relation === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $tabs[] = array_merge([
                'handle' => $isRenderMode ? $key : $relation,
                'label' => $label !== null && $label !== '' ? $label : Str::headline($relation),
                'type' => 'relation',
                'relation' => $relation,
            ], $extras);
        };

        foreach (($manifest['ui']['show']['tabs'] ?? []) as $tab) {
            if (! is_array($tab)) {
                continue;
            }
            if (! empty($tab['hidden'])) {
                // A hidden declaration suppresses the tab AND its
                // inferred twin: register the exclusion under both
                // the relation and the handle before any source runs.
                $hiddenRelation = (string) ($tab['relation'] ?? $tab['handle'] ?? '');
                if ($hiddenRelation !== '') {
                    $exclude[$hiddenRelation] = true;
                }
                if (! empty($tab['handle'])) {
                    $exclude[(string) $tab['handle']] = true;
                }

                continue;
            }
            if (($tab['type'] ?? '') === 'relation') {
                $extras = [];
                foreach (['render', 'group_by', 'start_field', 'end_field', 'label_field'] as $k) {
                    if (! empty($tab[$k])) {
                        $extras[$k] = (string) $tab[$k];
                    }
                }
                // Stacked composition (render: stack): several
                // relation blocks in ONE tab, in declared order —
                // the shape of composite hub tabs in the source
                // (a card above a table). The tab's primary
                // relation is its first block's.
                $stackRelation = null;
                if (($tab['render'] ?? '') === 'stack' && is_array($tab['blocks'] ?? null)) {
                    $blocks = [];
                    foreach ($tab['blocks'] as $block) {
                        if (! is_array($block) || empty($block['relation'])) {
                            continue;
                        }
                        $blocks[] = [
                            'relation' => (string) $block['relation'],
                            'label' => isset($block['label']) ? (string) $block['label'] : '',
                            'layout' => (string) ($block['layout'] ?? 'table'),
                        ];
                    }
                    if ($blocks !== []) {
                        $extras['blocks'] = $blocks;
                        $stackRelation = $blocks[0]['relation'];
                    }
                }
                // Declared inline post (a reply box on a ticket's
                // Replies tab): resolve the child slice's store
                // route; unresolved or unpermitted posts render
                // no form at all.
                if (is_array($tab['post'] ?? null)) {
                    $resolved = self::tabPost($tab['post']);
                    if ($resolved !== null) {
                        $extras['post'] = $resolved['post'];
                        $extras['post_url'] = $resolved['post_url'];
                    }
                }
                $add(
                    (string) ($tab['relation'] ?? $tab['handle'] ?? $stackRelation ?? ''),
                    isset($tab['label']) ? (string) $tab['label'] : null,
                    isset($tab['handle']) ? (string) $tab['handle'] : null,
                    $extras
                );
            } elseif (($tab['type'] ?? '') === 'audit') {
                // Audit tabs carry no relation: they render the
                // record's audit trail (entries attached below when
                // a record is supplied) or its empty state — never
                // silently nothing.
                $handle = (string) ($tab['handle'] ?? 'activity');
                if ($handle === '' || isset($seen['audit:'.$handle]) || isset($exclude[$handle])) {
                    continue;
                }
                $seen['audit:'.$handle] = true;
                $tabs[] = [
                    'handle' => $handle,
                    'label' => ! empty($tab['label']) ? (string) $tab['label'] : 'Activity',
                    'type' => 'audit',
                    'relation' => '',
                ];
            } elseif (($tab['type'] ?? '') === 'chart') {
                // Chart tabs aggregate a relation into a series
                // (today: the project burndown over the record's
                // tasks). The series is computed below when a
                // record is supplied; the declaration carries
                // the chart kind + the relation it aggregates.
                $handle = (string) ($tab['handle'] ?? 'chart');
                if ($handle === '' || isset($seen['chart:'.$handle]) || isset($exclude[$handle])) {
                    continue;
                }
                $seen['chart:'.$handle] = true;
                $tabs[] = [
                    'handle' => $handle,
                    'label' => ! empty($tab['label']) ? (string) $tab['label'] : 'Chart',
                    'type' => 'chart',
                    'chart' => (string) ($tab['chart'] ?? ''),
                    'relation' => (string) ($tab['relation'] ?? ''),
                ];
            } elseif (($tab['type'] ?? '') === 'permissions') {
                // Permissions tabs render the record's person's
                // module permission matrix (the source employee
                // hub's Permissions tab): modules × permission
                // types from permission_role + permission_types,
                // with per-user customisations. Data attaches
                // below when a record is supplied.
                $handle = (string) ($tab['handle'] ?? 'permissions');
                if ($handle === '' || isset($seen['permissions:'.$handle]) || isset($exclude[$handle])) {
                    continue;
                }
                $seen['permissions:'.$handle] = true;
                $tabs[] = [
                    'handle' => $handle,
                    'label' => ! empty($tab['label']) ? (string) $tab['label'] : 'Permissions',
                    'type' => 'permissions',
                    'relation' => '',
                ];
            }
        }

        $root = $manifest['models'][0] ?? null;
        if (is_array($root)) {
            foreach (($root['relations'] ?? []) as $rel) {
                if (is_array($rel) && ($rel['type'] ?? '') === 'hasMany') {
                    $add((string) ($rel['name'] ?? ''), isset($rel['label']) ? (string) $rel['label'] : null);
                }
            }
        }

        foreach (($manifest['ui']['forms']['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach (($section['fields'] ?? []) as $field) {
                if (is_array($field) && ($field['type'] ?? '') === 'repeater' && isset($field['handle'])) {
                    $add((string) $field['handle'], isset($field['label']) ? (string) $field['label'] : null);
                }
            }
        }

        if (is_object($record)) {
            foreach ($tabs as $i => $tab) {
                if (($tab['type'] ?? '') === 'audit') {
                    $entries = self::auditEntriesForRecord($record);
                    $tabs[$i]['entries'] = $entries;
                    $tabs[$i]['count'] = count($entries);

                    continue;
                }
                if (($tab['type'] ?? '') === 'chart') {
                    $tabs[$i]['chart_data'] = self::chartTabData($tab, $record);

                    continue;
                }
                if (($tab['type'] ?? '') === 'permissions') {
                    $tabs[$i] = self::enrichPermissionsTab($tab, $record);

                    continue;
                }
                $relation = $tab['relation'];
                $method = self::relationMethod($record, (string) $relation);
                if ($method === null) {
                    continue;
                }
                try {
                    $query = $record->{$method}();
                    if (is_object($query)) {
                        // Relations forward count() via __call, so probe by
                        // calling it — the catch keeps a bad relation safe.
                        $tabs[$i]['count'] = (int) $query->count();
                    }
                } catch (\Throwable $e) {
                    // A broken relation must never break the detail page.
                }
                // H1: a hub tab renders the RELATED slice's declared
                // list definition (columns + DataTableRows values),
                // never a generic ID/Summary/Status skeleton. Tabs that
                // cannot be resolved are flagged 'unresolved' so the
                // drift/presentation checks can catch them.
                $tabs[$i] = self::enrichTabularTab($tabs[$i], $record);
            }
        }

        return $tabs;
    }

    /**
     * Series data for a declared chart tab. One kind today:
     * 'burndown' — the record's tasks aggregated by
     * BurndownChart (the source systems' burndown math). The
     * window defaults to the record's own start/deadline; the
     * tab's date form may narrow it via bd_from / bd_to.
     *
     * @param  array<string, mixed>  $tab
     * @return array<string, mixed>|null
     */
    private static function chartTabData(array $tab, object $record): ?array
    {
        if (($tab['chart'] ?? '') !== 'burndown') {
            return null;
        }
        $relation = (string) ($tab['relation'] ?? '');
        $method = $relation === '' ? null : self::relationMethod($record, $relation);
        if ($method === null) {
            return null;
        }
        try {
            $query = $record->{$method}();
            if (! is_object($query) || ! method_exists($query, 'get')) {
                return null;
            }
            $tasks = $query->limit(1000)->get();
        } catch (\Throwable $e) {
            return null;
        }

        try {
            $request = function_exists('request') ? request() : null;
        } catch (\Throwable $e) {
            $request = null;
        }
        $from = $request !== null ? $request->query('bd_from') : null;
        $to = $request !== null ? $request->query('bd_to') : null;

        return BurndownChart::series(
            $tasks,
            isset($record->start_date) ? (string) $record->start_date : null,
            isset($record->deadline) ? (string) $record->deadline : null,
            is_string($from) && $from !== '' ? $from : null,
            is_string($to) && $to !== '' ? $to : null,
        );
    }

    /**
     * Data for a declared permissions tab: the record's
     * person's permission matrix (modules × types, effective
     * values from role grants + per-user customisations), the
     * write endpoints, and whether the VIEWER may edit — only
     * a user holding the employee edit ability (or a super
     * persona) edits another person's types, and never for an
     * admin-role target (the source's own rule).
     *
     * @param  array<string, mixed>  $tab
     * @return array<string, mixed>
     */
    private static function enrichPermissionsTab(array $tab, object $record): array
    {
        $userId = null;
        if (isset($record->user_id) && is_numeric($record->user_id)) {
            $userId = (int) $record->user_id;
        } elseif (method_exists($record, 'getTable') && $record->getTable() === 'users' && $record->getKey()) {
            $userId = (int) $record->getKey();
        }
        if ($userId === null) {
            return $tab + ['matrix' => null, 'editable' => false];
        }

        $tab['user_id'] = $userId;
        $tab['matrix'] = \LaraSlice\Core\Security\UserPermissionTypes::matrixForUser($userId);

        try {
            $viewer = function_exists('auth') ? auth()->user() : null;
        } catch (\Throwable $e) {
            $viewer = null;
        }
        $tab['editable'] = is_object($viewer)
            && ($tab['matrix'] !== null && ($tab['matrix']['admin_role'] ?? false) !== true)
            && (\LaraSlice\Core\Security\Access::isSuperAdmin($viewer)
                || \LaraSlice\Core\Security\Access::isSuperDev($viewer)
                || \LaraSlice\Core\Security\Access::allows($viewer, 'employee_detail.edit'));

        try {
            $tab['update_url'] = \Illuminate\Support\Facades\Route::has('laraslice.user_permissions.update')
                ? route('laraslice.user_permissions.update', ['userId' => $userId]) : null;
            $tab['reset_url'] = \Illuminate\Support\Facades\Route::has('laraslice.user_permissions.reset')
                ? route('laraslice.user_permissions.reset', ['userId' => $userId]) : null;
        } catch (\Throwable $e) {
            $tab['update_url'] = null;
            $tab['reset_url'] = null;
        }

        return $tab;
    }

    /**
     * The declared landing tab for a show page
     * (ui.show.default_tab): the handle of a declared show tab
     * (or 'overview') the page opens on when no ?tab= is in
     * the URL — e.g. a ticket hub opens on its conversation,
     * as the source systems' ticket page does. Null when the
     * declaration names nothing resolvable; callers fall back
     * to 'overview'.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function showDefaultTabFromManifest(array $manifest): ?string
    {
        $declared = $manifest['ui']['show']['default_tab'] ?? null;
        if (! is_string($declared) || $declared === '') {
            return null;
        }
        if ($declared === 'overview') {
            return 'overview';
        }
        foreach ((array) ($manifest['ui']['show']['tabs'] ?? []) as $tab) {
            if (is_array($tab) && ($tab['handle'] ?? null) === $declared && empty($tab['hidden'])) {
                return $declared;
            }
        }

        return null;
    }

    /**
     * The tab-curation exclusion set: `ui.show.exclude_tabs` entries,
     * keyed for lookup. Each entry names a relation or a tab handle
     * whose tab must not render (see relatedTabsFromManifest).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, true>
     */
    public static function excludedTabKeys(array $manifest): array
    {
        $set = [];
        foreach ((array) ($manifest['ui']['show']['exclude_tabs'] ?? []) as $key) {
            if (is_string($key) && $key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }

    /**
     * The declared label for the fields (record detail) tab — the
     * `ui.show.tabs` entry of `type: fields`, e.g. `label: Profile`
     * on people-shaped hubs. Null when no fields tab is declared
     * (or it declares no label): callers fall back to their default
     * wording ('Overview' on the overview hub strip).
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function fieldsTabLabelFromManifest(array $manifest): ?string
    {
        foreach ((array) ($manifest['ui']['show']['tabs'] ?? []) as $tab) {
            if (is_array($tab) && ($tab['type'] ?? '') === 'fields' && ! empty($tab['label'])) {
                return (string) $tab['label'];
            }
        }

        return null;
    }

    /**
     * The record's audit trail for an audit tab, from the framework's
     * audit infrastructure (AuditLogger::forEntity — entries are
     * recorded with entity_type = model class, entity_id = key).
     * Best-effort by design: no audit table, no container or any
     * query failure yields an empty list, and the tab renders its
     * empty state instead of breaking the detail page.
     *
     * @return list<array{action: string, slice: string, actor: string, created_at: string, old_values: array|null, new_values: array|null}>
     */
    protected static function auditEntriesForRecord(object $record): array
    {
        try {
            $key = method_exists($record, 'getKey') ? $record->getKey() : ($record->id ?? null);
            if ($key === null || $key === '') {
                return [];
            }

            return AuditLogger::forEntity(get_class($record), $key, 50)
                ->map(static fn ($row): array => [
                    'action' => (string) ($row->action ?? ''),
                    'slice' => (string) ($row->slice ?? ''),
                    'actor' => (string) ($row->actor_email ?? $row->actor_id ?? 'System'),
                    'created_at' => (string) ($row->created_at ?? ''),
                    'old_values' => is_array($row->old_values ?? null) ? $row->old_values : null,
                    'new_values' => is_array($row->new_values ?? null) ? $row->new_values : null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @var array<string, string>|null table name => owning slice name */
    private static ?array $sliceNameByTable = null;

    /**
     * Map a database table to the slice that owns it (root model wins),
     * scanning the discovered manifests once per request.
     */
    private static function sliceNameForTable(string $table): ?string
    {
        if (self::$sliceNameByTable === null) {
            self::$sliceNameByTable = [];
            try {
                if (function_exists('app') && app()->bound(SliceManager::class)) {
                    $manager = app(SliceManager::class);
                    $manager->discover();
                    foreach ($manager->discoveredSlices() as $slice) {
                        // Root ownership via the manifest's own table
                        // resolution (handles every manifest shape,
                        // including porter-era manifests with no
                        // models section at all).
                        try {
                            $primary = $slice->primaryTable();
                        } catch (\Throwable $e) {
                            $primary = null;
                        }
                        if (is_string($primary) && $primary !== '') {
                            self::$sliceNameByTable[$primary] = (string) $slice->name;
                        }
                        // Declared child models in list-shaped manifests.
                        foreach (($slice->raw['models'] ?? []) as $model) {
                            if (! is_array($model) || empty($model['table'])) {
                                continue;
                            }
                            $t = (string) $model['table'];
                            if (! isset(self::$sliceNameByTable[$t]) || ! empty($model['root'])) {
                                self::$sliceNameByTable[$t] = (string) $slice->name;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Enrichment is best-effort; tabs fall back unflagged-free.
            }
        }

        return self::$sliceNameByTable[$table] ?? null;
    }

    /**
     * H1 enrichment: resolve the tab relation's owning slice, take its
     * declared directory columns (or a field-derived fallback), fetch
     * the rows through the relation and render every cell through the
     * same DataTableRows pipeline the listings use — FK labels, option
     * labels, composite people cells, progress bars and all.
     *
     * @param  array<string, mixed>  $tab
     * @return array<string, mixed>
     */
    /**
     * The callable relation method for a declared relation name.
     * Manifests name relations in the porter's snake_case form
     * (project_activity, task_history) while generated models
     * expose camelCase methods (projectActivity, taskHistory):
     * an exact method wins, the camel form is the fallback, and
     * a name that resolves neither way is not a relation at all.
     */
    private static function relationMethod(object $record, string $relation): ?string
    {
        if ($relation === '') {
            return null;
        }
        if (method_exists($record, $relation)) {
            return $relation;
        }
        $camel = Str::camel($relation);
        if ($camel !== $relation && method_exists($record, $camel)) {
            return $camel;
        }

        return null;
    }

    private static function enrichTabularTab(array $tab, object $record): array
    {
        // Stacked composition (render: stack): enrich each
        // declared block as a tabular tab of its own relation,
        // then hand the panel the ordered blocks. A block whose
        // relation does not resolve renders its empty state.
        if (($tab['render'] ?? '') === 'stack' && is_array($tab['blocks'] ?? null)) {
            $blocks = [];
            foreach ($tab['blocks'] as $block) {
                $sub = self::enrichTabularTab([
                    'handle' => (string) ($block['relation'] ?? ''),
                    'label' => (string) ($block['label'] ?? ''),
                    'type' => 'relation',
                    'relation' => (string) ($block['relation'] ?? ''),
                ], $record);
                $blocks[] = [
                    'relation' => (string) ($block['relation'] ?? ''),
                    'label' => (string) ($block['label'] ?? ''),
                    'layout' => (string) ($block['layout'] ?? 'table'),
                    'columns' => $sub['columns'] ?? [],
                    'rows' => $sub['rows'] ?? [],
                    'create_url' => $sub['create_url'] ?? null,
                    'create_label' => $sub['create_label'] ?? 'Add',
                    'export_name' => $sub['export_name'] ?? null,
                    'inline' => $sub['inline'] ?? null,
                    'slice' => $sub['slice'] ?? null,
                    'records' => $sub['records'] ?? null,
                ];
            }
            $tab['blocks'] = $blocks;
            // The show blade's render-mode dispatch gates on
            // records being set; the stack's payload is its
            // blocks, so records is an intentional empty set.
            $tab['records'] = [];

            return $tab;
        }
        $relation = (string) ($tab['relation'] ?? '');
        $method = $relation === '' ? null : self::relationMethod($record, $relation);
        if ($method === null) {
            return $tab;
        }
        // Render-mode tabs (board / gantt / calendar / thread)
        // consume the RAW related records in their own kit
        // partials — the tabular column pipeline does not apply.
        $render = (string) ($tab['render'] ?? 'table');
        if ($render === 'board' || $render === 'gantt' || $render === 'calendar' || $render === 'thread') {
            try {
                $query = $record->{$method}();
                if (is_object($query) && method_exists($query, 'get')) {
                    $limit = $render === 'calendar' ? 500 : ($render === 'thread' ? 200 : 100);
                    $tab['records'] = $query->limit($limit)->get();
                    if ($render === 'calendar' && is_object($tab['records']) && method_exists($tab['records'], 'loadMissing')) {
                        // A schedule's display rides on its shift
                        // relation (name / short code / colour) —
                        // loaded under either conventional name
                        // the model carries.
                        foreach (['shift', 'employeeShift'] as $shiftRelation) {
                            try {
                                $first = is_object($tab['records']) && method_exists($tab['records'], 'first') ? $tab['records']->first() : null;
                                if (is_object($first) && method_exists($first, $shiftRelation)) {
                                    $tab['records']->loadMissing($shiftRelation);
                                    break;
                                }
                            } catch (\Throwable $e) {
                                // Rows render without shift dressing.
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $tab['records'] = [];
            }
            if ($render === 'board') {
                $tab = self::enrichBoardTab($tab, $record, $method);
            }

            return $tab;
        }
        try {
            $query = $record->{$method}();
            if (! is_object($query) || ! method_exists($query, 'getRelated')) {
                return $tab;
            }
            $related = $query->getRelated();
            $sliceName = self::sliceNameForTable($related->getTable());
            if ($sliceName === null) {
                return $tab + ['unresolved' => true];
            }
            $columns = self::columnsForSlice($sliceName);
            if ($columns === []) {
                $columns = self::fallbackTabColumns($sliceName);
            }
            // Sanity: the resolved slice's columns must describe THIS
            // relation's table. Name/model resolution ambiguities can
            // bind a child table to a sibling slice (the shifts/quota
            // tangle); when no declared column exists on the related
            // table, derive columns from the model itself instead of
            // rendering another slice's directory — and skip that
            // slice's service decoration, which would discard the
            // child's fields the same way.
            $sliceOwnsTable = $columns !== [] && self::columnsDescribeTable($columns, $related->getTable());
            if (! $sliceOwnsTable) {
                $foreignKey = null;
                if ($query instanceof HasOneOrMany) {
                    $foreignKey = $query->getForeignKeyName();
                }
                $columns = self::modelDerivedColumns($related->getTable(), $foreignKey);
            }
            if ($columns === []) {
                return $tab + ['unresolved' => true];
            }
            $rows = $query->limit(50)->get();
            // Decorate through the related slice's own listing
            // mapping when it has a service — tabs then show the
            // same computed values as the related directory itself.
            $service = $sliceOwnsTable ? self::serviceForSlice($sliceName) : null;
            if ($service !== null && method_exists($service, 'listingBusinessObjects')) {
                try {
                    $rows = $service->listingBusinessObjects($rows);
                } catch (\Throwable $e) {
                    // Raw models still render through the pipeline.
                }
            }
            // Tab content contract v2: a resolved tab is a working
            // list, not a picture of one — scoped create/export,
            // per-row actions and the related slice's declared
            // inline editor ride along (see tabActionSurface()).
            $surface = $sliceOwnsTable ? self::tabActionSurface($sliceName, $tab, $query, $record, $rows) : null;
            $tab['columns'] = $columns;
            $tab['rows'] = DataTableRows::from($rows, $columns, $surface['extra'] ?? null);
            if ($surface !== null) {
                foreach (['slice', 'create_url', 'create_label', 'export_name', 'inline'] as $k) {
                    if (isset($surface['tab'][$k])) {
                        $tab[$k] = $surface['tab'][$k];
                    }
                }
                if (! empty($surface['raw'])) {
                    foreach ($tab['rows'] as $i => $row) {
                        $rid = $row['__id'] ?? null;
                        if ($rid !== null && isset($surface['raw'][$rid])) {
                            $tab['rows'][$i]['__raw_'.$surface['tab']['inline']['field']] = $surface['raw'][$rid];
                        }
                    }
                }
            }

            return $tab;
        } catch (\Throwable $e) {
            return $tab + ['unresolved' => true];
        }
    }

    /** @var array<string, SliceManifest|null> slice name => manifest */
    private static array $manifestByName = [];

    private static function manifestForSlice(string $sliceName): ?SliceManifest
    {
        if (! array_key_exists($sliceName, self::$manifestByName)) {
            self::$manifestByName[$sliceName] = null;
            try {
                if (function_exists('app') && app()->bound(SliceManager::class)) {
                    $manager = app(SliceManager::class);
                    $manager->discover();
                    // Two slices can share one name (a fidelity
                    // slice and its porter twin). The manifest the
                    // tab's columns came from is the one
                    // readManifest() resolved — match by path
                    // first so the action surface (inline_edit,
                    // routes) comes from that same Heart.
                    $wantDir = null;
                    $mp = self::manifestPath($sliceName);
                    if ($mp !== null) {
                        $wantDir = dirname($mp);
                    }
                    $byName = null;
                    foreach ($manager->discoveredSlices() as $slice) {
                        if ((string) $slice->name !== $sliceName) {
                            continue;
                        }
                        if ($wantDir !== null && rtrim((string) $slice->path, '/') === rtrim($wantDir, '/')) {
                            self::$manifestByName[$sliceName] = $slice;
                            break;
                        }
                        $byName ??= $slice;
                    }
                    if (self::$manifestByName[$sliceName] === null) {
                        self::$manifestByName[$sliceName] = $byName;
                    }
                }
            } catch (\Throwable $e) {
                // Action surface is best-effort.
            }
        }

        return self::$manifestByName[$sliceName];
    }

    /**
     * The slice's web route-name prefix, parsed from its own
     * routes/web.php with the same pattern SliceManager uses for
     * declared-route registration.
     */
    private static function routePrefixForSlice(string $sliceName): ?string
    {
        $manifest = self::manifestForSlice($sliceName);
        if ($manifest === null) {
            return null;
        }
        try {
            $routesPath = $manifest->getRoutesPath();
            $webFile = $routesPath ? $routesPath.'/web.php' : null;
            if (! $webFile || ! is_file($webFile)) {
                return null;
            }
            if (preg_match("/Route::prefix\('([^']+)'\)->name\('([^']+)\.'\)/", (string) file_get_contents($webFile), $m)) {
                return $m[2];
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return null;
    }

    /**
     * Board-tab enrichment: the group_by axis vocabulary and,
     * when the related slice declares the axis inline-editable,
     * the drag channel. A hub board must render EVERY axis
     * column (the source boards list empty columns too — a
     * project whose tasks all sit in later columns still shows
     * the first one, empty), so the tab carries the related
     * slice's axis options alongside its records; the partial
     * seeds its groups from them. The drag channel mirrors the
     * directory board's: the related slice's own inline endpoint
     * and allowlist, offered only to viewers the related slice
     * lets edit.
     *
     * @param  array<string, mixed>  $tab
     * @return array<string, mixed>
     */
    private static function enrichBoardTab(array $tab, object $record, string $method): array
    {
        try {
            $query = $record->{$method}();
            if (! is_object($query) || ! method_exists($query, 'getRelated')) {
                return $tab;
            }
            $sliceName = self::sliceNameForTable($query->getRelated()->getTable());
            if ($sliceName === null) {
                return $tab;
            }
            $manifest = self::readManifest($sliceName);
            if ($manifest === []) {
                return $tab;
            }
            $groupBy = (string) ($tab['group_by'] ?? 'status');
            $axis = self::boardAxis($manifest, $groupBy);
            if ($axis !== null) {
                $tab['axis_options'] = $axis['options'];
            }
            $inlineFields = (array) ($manifest['ui']['directory']['inline_edit'] ?? []);
            if (! in_array($groupBy, $inlineFields, true)) {
                return $tab;
            }
            $prefix = self::routePrefixForSlice($sliceName);
            if ($prefix === null || ! Route::has($prefix.'.inline')) {
                return $tab;
            }
            $tabManifest = self::manifestForSlice($sliceName);
            $tabPerms = $tabManifest?->permissions ?? [];
            $editSlugs = array_values(array_filter($tabPerms, fn ($s) => str_ends_with((string) $s, '.edit') || str_ends_with((string) $s, '.update')));
            $tabUser = function_exists('auth') ? auth()->user() : null;
            if ($editSlugs !== [] && ($tabUser === null || ! Access::allows($tabUser, $editSlugs))) {
                return $tab;
            }
            $tab['inline'] = [
                'field' => $groupBy,
                'url' => route($prefix.'.inline', ['id' => '__ID__']),
            ];
        } catch (\Throwable $e) {
            // The board still renders its records without the surface.
        }

        return $tab;
    }

    /**
     * Tab content contract v2 — the action surface of a resolved
     * tab, derived entirely from the RELATED slice's own Heart:
     * its routes (create/show/edit/destroy), its declared
     * ui.directory.inline_edit (the same allowlist the inline
     * endpoint enforces) and the relation's foreign key for the
     * scoped-create preset. Nothing here is hub-specific.
     *
     * @param  array<string, mixed>  $tab
     * @return array{extra: ?callable, tab: array<string, mixed>, raw: array<array-key, mixed>}|null
     */
    private static function tabActionSurface(string $sliceName, array $tab, object $query, object $record, iterable $rows): ?array
    {
        $prefix = self::routePrefixForSlice($sliceName);
        if ($prefix === null) {
            return null;
        }
        $out = ['extra' => null, 'tab' => ['slice' => $sliceName], 'raw' => []];
        try {
            // Row action URLs obey the RELATED slice's own permissions:
            // a viewer without its edit/delete slugs must not be handed
            // the controls (the endpoints enforce it too, but listings
            // show only the controls the user may use). When the related
            // manifest declares no such slugs there is nothing to gate
            // on and the URLs stay route-driven.
            $tabManifest = self::manifestForSlice($sliceName);
            $tabPerms = $tabManifest?->permissions ?? [];
            $editSlugs = array_values(array_filter($tabPerms, fn ($s) => str_ends_with((string) $s, '.edit') || str_ends_with((string) $s, '.update')));
            $deleteSlugs = array_values(array_filter($tabPerms, fn ($s) => str_ends_with((string) $s, '.delete') || str_ends_with((string) $s, '.destroy')));
            $tabUser = function_exists('auth') ? auth()->user() : null;
            $canEditTab = $editSlugs === [] || ($tabUser !== null && Access::allows($tabUser, $editSlugs));
            $canDeleteTab = $deleteSlugs === [] || ($tabUser !== null && Access::allows($tabUser, $deleteSlugs));
            if (Route::has($prefix.'.show')
                || Route::has($prefix.'.edit')
                || Route::has($prefix.'.destroy')) {
                $out['extra'] = static function ($item) use ($prefix, $canEditTab, $canDeleteTab) {
                    $id = $item instanceof Model ? $item->getKey() : ($item->id ?? null);
                    if ($id === null) {
                        return [];
                    }

                    return [
                        'show_url' => Route::has($prefix.'.show') ? route($prefix.'.show', $id) : null,
                        'edit_url' => $canEditTab && Route::has($prefix.'.edit') ? route($prefix.'.edit', $id) : null,
                        'delete_url' => $canDeleteTab && Route::has($prefix.'.destroy') ? route($prefix.'.destroy', $id) : null,
                    ];
                };
            }
            if (Route::has($prefix.'.create')) {
                $params = [];
                if ($query instanceof HasOneOrMany) {
                    $local = $query->getLocalKeyName();
                    $params[$query->getForeignKeyName()] = $record->{$local};
                }
                $out['tab']['create_url'] = route($prefix.'.create', $params);
                $label = (string) ($tab['label'] ?? $sliceName);
                $out['tab']['create_label'] = 'Add '.Str::singular($label);
                $out['tab']['export_name'] = Str::slug($label);
            }
            // Inline editor: the related slice's declared
            // inline_edit fields, restricted to fields that are
            // also columns of this tab; options mirror the
            // endpoint's own allowlist extraction.
            $manifest = self::manifestForSlice($sliceName);
            $raw = $manifest?->raw ?? [];
            $inlineFields = (array) ($raw['ui']['directory']['inline_edit'] ?? []);
            $columnKeys = array_column(self::columnsForSlice($sliceName), 'key');
            foreach ($inlineFields as $field) {
                $field = (string) $field;
                if (! in_array($field, $columnKeys, true)) {
                    continue;
                }
                $options = self::fieldOptions($raw, $field);
                if ($options === []) {
                    continue;
                }
                if (Route::has($prefix.'.inline')) {
                    $out['tab']['inline'] = [
                        'field' => $field,
                        'options' => $options,
                        'url' => route($prefix.'.inline', ['id' => '__ID__']),
                    ];
                    foreach ($rows as $row) {
                        $rid = $row instanceof Model ? $row->getKey() : ($row->id ?? null);
                        if ($rid !== null) {
                            $out['raw'][$rid] = is_object($row) ? ($row->{$field} ?? null) : null;
                        }
                    }
                }
                break;
            }
        } catch (\Throwable $e) {
            // The tab still renders its rows without the surface.
        }

        return $out;
    }

    /**
     * Declared option list for a manifest field, shaped
     * [{value, label}] — the same definitions the inline
     * endpoint validates against.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array{value: string, label: string}>
     */
    private static function fieldOptions(array $raw, string $field): array
    {
        $defs = [];
        foreach ((array) ($raw['fields'] ?? []) as $key => $def) {
            if (! is_array($def)) {
                continue;
            }
            $handle = $def['handle'] ?? $def['name'] ?? (is_string($key) ? $key : null);
            if ($handle !== null) {
                $defs[(string) $handle] = $def;
            }
        }
        foreach ((array) ($raw['models'] ?? []) as $model) {
            foreach ((array) ($model['fields'] ?? []) as $def) {
                if (is_array($def) && isset($def['handle'])) {
                    $defs[(string) $def['handle']] = $def;
                }
            }
        }
        $options = $defs[$field]['options'] ?? null;
        if (! is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $k => $opt) {
            if (is_array($opt)) {
                $value = (string) ($opt['value'] ?? $k);
                $out[] = ['value' => $value, 'label' => (string) ($opt['label'] ?? $value)];
            } elseif (is_int($k)) {
                $out[] = ['value' => (string) $opt, 'label' => (string) $opt];
            } else {
                $out[] = ['value' => (string) $k, 'label' => (string) $opt];
            }
        }

        return $out;
    }

    /** @var array<string, list<string>> table => schema column names */
    private static array $tableColumnsCache = [];

    /** @return list<string> */
    private static function tableColumns(string $table): array
    {
        if (! isset(self::$tableColumnsCache[$table])) {
            try {
                self::$tableColumnsCache[$table] = Schema::getColumnListing($table);
            } catch (\Throwable $e) {
                self::$tableColumnsCache[$table] = [];
            }
        }

        return self::$tableColumnsCache[$table];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     */
    private static function columnsDescribeTable(array $columns, string $table): bool
    {
        $actual = self::tableColumns($table);
        if ($actual === []) {
            return true; // cannot verify — do not second-guess the slice
        }
        foreach ($columns as $col) {
            $key = (string) ($col['key'] ?? '');
            if ($key !== '' && $key !== 'id' && in_array($key, $actual, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Columns derived from the related table itself — the fallback for
     * child entities that own no slice/directory (e.g. leave quotas).
     * System/tenant/audit columns are excluded; FK columns resolve to
     * labels through the DataTableRows batch maps.
     *
     * @return list<array<string, mixed>>
     */
    private static function modelDerivedColumns(string $table, ?string $foreignKey = null): array
    {
        $actual = self::tableColumns($table);
        $skip = ['company_id', 'created_at', 'updated_at', 'deleted_at', 'hash', 'added_by', 'last_updated_by', 'created_by', 'updated_by'];
        if ($foreignKey !== null && $foreignKey !== '') {
            $skip[] = $foreignKey; // the parent's key — every row shares it
        }
        $labelish = [];
        $fks = [];
        $scalars = [];
        foreach ($actual as $col) {
            if ($col === 'id' || in_array($col, $skip, true) || str_ends_with($col, '_by')) {
                continue;
            }
            if (str_ends_with($col, '_name') || in_array($col, ['name', 'title', 'heading', 'subject'], true)) {
                $labelish[] = $col;
            } elseif (str_ends_with($col, '_id')) {
                $fks[] = $col;
            } else {
                $scalars[] = $col;
            }
        }
        $columns = [];
        foreach (array_slice(array_merge($labelish, $fks, $scalars), 0, 6) as $key) {
            $label = Str::headline($key);
            if (str_ends_with($key, '_id')) {
                $label = (string) preg_replace('/\s+Id$/', '', $label);
            }
            $columns[] = [
                'key' => $key,
                'label' => $label,
                'formatter' => $key === 'status' ? 'status_pill' : 'text',
                'html' => $key === 'status',
            ];
        }

        return $columns;
    }

    /** @var array<string, object|null> slice name => listing service */
    private static array $serviceBySlice = [];

    /**
     * Resolve a slice's listing service by naming convention
     * (<Namespace>\Services\<Singular>SliceService), the same class
     * its generated controller uses. Null when the slice has none.
     */
    private static function serviceForSlice(string $sliceName): ?object
    {
        if (array_key_exists($sliceName, self::$serviceBySlice)) {
            return self::$serviceBySlice[$sliceName];
        }
        $service = null;
        try {
            if (function_exists('app') && app()->bound(SliceManager::class)) {
                $manager = app(SliceManager::class);
                $manager->discover();
                $slice = $manager->getSlice($sliceName);
                $ns = $slice?->namespace;
                if (is_string($ns) && $ns !== '') {
                    $singular = Str::studly(Str::singular($sliceName));
                    $plural = Str::studly($sliceName);
                    foreach (array_unique([$singular, $plural]) as $base) {
                        $class = rtrim($ns, '\\').'\\Services\\'.$base.'SliceService';
                        if (class_exists($class)) {
                            $service = app()->bound($class) ? app($class) : new $class;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $service = null;
        }

        return self::$serviceBySlice[$sliceName] = $service;
    }

    /**
     * When the related slice declares no directory columns, derive a
     * modest column set from its manifest fields: the label field
     * first, then scalar/date fields, status last. System/audit
     * columns never appear (Tier 0 hygiene set).
     *
     * @return list<array<string, mixed>>
     */
    private static function fallbackTabColumns(string $sliceName): array
    {
        $manifest = self::readManifest($sliceName);
        $index = self::fieldIndex($manifest);
        if ($index === []) {
            return [];
        }
        $system = ['added_by', 'last_updated_by', 'created_by', 'updated_by', 'approved_by', 'approved_at', 'event_id', 'created_at', 'updated_at', 'deleted_at'];
        $labelKeys = ['name', 'title', 'heading', 'subject', 'full_name', 'label'];
        $picked = [];
        foreach ($labelKeys as $lk) {
            if (isset($index[$lk])) {
                $picked[] = $lk;
                break;
            }
        }
        if ($picked === []) {
            foreach (array_keys($index) as $k) {
                if (str_ends_with((string) $k, '_name')) {
                    $picked[] = (string) $k;
                    break;
                }
            }
        }
        foreach (array_keys($index) as $k) {
            $k = (string) $k;
            if (count($picked) >= 5 || in_array($k, $picked, true) || in_array($k, $system, true)
                || str_ends_with($k, '_by') || $k === 'status') {
                continue;
            }
            $type = $index[$k]['type'] ?? 'string';
            if (in_array($type, ['string', 'date', 'datetime', 'integer', 'decimal', 'boolean', 'text'], true)) {
                $picked[] = $k;
            }
        }
        if (isset($index['status'])) {
            $picked[] = 'status';
        }
        $columns = [];
        foreach ($picked as $k) {
            $def = $index[$k];
            $columns[] = array_filter([
                'key' => $k,
                'label' => (string) ($def['label'] ?? Str::headline($k)),
                'formatter' => $k === 'status' ? 'status_pill' : 'text',
                'html' => $k === 'status',
                'options' => self::normalizeOptions($def['options'] ?? null) ?: null,
                'field_type' => isset($def['type']) && is_string($def['type']) ? $def['type'] : null,
            ], fn ($v) => $v !== null);
        }

        return $columns;
    }

    /**
     * Compute the declared header stats for a slice's detail page.
     *
     * Manifest contract (`ui.show.stats`): each entry names a relation
     * on the record, an aggregate (count | sum | avg, with `column` for
     * sum/avg), an optional equality/`whereIn` `filter` map over the
     * related table's columns, and a label. This is how a hub header
     * shows domain counters (open tasks, hours logged) instead of the
     * generic record-id/status strip. A stat whose relation or query
     * fails is skipped — a broken declaration must never break or
     * misreport the page. (F3c.)
     *
     * @return list<array{handle: string, label: string, value: string}>
     */
    public static function showStats(string $sliceName, mixed $record = null): array
    {
        return self::showStatsFromManifest(self::readManifest($sliceName), $record);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{handle: string, label: string, value: string}>
     */
    /**
     * Declared overview widgets (ui.show.widgets) for a record:
     * the hub-overview composition layer (S-UI 3). Types:
     *
     * - gauge: {value: {relation, aggregate, filter?},
     *   max: {relation, ...} | {field} | number} -> value/max
     *   with a computed percent.
     * - chart: {relation, group_by, chart: donut|pie|bar,
     *   labels: {value: label}} -> grouped counts.
     *
     * A widget that cannot be computed is skipped, never
     * misreported (same rule as declared stats).
     */
    /**
     * Declared show-page composition (layout kit step 3,
     * 26 Oct 2026). ui.show.composition:
     *   standard (default) — identity header card + metadata
     *     strip + in-page tab ribbon + field card.
     *   overview — the source systems' hub composition: the
     *     record title moves to the topbar (page-title section),
     *     the tab strip fuses under it (filterbar stack), no
     *     header card or metadata strip, declared widgets lead
     *     and the field card follows as a plain 'Details' card.
     * ui.show.fields: end (default) | hide — field-card
     * placement under the overview composition.
     */
    public static function showCompositionFromManifest(array $manifest): array
    {
        $show = $manifest['ui']['show'] ?? [];

        return [
            'mode' => ($show['composition'] ?? 'standard') === 'overview' ? 'overview' : 'standard',
            'fields' => ($show['fields'] ?? 'end') === 'hide' ? 'hide' : 'end',
        ];
    }

    public static function showWidgetsFromManifest(array $manifest, mixed $record = null): array
    {
        $defs = $manifest['ui']['show']['widgets'] ?? [];
        if (! is_array($defs) || $defs === [] || ! is_object($record)) {
            return [];
        }

        $widgets = [];
        foreach ($defs as $def) {
            if (! is_array($def)) {
                continue;
            }
            $handle = (string) ($def['handle'] ?? '');
            $type = (string) ($def['type'] ?? '');
            try {
                if ($type === 'gauge' && $handle !== '') {
                    $valueSpec = (array) ($def['value'] ?? []);
                    $value = isset($valueSpec['field'])
                        ? (is_numeric(data_get($record, (string) $valueSpec['field'])) ? (float) data_get($record, (string) $valueSpec['field']) : null)
                        : self::widgetAggregate($record, $valueSpec);
                    $maxSpec = $def['max'] ?? null;
                    $max = is_numeric($maxSpec) ? (float) $maxSpec
                        : (is_array($maxSpec) && isset($maxSpec['field'])
                            ? (float) (data_get($record, (string) $maxSpec['field']) ?? 0)
                            : (is_array($maxSpec) ? self::widgetAggregate($record, $maxSpec) : null));
                    if ($value === null || $max === null || $max <= 0) {
                        continue;
                    }
                    // Declared side fields (label/from/type) render
                    // as label-over-value columns beside the gauge —
                    // the source progress card's Start Date /
                    // Deadline arrangement.
                    $gaugeFields = [];
                    foreach ((array) ($def['fields'] ?? []) as $fieldDef) {
                        if (! is_array($fieldDef) || empty($fieldDef['label']) || empty($fieldDef['from'])) {
                            continue;
                        }
                        $fv = data_get($record, (string) $fieldDef['from']);
                        $ftype = (string) ($fieldDef['type'] ?? 'text');
                        $ftext = ($ftype === 'date' || $ftype === 'datetime')
                            ? DataTableRows::formatDateValue($fv, $ftype)
                            : (string) DataTableRows::display($fv);
                        $gaugeFields[] = [
                            'label' => (string) $fieldDef['label'],
                            'value' => $ftext === '' ? self::emptyDash() : $ftext,
                        ];
                    }
                    $widgets[] = [
                        'handle' => $handle,
                        'placement' => (string) ($def['placement'] ?? 'main'),
                        'type' => 'gauge',
                        'title' => (string) ($def['title'] ?? Str::headline($handle)),
                        'value' => $value,
                        'max' => $max,
                        'percent' => max(0.0, min(100.0, round($value / $max * 100, 1))),
                        'display' => self::trimNumber($value).' / '.self::trimNumber($max),
                        // 'ring' (default) or 'semicircle' — the
                        // source progress card's half-gauge.
                        'variant' => (string) ($def['variant'] ?? 'ring'),
                        'color' => (string) ($def['color'] ?? ''),
                        'fields' => $gaugeFields,
                    ];
                } elseif ($type === 'stats' && $handle !== '') {
                    $rows = [];
                    foreach ((array) ($def['measures'] ?? []) as $measure) {
                        if (! is_array($measure) || empty($measure['label'])) {
                            continue;
                        }
                        $v = self::measureValue($record, $measure);
                        if ($v === null) {
                            continue;
                        }
                        $text = isset($measure['decimals'])
                            ? number_format($v, (int) $measure['decimals'])
                            : self::trimNumber($v);
                        $rows[] = [
                            'label' => (string) $measure['label'],
                            'value' => (string) ($measure['prefix'] ?? '').$text.(string) ($measure['suffix'] ?? ''),
                            'icon' => (string) ($measure['icon'] ?? ''),
                        ];
                    }
                    if ($rows !== []) {
                        $widgets[] = [
                            'handle' => $handle,
                            'placement' => (string) ($def['placement'] ?? 'main'),
                            'type' => 'stats',
                            'title' => (string) ($def['title'] ?? Str::headline($handle)),
                            // 'rows' (default): one card, label/value
                            // rows. 'cards': each measure its own
                            // small card in a two-column grid — the
                            // source rails' Late Attendance / Leaves
                            // Taken arrangement.
                            'layout' => (string) ($def['layout'] ?? 'rows'),
                            // Cards grid width (2 default; 3 for a
                            // three-across money row) and an optional
                            // section heading rendered above the
                            // cards — the source Statistics block.
                            'columns' => (int) ($def['columns'] ?? 2),
                            'heading' => (string) ($def['heading'] ?? ''),
                            'rows' => $rows,
                        ];
                    }
                } elseif ($type === 'pairs' && $handle !== '') {
                    // Labelled text pairs (a reporting card, a
                    // contacts card): each row resolves a dotted
                    // path like a detail field; anything missing
                    // renders as an em dash — the card is a surface
                    // even when every value is empty (the source
                    // renders it with "--" rows).
                    $rows = [];
                    foreach ((array) ($def['rows'] ?? []) as $rowDef) {
                        if (! is_array($rowDef) || empty($rowDef['label'])) {
                            continue;
                        }
                        $value = null;
                        if (! empty($rowDef['from'])) {
                            try {
                                $value = data_get($record, (string) $rowDef['from']);
                            } catch (\Throwable $e) {
                                $value = null;
                            }
                            if ($value === null && ! str_contains((string) $rowDef['from'], '.')) {
                                try {
                                    $value = data_get($record, 'user.'.(string) $rowDef['from']);
                                } catch (\Throwable $e) {
                                    $value = null;
                                }
                            }
                        }
                        $rows[] = [
                            'label' => (string) $rowDef['label'],
                            'value' => is_scalar($value) && trim((string) $value) !== '' ? (string) $value : self::emptyDash(),
                        ];
                    }
                    if ($rows !== []) {
                        $widgets[] = [
                            'handle' => $handle,
                            'placement' => (string) ($def['placement'] ?? 'main'),
                            'type' => 'pairs',
                            'title' => (string) ($def['title'] ?? ''),
                            // 'rows' (default): label/value rows.
                            // 'columns': label-over-value cells in
                            // a two-column grid — the source's
                            // Reporting card arrangement.
                            'layout' => (string) ($def['layout'] ?? 'rows'),
                            'rows' => $rows,
                        ];
                    }
                } elseif ($type === 'text' && $handle !== '') {
                    // A narrative card: one record text field under
                    // a title — the source's Project Details block
                    // (the record summary as a paragraph, not a
                    // field grid). An empty text skips the widget.
                    $from = (string) ($def['from'] ?? '');
                    if ($from === '') {
                        continue;
                    }
                    $tv = data_get($record, $from);
                    if (! is_scalar($tv) || trim((string) $tv) === '') {
                        continue;
                    }
                    $widgets[] = [
                        'handle' => $handle,
                        'placement' => (string) ($def['placement'] ?? 'main'),
                        'type' => 'text',
                        'title' => (string) ($def['title'] ?? Str::headline($handle)),
                        'text' => (string) $tv,
                    ];
                } elseif ($type === 'list' && $handle !== '') {
                    $relation = (string) ($def['relation'] ?? '');
                    if ($relation === '') {
                        continue;
                    }
                    $baseQuery = self::widgetRelationQuery($record, $relation);
                    if (! is_object($baseQuery)) {
                        continue;
                    }
                    $query = self::applyWidgetFilters($baseQuery, (array) ($def['filter'] ?? []));
                    $items = $query
                        ->orderBy((string) ($def['order_by'] ?? 'id'), (string) ($def['direction'] ?? 'desc'))
                        ->limit((int) ($def['limit'] ?? 5))
                        ->get();
                    $labelField = (string) ($def['label_field'] ?? 'name');
                    $rows = [];
                    foreach ($items as $item) {
                        $label = data_get($item, $labelField);
                        // Fallback (table) rows cannot traverse a
                        // dotted label path — resolve one hop via
                        // the row's foreign key.
                        if (($label === null || ! is_scalar($label)) && ! $item instanceof \Illuminate\Database\Eloquent\Model
                            && str_contains($labelField, '.')) {
                            [$relName, $relColumn] = explode('.', $labelField, 2);
                            $relTable = Str::snake(Str::plural($relName));
                            $relKey = data_get($item, $relName.'_id');
                            if ($relKey !== null && Schema::hasTable($relTable)) {
                                $label = DB::table($relTable)->where('id', $relKey)->value($relColumn);
                            }
                        }
                        if (! is_scalar($label) || (string) $label === '') {
                            $label = data_get($item, 'title') ?? data_get($item, 'name') ?? ('#'.data_get($item, 'id'));
                        }
                        $meta = ! empty($def['meta_field']) ? data_get($item, (string) $def['meta_field']) : null;
                        $rows[] = [
                            'label' => (string) $label,
                            'meta' => is_scalar($meta) ? (string) $meta : '',
                        ];
                    }
                    $widgets[] = [
                        'handle' => $handle,
                        'placement' => (string) ($def['placement'] ?? 'main'),
                        'type' => 'list',
                        'title' => (string) ($def['title'] ?? Str::headline($handle)),
                        'rows' => $rows,
                        'empty' => (string) ($def['empty_text'] ?? 'Nothing to show.'),
                        'empty_icon' => (string) ($def['empty_icon'] ?? ''),
                    ];
                } elseif ($type === 'chart' && $handle !== '') {
                    $relation = (string) ($def['relation'] ?? '');
                    $groupBy = (string) ($def['group_by'] ?? '');
                    if ($relation === '' || $groupBy === '') {
                        continue;
                    }
                    $chartQuery = self::widgetRelationQuery($record, $relation);
                    if (! is_object($chartQuery)) {
                        continue;
                    }
                    $items = $chartQuery->limit(500)->get();
                    $counts = [];
                    foreach ($items as $item) {
                        $gv = data_get($item, $groupBy);
                        if ($gv === null || ! is_scalar($gv)) {
                            continue;
                        }
                        $counts[(string) $gv] = ($counts[(string) $gv] ?? 0) + 1;
                    }
                    if ($counts === []) {
                        // A declared empty state is a surface, not
                        // an absence (the source renders the card
                        // with "Not enough data").
                        if (! empty($def['empty_text'])) {
                            $widgets[] = [
                                'handle' => $handle,
                                'placement' => (string) ($def['placement'] ?? 'main'),
                                'type' => 'chart',
                                'chart' => (string) ($def['chart'] ?? 'donut'),
                                'title' => (string) ($def['title'] ?? Str::headline($handle)),
                                'labels' => [],
                                'series' => [],
                                'colors' => [],
                                'legend' => (string) ($def['legend'] ?? 'values'),
                                'empty' => (string) $def['empty_text'],
                            ];
                        }
                        continue;
                    }
                    $labelsMap = (array) ($def['labels'] ?? []);
                    if ($labelsMap !== []) {
                        // Declared label maps also declare the
                        // group order (board columns, statuses) —
                        // and the full vocabulary: a declared group
                        // with no rows still belongs in the legend
                        // at zero (the source boards list every
                        // column; an absent slice, not an absent
                        // entry).
                        $ordered = [];
                        foreach ($labelsMap as $k => $_) {
                            $ordered[(string) $k] = $counts[(string) $k] ?? 0;
                        }
                        foreach ($counts as $k => $n) {
                            $ordered[$k] ??= $n;
                        }
                        $counts = $ordered;
                    }
                    $colorsMap = (array) ($def['colors'] ?? []);
                    $labels = [];
                    $series = [];
                    $colors = [];
                    foreach ($counts as $k => $n) {
                        $labels[] = (string) ($labelsMap[$k] ?? Str::headline($k));
                        $series[] = $n;
                        if (isset($colorsMap[$k])) {
                            $colors[] = (string) $colorsMap[$k];
                        }
                    }
                    $widgets[] = [
                        'handle' => $handle,
                        'placement' => (string) ($def['placement'] ?? 'main'),
                        'type' => 'chart',
                        'chart' => (string) ($def['chart'] ?? 'donut'),
                        'title' => (string) ($def['title'] ?? Str::headline($handle)),
                        'labels' => $labels,
                        'series' => $series,
                        'colors' => $colors,
                        'legend' => (string) ($def['legend'] ?? 'values'),
                    ];
                } elseif ($type === 'bars' && $handle !== '') {
                    // Planned-vs-actual style comparisons: each bar is
                    // a stats measure (field / aggregate / minus) with
                    // its own color; an unset field reads as zero —
                    // a comparison against "not planned" is still a
                    // comparison. Widths scale to the largest bar.
                    $bars = [];
                    foreach ((array) ($def['bars'] ?? []) as $bar) {
                        if (! is_array($bar) || empty($bar['label'])) {
                            continue;
                        }
                        $v = self::measureValue($record, $bar);
                        if ($v === null && isset($bar['field'])) {
                            $v = 0.0;
                        }
                        if ($v === null) {
                            continue;
                        }
                        if (isset($bar['scale']) && is_numeric($bar['scale']) && (float) $bar['scale'] != 0.0) {
                            $v = $v / (float) $bar['scale'];
                        }
                        $text = isset($bar['decimals'])
                            ? number_format($v, (int) $bar['decimals'])
                            : self::trimNumber($v);
                        $bars[] = [
                            'label' => (string) $bar['label'],
                            'value' => $v,
                            'color' => (string) ($bar['color'] ?? '#3b82f6'),
                            'display' => (string) ($bar['prefix'] ?? '').$text.(string) ($bar['suffix'] ?? ''),
                        ];
                    }
                    if ($bars !== []) {
                        $max = max(array_map(fn ($b) => $b['value'], $bars));
                        foreach ($bars as $i => $b) {
                            $bars[$i]['percent'] = $max > 0 ? max(0.0, min(100.0, round($b['value'] / $max * 100, 1))) : 0.0;
                        }
                        $widgets[] = [
                            'handle' => $handle,
                            'placement' => (string) ($def['placement'] ?? 'main'),
                            'type' => 'bars',
                            'title' => (string) ($def['title'] ?? Str::headline($handle)),
                            // 'rows' (default): horizontal meter
                            // rows. 'columns': vertical chart
                            // columns with value labels — the
                            // source's chart-card arrangement.
                            'style' => (string) ($def['style'] ?? 'rows'),
                            'bars' => $bars,
                        ];
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $widgets;
    }

    /**
     * One relation aggregate for a widget spec:
     * {relation, aggregate: count|sum|avg, column?, filter?}
     * with the declared-stats filter semantics ('!' excludes).
     */
    /**
     * The declared-stats filter semantics ('!' excludes),
     * shared by widget aggregates and widget lists.
     */
    /**
     * A query for a declared widget relation. The model's own
     * relation method wins; when the model does not carry one
     * (generated port models declare belongsTo fields, not the
     * reverse collections their hubs reference), fall back to
     * convention, in order: a user-scope column on the target
     * table (user_id, then the domain's other user-key names),
     * a foreign key back to the record, or the conventional
     * {thing}_users pivot. Mirrors the stats fallback — a
     * declared widget must not silently vanish because the
     * generator did not emit a method.
     */
    protected static function widgetRelationQuery(mixed $record, string $relation): mixed
    {
        if (! is_object($record) || $relation === '') {
            return null;
        }
        if (method_exists($record, $relation)) {
            $query = $record->{$relation}();

            return is_object($query) ? $query : null;
        }
        try {
            $table = Str::snake(Str::plural($relation));
            if (! Schema::hasTable($table)) {
                return null;
            }
            $userId = null;
            if (isset($record->user_id) && is_numeric($record->user_id)) {
                $userId = (int) $record->user_id;
            } elseif (method_exists($record, 'getTable') && $record->getTable() === 'users' && $record->getKey()) {
                $userId = (int) $record->getKey();
            }
            if ($userId !== null) {
                foreach (['user_id', 'award_to', 'agent_id', 'employee_id'] as $scopeColumn) {
                    if (Schema::hasColumn($table, $scopeColumn)) {
                        return DB::table($table)->where($scopeColumn, $userId);
                    }
                }
            }
            if (method_exists($record, 'getTable') && $record->getKey()) {
                $backFk = Str::singular($record->getTable()).'_id';
                if (Schema::hasColumn($table, $backFk)) {
                    return DB::table($table)->where($backFk, $record->getKey());
                }
            }
            $pivot = Str::singular($table).'_users';
            if ($userId !== null && Schema::hasTable($pivot)
                && Schema::hasColumn($pivot, 'user_id') && Schema::hasColumn($pivot, Str::singular($table).'_id')) {
                return DB::table($pivot)
                    ->join($table, "{$table}.id", '=', "{$pivot}.".Str::singular($table).'_id')
                    ->where("{$pivot}.user_id", $userId)
                    ->select("{$table}.*");
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    protected static function applyWidgetFilters(mixed $query, array $filter): mixed
    {
        foreach ($filter as $column => $value) {
            if ($column === '_period' && is_array($value) && count($value) === 2
                && preg_match('/^[a-z0-9_]+$/', (string) $value[0]) && $value[1] === 'month') {
                // Reserved key: current-month scope on a date
                // column (the source's profile widgets count the
                // current month, not all time).
                $query = $query->whereRaw('DATE('.$value[0].') BETWEEN ? AND ?', [
                    now()->startOfMonth()->toDateString(),
                    now()->endOfMonth()->toDateString(),
                ]);
                continue;
            }
            if (is_array($value)) {
                $exclude = array_values(array_filter($value, fn ($v) => is_string($v) && str_starts_with($v, '!')));
                $include = array_values(array_diff($value, $exclude));
                if ($exclude !== []) {
                    $query = $query->whereNotIn((string) $column, array_map(fn ($v) => substr($v, 1), $exclude));
                }
                if ($include !== []) {
                    $query = $query->whereIn((string) $column, $include);
                }
            } else {
                $query = $query->where((string) $column, $value);
            }
        }

        return $query;
    }

    /**
     * One stats measure: a record field, a relation aggregate
     * spec, or the difference of two aggregate specs (profit
     * = earnings - expenses).
     */
    protected static function measureValue(mixed $record, array $measure): ?float
    {
        $value = null;
        if (isset($measure['field'])) {
            $v = data_get($record, (string) $measure['field']);
            $value = is_numeric($v) ? (float) $v : null;
        } elseif (isset($measure['minus']) && is_array($measure['minus']) && count($measure['minus']) === 2) {
            $a = self::widgetAggregate($record, (array) $measure['minus'][0]);
            $b = self::widgetAggregate($record, (array) $measure['minus'][1]);
            $value = ($a === null || $b === null) ? null : $a - $b;
        } else {
            $value = self::widgetAggregate($record, $measure);
        }
        // A declared default turns "unset" into a number — the
        // source's arithmetic treats an empty budget as 0, and a
        // statistics card that silently drops the row misreports.
        if ($value === null && isset($measure['default']) && is_numeric($measure['default'])) {
            return (float) $measure['default'];
        }

        return $value;
    }

    protected static function widgetAggregate(mixed $record, array $spec): ?float
    {
        $relation = (string) ($spec['relation'] ?? '');
        if ($relation === '' || ! is_object($record)) {
            return null;
        }
        $query = self::widgetRelationQuery($record, $relation);
        if (! is_object($query)) {
            return null;
        }
        $query = self::applyWidgetFilters($query, (array) ($spec['filter'] ?? []));

        return match ((string) ($spec['aggregate'] ?? 'count')) {
            'sum' => (float) $query->sum((string) ($spec['column'] ?? '')),
            'avg' => (float) $query->avg((string) ($spec['column'] ?? '')),
            default => (float) $query->count(),
        };
    }

    protected static function trimNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /**
     * User-scoped query for a show stat whose relation method does not
     * exist on the record: the target table's own user_id column, or a
     * conventional {singular}_users pivot (counts only via pivot).
     */
    protected static function userScopedStatQuery(string $relation, int $userId, string $aggregate = 'count'): mixed
    {
        try {
            $table = Str::snake(Str::plural($relation));
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                return DB::table($table)->where('user_id', $userId);
            }
            if ($aggregate === 'count') {
                $pivot = Str::singular($table).'_users';
                if (Schema::hasTable($pivot) && Schema::hasColumn($pivot, 'user_id')) {
                    // Join the target table so declared filters (which
                    // name target columns, e.g. board_column_id) apply.
                    if (Schema::hasTable($table) && Schema::hasColumn($pivot, Str::singular($table).'_id')) {
                        return DB::table($pivot)
                            ->join($table, "{$table}.id", '=', "{$pivot}.".Str::singular($table).'_id')
                            ->where("{$pivot}.user_id", $userId);
                    }

                    return DB::table($pivot)->where('user_id', $userId);
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * The hub identity card (ui.show.identity): the person/record
     * the hub is about — name, a subtitle of resolved paths, one
     * meta line, and the About text — rendered at the head of the
     * overview's main column in hub composition, the way profile
     * hubs in the source systems open. Bare paths delegate to the
     * linked user, exactly like detail fields. Returns null when
     * undeclared or when no name resolves: no declaration, no
     * fabricated card.
     */
    /**
     * Last-resort resolution for a dotted identity path whose
     * middle segment is not an Eloquent relation: walk the
     * segments, and for a segment that does not resolve, look
     * the row up by the foreign-key convention — the segment's
     * table, keyed by the current model's snake name + '_id'
     * (users -> client_details via user_id) — the same
     * convention the FK evidence chain generates relations
     * from. Guarded by schema checks; any failure returns null.
     */
    public static function resolveDottedByForeignKey(mixed $record, string $path): mixed
    {
        try {
            $current = $record;
            $segments = explode('.', $path);
            $last = count($segments) - 1;
            foreach ($segments as $i => $segment) {
                $next = data_get($current, $segment);
                if ($next === null && $i < $last) {
                    $next = self::foreignKeyHop($current, $segment);
                }
                if ($next === null) {
                    return null;
                }
                $current = $next;
            }

            return $current;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * One hop of the foreign-key walk, for a segment that did
     * not resolve directly. Two conventions, both schema-guarded:
     * (a) the segment is a DETAIL of the current record — its
     * table keyed by the current record's id (users ->
     * client_details via user_id; for plain listing rows, whose
     * class name carries no stem, the person-detail convention
     * user_id is tried); (b) the current record carries the
     * segment's foreign key (<segment>_id) — the segment is the
     * referenced record (a listing row's client_id -> the
     * client, a user in this schema family).
     */
    private static function foreignKeyHop(mixed $current, string $segment): mixed
    {
        try {
            $table = Str::snake(Str::plural($segment));

            $currentId = null;
            if (is_object($current) && method_exists($current, 'getKey')) {
                $currentId = $current->getKey();
            } elseif (is_object($current) || is_array($current)) {
                $id = data_get($current, 'id');
                $currentId = is_numeric($id) ? $id : null;
            }
            if ($currentId !== null && Schema::hasTable($table)) {
                $fkCandidates = (is_object($current) && method_exists($current, 'getKey'))
                    ? [Str::snake(class_basename($current)).'_id']
                    : ['user_id'];
                foreach ($fkCandidates as $fk) {
                    if (Schema::hasColumn($table, $fk)) {
                        $row = DB::table($table)->where($fk, $currentId)->first();
                        if ($row !== null) {
                            return $row;
                        }
                    }
                }
            }

            $fkVal = (is_object($current) || is_array($current))
                ? data_get($current, $segment.'_id')
                : null;
            if (is_numeric($fkVal)) {
                $tables = [$table];
                if (in_array($segment, ['client', 'employee', 'user', 'customer', 'member', 'assignee', 'owner', 'author'], true)) {
                    $tables[] = 'users';
                }
                if (! Schema::hasTable($table)) {
                    // Unique-suffix fallback (the generator's FK
                    // evidence chain has the same step): an
                    // `agent_id` whose rows live in lead_agents,
                    // not agents — adopt the one table whose
                    // name ends in the conventional plural.
                    // Listings arrive schema-qualified on some
                    // drivers ("erp.lead_agents"), so compare
                    // bare names, deduped.
                    $bareNames = array_unique(array_map(
                        fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t,
                        Schema::getTableListing()
                    ));
                    $suffixMatches = array_values(array_filter(
                        $bareNames,
                        fn ($t) => str_ends_with($t, '_'.$table)
                    ));
                    if (count($suffixMatches) === 1) {
                        $tables[] = $suffixMatches[0];
                    }
                }
                foreach ($tables as $candidate) {
                    if (Schema::hasTable($candidate)) {
                        $row = DB::table($candidate)->where('id', $fkVal)->first();
                        if ($row !== null) {
                            return $row;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    public static function showIdentity(array $manifest, mixed $record = null): ?array
    {
        $def = $manifest['ui']['show']['identity'] ?? null;
        if (! is_array($def) || ! is_object($record)) {
            return null;
        }
        $resolve = function (string $path) use ($record) {
            try {
                $value = data_get($record, $path);
            } catch (\Throwable $e) {
                $value = null;
            }
            if ($value === null && ! str_contains($path, '.')) {
                try {
                    $value = data_get($record, 'user.'.$path);
                } catch (\Throwable $e) {
                    $value = null;
                }
            }
            if ($value === null && str_contains($path, '.')) {
                $value = self::resolveDottedByForeignKey($record, $path);
            }

            return $value;
        };
        $name = $resolve((string) ($def['name'] ?? 'name'));
        if (! is_scalar($name) || trim((string) $name) === '') {
            return null;
        }
        $subtitleParts = [];
        foreach ((array) ($def['subtitle'] ?? []) as $path) {
            $v = $resolve((string) $path);
            if (is_scalar($v) && trim((string) $v) !== '') {
                $subtitleParts[] = (string) $v;
            }
        }
        $meta = null;
        if (! empty($def['meta']['from'])) {
            $mv = $resolve((string) $def['meta']['from']);
            if ($mv !== null && $mv !== '') {
                $type = (string) ($def['meta']['type'] ?? '');
                if (($type === 'datetime' || $type === 'date')
                    && ! $mv instanceof \DateTimeInterface && is_string($mv) && strtotime($mv) !== false) {
                    $mv = new \DateTimeImmutable($mv);
                }
                if ($type === 'datetime' && $mv instanceof \DateTimeInterface) {
                    try {
                        $mv = $mv->setTimezone(new \DateTimeZone(self::displayTimezone()));
                    } catch (\Throwable $e) {
                        // An unresolvable declared timezone keeps the stored reading.
                    }
                    $mv = $mv->format('d-m-Y h:i a');
                } elseif ($type === 'date' && $mv instanceof \DateTimeInterface) {
                    $mv = $mv->format('d-m-Y');
                }
                $meta = [
                    'label' => (string) ($def['meta']['label'] ?? ''),
                    'value' => is_scalar($mv) ? (string) $mv : '',
                ];
            }
        }
        $about = ! empty($def['about']) ? $resolve((string) $def['about']) : null;

        return [
            'name' => (string) $name,
            'initial' => Str::upper(Str::substr(trim((string) $name), 0, 1)),
            'subtitle' => implode(' • ', $subtitleParts),
            'meta' => $meta,
            'about' => is_scalar($about) && trim((string) $about) !== '' ? (string) $about : null,
            'about_title' => (string) ($def['about_title'] ?? 'About'),
            // Optional card heading (a related-party card: the
            // project hub's "Client"), column placement ('main'
            // default, or 'rail'), and the avatar tile toggle —
            // party cards in the source rail carry no avatar.
            'title' => (string) ($def['title'] ?? ''),
            'placement' => ($def['placement'] ?? 'main') === 'rail' ? 'rail' : 'main',
            'avatar' => (bool) ($def['avatar'] ?? true),
            // Where the hub's declared stats (ui.show.stats) render:
            // 'strip' (default) as their own card row above the
            // composition, or 'inline' inside this identity card —
            // the source hubs' arrangement (declared
            // ui.show.identity.kpis: inline).
            'kpis' => ($def['kpis'] ?? 'strip') === 'inline' ? 'inline' : 'strip',
        ];
    }

    public static function showStatsFromManifest(array $manifest, mixed $record = null): array
    {
        $defs = $manifest['ui']['show']['stats'] ?? [];
        // A declared 'none' is an explicit no-stats hub (the
        // views' generic fallback strip also steps aside when
        // the overview declares widgets — see SliceGenerator).
        if ($defs === 'none' || ! is_array($defs) || $defs === [] || ! is_object($record)) {
            return [];
        }

        $stats = [];
        foreach ($defs as $def) {
            if (! is_array($def)) {
                continue;
            }
            $relation = (string) ($def['relation'] ?? '');
            $handle = (string) ($def['handle'] ?? $relation);
            if ($relation === '' || $handle === '') {
                continue;
            }
            try {
                $query = null;
                if (method_exists($record, $relation)) {
                    $query = $record->{$relation}();
                } else {
                    $aliasMap = [
                        'projects' => ['projectMembers', 'members'],
                        'tickets' => ['ticketAgents', 'ticketAgentGroups', 'assignedTickets', 'agentTickets'],
                    ];
                    foreach ($aliasMap[$relation] ?? [] as $alias) {
                        if (method_exists($record, $alias)) {
                            $query = $record->{$alias}();
                            break;
                        }
                    }
                    if ($query === null && isset($record->user_id) && class_exists(DB::class)) {
                        if (($relation === 'projects' || $handle === 'projects') && Schema::hasTable('project_members')) {
                            $query = DB::table('project_members')->where('user_id', $record->user_id);
                        } elseif (($relation === 'tickets' || $handle === 'tickets') && Schema::hasTable('tickets')) {
                            $query = DB::table('tickets')->where('agent_id', $record->user_id);
                        } else {
                            // Generalized user-scope fallback: ported
                            // profile slices (e.g. EmployeeDetails) often
                            // lack the hasMany method for user-owned data.
                            // Resolve the stat against the target table's
                            // user_id, or through a {thing}_users pivot.
                            $query = self::userScopedStatQuery($relation, (int) $record->user_id, (string) ($def['aggregate'] ?? 'count'));
                        }
                    }
                }
                if (! is_object($query)) {
                    continue;
                }
                foreach (($def['filter'] ?? []) as $column => $value) {
                    if (is_array($value)) {
                        // '!'-prefixed values exclude (whereNotIn) —
                        // e.g. open tasks = board column is not the
                        // complete column, the source app's own
                        // definition, which no status filter expresses.
                        $exclude = array_values(array_filter($value, fn ($v) => is_string($v) && str_starts_with($v, '!')));
                        $include = array_values(array_diff($value, $exclude));
                        if ($exclude !== []) {
                            $query = $query->whereNotIn((string) $column, array_map(fn ($v) => substr($v, 1), $exclude));
                        }
                        if ($include !== []) {
                            $query = $query->whereIn((string) $column, $include);
                        }
                    } else {
                        $query = $query->where((string) $column, $value);
                    }
                }
                $aggregate = (string) ($def['aggregate'] ?? 'count');
                $raw = match ($aggregate) {
                    'sum' => (float) $query->sum((string) ($def['column'] ?? '')),
                    'avg' => (float) $query->avg((string) ($def['column'] ?? '')),
                    default => (int) $query->count(),
                };
            } catch (\Throwable $e) {
                continue;
            }
            $stats[] = [
                'handle' => $handle,
                'label' => (string) ($def['label'] ?? Str::headline($handle)),
                'value' => $aggregate === 'count'
                    ? number_format((int) $raw)
                    : rtrim(rtrim(number_format((float) $raw, 2, '.', ''), '0'), '.'),
            ];
        }

        return $stats;
    }

    /**
     * Index the manifest's field definitions by field name, from both the
     * top-level `fields` map and each model's `fields` list.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, mixed>>
     */
    /**
     * Resolve a declared printable document (G2): ui.show.document
     * turns a record into a document structure — header fields, a
     * line-items table (from the declared relation or repeater), and
     * a totals block. This is the standard "document" pattern every
     * slice inherits by declaring it; the source application's
     * invoice documents are the reference shape.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{title: string, header: list<array{label: string, value: string}>, items: ?array{columns: list<array{handle: string, label: string}>, rows: list<list<string>>}, totals: list<array{label: string, value: string}>}|null
     */
    public static function documentFromManifest(array $manifest, mixed $record): ?array
    {
        $decl = $manifest['ui']['show']['document'] ?? null;
        if (! is_array($decl) || ! is_object($record)) {
            return null;
        }
        $fields = self::fieldIndex($manifest);
        $attributes = method_exists($record, 'getAttributes') ? $record->getAttributes() : [];
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'company_id', 'tenant_id', 'organization_id', 'workflow_state_entered_at'];

        $header = [];
        foreach (($decl['fields'] ?? array_keys($fields)) as $handle) {
            $handle = (string) $handle;
            if (in_array($handle, $skip, true) || ! array_key_exists($handle, $attributes)) {
                continue;
            }
            $header[] = [
                'label' => (string) (($fields[$handle]['label'] ?? null) ?? Str::headline($handle)),
                'value' => self::documentValue($handle, $fields[$handle] ?? [], $attributes[$handle]),
            ];
        }

        $items = null;
        $itemsHandle = (string) ($decl['line_items'] ?? '');
        if ($itemsHandle === '') {
            foreach ($fields as $handle => $def) {
                if (($def['type'] ?? null) === 'repeater') {
                    $itemsHandle = (string) $handle;
                    break;
                }
            }
        }
        if ($itemsHandle !== '' && method_exists($record, $itemsHandle)) {
            try {
                $rows = $record->{$itemsHandle};
                $rows = is_iterable($rows) ? collect($rows) : collect();
                $columns = [];
                $repeaterFields = $fields[$itemsHandle]['repeater_fields'] ?? [];
                if (is_array($repeaterFields) && $repeaterFields !== []) {
                    foreach ($repeaterFields as $rf) {
                        if (is_array($rf) && ! empty($rf['handle'])) {
                            $columns[] = ['handle' => (string) $rf['handle'], 'label' => (string) ($rf['label'] ?? Str::headline((string) $rf['handle']))];
                        }
                    }
                }
                $formatted = [];
                foreach ($rows as $row) {
                    $rowAttrs = is_object($row) && method_exists($row, 'getAttributes') ? $row->getAttributes() : (is_array($row) ? $row : []);
                    if ($columns === [] && $rowAttrs !== []) {
                        foreach (array_keys($rowAttrs) as $key) {
                            if (in_array($key, $skip, true) || str_ends_with((string) $key, '_id')) {
                                continue;
                            }
                            $columns[] = ['handle' => (string) $key, 'label' => Str::headline((string) $key)];
                            if (count($columns) >= 6) {
                                break;
                            }
                        }
                    }
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = self::documentValue($col['handle'], [], $rowAttrs[$col['handle']] ?? null);
                    }
                    $formatted[] = $line;
                }
                if ($columns !== []) {
                    $items = ['columns' => $columns, 'rows' => $formatted];
                }
            } catch (\Throwable) {
                $items = null;
            }
        }

        $totals = [];
        foreach (($decl['totals'] ?? []) as $handle) {
            $handle = (string) $handle;
            if (! array_key_exists($handle, $attributes)) {
                continue;
            }
            $totals[] = [
                'label' => (string) (($fields[$handle]['label'] ?? null) ?? Str::headline($handle)),
                'value' => self::documentValue($handle, $fields[$handle] ?? [], $attributes[$handle]),
            ];
        }

        return [
            'title' => (string) ($decl['title'] ?? Str::headline((string) ($manifest['name'] ?? 'Document'))),
            // The issuing company's identity heads the document,
            // as the source's documents print company()->company_name
            // — read through the SettingsUi accessor (the bound
            // companies row), never a per-template table read.
            'company' => SettingsUi::companyIdentity(),
            'header' => $header,
            'items' => $items,
            'totals' => $totals,
        ];
    }

    protected static function documentValue(string $handle, array $def, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '—';
        }
        if (is_bool($raw)) {
            return $raw ? 'Yes' : 'No';
        }
        $inference = FieldInference::analyze($handle, (string) ($def['type'] ?? 'string'));
        if ((($inference['is_money'] ?? false) || ($inference['formatter'] ?? null) === 'money') && is_numeric($raw)) {
            // Documents render amounts under the effective
            // currency format (SettingsUi) — the same store
            // lists read — not a hard-coded 2-decimal shape.
            return SettingsUi::formatAmount((float) $raw);
        }
        if (($inference['is_date'] ?? false) || str_ends_with($handle, '_date')) {
            // Format exactly like the hub/detail pipeline does.
            // The old substring check ('date' inside the type)
            // silently missed the manifest field index's real
            // vocabulary — ported date columns are typed
            // 'timestamp' — so document dates printed raw
            // ('2024-01-12 00:00:00'). FieldInference's is_date
            // knows the full type list; formatDateValue falls
            // back to the raw string for unparseable values.
            return DataTableRows::formatDateValue($raw, (string) ($def['type'] ?? 'date'));
        }

        return is_scalar($raw) ? (string) $raw : json_encode($raw);
    }

    /**
     * Resolve a declared matrix directory view (G2): records become
     * a rows x period grid — the standard pattern behind
     * timesheet/attendance-style screens. Declared as
     * ui.directory.matrix { row_key, date_field, cell_field?, marker?,
     * title? }. Pure transform: the controller fetches the period's
     * records (declared-filter criteria), this builds the grid.
     *
     * Two source-faithful cell modes extend the base marker grid
     * (declared `cell_mode:`; context supplied by the controller):
     * - `attendance` (period month): a record renders a status
     *   glyph from its late / half_day flags; roster rows also
     *   resolve approved leaves and holidays for record-less days.
     * - `roster` (period week): a record renders its shift as a
     *   colour chip (context `shifts`: id => {code, name, color});
     *   holidays mark record-less days.
     * Context may also carry `subtitles` (row key => subtitle,
     * e.g. the person's designation) and `row_order` (the
     * population's own order, e.g. employee_details order —
     * without it rows sort by label as before).
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<int|string, string>  $labelMap  row_key value => label (relationship options)
     * @param  array<string, mixed>  $context  window / holidays / leaves / subtitles / row_order / shifts
     * @return array<string, mixed>|null
     */
    public static function matrixFromManifest(array $manifest, iterable $records, int $month, int $year, array $labelMap = [], array $context = []): ?array
    {
        $decl = $manifest['ui']['directory']['matrix'] ?? null;
        if (! is_array($decl) || empty($decl['row_key']) || empty($decl['date_field'])) {
            return null;
        }
        $rowKey = (string) $decl['row_key'];
        $dateField = (string) $decl['date_field'];
        $cellField = isset($decl['cell_field']) ? (string) $decl['cell_field'] : null;
        $marker = (string) ($decl['marker'] ?? 'P');
        $labelField = isset($decl['row_label']) ? (string) $decl['row_label'] : (str_ends_with($rowKey, '_id') ? substr($rowKey, 0, -3).'_name' : $rowKey);
        $period = (string) ($decl['period'] ?? 'month');
        $cellMode = (string) ($decl['cell_mode'] ?? '');

        // Period window: a week window arrives via context (the
        // controller anchors it); the month window is built here.
        $window = $context['window'] ?? null;
        if ($period === 'week' && is_array($window) && ! empty($window[0]) && ! empty($window[1])) {
            $start = new \DateTimeImmutable((string) $window[0]);
            $end = new \DateTimeImmutable((string) $window[1]);
            $anchorLabel = $start->format('j M') . ' - ' . $end->format('d M');
            $prevAnchor = $start->modify('-7 days')->format('Y-m-d');
            $nextAnchor = $start->modify('+7 days')->format('Y-m-d');
        } else {
            $period = 'month';
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $end = $start->modify('last day of this month');
            $anchorLabel = $start->format('F Y');
            $prevAnchor = $start->modify('-1 month')->format('Y-m-d');
            $nextAnchor = $start->modify('+1 month')->format('Y-m-d');
        }
        $days = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $days[] = [
                'date' => $cursor->format('Y-m-d'),
                'n' => (int) $cursor->format('j'),
                'dow' => $cursor->format('D'),
                'dow_long' => strtoupper($cursor->format('l')),
                'mon_short' => strtoupper($cursor->format('M')),
                'weekend' => (int) $cursor->format('N') >= 6,
            ];
        }
        $daysInMonth = (int) $start->format('t');
        $inWindow = fn (string $date): bool => $date >= $start->format('Y-m-d') && $date <= $end->format('Y-m-d');

        $subtitles = (array) ($context['subtitles'] ?? []);
        $rows = [];
        // Roster mode (declared `roster: true`): every member of
        // the row-key label map is a row, with or without records
        // — the source's attendance grid renders the full roster
        // and marks elapsed days absent; a records-only grid
        // reads as an empty page for a quiet month.
        if (! empty($decl['roster'])) {
            foreach ($labelMap as $key => $label) {
                $rows[$key] = [
                    'key' => $key,
                    'label' => (string) $label,
                    'subtitle' => isset($subtitles[$key]) ? (string) $subtitles[$key] : null,
                    'cells' => [],
                    'present' => 0,
                ];
            }
        }
        $shifts = (array) ($context['shifts'] ?? []);
        $shiftField = (string) ($decl['shift_field'] ?? 'employee_shift_id');
        foreach ($records as $record) {
            // Records arrive as Eloquent models (getAttributes), as
            // listing business objects (typed public properties), or
            // as plain arrays — accept all three.
            $attrs = match (true) {
                is_object($record) && method_exists($record, 'getAttributes') => $record->getAttributes(),
                is_array($record) => $record,
                is_object($record) => get_object_vars($record),
                default => [],
            };
            $rawDate = $attrs[$dateField] ?? null;
            $key = $attrs[$rowKey] ?? null;
            if ($rawDate === null || $key === null) {
                continue;
            }
            $date = substr((string) $rawDate, 0, 10);
            if (! $inWindow($date)) {
                continue;
            }
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'key' => $key,
                    'label' => (string) ($labelMap[$key] ?? $attrs[$labelField] ?? $key),
                    'subtitle' => isset($subtitles[$key]) ? (string) $subtitles[$key] : null,
                    'cells' => [],
                    'present' => 0,
                ];
            }
            if (! isset($rows[$key]['cells'][$date])) {
                $rows[$key]['cells'][$date] = match ($cellMode) {
                    'attendance' => match (true) {
                        ($attrs['half_day'] ?? 'no') === 'yes' => ['icon' => 'star-half', 'class' => 'text-rose-500', 'label' => 'Half Day'],
                        ($attrs['late'] ?? 'no') === 'yes' => ['icon' => 'circle-alert', 'class' => 'text-amber-500', 'label' => 'Late'],
                        default => ['icon' => 'check', 'class' => 'text-emerald-500', 'label' => 'Present'],
                    },
                    'roster' => isset($shifts[(int) ($attrs[$shiftField] ?? 0)])
                        ? ['chip' => $shifts[(int) $attrs[$shiftField]]]
                        : $marker,
                    default => $cellField !== null && isset($attrs[$cellField]) && $attrs[$cellField] !== ''
                        ? mb_strimwidth((string) $attrs[$cellField], 0, 3)
                        : $marker,
                };
                $rows[$key]['present']++;
            }
        }

        // Roster fills for record-less days: approved leaves and
        // holidays (attendance), holidays (roster). A record
        // always wins over both.
        if (! empty($decl['roster']) && $cellMode === 'attendance') {
            $leaves = (array) ($context['leaves'] ?? []);
            $holidays = (array) ($context['holidays'] ?? []);
            foreach ($rows as $key => &$row) {
                foreach ($days as $day) {
                    $date = $day['date'];
                    if (isset($row['cells'][$date])) {
                        continue;
                    }
                    if (! empty($leaves[$key][$date])) {
                        $row['cells'][$date] = ['icon' => 'plane', 'class' => 'text-rose-500', 'label' => 'On Leave'];
                    } elseif (! empty($holidays[$date])) {
                        $row['cells'][$date] = ['icon' => 'star', 'class' => 'text-amber-400 fill-amber-400', 'label' => 'Holiday'];
                    }
                }
            }
            unset($row);
        }
        if (! empty($decl['roster']) && $cellMode === 'roster') {
            $holidays = (array) ($context['holidays'] ?? []);
            foreach ($rows as &$row) {
                foreach ($days as $day) {
                    $date = $day['date'];
                    if (! isset($row['cells'][$date]) && ! empty($holidays[$date])) {
                        $row['cells'][$date] = ['icon' => 'star', 'class' => 'text-sky-500', 'label' => 'Holiday'];
                    }
                }
            }
            unset($row);
        }

        $rows = array_values($rows);
        $rowOrder = array_values((array) ($context['row_order'] ?? []));
        if ($rowOrder !== []) {
            $rank = array_flip(array_map('strval', $rowOrder));
            usort($rows, function ($a, $b) use ($rank) {
                $ra = $rank[(string) $a['key']] ?? PHP_INT_MAX;
                $rb = $rank[(string) $b['key']] ?? PHP_INT_MAX;

                return $ra === $rb ? strcasecmp($a['label'], $b['label']) : ($ra <=> $rb);
            });
        } else {
            usort($rows, fn ($a, $b) => strcasecmp($a['label'], $b['label']));
        }

        $legendChips = [];
        if ($cellMode === 'roster') {
            foreach ($shifts as $shift) {
                if (is_array($shift) && ($shift['code'] ?? '') !== '') {
                    $legendChips[] = $shift;
                }
            }
        }

        return [
            'title' => (string) ($decl['title'] ?? Str::headline((string) ($manifest['name'] ?? 'Matrix'))),
            'days' => $days,
            'rows' => $rows,
            'month' => (int) $start->format('n'),
            'year' => (int) $start->format('Y'),
            'days_in_month' => $daysInMonth,
            'days_in_period' => count($days),
            'roster' => ! empty($decl['roster']),
            'legend' => array_values(array_filter((array) ($decl['legend'] ?? []), 'is_array')),
            'legend_chips' => $legendChips,
            'row_label' => is_string($decl['row_label'] ?? null) && $decl['row_label'] !== '' ? $decl['row_label'] : 'Employee',
            'period' => $period,
            'cell_mode' => $cellMode,
            'anchor_label' => $anchorLabel,
            'prev_anchor' => $prevAnchor,
            'next_anchor' => $nextAnchor,
        ];
    }

    /** Readable text colour on a hex colour chip. */
    public static function contrastText(string $hex): string
    {
        if (! preg_match('/^#([0-9a-fA-F]{6})$/', trim($hex), $m)) {
            return '#1e293b';
        }
        $lum = 0.2126 * (hexdec(substr($m[1], 0, 2)) / 255)
            + 0.7152 * (hexdec(substr($m[1], 2, 2)) / 255)
            + 0.0722 * (hexdec(substr($m[1], 4, 2)) / 255);

        return $lum < 0.45 ? '#ffffff' : '#1e293b';
    }

    /**
     * Resolve a declared record timer (G8): a start/stop work
     * timer rendered on the show page, writing session rows to a
     * related log model. Declared as ui.show.timer:
     * { relation, start_field?, end_field?, user_field?, title? }.
     * One active timer per user across the whole log table —
     * starting a second one is refused (the source application's
     * rule). Stop computes total_hours/total_minutes when the log
     * table carries those columns.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{relation: string, start_field: string, end_field: string, user_field: string, title: string}|null
     */
    public static function timerFromManifest(array $manifest): ?array
    {
        $decl = $manifest['ui']['show']['timer'] ?? null;
        if (! is_array($decl) || empty($decl['relation'])) {
            return null;
        }

        return [
            'relation' => (string) $decl['relation'],
            'start_field' => (string) ($decl['start_field'] ?? 'start_time'),
            'end_field' => (string) ($decl['end_field'] ?? 'end_time'),
            'user_field' => (string) ($decl['user_field'] ?? 'user_id'),
            'title' => (string) ($decl['title'] ?? 'Timer'),
        ];
    }

    /**
     * Resolve a declared bulk-entry view (G8): a one-date grid over a
     * row source (the row key's relationship options) with per-row
     * editable fields — the standard pattern behind screens like
     * Mark Attendance. Declared as ui.directory.bulk_entry:
     * { row_key?, date_field?, fields: [handle | {handle, input?,
     * options?}], title? }. row_key/date_field default to the
     * matrix declaration's when present.
     *
     * Field inputs: time | datetime | text | select. A datetime
     * field renders as a time input whose value is composed with
     * the entry date on save. Labels default to the directory
     * column labels.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{title: string, row_key: string, date_field: string, fields: list<array{handle: string, label: string, input: string, options: list<string>}>}|null
     */
    /**
     * Declared CSV import (P13): ui.directory.import =
     * {format: csv, key?: <field for upsert>, columns: {CSV header:
     * field}}. The Mind declares the pipeline + dedupe rule in
     * plain words; the Heart declares the executable map here.
     */
    public static function importFromManifest(array $manifest): ?array
    {
        $decl = $manifest['ui']['directory']['import'] ?? null;
        if (! is_array($decl) || empty($decl['columns']) || ! is_array($decl['columns'])) {
            return null;
        }
        $columns = [];
        foreach ($decl['columns'] as $header => $field) {
            if (is_string($header) && is_string($field) && $field !== '') {
                $columns[$header] = $field;
            }
        }
        if ($columns === []) {
            return null;
        }

        return [
            'format' => (string) ($decl['format'] ?? 'csv'),
            'key' => isset($decl['key']) && is_string($decl['key']) && $decl['key'] !== '' ? $decl['key'] : null,
            'columns' => $columns,
        ];
    }

    /**
     * Declared invite/provisioning (P14): ui.directory.invite =
     * {link: user_id, name_field: full_name, role?: Employee}.
     * Lists records whose link field is empty; provisioning
     * creates the user, assigns the role club, links the record,
     * and sends the activation (password-reset) email.
     */
    public static function inviteFromManifest(array $manifest): ?array
    {
        $decl = $manifest['ui']['directory']['invite'] ?? null;
        if (! is_array($decl) || empty($decl['link'])) {
            return null;
        }

        return [
            'link' => (string) $decl['link'],
            'name_field' => (string) ($decl['name_field'] ?? 'name'),
            'role' => isset($decl['role']) && is_string($decl['role']) ? $decl['role'] : null,
        ];
    }

    public static function bulkEntryFromManifest(array $manifest): ?array
    {
        $decl = $manifest['ui']['directory']['bulk_entry'] ?? null;
        if (! is_array($decl)) {
            return null;
        }
        $matrix = $manifest['ui']['directory']['matrix'] ?? [];
        $rowKey = (string) ($decl['row_key'] ?? $matrix['row_key'] ?? '');
        $dateField = (string) ($decl['date_field'] ?? $matrix['date_field'] ?? '');
        if ($rowKey === '' || $dateField === '' || empty($decl['fields']) || ! is_array($decl['fields'])) {
            return null;
        }

        $labels = [];
        foreach (($manifest['ui']['directory']['columns'] ?? []) as $col) {
            if (is_array($col) && ! empty($col['key'])) {
                $labels[(string) $col['key']] = (string) ($col['label'] ?? Str::headline((string) $col['key']));
            }
        }

        $fields = [];
        foreach ($decl['fields'] as $f) {
            if (is_string($f)) {
                $f = ['handle' => $f];
            }
            if (! is_array($f) || empty($f['handle'])) {
                continue;
            }
            $handle = (string) $f['handle'];
            // Options are flat values; a {value, label} entry also
            // contributes its label (id-valued selects, e.g. shift
            // pickers in a bulk-assign grid).
            $options = [];
            $optionLabels = [];
            foreach ((array) ($f['options'] ?? []) as $opt) {
                if (is_array($opt) && isset($opt['value'])) {
                    $options[] = (string) $opt['value'];
                    if (isset($opt['label'])) {
                        $optionLabels[(string) $opt['value']] = (string) $opt['label'];
                    }
                } else {
                    $options[] = (string) $opt;
                }
            }
            $fields[] = [
                'handle' => $handle,
                'label' => (string) ($f['label'] ?? $labels[$handle] ?? Str::headline($handle)),
                'input' => (string) ($f['input'] ?? 'text'),
                'options' => $options,
                'option_labels' => $optionLabels,
            ];
        }
        if ($fields === []) {
            return null;
        }

        // Range mode (P10 extension): one submission applies the
        // same row values across a date range — the source
        // systems' bulk-mark semantics — skipping declared
        // holiday dates (and weekends when declared).
        $holidays = null;
        if (! empty($decl['holidays']) && is_array($decl['holidays']) && ! empty($decl['holidays']['slice'])) {
            $holidays = [
                'slice' => (string) $decl['holidays']['slice'],
                'date_field' => (string) ($decl['holidays']['date_field'] ?? 'date'),
            ];
        }

        return [
            'title' => (string) ($decl['title'] ?? 'Bulk Entry'),
            'row_key' => $rowKey,
            'date_field' => $dateField,
            'fields' => $fields,
            'range' => (bool) ($decl['range'] ?? false),
            'skip_weekends' => (bool) ($decl['skip_weekends'] ?? false),
            'holidays' => $holidays,
        ];
    }

    /**
     * Resolve a declared report view (G2): records grouped by a field
     * with computed aggregates — the standard pattern behind tabular
     * report pages (group-by + count/sum/avg + a totals row).
     * Declared as ui.directory.report { group_by, aggregates:
     * [{fn: count|sum|avg, column?, label?}], date_field?, title? }.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<int|string, string>  $labelMap  group value => label (relationship options)
     * @return array{title: string, groupLabel: string, columns: list<string>, rows: list<array{label: string, values: list<string>}>, totals: list<string>, count: int}|null
     */
    public static function reportFromManifest(array $manifest, iterable $records, array $labelMap = []): ?array
    {
        $decl = $manifest['ui']['directory']['report'] ?? null;
        if (! is_array($decl) || empty($decl['group_by']) || empty($decl['aggregates']) || ! is_array($decl['aggregates'])) {
            return null;
        }
        $fields = self::fieldIndex($manifest);
        $groupBy = (string) $decl['group_by'];
        $groupDef = $fields[$groupBy] ?? [];
        $labelField = str_ends_with($groupBy, '_id') ? substr($groupBy, 0, -3).'_name' : null;

        $aggregates = [];
        foreach ($decl['aggregates'] as $agg) {
            if (is_array($agg) && ! empty($agg['fn'])) {
                $aggregates[] = [
                    'fn' => in_array($agg['fn'], ['sum', 'avg'], true) ? $agg['fn'] : 'count',
                    'column' => isset($agg['column']) ? (string) $agg['column'] : null,
                    'label' => (string) ($agg['label'] ?? Str::headline((string) ($agg['column'] ?? 'count'))),
                ];
            }
        }
        if ($aggregates === []) {
            return null;
        }

        $groups = [];
        $all = [];
        foreach ($records as $record) {
            $attrs = match (true) {
                is_object($record) && method_exists($record, 'getAttributes') => $record->getAttributes(),
                is_array($record) => $record,
                is_object($record) => get_object_vars($record),
                default => [],
            };
            $all[] = $attrs;
            $key = $attrs[$groupBy] ?? null;
            $key = $key === null ? '—' : (string) $key;
            $groups[$key] ??= ['label' => (string) ($labelMap[$key] ?? ($labelField ? ($attrs[$labelField] ?? null) : null) ?? self::optionLabel($groupDef, $key) ?? $key), 'rows' => []];
            $groups[$key]['rows'][] = $attrs;
        }

        $compute = function (array $rows) use ($aggregates, $fields): array {
            $values = [];
            foreach ($aggregates as $agg) {
                if ($agg['fn'] === 'count' || $agg['column'] === null) {
                    $values[] = number_format(count($rows));

                    continue;
                }
                $col = $agg['column'];
                $nums = array_filter(array_map(fn ($r) => $r[$col] ?? null, $rows), fn ($v) => is_numeric($v));
                $raw = $agg['fn'] === 'sum' ? array_sum($nums) : (count($nums) ? array_sum($nums) / count($nums) : 0);
                $values[] = self::documentValue($col, $fields[$col] ?? [], $raw);
            }

            return $values;
        };

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = ['label' => $group['label'], 'values' => $compute($group['rows'])];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return [
            'title' => (string) ($decl['title'] ?? Str::headline((string) ($manifest['name'] ?? 'Report')).' Report'),
            'groupLabel' => (string) ($decl['group_label'] ?? ($groupDef['label'] ?? Str::headline($groupBy))),
            'columns' => array_column($aggregates, 'label'),
            'rows' => $rows,
            'totals' => $compute($all),
            'count' => count($all),
        ];
    }

    /**
     * Multi-source comparison report (the Income-vs-Expense
     * shape): several money streams, each declared as
     * {label, table, date_field, amount, sign}, aligned by
     * month with a signed Net column, totals and header
     * cards. Declared as a named report under
     * ui.directory.reports[] with a `compare` list; the
     * controller supplies one row set per source with
     * normalized {date, amount} rows.
     *
     * @param  array<string, mixed>  $decl
     * @param  list<array{label: string, sign: int, rows: list<array{date: mixed, amount: mixed}>}>  $sources
     * @return array{title: string, groupLabel: string, columns: list<string>, rows: list<array{label: string, values: list<string>}>, totals: list<string>, count: int, cards: list<array{label: string, value: string}>}|null
     */
    public static function compareFromManifest(array $decl, array $sources): ?array
    {
        if (empty($decl['compare']) || ! is_array($decl['compare']) || $sources === []) {
            return null;
        }
        $money = fn ($v) => SettingsUi::formatAmount((float) $v);
        $periods = [];
        $totals = array_fill(0, count($sources), 0.0);
        $count = 0;
        foreach ($sources as $i => $source) {
            foreach ($source['rows'] as $row) {
                $count++;
                $amount = is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0.0;
                $totals[$i] += $amount;
                $ts = isset($row['date']) && $row['date'] !== null && $row['date'] !== ''
                    ? strtotime((string) $row['date']) : false;
                if ($ts === false) {
                    continue;
                }
                $key = date('Y-m', $ts);
                $periods[$key][$i] = ($periods[$key][$i] ?? 0.0) + $amount;
            }
        }
        ksort($periods);
        $net = fn (array $sums) => array_sum(array_map(
            fn ($i) => ($sources[$i]['sign'] ?? 1) * ($sums[$i] ?? 0.0),
            array_keys($sources)
        ));
        $rows = [];
        foreach ($periods as $key => $sums) {
            $values = [];
            foreach ($sources as $i => $source) {
                $values[] = $money($sums[$i] ?? 0.0);
            }
            $values[] = $money($net($sums));
            $rows[] = ['label' => date('F Y', strtotime($key.'-01')), 'values' => $values];
        }
        $totalValues = array_map($money, $totals);
        $totalValues[] = $money($net($totals));
        $cards = [];
        foreach ($sources as $i => $source) {
            $cards[] = ['label' => 'Total '.$source['label'], 'value' => $money($totals[$i])];
        }
        $cards[] = ['label' => 'Net', 'value' => $money($net($totals))];

        return [
            'title' => (string) ($decl['title'] ?? 'Comparison Report'),
            'groupLabel' => 'Month',
            'columns' => [...array_column($sources, 'label'), 'Net'],
            'rows' => $rows,
            'totals' => $totalValues,
            'count' => $count,
            'cards' => $cards,
        ];
    }

    protected static function optionLabel(array $fieldDef, string $value): ?string
    {
        $options = $fieldDef['options'] ?? null;
        if (! is_array($options)) {
            return null;
        }
        foreach ($options as $k => $opt) {
            if (is_array($opt) && isset($opt['value']) && (string) $opt['value'] === $value) {
                return isset($opt['label']) ? (string) $opt['label'] : null;
            }
            if (is_string($k) && $k === $value && is_string($opt)) {
                return $opt;
            }
        }

        return null;
    }

    protected static function fieldIndex(array $manifest): array
    {
        $index = [];
        $add = static function ($name, $def) use (&$index): void {
            if (is_string($name) && $name !== '' && is_array($def)) {
                $index[$name] ??= $def;
            }
        };
        if (isset($manifest['fields']) && is_array($manifest['fields'])) {
            foreach ($manifest['fields'] as $name => $def) {
                $add($name, $def);
            }
        }
        if (isset($manifest['models']) && is_array($manifest['models'])) {
            foreach ($manifest['models'] as $model) {
                if (! is_array($model) || ! isset($model['fields']) || ! is_array($model['fields'])) {
                    continue;
                }
                foreach ($model['fields'] as $k => $def) {
                    if (is_array($def)) {
                        $add($def['name'] ?? $def['handle'] ?? (is_string($k) ? $k : null), $def);
                    }
                }
            }
        }

        return $index;
    }

    /**
     * Normalize a field's declared options into a value => label map.
     * Accepts {value: label} maps, lists of {value, label} pairs, and
     * plain scalar lists.
     *
     * @return array<string, string>
     */
    /**
     * Options for a column from the directory's filter
     * declarations when the field itself declares none.
     */
    protected static function filterDeclaredOptions(array $manifest, string $handle, string $sliceName): array
    {
        $directory = $manifest['ui']['directory'] ?? [];
        foreach (['quick_filters', 'sidebar_filters'] as $group) {
            foreach ((array) ($directory[$group] ?? []) as $filter) {
                if (! is_array($filter) || ($filter['handle'] ?? null) !== $handle) {
                    continue;
                }
                if (! empty($filter['options'])) {
                    return self::normalizeOptions($filter['options']);
                }
                $from = (string) ($filter['options_from'] ?? '');
                if (str_starts_with($from, 'distinct:')) {
                    $column = substr($from, strlen('distinct:'));
                    try {
                        $manager = app(SliceManager::class);
                        // Models are usually the singular of the
                        // slice name (Leaves -> Leaf).
                        $modelClass = $manager->modelClass(Str::studly($sliceName))
                            ?? $manager->modelClass(Str::studly(Str::singular($sliceName)));
                        if ($modelClass && class_exists($modelClass)) {
                            $values = $modelClass::query()->distinct()->pluck($column);
                            $map = [];
                            foreach ($values as $value) {
                                if (is_scalar($value) && (string) $value !== '') {
                                    $map[(string) $value] = Str::headline((string) $value);
                                }
                            }

                            return $map;
                        }
                    } catch (\Throwable) {
                        return [];
                    }
                }
            }
        }

        return [];
    }

    /**
     * Declared filter options as a list of {value, label} pairs —
     * the shape every strip/drawer renderer consumes. Manifests
     * declare options in BOTH shapes (maps and pair lists); the
     * renderers must never see the raw map (a map rendered as
     * pairs produced identical 'All' tabs, QA8 26 Oct 2026).
     */
    public static function optionsList(mixed $options): array
    {
        $list = [];
        foreach (self::normalizeOptions($options) as $value => $label) {
            $list[] = ['value' => $value, 'label' => $label];
        }

        return $list;
    }

    /**
     * Role option set from the app's roles table (display names),
     * for role columns that declare no options of their own.
     *
     * @return list<string>
     */
    /**
     * The board axis of a slice's directory: the column the board
     * view groups by, with its full value vocabulary. Derivation
     * mirrors the generated index blades exactly — a literal
     * status/state/stage column wins; otherwise an FK column the
     * declaration labels Status/Stage (or whose key names the
     * board/stage/status) is the axis. Options resolve the way
     * directory columns resolve them: declared on the column,
     * declared on the field, else the FK target table's rows.
     * Boards render EVERY axis value (the source boards list
     * empty columns too); consumers merge this vocabulary with
     * the groups the rows actually form. Null when the slice
     * declares no resolvable axis. $onlyKey pins the axis to a
     * declared group_by (hub board tabs) instead of deriving it.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{key: string, options: list<array{value: string, label: string}>}|null
     */
    public static function boardAxis(array $manifest, ?string $onlyKey = null): ?array
    {
        $dir = is_array($manifest['ui']['directory'] ?? null) ? $manifest['ui']['directory'] : [];
        $columns = [];
        foreach ((array) ($dir['columns'] ?? []) as $c) {
            if (is_array($c) && (string) ($c['key'] ?? $c['handle'] ?? '') !== '') {
                $columns[] = $c;
            }
        }
        $key = null;
        $colDef = [];
        if ($onlyKey !== null && $onlyKey !== '') {
            $key = $onlyKey;
            foreach ($columns as $c) {
                if ((string) ($c['key'] ?? $c['handle'] ?? '') === $key) {
                    $colDef = $c;
                    break;
                }
            }
        } else {
            foreach ($columns as $c) {
                $ck = (string) ($c['key'] ?? $c['handle'] ?? '');
                if (in_array($ck, ['status', 'state', 'stage'], true)) {
                    $key = $ck;
                    $colDef = $c;
                    break;
                }
            }
            if ($key === null) {
                foreach ($columns as $c) {
                    $ck = (string) ($c['key'] ?? $c['handle'] ?? '');
                    $label = (string) ($c['label'] ?? '');
                    if (str_ends_with($ck, '_id')
                        && (in_array($label, ['Status', 'Stage'], true)
                            || str_contains($ck, 'board')
                            || str_contains($ck, 'stage')
                            || str_contains($ck, 'status'))) {
                        $key = $ck;
                        $colDef = $c;
                        break;
                    }
                }
            }
        }
        if ($key === null) {
            return null;
        }
        $options = self::normalizeOptions($colDef['options'] ?? null);
        if ($options === []) {
            $fields = self::fieldIndex($manifest);
            $options = self::normalizeOptions($fields[$key]['options'] ?? null);
        }
        if ($options === [] && str_ends_with($key, '_id')) {
            $options = self::foreignKeyOptions($colDef, $key);
        }
        if ($options === []) {
            return null;
        }
        $list = [];
        foreach ($options as $value => $label) {
            $list[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return ['key' => $key, 'options' => $list];
    }

    /**
     * The role a user-picker field is bound to, when any: a field
     * or column def may declare `options_role: client`; otherwise
     * the people stems whose base IS a role name in this domain
     * family (client, agent) bind to that role by convention.
     * Null = the picker is not role-bound.
     *
     * @param  array<string, mixed>  $def
     */
    public static function pickerRole(array $def, string $base): ?string
    {
        $declared = $def['options_role'] ?? null;
        if (is_string($declared) && trim($declared) !== '') {
            return trim($declared);
        }

        return in_array($base, ['client', 'agent'], true) ? $base : null;
    }

    /**
     * Ids of the users holding a role, or null when the role does
     * not resolve in this app (no roles table, no such role, no
     * recognizable membership store) — callers then leave the
     * picker unfiltered rather than emptying it on a guess.
     *
     * @return list<int>|null
     */
    public static function roleMemberIds(string $role): ?array
    {
        try {
            if (! Schema::hasTable('roles')) {
                return null;
            }
            $cols = Schema::getColumnListing('roles');
            $roleId = null;
            foreach (DB::table('roles')->get() as $row) {
                $name = (string) ($row->name ?? '');
                $display = in_array('display_name', $cols, true) ? (string) ($row->display_name ?? '') : '';
                if (mb_strtolower($name) === mb_strtolower($role)
                    || ($display !== '' && mb_strtolower($display) === mb_strtolower($role))) {
                    $roleId = (int) $row->id;
                    break;
                }
            }
            if ($roleId === null) {
                return null;
            }
            if (Schema::hasTable('role_user')) {
                return array_map('intval', DB::table('role_user')->where('role_id', $roleId)->pluck('user_id')->all());
            }
            if (Schema::hasTable('model_has_roles')) {
                return array_map('intval', DB::table('model_has_roles')->where('role_id', $roleId)->pluck('model_id')->all());
            }
            if (Schema::hasTable('users') && in_array('role_id', Schema::getColumnListing('users'), true)) {
                return array_map('intval', DB::table('users')->where('role_id', $roleId)->pluck('id')->all());
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Options for an FK column from its target table
     * (id => label), using the same table/label conventions as
     * the row pipeline's FK resolution. Empty when no target
     * table or label column resolves.
     *
     * @return array<string, string>
     */
    protected static function foreignKeyOptions(array $c, string $key): array
    {
        if (! str_ends_with($key, '_id') || $key === 'id') {
            return [];
        }
        $base = substr($key, 0, -3);
        $candidates = ! empty($c['label_table'])
            ? [(string) $c['label_table']]
            : match ($base) {
                'department' => ['teams', 'departments', 'department'],
                'client' => ['users', 'client_details', 'clients'],
                'agent' => ['users', 'agents', 'agent'],
                'board_column' => ['taskboard_columns', 'board_columns'],
                // Support-desk stems live in the ticket-prefixed
                // tables (ticket_channels / ticket_types); without
                // the alias the Ticket channel/type pickers and
                // filters resolve no table and render empty.
                'channel' => ['ticket_channels', 'channels', 'channel'],
                'type' => ['ticket_types', 'types', 'type'],
                default => [Str::plural($base), $base],
            };
        foreach ($candidates as $tbl) {
            try {
                if (! Schema::hasTable($tbl)) {
                    continue;
                }
                $cols = Schema::getColumnListing($tbl);
                if (! in_array('id', $cols, true)) {
                    continue;
                }
                $labelCol = null;
                if (! empty($c['label_column']) && in_array((string) $c['label_column'], $cols, true)) {
                    $labelCol = (string) $c['label_column'];
                }
                if ($labelCol === null) {
                    foreach (['name', 'title', 'heading', 'subject', 'team_name', 'company_name', 'label', 'display_name', 'full_name', 'column_name', 'invoice_number', 'order_number', 'sku', 'reference', 'code'] as $candidate) {
                        if (in_array($candidate, $cols, true)) {
                            $labelCol = $candidate;
                            break;
                        }
                    }
                }
                if ($labelCol === null) {
                    // Person-shaped target (employee_details and
                    // kin): no label column of its own, but the
                    // person's name resolves through the linked
                    // user — the same join the row pipeline uses
                    // (F10, Day-2 drill).
                    $personMap = DataTableRows::personNameMap($tbl, null, 200);
                    if (! empty($personMap)) {
                        $out = [];
                        foreach ($personMap as $id => $name) {
                            $out[(string) $id] = $name;
                        }

                        return $out;
                    }
                    continue;
                }
                $out = [];
                foreach (DB::table($tbl)->orderBy('id')->limit(200)->pluck($labelCol, 'id') as $id => $label) {
                    if (trim((string) $label) !== '') {
                        $out[(string) $id] = (string) $label;
                    }
                }
                if ($tbl === 'users') {
                    // A role-bound people column (client/agent)
                    // lists only that role's members — the same
                    // contract the form options resolver honours.
                    // An unresolvable role leaves the list as is.
                    $role = self::pickerRole($c, $base);
                    $memberIds = $role !== null ? self::roleMemberIds($role) : null;
                    if ($memberIds !== null) {
                        $keep = array_flip(array_map('strval', $memberIds));

                        return array_intersect_key($out, $keep);
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            } catch (\Throwable $e) {
                // An unresolvable target leaves the editor to
                // its other option sources.
            }
        }

        return [];
    }

    protected static function roleOptions(): array
    {
        try {
            if (! Schema::hasTable('roles')) {
                return [];
            }
            $cols = Schema::getColumnListing('roles');
            $hasDisplay = in_array('display_name', $cols, true);
            // Prefer a row's display_name, but fall back to its
            // name when the display column is empty — ported
            // role tables carry the framework's display_name
            // column with the imported rows' names only in
            // `name`, and plucking display_name alone yielded
            // an empty option list (blank inline role selects).
            $names = DB::table('roles')->orderBy('id')->get(['name', ...($hasDisplay ? ['display_name'] : [])])
                ->map(fn ($r) => ($hasDisplay && trim((string) ($r->display_name ?? '')) !== '')
                    ? $r->display_name
                    : $r->name)
                ->map(fn ($n) => trim((string) $n))
                ->filter(fn ($n) => $n !== '')
                ->map(fn ($n) => str_contains($n, ' ') ? $n : Str::headline($n))
                ->unique()
                ->values()
                ->all();

            return $names;
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected static function normalizeOptions(mixed $options): array
    {
        $map = [];
        if (! is_array($options)) {
            return $map;
        }
        foreach ($options as $k => $v) {
            if (is_array($v)) {
                $value = $v['value'] ?? $k;
                $label = $v['label'] ?? $value;
            } elseif (is_int($k)) {
                $value = $v;
                $label = $v;
            } else {
                $value = $k;
                $label = $v;
            }
            if (is_scalar($value) && is_scalar($label)) {
                $map[(string) $value] = (string) $label;
            }
        }

        return $map;
    }

    /** Placements the Page Structure Contract (Body v4) allows for a list page's primary action. */
    public const LIST_PLACEMENTS = ['header-right', 'toolbar-left', 'command-bar', 'below-facets-left'];

    /**
     * Resolve a list page's declared primary-action placement
     * (Body v4, Page Structure Contract). Reads
     * ui.pages.list.placement first, then the legacy
     * ui.directory.placement; anything undeclared or outside the
     * vocabulary falls back to 'below-facets-left' — the actions
     * band under the filter bar, which is the fleet's current
     * rendered behaviour, so undeclared pages never move.
     *
     * @param  array<string, mixed>  $ui  The manifest's `ui` array.
     */
    public static function listPlacement(array $ui): string
    {
        $declared = $ui['pages']['list']['placement']
            ?? $ui['directory']['placement']
            ?? null;

        return is_string($declared) && in_array($declared, self::LIST_PLACEMENTS, true)
            ? $declared
            : 'below-facets-left';
    }

    /**
     * @return array<string, mixed>
     */
    /** @var array<string, array<string, mixed>> slice name => parsed manifest */
    private static array $manifestDataBySlice = [];

    public static function readManifest(string $sliceName): array
    {
        if (array_key_exists($sliceName, self::$manifestDataBySlice)) {
            return self::$manifestDataBySlice[$sliceName];
        }
        $path = self::manifestPath($sliceName);
        if ($path === null || ! is_file($path)) {
            return [];
        }

        try {
            if (str_ends_with(strtolower($path), '.json')) {
                return self::$manifestDataBySlice[$sliceName] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) ?: [];
            }
            if (class_exists(Yaml::class)) {
                $data = Yaml::parseFile($path);

                return self::$manifestDataBySlice[$sliceName] = is_array($data) ? $data : [];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    /** @var array<string, string|null> slice name => manifest path */
    private static array $manifestPathBySlice = [];

    public static function manifestPath(string $sliceName): ?string
    {
        // Route-owner preference (QA5, 25 Oct 2026): when slices
        // share a name (twins), the name index resolves the FIRST
        // discovered — a page then renders its twin's declared
        // columns, whose relations its own model may not carry
        // (the work Projects list rendered the pilot slice's
        // columns and every relation cell came out empty). When
        // a same-named slice's declared route prefix owns the
        // current request path, THAT slice's manifest is the
        // page's own — the G5 own-manifest principle applied to
        // directory reads. The result is deliberately not written
        // to the name-keyed static cache.
        try {
            if (function_exists('app') && app()->bound(SliceManager::class) && app()->bound('request')) {
                $reqPath = trim((string) app('request')->path(), '/');
                if ($reqPath !== '') {
                    $manager = app(SliceManager::class);
                    $manager->discover();
                    // discoveredSlices(), not getAllSlices(): the
                    // name-keyed registry collapses twins to one
                    // entry, which is the shadowing itself.
                    foreach ($manager->discoveredSlices() as $candidate) {
                        if (($candidate->name ?? null) !== $sliceName) {
                            continue;
                        }
                        foreach (glob(rtrim((string) $candidate->path, '/\\').'/Routes/*.php') ?: [] as $routesFile) {
                            $src = (string) @file_get_contents($routesFile);
                            if (! preg_match("/Route::prefix\\(\\s*'([^']+)'\\)/", $src, $m)) {
                                continue;
                            }
                            $prefix = trim($m[1], '/');
                            if ($reqPath === $prefix || str_starts_with($reqPath, $prefix.'/')) {
                                foreach (['slice.yaml', 'slice.yml'] as $file) {
                                    $p = rtrim((string) $candidate->path, '/\\').DIRECTORY_SEPARATOR.$file;
                                    if (is_file($p)) {
                                        return $p;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the name index.
        }

        if (array_key_exists($sliceName, self::$manifestPathBySlice)) {
            return self::$manifestPathBySlice[$sliceName];
        }
        try {
            if (function_exists('app') && app()->bound(SliceManager::class)) {
                /** @var SliceManager $manager */
                $manager = app(SliceManager::class);
                $manager->discover();
                $slice = $manager->getSlice($sliceName);
                if ($slice) {
                    foreach (['slice.yaml', 'slice.yaml', 'slice.yml'] as $file) {
                        $p = rtrim($slice->path, '/\\').DIRECTORY_SEPARATOR.$file;
                        if (is_file($p)) {
                            return self::$manifestPathBySlice[$sliceName] = $p;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to path heuristics
        }

        // Heuristics when container not ready
        $candidates = [];
        if (function_exists('app_path')) {
            $candidates[] = app_path('Slices');
            $candidates[] = base_path('app/Slices');
        }
        $candidates[] = dirname(__DIR__, 2).'/Slices';

        foreach ($candidates as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $found = self::findManifestUnder($root, $sliceName, 0, 6);
            if ($found) {
                return self::$manifestPathBySlice[$sliceName] = $found;
            }
        }

        return self::$manifestPathBySlice[$sliceName] = null;
    }

    protected static function findManifestUnder(string $dir, string $sliceName, int $depth, int $max): ?string
    {
        if ($depth > $max) {
            return null;
        }
        $base = basename($dir);
        if (strcasecmp($base, $sliceName) === 0 || strcasecmp($base, str_replace(' ', '', $sliceName)) === 0) {
            foreach (['slice.yaml', 'slice.yaml', 'slice.yml'] as $file) {
                $p = $dir.DIRECTORY_SEPARATOR.$file;
                if (is_file($p)) {
                    return $p;
                }
            }
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $sub) {
            $hit = self::findManifestUnder($sub, $sliceName, $depth + 1, $max);
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }
}
