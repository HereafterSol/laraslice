<?php

namespace LaraSlice\Core\Base;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Core\Security\Traits\AuthorizesSliceActions;

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
        $createForm = new $formClass();

        // Load UI config from slice.yaml for summary cards, quick filters, etc.
        $uiConfig = $this->getSliceUiConfig();
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
                        $criteria[$handle] = $request->input($handle);
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
                    $criteria[$handle] = $filterDefaults[$handle];
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
            if ($criteria !== []) {
                $filter->setCriteria($criteria);
            }
        }

        $pagedList = $this->getService()->getList($filter);
        $summaryData = $this->computeSummaryData($summaryCards);
        // Resolve quick filter options from distinct values
        foreach ($quickFilters as &$qf) {
            if (isset($qf['options_from']) && str_starts_with($qf['options_from'], 'distinct:')) {
                $field = substr($qf['options_from'], 9);
                $qf['options'] = $this->getDistinctFilterOptions($field);
            }
        }
        unset($qf);

        // Resolve sidebar filter select options (distinct values or
        // related-record labels) into the same value/label shape.
        foreach ($sidebarFilters as &$sf) {
            if (($sf['type'] ?? null) === 'relation_exists' && ($sf['relation'] ?? null) === 'users') {
                try {
                    $sf['options'] = \Illuminate\Support\Facades\DB::table('users')
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
            } elseif (is_string($from) && str_starts_with($from, 'relation:')) {
                $field = substr($from, 9);
                $base = str_ends_with($field, '_id') ? substr($field, 0, -3) : $field;
                $map = $relationshipOptions[\Illuminate\Support\Str::camel($base).'Options'] ?? [];
                $sf['options'] = collect($map)->map(fn ($label, $value) => ['value' => $value, 'label' => (string) $label])->values()->toArray();
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
                $href = \Illuminate\Support\Facades\Route::has((string) $action['route'])
                    ? route((string) $action['route'], $this->routeParameters())
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
                'reportUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('report'))
                    ? route($this->resolveRouteName('report'), $this->routeParameters())
                    : null,
                'matrixUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('matrix'))
                    ? route($this->resolveRouteName('matrix'), $this->routeParameters())
                    : null,
                'bulkEntryLabel' => ($be = \LaraSlice\Support\DirectoryUi::bulkEntryFromManifest($this->ownManifest())) !== null
                    ? ($be['title'] ?? 'Bulk Entry')
                    : 'Bulk Entry',
                'bulkEntryUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('bulk_entry'))
                    ? route($this->resolveRouteName('bulk_entry'), $this->routeParameters())
                    : null,
                // Relative path: the template is consumed by a
                // same-origin fetch, and route()'s absolute form
                // would pin APP_URL's host into the page.
                'inlineUpdateUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('inline'))
                    ? route($this->resolveRouteName('inline'), $this->routeParameters(['id' => '__ID__']), false)
                    : null,
                'quickFilters' => $quickFilters,
                'sidebarFilters' => $sidebarFilters,
                'filterDefaults' => $filterDefaults,
                'directoryActions' => $directoryActions,
                'activeFilters' => $filter instanceof BaseFilter ? $filter->getCriteria() : [],
                'uiPresentationCreate' => $uiConfig['presentation']['create'] ?? 'page',
                // Directory UI values come from the controller's own
                // manifest (G5 own-manifest-first). Generated blades
                // historically re-read the manifest from a relative
                // file path, which silently yields [] in compiled
                // views — controller values take precedence there.
                'inlineEdit' => array_values((array) ($uiConfig['directory']['inline_edit'] ?? [])),
                'tableViews' => array_values((array) ($uiConfig['directory']['views'] ?? ['table'])),
                'defaultView' => (string) ($uiConfig['directory']['default_view'] ?? 'table'),
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
            $filter->setCriteria([
                $dateField.'_from' => sprintf('%04d-%02d-01', $year, $month),
                $dateField.'_to' => date('Y-m-t', mktime(0, 0, 0, $month, 1, $year)).' 23:59:59',
            ]);
        }
        $items = $this->getService()->getList($filter)->items;

        $formClass = $this->getFormClass();
        $options = $this->resolveRelationshipOptions(new $formClass);
        // Relationship options are keyed by convention (usersOptions,
        // userOptions, ...) — find the map for the matrix row key.
        $rowKey = (string) $decl['row_key'];
        $base = str_ends_with($rowKey, '_id') ? substr($rowKey, 0, -3) : $rowKey;
        $labelMap = [];
        foreach ([$rowKey, \Illuminate\Support\Str::camel(\Illuminate\Support\Str::plural($base)).'Options', \Illuminate\Support\Str::camel($base).'Options', \Illuminate\Support\Str::studly(\Illuminate\Support\Str::plural($base)).'Options'] as $candidateKey) {
            if (isset($options[$candidateKey]) && is_array($options[$candidateKey])) {
                $labelMap = $options[$candidateKey];
                break;
            }
        }

        $matrix = \LaraSlice\Support\DirectoryUi::matrixFromManifest($manifest, $items, $month, $year, is_array($labelMap) ? $labelMap : []);
        if ($matrix === null) {
            abort(404);
        }

        $own = $this->ownViewName('matrix');
        $view = view()->exists($own) ? $own : 'laraslice::directory.matrix';

        return view($view, [
            'matrix' => $matrix,
            'indexUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('index'))
                ? route($this->resolveRouteName('index'), $this->routeParameters())
                : url('/'),
            'routePrefix' => $this->getRoutePrefix(),
        ]);
    }

    /**
     * Report view (G2): grouped aggregates for slices declaring
     * ui.directory.report, served at {index-uri}/report with an
     * optional month/year period when the declaration names a
     * date_field. SliceManager registers the route centrally.
     */
    public function report(Request $request)
    {
        $this->authorizeSlice('view');

        $manifest = $this->ownManifest();
        $decl = $manifest['ui']['directory']['report'] ?? null;
        if (! is_array($decl) || empty($decl['group_by'])) {
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
        foreach ([$groupBy, \Illuminate\Support\Str::camel(\Illuminate\Support\Str::plural($base)).'Options', \Illuminate\Support\Str::camel($base).'Options', \Illuminate\Support\Str::studly(\Illuminate\Support\Str::plural($base)).'Options'] as $candidateKey) {
            if (isset($options[$candidateKey]) && is_array($options[$candidateKey])) {
                $labelMap = $options[$candidateKey];
                break;
            }
        }

        $report = \LaraSlice\Support\DirectoryUi::reportFromManifest($manifest, $items, $labelMap);
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
            'indexUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('index'))
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
        $decl = \LaraSlice\Support\DirectoryUi::bulkEntryFromManifest($manifest);
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
        foreach ([$rowKey, \Illuminate\Support\Str::camel(\Illuminate\Support\Str::plural($base)).'Options', \Illuminate\Support\Str::camel($base).'Options', \Illuminate\Support\Str::studly(\Illuminate\Support\Str::plural($base)).'Options'] as $candidateKey) {
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
            'storeUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('bulk_entry_store'))
                ? route($this->resolveRouteName('bulk_entry_store'), $this->routeParameters())
                : url()->current(),
            'indexUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('index'))
                ? route($this->resolveRouteName('index'), $this->routeParameters())
                : url('/'),
            'matrixUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('matrix'))
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
        $decl = \LaraSlice\Support\DirectoryUi::bulkEntryFromManifest($manifest);
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
                    $holidayClass = app(\LaraSlice\Core\Discovery\SliceManager::class)->modelClass($decl['holidays']['slice']);
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
            $message = "Saved {$saved} record(s) across {$days} date(s)" . ($notes === [] ? '.' : ' (' . implode(', ', $notes) . ').');

            return redirect()
                ->route($this->resolveRouteName('bulk_entry'), $this->routeParameters(['date' => $data['from']]))
                ->with('success', $message);
        }

        if (empty($data['date'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
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
        $decl = \LaraSlice\Support\DirectoryUi::timerFromManifest($manifest);
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
            'startUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('timer_start'))
                ? route($this->resolveRouteName('timer_start'), $this->routeParameters(['id' => $record->getKey()]))
                : null,
            'stopUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('timer_stop'))
                ? route($this->resolveRouteName('timer_stop'), $this->routeParameters(['id' => $record->getKey()]))
                : null,
        ];
    }

    /**
     * Start the declared timer on a record (G8). One active timer
     * per user across the whole log table — the source
     * application's rule.
     */
    public function timerStart(Request $request, string|int $id)
    {
        $this->authorizeSlice('create');
        $decl = \LaraSlice\Support\DirectoryUi::timerFromManifest($this->ownManifest());
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
        $decl = \LaraSlice\Support\DirectoryUi::timerFromManifest($this->ownManifest());
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
        $startedAt = \Illuminate\Support\Carbon::parse($log->{$decl['start_field']});
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
                            $segmentName = Str::afterLast($base, '_') . '_name';
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

        $showView = $this->ownViewName('show');
        $ownManifest = $this->ownManifest();
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
                'relatedTabs' => \LaraSlice\Support\DirectoryUi::relatedTabsFromManifest($ownManifest, $record),
                // F3c: declared header stats (ui.show.stats) computed
                // over the record's relations.
                'showStats' => \LaraSlice\Support\DirectoryUi::showStatsFromManifest($ownManifest, $record),
                'showWidgets' => \LaraSlice\Support\DirectoryUi::showWidgetsFromManifest($ownManifest, $record),
                'timerState' => $this->timerState($ownManifest, $record),
                ...$this->overlayLayoutData(request()),
                'documentUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('document'))
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

        $document = \LaraSlice\Support\DirectoryUi::documentFromManifest($this->ownManifest(), $record);
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
    protected function ownManifest(): array
    {
        try {
            $ref = new \ReflectionClass($this);
            $sliceDir = dirname((string) $ref->getFileName(), 2);
            foreach (['slice.yaml', 'slice.yml'] as $file) {
                $path = $sliceDir.DIRECTORY_SEPARATOR.$file;
                if (is_file($path)) {
                    $data = \Symfony\Component\Yaml\Yaml::parseFile($path);

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
            $unique = \LaraSlice\Core\Discovery\SliceManager::viewNamespaceForPath($sliceDir).'::'.$view;

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
            $yamlPath = $sliceDir . '/slice.yaml';
            if (file_exists($yamlPath)) {
                $data = \Symfony\Component\Yaml\Yaml::parseFile($yamlPath);
                return $data['ui'] ?? [];
            }
            // Core framework slices declare ui in slice.yaml
            $jsonPath = $sliceDir . '/slice.yaml';
            if (file_exists($jsonPath)) {
                $data = json_decode(file_get_contents($jsonPath), true);
                return $data['ui'] ?? [];
            }
        } catch (\Throwable $e) {
            // Fall through to the registry lookup
        }

        try {
            $slice = app(\LaraSlice\Core\Discovery\SliceManager::class)->getSliceByRoutePrefix($this->getRoutePrefix());
            if (!empty($slice->ui)) {
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
                    $model = new $modelClass();
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
                    $value = match ($aggregate) {
                        'sum' => $query->clone()->sum($handle),
                        'avg' => $query->clone()->avg($handle),
                        'max' => $query->clone()->max($handle),
                        'min' => $query->clone()->min($handle),
                        default => $query->clone()->count(),
                    };
                    // Format money values
                    if (($card['formatter'] ?? '') === 'money') {
                        $data[$handle] = '$' . number_format((float) $value, 2);
                    } else {
                        $data[$handle] = is_numeric($value) ? number_format($value) : $value;
                    }
                } catch (\Throwable $e) {
                    $data[$handle] = '—';
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
                return $values->map(fn ($v) => ['value' => $v, 'label' => \Illuminate\Support\Str::headline((string) $v)])->toArray();
            }
        } catch (\Throwable $e) {
            // Silently fail
        }

        return [];
    }
}
