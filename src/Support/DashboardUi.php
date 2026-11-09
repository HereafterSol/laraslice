<?php

namespace LaraSlice\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Security\Access;

/**
 * Declared dashboard widgets (S-UI 4, pattern P4): each slice
 * may declare `ui.dashboard.widgets`, and the framework
 * aggregates them into the standard dashboard. Types:
 *
 * - counter: a model aggregate (with declared-stats filter
 *   semantics, '!' excludes) as a big number card.
 * - list: the most recent records as a mini list linking to
 *   their detail pages.
 * - week: a week strip — records bucketed per day of the
 *   current week by a date field.
 * - chart: model counts grouped by a column, with labels and
 *   group order from the declaration (same as show widgets).
 * - today: records whose date field falls today — the
 *   personal-day panel (shifts, due items); renders its
 *   empty state rather than disappearing.
 * - clock: the current user's attendance state for today
 *   (not clocked in / clocked in since … / shift complete)
 *   from an attendances-style slice.
 *
 * Personal widgets (pattern D2): any widget may declare
 * `scope: own`, narrowing it to the signed-in user. The
 * ownership test comes from the widget's own `own:` spec
 * ({field} or {relation, key}), else the slice's declared
 * access.own_scope clauses, else a conventional user_id /
 * created_by column. When own-scope cannot be established
 * (no user, no clause, no column) the widget is skipped —
 * personal data is never silently widened to everyone's.
 *
 * Audience + scope enforcement: a widget may declare
 * `roles:` (role names, e.g. [admin, employee]); it renders
 * only for users holding one of those roles (super roles
 * always pass). Independently, when the slice's Heart
 * declares permissions, the signed-in user's view scope
 * for the slice (the same Access::scopeFor resolution the
 * slice controllers enforce) gates and narrows every
 * widget: scope 'none' drops the slice's widgets entirely
 * (no dead links, no leaked totals), and owned / added /
 * both narrow widget queries with the same clauses the
 * controllers narrow lists with — field, relation and
 * pivot clauses alike. A module hidden for the user
 * (ModuleSettings) drops the slice's widgets too, exactly
 * as its pages 403. With no signed-in user (CLI, unit
 * contexts) nothing is filtered, as before.
 *
 * A widget that cannot be computed is skipped, never
 * misreported.
 */
class DashboardUi
{
    /** @var array<string, string> Memoized slice view scopes, keyed user|permission-base. */
    protected static array $scopeCache = [];

    /** @var array<int, array<int, string>> Memoized normalized role names, keyed by user id. */
    protected static array $roleCache = [];

    /** Test seam: forget memoized resolutions. */
    public static function flush(): void
    {
        self::$scopeCache = [];
        self::$roleCache = [];
    }

    /**
     * @param  array<int, array{manifest: array, model: ?string, routes: array{index?: ?string, show?: ?string}}>  $entries
     * @return array<int, array<string, mixed>>
     */
    public static function collect(array $entries): array
    {
        $widgets = [];
        foreach ($entries as $entry) {
            foreach (self::widgetsFromManifest(
                (array) ($entry['manifest'] ?? []),
                $entry['model'] ?? null,
                (array) ($entry['routes'] ?? [])
            ) as $widget) {
                $widgets[] = $widget;
            }
        }

        // Declared order wins (stable): portals compose their
        // widget set deliberately (layout kit step 4).
        usort($widgets, fn ($a, $b) => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));

        return $widgets;
    }

    /**
     * The signed-in user's own-record URL ("My Profile"): the
     * show URL of the viewer's own record in the slice that
     * declares a profile dashboard widget for their audience.
     * Layouts link it from the workspace nav so a user whose
     * role holds no directory grant on the people slice (an
     * employee, say) still has an in-app path to their own
     * hub — the show page itself admits the owner through
     * self-record access. Null when the user has no profile
     * record in any slice that declares one for them.
     */
    public static function ownRecordUrl(): ?string
    {
        $user = self::currentUser();
        if ($user === null || ! function_exists('app') || ! app()->bound(SliceManager::class)) {
            return null;
        }
        try {
            $manager = app(SliceManager::class);
            $manager->discover();
            $slices = $manager->discoveredSlices();
            if ($slices === []) {
                $slices = $manager->getActiveSlices();
            }
            foreach ($slices as $slice) {
                if (isset($slice->active) && ! $slice->active) {
                    continue;
                }
                $manifest = (array) ($slice->raw ?? []);
                $defs = $manifest['ui']['dashboard']['widgets'] ?? [];
                if (! is_array($defs) || $defs === []) {
                    continue;
                }
                foreach ($defs as $def) {
                    if (! is_array($def) || ($def['type'] ?? '') !== 'profile') {
                        continue;
                    }
                    if (! self::rolesAllow($def, $user)) {
                        continue;
                    }
                    $modelClass = $slice->getModelClass();
                    if (! $modelClass || ! class_exists($modelClass)) {
                        continue;
                    }
                    $routesPath = $slice->getRoutesPath();
                    $webFile = $routesPath ? $routesPath.'/web.php' : null;
                    if (! $webFile || ! is_file($webFile)
                        || ! preg_match("/Route::prefix\('([^']+)'\)/", (string) file_get_contents($webFile), $m)) {
                        continue;
                    }
                    $widget = self::profile($def, $modelClass, ['show' => '/'.trim($m[1], '/').'/{id}']);
                    if ($widget !== null && ! empty($widget['url'])) {
                        return (string) $widget['url'];
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array{index?: ?string, show?: ?string}  $routes  URI patterns; show carries an {id} placeholder
     * @return array<int, array<string, mixed>>
     */
    public static function widgetsFromManifest(array $manifest, ?string $modelClass, array $routes = []): array
    {
        $defs = $manifest['ui']['dashboard']['widgets'] ?? [];
        if (! is_array($defs) || $defs === [] || ! $modelClass || ! class_exists($modelClass)) {
            return [];
        }

        $user = self::currentUser();
        $scopeNone = false;
        if ($user !== null) {
            // A slice the user cannot view at all contributes
            // nothing — no dead links, no leaked totals. The
            // one exception is the profile widget: it reads the
            // viewer's OWN record by construction, and the show
            // page admits the owner through self-record access
            // even when their role holds no view grant on the
            // people slice (the source's arrangement — employees
            // hold no "view employees" permission, yet their
            // dashboard carries their own card). A hidden
            // feature module still suppresses everything (its
            // pages 403 for this user too).
            $scopeNone = self::sliceViewScope($manifest, $user) === 'none';
            try {
                if (ModuleSettings::isHiddenFor($user, isset($manifest['feature_module']) ? (string) $manifest['feature_module'] : null)) {
                    return [];
                }
            } catch (\Throwable) {
                // Module visibility is best-effort here; the
                // controllers remain the enforcement point.
            }
        }

        $widgets = [];
        foreach ($defs as $def) {
            if (! is_array($def)) {
                continue;
            }
            if ($scopeNone && (string) ($def['type'] ?? '') !== 'profile') {
                continue;
            }
            if (! self::rolesAllow($def, $user)) {
                continue;
            }
            $type = (string) ($def['type'] ?? '');
            $handle = (string) ($def['handle'] ?? $type);
            try {
                $widget = match ($type) {
                    'counter' => self::counter($def, $modelClass, $routes, $manifest),
                    'table' => self::table($def, $modelClass, $routes, $manifest),
                    'calendar' => self::calendar($def, $modelClass, $routes, $manifest),
                    'list' => self::recentList($def, $modelClass, $routes, $manifest),
                    'week' => self::week($def, $modelClass, $routes, $manifest),
                    'chart' => self::chart($def, $modelClass, $manifest),
                    'today' => self::today($def, $modelClass, $routes, $manifest),
                    'clock' => self::clock($def, $modelClass, $routes),
                    'people_dates' => self::peopleDates($def, $modelClass, $routes, $manifest),
                    'profile' => self::profile($def, $modelClass, $routes),
                    'schedule' => self::schedule($def, $modelClass, $routes, $manifest),
                    default => null,
                };
                if ($widget !== null) {
                    $widgets[] = ['handle' => $handle, 'order' => (int) ($def['order'] ?? 100)] + $widget;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $widgets;
    }

    /**
     * The base query for a widget, narrowed to the current
     * user when the declaration says `scope: own`. Returns
     * null when own-scope cannot be established.
     */
    protected static function baseQuery(array $def, string $modelClass, array $manifest): mixed
    {
        $query = $modelClass::query();
        $user = self::currentUser();

        if ((string) ($def['scope'] ?? '') === 'own') {
            // Personal widget (pattern D2): narrow to the
            // signed-in user via the def's own spec, else the
            // slice's declared own-scope clauses, else a
            // conventional owner column. Unresolvable means
            // the widget is skipped — personal data is never
            // widened to everyone's.
            if ($user === null) {
                return null;
            }
            $uid = (int) $user->id;
            $declared = [];
            if (isset($def['own']) && is_array($def['own'])) {
                $declared = [$def['own']];
            } elseif (! empty($manifest['access']['own_scope']) && is_array($manifest['access']['own_scope'])) {
                $declared = $manifest['access']['own_scope'];
            }
            $clauses = self::normalizeOwnClauses($declared, $uid);
            if ($clauses === []) {
                try {
                    $table = (new $modelClass)->getTable();
                    foreach (['user_id', 'created_by'] as $column) {
                        if (\Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                            $clauses = [['field' => $column, 'value' => $uid]];
                            break;
                        }
                    }
                } catch (\Throwable) {
                    $clauses = [];
                }
            }
            if ($clauses === []) {
                return null;
            }

            return self::applyClauses($query, $clauses, self::modelTable($modelClass));
        }

        // Slice view-scope narrowing: a user whose grant on
        // this slice is owned / added / both sees widget
        // numbers over exactly the records their list shows
        // (the controllers' clause semantics, mirrored in
        // viewScopeClauses()).
        if ($user !== null) {
            $scope = self::sliceViewScope($manifest, $user);
            if (in_array($scope, ['owned', 'added', 'both'], true)) {
                $clauses = self::viewScopeClauses($manifest, $scope, (int) $user->id);
                if ($clauses !== []) {
                    $query = self::applyClauses($query, $clauses, self::modelTable($modelClass));
                }
            }
        }

        return $query;
    }

    protected static function currentUser(): ?object
    {
        try {
            $user = auth()->user();
        } catch (\Throwable) {
            return null;
        }

        return is_object($user) && isset($user->id) ? $user : null;
    }

    /**
     * The slice's permission base: the base of the first
     * permission its Heart declares (the same Heart-first
     * rule the slice controllers resolve theirs by). Null
     * when the Heart declares none — such slices are not
     * scope-enforced here, as before.
     */
    protected static function permissionBase(array $manifest): ?string
    {
        foreach ((array) ($manifest['permissions'] ?? []) as $perm) {
            if (is_string($perm) && str_contains($perm, '.')) {
                return substr($perm, 0, (int) strpos($perm, '.'));
            }
        }

        return null;
    }

    /**
     * The signed-in user's view scope for the slice,
     * resolved exactly like the controllers resolve theirs
     * (widest grant across the base/plural view + wildcard
     * slugs; the view-own slug form means 'owned'). Null
     * when enforcement does not apply (no user, or the
     * Heart declares no permissions).
     */
    protected static function sliceViewScope(array $manifest, ?object $user): ?string
    {
        if ($user === null || ! isset($user->id)) {
            return null;
        }
        $base = self::permissionBase($manifest);
        if ($base === null) {
            return null;
        }
        $key = $user->id.'|'.$base;
        if (isset(self::$scopeCache[$key])) {
            return self::$scopeCache[$key];
        }
        try {
            $plural = Str::plural($base);
            $scope = 'none';
            foreach (["{$base}.view", "{$plural}.view", "{$base}.*", "{$plural}.*"] as $slug) {
                $scope = Access::widerScope($scope, Access::scopeFor($user, $slug));
            }
            if ($scope === 'none'
                && Access::allows($user, ["{$base}.view-own", "{$plural}.view-own"])) {
                $scope = 'owned';
            }
        } catch (\Throwable) {
            // Fail closed: an unresolvable grant store hides
            // the slice's widgets rather than leaking totals
            // (the controllers 403 in the same situation).
            $scope = 'none';
        }

        return self::$scopeCache[$key] = $scope;
    }

    /**
     * Whether a widget's declared `roles:` audience admits
     * the signed-in user. No declaration (or no user, e.g.
     * CLI/unit contexts) admits everyone, as before; super
     * roles always pass.
     */
    protected static function rolesAllow(array $def, ?object $user): bool
    {
        $declared = $def['roles'] ?? null;
        if (! is_array($declared) || $declared === [] || $user === null) {
            return true;
        }
        try {
            if (Access::isSuperAdmin($user) || Access::isSuperDev($user)) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to the role-name match.
        }
        $mine = self::userRoleNames($user);
        foreach ($declared as $role) {
            if (in_array(self::normalizeRole((string) $role), $mine, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> Normalized role names of the user. */
    protected static function userRoleNames(object $user): array
    {
        $id = (int) ($user->id ?? 0);
        if (isset(self::$roleCache[$id])) {
            return self::$roleCache[$id];
        }
        $names = [];
        try {
            if (method_exists($user, 'roles')) {
                $names = $user->roles()->pluck('roles.name')->map(fn ($n) => (string) $n)->all();
            }
        } catch (\Throwable) {
            $names = [];
        }
        if ($names === [] && $id > 0) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('role_user') && \Illuminate\Support\Facades\Schema::hasTable('roles')) {
                    $names = \Illuminate\Support\Facades\DB::table('role_user')
                        ->join('roles', 'roles.id', '=', 'role_user.role_id')
                        ->where('role_user.user_id', $id)
                        ->pluck('roles.name')
                        ->map(fn ($n) => (string) $n)
                        ->all();
                }
            } catch (\Throwable) {
                $names = [];
            }
        }
        $normalized = array_values(array_unique(array_map(fn ($n) => self::normalizeRole($n), $names)));

        return self::$roleCache[$id] = $normalized;
    }

    protected static function normalizeRole(string $role): string
    {
        return str_replace([' ', '_'], '-', strtolower(trim($role)));
    }

    /**
     * The clauses that narrow a widget query to the user's
     * view scope, mirroring the controllers': owned → the
     * slice's own-scope clauses (declared access.own_scope,
     * else the user_id owner column); added → authorship
     * (added_by), falling back to the own clauses, else a
     * never-match clause; both → the union.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function viewScopeClauses(array $manifest, string $scope, int $uid): array
    {
        $own = self::ownScopeClauses($manifest, $uid);
        $added = static function () use ($manifest, $own, $uid): array {
            if (isset($manifest['fields']['added_by'])) {
                return [['field' => 'added_by', 'value' => $uid]];
            }
            if ($own !== []) {
                return $own;
            }

            return [['field' => 'id', 'value' => -1]];
        };

        return match ($scope) {
            'owned' => $own,
            'added' => $added(),
            'both' => self::mergeClauses($own, $added()),
            default => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    protected static function ownScopeClauses(array $manifest, int $uid): array
    {
        $declared = $manifest['access']['own_scope'] ?? null;
        if (is_array($declared) && $declared !== []) {
            $clauses = self::normalizeOwnClauses($declared, $uid);
            if ($clauses !== []) {
                return $clauses;
            }
        }
        $ownerField = isset($manifest['owner_field'])
            ? (string) $manifest['owner_field']
            : (isset($manifest['fields']['user_id']) ? 'user_id' : null);

        return $ownerField !== null ? [['field' => $ownerField, 'value' => $uid]] : [];
    }

    /**
     * Normalize declared own-scope entries to the
     * controller clause shape ({field} | {relation, key} |
     * {pivot, foreign_key, user_key}, value resolved to the
     * user) — the same normalization the controllers apply.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function normalizeOwnClauses(array $declared, int $uid): array
    {
        $clauses = [];
        foreach ($declared as $clause) {
            if (! is_array($clause)) {
                continue;
            }
            if (! empty($clause['field'])) {
                $clauses[] = ['field' => (string) $clause['field'], 'value' => $uid];
            } elseif (! empty($clause['relation'])) {
                $clauses[] = ['relation' => (string) $clause['relation'], 'key' => (string) ($clause['key'] ?? 'id'), 'value' => $uid];
            } elseif (! empty($clause['pivot'])) {
                $clauses[] = [
                    'pivot' => (string) $clause['pivot'],
                    'foreign_key' => (string) ($clause['foreign_key'] ?? ''),
                    'user_key' => (string) ($clause['user_key'] ?? 'user_id'),
                    'value' => $uid,
                ];
            }
        }

        return $clauses;
    }

    /**
     * Apply scope clauses to a query as a grouped OR — a
     * record counts when ANY clause claims it, the same
     * semantics the list narrowing uses. Pivot clauses ride
     * an EXISTS subquery against the model's own table.
     */
    protected static function applyClauses(mixed $query, array $clauses, string $table): mixed
    {
        return $query->where(function ($q) use ($clauses, $table) {
            foreach ($clauses as $clause) {
                if (! empty($clause['field'])) {
                    $q->orWhere((string) $clause['field'], $clause['value'] ?? null);
                } elseif (! empty($clause['relation'])) {
                    $key = (string) ($clause['key'] ?? 'id');
                    $value = $clause['value'] ?? null;
                    $q->orWhereHas((string) $clause['relation'], function ($rel) use ($key, $value) {
                        // Qualify: an unqualified 'id' is ambiguous
                        // against pivot columns on BelongsToMany.
                        $rel->where($rel->getModel()->getTable().'.'.$key, $value);
                    });
                } elseif (! empty($clause['pivot']) && ($clause['foreign_key'] ?? '') !== '' && $table !== '') {
                    $pivot = (string) $clause['pivot'];
                    $foreignKey = (string) $clause['foreign_key'];
                    $userKey = (string) ($clause['user_key'] ?? 'user_id');
                    $value = $clause['value'] ?? null;
                    $q->orWhereExists(function ($sub) use ($pivot, $foreignKey, $userKey, $value, $table) {
                        $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                            ->from($pivot)
                            ->whereColumn($pivot.'.'.$foreignKey, $table.'.id')
                            ->where($pivot.'.'.$userKey, $value);
                    });
                }
            }
        });
    }

    /** @param  array<int, array<string, mixed>>  $a  @param  array<int, array<string, mixed>>  $b  @return array<int, array<string, mixed>> */
    protected static function mergeClauses(array $a, array $b): array
    {
        $seen = [];
        $merged = [];
        foreach (array_merge($a, $b) as $clause) {
            $key = json_encode($clause);
            if ($key === false || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $clause;
        }

        return $merged;
    }

    protected static function modelTable(string $modelClass): string
    {
        try {
            return (new $modelClass)->getTable();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The slice's display label for widget titles: the
     * Heart's declared title / name (e.g. "Tasks"), so a
     * counter that declares only a handle + label still
     * names WHAT it counts.
     */
    protected static function sliceLabel(array $manifest): ?string
    {
        foreach (['title', 'name'] as $key) {
            if (! empty($manifest[$key]) && is_string($manifest[$key])) {
                return $manifest[$key];
            }
        }

        return null;
    }

    protected static function counter(array $def, string $modelClass, array $routes, array $manifest = []): ?array
    {
        $base = self::baseQuery($def, $modelClass, $manifest);
        if ($base === null) {
            return null;
        }
        $query = self::applyFilters($base, (array) ($def['filter'] ?? []));
        if (! empty($def['range']) && is_array($def['range']) && ($def['range']['period'] ?? '') === 'this_week'
            && ! empty($def['range']['field'])) {
            $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();
            $query = $query->whereBetween((string) $def['range']['field'], [
                $weekStart->toDateString(),
                $weekStart->copy()->addDays(6)->endOfDay()->toDateTimeString(),
            ]);
        }
        $aggregate = (string) ($def['aggregate'] ?? 'count');
        $raw = match ($aggregate) {
            'sum' => (float) $query->sum((string) ($def['column'] ?? '')),
            'avg' => (float) $query->avg((string) ($def['column'] ?? '')),
            default => (int) $query->count(),
        };

        $widget = [
            'type' => 'counter',
            'title' => (string) ($def['title'] ?? self::sliceLabel($manifest) ?? Str::headline((string) ($def['handle'] ?? 'Counter'))),
            'icon' => $def['icon'] ?? null,
            'value' => ($aggregate === 'count' ? number_format($raw) : self::trimNumber((float) $raw)).(string) ($def['suffix'] ?? ''),
            'label' => isset($def['label']) ? (string) $def['label'] : null,
            'link' => $routes['index'] ?? null,
        ];

        // Dual figure (layout kit step 4): a counter may declare
        // a `secondary` measure — its own label/filter/aggregate —
        // rendered beside the primary (the source dashboards'
        // "1 Pending · 1 Overdue" cards).
        if (! empty($def['secondary']) && is_array($def['secondary'])) {
            $sec = $def['secondary'];
            $secBase = self::baseQuery($def, $modelClass, $manifest);
            if ($secBase !== null) {
                $secQuery = self::applyFilters($secBase, (array) ($sec['filter'] ?? []));
                $secAgg = (string) ($sec['aggregate'] ?? 'count');
                $secRaw = match ($secAgg) {
                    'sum' => (float) $secQuery->sum((string) ($sec['column'] ?? '')),
                    'avg' => (float) $secQuery->avg((string) ($sec['column'] ?? '')),
                    default => (int) $secQuery->count(),
                };
                $widget['secondary_value'] = ($secAgg === 'count' ? number_format($secRaw) : self::trimNumber((float) $secRaw)).(string) ($sec['suffix'] ?? '');
                $widget['secondary_label'] = (string) ($sec['label'] ?? 'Secondary');
                $widget['secondary_tone'] = (string) ($sec['tone'] ?? 'default');
            }
        }

        return $widget;
    }

    /**
     * Table widget (layout kit step 4): a real mini-table —
     * declared columns over the slice's records, optionally
     * own-scoped like every other widget. Cells are formatted
     * server-side (text | date | datetime | status).
     */
    protected static function table(array $def, string $modelClass, array $routes, array $manifest = []): ?array
    {
        $query = self::baseQuery($def, $modelClass, $manifest);
        if ($query === null) {
            return null;
        }
        $query = self::applyFilters($query, (array) ($def['filter'] ?? []));
        if (! empty($def['with']) && is_array($def['with'])) {
            $query = $query->with(array_map('strval', $def['with']));
        }
        $items = $query
            ->orderBy((string) ($def['order_by'] ?? 'id'), (string) ($def['direction'] ?? 'desc'))
            ->limit((int) ($def['limit'] ?? 5))
            ->get();
        $columns = [];
        foreach ((array) ($def['columns'] ?? []) as $col) {
            if (is_array($col) && ! empty($col['field'])) {
                $columns[] = [
                    'label' => (string) ($col['label'] ?? Str::headline((string) $col['field'])),
                    'field' => (string) $col['field'],
                    'format' => (string) ($col['format'] ?? 'text'),
                    'prefix' => (string) ($col['prefix'] ?? ''),
                ];
            }
        }
        if ($columns === []) {
            return null;
        }
        $rows = [];
        foreach ($items as $item) {
            $cells = [];
            foreach ($columns as $col) {
                $v = data_get($item, $col['field']);
                $cells[] = match ($col['format']) {
                    'date' => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-m-Y') : '—',
                    'datetime' => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-m-Y, h:i A') : '—',
                    'status' => $v !== null && $v !== '' ? Str::headline((string) $v) : '—',
                    default => $v !== null && $v !== '' ? $col['prefix'].(string) $v : '—',
                };
            }
            $rows[] = [
                'cells' => $cells,
                'url' => ! empty($routes['show']) ? str_replace('{id}', (string) ($item->id ?? ''), (string) $routes['show']) : null,
            ];
        }

        return [
            'type' => 'table',
            'title' => (string) ($def['title'] ?? Str::headline((string) ($def['handle'] ?? 'Table'))),
            'icon' => $def['icon'] ?? null,
            'columns' => array_map(fn ($c) => $c['label'], $columns),
            'rows' => $rows,
            'empty' => (string) ($def['empty'] ?? '- No record found. -'),
            'link' => $routes['index'] ?? null,
        ];
    }

    /**
     * Calendar widget (layout kit step 4): the current month as
     * a grid; declared records plot onto their date field.
     * Single-source by design — the declaring slice's records.
     */
    protected static function calendar(array $def, string $modelClass, array $routes, array $manifest = []): ?array
    {
        $query = self::baseQuery($def, $modelClass, $manifest);
        if ($query === null) {
            return null;
        }
        $dateField = (string) ($def['date_field'] ?? 'start_date_time');
        $labelField = (string) ($def['label_field'] ?? 'name');
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        $items = self::applyFilters($query, (array) ($def['filter'] ?? []))
            ->whereBetween($dateField, [$start->toDateTimeString(), $end->toDateTimeString()])
            ->get();
        $byDay = [];
        foreach ($items as $item) {
            $v = data_get($item, $dateField);
            if (! $v) {
                continue;
            }
            $byDay[\Illuminate\Support\Carbon::parse($v)->format('Y-m-d')][] = (string) data_get($item, $labelField, '');
        }
        $weeks = [];
        $cursor = $start->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY);
        while ($cursor->lte($end)) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $key = $cursor->format('Y-m-d');
                $week[] = [
                    'day' => (int) $cursor->format('j'),
                    'in_month' => $cursor->month === $start->month,
                    'today' => $cursor->isToday(),
                    'events' => array_slice($byDay[$key] ?? [], 0, 2),
                    'more' => max(0, count($byDay[$key] ?? []) - 2),
                ];
                $cursor->addDay();
            }
            $weeks[] = $week;
        }

        return [
            'type' => 'calendar',
            'title' => (string) ($def['title'] ?? Str::headline((string) ($def['handle'] ?? 'Calendar'))),
            'icon' => $def['icon'] ?? null,
            'month_label' => $start->format('F Y'),
            'weeks' => $weeks,
            'link' => $routes['index'] ?? null,
        ];
    }

    protected static function recentList(array $def, string $modelClass, array $routes, array $manifest = []): ?array
    {
        $query = self::baseQuery($def, $modelClass, $manifest);
        if ($query === null) {
            return null;
        }
        $labelField = (string) ($def['label_field'] ?? 'name');
        $query = self::applyFilters($query, (array) ($def['filter'] ?? []));
        $items = $query
            ->orderBy((string) ($def['order_by'] ?? 'id'), (string) ($def['direction'] ?? 'desc'))
            ->limit((int) ($def['limit'] ?? 5))
            ->get();
        $rows = [];
        foreach ($items as $item) {
            $label = data_get($item, $labelField);
            if (! is_scalar($label) || (string) $label === '') {
                $label = data_get($item, 'title') ?? data_get($item, 'name') ?? ('#'.data_get($item, 'id'));
            }
            $rows[] = [
                'label' => (string) $label,
                'meta' => ! empty($def['meta_field']) ? (string) (data_get($item, (string) $def['meta_field']) ?? '') : '',
                'url' => isset($routes['show']) && $routes['show']
                    ? str_replace('{id}', (string) data_get($item, 'id'), (string) $routes['show'])
                    : null,
            ];
        }
        if ($rows === []) {
            return null;
        }

        return [
            'type' => 'list',
            'title' => (string) ($def['title'] ?? 'Recent'),
            'rows' => $rows,
            'link' => $routes['index'] ?? null,
        ];
    }

    protected static function week(array $def, string $modelClass, array $routes, array $manifest = []): ?array
    {
        $field = (string) ($def['date_field'] ?? '');
        if ($field === '') {
            return null;
        }
        $query = self::baseQuery($def, $modelClass, $manifest);
        if ($query === null) {
            return null;
        }
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();
        // Lower bound as a bare date: includes date-only
        // stored values on the start day (see today()).
        $values = $query
            ->whereBetween($field, [$start->toDateString(), $end->toDateTimeString()])
            ->pluck($field);
        $buckets = array_fill(0, 7, 0);
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $day = Carbon::parse($value);
            if ($day->betweenIncluded($start, $end)) {
                $buckets[($day->dayOfWeekIso - 1)]++;
            }
        }
        $todayIso = Carbon::now()->dayOfWeekIso;
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $days[] = [
                'label' => $date->format('D'),
                'date' => $date->format('M j'),
                'count' => $buckets[$i],
                'today' => $date->isToday(),
            ];
        }

        return [
            'type' => 'week',
            'title' => (string) ($def['title'] ?? 'This Week'),
            'days' => $days,
            'link' => $routes['index'] ?? null,
        ];
    }

    protected static function chart(array $def, string $modelClass, array $manifest = []): ?array
    {
        $groupBy = (string) ($def['group_by'] ?? '');
        if ($groupBy === '') {
            return null;
        }
        $query = self::baseQuery($def, $modelClass, $manifest);
        if ($query === null) {
            return null;
        }
        $values = $query->limit(1000)->pluck($groupBy);
        $counts = [];
        foreach ($values as $value) {
            if ($value === null || ! is_scalar($value)) {
                continue;
            }
            $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
        }
        if ($counts === []) {
            return null;
        }
        $labelsMap = (array) ($def['labels'] ?? []);
        if ($labelsMap !== []) {
            $ordered = [];
            foreach ($labelsMap as $k => $_) {
                if (isset($counts[(string) $k])) {
                    $ordered[(string) $k] = $counts[(string) $k];
                }
            }
            foreach ($counts as $k => $n) {
                $ordered[$k] ??= $n;
            }
            $counts = $ordered;
        }
        $labels = [];
        $series = [];
        foreach ($counts as $k => $n) {
            $labels[] = (string) ($labelsMap[$k] ?? Str::headline($k));
            $series[] = $n;
        }

        return [
            'type' => 'chart',
            'chart' => (string) ($def['chart'] ?? 'donut'),
            'title' => (string) ($def['title'] ?? 'Chart'),
            'labels' => $labels,
            'series' => $series,
        ];
    }

    protected static function today(array $def, string $modelClass, array $routes, array $manifest = []): ?array
    {
        $field = (string) ($def['date_field'] ?? '');
        if ($field === '') {
            return null;
        }
        $query = self::baseQuery($def, $modelClass, $manifest);
        if ($query === null) {
            return null;
        }
        $labelField = (string) ($def['label_field'] ?? 'name');
        $query = self::applyFilters($query, (array) ($def['filter'] ?? []));
        // whereDate, not a datetime whereBetween: date-only
        // columns are stored as 'Y-m-d' text on some drivers,
        // and a bare date string sorts BEFORE the same day's
        // '00:00:00' bound, silently emptying the widget.
        $items = $query
            ->whereDate($field, Carbon::today())
            ->orderBy($field)
            ->limit((int) ($def['limit'] ?? 8))
            ->get();
        $rows = [];
        foreach ($items as $item) {
            $label = data_get($item, $labelField);
            if (! is_scalar($label) || (string) $label === '') {
                $label = data_get($item, 'title') ?? data_get($item, 'name') ?? ('#'.data_get($item, 'id'));
            }
            $at = data_get($item, $field);
            $rows[] = [
                'label' => (string) $label,
                // Date-only values have no time worth showing.
                'meta' => $at !== null && ! (is_string($at) && strlen($at) <= 10) ? Carbon::parse($at)->format('H:i') : '',
                'url' => isset($routes['show']) && $routes['show']
                    ? str_replace('{id}', (string) data_get($item, 'id'), (string) $routes['show'])
                    : null,
            ];
        }

        return [
            'type' => 'today',
            'title' => (string) ($def['title'] ?? 'Today'),
            'rows' => $rows,
            'empty' => (string) ($def['empty_text'] ?? 'Nothing scheduled for today.'),
            'link' => $routes['index'] ?? null,
        ];
    }

    protected static function clock(array $def, string $modelClass, array $routes): ?array
    {
        $uid = auth()->id();
        if ($uid === null) {
            return null;
        }
        $userField = (string) ($def['user_field'] ?? 'user_id');
        $inField = (string) ($def['in_field'] ?? 'clock_in_time');
        $outField = (string) ($def['out_field'] ?? 'clock_out_time');
        $row = $modelClass::query()
            ->where($userField, $uid)
            ->whereDate($inField, Carbon::today())
            ->orderBy($inField)
            ->first();
        $formatSpan = static function (Carbon $from, Carbon $to): string {
            $mins = max(0, (int) $from->diffInMinutes($to));

            return intdiv($mins, 60).':'.str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT);
        };
        if ($row === null) {
            $state = ['state' => 'out', 'headline' => 'Not clocked in today', 'detail' => ''];
        } elseif (data_get($row, $outField) === null) {
            $in = Carbon::parse(data_get($row, $inField));
            $state = ['state' => 'in', 'headline' => 'Clocked in since '.$in->format('H:i'), 'detail' => $formatSpan($in, Carbon::now()).' elapsed'];
        } else {
            $in = Carbon::parse(data_get($row, $inField));
            $out = Carbon::parse(data_get($row, $outField));
            $state = ['state' => 'done', 'headline' => 'Shift complete', 'detail' => $in->format('H:i').' – '.$out->format('H:i').' · '.$formatSpan($in, $out)];
        }

        return ['type' => 'clock', 'title' => (string) ($def['title'] ?? 'My Attendance'), 'link' => $routes['index'] ?? null,
            'punch_url' => ! empty($def['punch']) ? ($routes['punch'] ?? null) : null] + $state;
    }

    /**
     * Upcoming occurrences of a date field on people records
     * (birthdays, joining anniversaries): each record's
     * month/day is projected onto this year (or next when it
     * has passed) and kept when it lands inside the declared
     * window. Always returns the widget — an empty window is
     * an honest empty state, like the source's cards.
     */
    protected static function peopleDates(array $def, string $modelClass, array $routes, array $manifest = []): ?array
    {
        $field = (string) ($def['field'] ?? '');
        if ($field === '') {
            return null;
        }
        $query = self::baseQuery($def, $modelClass, $manifest);
        if ($query === null) {
            return null;
        }
        $query = self::applyFilters($query, (array) ($def['filter'] ?? []));
        $namePath = (string) ($def['name_field'] ?? 'user.name');
        $window = (int) ($def['days'] ?? 30);
        $today = Carbon::today();
        $found = [];
        foreach ($query->get() as $item) {
            $raw = data_get($item, $field);
            if ($raw === null || $raw === '') {
                continue;
            }
            $next = Carbon::parse($raw)->copy()->year($today->year);
            if ($next->lt($today)) {
                $next->addYear();
            }
            $diff = (int) $today->diffInDays($next);
            if ($diff > $window) {
                continue;
            }
            $label = data_get($item, $namePath);
            if (! is_scalar($label) || (string) $label === '') {
                $label = data_get($item, 'name') ?? ('#'.data_get($item, 'id'));
            }
            $found[] = ['when' => $next, 'row' => [
                'label' => (string) $label,
                'meta' => $next->format('M j').($diff === 0 ? ' · Today' : ' · in '.$diff.'d'),
                'url' => isset($routes['show']) && $routes['show']
                    ? str_replace('{id}', (string) data_get($item, 'id'), (string) $routes['show'])
                    : null,
            ]];
        }
        usort($found, fn ($a, $b) => $a['when'] <=> $b['when']);
        $rows = array_map(fn ($f) => $f['row'], array_slice($found, 0, (int) ($def['limit'] ?? 8)));

        return [
            'type' => 'people_dates',
            'title' => (string) ($def['title'] ?? 'Upcoming'),
            'rows' => $rows,
            'empty' => (string) ($def['empty_text'] ?? 'Nothing coming up.'),
            'link' => $routes['index'] ?? null,
        ];
    }

    /**
     * The signed-in user's own record as a profile card
     * (the source dashboard's welcome card): name, a subtitle
     * path (designation), and declared label/value lines read
     * off the record by data path. Skipped entirely when the
     * user has no such record — never someone else's card.
     */
    protected static function profile(array $def, string $modelClass, array $routes): ?array
    {
        $uid = auth()->id();
        if ($uid === null) {
            return null;
        }
        try {
            $row = $modelClass::query()->where((string) ($def['user_field'] ?? 'user_id'), $uid)->first();
        } catch (\Throwable) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $lines = [];
        foreach ((array) ($def['lines'] ?? []) as $line) {
            if (! is_array($line) || empty($line['field'])) {
                continue;
            }
            try {
                $v = data_get($row, (string) $line['field']);
            } catch (\Throwable) {
                // One unresolvable path (a relation whose model
                // the registry cannot supply) drops its line,
                // never the whole card.
                continue;
            }
            if ($v === null || $v === '') {
                continue;
            }
            $lines[] = [
                'label' => (string) ($line['label'] ?? $line['field']),
                'value' => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)
                    ? Carbon::parse($v)->format('M j, Y')
                    : (string) $v,
            ];
        }

        $showUrl = isset($routes['show']) && $routes['show']
            ? str_replace('{id}', (string) data_get($row, 'id'), (string) $routes['show'])
            : null;

        return [
            'type' => 'profile',
            'title' => (string) ($def['title'] ?? 'My Profile'),
            'name' => (string) (self::safeGet($row, (string) ($def['name_field'] ?? 'user.name')) ?? ''),
            'subtitle' => (string) (self::safeGet($row, (string) ($def['subtitle_field'] ?? 'designation.name')) ?? ''),
            'lines' => $lines,
            // The card IS the viewer's own record: its link
            // goes to that record, not the slice directory —
            // the audience for a profile widget routinely holds
            // no view grant on the directory (employees on the
            // people slice), and a card link that 403s for the
            // very user it profiles is a dead link.
            'link' => $showUrl ?? ($routes['index'] ?? null),
            'url' => $showUrl,
        ];
    }

    /**
     * The signed-in user's week as named cells (a shift
     * schedule): one cell per day Mon–Sun carrying the day's
     * declared label (the shift name), empty when unscheduled.
     */
    protected static function schedule(array $def, string $modelClass, array $routes, array $manifest = []): ?array
    {
        $field = (string) ($def['date_field'] ?? 'date');
        $query = self::baseQuery($def, $modelClass, $manifest);
        if ($query === null) {
            return null;
        }
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->addDays(6)->endOfDay();
        $labelField = (string) ($def['label_field'] ?? 'name');
        $byDay = [];
        foreach ($query->whereBetween($field, [$start->toDateString(), $end->toDateTimeString()])->get() as $item) {
            $at = data_get($item, $field);
            if ($at !== null) {
                $byDay[Carbon::parse($at)->toDateString()] = (string) (data_get($item, $labelField) ?? '');
            }
        }
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $days[] = [
                'label' => $date->format('D'),
                'date' => $date->format('M j'),
                'text' => $byDay[$date->toDateString()] ?? '',
                'today' => $date->isToday(),
            ];
        }

        return [
            'type' => 'schedule',
            'title' => (string) ($def['title'] ?? 'Schedule'),
            'days' => $days,
            'link' => $routes['index'] ?? null,
        ];
    }

    /**
     * Apply the declared-stats filter semantics to a query:
     * scalar = where, array = whereIn, '!'-prefixed = whereNotIn.
     */
    /** data_get that never throws (relation resolution can). */
    protected static function safeGet(mixed $target, string $path): mixed
    {
        try {
            return data_get($target, $path);
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function applyFilters(mixed $query, array $filter): mixed
    {
        foreach ($filter as $column => $value) {
            // Operator form (layout kit step 4):
            //   field: {op: lt|lte|gt|gte|neq, value: X}
            // The value token '@today' resolves to today's date —
            // how a static manifest says "overdue".
            if (is_array($value) && isset($value['op'])) {
                $v = $value['value'] ?? null;
                if ($v === '@today') {
                    $v = now()->toDateString();
                } elseif ($v === '@now') {
                    $v = now()->toDateTimeString();
                }
                $query = match ((string) $value['op']) {
                    'lt' => $query->where((string) $column, '<', $v),
                    'lte' => $query->where((string) $column, '<=', $v),
                    'gt' => $query->where((string) $column, '>', $v),
                    'gte' => $query->where((string) $column, '>=', $v),
                    'neq' => $query->where((string) $column, '!=', $v),
                    default => $query,
                };
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

    protected static function trimNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
