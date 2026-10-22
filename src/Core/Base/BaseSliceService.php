<?php

namespace LaraSlice\Core\Base;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Core\Contracts\IFilterObject;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LaraSlice\Support\SchemaCache;

abstract class BaseSliceService implements IFormDataService, IListingDataService
{
    abstract protected function getModelClass(): string;

    abstract protected function mapToForm(Model $model): IBusinessObject;

    abstract protected function mapToListing(Model $model): IBusinessObject;

    public function save(IBusinessObject $form): string|int
    {
        return DB::transaction(function () use ($form) {
            $this->normalizeRepeaterPayloads($form);
            $this->validateRepeaterPayloads($form);
            $this->validate($form);

            $modelClass = $this->getModelClass();
            $isNew = empty($form->id);
            $model = $isNew ? new $modelClass : $this->newQuery()->findOrFail($form->id);

            $this->beforeSave($form, $model, $isNew);

            $data = $form->toArray();
            unset($data['id']);
            // Repeater collections are synced separately, never mass-assigned
            foreach (array_keys($this->repeaterDefinitions()) as $repeaterHandle) {
                unset($data[$repeaterHandle]);
            }

            // Updates only touch what the request sent, so partial API updates keep other values
            if (! $isNew && $form instanceof BaseFormBusinessObject && $form->providedFields() !== null) {
                $data = array_intersect_key($data, array_flip($form->providedFields()));
            }

            // Populate model attributes
            $model->fill($data);
            $this->prepareModelForSave($form, $model, $isNew);
            $model->save();

            $this->afterSave($form, $model, $isNew);
            $this->syncRepeaters($form, $model);

            return $model->getKey();
        });
    }

    /**
     * Repeater (line-item) definitions for this slice's aggregate children.
     * Generated services override this from the manifest's repeater fields.
     * Shape per handle: ['table' => string, 'foreign_key' => string,
     * 'tenant_key' => ?string, 'fields' => [name => ['type' => string,
     * 'required' => bool, 'options' => array]]].
     *
     * @return array<string, array<string, mixed>>
     */
    public function repeaterDefinitions(): array
    {
        return [];
    }

    protected function repeaterRowsProvided(IBusinessObject $form, string $handle): bool
    {
        if (! property_exists($form, $handle)) {
            return false;
        }
        if ($form instanceof BaseFormBusinessObject) {
            if ($form->providedFields() !== null) {
                return $form->provided($handle);
            }

            // Hand-built DTO (no request provenance): only sync explicit rows
            return ! empty($form->{$handle});
        }

        return true;
    }

    /**
     * Drop wholly-empty repeater rows (the component always submits one
     * blank starter row) and whitelist row keys to the declared fields.
     */
    protected function normalizeRepeaterPayloads(IBusinessObject $form): void
    {
        foreach ($this->repeaterDefinitions() as $handle => $def) {
            if (! $this->repeaterRowsProvided($form, $handle)) {
                continue;
            }
            $rows = $form->{$handle};
            if (! is_array($rows)) {
                $form->{$handle} = [];

                continue;
            }
            $fields = array_keys($def['fields'] ?? []);
            $clean = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $row = array_intersect_key($row, array_flip(array_merge(['id'], $fields)));
                $isEmpty = true;
                foreach ($fields as $f) {
                    $v = $row[$f] ?? null;
                    if ($v !== null && $v !== '' && $v !== []) {
                        $isEmpty = false;
                        break;
                    }
                }
                if ($isEmpty) {
                    continue;
                }
                foreach ($fields as $f) {
                    $type = $def['fields'][$f]['type'] ?? 'string';
                    if (($row[$f] ?? null) === '' && in_array($type, ['integer', 'bigInteger', 'foreign_id', 'decimal', 'float', 'date', 'datetime', 'timestamp', 'boolean'], true)) {
                        $row[$f] = null;
                    }
                }
                $clean[] = $row;
            }
            $form->{$handle} = $clean;
        }
    }

    protected function validateRepeaterPayloads(IBusinessObject $form): void
    {
        $data = [];
        $rules = [];
        foreach ($this->repeaterDefinitions() as $handle => $def) {
            if (! $this->repeaterRowsProvided($form, $handle)) {
                continue;
            }
            $data[$handle] = $form->{$handle};
            $rules[$handle] = ['array'];
            $rules["{$handle}.*.id"] = ['nullable', 'integer'];
            foreach (($def['fields'] ?? []) as $fname => $fdef) {
                $r = [! empty($fdef['required']) ? 'required' : 'nullable'];
                $r[] = match ($fdef['type'] ?? 'string') {
                    'integer', 'bigInteger', 'foreign_id' => 'integer',
                    'decimal', 'float' => 'numeric',
                    'boolean' => 'boolean',
                    'date', 'datetime', 'timestamp' => 'date',
                    'email' => 'email',
                    'json' => 'array',
                    default => 'string',
                };
                if (! empty($fdef['options']) && is_array($fdef['options'])) {
                    $r[] = 'in:'.implode(',', array_keys($fdef['options']));
                }
                $rules["{$handle}.*.{$fname}"] = $r;
            }
        }
        if ($rules !== []) {
            Validator::make($data, $rules)->validate();
        }
    }

    /**
     * Sync provided repeater collections against the child table: update
     * rows that belong to this parent, insert new rows, delete rows the
     * user removed. Runs inside save()'s transaction.
     */
    protected function syncRepeaters(IBusinessObject $form, Model $model): void
    {
        foreach ($this->repeaterDefinitions() as $handle => $def) {
            if (! $this->repeaterRowsProvided($form, $handle)) {
                continue;
            }
            $table = $def['table'] ?? null;
            $fk = $def['foreign_key'] ?? null;
            if (! $table || ! $fk) {
                continue;
            }
            $fields = array_keys($def['fields'] ?? []);
            $tenantKey = $def['tenant_key'] ?? 'company_id';
            $existingIds = DB::table($table)->where($fk, $model->getKey())->pluck('id')->map(fn ($i) => (int) $i)->all();
            $kept = [];
            foreach ($form->{$handle} as $row) {
                $payload = array_intersect_key($row, array_flip($fields));
                $payload[$fk] = $model->getKey();
                if ($tenantKey && SchemaCache::hasColumn($table, $tenantKey) && $model->getAttribute($tenantKey) !== null) {
                    $payload[$tenantKey] = $model->getAttribute($tenantKey);
                }
                $rowId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
                if ($rowId !== null && in_array($rowId, $existingIds, true)) {
                    if (SchemaCache::hasColumn($table, 'updated_at')) {
                        $payload['updated_at'] = now();
                    }
                    DB::table($table)->where('id', $rowId)->where($fk, $model->getKey())->update($payload);
                    $kept[] = $rowId;
                } else {
                    if (SchemaCache::hasColumn($table, 'created_at')) {
                        $payload['created_at'] = now();
                    }
                    if (SchemaCache::hasColumn($table, 'updated_at')) {
                        $payload['updated_at'] = now();
                    }
                    $kept[] = (int) DB::table($table)->insertGetId($payload);
                }
            }
            $stale = array_diff($existingIds, $kept);
            if ($stale !== []) {
                DB::table($table)->where($fk, $model->getKey())->whereIn('id', $stale)->delete();
            }
        }
    }

    /** Load child rows into the form DTO so edit forms render existing line items. */
    protected function attachRepeaterRows(IBusinessObject $form, Model $model): void
    {
        foreach ($this->repeaterDefinitions() as $handle => $def) {
            if (! property_exists($form, $handle)) {
                continue;
            }
            $table = $def['table'] ?? null;
            $fk = $def['foreign_key'] ?? null;
            if (! $table || ! $fk) {
                continue;
            }
            $fields = array_keys($def['fields'] ?? []);
            try {
                $rows = DB::table($table)->where($fk, $model->getKey())->orderBy('id')->get()
                    ->map(fn ($r) => array_intersect_key((array) $r, array_flip(array_merge(['id'], $fields))))->all();
            } catch (\Throwable $e) {
                $rows = [];
            }
            $form->{$handle} = $rows;
        }
    }

    protected function deleteRepeaterRows(Model $model): void
    {
        foreach ($this->repeaterDefinitions() as $def) {
            $table = $def['table'] ?? null;
            $fk = $def['foreign_key'] ?? null;
            if ($table && $fk) {
                try {
                    DB::table($table)->where($fk, $model->getKey())->delete();
                } catch (\Throwable $e) {
                    // Child cleanup is best-effort; FK cascades may already handle it
                }
            }
        }
    }

    public function findModel(string|int $id): ?Model
    {
        return $this->newQuery()->find($id);
    }

    public function getItemById(string|int $id): ?IBusinessObject
    {
        $model = $this->newQuery()->find($id);

        if (! $model) {
            return null;
        }

        $form = $this->mapToForm($model);
        $this->attachRepeaterRows($form, $model);

        return $form;
    }

    public function delete(string|int $id): bool
    {
        $model = $this->newQuery()->find($id);

        if (! $model) {
            return false;
        }

        return DB::transaction(function () use ($model, $id) {
            $this->beforeDelete($model);
            $this->deleteRepeaterRows($model);
            $result = (bool) $model->delete();
            $this->afterDelete($id);

            return $result;
        });
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
        $items = $models->map(fn ($m) => $this->mapToListing($m))->all();

        return new PagedDataList($items, $totalCount, $page, $limit);
    }

    protected function applyFilters(Builder $query, IFilterObject $filter): void
    {
        if ($search = $filter->getSearch()) {
            $this->applySearch($query, $search);
        }

        // Declared-filter criteria (G5): the controller only ever sets
        // keys the slice manifest declares, so applying them generically
        // here is safe — equality, whereIn for arrays, and the
        // `<handle>_from` / `<handle>_to` range convention.
        if (method_exists($filter, 'getCriteria')) {
            $model = null;
            try {
                $modelClass = $this->getModelClass();
                $model = new $modelClass;
            } catch (\Throwable $e) {
                $model = null;
            }
            foreach ($filter->getCriteria() as $key => $value) {
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                // Relation-exists criteria (translated by the
                // controller from type: relation_exists filters).
                if (str_starts_with($key, '__exists:')) {
                    $parts = explode('.', substr($key, strlen('__exists:')), 2);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    [$relation, $relKey] = $parts;
                    try {
                        $modelClass = $this->getModelClass();
                        $probe = new $modelClass;
                        if (! method_exists($probe, $relation)) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                    // Qualify the key with the related table: an
                    // unqualified 'id' is ambiguous across pivot joins.
                    $qualified = $relKey;
                    try {
                        $relTable = $probe->{$relation}()->getRelated()->getTable();
                        if (! str_contains($relKey, '.')) {
                            $qualified = $relTable.'.'.$relKey;
                        }
                    } catch (\Throwable $e) {
                        // Keep the bare key.
                    }
                    if (is_string($value) && str_starts_with($value, '!')) {
                        $neg = substr($value, 1);
                        $query->whereDoesntHave($relation, fn ($q) => $q->where($qualified, $neg));
                    } else {
                        $query->whereHas($relation, fn ($q) => $q->where($qualified, $value));
                    }
                    continue;
                }
                // A declared filter whose column the table does not have
                // (stale manifest, partial port) is skipped, never a 500.
                // The check asks the model's own connection; if it cannot
                // run at all, the criterion still applies (fail open).
                $column = str_ends_with($key, '_from') ? substr($key, 0, -5) : (str_ends_with($key, '_to') ? substr($key, 0, -3) : $key);
                if ($model !== null && ! in_array($column, $model->getFillable(), true)) {
                    try {
                        if (! $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        // Schema check unavailable — apply the criterion.
                    }
                }
                if (str_ends_with($key, '_from')) {
                    $query->where(substr($key, 0, -5), '>=', $value);
                } elseif (str_ends_with($key, '_to')) {
                    $query->where(substr($key, 0, -3), '<=', $value);
                } elseif (is_array($value)) {
                    // '!'-prefixed entries exclude (fidelity defaults
                    // like "hide completed" ride on this).
                    $include = [];
                    $exclude = [];
                    foreach ($value as $v) {
                        if (is_string($v) && str_starts_with($v, '!')) {
                            $exclude[] = substr($v, 1);
                        } else {
                            $include[] = $v;
                        }
                    }
                    if ($include !== []) {
                        $query->whereIn($key, $include);
                    }
                    if ($exclude !== []) {
                        $query->whereNotIn($key, $exclude);
                    }
                } elseif (is_string($value) && str_starts_with($value, '!')) {
                    $query->whereNotIn($key, explode(',', substr($value, 1)));
                } else {
                    $query->where($key, $value);
                }
            }
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
            return SchemaCache::hasColumn($model->getTable(), $column);
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
