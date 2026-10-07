<?php

namespace LaraSlice\Core\Base;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

        $pagedList = $this->getService()->getList($filter);

        return view($this->getViewPrefix() . 'index', [
            'pagedList'   => $pagedList,
            'filter'      => $filter,
            'routePrefix' => $this->getRoutePrefix(),
            'routeParameters' => $this->routeParameters(),
            'parentId' => request()->route('parentId'),
        ]);
    }

    public function create()
    {
        $this->authorizeSlice('create');

        $formClass = $this->getFormClass();
        $form = new $formClass();

        $viewData = array_merge(
            $this->resolveRelationshipOptions($form),
            $this->getFormViewData($form, true),
            [
                'form'        => $form,
                'isNew'       => true,
                'routePrefix' => $this->getRoutePrefix(),
                'routeParameters' => $this->routeParameters(),
                'parentId' => request()->route('parentId'),
            ]
        );

        return view($this->getViewPrefix() . 'form', $viewData);
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
                if (!str_ends_with($propName, '_id') || $propName === 'parent_id') {
                    continue;
                }

                $base = substr($propName, 0, -3);
                $tablesToTry = [
                    \Illuminate\Support\Str::plural($base),
                    $base,
                ];

                foreach ($tablesToTry as $tbl) {
                    if (\Illuminate\Support\Facades\Schema::hasTable($tbl)) {
                        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($tbl);
                        $labelCol = 'id';
                        foreach (['name', 'title', 'label', 'order_number', 'sku'] as $candidate) {
                            if (in_array($candidate, $columns, true)) {
                                $labelCol = $candidate;
                                break;
                            }
                        }

                        $data = \Illuminate\Support\Facades\DB::table($tbl)->pluck($labelCol, 'id')->toArray();

                        $camelPlural = \Illuminate\Support\Str::camel(\Illuminate\Support\Str::plural($base)) . 'Options';
                        $camelSingular = \Illuminate\Support\Str::camel($base) . 'Options';
                        $studlyPlural = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::plural($base)) . 'Options';
                        $snakePlural = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::plural($base)) . 'Options';
                        $snakeSingular = \Illuminate\Support\Str::snake($base) . 'Options';

                        $options[$camelPlural] = $data;
                        $options[$camelSingular] = $data;
                        $options[$studlyPlural] = $data;
                        $options[$snakePlural] = $data;
                        $options[$snakeSingular] = $data;

                        // Resolve quick-add store URL for dialog/drawer
                        $quickStoreUrl = null;
                        $pluralBase = \Illuminate\Support\Str::plural($base);
                        foreach ([$base . '.store', $pluralBase . '.store'] as $rName) {
                            if (\Illuminate\Support\Facades\Route::has($rName)) {
                                $quickStoreUrl = route($rName);
                                break;
                            }
                        }
                        if (!$quickStoreUrl) {
                            foreach (['crm.', 'e_commerce.', 'ecommerce.', 'billing.', 'content.', 'admin.'] as $dPrefix) {
                                if (\Illuminate\Support\Facades\Route::has($dPrefix . $base . '.store')) {
                                    $quickStoreUrl = route($dPrefix . $base . '.store');
                                    break;
                                }
                                if (\Illuminate\Support\Facades\Route::has($dPrefix . $pluralBase . '.store')) {
                                    $quickStoreUrl = route($dPrefix . $pluralBase . '.store');
                                    break;
                                }
                            }
                        }
                        if (!$quickStoreUrl) {
                            foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByName() as $rName => $routeObj) {
                                if ($rName === "{$base}.store" || $rName === "{$pluralBase}.store"
                                    || str_ends_with($rName, ".{$base}.store")
                                    || str_ends_with($rName, ".{$pluralBase}.store")) {
                                    $quickStoreUrl = route($rName);
                                    break;
                                }
                            }
                        }

                        if ($quickStoreUrl) {
                            $options[\Illuminate\Support\Str::camel($base) . 'QuickStoreUrl'] = $quickStoreUrl;
                            $options[\Illuminate\Support\Str::camel($pluralBase) . 'QuickStoreUrl'] = $quickStoreUrl;
                            $options[\Illuminate\Support\Str::studly($pluralBase) . 'QuickStoreUrl'] = $quickStoreUrl;
                            $options[\Illuminate\Support\Str::snake($base) . 'QuickStoreUrl'] = $quickStoreUrl;
                            $options[\Illuminate\Support\Str::snake($pluralBase) . 'QuickStoreUrl'] = $quickStoreUrl;
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
        $singular = \Illuminate\Support\Str::singular($prefix);
        $plural = \Illuminate\Support\Str::plural($prefix);

        if (\Illuminate\Support\Facades\Route::has("{$prefix}.{$action}")) {
            return "{$prefix}.{$action}";
        }
        if (\Illuminate\Support\Facades\Route::has("{$singular}.{$action}")) {
            return "{$singular}.{$action}";
        }
        if (\Illuminate\Support\Facades\Route::has("{$plural}.{$action}")) {
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

    public function edit(string|int $id)
    {
        $this->authorizeSlice('edit');

        $form = $this->getService()->getItemById($id);

        if (!$form) {
            return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())->with('error', 'Record not found');
        }

        $viewData = array_merge(
            $this->resolveRelationshipOptions($form),
            $this->getFormViewData($form, false),
            [
                'form'        => $form,
                'isNew'       => false,
                'routePrefix' => $this->getRoutePrefix(),
                'routeParameters' => $this->routeParameters(['id' => $id]),
                'parentId' => request()->route('parentId'),
            ]
        );

        return view($this->getViewPrefix() . 'form', $viewData);
    }

    public function store(Request $request)
    {
        $this->authorizeSlice('create');

        // Prevent accidental rapid duplicate submissions (within 3 seconds)
        $fingerprint = 'slice_sub_' . sha1(
            ($request->user()?->id ?? $request->ip()) . '|' .
            $request->path() . '|' .
            json_encode($request->except(['_token', '_method']))
        );

        if (\Illuminate\Support\Facades\Cache::has($fingerprint)) {
            $prevId = \Illuminate\Support\Facades\Cache::get($fingerprint);
            $msg = 'Record created successfully.';
            if ($request->wantsJson() || $request->ajax() || $request->header('Accept') === 'application/json') {
                return response()->json(['success' => true, 'id' => $prevId, 'message' => $msg], 200);
            }
            return redirect()->route($this->resolveRouteName('index'), $this->routeParameters())->with('success', $msg);
        }

        $formClass = $this->getFormClass();
        // A create must never target an existing record through a smuggled id
        $form = $formClass::fromArray($request->except(['id']));

        $id = $this->getService()->save($form);
        try {
            \Illuminate\Support\Facades\Cache::put($fingerprint, $id, now()->addSeconds(3));
        } catch (\Throwable $e) {}

        $msg = 'Record created successfully.';

        if ($request->wantsJson() || $request->ajax() || $request->header('Accept') === 'application/json') {
            $createdModel = $this->getService()->getItemById($id);
            $label = $createdModel->name ?? $createdModel->title ?? $createdModel->label ?? ('#' . $id);

            return response()->json([
                'success' => true,
                'id'      => $id,
                'label'   => $label,
                'item'    => $createdModel,
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
     * Resolve the permission base slug (e.g. "shop_product", "user", "role")
     */
    protected function getPermissionBase(): string
    {
        $prefix = rtrim($this->getRoutePrefix(), '.');
        if (str_contains($prefix, '.')) {
            $parts = explode('.', $prefix);
            $prefix = end($parts);
        }
        return \Illuminate\Support\Str::snake(\Illuminate\Support\Str::singular($prefix));
    }
}
