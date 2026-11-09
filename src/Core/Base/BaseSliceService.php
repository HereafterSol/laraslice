<?php

namespace LaraSlice\Core\Base;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Core\Contracts\IFilterObject;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Core\Discovery\UpdateSemantics;
use LaraSlice\Support\DocumentMath;
use LaraSlice\Support\SchemaCache;
use Symfony\Component\Yaml\Yaml;

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
            if (empty($form->id)) {
                $this->applyDeclaredDefaults($form);
            }
            $this->applyDeclaredNumbering($form);
            $this->applyDeclaredSlugs($form);
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

            // Update semantics are declared in the Heart
            // (Proposal #6): 'patch' — the framework default and
            // the behaviour updates have always had — writes
            // only what the request sent, so partial updates
            // keep other values. 'replace' makes the request
            // body the record's new state: the completeness
            // check runs first (a partial body is rejected,
            // naming the missing fields), then every writable
            // field is written.
            if (! $isNew && $form instanceof BaseFormBusinessObject && $form->providedFields() !== null) {
                if ($this->updateSemantics()['mode'] === 'replace') {
                    $this->assertReplaceComplete($form);
                } else {
                    $data = array_intersect_key($data, array_flip($form->providedFields()));
                }
            }

            // Server-computed document totals stay authoritative
            // even though the client never submits them (the form
            // renders no subtotal/total input to provide): without
            // this, the intersection above keeps the stale stored
            // totals while the line items change underneath them.
            foreach ($this->repeaterDefinitions() as $def) {
                $document = $def['document'] ?? null;
                if (! is_array($document)) {
                    continue;
                }
                foreach (['subtotal_field', 'total_field'] as $key) {
                    $field = $document[$key] ?? null;
                    if ($field && property_exists($form, (string) $field)) {
                        $data[(string) $field] = $form->{(string) $field};
                    }
                }
            }

            // Populate model attributes
            $model->fill($data);
            $this->prepareModelForSave($form, $model, $isNew);
            $this->applyComputedFields($model);
            $model->save();
            $this->applyDeclaredExclusivity($model);

            $this->afterSave($form, $model, $isNew);
            $this->syncRepeaters($form, $model);
            $this->syncRelationMulti($form, $model);

            return $model->getKey();
        });
    }

    /**
     * This slice's update semantics, resolved from its Heart
     * through the one shared resolver (UpdateSemantics) so the
     * enforced behaviour and the projected contract agree.
     *
     * @return array{mode: 'patch'|'replace', declared: bool}
     */
    protected function updateSemantics(): array
    {
        return UpdateSemantics::fromManifest($this->ownManifest());
    }

    /**
     * Replace-semantics completeness (Proposal #6): under a
     * declared `update: replace`, every writable declared
     * field must be present in the update body. Missing fields
     * reject the write with one issue each — the caller learns
     * exactly what a complete body needs.
     *
     * Not counted as missing (the client does not own them):
     * the id; repeater collections and relation-multi
     * selections (synced through their own channels); document
     * subtotal/total fields (server-computed); fields the
     * Heart itself supplies semantics for (`default:` —
     * declaration-supplied, `computed:` — server-derived).
     * A Heart declaring no fields in either shape (the
     * top-level `fields:` map or the root model's field
     * list) gives the check nothing to judge against and
     * it does not engage.
     */
    protected function assertReplaceComplete(BaseFormBusinessObject $form): void
    {
        $declared = $this->declaredFieldDefs();
        if ($declared === []) {
            return;
        }

        $serverOwned = ['id' => true];
        foreach (array_keys($this->repeaterDefinitions()) as $handle) {
            $serverOwned[(string) $handle] = true;
        }
        foreach (array_keys($this->relationMultiDefinitions()) as $handle) {
            $serverOwned[(string) $handle] = true;
        }
        foreach ($this->repeaterDefinitions() as $def) {
            $document = is_array($def) ? ($def['document'] ?? null) : null;
            if (is_array($document)) {
                foreach (['subtotal_field', 'total_field'] as $key) {
                    if (! empty($document[$key])) {
                        $serverOwned[(string) $document[$key]] = true;
                    }
                }
            }
        }

        $provided = array_flip($form->providedFields() ?? []);
        $issues = [];
        foreach ($declared as $name => $def) {
            $name = (string) $name;
            if (isset($serverOwned[$name]) || ! property_exists($form, $name)) {
                continue;
            }
            // A `default:` supplies the field's semantics only
            // when it carries an actual value — generated
            // Hearts emit `default: null` as a shape filler on
            // every field, and applyDeclaredDefaults itself
            // treats a null default as no default.
            if (is_array($def) && ((array_key_exists('default', $def) && $def['default'] !== null) || array_key_exists('computed', $def))) {
                continue;
            }
            if (! isset($provided[$name])) {
                $issues[] = [
                    'field' => $name,
                    'problem' => "Field '{$name}' is missing from a replace update.",
                    'expected' => "This slice declares 'update: replace' — send the complete record, including '{$name}'; or declare 'update: patch' in the slice's Heart for partial updates.",
                ];
            }
        }

        if ($issues !== []) {
            throw new BindingIssuesException($issues);
        }
    }

    /**
     * The slice's declared fields across BOTH Heart shapes —
     * the top-level `fields:` map and the root model's field
     * list — merged the way the metadata projector merges
     * them (the model list wins on conflict; the map fills).
     * A Heart may declare its fields in either shape; the
     * write path reads declarations exactly where the
     * projection does.
     *
     * @return array<string, array<string, mixed>> field name => declaration
     */
    protected function declaredFieldDefs(): array
    {
        $manifest = $this->ownManifest();
        $declared = [];
        foreach ((array) ($manifest['fields'] ?? []) as $name => $def) {
            $declared[(string) $name] = is_array($def) ? $def : [];
        }

        $root = null;
        foreach ((array) ($manifest['models'] ?? []) as $model) {
            if (! is_array($model)) {
                continue;
            }
            if ($root === null) {
                $root = $model;
            }
            if (! empty($model['root'])) {
                $root = $model;
                break;
            }
        }
        foreach ((array) ($root['fields'] ?? []) as $fieldDef) {
            if (! is_array($fieldDef)) {
                continue;
            }
            $handle = (string) ($fieldDef['handle'] ?? $fieldDef['name'] ?? '');
            if ($handle !== '') {
                $declared[$handle] = array_merge($declared[$handle] ?? [], $fieldDef);
            }
        }

        return $declared;
    }

    /**
     * Declared create defaults: the manifest's per-field
     * `default:` values, applied when a create leaves the
     * field empty — omitted from the request entirely or
     * submitted blank. Status fields above all: the source
     * systems treat a new record's initial state as
     * server-owned (a leave enters as pending, a ticket as
     * open, an invoice as unpaid), and a NULL status silently
     * drops the record out of every status-filtered list,
     * board and report. Explicit values always win, and
     * updates never see defaults — they are create semantics.
     * Living in the service (not a controller) gives the web
     * and API write paths the same behaviour, the same reason
     * document math lives here. A default must never break a
     * save: any failure to read the manifest skips silently.
     */
    protected function applyDeclaredDefaults(IBusinessObject $form): void
    {
        try {
            $manifest = $this->ownManifest();
            foreach ((array) ($manifest['fields'] ?? []) as $name => $def) {
                if (! is_array($def) || ! array_key_exists('default', $def)) {
                    continue;
                }
                $default = $def['default'];
                if ($default === null || is_array($default) || ! property_exists($form, (string) $name)) {
                    continue;
                }
                $current = $form->{$name};
                if ($current !== null && $current !== '') {
                    continue;
                }
                $prop = new \ReflectionProperty($form, (string) $name);
                $type = $prop->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                $form->{$name} = match ($typeName) {
                    'int' => (int) $default,
                    'float' => (float) $default,
                    'bool' => filter_var($default, FILTER_VALIDATE_BOOLEAN),
                    'string' => (string) $default,
                    default => $default,
                };
            }
        } catch (\Throwable $e) {
            // A default must never break a save.
        }
    }

    /**
     * Declared document numbering (C1): a manifest field may
     * carry `numbering: <kind>` (contract / invoice / estimate /
     * credit_note / order) — on CREATE, the entered raw number
     * is rewritten through the invoice_settings row the hub
     * edits (prefix + separator + zero-padding), exactly as the
     * source's store requests rewrite it through its
     * NumberFormat helper. Updates keep the stored value (the
     * source's update path takes the number as-is). When the
     * form also carries the conventional `original_{field}`
     * property, it receives the raw part — the source observer's
     * derivation. A numbering failure must never break a save.
     */
    protected function applyDeclaredNumbering(IBusinessObject $form): void
    {
        if (! empty($form->id)) {
            return;
        }
        try {
            foreach ((array) ($this->ownManifest()['fields'] ?? []) as $name => $def) {
                $kind = is_array($def) ? ($def['numbering'] ?? null) : null;
                if (! is_string($kind) || $kind === '' || ! property_exists($form, (string) $name)) {
                    continue;
                }
                $raw = $form->{$name};
                if (! is_scalar($raw) || (string) $raw === '') {
                    continue;
                }
                $formatted = \LaraSlice\Support\Numbering::format($kind, $raw);
                $form->{$name} = $formatted;
                $originalProp = 'original_'.(string) $name;
                if (property_exists($form, $originalProp)) {
                    $form->{$originalProp} = \LaraSlice\Support\Numbering::originalPart($kind, $formatted);
                }
            }
        } catch (\Throwable $e) {
            // Numbering must never break a save.
        }
    }

    /**
     * Declared slug derivation: a manifest field may carry
     * `slug_from: <field>` (with optional `slug_scope: <field>`)
     * — the field's value is the slug of the source field, made
     * unique within the scope against the model's own table
     * with -1, -2, … suffixes. This is the source's
     * generateUniqueSlug rule for its custom-field names: the
     * admin names the label, never the slug. Regenerated on
     * update when the source field participates in the save
     * (the source regenerates on update too). A slug failure
     * must never break a save.
     */
    protected function applyDeclaredSlugs(IBusinessObject $form): void
    {
        try {
            foreach ((array) ($this->ownManifest()['fields'] ?? []) as $name => $def) {
                if (! is_array($def)) {
                    continue;
                }
                $from = $def['slug_from'] ?? null;
                if (! is_string($from) || $from === ''
                    || ! property_exists($form, (string) $name)
                    || ! property_exists($form, $from)) {
                    continue;
                }
                if (! empty($form->id)
                    && $form instanceof BaseFormBusinessObject
                    && $form->providedFields() !== null
                    && ! $form->provided($from)) {
                    continue;
                }
                $label = $form->{$from};
                if (! is_scalar($label) || trim((string) $label) === '') {
                    continue;
                }
                $slug = \Illuminate\Support\Str::slug((string) $label);
                if ($slug === '') {
                    $slug = 'field';
                }
                $modelClass = $this->getModelClass();
                $table = (new $modelClass)->getTable();
                $scope = $def['slug_scope'] ?? null;
                $scopeValue = is_string($scope) && $scope !== '' && property_exists($form, $scope)
                    ? $form->{$scope}
                    : null;
                $candidate = $slug;
                $i = 1;
                while (true) {
                    $query = DB::table($table)->where((string) $name, $candidate);
                    if (is_string($scope) && $scope !== '' && $scopeValue !== null) {
                        $query->where($scope, $scopeValue);
                    }
                    if (! empty($form->id)) {
                        $query->where('id', '<>', $form->id);
                    }
                    if (! $query->exists()) {
                        break;
                    }
                    $candidate = $slug.'-'.$i;
                    $i++;
                }
                $form->{$name} = $candidate;
            }
        } catch (\Throwable $e) {
            // A slug must never break a save.
        }
    }

    /**
     * Declared flag exclusivity (C2): a manifest field may
     * carry `exclusive: true` — when a record is saved with the
     * flag ON, every sibling row's flag is cleared (scoped to
     * the same company when the table is company-scoped). This
     * is the source's set-default behaviour (its business
     * address controller clears is_default on all rows before
     * setting the chosen one): a default is a radio, not a
     * checkbox. An exclusivity failure must never break a save.
     */
    protected function applyDeclaredExclusivity(Model $model): void
    {
        try {
            foreach ((array) ($this->ownManifest()['fields'] ?? []) as $name => $def) {
                if (! is_array($def) || empty($def['exclusive'])) {
                    continue;
                }
                $name = (string) $name;
                if (! $this->modelHasColumn($model, $name)) {
                    continue;
                }
                $value = $model->{$name};
                if (! ($value === 1 || $value === '1' || $value === true || $value === 'yes')) {
                    continue;
                }
                $query = DB::table($model->getTable())->where('id', '<>', $model->getKey());
                if ($this->modelHasColumn($model, 'company_id') && $model->company_id !== null) {
                    $query->where('company_id', $model->company_id);
                }
                $query->update([$name => 0]);
            }
        } catch (\Throwable $e) {
            // Exclusivity must never break a save.
        }
    }

    /** This slice's own manifest (slice.yaml beside the slice's Services directory), or []. */
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
            // A missing manifest means no declared defaults; never fail save().
        }

        return [];
    }

    /**
     * Declared computed fields: a manifest field may carry a
     * `computed:` spec naming the derivation the server owns —
     * the value is never taken from the request (a hand-typed
     * or blank value would silently corrupt every aggregate
     * built on it; the comparative test found port-created
     * timelogs storing total_hours NULL, vanishing from the
     * hour totals reports rely on). Supported spec:
     * `hours_between(start_field, end_field)` — decimal hours
     * between two datetime attributes, rounded to 2dp, on both
     * create and update (an edited end time recomputes). An
     * unparseable endpoint leaves the stored value untouched.
     */
    protected function applyComputedFields(Model $model): void
    {
        try {
            foreach ((array) ($this->ownManifest()['fields'] ?? []) as $name => $def) {
                $spec = is_array($def) ? ($def['computed'] ?? null) : null;
                if (! is_string($spec) || ! $this->modelHasColumn($model, (string) $name)) {
                    continue;
                }
                if (preg_match('/^hours_between\(\s*([a-z0-9_]+)\s*,\s*([a-z0-9_]+)\s*\)$/', trim($spec), $m) !== 1) {
                    continue;
                }
                $start = $model->getAttribute($m[1]);
                $end = $model->getAttribute($m[2]);
                if (empty($start) || empty($end)) {
                    continue;
                }
                $startTs = is_numeric($start) ? (int) $start : strtotime((string) $start);
                $endTs = is_numeric($end) ? (int) $end : strtotime((string) $end);
                if ($startTs === false || $endTs === false) {
                    continue;
                }
                $model->setAttribute((string) $name, round(($endTs - $startTs) / 3600, 2));
            }
        } catch (\Throwable $e) {
            // A computed field must never break a save.
        }
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
                    // A boolean's hidden companion submits '0' even
                    // for the untouched starter row; on its own that
                    // is not content (a kept starter row would make
                    // every other field's required rule block a
                    // create that never touched the repeater).
                    if (($def['fields'][$f]['type'] ?? 'string') === 'boolean' && ($v === '0' || $v === 0 || $v === false)) {
                        continue;
                    }
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
            // A declared repeater whose table does not carry the
            // parent foreign key is not a child collection at all
            // (a belongsTo lookup modelled as a repeater by the
            // porter). The read/delete paths already tolerate it;
            // the save path must skip it too instead of failing
            // the whole save with an unknown-column error.
            if (! SchemaCache::hasColumn($table, $fk)) {
                continue;
            }
            $fields = array_keys($def['fields'] ?? []);
            $tenantKey = $def['tenant_key'] ?? 'company_id';
            $existingIds = DB::table($table)->where($fk, $model->getKey())->pluck('id')->map(fn ($i) => (int) $i)->all();
            $kept = [];
            foreach ($form->{$handle} as $row) {
                $payload = array_intersect_key($row, array_flip($fields));
                // The form always submits its blank template row;
                // a row with no entered value is the repeater's
                // empty state, not data. Inserting it writes a junk
                // child row — and fails outright when the table has
                // a NOT NULL column the form does not carry.
                // Boolean/toggle fields always submit a value, so
                // they can never make a row "have content" alone;
                // '0' in a content field IS a value, not emptiness.
                $hasValue = false;
                foreach ($payload as $field => $value) {
                    $type = $def['fields'][$field]['type'] ?? null;
                    if (in_array($type, ['boolean', 'bool', 'toggle', 'checkbox'], true)) {
                        continue;
                    }
                    if ($value !== null && $value !== '' && ! (is_string($value) && trim($value) === '')) {
                        $hasValue = true;
                        break;
                    }
                }
                if (! $hasValue) {
                    continue;
                }
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

            // Header discount: resolved from the declared header
            // fields into the pure helper's inputs.
            $discountField = $document['discount_field'] ?? null;
            if ($discountField && property_exists($form, (string) $discountField)) {
                $document['discount'] = $form->{(string) $discountField};
            }
            $discountTypeField = $document['discount_type_field'] ?? null;
            if ($discountTypeField && property_exists($form, (string) $discountTypeField)) {
                $document['discount_type'] = $form->{(string) $discountTypeField};
            }

            // Per-row tax: the row field carries tax ids (the
            // source systems store them as a JSON list); rates
            // live in the declared tax table. Each row's tax
            // amount is computed here (the service owns the DB)
            // and handed to the pure helper through a virtual
            // row key that the repeater sync never persists.
            $taxField = $document['tax_field'] ?? null;
            $taxTable = $document['tax_table'] ?? null;
            if ($taxField && $taxTable) {
                $rows = $this->resolveDocumentTaxes($rows, $document, $form);
                $document['tax_amount'] = $document['tax_amount'] ?? '__tax_amount';
            }

            $result = DocumentMath::apply($rows, $document);
            $form->{$handle} = $result['rows'];
            foreach (['subtotal_field' => 'subtotal', 'total_field' => 'total'] as $key => $value) {
                $field = $document[$key] ?? null;
                if ($field && property_exists($form, (string) $field)) {
                    $form->{(string) $field} = $result[$value];
                }
            }
            // A new document owes its full total (the source sets
            // due = total at creation; payments reduce it later,
            // so updates never re-derive it here).
            $dueField = $document['due_field'] ?? null;
            if ($dueField && empty($form->id) && property_exists($form, (string) $dueField)) {
                $form->{(string) $dueField} = $result['total'];
            }
        }
    }

    /**
     * Compute each row's tax amount from the declared tax
     * table's rates. Mode (from the declared header field,
     * default 'after_discount', the source systems' norm):
     * after_discount taxes the discounted share of each line,
     * before_discount taxes the full line.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $document
     * @return array<int, array<string, mixed>>
     */
    protected function resolveDocumentTaxes(array $rows, array $document, IBusinessObject $form): array
    {
        $taxField = (string) $document['tax_field'];
        $rateColumn = (string) ($document['tax_rate'] ?? 'rate_percent');
        try {
            $rates = DB::table((string) $document['tax_table'])->pluck($rateColumn, 'id')->all();
        } catch (\Throwable $e) {
            return $rows;
        }

        $qty = (string) ($document['quantity'] ?? 'quantity');
        $price = (string) ($document['unit_price'] ?? 'unit_price');
        $subtotal = 0.0;
        foreach ($rows as $row) {
            $subtotal += round((is_numeric($row[$qty] ?? null) ? (float) $row[$qty] : 0.0)
                * (is_numeric($row[$price] ?? null) ? (float) $row[$price] : 0.0), 2);
        }
        $discountAmount = 0.0;
        $discount = is_numeric($document['discount'] ?? null) ? (float) $document['discount'] : 0.0;
        if ($discount > 0 && $subtotal > 0) {
            $discountAmount = ($document['discount_type'] ?? 'amount') === 'percent'
                ? $subtotal * $discount / 100
                : $discount;
        }
        $modeField = $document['tax_mode_field'] ?? null;
        $mode = $modeField && property_exists($form, (string) $modeField)
            ? (string) ($form->{(string) $modeField} ?? 'after_discount')
            : 'after_discount';
        $ratio = ($mode === 'after_discount' && $subtotal > 0)
            ? max(0.0, ($subtotal - $discountAmount) / $subtotal)
            : 1.0;

        foreach ($rows as $i => $row) {
            $raw = $row[$taxField] ?? null;
            $ids = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $ids = is_array($decoded) ? $decoded : array_map('trim', explode(',', $raw));
            } elseif (is_array($raw)) {
                $ids = $raw;
            }
            $rateSum = 0.0;
            foreach ($ids as $id) {
                if (is_numeric($id) && isset($rates[(int) $id])) {
                    $rateSum += (float) $rates[(int) $id];
                }
            }
            $line = round((is_numeric($row[$qty] ?? null) ? (float) $row[$qty] : 0.0)
                * (is_numeric($row[$price] ?? null) ? (float) $row[$price] : 0.0), 2);
            $rows[$i]['__tax_amount'] = round($line * $ratio * $rateSum / 100, 2);
        }

        return $rows;
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
        $collection = $models instanceof Collection
            ? $models
            : new Collection($models);
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
            // A declared/default sort field that is not a real
            // column on this table (stale manifest, computed
            // handle) degrades to natural order instead of a
            // SQL error. Checked through the model's own
            // connection (no facade — services also run in
            // bare unit contexts); any failure applies the sort
            // as before.
            $sortField = $filter->getSortBy();
            $applies = true;
            try {
                $sortModel = $query->getModel();
                $applies = $sortModel->getConnection()->getSchemaBuilder()->hasColumn($sortModel->getTable(), $sortField);
            } catch (\Throwable) {
                $applies = true;
            }
            if ($applies) {
                $query->orderBy($sortField, $filter->isSortDesc() ? 'desc' : 'asc');
            }
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
                            } elseif (! empty($clause['pivot']) && ! empty($clause['foreign_key'])) {
                                // Membership pivot clause: an
                                // EXISTS row in the pivot table
                                // linking this record to the
                                // viewer (project_members,
                                // task_users, event_attendees…).
                                $pivot = (string) $clause['pivot'];
                                $fk = (string) $clause['foreign_key'];
                                $userKey = (string) ($clause['user_key'] ?? 'user_id');
                                $mainTable = $model !== null ? $model->getTable() : null;
                                if ($mainTable !== null && \Illuminate\Support\Facades\Schema::hasTable($pivot)) {
                                    $group->orWhereExists(function ($q) use ($pivot, $fk, $userKey, $mainTable, $clause) {
                                        $q->select(\Illuminate\Support\Facades\DB::raw(1))
                                            ->from($pivot)
                                            ->whereColumn("{$pivot}.{$fk}", "{$mainTable}.id")
                                            ->where("{$pivot}.{$userKey}", $clause['value']);
                                    });
                                }
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
            // Declared auth stamps: a field whose Heart entry
            // carries `stamp: auth` is server-owned on create —
            // the acting user fills it whenever the client left
            // it empty (a ticket reply's author, posted from a
            // hub tab that submits only the body). An explicitly
            // submitted value always wins.
            if ($isNew) {
                try {
                    foreach (($this->ownManifest()['fields'] ?? []) as $fieldName => $fieldDef) {
                        if (is_array($fieldDef) && ($fieldDef['stamp'] ?? null) === 'auth'
                            && empty($model->{$fieldName}) && $this->modelHasColumn($model, (string) $fieldName)) {
                            $model->{$fieldName} = $userId;
                        }
                    }
                } catch (\Throwable $e) {
                    // A manifest that cannot be read never breaks a save.
                }
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
