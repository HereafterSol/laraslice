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
use LaraSlice\Support\DocumentMath;
use LaraSlice\Support\SchemaCache;

abstract class BaseSliceService implements IFormDataService, IListingDataService
{
    abstract protected function getModelClass(): string;

    /**
     * Public accessor for the slice's model class — the
     * abstract stays protected (each service pins its own
     * model), but framework callers (declared routes like
     * the clock punch) legitimately need the class.
     */
    public function modelClass(): string
    {
        return $this->getModelClass();
    }

    abstract protected function mapToForm(Model $model): IBusinessObject;

    abstract protected function mapToListing(Model $model): IBusinessObject;

    public function save(IBusinessObject $form): string|int
    {
        return DB::transaction(function () use ($form) {
            $this->normalizeRepeaterPayloads($form);
            $this->validateRepeaterPayloads($form);
            $this->applyDocumentMath($form);
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
            // Relation-multi (BelongsToMany) selections likewise
            foreach (array_keys($this->relationMultiDefinitions()) as $multiHandle) {
                unset($data[$multiHandle]);
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
            $this->syncRelationMulti($form, $model);

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

    /**
     * Relation-multi (BelongsToMany) form selections declared in the
     * manifest's form sections (`type: relation_multi`). Generated
     * services override this; shape per handle:
     * ['relation' => string] — the model's BelongsToMany method the
     * selection syncs to. The form BO carries the selection as an
     * int-id array under the handle; it is never a model column.
     *
     * @return array<string, array<string, mixed>>
     */
    public function relationMultiDefinitions(): array
    {
        return [];
    }

    /**
     * Sync declared BelongsToMany selections from the form BO onto
     * the saved model. Updates only touch selections the request
     * actually provided, so partial API updates keep the rest.
     */
    protected function syncRelationMulti(IBusinessObject $form, Model $model): void
    {
        foreach ($this->relationMultiDefinitions() as $handle => $def) {
            if (! property_exists($form, $handle)) {
                continue;
            }
            if ($form instanceof BaseFormBusinessObject && $form->providedFields() !== null && ! $form->provided($handle)) {
                continue;
            }
            $relation = is_array($def) ? (string) ($def['relation'] ?? $handle) : $handle;
            if (! method_exists($model, $relation)) {
                continue;
            }
            $ids = array_values(array_unique(array_filter(
                array_map('intval', (array) $form->{$handle}),
                fn (int $id) => $id > 0
            )));
            $model->{$relation}()->sync($ids);
        }
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

    /**
     * Document arithmetic (backlog D3): for each repeater
     * whose definition carries a `document` block, recompute
     * line amounts from quantity × unit price and write the
     * subtotal / grand total onto the form's declared header
     * fields. Client-submitted amounts are never trusted;
     * {@see DocumentMath} is the single implementation,
     * mirrored live by the form component.
     */
    protected function applyDocumentMath(IBusinessObject $form): void
    {
        foreach ($this->repeaterDefinitions() as $handle => $def) {
            $document = $def['document'] ?? null;
            if (! is_array($document) || ! $this->repeaterRowsProvided($form, $handle)) {
                continue;
            }
            $rows = is_array($form->{$handle} ?? null) ? $form->{$handle} : [];
            $result = DocumentMath::apply($rows, $document);
            $form->{$handle} = $result['rows'];
            foreach (['subtotal_field' => 'subtotal', 'total_field' => 'total'] as $key => $value) {
                $field = $document[$key] ?? null;
                if ($field && property_exists($form, (string) $field)) {
                    $form->{(string) $field} = $result[$value];
                }
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

    /**
     * Record action (L3): duplicate a record with its repeater
     * children. The copy's first human label column (title /
     * name / subject / code / number) gains a " (copy)" suffix
     * so unique columns and list displays stay distinguishable.
     * Returns the new model, or null when the record is gone.
     */
    public function duplicateRecord(string|int $id): ?Model
    {
        $model = $this->findModel($id);
        if (! $model) {
            return null;
        }

        return DB::transaction(function () use ($model) {
            $copy = $model->replicate();
            foreach (['title', 'name', 'heading', 'subject', 'code', 'number'] as $column) {
                if ($this->modelHasColumn($model, $column) && is_string($model->{$column}) && $model->{$column} !== '') {
                    $copy->{$column} = $model->{$column}.' (copy)';
                    break;
                }
            }
            $copy->save();

            foreach ($this->repeaterDefinitions() as $definition) {
                $table = $definition['table'] ?? null;
                $foreignKey = $definition['foreign_key'] ?? null;
                if (! $table || ! $foreignKey) {
                    continue;
                }
                foreach (DB::table($table)->where($foreignKey, $model->getKey())->get() as $row) {
                    $data = (array) $row;
                    unset($data['id']);
                    $data[$foreignKey] = $copy->getKey();
                    DB::table($table)->insert($data);
                }
            }

            return $copy;
        });
    }

    /**
     * Record action (L3): move a record through a declared
     * state transition — set one column to one declared value.
     * Refuses silently-unknown columns (returns false) so a
     * stale declaration can never write arbitrary fields.
     */
    public function transitionRecord(string|int $id, string $field, mixed $value): bool
    {
        $model = $this->findModel($id);
        if (! $model || ! $this->modelHasColumn($model, $field)) {
            return false;
        }
        $model->{$field} = $value;

        return (bool) $model->save();
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

    /**
     * Bulk delete (L4): the directory's native bulk action.
     * Each id goes through delete() so hooks and repeater
     * cleanup apply exactly as for a single delete; missing
     * ids are skipped. Returns the number deleted.
     *
     * @param  array<int, string|int>  $ids
     */
    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            if ((is_int($id) || (is_string($id) && $id !== '')) && $this->delete($id)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Decorate already-fetched models through this slice's listing
     * mapping (mapToListing), applying the same eager loads the
     * directory query declares. Hub tabs (H1) use this so a related
     * record renders in a tab exactly as it does in its own
     * directory — computed codes, assignee stacks, logged-hour
     * aggregates and all — instead of raw storage attributes.
     *
     * @param  iterable<Model>  $models
     * @return list<IBusinessObject>
     */
    public function listingBusinessObjects(iterable $models): array
    {
        $collection = $models instanceof \Illuminate\Database\Eloquent\Collection
            ? $models
            : new \Illuminate\Database\Eloquent\Collection($models);
        if ($collection->isNotEmpty()) {
            try {
                $eager = array_keys($this->newQuery()->getEagerLoads());
                if ($eager !== []) {
                    $collection->loadMissing($eager);
                }
            } catch (\Throwable $e) {
                // Eager-load decoration is best-effort; raw mapping still applies.
            }
        }

        return $collection->map(fn ($m) => $this->mapToListing($m))->all();
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
                    // The relation may be a dotted PATH
                    // (user.employeeDetail.designation_id): the
                    // last segment is the column, everything
                    // before it is the relation path whereHas
                    // walks natively.
                    $parts = explode('.', substr($key, strlen('__exists:')));
                    if (count($parts) < 2) {
                        continue;
                    }
                    $relKey = (string) array_pop($parts);
                    $relation = implode('.', $parts);
                    try {
                        $modelClass = $this->getModelClass();
                        $probe = new $modelClass;
                        if (! method_exists($probe, $parts[0])) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                    // Qualify the key with the related table: an
                    // unqualified 'id' is ambiguous across pivot
                    // joins. Single-hop only — on a dotted path
                    // the where runs against the FINAL relation's
                    // table, so the bare column is correct there.
                    $qualified = $relKey;
                    if (! str_contains($relation, '.')) {
                        try {
                            $relTable = $probe->{$relation}()->getRelated()->getTable();
                            $qualified = $relTable.'.'.$relKey;
                        } catch (\Throwable $e) {
                            // Keep the bare key.
                        }
                    }
                    if (is_string($value) && str_starts_with($value, '!')) {
                        $neg = substr($value, 1);
                        $query->whereDoesntHave($relation, fn ($q) => $q->where($qualified, $neg));
                    } else {
                        $query->whereHas($relation, fn ($q) => $q->where($qualified, $value));
                    }
                    continue;
                }
                // Own-scope alternatives (Body v2 access): the
                // controller resolves the slice's declared
                // access.own_scope clauses against the viewer;
                // a record is theirs when ANY clause matches
                // (a project's client via its client_id column,
                // an employee via the members relation, an
                // invoice via its project's client). Applied
                // as one grouped OR so the alternatives never
                // widen each other.
                if ($key === '__own_any' && is_array($value)) {
                    $query->where(function ($group) use ($value, $model) {
                        foreach ($value as $clause) {
                            if (! is_array($clause) || ! isset($clause['value'])) {
                                continue;
                            }
                            if (! empty($clause['field'])) {
                                $group->orWhere((string) $clause['field'], $clause['value']);
                            } elseif (! empty($clause['relation'])) {
                                $relation = (string) $clause['relation'];
                                $relKey = (string) ($clause['key'] ?? 'id');
                                $qualified = $relKey;
                                if ($model !== null && ! str_contains($relation, '.') && method_exists($model, $relation)) {
                                    try {
                                        $qualified = $model->{$relation}()->getRelated()->getTable().'.'.$relKey;
                                    } catch (\Throwable $e) {
                                        // Keep the bare key.
                                    }
                                }
                                $group->orWhereHas($relation, fn ($q) => $q->where($qualified, $clause['value']));
                            }
                        }
                    });
                    continue;
                }
                // Option-criteria conditions (fidelity): the
                // controller translated a chosen filter option's
                // declared `criteria` into this list; applied as
                // one AND group. Ops: eq, neq, lt, lte, gt, gte,
                // between, in, not_in, like. A condition naming a
                // column the table lacks is skipped, never a 500.
                if ($key === '__conditions' && is_array($value)) {
                    $query->where(function ($group) use ($value, $model) {
                        foreach ($value as $cond) {
                            if (! is_array($cond) || empty($cond['field']) || empty($cond['op'])) {
                                continue;
                            }
                            $field = (string) $cond['field'];
                            if ($model !== null) {
                                try {
                                    if (! $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $field)) {
                                        continue;
                                    }
                                } catch (\Throwable $e) {
                                    // Schema check unavailable — apply it.
                                }
                            }
                            $v = $cond['value'] ?? null;
                            switch ((string) $cond['op']) {
                                case 'eq':
                                    $group->where($field, $v);
                                    break;
                                case 'neq':
                                    $group->where($field, '!=', $v);
                                    break;
                                case 'lt':
                                    $group->where($field, '<', $v);
                                    break;
                                case 'lte':
                                    $group->where($field, '<=', $v);
                                    break;
                                case 'gt':
                                    $group->where($field, '>', $v);
                                    break;
                                case 'gte':
                                    $group->where($field, '>=', $v);
                                    break;
                                case 'between':
                                    if (is_array($v) && count($v) === 2) {
                                        $group->whereBetween($field, array_values($v));
                                    }
                                    break;
                                case 'in':
                                    $group->whereIn($field, (array) $v);
                                    break;
                                case 'not_in':
                                    $group->whereNotIn($field, (array) $v);
                                    break;
                                case 'like':
                                    $group->where($field, 'like', $v);
                                    break;
                            }
                        }
                    });
                    continue;
                }
                // Any other __ key is reserved-namespace noise
                // (state markers, future criteria kinds): never a
                // column criterion.
                if (str_starts_with($key, '__')) {
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
