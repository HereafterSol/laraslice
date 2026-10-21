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
        if ($filter instanceof BaseFilter) {
            $criteria = [];
            foreach (array_merge($quickFilters, $sidebarFilters) as $declared) {
                $handle = $declared['handle'] ?? null;
                if (! is_string($handle) || $handle === '') {
                    continue;
                }
                if ($request->filled($handle)) {
                    $criteria[$handle] = $request->input($handle);
                }
                foreach (['from', 'to'] as $suffix) {
                    $rangeKey = $handle.'_'.$suffix;
                    if ($request->filled($rangeKey)) {
                        $criteria[$rangeKey] = $request->input($rangeKey);
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
                'matrixUrl' => \Illuminate\Support\Facades\Route::has($this->resolveRouteName('matrix'))
                    ? route($this->resolveRouteName('matrix'), $this->routeParameters())
                    : null,
                'quickFilters' => $quickFilters,
                'sidebarFilters' => $sidebarFilters,
                'activeFilters' => $filter instanceof BaseFilter ? $filter->getCriteria() : [],
                'uiPresentationCreate' => $uiConfig['presentation']['create'] ?? 'page',
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
