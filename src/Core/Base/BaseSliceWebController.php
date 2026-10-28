<?php

namespace LaraSlice\Core\Base;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Security\Traits\AuthorizesSliceActions;
use LaraSlice\Support\DirectoryUi;
use Symfony\Component\Yaml\Yaml;

abstract class BaseSliceWebController extends Controller
{
    use AuthorizesSliceActions;

    abstract protected function getService(): IFormDataService&IListingDataService;

    abstract protected function getFormClass(): string;

    abstract protected function getFilterClass(): string;

    abstract protected function getViewPrefix(): string; // e.g. "sample_product::"

    abstract protected function getRoutePrefix(): string; // e.g. "products."

    public function index(Request $request)
    {
        $this->authorizeSlice('view');

        $filterClass = $this->getFilterClass();
        $filter = new $filterClass($request->all());

        // The data-table on index pages searches/sorts/pages client-side, so load all rows up to the cap
        if ($filter instanceof BaseFilter) {
            $filter->withLimit((int) config('laraslice.data_table.max_rows', 1000));
        }

        $formClass = $this->getFormClass();
        $createForm = new $formClass;

        // Load UI config from slice.yaml for summary cards, quick filters, etc.
        $uiConfig = $this->getSliceUiConfig();

        // Default-view enforcement (backlog D4): a slice whose
        // Heart declares default_view: matrix LANDS on its
        // matrix page (the source behaviour for attendances),
        // instead of always opening the table with the matrix
        // behind a button. An explicit ?view=… always wins,
        // which is how the matrix page's List-view link opts
        // back out.
        if (($uiConfig['directory']['default_view'] ?? 'table') === 'matrix'
            && ! $request->has('view')
            && ! empty($uiConfig['directory']['matrix'])
            && Route::has($this->resolveRouteName('matrix'))) {
            return redirect()->route(
                $this->resolveRouteName('matrix'),
                array_merge($this->routeParameters(), $request->query())
            );
        }

        $summaryCards = $uiConfig['directory']['summary_cards'] ?? [];
        $quickFilters = $uiConfig['directory']['quick_filters'] ?? [];
        $sidebarFilters = $uiConfig['directory']['sidebar_filters'] ?? [];

        // G5: declared filters must actually filter. Build the criteria
        // ONLY from handles the manifest declares (quick + sidebar),
        // including the `<handle>_from` / `<handle>_to` range inputs of
        // date_range filters, and hand them to the service via the
        // filter object. Before this, filter tabs/sidebar declarations
        // rendered (or didn't) but never narrowed a single row.
        $relationshipOptions = $this->resolveRelationshipOptions($createForm);
        $filterDefaults = [];
        if ($filter instanceof BaseFilter) {
            $criteria = [];
            $queryKeys = array_keys($request->query());
            foreach (array_merge($quickFilters, $sidebarFilters) as $declared) {
                $handle = $declared['handle'] ?? null;
                if (! is_string($handle) || $handle === '') {
                    continue;
                }
                if (array_key_exists('default', $declared) && $declared['default'] !== null && $declared['default'] !== '') {
                    $filterDefaults[$handle] = $declared['default'] === '$user' ? auth()->id() : $declared['default'];
                }
                // Relation-exists filters (fidelity): the criterion
                // targets a RELATED record (assignees via a pivot),
                // not a column on this table. Translated here into
                // the __exists:<relation>.<key> criteria key the
                // base service applies as whereHas.
                if (($declared['type'] ?? null) === 'relation_exists' && ! empty($declared['relation'])) {
                    $existsKey = '__exists:'.$declared['relation'].'.'.($declared['key'] ?? 'id');
                    $existsValue = null;
                    if ($request->filled($handle) && $request->input($handle) !== '__all__') {
                        $existsValue = $request->input($handle) === '$user' ? auth()->id() : $request->input($handle);
                    } elseif (isset($filterDefaults[$handle]) && ! in_array($handle, $queryKeys, true)) {
                        $existsValue = $filterDefaults[$handle];
                    }
                    if ($existsValue !== null) {
                        $criteria[$existsKey] = $existsValue;
                    }

                    continue;
                }
                if ($request->filled($handle)) {
                    // '__all__' is the explicit clear sentinel the
                    // filter tabs send: no criterion, and the
                    // declared default stays off for this request.
                    if ($request->input($handle) !== '__all__') {
                        // Option-level criteria (fidelity): a
                        // chosen option carrying `criteria`
                        // replaces the plain equality with its
                        // condition group (Overdue, buckets).
                        $optionConditions = DirectoryUi::optionConditions($declared, $request->input($handle), auth()->id());
                        if ($optionConditions !== null) {
                            $criteria['__conditions'] = array_merge($criteria['__conditions'] ?? [], $optionConditions);
                        } else {
                            $criteria[$handle] = $request->input($handle);
                        }
                    }
                }
                foreach (['from', 'to'] as $suffix) {
                    $rangeKey = $handle.'_'.$suffix;
                    if ($request->filled($rangeKey)) {
                        $criteria[$rangeKey] = $request->input($rangeKey);
                    }
                }
                // Declared DEFAULT (fidelity stage): applies only
                // when the request never mentioned the handle —
                // any interaction (a tab click always carries the
                // key, even empty or '__all__') suppresses it.
                if (isset($filterDefaults[$handle]) && ! in_array($handle, $queryKeys, true)) {
                    $optionConditions = DirectoryUi::optionConditions($declared, $filterDefaults[$handle], auth()->id());
                    if ($optionConditions !== null) {
                        $criteria['__conditions'] = array_merge($criteria['__conditions'] ?? [], $optionConditions);
                    } else {
                        $criteria[$handle] = $filterDefaults[$handle];
                    }
                }
                // Range defaults (progress 0-20% in the source):
                // default_from / default_to apply while neither
                // range key appears in the request.
                if ((array_key_exists('default_from', $declared) || array_key_exists('default_to', $declared))
                    && ! in_array($handle.'_from', $queryKeys, true) && ! in_array($handle.'_to', $queryKeys, true)) {
                    if (array_key_exists('default_from', $declared)) {
                        $criteria[$handle.'_from'] = $declared['default_from'];
                    }
                    if (array_key_exists('default_to', $declared)) {
                        $criteria[$handle.'_to'] = $declared['default_to'];
                    }
                }
            }
            // date_filter_on translation: a declared range carrying
            // `columns` applies to the column the request's
            // column_param names (or its default_column). The
            // generic <handle>_from/_to criteria are rewritten to
            // the chosen column for the generic filter engine.
            foreach (array_merge($quickFilters, $sidebarFilters) as $declaredRange) {
                if (! is_array($declaredRange) || empty($declaredRange['columns']) || ! is_array($declaredRange['columns'])) {
                    continue;
                }
                $rangeHandle = (string) ($declaredRange['handle'] ?? '');
                if ($rangeHandle === '' || (! isset($criteria[$rangeHandle.'_from']) && ! isset($criteria[$rangeHandle.'_to']))) {
                    continue;
                }
                $param = (string) ($declaredRange['column_param'] ?? 'date_filter_on');
                $chosen = (string) $request->query($param, '');
                if (! in_array($chosen, $declaredRange['columns'], true)) {
                    $chosen = (string) ($declaredRange['default_column'] ?? $declaredRange['columns'][0]);
                }
                foreach (['_from', '_to'] as $suffix) {
                    if (isset($criteria[$rangeHandle.$suffix])) {
                        $criteria[$chosen.$suffix] = $criteria[$rangeHandle.$suffix];
                        unset($criteria[$rangeHandle.$suffix]);
                    }
                }
            }
            // Own-scope (Body v2 access): a view-own-only grant
            // narrows the list to the viewer's own records.
            // The slice's declared access.own_scope clauses
            // decide what "own" means; a single column clause
            // stays a plain criterion, anything richer rides
            // the service's grouped-OR __own_any criterion.
            if ($this->sliceViewScope() === 'own') {
                $clauses = $this->ownScopeClauses();
                if (count($clauses) === 1 && isset($clauses[0]['field'])) {
                    $criteria[$clauses[0]['field']] = $clauses[0]['value'];
                } elseif ($clauses !== []) {
                    $criteria['__own_any'] = $clauses;
                }
            }
            if ($criteria !== []) {
                $filter->setCriteria($criteria);
            }
        }

        $pagedList = $this->getService()->getList($filter);
        $summaryData = $this->computeSummaryData($summaryCards);
        // Resolve quick filter options from distinct values
        foreach ($quickFilters as &$qf) {
            if (isset($qf['options']) && is_array($qf['options'])) {
                $qf['options'] = DirectoryUi::optionsList($qf['options']);
            }
            if (isset($qf['options_from']) && str_starts_with($qf['options_from'], 'distinct:')) {
                $field = substr($qf['options_from'], 9);
                $qf['options'] = $this->getDistinctFilterOptions($field);
            } elseif (isset($qf['options_from']) && str_starts_with($qf['options_from'], 'lookup:')) {
                $qf['options'] = $this->resolveLookupOptions($qf['options_from']);
            }
        }
        unset($qf);

        // Resolve sidebar filter select options (distinct values or
        // related-record labels) into the same value/label shape.
        foreach ($sidebarFilters as &$sf) {
            if (($sf['type'] ?? null) === 'relation_exists' && ($sf['relation'] ?? null) === 'users') {
                try {
                    $sf['options'] = DB::table('users')
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn ($u) => ['value' => $u->id, 'label' => (string) $u->name])
                        ->toArray();
                } catch (\Throwable $e) {
                    $sf['options'] = [];
                }

                continue;
            }
            $from = $sf['options_from'] ?? null;
            if (is_string($from) && str_starts_with($from, 'distinct:')) {
                $sf['options'] = $this->getDistinctFilterOptions(substr($from, 9));
            } elseif (is_string($from) && str_starts_with($from, 'lookup:')) {
                $sf['options'] = $this->resolveLookupOptions($from);
            } elseif (is_string($from) && str_starts_with($from, 'relation:')) {
                $field = substr($from, 9);
                $base = str_ends_with($field, '_id') ? substr($field, 0, -3) : $field;
                $map = $relationshipOptions[Str::camel($base).'Options'] ?? [];
                $sf['options'] = collect($map)->map(fn ($label, $value) => ['value' => $value, 'label' => (string) $label])->values()->toArray();
            } elseif (! empty($sf['options']) && is_array($sf['options'])) {
                // Declared static options: normalize the map form
                // ({value: label}) and the scalar-list form into
                // the drawer's {value, label} list shape.
                $normalized = [];
                foreach ($sf['options'] as $k => $v) {
                    if (is_array($v) && isset($v['value'])) {
                        $normalized[] = ['value' => $v['value'], 'label' => (string) ($v['label'] ?? $v['value'])];
                    } elseif (is_string($k)) {
                        $normalized[] = ['value' => $k, 'label' => (string) $v];
                    } else {
                        $normalized[] = ['value' => $v, 'label' => Str::headline((string) $v)];
                    }
                }
                $sf['options'] = $normalized;
            }
        }
        unset($sf);

        // Declared context actions (fidelity stage): the buttons a
        // source page carries beside Add — a filtered view of the
        // same list (My Tasks), a sibling route, or a plain URL.
        $directoryActions = [];
        foreach (($uiConfig['directory']['actions'] ?? []) as $action) {
            if (! is_array($action) || empty($action['label'])) {
                continue;
            }
            $kind = (string) ($action['kind'] ?? 'url');
            $href = null;
            if ($kind === 'filter') {
                $params = [];
                foreach ((array) ($action['criteria'] ?? []) as $k => $v) {
                    $params[$k] = $v === '$user' ? auth()->id() : $v;
                }
                $href = $request->url().($params === [] ? '' : '?'.http_build_query($params));
            } elseif ($kind === 'route' && ! empty($action['route'])) {
                $params = $this->routeParameters();
                foreach ((array) ($action['params_from_filter'] ?? []) as $param) {
                    if (isset($criteria[$param])) {
                        $params[$param] = $criteria[$param];
                    }
                }
                $href = Route::has((string) $action['route'])
                    ? route((string) $action['route'], $params)
                    : null;
            } elseif ($kind === 'seed' && ! empty($action['set'])) {
                $href = Route::has($this->resolveRouteName('seed_action'))
                    ? route($this->resolveRouteName('seed_action'), $this->routeParameters())
                    : null;
                if ($href !== null) {
                    $directoryActions[] = [
                        'label' => (string) $action['label'],
                        'href' => $href,
                        'icon' => isset($action['icon']) ? (string) $action['icon'] : null,
                        'variant' => (string) ($action['variant'] ?? 'outline'),
                        'method' => 'post',
                        'fields' => ['set' => (string) $action['set']],
                        'confirm' => isset($action['confirm']) ? (string) $action['confirm'] : null,
                    ];
                }

                continue;
            } elseif ($kind === 'invite') {
                $href = Route::has($this->resolveRouteName('invite'))
                    ? route($this->resolveRouteName('invite'), $this->routeParameters())
                    : null;
            } elseif (! empty($action['url'])) {
                $href = url((string) $action['url']);
            }
            if ($href === null) {
                continue;
            }
            $directoryActions[] = [
                'label' => (string) $action['label'],
                'href' => $href,
                'icon' => isset($action['icon']) ? (string) $action['icon'] : null,
                'variant' => (string) ($action['variant'] ?? 'outline'),
            ];
        }

        // The data-table component's default view must be one of
        // ITS views: a declared default naming a separate server
        // view ('matrix') must not reach the component raw (it
        // rendered every panel hidden — a blank list), and an
        // explicit ?view= naming a component view wins.
        $dirViews = array_values((array) ($uiConfig['directory']['views'] ?? ['table']));
        $componentDefaultView = in_array((string) $request->query('view'), $dirViews, true)
            ? (string) $request->query('view')
            : (in_array((string) ($uiConfig['directory']['default_view'] ?? 'table'), $dirViews, true)
                ? (string) ($uiConfig['directory']['default_view'] ?? 'table')
                : ($dirViews[0] ?? 'table'));

        // Active-filter state for the view: the raw criteria
        // minus the reserved __ keys, with the visitor's raw
        // selections restored for option-criteria filters (whose
        // criteria ride on __conditions, not the handle key) so
        // tabs/selects highlight and strip forms carry them over.
        $activeFilters = $filter instanceof BaseFilter ? $filter->getCriteria() : [];
        foreach (array_keys($activeFilters) as $activeKey) {
            if (str_starts_with((string) $activeKey, '__')) {
                unset($activeFilters[$activeKey]);
            }
        }
        foreach (array_merge($quickFilters, $sidebarFilters) as $declaredFilter) {
            $activeHandle = $declaredFilter['handle'] ?? null;
            if (! is_string($activeHandle) || $activeHandle === '') {
                continue;
            }
            if ($request->filled($activeHandle) && $request->input($activeHandle) !== '__all__') {
                $activeFilters[$activeHandle] = $request->input($activeHandle);
            } elseif (isset($filterDefaults[$activeHandle]) && ! in_array($activeHandle, $queryKeys ?? [], true)) {
                $activeFilters[$activeHandle] = $filterDefaults[$activeHandle];
            }
        }

        $viewData = array_merge(
            $relationshipOptions,
            [
                'pagedList' => $pagedList,
                'filter' => $filter,
                'createForm' => $createForm,
                'routePrefix' => $this->getRoutePrefix(),
                'routeParameters' => $this->routeParameters(),
                'parentId' => request()->route('parentId'),
                'can' => $this->sliceAbilities(),
                'summaryCards' => $summaryCards,
                'summaryData' => $summaryData,
                'presentation' => $this->presentationModes(),
                'drawerSize' => $this->presentationDrawerSize(),
                'reportUrl' => Route::has($this->resolveRouteName('report'))
                    ? route($this->resolveRouteName('report'), $this->routeParameters())
                    : null,
                'matrixUrl' => Route::has($this->resolveRouteName('matrix'))
                    ? route($this->resolveRouteName('matrix'), $this->routeParameters())
                    : null,
                'importUrl' => Route::has($this->resolveRouteName('import'))
                    ? route($this->resolveRouteName('import'), $this->routeParameters())
                    : null,
                'bulkEntryLabel' => ($be = DirectoryUi::bulkEntryFromManifest($this->ownManifest())) !== null
                    ? ($be['title'] ?? 'Bulk Entry')
                    : 'Bulk Entry',
                'bulkEntryUrl' => Route::has($this->resolveRouteName('bulk_entry'))
                    ? route($this->resolveRouteName('bulk_entry'), $this->routeParameters())
                    : null,
                // Relative path: the template is consumed by a
                // same-origin fetch, and route()'s absolute form
                // would pin APP_URL's host into the page.
                'inlineUpdateUrl' => Route::has($this->resolveRouteName('inline'))
                    ? route($this->resolveRouteName('inline'), $this->routeParameters(['id' => '__ID__']), false)
                    : null,
                'quickFilters' => $quickFilters,
                'sidebarFilters' => $sidebarFilters,
                'filterDefaults' => $filterDefaults,
                'directoryActions' => $directoryActions,
                'activeFilters' => $activeFilters,
                'uiPresentationCreate' => $uiConfig['presentation']['create'] ?? 'page',
                // Directory UI values come from the controller's own
                // manifest (G5 own-manifest-first). Generated blades
                // historically re-read the manifest from a relative
                // file path, which silently yields [] in compiled
                // views — controller values take precedence there.
                'inlineEdit' => array_values((array) ($uiConfig['directory']['inline_edit'] ?? [])),
                'density' => (string) ($uiConfig['directory']['density'] ?? 'compact'),
                'demoSeed' => (bool) ($uiConfig['directory']['demo_seed'] ?? false),
                'columnsMenu' => (bool) ($uiConfig['directory']['columns_menu'] ?? true),
                'placement' => DirectoryUi::listPlacement($uiConfig),
                'tableViews' => $dirViews,
                'defaultView' => $componentDefaultView,
            ]
        );

        return view($this->ownViewName('index'), $viewData);
    }

    /**
     * Matrix directory view (G2): rows x days-of-month grid for
     * slices declaring ui.directory.matrix, served at
     * {index-uri}/matrix?month=&year=. SliceManager registers the
     * route centrally for declared slices.
     */
    public function matrix(Request $request)
    {
        $this->authorizeSlice('view');

        $manifest = $this->ownManifest();
        $decl = $manifest['ui']['directory']['matrix'] ?? null;
        if (! is_array($decl) || empty($decl['row_key']) || empty($decl['date_field'])) {
            abort(404);
        }
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        $month = ($month >= 1 && $month <= 12) ? $month : (int) date('n');
        $year = ($year >= 1970 && $year <= 2100) ? $year : (int) date('Y');

        $filterClass = $this->getFilterClass();
        $filter = new $filterClass($request->all());
        if ($filter instanceof BaseFilter) {
            $filter->withLimit(5000);
            $dateField = (string) $decl['date_field'];
            // Merge, never replace: the declared sidebar filters
            // (employee / department / designation on attendances)
            // ride the same criteria as the list — replacing them
            // here silently unfiltered the matrix (25 Oct 2026).
            $filter->setCriteria(array_merge($filter->getCriteria(), [
                $dateField.'_from' => sprintf('%04d-%02d-01', $year, $month),
                $dateField.'_to' => date('Y-m-t', mktime(0, 0, 0, $month, 1, $year)).' 23:59:59',
            ]));
        }
        $items = $this->getService()->getList($filter)->items;

        $formClass = $this->getFormClass();
        $options = $this->resolveRelationshipOptions(new $formClass);
        // Relationship options are keyed by convention (usersOptions,
        // userOptions, ...) — find the map for the matrix row key.
        $rowKey = (string) $decl['row_key'];
        $base = str_ends_with($rowKey, '_id') ? substr($rowKey, 0, -3) : $rowKey;
        $labelMap = [];
        foreach ([$rowKey, Str::camel(Str::plural($base)).'Options', Str::camel($base).'Options', Str::studly(Str::plural($base)).'Options'] as $candidateKey) {
            if (isset($options[$candidateKey]) && is_array($options[$candidateKey])) {
                $labelMap = $options[$candidateKey];
                break;
            }
        }

        // Roster scoping: with `roster: true` the grid lists the
        // whole member map, so the declared filters must narrow
        // the MAP, not only the records — a department filter that
        // still lists every member is a filter that does nothing.
        // The row-key filter picks its member directly;
        // relation_exists filters resolve through the model's own
        // relation (its related table carries the row key as the
        // owner key — employee_details.user_id for attendances).
        if (! empty($decl['roster']) && is_array($labelMap) && $labelMap !== []) {
            // Declared population scope: the roster lists members
            // of a named table (e.g. attendances list EMPLOYEES —
            // users with an employee_details row — never every
            // account). Without it the roster is the whole label
            // map, which silently includes clients and admins.
            $population = $decl['roster_population'] ?? null;
            if (is_array($population) && ! empty($population['table']) && ! empty($population['key'])) {
                try {
                    $memberIds = DB::table((string) $population['table'])
                        ->pluck((string) $population['key'])
                        ->map(fn ($v) => (int) $v)
                        ->all();
                    $labelMap = array_intersect_key($labelMap, array_flip($memberIds));
                } catch (\Throwable $e) {
                    // An unresolvable population leaves the full map.
                }
            }
            $modelClass = $this->getService()->modelClass();
            foreach ((array) ($manifest['ui']['directory']['sidebar_filters'] ?? []) as $sf) {
                if (! is_array($sf) || empty($sf['handle'])) {
                    continue;
                }
                $value = $request->query((string) $sf['handle']);
                if ($value === null || $value === '') {
                    continue;
                }
                if ((string) $sf['handle'] === $rowKey) {
                    $labelMap = array_intersect_key($labelMap, array_flip([(int) $value]));

                    continue;
                }
                if (($sf['type'] ?? null) === 'relation_exists' && ! empty($sf['relation']) && ! empty($sf['key'])) {
                    try {
                        $probe = new $modelClass;
                        if (method_exists($probe, (string) $sf['relation'])) {
                            $relation = $probe->{(string) $sf['relation']}();
                            // The member-id column lives on the
                            // related table either way: a BelongsTo
                            // names it the owner key, a HasOne /
                            // HasMany names it the foreign key.
                            $memberColumn = $relation instanceof BelongsTo
                                ? $relation->getOwnerKeyName()
                                : $relation->getForeignKeyName();
                            $ids = DB::table($relation->getRelated()->getTable())
                                ->where((string) $sf['key'], $value)
                                ->pluck($memberColumn)
                                ->map(fn ($v) => (int) $v)
                                ->all();
                            $labelMap = array_intersect_key($labelMap, array_flip($ids));
                        }
                    } catch (\Throwable $e) {
                        // An unresolvable roster scope leaves the
                        // full roster; the records stay filtered.
                    }
                }
            }
        }

        $matrix = DirectoryUi::matrixFromManifest($manifest, $items, $month, $year, is_array($labelMap) ? $labelMap : []);
        if ($matrix === null) {
            abort(404);
        }

        $own = $this->ownViewName('matrix');
        $view = view()->exists($own) ? $own : 'laraslice::directory.matrix';

        // When the matrix is the declared default view, the
        // List-view link must carry ?view=table or index()
        // would redirect straight back here (backlog D4).
        $indexUrl = Route::has($this->resolveRouteName('index'))
            ? route($this->resolveRouteName('index'), $this->routeParameters())
            : url('/');
        if (($manifest['ui']['directory']['default_view'] ?? 'table') === 'matrix') {
            $indexUrl .= (str_contains($indexUrl, '?') ? '&' : '?').'view=table';
        }

        // Declared sidebar filters render on the matrix page too
        // (the source fuses employee / department / designation /
        // month / year into one bar) — resolve their options the
        // same way the directory does.
        $matrixFilters = [];
        foreach ((array) ($manifest['ui']['directory']['sidebar_filters'] ?? []) as $sf) {
            if (! is_array($sf) || empty($sf['handle'])) {
                continue;
            }
            $from = $sf['options_from'] ?? null;
            $opts = [];
            if (is_string($from) && str_starts_with($from, 'lookup:')) {
                $opts = $this->resolveLookupOptions($from);
            } elseif (is_string($from) && str_starts_with($from, 'distinct:')) {
                $opts = $this->getDistinctFilterOptions(substr($from, 9));
            } elseif (! empty($sf['options']) && is_array($sf['options'])) {
                foreach ($sf['options'] as $k => $v) {
                    $opts[] = is_array($v) && isset($v['value'])
                        ? ['value' => $v['value'], 'label' => (string) ($v['label'] ?? $v['value'])]
                        : ['value' => is_string($k) ? $k : $v, 'label' => (string) $v];
                }
            }
            $matrixFilters[] = [
                'handle' => (string) $sf['handle'],
                'label' => (string) ($sf['label'] ?? $sf['handle']),
                'options' => $opts,
                'active' => $request->query((string) $sf['handle']),
            ];
        }
        $be = DirectoryUi::bulkEntryFromManifest($this->ownManifest());
        $bulkEntryUrl = $be !== null && Route::has($this->resolveRouteName('bulk_entry'))
            ? route($this->resolveRouteName('bulk_entry'), $this->routeParameters())
            : null;
        $importUrl = Route::has($this->resolveRouteName('import'))
            ? route($this->resolveRouteName('import'), $this->routeParameters())
            : null;

        return view($view, [
            'matrix' => $matrix,
            'indexUrl' => $indexUrl,
            'routePrefix' => $this->getRoutePrefix(),
            'matrixFilters' => $matrixFilters,
            'bulkEntryUrl' => $bulkEntryUrl,
            'bulkEntryLabel' => $be['title'] ?? 'Bulk Entry',
            'importUrl' => $importUrl,
        ]);
    }

    /**
     * Conversation surface (25 Oct 2026): a two-pane 1:1 chat for
     * slices declaring ui.conversation — the port of the source
     * app's Messages page, which no directory/table pattern can
     * express. The slice's model is the conversation (fields
     * user_one / user_two + a replies has-many whose rows carry
     * reply + user_id); relation names are conventional and may
     * be overridden in the declaration:
     *   ui:
     *     conversation:
     *       title: Messages            # optional
     *       one: userOne               # belongsTo relations
     *       two: userTwo
     *       replies: replies           # hasMany relation
     * Routes (SliceManager, declared): GET {prefix}/chat,
     * POST {prefix}/chat (start), POST {prefix}/chat/{id}/reply.
     */
    public function conversationPage(Request $request)
    {
        $this->authorizeSlice('view');
        $chat = $this->conversationViewModel($request);
        if ($chat === null) {
            abort(404);
        }
        $own = $this->ownViewName('conversation');
        $view = view()->exists($own) ? $own : 'laraslice::directory.conversation';

        return view($view, [
            'chat' => $chat,
            'routePrefix' => $this->getRoutePrefix(),
        ]);
    }

    public function conversationStart(Request $request)
    {
        $this->authorizeSlice('create');
        $decl = $this->conversationDeclaration();
        if ($decl === null) {
            abort(404);
        }
        $me = (int) auth()->id();
        $other = (int) $request->input('user_id');
        if ($other <= 0 || $other === $me) {
            abort(422);
        }
        $userClass = app(SliceManager::class)->modelClass('User');
        if ($userClass === null || ! $userClass::query()->whereKey($other)->exists()) {
            abort(422);
        }
        $modelClass = $this->getService()->modelClass();
        $conversation = $modelClass::query()
            ->where(fn ($q) => $q->where(['user_one' => $me, 'user_two' => $other]))
            ->orWhere(fn ($q) => $q->where(['user_one' => $other, 'user_two' => $me]))
            ->first()
            ?? $modelClass::query()->create(['user_one' => $me, 'user_two' => $other]);

        return redirect()->route($this->resolveRouteName('chat'), array_merge(
            $this->routeParameters(),
            ['c' => $conversation->getKey()]
        ));
    }

    public function conversationReply(Request $request, $id)
    {
        $this->authorizeSlice('create');
        $decl = $this->conversationDeclaration();
        if ($decl === null) {
            abort(404);
        }
        $request->validate(['reply' => ['required', 'string', 'max:5000']]);
        $me = (int) auth()->id();
        $modelClass = $this->getService()->modelClass();
        $conversation = $modelClass::query()->findOrFail($id);
        if ((int) $conversation->user_one !== $me && (int) $conversation->user_two !== $me) {
            abort(403);
        }
        $repliesRelation = (string) ($decl['replies'] ?? 'replies');
        $conversation->{$repliesRelation}()->create([
            'reply' => (string) $request->input('reply'),
            'user_id' => $me,
        ]);

        return redirect()->route($this->resolveRouteName('chat'), array_merge(
            $this->routeParameters(),
            ['c' => $conversation->getKey()]
        ));
    }

    private function conversationDeclaration(): ?array
    {
        $decl = $this->ownManifest()['ui']['conversation'] ?? null;

        return is_array($decl) ? $decl : null;
    }

    private function conversationViewModel(Request $request): ?array
    {
        $decl = $this->conversationDeclaration();
        if ($decl === null) {
            return null;
        }
        $me = (int) auth()->id();
        $one = (string) ($decl['one'] ?? 'userOne');
        $two = (string) ($decl['two'] ?? 'userTwo');
        $repliesRel = (string) ($decl['replies'] ?? 'replies');
        $modelClass = $this->getService()->modelClass();

        $conversations = $modelClass::query()
            ->where(fn ($q) => $q->where('user_one', $me)->orWhere('user_two', $me))
            ->with([$one, $two, $repliesRel])
            ->get()
            ->map(function ($c) use ($me, $one, $two, $repliesRel) {
                $otherUser = (int) $c->user_one === $me ? $c->{$two} : $c->{$one};
                $replies = $c->{$repliesRel}->sortBy('created_at')->values();
                $last = $replies->last();

                return [
                    'id' => $c->getKey(),
                    'other' => $otherUser ? [
                        'id' => $otherUser->getKey(),
                        'name' => (string) ($otherUser->name ?? 'User'),
                    ] : ['id' => null, 'name' => 'User'],
                    'preview' => $last ? (string) $last->reply : '',
                    'last_at' => $last ? $last->created_at : $c->created_at,
                    'replies' => $replies->map(fn ($r) => [
                        'id' => $r->getKey(),
                        'body' => (string) $r->reply,
                        'mine' => (int) $r->user_id === $me,
                        'at' => $r->created_at,
                    ])->all(),
                ];
            })
            ->sortByDesc(fn ($c) => $c['last_at'])
            ->values();

        // No implicit selection: with nothing chosen the surface
        // shows its empty state, exactly like the source page.
        $selectedId = (int) $request->query('c', 0);
        $selected = $selectedId > 0 ? $conversations->firstWhere('id', $selectedId) : null;

        $userClass = app(SliceManager::class)->modelClass('User');
        $users = $userClass === null ? [] : $userClass::query()
            ->whereKeyNot($me)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->getKey(), 'name' => (string) $u->name])
            ->all();

        $route = fn (string $action, array $params = []) => Route::has($this->resolveRouteName($action))
            ? route($this->resolveRouteName($action), array_merge($this->routeParameters(), $params))
            : null;

        return [
            'title' => (string) ($decl['title'] ?? 'Messages'),
            'conversations' => $conversations->all(),
            'selected' => $selected,
            'users' => $users,
            'urls' => [
                'page' => $route('chat'),
                'start' => $route('chat.start'),
                'reply' => $selected ? $route('chat.reply', ['id' => $selected['id']]) : null,
            ],
        ];
    }

    /**
     * Report view (G2): grouped aggregates for slices declaring
     * ui.directory.report, served at {index-uri}/report with an
     * optional month/year period when the declaration names a
     * date_field. SliceManager registers the route centrally.
     * Additional NAMED reports live under ui.directory.reports[]
     * ({handle, ...}) at {index-uri}/report/{handle}; a named
     * report may declare `compare` (multi-source money streams
     * aligned by month, with a signed Net column) instead of
     * group_by/aggregates.
     */
    public function report(Request $request, ?string $reportHandle = null)
    {
        $this->authorizeSlice('view');

        $manifest = $this->ownManifest();
        $decl = null;
        if ($reportHandle !== null) {
            foreach (($manifest['ui']['directory']['reports'] ?? []) as $candidate) {
                if (is_array($candidate) && ($candidate['handle'] ?? null) === $reportHandle) {
                    $decl = $candidate;
                    break;
                }
            }
            if (! is_array($decl)) {
                abort(404);
            }
        } else {
            $decl = $manifest['ui']['directory']['report'] ?? null;
            if (! is_array($decl)) {
                abort(404);
            }
        }

        if (! empty($decl['compare']) && is_array($decl['compare'])) {
            $sources = [];
            foreach ($decl['compare'] as $source) {
                if (! is_array($source) || empty($source['table'])) {
                    continue;
                }
                $dateField = (string) ($source['date_field'] ?? 'created_at');
                $amountField = (string) ($source['amount'] ?? 'amount');
                $rows = DB::table((string) $source['table'])
                    ->select([$dateField, $amountField])
                    ->get()
                    ->map(fn ($r) => ['date' => $r->{$dateField} ?? null, 'amount' => $r->{$amountField} ?? null])
                    ->all();
                $sources[] = [
                    'label' => (string) ($source['label'] ?? $source['table']),
                    'sign' => (int) ($source['sign'] ?? 1),
                    'rows' => $rows,
                ];
            }
            $report = DirectoryUi::compareFromManifest($decl, $sources);
            if ($report === null) {
                abort(404);
            }
            $own = $this->ownViewName('report');
            $view = view()->exists($own) ? $own : 'laraslice::directory.report';

            return view($view, [
                'report' => $report,
                'hasPeriod' => false,
                'month' => (int) $request->query('month', (int) date('n')),
                'year' => (int) $request->query('year', (int) date('Y')),
                'indexUrl' => Route::has($this->resolveRouteName('index'))
                    ? route($this->resolveRouteName('index'), $this->routeParameters())
                    : url('/'),
                'routePrefix' => $this->getRoutePrefix(),
            ]);
        }

        if (empty($decl['group_by'])) {
            abort(404);
        }

        $filterClass = $this->getFilterClass();
        $filter = new $filterClass($request->all());
        if ($filter instanceof BaseFilter) {
            $filter->withLimit(5000);
            if (! empty($decl['date_field']) && $request->filled('month') && $request->filled('year')) {
                $month = max(1, min(12, (int) $request->query('month')));
                $year = max(1970, min(2100, (int) $request->query('year')));
                $dateField = (string) $decl['date_field'];
                $filter->setCriteria([
                    $dateField.'_from' => sprintf('%04d-%02d-01', $year, $month),
                    $dateField.'_to' => date('Y-m-t', mktime(0, 0, 0, $month, 1, $year)).' 23:59:59',
                ]);
            }
        }
        $items = $this->getService()->getList($filter)->items;

        $formClass = $this->getFormClass();
        $options = $this->resolveRelationshipOptions(new $formClass);
        $groupBy = (string) $decl['group_by'];
        $base = str_ends_with($groupBy, '_id') ? substr($groupBy, 0, -3) : $groupBy;
        $labelMap = [];
        foreach ([$groupBy, Str::camel(Str::plural($base)).'Options', Str::camel($base).'Options', Str::studly(Str::plural($base)).'Options'] as $candidateKey) {
            if (isset($options[$candidateKey]) && is_array($options[$candidateKey])) {
                $labelMap = $options[$candidateKey];
                break;
            }
        }

        $report = DirectoryUi::reportFromManifest($manifest, $items, $labelMap);
        if ($report === null) {
            abort(404);
        }

        $own = $this->ownViewName('report');
        $view = view()->exists($own) ? $own : 'laraslice::directory.report';

        return view($view, [
            'report' => $report,
            'hasPeriod' => ! empty($decl['date_field']),
            'month' => (int) $request->query('month', (int) date('n')),
            'year' => (int) $request->query('year', (int) date('Y')),
            'indexUrl' => Route::has($this->resolveRouteName('index'))
                ? route($this->resolveRouteName('index'), $this->routeParameters())
                : url('/'),
            'routePrefix' => $this->getRoutePrefix(),
        ]);
    }

    /**
     * Bulk entry (G8): one-date grid for slices declaring
     * ui.directory.bulk_entry — rows are the row key's relationship
     * options, columns the declared fields, existing records for
     * the date prefilled. The standard pattern behind screens like
     * Mark Attendance. SliceManager registers the routes centrally.
     */
    public function bulkEntry(Request $request)
    {
        $this->authorizeSlice('view');

        $manifest = $this->ownManifest();
        $decl = DirectoryUi::bulkEntryFromManifest($manifest);
        if ($decl === null) {
            abort(404);
        }
        $date = (string) $request->query('date', date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            $date = date('Y-m-d');
        }

        $formClass = $this->getFormClass();
        $options = $this->resolveRelationshipOptions(new $formClass);
        $rowKey = $decl['row_key'];
        $base = str_ends_with($rowKey, '_id') ? substr($rowKey, 0, -3) : $rowKey;
        $labelMap = [];
        foreach ([$rowKey, Str::camel(Str::plural($base)).'Options', Str::camel($base).'Options', Str::studly(Str::plural($base)).'Options'] as $candidateKey) {
            if (isset($options[$candidateKey]) && is_array($options[$candidateKey])) {
                $labelMap = $options[$candidateKey];
                break;
            }
        }

        $existing = [];
        $filterClass = $this->getFilterClass();
        $filter = new $filterClass([]);
        if ($filter instanceof BaseFilter) {
            $filter->withLimit(5000);
            $filter->setCriteria([
                $decl['date_field'].'_from' => $date.' 00:00:00',
                $decl['date_field'].'_to' => $date.' 23:59:59',
            ]);
            foreach ($this->getService()->getList($filter)->items as $item) {
                $attrs = method_exists($item, 'getAttributes') ? $item->getAttributes() : (is_array($item) ? $item : get_object_vars($item));
                $k = $attrs[$rowKey] ?? null;
                if ($k !== null && ! isset($existing[$k])) {
                    $existing[$k] = $attrs;
                }
            }
        }

        $rows = [];
        foreach ($labelMap as $key => $label) {
            $values = [];
            foreach ($decl['fields'] as $f) {
                $raw = $existing[$key][$f['handle']] ?? null;
                $values[$f['handle']] = $raw === null || $raw === ''
                    ? ''
                    : (($f['input'] === 'time' || $f['input'] === 'datetime') ? date('H:i', strtotime((string) $raw)) : (string) $raw);
            }
            $rows[] = ['key' => $key, 'label' => (string) $label, 'values' => $values, 'exists' => isset($existing[$key])];
        }

        $own = $this->ownViewName('bulk-entry');
        $view = view()->exists($own) ? $own : 'laraslice::directory.bulk-entry';

        return view($view, [
            'bulk' => $decl,
            'rows' => $rows,
            'date' => $date,
            'storeUrl' => Route::has($this->resolveRouteName('bulk_entry_store'))
                ? route($this->resolveRouteName('bulk_entry_store'), $this->routeParameters())
                : url()->current(),
            'indexUrl' => Route::has($this->resolveRouteName('index'))
                ? route($this->resolveRouteName('index'), $this->routeParameters())
                : url('/'),
            'matrixUrl' => Route::has($this->resolveRouteName('matrix'))
                ? route($this->resolveRouteName('matrix'), $this->routeParameters())
                : null,
            'routePrefix' => $this->getRoutePrefix(),
        ]);
    }

    /**
     * Save a bulk-entry grid: one record per row for the date.
     * Rows whose date field is left empty are skipped (an existing
     * record is left untouched — clearing is a delete, done from
     * the record itself). Datetime fields are composed from the
     * entry date + the submitted time.
     */
    public function bulkEntryStore(Request $request)
    {
        $this->authorizeSlice('create');

        $manifest = $this->ownManifest();
        $decl = DirectoryUi::bulkEntryFromManifest($manifest);
        if ($decl === null) {
            abort(404);
        }
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'entries' => ['array'],
            'entries.*' => ['array'],
        ]);

        $service = $this->getService();
        $ref = new \ReflectionMethod($service, 'getModelClass');
        $ref->setAccessible(true);
        $modelClass = $ref->invoke($service);
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            abort(500, 'Bulk entry: model class not resolvable.');
        }

        // One save pass for one date; range mode calls it per date.
        $saveForDate = function (string $date) use ($data, $decl, $modelClass): int {
            $saved = 0;
            foreach (($data['entries'] ?? []) as $rowKeyValue => $submitted) {
                if (! is_array($submitted)) {
                    continue;
                }
                $values = [];
                $hasDateValue = false;
                foreach ($decl['fields'] as $f) {
                    $v = $submitted[$f['handle']] ?? null;
                    if ($v === null || $v === '') {
                        continue;
                    }
                    if ($f['input'] === 'time' || $f['input'] === 'datetime' || $f['handle'] === $decl['date_field']) {
                        $values[$f['handle']] = $date.' '.(preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v.':00' : $v);
                        if ($f['handle'] === $decl['date_field']) {
                            $hasDateValue = true;
                        }
                    } else {
                        $values[$f['handle']] = $v;
                    }
                }
                if (! $hasDateValue || $values === []) {
                    continue;
                }
                $model = $modelClass::query()
                    ->where($decl['row_key'], $rowKeyValue)
                    ->whereDate($decl['date_field'], $date)
                    ->first() ?? new $modelClass;
                $model->forceFill(array_merge($values, [$decl['row_key'] => $rowKeyValue]));
                $model->save();
                $saved++;
            }

            return $saved;
        };

        $rangeMode = ! empty($decl['range']) && ! empty($data['from']) && ! empty($data['to']);
        if ($rangeMode) {
            // Holiday dates in range (declared slice + date field)
            $holidayDates = [];
            if (! empty($decl['holidays'])) {
                try {
                    $holidayClass = app(SliceManager::class)->modelClass($decl['holidays']['slice']);
                    if (is_string($holidayClass) && class_exists($holidayClass)) {
                        $holidayDates = $holidayClass::query()
                            ->whereDate($decl['holidays']['date_field'], '>=', $data['from'])
                            ->whereDate($decl['holidays']['date_field'], '<=', $data['to'])
                            ->pluck($decl['holidays']['date_field'])
                            ->map(fn ($d) => date('Y-m-d', strtotime((string) $d)))
                            ->all();
                    }
                } catch (\Throwable $e) {
                    $holidayDates = [];
                }
            }

            $saved = 0;
            $days = 0;
            $skippedHolidays = 0;
            $skippedWeekends = 0;
            $cursor = new \DateTimeImmutable($data['from']);
            $last = new \DateTimeImmutable($data['to']);
            while ($cursor <= $last) {
                $day = $cursor->format('Y-m-d');
                if (in_array($day, $holidayDates, true)) {
                    $skippedHolidays++;
                } elseif (! empty($decl['skip_weekends']) && (int) $cursor->format('N') >= 6) {
                    $skippedWeekends++;
                } else {
                    $saved += $saveForDate($day);
                    $days++;
                }
                $cursor = $cursor->modify('+1 day');
            }

            $notes = [];
            if ($skippedHolidays > 0) {
                $notes[] = "skipped {$skippedHolidays} holiday date(s)";
            }
            if ($skippedWeekends > 0) {
                $notes[] = "skipped {$skippedWeekends} weekend date(s)";
            }
            $message = "Saved {$saved} record(s) across {$days} date(s)".($notes === [] ? '.' : ' ('.implode(', ', $notes).').');

            return redirect()
                ->route($this->resolveRouteName('bulk_entry'), $this->routeParameters(['date' => $data['from']]))
                ->with('success', $message);
        }

        if (empty($data['date'])) {
            throw ValidationException::withMessages([
                'date' => 'A date (or a from/to range) is required.',
            ]);
        }
        $date = $data['date'];
        $saved = $saveForDate($date);

        return redirect()
            ->route($this->resolveRouteName('bulk_entry'), $this->routeParameters(['date' => $date]))
            ->with('success', "Saved {$saved} record(s) for {$date}.");
    }

    /**
     * Timer state for the show page (G8): the declared timer's
     * totals + the current user's active session on this record
     * (or anywhere, so the page can say why Start is refused).
     */
    protected function timerState(array $manifest, mixed $record): ?array
    {
        $decl = DirectoryUi::timerFromManifest($manifest);
        if ($decl === null || ! is_object($record) || ! method_exists($record, $decl['relation'])) {
            return null;
        }
        $userId = auth()->id();
        $relation = $record->{$decl['relation']}();
        $logClass = get_class($relation->getRelated());
        $base = $logClass::query()->where($decl['user_field'], $userId);

        $activeHere = (clone $base)
            ->where($relation->getForeignKeyName(), $record->getKey())
            ->whereNull($decl['end_field'])
            ->first();
        $activeAnywhere = $activeHere ?? (clone $base)->whereNull($decl['end_field'])->first();

        $totalMinutes = 0;
        foreach ($record->{$decl['relation']}()->whereNotNull($decl['end_field'])->get() as $log) {
            $start = strtotime((string) $log->{$decl['start_field']});
            $end = strtotime((string) $log->{$decl['end_field']});
            if ($start !== false && $end !== false && $end >= $start) {
                $totalMinutes += (int) (($end - $start) / 60);
            }
        }

        return [
            'decl' => $decl,
            'activeHere' => $activeHere,
            'activeElsewhere' => $activeAnywhere && ! $activeHere,
            'totalMinutes' => $totalMinutes,
            'startUrl' => Route::has($this->resolveRouteName('timer_start'))
                ? route($this->resolveRouteName('timer_start'), $this->routeParameters(['id' => $record->getKey()]))
                : null,
            'stopUrl' => Route::has($this->resolveRouteName('timer_stop'))
                ? route($this->resolveRouteName('timer_stop'), $this->routeParameters(['id' => $record->getKey()]))
                : null,
        ];
    }

    /**
     * Start the declared timer on a record (G8). One active timer
     * per user across the whole log table — the source
     * application's rule.
     */
    /**
     * Declared CSV import (P13): the form explains the expected
     * columns (the declared map) and offers the template; the
     * store parses the file, maps headers case-insensitively,
     * and creates rows — or updates them in place when the
     * declaration names a `key` field (the Mind's dedupe rule).
     * Bad rows are reported, never silently dropped.
     */
    public function importForm(Request $request)
    {
        $this->authorizeSlice('view');

        $decl = DirectoryUi::importFromManifest($this->ownManifest());
        if ($decl === null) {
            abort(404);
        }

        return view('laraslice::directory.import', [
            'sliceName' => class_basename($this),
            'decl' => $decl,
            'report' => session('import_report'),
            'storeUrl' => route($this->resolveRouteName('import_store'), $this->routeParameters()),
            'templateUrl' => route($this->resolveRouteName('import_template'), $this->routeParameters()),
            'indexUrl' => route($this->resolveRouteName('index'), $this->routeParameters()),
            'routePrefix' => $this->getRoutePrefix(),
        ]);
    }

    public function importTemplate(Request $request)
    {
        $this->authorizeSlice('view');

        $decl = DirectoryUi::importFromManifest($this->ownManifest());
        if ($decl === null) {
            abort(404);
        }

        $headers = array_keys($decl['columns']);

        return response()->streamDownload(function () use ($headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            fclose($out);
        }, 'import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function importStore(Request $request)
    {
        $this->authorizeSlice('create');

        $manifest = $this->ownManifest();
        $decl = DirectoryUi::importFromManifest($manifest);
        if ($decl === null) {
            abort(404);
        }
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $service = $this->getService();
        $ref = new \ReflectionMethod($service, 'getModelClass');
        $ref->setAccessible(true);
        $modelClass = $ref->invoke($service);
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            abort(500, 'Import: model class not resolvable.');
        }

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headerRow = $handle ? fgetcsv($handle) : false;
        if ($headerRow === false) {
            return back()->with('import_report', ['created' => 0, 'updated' => 0, 'errors' => ['The file is empty.']]);
        }
        $positions = [];
        foreach ($headerRow as $i => $h) {
            $positions[strtolower(trim((string) $h))] = $i;
        }
        $map = [];
        foreach ($decl['columns'] as $header => $field) {
            $pos = $positions[strtolower(trim($header))] ?? null;
            if ($pos !== null) {
                $map[$field] = $pos;
            }
        }
        if ($map === []) {
            fclose($handle);

            return back()->with('import_report', ['created' => 0, 'updated' => 0, 'errors' => ['None of the expected columns were found in the file header.']]);
        }

        $created = 0;
        $updated = 0;
        $errors = [];
        $line = 1;
        $probe = new $modelClass;
        $schema = $probe->getConnection()->getSchemaBuilder();
        $validFields = array_filter(array_values($decl['columns']), fn ($f) => $schema->hasColumn($probe->getTable(), $f));
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $attrs = [];
            foreach ($map as $field => $pos) {
                if (! in_array($field, $validFields, true)) {
                    continue;
                }
                $value = isset($row[$pos]) ? trim((string) $row[$pos]) : null;
                $attrs[$field] = $value === '' ? null : $value;
            }
            if ($attrs === []) {
                continue;
            }
            try {
                if ($decl['key'] !== null && ! empty($attrs[$decl['key']])) {
                    $existing = $modelClass::query()->where($decl['key'], $attrs[$decl['key']])->first();
                    if ($existing !== null) {
                        $existing->fill($attrs)->save();
                        $updated++;

                        continue;
                    }
                }
                (new $modelClass)->fill($attrs)->save();
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: ".$e->getMessage();
            }
        }
        fclose($handle);

        return redirect()->route($this->resolveRouteName('import'), $this->routeParameters())
            ->with('import_report', ['created' => $created, 'updated' => $updated, 'errors' => $errors]);
    }

    /**
     * Reference-data action (P15): POST a declared reference set
     * (ui.directory.reference_sets.<set> = {file, key}) — rows
     * from the slice's Resources/data file are upserted by key.
     * Idempotent: running "Mark Default Holidays" twice changes
     * nothing the second time.
     */
    public function seedAction(Request $request)
    {
        $this->authorizeSlice('create');

        $manifest = $this->ownManifest();
        $setName = (string) $request->input('set', '');
        $decl = $manifest['ui']['directory']['reference_sets'][$setName] ?? null;
        if (! is_array($decl) || empty($decl['file']) || empty($decl['key'])) {
            abort(404);
        }
        $ref = new \ReflectionClass($this);
        $dataFile = dirname((string) $ref->getFileName(), 2).'/Resources/data/'.basename((string) $decl['file']);
        if (! is_file($dataFile)) {
            abort(404);
        }
        $rows = Yaml::parseFile($dataFile);
        if (! is_array($rows)) {
            abort(422);
        }
        $service = $this->getService();
        $mref = new \ReflectionMethod($service, 'getModelClass');
        $mref->setAccessible(true);
        $modelClass = $mref->invoke($service);
        $count = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$decl['key']])) {
                continue;
            }
            $modelClass::query()->updateOrCreate([$decl['key'] => $row[$decl['key']]], $row);
            $count++;
        }

        return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())
            ->with('success', "Reference data applied: {$count} record(s).");
    }

    /**
     * Invite / provisioning (P14): the page lists records whose
     * declared link field is empty; provisioning creates the
     * user, assigns the declared role club when it exists, links
     * the record, and sends the activation (password-reset)
     * email so the person sets their own password.
     */
    public function inviteForm(Request $request)
    {
        $this->authorizeSlice('create');

        $decl = DirectoryUi::inviteFromManifest($this->ownManifest());
        if ($decl === null) {
            abort(404);
        }
        $service = $this->getService();
        $mref = new \ReflectionMethod($service, 'getModelClass');
        $mref->setAccessible(true);
        $modelClass = $mref->invoke($service);
        $records = $modelClass::query()->whereNull($decl['link'])->limit(100)->get()
            ->map(fn ($m) => ['id' => $m->getKey(), 'name' => (string) ($m->{$decl['name_field']} ?? '#'.$m->getKey())])
            ->all();

        return view('laraslice::directory.invite', [
            'records' => $records,
            'decl' => $decl,
            'storeUrl' => route($this->resolveRouteName('invite_store'), $this->routeParameters()),
            'indexUrl' => route($this->resolveRouteName('index'), $this->routeParameters()),
            'report' => session('invite_report'),
            'routePrefix' => $this->getRoutePrefix(),
        ]);
    }

    public function inviteStore(Request $request)
    {
        $this->authorizeSlice('create');

        $decl = DirectoryUi::inviteFromManifest($this->ownManifest());
        if ($decl === null) {
            abort(404);
        }
        $data = $request->validate([
            'record_id' => ['required'],
            'email' => ['required', 'email'],
        ]);
        $service = $this->getService();
        $mref = new \ReflectionMethod($service, 'getModelClass');
        $mref->setAccessible(true);
        $modelClass = $mref->invoke($service);
        $record = $modelClass::query()->findOrFail($data['record_id']);
        if (! empty($record->{$decl['link']})) {
            return back()->with('invite_report', ['error' => 'That record is already linked to a user.']);
        }
        $userClass = app(SliceManager::class)->modelClass('User');
        if ($userClass === null || ! class_exists($userClass)) {
            abort(500, 'Invite: user model not resolvable.');
        }
        if ($userClass::query()->where('email', $data['email'])->exists()) {
            return back()->with('invite_report', ['error' => 'A user with that email already exists.']);
        }
        $user = (new $userClass)->forceFill([
            'name' => (string) ($record->{$decl['name_field']} ?? 'Invited user'),
            'email' => $data['email'],
            'password' => Hash::make(Str::random(40)),
        ]);
        $user->save();
        if ($decl['role'] !== null) {
            try {
                $roleId = DB::table('roles')->where('name', $decl['role'])->value('id')
                    ?? DB::table('roles')->where('display_name', $decl['role'])->value('id');
                if ($roleId) {
                    DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->getKey()]);
                }
            } catch (\Throwable) {
                // Role assignment is best-effort; the account exists.
            }
        }
        $record->forceFill([$decl['link'] => $user->getKey()])->save();
        try {
            Password::broker()->sendResetLink(['email' => $data['email']]);
        } catch (\Throwable) {
            // Mail transport may be unconfigured; the link still stands.
        }

        return redirect()->route($this->resolveRouteName('invite'), $this->routeParameters())
            ->with('invite_report', ['success' => "Invited {$data['email']} — activation email sent."]);
    }

    public function timerStart(Request $request, string|int $id)
    {
        $this->authorizeSlice('create');
        $decl = DirectoryUi::timerFromManifest($this->ownManifest());
        $record = $this->getService()->findModel($id);
        if ($decl === null || $record === null || ! method_exists($record, $decl['relation'])) {
            abort(404);
        }
        $relation = $record->{$decl['relation']}();
        $logClass = get_class($relation->getRelated());
        $active = $logClass::query()
            ->where($decl['user_field'], auth()->id())
            ->whereNull($decl['end_field'])
            ->first();
        if ($active !== null) {
            return back()->with('error', 'Timer is already running for a task.');
        }
        $relation->create([
            $decl['user_field'] => auth()->id(),
            $decl['start_field'] => now(),
        ]);

        return back()->with('success', 'Timer started successfully.');
    }

    /** Stop the current user's active timer on a record (G8). */
    public function timerStop(Request $request, string|int $id)
    {
        $this->authorizeSlice('create');
        $decl = DirectoryUi::timerFromManifest($this->ownManifest());
        $record = $this->getService()->findModel($id);
        if ($decl === null || $record === null || ! method_exists($record, $decl['relation'])) {
            abort(404);
        }
        $log = $record->{$decl['relation']}()
            ->where($decl['user_field'], auth()->id())
            ->whereNull($decl['end_field'])
            ->first();
        if ($log === null) {
            return back()->with('error', 'No running timer for this record.');
        }
        $end = now();
        $log->{$decl['end_field']} = $end;
        // total_hours / total_minutes are the log vocabulary of
        // the source domain — fill them when the table has them.
        // The start value may be an uncast string on the model.
        $startedAt = Carbon::parse($log->{$decl['start_field']});
        $schema = $log->getConnection()->getSchemaBuilder();
        if ($schema->hasColumn($log->getTable(), 'total_minutes')) {
            $log->total_minutes = (string) (int) $startedAt->diffInMinutes($end);
        }
        if ($schema->hasColumn($log->getTable(), 'total_hours')) {
            $log->total_hours = (string) (int) $startedAt->diffInHours($end);
        }
        $log->save();

        return back()->with('success', 'Timer stopped successfully.');
    }

    /**
     * Clock punch (step-4 L4-residue, paired with the D2 clock
     * widget): POST {prefix}/punch, registered when the slice's
     * dashboard declares a clock widget with punch: true.
     * Toggles the signed-in user's attendance for today — an
     * open row is clocked out; otherwise a new row is clocked
     * in. Field names come from the widget declaration itself.
     */
    public function punch(Request $request)
    {
        $manifest = $this->ownManifest();
        $def = null;
        foreach (($manifest['ui']['dashboard']['widgets'] ?? []) as $widget) {
            if (is_array($widget) && ($widget['type'] ?? '') === 'clock' && ! empty($widget['punch'])) {
                $def = $widget;
                break;
            }
        }
        if ($def === null) {
            abort(404);
        }
        $userField = (string) ($def['user_field'] ?? 'user_id');
        $inField = (string) ($def['in_field'] ?? 'clock_in_time');
        $outField = (string) ($def['out_field'] ?? 'clock_out_time');
        $modelClass = $this->getService()->modelClass();
        $uid = auth()->id();

        $open = $modelClass::query()
            ->where($userField, $uid)
            ->whereDate($inField, Carbon::today())
            ->whereNull($outField)
            ->orderBy($inField)
            ->first();
        if ($open !== null) {
            $this->authorizeSlice('update');
            $open->{$outField} = now();
            $schema = $open->getConnection()->getSchemaBuilder();
            if ($schema->hasColumn($open->getTable(), 'clock_out_ip')) {
                $open->clock_out_ip = $request->ip();
            }
            $open->save();

            return back()->with('success', 'Clocked out at '.now()->format('H:i').'.');
        }

        $this->authorizeSlice('create');
        $record = new $modelClass;
        $record->{$userField} = $uid;
        $record->{$inField} = now();
        $schema = $record->getConnection()->getSchemaBuilder();
        if ($schema->hasColumn($record->getTable(), 'company_id') && $record->company_id === null) {
            $record->company_id = auth()->user()->company_id ?? 1;
        }
        if ($schema->hasColumn($record->getTable(), 'clock_in_ip')) {
            $record->clock_in_ip = $request->ip();
        }
        $record->save();

        return back()->with('success', 'Clocked in at '.now()->format('H:i').'.');
    }

    public function create()
    {
        $this->authorizeSlice('create');

        $formClass = $this->getFormClass();
        $form = new $formClass;

        $viewData = array_merge(
            $this->resolveRelationshipOptions($form),
            $this->getFormViewData($form, true),
            $this->overlayLayoutData(request()),
            [
                'form' => $form,
                'isNew' => true,
                'routePrefix' => $this->getRoutePrefix(),
                'routeParameters' => $this->routeParameters(),
                'parentId' => request()->route('parentId'),
            ]
        );

        return view($this->ownViewName('form'), $viewData);
    }

    protected function getFormViewData(mixed $form, bool $isNew): array
    {
        return [];
    }

    protected function resolveRelationshipOptions(mixed $form): array
    {
        $options = [];

        try {
            $props = is_object($form) ? get_object_vars($form) : (is_array($form) ? $form : []);

            // Manifest-declared relations on fields that break
            // the *_id naming convention (a source column like
            // award_to pointing at users): synthesize the
            // relation's conventional prop so its options resolve
            // under the usual keys (usersOptions, ...) and blades
            // can render a picker for the field.
            try {
                foreach (($this->ownManifest()['fields'] ?? []) as $fieldName => $fieldDef) {
                    $relation = is_array($fieldDef) ? ($fieldDef['relation'] ?? null) : null;
                    if (is_string($relation) && $relation !== '' && ! str_ends_with((string) $fieldName, '_id')) {
                        $pseudo = $relation.'_id';
                        if (! array_key_exists($pseudo, $props)) {
                            $props[$pseudo] = is_object($form) ? ($form->{$fieldName} ?? null) : ($form[$fieldName] ?? null);
                        }
                    }
                }
            } catch (\Throwable) {
            }

            foreach ($props as $propName => $propVal) {
                if (! str_ends_with($propName, '_id') || $propName === 'parent_id') {
                    continue;
                }

                $base = substr($propName, 0, -3);
                $tablesToTry = match ($base) {
                    'department' => ['teams', 'departments', 'department'],
                    default => [Str::plural($base), $base],
                };

                foreach ($tablesToTry as $tbl) {
                    if (Schema::hasTable($tbl)) {
                        $columns = Schema::getColumnListing($tbl);
                        $labelCol = 'id';
                        foreach (['name', 'title', 'team_name', 'company_name', 'display_name', 'full_name', 'label', 'order_number', 'sku', 'reference', 'code'] as $candidate) {
                            if (in_array($candidate, $columns, true)) {
                                $labelCol = $candidate;
                                break;
                            }
                        }
                        // Convention fallback: *_name columns (leave_types.type_name, ...)
                        if ($labelCol === 'id') {
                            $segmentName = Str::afterLast($base, '_').'_name';
                            $nameCols = array_values(array_filter($columns, static fn ($cl) => str_ends_with($cl, '_name')));
                            foreach (array_merge([$segmentName], $nameCols) as $candidate) {
                                if (in_array($candidate, $columns, true)) {
                                    $labelCol = $candidate;
                                    break;
                                }
                            }
                        }

                        // Capped so a large related table can't blow up the form; the selected value always stays
                        $data = DB::table($tbl)
                            ->orderBy($labelCol)
                            ->limit((int) config('laraslice.forms.relationship_options_limit', 500))
                            ->pluck($labelCol, 'id')
                            ->toArray();
                        if (is_scalar($propVal) && $propVal !== '' && ! array_key_exists($propVal, $data)) {
                            $selected = DB::table($tbl)->where('id', $propVal)->value($labelCol);
                            if ($selected !== null) {
                                $data[$propVal] = $selected;
                            }
                        }

                        $camelPlural = Str::camel(Str::plural($base)).'Options';
                        $camelSingular = Str::camel($base).'Options';
                        $studlyPlural = Str::studly(Str::plural($base)).'Options';
                        $snakePlural = Str::snake(Str::plural($base)).'Options';
                        $snakeSingular = Str::snake($base).'Options';

                        $options[$camelPlural] = $data;
                        $options[$camelSingular] = $data;
                        $options[$studlyPlural] = $data;
                        $options[$snakePlural] = $data;
                        $options[$snakeSingular] = $data;

                        // Resolve quick-add store URL for dialog/drawer
                        $quickStoreUrl = null;
                        $pluralBase = Str::plural($base);
                        foreach ([$base.'.store', $pluralBase.'.store'] as $rName) {
                            if (Route::has($rName)) {
                                $quickStoreUrl = route($rName);
                                break;
                            }
                        }
                        if (! $quickStoreUrl) {
                            foreach (['crm.', 'e_commerce.', 'ecommerce.', 'billing.', 'content.', 'admin.'] as $dPrefix) {
                                if (Route::has($dPrefix.$base.'.store')) {
                                    $quickStoreUrl = route($dPrefix.$base.'.store');
                                    break;
                                }
                                if (Route::has($dPrefix.$pluralBase.'.store')) {
                                    $quickStoreUrl = route($dPrefix.$pluralBase.'.store');
                                    break;
                                }
                            }
                        }
                        if (! $quickStoreUrl) {
                            foreach (Route::getRoutes()->getRoutesByName() as $rName => $routeObj) {
                                if ($rName === "{$base}.store" || $rName === "{$pluralBase}.store"
                                    || str_ends_with($rName, ".{$base}.store")
                                    || str_ends_with($rName, ".{$pluralBase}.store")) {
                                    $quickStoreUrl = route($rName);
                                    break;
                                }
                            }
                        }

                        if ($quickStoreUrl) {
                            $options[Str::camel($base).'QuickStoreUrl'] = $quickStoreUrl;
                            $options[Str::camel($pluralBase).'QuickStoreUrl'] = $quickStoreUrl;
                            $options[Str::studly($pluralBase).'QuickStoreUrl'] = $quickStoreUrl;
                            $options[Str::snake($base).'QuickStoreUrl'] = $quickStoreUrl;
                            $options[Str::snake($pluralBase).'QuickStoreUrl'] = $quickStoreUrl;
                        }
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail silently if DB schema is not ready
        }

        // Relation-multi (BelongsToMany) form selections declared in
        // the manifest's form sections carry no *_id property, so the
        // loop above never sees them: resolve their options from the
        // root model's own relation instead (any pivot-backed picker).
        try {
            foreach (($this->ownManifest()['ui']['forms']['sections'] ?? []) as $section) {
                foreach (($section['fields'] ?? []) as $field) {
                    if (! is_array($field) || ($field['type'] ?? '') !== 'relation_multi') {
                        continue;
                    }
                    $handle = (string) ($field['handle'] ?? '');
                    $relation = (string) ($field['relation'] ?? $handle);
                    if ($handle === '' || $relation === '') {
                        continue;
                    }
                    $key = Str::camel($handle).'Options';
                    if (isset($options[$key])) {
                        continue;
                    }
                    $modelClass = $this->getService()->modelClass();
                    $probe = new $modelClass;
                    if (! method_exists($probe, $relation)) {
                        continue;
                    }
                    $related = $probe->{$relation}()->getRelated();
                    $table = $related->getTable();
                    $columns = Schema::getColumnListing($table);
                    $labelCol = 'id';
                    foreach (['name', 'title', 'team_name', 'company_name', 'display_name', 'full_name', 'label', 'code'] as $candidate) {
                        if (in_array($candidate, $columns, true)) {
                            $labelCol = $candidate;
                            break;
                        }
                    }
                    if ($labelCol === 'id') {
                        foreach ($columns as $column) {
                            if (str_ends_with($column, '_name')) {
                                $labelCol = $column;
                                break;
                            }
                        }
                    }
                    $options[$key] = DB::table($table)
                        ->orderBy($labelCol)
                        ->limit((int) config('laraslice.forms.relationship_options_limit', 500))
                        ->pluck($labelCol, 'id')
                        ->toArray();
                }
            }
        } catch (\Throwable) {
            // Options stay empty; the picker renders an honest empty state
        }

        return $options;
    }

    protected function resolveRouteName(string $action): string
    {
        $prefix = rtrim($this->getRoutePrefix(), '.');
        $singular = Str::singular($prefix);
        $plural = Str::plural($prefix);

        if (Route::has("{$prefix}.{$action}")) {
            return "{$prefix}.{$action}";
        }
        if (Route::has("{$singular}.{$action}")) {
            return "{$singular}.{$action}";
        }
        if (Route::has("{$plural}.{$action}")) {
            return "{$plural}.{$action}";
        }

        return "{$prefix}.{$action}";
    }

    protected function routeParameters(array $extra = []): array
    {
        $parameters = request()->route()?->parameters() ?? [];
        unset($parameters['id']);

        return array_merge($parameters, $extra);
    }

    public function show(string|int $id)
    {
        $this->authorizeSlice('view');

        $service = $this->getService();
        $record = method_exists($service, 'findModel') ? $service->findModel($id) : null;
        if (! $record) {
            $record = $service->getItemById($id);
        }

        if (! $record) {
            return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())->with('error', 'Record not found');
        }

        // Own-scope: another person's record is not viewable.
        // A slice with no derivable scope clauses keeps the
        // historical unenforced behaviour (the porter/C3 pass
        // ensures view-own slices declare their scope).
        if ($this->sliceViewScope() === 'own' && $this->ownScopeClauses() !== [] && ! $this->recordMatchesOwnScope($record)) {
            abort(404);
        }

        $showView = $this->ownViewName('show');
        $ownManifest = $this->ownManifest();

        // Document-primary show (G2): when the slice declares
        // ui.show.document.primary, the record's page IS the
        // document — the source products' invoice behaviour —
        // rendered in the app shell instead of the generic
        // hub. The /document route keeps the print layout.
        $docDef = $ownManifest['ui']['show']['document'] ?? null;
        if (is_array($docDef) && ! empty($docDef['primary'])) {
            $document = DirectoryUi::documentFromManifest($ownManifest, $record);
            if ($document !== null) {
                $own = $this->ownViewName('document');
                $view = view()->exists($own) ? $own : 'laraslice::documents.default';
                $prefix = $this->getRoutePrefix();

                return view($view, [
                    'document' => $document,
                    'record' => $record,
                    'routePrefix' => $prefix,
                    'routeParameters' => $this->routeParameters(['id' => $id]),
                    'layout' => 'app',
                    'can' => $this->sliceAbilities(),
                    'indexUrl' => Route::has($prefix.'index')
                        ? route($prefix.'index', $this->routeParameters()) : null,
                    'editUrl' => Route::has($prefix.'edit')
                        ? route($prefix.'edit', $this->routeParameters(['id' => $id])) : null,
                    'printUrl' => Route::has($prefix.'document')
                        ? route($prefix.'document', $this->routeParameters(['id' => $id])) : null,
                ]);
            }
        }

        if (view()->exists($showView)) {
            return view($showView, [
                'record' => $record,
                'routePrefix' => $this->getRoutePrefix(),
                'routeParameters' => $this->routeParameters(),
                'can' => $this->sliceAbilities(),
                // F3: detail-page composition. Resolved from THIS
                // controller's own manifest (duplicate slice names make
                // name-based lookup unreliable) so the show view can
                // render related-record tabs with live counts.
                'relatedTabs' => DirectoryUi::relatedTabsFromManifest($ownManifest, $record),
                // F3c: declared header stats (ui.show.stats) computed
                // over the record's relations.
                'showStats' => DirectoryUi::showStatsFromManifest($ownManifest, $record),
                'showWidgets' => DirectoryUi::showWidgetsFromManifest($ownManifest, $record),
                // Layout kit step 3: declared show composition
                // (ui.show.composition) for the view's branches.
                'showComposition' => DirectoryUi::showCompositionFromManifest($ownManifest),
                // Hub tab curation: the declared fields-tab label
                // (ui.show.tabs type: fields) — show views render it
                // in place of the hard-coded 'Overview' when declared.
                'fieldsTabLabel' => DirectoryUi::fieldsTabLabelFromManifest($ownManifest),
                'timerState' => $this->timerState($ownManifest, $record),
                ...$this->overlayLayoutData(request()),
                'documentUrl' => Route::has($this->resolveRouteName('document'))
                    ? route($this->resolveRouteName('document'), $this->routeParameters(['id' => $id]))
                    : null,
            ]);
        }

        return $this->edit($id);
    }

    /**
     * Printable document view (G2): serves the slice's declared
     * ui.show.document at {prefix}/{id}/document. The route is
     * registered centrally by SliceManager for declared slices. The
     * slice's own document template wins; the package's standard
     * document template renders otherwise.
     */
    /**
     * Inline field update (standard list pattern P1): persists ONE
     * field of ONE record from the list's inline editor (e.g. the
     * status select). The field must be declared in the slice's
     * own ui.directory.inline_edit, and when the field declares
     * options the value must be one of them — the declaration is
     * the allowlist, nothing else is writable through here.
     */
    public function inlineUpdate(Request $request, string|int $id)
    {
        $field = (string) $request->input('field', '');
        $manifest = $this->ownManifest();
        $allowed = (array) ($manifest['ui']['directory']['inline_edit'] ?? []);
        if ($field === '' || ! in_array($field, $allowed, true)) {
            return response()->json(['success' => false, 'message' => 'Field is not inline-editable.'], 422);
        }

        $value = $request->input('value');
        $optionValues = null;
        $fieldDefs = [];
        foreach ((array) ($manifest['fields'] ?? []) as $def) {
            if (is_array($def) && isset($def['handle'])) {
                $fieldDefs[$def['handle']] = $def;
            }
        }
        foreach ((array) ($manifest['models'] ?? []) as $model) {
            foreach ((array) ($model['fields'] ?? []) as $def) {
                if (is_array($def) && isset($def['handle'])) {
                    $fieldDefs[$def['handle']] = $def;
                }
            }
        }
        if (isset($fieldDefs[$field]['options']) && is_array($fieldDefs[$field]['options'])) {
            $optionValues = [];
            foreach ($fieldDefs[$field]['options'] as $k => $opt) {
                $optionValues[] = (string) (is_array($opt) ? ($opt['value'] ?? $k) : (is_int($k) ? $opt : $k));
            }
        }
        if ($optionValues !== null && ! in_array((string) $value, $optionValues, true)) {
            return response()->json(['success' => false, 'message' => 'Value is not a declared option.'], 422);
        }

        $service = $this->getService();
        $record = method_exists($service, 'findModel') ? $service->findModel($id) : null;
        if (! $record) {
            return response()->json(['success' => false, 'message' => 'Record not found.'], 404);
        }
        // Inline ACTION (list contract v3): a declared inline field
        // that is not a model attribute (e.g. a person's role, a
        // pivot) is written by the slice service's own writer —
        // the declaration stays the allowlist, the service owns
        // the mutation and its validation.
        if (method_exists($service, 'inlineFieldWriter')) {
            $writer = $service->inlineFieldWriter($field);
            if (is_callable($writer)) {
                $writer($record, $value);

                return response()->json(['success' => true]);
            }
        }
        $record->forceFill([$field => $value])->save();

        return response()->json(['success' => true]);
    }

    public function document(string|int $id)
    {
        $this->authorizeSlice('view');

        $service = $this->getService();
        $record = method_exists($service, 'findModel') ? $service->findModel($id) : null;
        if (! $record) {
            $record = $service->getItemById($id);
        }
        if (! $record) {
            return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())->with('error', 'Record not found');
        }

        $document = DirectoryUi::documentFromManifest($this->ownManifest(), $record);
        if ($document === null) {
            abort(404);
        }

        $own = $this->ownViewName('document');
        $view = view()->exists($own) ? $own : 'laraslice::documents.default';

        return view($view, [
            'document' => $document,
            'record' => $record,
            'routePrefix' => $this->getRoutePrefix(),
            'routeParameters' => $this->routeParameters(['id' => $id]),
        ]);
    }

    public function edit(string|int $id)
    {
        $this->authorizeSlice('edit');

        $form = $this->getService()->getItemById($id);

        if (! $form) {
            return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())->with('error', 'Record not found');
        }

        $viewData = array_merge(
            $this->resolveRelationshipOptions($form),
            $this->getFormViewData($form, false),
            $this->overlayLayoutData(request()),
            [
                'form' => $form,
                'isNew' => false,
                'routePrefix' => $this->getRoutePrefix(),
                'routeParameters' => $this->routeParameters(['id' => $id]),
                'parentId' => request()->route('parentId'),
            ]
        );

        return view($this->ownViewName('form'), $viewData);
    }

    public function store(Request $request)
    {
        $this->authorizeSlice('create');

        // Prevent accidental rapid duplicate submissions (within 3 seconds)
        $fingerprint = 'slice_sub_'.sha1(
            ($request->user()?->id ?? $request->ip()).'|'.
            $request->path().'|'.
            json_encode($request->except(['_token', '_method']))
        );

        if (Cache::has($fingerprint)) {
            $prevId = Cache::get($fingerprint);
            $msg = 'Record created successfully.';
            if ($request->wantsJson() || $request->ajax() || $request->header('Accept') === 'application/json') {
                return response()->json(['success' => true, 'id' => $prevId, 'message' => $msg], 200);
            }

            return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())->with('success', $msg);
        }

        $formClass = $this->getFormClass();
        // A create must never target an existing record through a smuggled id
        $form = $formClass::fromArray($request->except(['id']));
        $this->captureRepeaterMarkers($request, $form);
        $this->applyWorkflowInitialState($form);
        $this->applyOwnScopeOnCreate($form);

        $id = $this->getService()->save($form);
        try {
            Cache::put($fingerprint, $id, now()->addSeconds(3));
        } catch (\Throwable $e) {
        }

        $msg = 'Record created successfully.';

        if ($request->wantsJson() || $request->ajax() || $request->header('Accept') === 'application/json') {
            $createdModel = $this->getService()->getItemById($id);
            $label = $createdModel->name ?? $createdModel->title ?? $createdModel->label ?? ('#'.$id);

            return response()->json([
                'success' => true,
                'id' => $id,
                'label' => $label,
                'item' => $createdModel,
                'message' => $msg,
            ], 201);
        }

        if ($request->input('action') === 'save_continue') {
            return redirect()->route($this->resolveRouteName('edit'), $this->routeParameters(['id' => $id]))->with('success', $msg);
        }

        return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())->with('success', $msg);
    }

    public function update(Request $request, string|int $id)
    {
        $this->authorizeSlice('edit');

        $formClass = $this->getFormClass();
        $data = $request->all();
        $data['id'] = $id;
        $form = $formClass::fromArray($data);
        $this->captureRepeaterMarkers($request, $form);

        $this->getService()->save($form);
        $msg = 'Record updated successfully.';

        if ($request->wantsJson() || $request->ajax() || $request->header('Accept') === 'application/json') {
            $updatedModel = $this->getService()->getItemById($id);
            $label = $updatedModel->name ?? $updatedModel->title ?? $updatedModel->label ?? ('#'.$id);

            return response()->json([
                'success' => true,
                'id' => $id,
                'label' => $label,
                'item' => $updatedModel,
                'message' => $msg,
            ], 200);
        }

        if ($request->input('action') === 'save_continue') {
            return back()->with('success', $msg);
        }

        return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())->with('success', $msg);
    }

    public function destroy(string|int $id)
    {
        $this->authorizeSlice('delete');

        $this->getService()->delete($id);

        return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())
            ->with('success', 'Record deleted successfully');
    }

    /**
     * Repeater forms submit a hidden _repeaters[] marker per collection so
     * a collection whose rows were ALL removed still counts as provided
     * (it submits no array key at all). Mark those DTO properties provided
     * and normalize their values for the service's child sync.
     */
    protected function captureRepeaterMarkers(Request $request, object $form): void
    {
        $service = $this->getService();
        if (! $service instanceof BaseSliceService || ! $form instanceof BaseFormBusinessObject) {
            return;
        }
        $marked = (array) $request->input('_repeaters', []);
        foreach (array_keys($service->repeaterDefinitions()) as $handle) {
            if (in_array($handle, $marked, true) || $request->has($handle)) {
                if (property_exists($form, $handle)) {
                    $value = $request->input($handle);
                    $form->{$handle} = is_array($value) ? $value : [];
                }
                $form->markProvided($handle);
            }
        }
    }

    /**
     * Resolve the permission base slug (e.g. "shop_product", "user", "role")
     */
    protected function getPermissionBase(): string
    {
        // The Heart wins: when the slice's manifest declares
        // permissions, their base IS the permission base. The
        // route-prefix derivation below misfires on prefixed
        // routes (hr_holidays -> hr_holiday) whose Hearts declare
        // clean bases (holiday.*) — every non-super user 403s.
        try {
            foreach (($this->ownManifest()['permissions'] ?? []) as $perm) {
                if (is_string($perm) && str_contains($perm, '.')) {
                    return substr($perm, 0, (int) strpos($perm, '.'));
                }
            }
        } catch (\Throwable) {
        }

        $prefix = rtrim($this->getRoutePrefix(), '.');
        if (str_contains($prefix, '.')) {
            $parts = explode('.', $prefix);
            $prefix = end($parts);
        }

        return Str::snake(Str::singular($prefix));
    }

    /**
     * Parse this controller's own slice manifest from disk.
     *
     * The controller file lives two levels below the slice root
     * (Controllers/XxxController.php), which identifies the slice
     * unambiguously even when several slices share a name.
     *
     * @return array<string, mixed>
     */
    /**
     * The column that owns a record for own-scope access: the
     * manifest's declared owner_field, else user_id when the
     * slice has one. Null means the slice cannot be own-scoped.
     */
    protected function ownScopeOwnerField(): ?string
    {
        $manifest = $this->ownManifest();
        if (! empty($manifest['owner_field'])) {
            return (string) $manifest['owner_field'];
        }

        return isset($manifest['fields']['user_id']) ? 'user_id' : null;
    }

    /**
     * The viewer's own-scope clauses for this slice (Body v2
     * access): the manifest's declared `access.own_scope`
     * list when present — each entry is {field: <column>} or
     * {relation: <name>, key: <related column, default id>},
     * resolved against the signed-in user — else the single
     * owner-column clause ownScopeOwnerField() derives.
     * A record is the viewer's when ANY clause matches: a
     * project is its client's by client_id AND its members'
     * by the members relation; an invoice is its client's
     * via the project relation's client_id.
     *
     * @return array<int, array{field?: string, relation?: string, key?: string, value: mixed}>
     */
    protected function ownScopeClauses(): array
    {
        $manifest = $this->ownManifest();
        $declared = $manifest['access']['own_scope'] ?? null;
        if (is_array($declared) && $declared !== []) {
            $clauses = [];
            foreach ($declared as $clause) {
                if (! is_array($clause)) {
                    continue;
                }
                if (! empty($clause['field'])) {
                    $clauses[] = ['field' => (string) $clause['field'], 'value' => auth()->id()];
                } elseif (! empty($clause['relation'])) {
                    $clauses[] = ['relation' => (string) $clause['relation'], 'key' => (string) ($clause['key'] ?? 'id'), 'value' => auth()->id()];
                }
            }
            if ($clauses !== []) {
                return $clauses;
            }
        }
        $ownerField = $this->ownScopeOwnerField();

        return $ownerField !== null ? [['field' => $ownerField, 'value' => auth()->id()]] : [];
    }

    /**
     * Whether a loaded record belongs to the viewer under
     * the own-scope clauses (detail-route enforcement).
     */
    protected function recordMatchesOwnScope(object $record): bool
    {
        foreach ($this->ownScopeClauses() as $clause) {
            if (isset($clause['field'])) {
                if ((int) ($record->{$clause['field']} ?? 0) === (int) $clause['value']) {
                    return true;
                }
            } elseif (isset($clause['relation']) && method_exists($record, $clause['relation'])) {
                try {
                    $relation = $record->{$clause['relation']}();
                    $qualified = str_contains($clause['relation'], '.')
                        ? $clause['key']
                        : $relation->getRelated()->getTable().'.'.$clause['key'];
                    if ($relation->where($qualified, $clause['value'])->exists()) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    // An unevaluable clause is unsatisfied, never an error.
                }
            }
        }

        return false;
    }

    /**
     * Own-scope create: a view-own user creating a record
     * creates it for THEMSELVES — the owner column is forced
     * to the creator regardless of what the form submitted
     * (an employee applying for leave applies for herself,
     * even if the shared form carries a person picker).
     */
    protected function applyOwnScopeOnCreate(object $form): void
    {
        if ($this->sliceViewScope() !== 'own') {
            return;
        }
        $ownerField = $this->ownScopeOwnerField();
        if ($ownerField !== null && property_exists($form, $ownerField)) {
            $form->{$ownerField} = auth()->id();
        }
    }

    /**
     * Workflow hygiene (Body v2): a created record enters its
     * workflow at the declared initial state when the request
     * left the state field empty — the state is server-set,
     * never an accident of an empty input.
     */
    protected function applyWorkflowInitialState(object $form): void
    {
        $workflow = $this->ownManifest()['workflow'] ?? null;
        if (! is_array($workflow) || empty($workflow['enabled'])) {
            return;
        }
        $field = (string) ($workflow['state_field'] ?? 'status');
        $initial = $workflow['initial_state'] ?? null;
        if ($initial !== null && property_exists($form, $field) && empty($form->{$field})) {
            $form->{$field} = $initial;
        }
    }

    protected function ownManifest(): array
    {
        try {
            $ref = new \ReflectionClass($this);
            $sliceDir = dirname((string) $ref->getFileName(), 2);
            foreach (['slice.yaml', 'slice.yml'] as $file) {
                $path = $sliceDir.DIRECTORY_SEPARATOR.$file;
                if (is_file($path)) {
                    $data = Yaml::parseFile($path);

                    return is_array($data) ? $data : [];
                }
            }
        } catch (\Throwable $e) {
            // A missing manifest means no composed tabs; never fail show().
        }

        return [];
    }

    /**
     * Load UI config from the slice's slice.yaml.
     */
    /**
     * Resolve the declared overlay presentation for create/edit/show
     * (ui.presentation.*): page (default), drawer, or modal. (F7 —
     * URL-bound overlays: the canonical route stays the screen's URL;
     * drawer/modal only change how it is presented from list pages.)
     *
     * @return array{create: string, edit: string, show: string}
     */
    /**
     * Resolve a view name for THIS slice (G6). Same-named slices share
     * the generated '{plural}::' namespace and Laravel renders the
     * first registered hint — another slice's template. When the
     * shared namespace's winning hint is not this controller's own
     * slice directory, fall back to the unique path-derived namespace
     * SliceManager registered for this slice (ls_...), so a slice
     * always renders its own templates.
     */
    protected function ownViewName(string $view): string
    {
        $default = $this->getViewPrefix().$view;
        try {
            $file = (new \ReflectionClass($this))->getFileName();
            if (! $file) {
                return $default;
            }
            $sliceDir = dirname(dirname($file));
            $viewsDir = $sliceDir.'/Resources/views';
            if (! is_file($viewsDir.'/'.$view.'.blade.php')) {
                return $default;
            }
            $ns = rtrim($this->getViewPrefix(), ':');
            $hints = view()->getFinder()->getHints()[$ns] ?? [];
            foreach ($hints as $hint) {
                if (is_file($hint.'/'.$view.'.blade.php')) {
                    if (realpath($hint) === realpath($viewsDir)) {
                        return $default; // shared namespace already resolves to my own views
                    }
                    break; // another slice shadows the shared namespace
                }
            }
            $unique = SliceManager::viewNamespaceForPath($sliceDir).'::'.$view;

            return view()->exists($unique) ? $unique : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Declared overlay drawer width (ui.presentation.drawer_width:
     * sm|md|lg|xl, default lg — content/form drawers need more
     * room than the component's compact default).
     */
    protected function presentationDrawerSize(): string
    {
        $declared = $this->ownManifest()['ui']['presentation']
            ?? $this->getSliceUiConfig()['presentation']
            ?? [];
        $size = is_array($declared) ? ($declared['drawer_width'] ?? 'lg') : 'lg';

        return in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'lg';
    }

    protected function presentationModes(): array
    {
        $declared = $this->ownManifest()['ui']['presentation']
            ?? $this->getSliceUiConfig()['presentation']
            ?? [];
        $modes = [];
        foreach (['create', 'edit', 'show'] as $action) {
            $mode = is_array($declared) ? ($declared[$action] ?? 'page') : 'page';
            $modes[$action] = in_array($mode, ['drawer', 'modal'], true) ? $mode : 'page';
        }

        return $modes;
    }

    /**
     * When the overlay loader fetches a screen, the same canonical URL
     * answers with the fragment layout (content only, no app chrome).
     *
     * @return array<string, string>
     */
    protected function overlayLayoutData(Request $request): array
    {
        return $request->headers->has('X-LaraSlice-Overlay')
            ? ['overlayLayout' => 'laraslice::layouts.fragment']
            : [];
    }

    protected function getSliceUiConfig(): array
    {
        // The controller's OWN manifest wins (G5): route-prefix lookup
        // is name-based and silently resolves a different slice when
        // names collide — the Tasks index once rendered another slice's
        // quick filters and none of its own sidebar filters. Same
        // own-manifest principle as show() (F3).
        try {
            $ref = new \ReflectionClass($this);
            $ctrlPath = $ref->getFileName();
            // Controllers/XxxController.php -> slice manifest (two levels up)
            $sliceDir = dirname($ctrlPath, 2);
            $yamlPath = $sliceDir.'/slice.yaml';
            if (file_exists($yamlPath)) {
                $data = Yaml::parseFile($yamlPath);

                return $data['ui'] ?? [];
            }
            // Core framework slices declare ui in slice.yaml
            $jsonPath = $sliceDir.'/slice.yaml';
            if (file_exists($jsonPath)) {
                $data = json_decode(file_get_contents($jsonPath), true);

                return $data['ui'] ?? [];
            }
        } catch (\Throwable $e) {
            // Fall through to the registry lookup
        }

        try {
            $slice = app(SliceManager::class)->getSliceByRoutePrefix($this->getRoutePrefix());
            if (! empty($slice->ui)) {
                return (array) $slice->ui;
            }
        } catch (\Throwable $e) {
            // No registry entry either
        }

        return [];
    }

    /**
     * Compute summary card values (sum, count, avg) from the database.
     */
    protected function computeSummaryData(array $cards): array
    {
        $data = [];
        if (empty($cards)) {
            return $data;
        }

        try {
            $service = $this->getService();
            $model = null;
            // Try to get the model via the service (using reflection for protected method)
            try {
                $ref = new \ReflectionMethod($service, 'getModelClass');
                $ref->setAccessible(true);
                $modelClass = $ref->invoke($service);
                if ($modelClass && class_exists($modelClass)) {
                    $model = new $modelClass;
                }
            } catch (\Throwable $e) {
                // Try alternative: get from filter or form class
            }
            if (! $model) {
                return $data;
            }

            $query = $model->newQuery();
            foreach ($cards as $card) {
                $handle = $card['handle'] ?? '';
                $aggregate = $card['aggregate'] ?? 'count';
                if (! $handle) {
                    continue;
                }
                try {
                    // A card may scope itself with a where map
                    // ({column: value}) — status strips such as
                    // Total / Open / Closed / Pending counts — and
                    // may store under its own key when several
                    // cards share one column.
                    $scoped = $query->clone();
                    if (! empty($card['where']) && is_array($card['where'])) {
                        foreach ($card['where'] as $whereColumn => $whereValue) {
                            $scoped->where($whereColumn, $whereValue);
                        }
                    }
                    $value = match ($aggregate) {
                        'sum' => $scoped->sum($handle),
                        'avg' => $scoped->avg($handle),
                        'max' => $scoped->max($handle),
                        'min' => $scoped->min($handle),
                        default => $scoped->count(),
                    };
                    $dataKey = (string) ($card['key'] ?? $handle);
                    // Format money values
                    if (($card['formatter'] ?? '') === 'money') {
                        $data[$dataKey] = '$'.number_format((float) $value, 2);
                    } else {
                        $data[$dataKey] = is_numeric($value) ? number_format($value) : $value;
                    }
                } catch (\Throwable $e) {
                    $data[(string) ($card['key'] ?? $handle)] = '—';
                }
            }
        } catch (\Throwable $e) {
            // Silently fail - summary cards are optional
        }

        return $data;
    }

    /**
     * Get distinct values for a quick filter field.
     */
    /**
     * Facet options from a lookup table (list contract v3):
     * `lookup:<table>:<value column>:<label column>` resolves to
     * value/label pairs ordered by label — the option set for
     * facet selects (designations, people, categories) whose
     * values live in their own table, not in field declarations
     * or distinct column values.
     */
    protected function resolveLookupOptions(string $spec): array
    {
        $parts = explode(':', substr($spec, strlen('lookup:')));
        if (count($parts) !== 3) {
            return [];
        }
        [$table, $valueCol, $labelCol] = $parts;
        if (! preg_match('/^[a-z0-9_]+$/', $table.$valueCol.$labelCol)) {
            return [];
        }
        try {
            return DB::table($table)
                ->whereNotNull($labelCol)
                ->where($labelCol, '!=', '')
                ->distinct()
                ->orderBy($labelCol)
                ->get([$valueCol, $labelCol])
                ->map(fn ($r) => ['value' => $r->{$valueCol}, 'label' => (string) $r->{$labelCol}])
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function getDistinctFilterOptions(string $field): array
    {
        try {
            $service = $this->getService();
            $model = null;
            if (method_exists($service, 'getModel')) {
                $model = $service->getModel();
            } elseif (method_exists($service, 'getModelClass')) {
                $ref = new \ReflectionMethod($service, 'getModelClass');
                $ref->setAccessible(true);
                $modelClass = $ref->invoke($service);
                $model = new $modelClass;
            }
            if ($model) {
                $values = $model->newQuery()->distinct()->pluck($field)->filter()->take(20);

                return $values->map(fn ($v) => ['value' => $v, 'label' => Str::headline((string) $v)])->toArray();
            }
        } catch (\Throwable $e) {
            // Silently fail
        }

        return [];
    }
}
