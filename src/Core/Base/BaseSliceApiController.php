<?php

namespace LaraSlice\Core\Base;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Core\Security\Traits\AuthorizesSliceActions;

abstract class BaseSliceApiController extends Controller
{
    use AuthorizesSliceActions;

    abstract protected function getService(): IFormDataService&IListingDataService;
    abstract protected function getFormClass(): string;
    abstract protected function getFilterClass(): string;

    public function getList(Request $request): JsonResponse
    {
        $this->authorizeSlice('view');

        $filterClass = $this->getFilterClass();
        $filter = new $filterClass($request->all());

        $list = $this->getService()->getList($filter);

        return response()->json($list->toArray());
    }

    public function getItemById(string|int $id): JsonResponse
    {
        $this->authorizeSlice('view');

        $item = $this->getService()->getItemById($id);

        if (!$item) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json($item->toArray());
    }

    public function save(Request $request): JsonResponse
    {
        $isUpdate = ! empty($request->input('id'));
        $this->authorizeSlice($isUpdate ? 'edit' : 'create');

        $formClass = $this->getFormClass();
        $form = $formClass::fromArray($request->all());

        try {
            $id = $this->getService()->save($form);
            return response()->json([
                'success' => true,
                'id'      => $id,
                'message' => 'Record saved successfully',
            ], $isUpdate ? 200 : 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Record not found.'], 404);
        }
    }

    public function delete(string|int $id): JsonResponse
    {
        $this->authorizeSlice('delete');

        $deleted = $this->getService()->delete($id);

        if (!$deleted) {
            return response()->json(['error' => 'Record not found or could not be deleted'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Record deleted successfully',
        ]);
    }

    /**
     * Permission base slug derived from the controller name, e.g. UserApiController -> "user".
     */
    protected function getPermissionBase(): string
    {
        $name = preg_replace('/ApiController$/', '', class_basename(static::class));

        return \Illuminate\Support\Str::snake(\Illuminate\Support\Str::singular($name));
    }
}
