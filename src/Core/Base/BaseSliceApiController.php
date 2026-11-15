<?php

namespace LaraSlice\Core\Base;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LaraSlice\Core\Base\Traits\AppliesFieldBehaviour;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Core\Metadata\MetadataIssues;
use LaraSlice\Core\Security\Traits\AuthorizesSliceActions;

abstract class BaseSliceApiController extends Controller
{
    use AppliesFieldBehaviour;
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

        if (! $item) {
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

        // Strict binding (Proposal #6): a provided value that
        // does not fit its declared type rejects the write with
        // per-issue errors — it is never silently coerced or
        // dropped on the way to the model.
        if ($form instanceof BaseFormBusinessObject && $form->bindingIssues() !== []) {
            return response()->json(MetadataIssues::binding($form->bindingIssues()), 422);
        }

        try {
            // Field behaviour parity with the web surface
            // (Seat 1, W5/D-012): the shared trait judges
            // this write exactly as BaseSliceWebController
            // judges a form post — mandatory_depends_on
            // throws ValidationException (rendered below in
            // this controller's 422 shape), fetch_from fills
            // only an empty value, read_only_depends_on
            // restores, computed/fallback recompute, and a
            // create mints the declared autoname series.
            // Display-only depends_on has no server effect.
            $existing = $isUpdate ? $this->getService()->getItemById($request->input('id')) : null;
            $this->applyFieldBehaviour($request, $form, $existing, ! $isUpdate);
            if (! $isUpdate) {
                $this->applyAutoname($form, $request);
            }

            $id = $this->getService()->save($form);

            return response()->json([
                'success' => true,
                'id' => $id,
                'message' => 'Record saved successfully',
            ], $isUpdate ? 200 : 201);
        } catch (BindingIssuesException $e) {
            // Replace-semantics completeness (Proposal #6):
            // same per-issue envelope as a binding rejection.
            return response()->json(MetadataIssues::binding($e->issues()), 422);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Record not found.'], 404);
        }
    }

    public function delete(string|int $id): JsonResponse
    {
        $this->authorizeSlice('delete');

        try {
            $deleted = $this->getService()->delete($id);
        } catch (LinkedRecordException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'links' => $e->linkCount()], 409);
        }

        if (! $deleted) {
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

        return Str::snake(Str::singular($name));
    }
}
