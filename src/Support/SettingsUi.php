<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Declared settings sections (S-UI 5, pattern P9): each slice
 * may declare top-level `settings:` sections in its manifest,
 * and the framework aggregates them into ONE hub — a rail of
 * sections (global and per-feature interleaved, like the
 * reference ERP) where every section is a form with its own
 * save. Values persist through the core Setting store, keyed
 * per section and grouped by the declaring slice.
 *
 * A section may instead declare `bind: {table: <name>}` —
 * then its fields are COLUMNS of that settings table and
 * values read/write the table's single row (created on
 * first save). This is how ported systems surface their
 * settings: the source's own settings tables (smtp_settings,
 * invoice_settings, …) become hub forms instead of hollow
 * CRUD directories (backlog D1). Unbound sections keep the
 * core key-value store.
 *
 * Field types: text, number, textarea, select (options),
 * toggle, secret (stored encrypted in the KV store; blank
 * saves keep the current value), time (an HH:MM[:SS] clock
 * value — for bound columns that store a time of day, e.g.
 * a backup hour kept as '00:00:00'; a number input cannot
 * hold those and silently wiped them on every save).
 */
class SettingsUi
{
    /**
     * @param  array<int, array{slice: string, manifest: array}>  $entries
     * @param  callable(string, mixed): mixed  $valueResolver  (storage key, default) -> current value
     * @return array<int, array<string, mixed>>
     */
    public static function collectSections(array $entries, callable $valueResolver): array
    {
        $sections = [];
        foreach ($entries as $entry) {
            $slice = (string) ($entry['slice'] ?? '');
            $defs = $entry['manifest']['settings'] ?? [];
            if ($slice === '' || ! is_array($defs)) {
                continue;
            }
            foreach ($defs as $def) {
                if (! is_array($def) || empty($def['handle'])) {
                    continue;
                }
                $handle = (string) $def['handle'];
                $fields = [];
                foreach ((array) ($def['fields'] ?? []) as $field) {
                    if (! is_array($field) || empty($field['handle'])) {
                        continue;
                    }
                    $fieldHandle = (string) $field['handle'];
                    $key = (string) ($field['key'] ?? $handle.'.'.$fieldHandle);
                    $default = $field['default'] ?? null;
                    $fields[] = [
                        'handle' => $fieldHandle,
                        'key' => $key,
                        'label' => (string) ($field['label'] ?? Str::headline($fieldHandle)),
                        'type' => in_array(($field['type'] ?? 'text'), ['text', 'number', 'textarea', 'select', 'toggle', 'secret', 'time', 'roles'], true)
                            ? (string) $field['type']
                            : 'text',
                        'options' => self::normalizeOptions($field['options'] ?? null),
                        'options_source' => isset($field['options_source']) && is_string($field['options_source']) ? $field['options_source'] : null,
                        'default' => $default,
                        'secret' => ! empty($field['secret']) || ($field['type'] ?? '') === 'secret',
                        'help' => $field['help'] ?? null,
                        'value' => $valueResolver($key, $default),
                    ];
                }
                $sections[] = [
                    'id' => $slice.':'.$handle,
                    'slice' => $slice,
                    'handle' => $handle,
                    'title' => (string) ($def['title'] ?? Str::headline($handle)),
                    'icon' => $def['icon'] ?? null,
                    'description' => $def['description'] ?? null,
                    'bind' => isset($def['bind']['table']) && is_string($def['bind']['table'])
                        ? array_filter([
                            'table' => $def['bind']['table'],
                            // Keyed-rows binding (L5): a multi-row
                            // settings table edited as one row per
                            // record with toggle columns — the
                            // notification-settings shape.
                            'rows_key' => isset($def['bind']['rows_key']) && is_string($def['bind']['rows_key']) ? $def['bind']['rows_key'] : null,
                            'rows_label' => isset($def['bind']['rows_label']) && is_string($def['bind']['rows_label']) ? $def['bind']['rows_label'] : null,
                            'toggles' => isset($def['bind']['toggles']) && is_array($def['bind']['toggles'])
                                ? array_values(array_filter($def['bind']['toggles'], 'is_string'))
                                : null,
                        ], static fn ($v) => $v !== null)
                        : null,
                    'fields' => $fields,
                ];
            }
        }

        return $sections;
    }

    /**
     * The toggle conventions settings tables actually use:
     * a stored value counts as ON for 1 / '1' / 'yes' /
     * 'true' / true / 'active' / 'enabled', and writing a
     * toggle preserves the convention the existing value
     * already uses (yes/no stays yes/no, active/deactive
     * stays active/deactive, ints stay ints).
     */
    public static function toggleIsOn(mixed $value): bool
    {
        return in_array($value, [1, '1', 'yes', 'true', true, 'active', 'enabled'], true);
    }

    public static function toggleValueFor(mixed $current, bool $on): mixed
    {
        return match (true) {
            $current === 'yes' || $current === 'no' => $on ? 'yes' : 'no',
            $current === 'active' || $current === 'deactive' => $on ? 'active' : 'deactive',
            $current === 'enabled' || $current === 'disabled' => $on ? 'enabled' : 'disabled',
            is_int($current) => $on ? 1 : 0,
            default => $on ? '1' : '0',
        };
    }

    /**
     * The members of an enum column type, e.g.
     * enum('active','inactive') -> ['active', 'inactive'].
     *
     * @return array<int, string>
     */
    public static function enumValues(string $columnType): array
    {
        if (preg_match("/^enum\((.*)\)$/i", trim($columnType), $m) !== 1) {
            return [];
        }
        preg_match_all("/'([^']*)'/", $m[1], $matches);

        return $matches[1];
    }

    /**
     * The [on, off] pair a toggle writes when the bound
     * column is an enum declaring a known toggle vocabulary
     * — or null when the enum declares none (the caller then
     * falls back to toggleValueFor's convention preservation).
     * The current value alone cannot pick the pair: 'active'
     * pairs with 'deactive' in one table and with 'inactive'
     * in another (global_settings.google_calendar_status is
     * enum('active','inactive'); writing 'deactive' there is
     * rejected by the column). Both members must be in the
     * enum, so the pairs never collide.
     *
     * @param  array<int, string>  $enumValues
     * @return array{0: string, 1: string}|null
     */
    public static function togglePairFor(array $enumValues): ?array
    {
        foreach ([['yes', 'no'], ['active', 'inactive'], ['active', 'deactive'], ['enabled', 'disabled'], ['enable', 'disable'], ['true', 'false']] as $pair) {
            if (in_array($pair[0], $enumValues, true) && in_array($pair[1], $enumValues, true)) {
                return $pair;
            }
        }

        return null;
    }

    /**
     * A time input submits HH:MM; settings columns that hold
     * a time of day store HH:MM:SS. Pad the seconds so a
     * rendered-then-saved time round-trips byte-identically
     * instead of silently shortening the stored value.
     * Anything that is not a plain HH:MM[:SS] passes through.
     */
    public static function normalizeTimeValue(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\d{1,2}:\d{2}$/', $value) === 1) {
            return $value.':00';
        }

        return $value;
    }

    /**
     * The zero value a NOT NULL column can always accept, by
     * column family — used when a bound settings save would
     * otherwise write null (a blank form field, nulled by the
     * ConvertEmptyStringsToNull middleware) into a column
     * that can never hold it on an insert. Returns null when
     * the family has no safe zero value (dates/datetimes):
     * the caller then leaves the column out of the insert.
     *
     * @param  array<string, mixed>  $column  one Schema::getColumns() entry
     */
    public static function zeroValueFor(array $column): mixed
    {
        $type = (string) ($column['type_name'] ?? '');

        return match (true) {
            in_array($type, ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set', 'string'], true) => '',
            in_array($type, ['bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint', 'decimal', 'numeric', 'float', 'double', 'real', 'boolean', 'bool'], true) => 0,
            $type === 'time' => '00:00:00',
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Bound-table CONSUMPTION (closure programme Item 2, 7 Nov 2026)
    //
    // The hub WRITES bound settings tables; these accessors are the
    // framework's single READ path for the sections whose values
    // must drive the running app — company identity, app name,
    // currency format, theme, storage — mirroring how the source
    // consumes its own tables (its global_setting() / company() /
    // currency_format() helpers and its storage config provider).
    // Shells, lists and documents read through here, never with
    // per-page table reads of their own. Every read is guarded —
    // an app without the table (fresh install, test app) gets the
    // declared defaults, never an error — and cached per request.
    // ------------------------------------------------------------------

    /** @var array<string, object|null> */
    protected static array $boundRowCache = [];

    protected static ?int $boundCacheApp = null;

    /**
     * The cache lives for one application instance (one
     * request; one test app). A new app instance — a fresh
     * test case, a new Octane worker boot — starts empty.
     */
    protected static function scopeBoundCache(): void
    {
        $id = spl_object_id(app());
        if (self::$boundCacheApp !== $id) {
            self::$boundCacheApp = $id;
            self::$boundRowCache = [];
        }
    }

    /** Test hook: drop the per-request bound-row cache. */
    public static function forgetBoundSettings(): void
    {
        self::$boundRowCache = [];
    }

    /**
     * The effective (first) row of a bound settings table, or
     * null when the table does not exist in this app or is empty.
     */
    public static function boundRow(string $table): ?object
    {
        self::scopeBoundCache();
        if (array_key_exists($table, self::$boundRowCache)) {
            return self::$boundRowCache[$table];
        }
        $row = null;
        try {
            if (Schema::hasTable($table)) {
                $row = DB::table($table)->orderBy('id')->first();
            }
        } catch (\Throwable) {
            $row = null;
        }

        return self::$boundRowCache[$table] = $row;
    }

    /** The ported company record (companies, first row). */
    public static function companyRow(): ?object
    {
        return self::boundRow('companies');
    }

    /**
     * The enabled language rows — the semantics of the source's
     * language_setting() helper: only status='enabled' rows are
     * offered anywhere a language is chosen (its auth screens
     * and locale pickers read exactly this set). Guarded: no
     * table, no languages.
     *
     * @return array<int, object>
     */
    public static function enabledLanguages(): array
    {
        try {
            if (! Schema::hasTable('language_settings')) {
                return [];
            }

            return DB::table('language_settings')
                ->where('status', 'enabled')
                ->orderBy('language_name')
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Options for an options_source-driven select. One source
     * today: 'enabled_languages' — the enabled language rows
     * keyed by their language code (the source's
     * language_setting() set).
     *
     * @return array<string, string>
     */
    public static function optionsForSource(?string $source): array
    {
        if ($source === 'enabled_languages') {
            $options = [];
            foreach (self::enabledLanguages() as $language) {
                $code = (string) ($language->language_code ?? '');
                if ($code !== '') {
                    $options[$code] = (string) ($language->language_name ?? $code);
                }
            }

            return $options;
        }

        return [];
    }

    /**
     * Role options for a roles-type bound-section field: every
     * role except the client role — the source's time-log
     * settings list exactly this set (Role::where('name','<>',
     * 'client')).
     *
     * @return array<int, string>
     */
    public static function roleOptions(): array
    {
        try {
            if (! Schema::hasTable('roles')) {
                return [];
            }

            return DB::table('roles')
                ->where('name', '<>', 'client')
                ->orderBy('id')
                ->pluck('display_name', 'id')
                ->map(fn ($name, $id) => (string) ($name !== null && $name !== '' ? $name : 'Role '.$id))
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The company name the source prints on its documents
     * (company()->company_name), falling back to the app name.
     */
    public static function companyName(): string
    {
        try {
            $name = self::companyRow()->company_name ?? null;

            return is_string($name) && trim($name) !== '' ? $name : (string) config('app.name', 'LaraSlice');
        } catch (\Throwable) {
            return 'LaraSlice';
        }
    }

    /**
     * The company identity block a document header renders —
     * the fields the source's invoice document shows.
     *
     * @return array{name: string, email: mixed, phone: mixed, address: mixed, website: mixed}
     */
    public static function companyIdentity(): array
    {
        $row = self::companyRow();

        return [
            'name' => self::companyName(),
            'email' => $row->company_email ?? null,
            'phone' => $row->company_phone ?? null,
            'address' => $row->address ?? null,
            'website' => $row->website ?? null,
        ];
    }

    /**
     * The application name for page titles and shell branding:
     * the source's global_app_name first (its auth layout and
     * public chrome read exactly that), then the company
     * record's own app_name (the source's base controller
     * prefers it where a company is resolved), then config.
     */
    public static function appName(): string
    {
        try {
            $global = self::boundRow('global_settings')->global_app_name ?? null;
            if (is_string($global) && trim($global) !== '') {
                return $global;
            }
            $companyApp = self::companyRow()->app_name ?? null;
            if (is_string($companyApp) && trim($companyApp) !== '') {
                return $companyApp;
            }

            return (string) config('app.name', 'LaraSlice');
        } catch (\Throwable) {
            return 'LaraSlice';
        }
    }

    /**
     * The effective currency format, resolved the way the
     * source's currency_format() resolves it, with the
     * hub-bound company format row first:
     *
     *  1. a `currency_format_settings` row, when one exists —
     *     that is the row the hub's Currency section edits;
     *  2. the company's currency row (`companies.currency_id`
     *     → `currencies`) — the store the source's formatter
     *     actually reads — else the first currency row;
     *  3. declared defaults identical to PHP's number_format
     *     defaults, so an unwired app renders exactly as before.
     *
     * @return array{position: string, decimals: int, thousand_separator: string, decimal_separator: string, symbol: ?string}
     */
    public static function currencyFormat(): array
    {
        $hub = self::boundRow('currency_format_settings');
        if ($hub !== null) {
            return self::normalizeCurrencyFormat($hub, self::companyCurrencyRow()->currency_symbol ?? self::firstCurrencyRow()->currency_symbol ?? null);
        }
        $currency = self::companyCurrencyRow() ?? self::firstCurrencyRow();
        if ($currency !== null) {
            return self::normalizeCurrencyFormat($currency, $currency->currency_symbol ?? null);
        }

        return ['position' => 'left', 'decimals' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.', 'symbol' => null];
    }

    /**
     * The effective format for a specific record: the hub row
     * still wins globally; otherwise the record's own currency
     * row carries its format (the source formats per currency
     * when a currency is named).
     *
     * @return array{position: string, decimals: int, thousand_separator: string, decimal_separator: string, symbol: ?string}
     */
    public static function currencyFormatFor(mixed $item = null): array
    {
        if (self::boundRow('currency_format_settings') === null && $item !== null) {
            $currencyId = data_get($item, 'currency_id');
            if (is_numeric($currencyId)) {
                $row = self::currencyRow((int) $currencyId);
                if ($row !== null) {
                    return self::normalizeCurrencyFormat($row, $row->currency_symbol ?? null);
                }
            }
        }

        return self::currencyFormat();
    }

    /** A bare formatted amount (no symbol) under the effective format. */
    public static function formatAmount(float|int|string $value, ?array $format = null): string
    {
        $format ??= self::currencyFormat();

        return number_format((float) $value, $format['decimals'], $format['decimal_separator'], $format['thousand_separator']);
    }

    /**
     * Money the way the source's currency_format() renders it:
     * the amount under the effective format, with the symbol
     * placed per currency_position (left / right /
     * left_with_space / right_with_space).
     */
    public static function money(mixed $value, ?string $symbol = null, mixed $item = null): string
    {
        $format = self::currencyFormatFor($item);
        $amount = self::formatAmount((float) $value, $format);
        $symbol = htmlspecialchars((string) ($symbol ?? $format['symbol'] ?? ''), ENT_QUOTES, 'UTF-8');

        return match ($format['position']) {
            'right' => $amount.$symbol,
            'left_with_space' => $symbol.' '.$amount,
            'right_with_space' => $amount.' '.$symbol,
            default => $symbol.$amount,
        };
    }

    protected static function companyCurrencyRow(): ?object
    {
        $currencyId = self::companyRow()->currency_id ?? null;

        return is_numeric($currencyId) ? self::currencyRow((int) $currencyId) : null;
    }

    protected static function firstCurrencyRow(): ?object
    {
        return self::boundRow('currencies');
    }

    protected static function currencyRow(int $id): ?object
    {
        self::scopeBoundCache();
        $key = 'currencies#'.$id;
        if (array_key_exists($key, self::$boundRowCache)) {
            return self::$boundRowCache[$key];
        }
        $row = null;
        try {
            if (Schema::hasTable('currencies')) {
                $row = DB::table('currencies')->where('id', $id)->first();
            }
        } catch (\Throwable) {
            $row = null;
        }

        return self::$boundRowCache[$key] = $row;
    }

    /**
     * @return array{position: string, decimals: int, thousand_separator: string, decimal_separator: string, symbol: ?string}
     */
    protected static function normalizeCurrencyFormat(object $row, ?string $symbol): array
    {
        $position = (string) ($row->currency_position ?? 'left');
        $decimals = $row->no_of_decimal ?? null;

        return [
            'position' => in_array($position, ['left', 'right', 'left_with_space', 'right_with_space'], true) ? $position : 'left',
            'decimals' => is_numeric($decimals) ? max(0, min(6, (int) $decimals)) : 2,
            'thousand_separator' => is_string($row->thousand_separator ?? null) ? $row->thousand_separator : ',',
            'decimal_separator' => is_string($row->decimal_separator ?? null) && $row->decimal_separator !== '' ? $row->decimal_separator : '.',
            'symbol' => $symbol,
        ];
    }

    /**
     * The theme row for a panel (the source keeps one row per
     * panel: admin / project_admin / employee / client and
     * resolves it from the signed-in user's role family);
     * defaults to the admin row the hub's Theme section edits.
     */
    public static function themeRow(?string $panel = null): ?object
    {
        self::scopeBoundCache();
        $panel ??= self::themePanel();
        $key = 'theme_settings#'.$panel;
        if (array_key_exists($key, self::$boundRowCache)) {
            return self::$boundRowCache[$key];
        }
        $row = null;
        try {
            if (Schema::hasTable('theme_settings')) {
                $row = DB::table('theme_settings')->where('panel', $panel)->orderBy('id')->first()
                    ?? DB::table('theme_settings')->orderBy('id')->first();
            }
        } catch (\Throwable) {
            $row = null;
        }

        return self::$boundRowCache[$key] = $row;
    }

    /** The panel the current request's chrome follows. */
    public static function themePanel(): string
    {
        try {
            $user = auth()->user();
            if ($user !== null) {
                $names = '';
                if (method_exists($user, 'getRoleNames')) {
                    $names = strtolower(implode(' ', $user->getRoleNames()->all()));
                } elseif (isset($user->roles) && is_iterable($user->roles)) {
                    $names = strtolower(implode(' ', collect($user->roles)->map(fn ($r) => (string) ($r->name ?? $r->slug ?? ''))->all()));
                }
                if (str_contains($names, 'client')) {
                    return 'client';
                }
                if (str_contains($names, 'employee')) {
                    return 'employee';
                }
            }
        } catch (\Throwable) {
            // No resolvable user: the admin panel is the default.
        }

        return 'admin';
    }

    /**
     * The declared field → design-token mapping for the port's
     * chrome, as CSS variable declarations for a `:root` block.
     * The source's emitted theme CSS drives its primary buttons,
     * links, badges and active states from ONE field —
     * header_color (its --header_color) — so header_color maps
     * to the shell's primary tokens; sidebar_color and
     * sidebar_text_color map to the sidebar tokens. (The
     * source stores link_color and user_css but no source view
     * renders them; they are deliberately not mapped.) Values
     * are colour-validated before emission — a settings field
     * must never become a CSS injection vector.
     */
    public static function themeCssVariables(?string $panel = null): string
    {
        $row = self::themeRow($panel);
        if ($row === null) {
            return '';
        }
        $map = [
            '--primary' => $row->header_color ?? null,
            '--sidebar-primary' => $row->header_color ?? null,
            '--ring' => $row->header_color ?? null,
            '--sidebar' => $row->sidebar_color ?? null,
            '--sidebar-foreground' => $row->sidebar_text_color ?? null,
        ];
        $out = [];
        foreach ($map as $var => $value) {
            if (is_string($value) && self::isCssColour($value)) {
                $out[] = $var.': '.trim($value).';';
            }
        }

        return implode(' ', $out);
    }

    protected static function isCssColour(string $value): bool
    {
        $value = trim($value);

        return preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1
            || preg_match('/^(?:rgb|rgba|hsl|hsla|oklch|oklab)\([0-9\s\.,%\/]+\)$/i', $value) === 1;
    }

    /** The enabled storage row (the source's storage_setting()). */
    public static function storageRow(): ?object
    {
        self::scopeBoundCache();
        $key = 'file_storage_settings#enabled';
        if (array_key_exists($key, self::$boundRowCache)) {
            return self::$boundRowCache[$key];
        }
        $row = null;
        try {
            if (Schema::hasTable('file_storage_settings')) {
                $row = DB::table('file_storage_settings')->where('status', 'enabled')->orderBy('id')->first();
            }
        } catch (\Throwable) {
            $row = null;
        }

        return self::$boundRowCache[$key] = $row;
    }

    /**
     * Apply the enabled storage row to the filesystem config —
     * the framework counterpart of the source's
     * FileStorageCustomConfigProvider: the row's `filesystem`
     * selects the default disk, and an S3-family selection
     * takes its credentials from the row's `auth_keys` JSON
     * ({driver, key, secret, region, bucket}). Selections with
     * no driver in the framework (google_drive, dropbox) leave
     * the app default untouched rather than pointing uploads
     * at an undefined disk. Never throws: storage settings
     * must not be able to break application boot.
     */
    public static function applyStorageSettings(): void
    {
        try {
            $row = self::storageRow();
            if ($row === null) {
                return;
            }
            $filesystem = (string) ($row->filesystem ?? 'local');
            $keys = is_string($row->auth_keys ?? null) ? json_decode($row->auth_keys, true) : null;
            $keys = is_array($keys) ? $keys : [];
            switch ($filesystem) {
                case 'local':
                    config(['filesystems.default' => 'local']);
                    break;
                case 'aws_s3':
                    if ($keys !== []) {
                        $driver = (string) ($keys['driver'] ?? 's3');
                        config([
                            'filesystems.default' => $driver,
                            'filesystems.cloud' => $driver,
                            'filesystems.disks.s3.key' => $keys['key'] ?? null,
                            'filesystems.disks.s3.secret' => $keys['secret'] ?? null,
                            'filesystems.disks.s3.region' => $keys['region'] ?? null,
                            'filesystems.disks.s3.bucket' => $keys['bucket'] ?? null,
                        ]);
                    }
                    break;
                case 'digitalocean':
                case 'wasabi':
                    if ($keys !== []) {
                        $region = (string) ($keys['region'] ?? '');
                        $endpoint = $filesystem === 'digitalocean'
                            ? 'https://'.$region.'.digitaloceanspaces.com'
                            : 'https://s3.'.$region.'.wasabisys.com';
                        config([
                            'filesystems.default' => $filesystem,
                            'filesystems.cloud' => $filesystem,
                            'filesystems.disks.'.$filesystem => [
                                'driver' => 's3',
                                'key' => $keys['key'] ?? null,
                                'secret' => $keys['secret'] ?? null,
                                'region' => $keys['region'] ?? null,
                                'bucket' => $keys['bucket'] ?? null,
                                'endpoint' => $keys['endpoint'] ?? $endpoint,
                                'use_path_style_endpoint' => false,
                            ],
                        ]);
                    }
                    break;
                default:
                    // google_drive / dropbox / unknown: no driver
                    // ships with the framework — leave the app's
                    // configured default disk in place.
                    break;
            }
        } catch (\Throwable) {
            // Boot must never fail on a settings table.
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
}
