<?php

namespace LaraSlice\Core\Base;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IFilterObject;
use LaraSlice\Core\Contracts\IListingDataService;

abstract class BaseSliceService implements IFormDataService, IListingDataService
{
    abstract protected function getModelClass(): string;
    abstract protected function mapToForm(Model $model): IBusinessObject;
    abstract protected function mapToListing(Model $model): IBusinessObject;

    public function save(IBusinessObject $form): string|int
    {
        $this->validate($form);

        $modelClass = $this->getModelClass();
        $isNew = empty($form->id);
        $model = $isNew ? new $modelClass() : $this->newQuery()->findOrFail($form->id);

        $this->beforeSave($form, $model, $isNew);

        $data = $form->toArray();
        unset($data['id']);
        
        // Populate model attributes
        $model->fill($data);
        $this->prepareModelForSave($form, $model, $isNew);
        $model->save();

        $this->afterSave($form, $model, $isNew);

        return $model->getKey();
    }

    public function getItemById(string|int $id): ?IBusinessObject
    {
        $model = $this->newQuery()->find($id);

        if (!$model) {
            return null;
        }

        return $this->mapToForm($model);
    }

    public function delete(string|int $id): bool
    {
        $model = $this->newQuery()->find($id);

        if (!$model) {
            return false;
        }

        $this->beforeDelete($model);
        $result = (bool) $model->delete();
        $this->afterDelete($id);

        return $result;
    }

    public function getList(IFilterObject $filter): PagedDataList
    {
        $query = $this->newQuery();

        $this->applyFilters($query, $filter);

        $totalCount = $query->count();
        $page = $filter->getPage();
        $limit = $filter->getLimit();

        if ($filter->getSortBy()) {
            $query->orderBy($filter->getSortBy(), $filter->isSortDesc() ? 'desc' : 'asc');
        }

        $models = $query->skip(($page - 1) * $limit)->take($limit)->get();
        $items = $models->map(fn($m) => $this->mapToListing($m))->all();

        return new PagedDataList($items, $totalCount, $page, $limit);
    }

    protected function applyFilters(Builder $query, IFilterObject $filter): void
    {
        if ($search = $filter->getSearch()) {
            $this->applySearch($query, $search);
        }
    }

    /** Create a base query that subclasses may scope to an aggregate or tenant. */
    protected function newQuery(): Builder
    {
        $modelClass = $this->getModelClass();

        return $modelClass::query();
    }

    /** Apply server-owned attributes after request data has been mass assigned. */
    /** Apply server-owned attributes after request data has been mass assigned. */
    protected function prepareModelForSave(IBusinessObject $form, Model $model, bool $isNew): void
    {
        if (auth()->check()) {
            $userId = auth()->id();
            if ($isNew && empty($model->created_by) && $this->modelHasColumn($model, 'created_by')) {
                $model->created_by = $userId;
            }
            if ($this->modelHasColumn($model, 'updated_by')) {
                $model->updated_by = $userId;
            }
        }
    }

    protected function modelHasColumn(Model $model, string $column): bool
    {
        if (in_array($column, $model->getFillable(), true) || array_key_exists($column, $model->getAttributes())) {
            return true;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function applySearch(Builder $query, string $search): void
    {
        // Subclasses override with specific searchable columns
    }

    protected function validate(IBusinessObject $form): void {}
    protected function beforeSave(IBusinessObject $form, Model $model, bool $isNew): void {}
    protected function afterSave(IBusinessObject $form, Model $model, bool $isNew): void {}
    protected function beforeDelete(Model $model): void {}
    protected function afterDelete(string|int $id): void {}
}
