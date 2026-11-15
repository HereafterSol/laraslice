<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Support\Directory;
use LaraSlice\Support\FieldInference;
use Symfony\Component\Yaml\Yaml;

class SliceGenerator
{
    protected string $slicesPath;

    protected string $namespace;

    protected FieldDefinitionNormalizer $fieldNormalizer;

    public function __construct(?string $slicesPath = null, ?string $namespace = null)
    {
        $this->slicesPath = $slicesPath ?: config('laraslice.slices_path', app_path('Slices'));
        $this->namespace = SliceNamespace::validate($namespace ?: config('laraslice.slices_namespace', 'App\\Slices'));
        $this->fieldNormalizer = new FieldDefinitionNormalizer;
    }

    /**
     * Evidence-based target for a belongsTo FK whose manifest
     * field carries no declared relation model. The column stem
     * alone names the wrong model whenever the target table is
     * domain-prefixed (projects.category_id -> project_category,
     * model ProjectCategory — a stem lookup for "Category" finds
     * nothing and the generated relation throws at runtime).
     * Chain: the record-prefixed table, then the conventional
     * table (plural/stem), then a UNIQUE table ending in
     * _{stem}. Declared relation metadata always wins — this
     * only fills the gap it leaves.
     */
    private function resolveForeignTargetModel(string $fkName, string $recordTable): ?string
    {
        $base = Str::replaceLast('_id', '', $fkName);
        if ($base === '' || $base === $fkName) {
            return null;
        }
        try {
            if (! function_exists('app') || ! app()->bound(SliceManager::class)) {
                return null;
            }
            $models = [];
            foreach (app(SliceManager::class)->getSlices() as $slice) {
                foreach (($slice->raw['models'] ?? []) as $m) {
                    if (! empty($m['handle']) && ! empty($m['table'])) {
                        $models[(string) $m['handle']] = (string) $m['table'];
                    }
                }
            }
            if ($models === []) {
                return null;
            }
            // Candidates in evidence order; a candidate is only
            // accepted when it RESOLVES to a real class — a manifest
            // handle can be a phantom (declared 'client', generated
            // as ClientDetail), and emitting it would birth another
            // throwing relation.
            $manager = app(SliceManager::class);
            $candidates = [];
            $prefixed = Str::singular($recordTable).'_'.$base;
            foreach ($models as $handle => $table) {
                if ($table === $prefixed) {
                    $candidates[] = Str::studly($handle);
                }
            }
            foreach ([Str::plural($base), $base] as $candidateTable) {
                foreach ($models as $handle => $table) {
                    if ($table === $candidateTable) {
                        $candidates[] = Str::studly($handle);
                    }
                }
            }
            // Fused form: taskboard_columns for tasks.board_column_id
            // (record stem + plural stem, underscores removed).
            $fused = str_replace('_', '', Str::singular($recordTable).Str::plural($base));
            foreach ($models as $handle => $table) {
                if (str_replace('_', '', $table) === $fused) {
                    $candidates[] = Str::studly($handle);
                }
            }
            $pluralPrefixed = Str::singular($recordTable).'_'.Str::plural($base);
            foreach ($models as $handle => $table) {
                if ($table === $pluralPrefixed) {
                    $candidates[] = Str::studly($handle);
                }
            }
            $suffixMatches = [];
            foreach ($models as $handle => $table) {
                if (str_ends_with($table, '_'.$base) || str_ends_with($table, '_'.Str::plural($base))) {
                    $suffixMatches[] = $handle;
                }
            }
            if (count($suffixMatches) === 1) {
                $candidates[] = Str::studly($suffixMatches[0]);
            }
            // Sibling rows of the same table family (a recurring
            // or dependent task is a task).
            $recordSingular = Str::singular($recordTable);
            if (str_ends_with($base, '_'.$recordSingular) || $base === $recordSingular) {
                foreach ($models as $handle => $table) {
                    if ($table === $recordTable) {
                        $candidates[] = Str::studly($handle);
                    }
                }
            }
            // People stems are users in this domain family (the
            // same rule the row pipeline encodes for client_id).
            if ($base === 'client' || in_array($base, ['agent', 'member', 'created_by', 'updated_by', 'added_by', 'approved_by', 'assigned_to', 'assigned_by', 'owner', 'from_user', 'to_user'], true)) {
                $candidates[] = 'User';
            }
            foreach (array_unique($candidates) as $candidate) {
                if ($manager->modelClass($candidate) !== null) {
                    return $candidate;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    public function generate(string $name, array $fields = [], bool $includeWorkflow = false, array $options = []): string
    {
        $studlyName = SliceName::canonical($name);
        $pluralName = Str::plural($studlyName);
        $snakeName = SliceName::snake($studlyName);
        $pluralSnake = SliceName::snake($pluralName);

        $tablePrefix = isset($options['table_prefix']) ? (string) $options['table_prefix'] : '';
        $tableName = isset($options['table']) && is_string($options['table']) && trim($options['table']) !== ''
            ? trim($options['table'])
            : ($tablePrefix ? $tablePrefix.$pluralSnake : $pluralSnake);

        $app = isset($options['app']) && is_string($options['app']) && trim($options['app']) !== ''
            ? trim($options['app'])
            : null;
        // Explicit super_domain creates a third nesting tier: {super}/{app}/{domain}/{slice}.
        // (Falls back to legacy behavior where super_domain was only an app alias.)
        $superDomainOpt = isset($options['super_domain']) && is_string($options['super_domain']) && trim($options['super_domain']) !== ''
            ? trim($options['super_domain'])
            : null;
        if (! $app && $superDomainOpt) {
            $app = $superDomainOpt;
            $superDomainOpt = null;
        }
        $appFolder = $app ? SliceName::domainSegment($app) : null;
        $appSlug = $app ? Str::slug($app) : null;
        $superFolder = $superDomainOpt ? SliceName::domainSegment($superDomainOpt) : null;
        $superSlug = $superDomainOpt ? Str::slug($superDomainOpt) : null;

        $domain = isset($options['domain']) && is_string($options['domain']) && trim($options['domain']) !== ''
            ? trim($options['domain'])
            : (isset($options['module']) && is_string($options['module']) ? trim($options['module']) : null);
        $domainFolder = $domain ? SliceName::domainSegment($domain) : null;
        $domainSlug = $domain ? Str::slug($domain) : null;
        $domainDot = $domainSlug ? str_replace('-', '_', $domainSlug).'.' : '';

        if ($superFolder && $appFolder && $domainFolder) {
            $routePrefix = "{$superSlug}/{$appSlug}/{$domainSlug}/{$pluralSnake}";
            $targetDir = $this->slicesPath.DIRECTORY_SEPARATOR.$superFolder.DIRECTORY_SEPARATOR.$appFolder.DIRECTORY_SEPARATOR.$domainFolder.DIRECTORY_SEPARATOR.$pluralName;
            $sliceNamespace = "{$this->namespace}\\{$superFolder}\\{$appFolder}\\{$domainFolder}\\{$pluralName}";
        } elseif ($appFolder && $domainFolder) {
            $routePrefix = "{$appSlug}/{$domainSlug}/{$pluralSnake}";
            $targetDir = $this->slicesPath.DIRECTORY_SEPARATOR.$appFolder.DIRECTORY_SEPARATOR.$domainFolder.DIRECTORY_SEPARATOR.$pluralName;
            $sliceNamespace = "{$this->namespace}\\{$appFolder}\\{$domainFolder}\\{$pluralName}";
        } elseif ($domainFolder) {
            $routePrefix = "{$domainSlug}/{$pluralSnake}";
            $targetDir = $this->slicesPath.DIRECTORY_SEPARATOR.$domainFolder.DIRECTORY_SEPARATOR.$pluralName;
            $sliceNamespace = "{$this->namespace}\\{$domainFolder}\\{$pluralName}";
        } else {
            $routePrefix = $pluralSnake;
            $targetDir = $this->slicesPath.DIRECTORY_SEPARATOR.$pluralName;
            $sliceNamespace = "{$this->namespace}\\{$pluralName}";
        }
        $navUrl = '/'.$routePrefix;
        // G6 step 2: route NAMES are path-qualified for nested slices
        // (erp/erp/work/tasks -> erp_erp_work_tasks.*) so same-named
        // slices never share route names; root slices keep the plain
        // plural name, unchanged.
        $routeNamePrefix = str_replace('/', '_', str_replace('-', '_', $routePrefix));

        $multitenant = filter_var($options['multitenant'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $tenantKey = $options['tenant_key'] ?? 'company_id';

        $isExplicitFields = ! empty($fields);
        if (! $isExplicitFields) {
            $fields = [
                ['name' => 'title', 'label' => 'Title', 'type' => 'string', 'nullable' => false, 'rules' => ['required', 'string', 'max:255']],
                ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'nullable' => true, 'rules' => ['nullable', 'string']],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'nullable' => false, 'default' => 'draft', 'rules' => ['required', 'string', 'in:draft,active,archived']],
            ];
        }

        // Companion-account creation (declared account_creation:):
        // the account's foreign key is supplied by the store when
        // the account section is filled, so the picker field must
        // not be hard-required — neither in the form markup the
        // normalizer is about to bake nor in the generated
        // validation (the controller guards the case where neither
        // path supplies a user). Applied BEFORE normalize() because
        // the normalizer pre-renders each field's form markup.
        $accountDeclOpt = is_array($options['account_creation'] ?? null) ? $options['account_creation'] : null;
        if ($accountDeclOpt !== null && ! empty($accountDeclOpt['foreign_key'])) {
            foreach ($fields as &$fieldDef) {
                if (($fieldDef['name'] ?? null) === (string) $accountDeclOpt['foreign_key']) {
                    $fieldDef['required'] = false;
                    $fieldDef['nullable'] = true;
                }
            }
            unset($fieldDef);
        }

        $fields = $this->fieldNormalizer->normalize($fields);
        $fieldsByName = [];
        foreach ($fields as $field) {
            $fieldsByName[$field['name']] = $field;
        }

        $softDeletes = (bool) ($options['soft_deletes'] ?? false);
        $migrationSoftDeletes = $softDeletes ? "\n                \$table->softDeletes();" : '';
        $encryptedColumns = array_column(array_filter($fields, fn (array $f) => ! empty($f['encrypted'])), 'name');
        // Declared per-field casts (the field-level `cast`
        // key, absorbed vocabulary 7 Nov): a field may name
        // the Eloquent cast its column carries, and the
        // generator emits it into $casts alongside the
        // encrypted casts. The encrypted flag is the
        // stronger declaration for its own columns and
        // overlays any cast named there. With no declared
        // casts this reduces to the historical expression.
        $castMap = [];
        foreach ($fields as $castField) {
            if (! empty($castField['cast']) && is_string($castField['cast'])) {
                $castMap[(string) $castField['name']] = $castField['cast'];
            }
        }
        foreach ($encryptedColumns as $encryptedColumn) {
            $castMap[$encryptedColumn] = 'encrypted';
        }
        $modelCasts = $castMap === []
            ? ''
            : "\n    protected \$casts = ".var_export($castMap, true).';';

        $titleField = $fieldsByName['title'] ?? null;
        $descriptionField = $fieldsByName['description'] ?? null;
        $statusField = $fieldsByName['status'] ?? null;

        $extraFields = array_values(array_filter($fields, fn (array $f) => ! in_array($f['name'], ['title', 'description', 'status'], true)));
        // Repeater and multiselect fields are child
        // collections, not columns: they never enter the
        // fillable/search/sort sets.
        $customColumns = array_values(array_map(
            static fn (array $f): string => (string) $f['name'],
            array_filter($fields, static fn (array $f): bool => ! in_array($f['type'] ?? '', ['repeater', 'multiselect'], true))
        ));

        $manifestFields = array_map(function (array $field) {
            $manifestField = [
                'name' => $field['name'],
                'label' => $field['label'],
                'type' => $field['type'],
            ];
            // Decimal precision ("p,s") survives into the Heart so
            // staging/regen from the manifest keeps it; without the
            // record, the first regen reverted the column to the
            // (12,2) fallback even when generation had it right.
            if (in_array($field['type'], ['decimal', 'float'], true) && ($field['length'] ?? null) !== null) {
                $manifestField['length'] = $field['length'];
            }
            // A field's declared relation (name/model + the table
            // it references) survives into the Heart's fields map:
            // the form runtime resolves FK pickers from the
            // declaration, and a Heart that drops it leaves the
            // picker to table-name guessing (F10, Day-2 drill).
            if (! empty($field['relation']) && (is_array($field['relation']) || is_string($field['relation']))) {
                $manifestField['relation'] = $field['relation'];
                if (($field['references'] ?? null) !== null) {
                    $manifestField['references'] = $field['references'];
                }
            }
            // A dynamic option source (Stage C-5) survives
            // into the Heart for the same reason: the runtime
            // form resolver reads it from the declaration, and
            // a Heart that drops it silently reverts the field
            // to a free input on the next regen.
            if (! empty($field['options_from']) && is_string($field['options_from'])) {
                $manifestField['options_from'] = $field['options_from'];
            }
            // Field behaviour (Seat 1) survives into the
            // Heart for the same reason: the runtime reads
            // conditions, fetch sources and directory
            // flags from the declaration.
            foreach (['depends_on', 'mandatory_depends_on', 'read_only_depends_on'] as $behaviourKey) {
                if (! empty($field[$behaviourKey]) && is_array($field[$behaviourKey])) {
                    $manifestField[$behaviourKey] = $field[$behaviourKey];
                }
            }
            if (! empty($field['fetch_from']) && is_string($field['fetch_from'])) {
                $manifestField['fetch_from'] = $field['fetch_from'];
            }
            if (! empty($field['computed']) && is_array($field['computed'])) {
                $manifestField['computed'] = $field['computed'];
            }
            if (! empty($field['fallback_from']) && is_string($field['fallback_from'])) {
                $manifestField['fallback_from'] = $field['fallback_from'];
            }
            if (! empty($field['in_list_view'])) {
                $manifestField['in_list_view'] = true;
            }
            if (! empty($field['in_standard_filter'])) {
                $manifestField['in_standard_filter'] = true;
            }
            if (! empty($field['section']) && is_string($field['section'])) {
                $manifestField['section'] = $field['section'];
            }
            // Repeater declarations survive into the Heart so
            // a regen from the manifest keeps the collection.
            if (($field['type'] ?? '') === 'repeater') {
                if (! empty($field['table'])) {
                    $manifestField['table'] = $field['table'];
                }
                if (! empty($field['foreign_key'])) {
                    $manifestField['foreign_key'] = $field['foreign_key'];
                }
                $manifestField['repeater_fields'] = $field['repeater_fields'] ?? [];
            }
            // Multiselect declarations survive the same way:
            // the seat keys are the declaration, and a Heart
            // that drops them leaves a plain input behind.
            if (($field['type'] ?? '') === 'multiselect') {
                foreach (['table', 'foreign_key', 'target_field', 'target', 'parent_type'] as $seatKey) {
                    if (! empty($field[$seatKey]) && is_string($field[$seatKey])) {
                        $manifestField[$seatKey] = $field[$seatKey];
                    }
                }
            }
            $manifestField += [
                'nullable' => $field['nullable'],
                'default' => $field['default'],
                'options' => $field['options'] ?? [],
                'hidden' => ! empty($field['hidden']),
                'show_in_form' => isset($field['show_in_form']) ? (bool) $field['show_in_form'] : empty($field['hidden']),
                'show_in_list' => isset($field['show_in_list']) ? (bool) $field['show_in_list'] : empty($field['hidden']),
            ];

            return $manifestField;
        }, $fields);

        $fillable = var_export(array_values(array_unique($customColumns)), true);
        $sortableColumns = implode(', ', array_map(fn (string $column) => var_export($column, true), array_values(array_unique(array_merge(['id'], $customColumns, ['created_at', 'updated_at'])))));

        // Primary Search & Identity column discovery
        $searchColumn = 'id';
        foreach (['title', 'name', 'full_name', 'label', 'code', 'order_number', 'invoice_number', 'reference', 'number', 'email', 'slug'] as $candidate) {
            if (isset($fieldsByName[$candidate])) {
                $searchColumn = $candidate;
                break;
            }
        }
        if ($searchColumn === 'id') {
            foreach ($fields as $f) {
                if (in_array($f['type'], ['string', 'text', 'varchar'], true)) {
                    $searchColumn = $f['name'];
                    break;
                }
            }
        }

        // F5: inference parity — when the caller did not declare
        // directory columns, derive the same default directory the
        // porter builds (shared FieldInference rules) so hand-made
        // slices render with formatters, quick filters, and summary
        // cards instead of plain text columns.
        $uiOption = is_array($options['ui'] ?? null) ? $options['ui'] : [];
        if (empty($uiOption['directory']['columns'])) {
            $uiOption['directory'] = array_merge(
                $this->buildDefaultDirectory($fields, (string) ($options['tenant_key'] ?? 'company_id')),
                is_array($uiOption['directory'] ?? null) ? $uiOption['directory'] : []
            );
            $options['ui'] = $uiOption;
        }

        // DTO properties strictly for defined fields
        // F2: repeater (line-item) collections declared in the UI config
        $repeaterDefinitions = $this->extractRepeaterDefinitions(
            is_array($options['ui'] ?? null) ? $options['ui'] : [],
            $tableName,
            $fields
        );
        // Multiselect (Table MultiSelect) picker selections
        // declared in the fields map.
        $multiselectDefinitions = $this->extractMultiselectDefinitions($fields);
        // Relation-multi (BelongsToMany) selections declared in the UI config
        $relationMultiDefinitions = $this->extractRelationMultiDefinitions(
            is_array($options['ui'] ?? null) ? $options['ui'] : []
        );

        $dtoFormList = [];
        $dtoListingList = [];
        foreach ($fields as $f) {
            if (in_array($f['type'] ?? '', ['repeater', 'multiselect'], true)) {
                continue; // the array property is added from the definitions below
            }
            $dtoFormList[] = '    '.$this->fieldNormalizer->dtoProperty($f['name'], $f);
            if (empty($f['hidden']) && ($f['show_in_list'] ?? true)) {
                $dtoListingList[] = '    '.$this->fieldNormalizer->dtoProperty($f['name'], $f);
            }
        }
        foreach (array_keys($repeaterDefinitions) as $repeaterHandle) {
            $dtoFormList[] = "    public array \${$repeaterHandle} = [];";
        }
        foreach (array_keys($multiselectDefinitions) as $multiselectHandle) {
            $dtoFormList[] = "    public array \${$multiselectHandle} = [];";
        }
        foreach (array_keys($relationMultiDefinitions) as $multiHandle) {
            $dtoFormList[] = "    public array \${$multiHandle} = [];";
        }
        $dtoFormBody = implode("\n", array_filter($dtoFormList));
        $dtoListingBody = implode("\n", array_filter($dtoListingList));

        // Migration columns strictly for defined fields
        $migrationCols = [];
        if ($multitenant && ! in_array($tenantKey, $customColumns, true)) {
            $migrationCols[] = "                \$table->unsignedBigInteger('{$tenantKey}')->nullable()->index();";
            $customColumns[] = $tenantKey;
        }
        foreach ($fields as $f) {
            if (in_array($f['type'] ?? '', ['repeater', 'multiselect'], true)) {
                continue; // child rows live in the child table, not a column here
            }
            $migrationCols[] = '                '.$f['migration'];
        }
        if ($includeWorkflow) {
            if (! in_array('status', $customColumns, true)) {
                $migrationCols[] = "                \$table->string('status', 50)->default('draft')->index();";
            }
            $migrationCols[] = "                \$table->unsignedBigInteger('assigned_to_user_id')->nullable()->index();";
            $migrationCols[] = "                \$table->string('assigned_to_role', 100)->nullable();";
            $migrationCols[] = "                \$table->string('assigned_to_department', 100)->nullable();";
            $migrationCols[] = "                \$table->timestamp('workflow_state_entered_at')->nullable();";
        }
        $migrationBody = implode("\n", $migrationCols);

        // Validation rules strictly for defined fields
        $validationRuleLines = [];
        foreach ($fields as $f) {
            $validationRuleLines[] = "            '{$f['name']}' => ".var_export($f['rules'], true).',';
        }
        foreach (array_keys($relationMultiDefinitions) as $multiHandle) {
            $validationRuleLines[] = "            '{$multiHandle}' => ".var_export(['nullable', 'array'], true).',';
            $validationRuleLines[] = "            '{$multiHandle}.*' => ".var_export(['integer'], true).',';
        }
        $validationRules = implode("\n", $validationRuleLines);

        // Declarative Schema Form fields
        $schemaFormList = [];
        foreach ($fields as $f) {
            if (empty($f['hidden']) && ($f['show_in_form'] ?? true)) {
                $schemaFormList[] = '            '.$f['schema'];
            }
        }
        $schemaFormBody = implode(",\n", $schemaFormList);

        // Declarative Schema Table columns
        $schemaTableList = ["            Column::make('id')->label('ID')->sortable()"];
        if ($searchColumn !== 'id' && isset($fieldsByName[$searchColumn])) {
            $schemaTableList[] = "            Column::make('{$searchColumn}')->label(".var_export($fieldsByName[$searchColumn]['label'], true).')->sortable()->searchable()';
        }
        if ($statusField && empty($statusField['hidden']) && ($statusField['show_in_list'] ?? true)) {
            $schemaTableList[] = "            Column::make('status')->label(".var_export($statusField['label'], true).')->badge()';
        }
        foreach ($fields as $f) {
            if ($f['name'] === $searchColumn || $f['name'] === 'status') {
                continue;
            }
            if (! empty($f['hidden']) || (isset($f['show_in_list']) && ! $f['show_in_list'])) {
                continue;
            }
            $schemaTableList[] = "            Column::make('{$f['name']}')->label(".var_export($f['label'], true).')';
        }
        $schemaTableList[] = "            Column::make('created_at')->label('Created')->datetime()";
        $schemaTableBody = implode(",\n", $schemaTableList);

        // Column definitions for the index data-table
        $dataTableColumn = fn (string $key, string $label): string => "            ['key' => ".var_export($key, true).", 'label' => ".var_export($label, true).'],';
        $dtCols = [$dataTableColumn('id', 'ID')];
        if ($searchColumn !== 'id' && isset($fieldsByName[$searchColumn])) {
            $dtCols[] = $dataTableColumn($searchColumn, $fieldsByName[$searchColumn]['label']);
        }
        if ($statusField && empty($statusField['hidden']) && ($statusField['show_in_list'] ?? true)) {
            $dtCols[] = $dataTableColumn('status', $statusField['label']);
        }
        $otherColsAdded = 0;
        foreach ($fields as $f) {
            if ($f['name'] === $searchColumn || $f['name'] === 'status') {
                continue;
            }
            if (! empty($f['hidden']) || (isset($f['show_in_list']) && ! $f['show_in_list'])) {
                continue;
            }
            if (! isset($f['show_in_list']) && $otherColsAdded >= 4) {
                continue;
            }
            $dtCols[] = $dataTableColumn($f['name'], $f['label']);
            $otherColsAdded++;
        }
        $dtCols[] = $dataTableColumn('created_at', 'Created');
        $dataTableColumns = implode('
', $dtCols);

        // Check if declarative UI configuration is passed
        $uiConfig = $options['ui'] ?? [];

        // Declared record dialogs (D-015): a Heart may seat
        // fields in a modal flow gated by a trigger field —
        // Desk's "Set as Lost" pattern: picking the trigger
        // value opens the dialog holding the dialog's fields;
        // confirming submits the form; cancelling reverts the
        // trigger. Shape: ui.dialogs: [{name, label, trigger:
        // {field, value}, fields: [...], confirm_label}].
        // Dialog fields leave the section flow entirely (the
        // dialog IS their gate, so their depends_on clauses
        // are stripped from the dialog copies).
        $dialogDecls = [];
        $dialogFieldMap = [];
        foreach (($uiConfig['dialogs'] ?? []) as $dialogDecl) {
            if (! is_array($dialogDecl) || ! is_string($dialogDecl['name'] ?? null) || $dialogDecl['name'] === ''
                || ! is_array($dialogDecl['fields'] ?? null) || $dialogDecl['fields'] === []
                || ! is_array($dialogDecl['trigger'] ?? null)
                || ! is_string($dialogDecl['trigger']['field'] ?? null)
                || ! array_key_exists('value', $dialogDecl['trigger'])) {
                continue;
            }
            $dialogIndex = count($dialogDecls);
            $dialogDecls[] = [
                'name' => $dialogDecl['name'],
                'label' => is_string($dialogDecl['label'] ?? null) ? $dialogDecl['label'] : Str::headline($dialogDecl['name']),
                'confirm_label' => is_string($dialogDecl['confirm_label'] ?? null) ? $dialogDecl['confirm_label'] : 'Confirm',
                'trigger_field' => $dialogDecl['trigger']['field'],
                'trigger_value' => (string) $dialogDecl['trigger']['value'],
                'state_var' => 'lsDlg'.Str::studly($dialogDecl['name']),
            ];
            foreach ($dialogDecl['fields'] as $dialogField) {
                if (is_string($dialogField)) {
                    $dialogFieldMap[$dialogField] = $dialogIndex;
                }
            }
        }
        $dialogMarkups = array_fill(0, max(1, count($dialogDecls)), []);

        if (! empty($uiConfig['directory']['columns']) && is_array($uiConfig['directory']['columns'])) {
            $dtCols = [];
            foreach ($uiConfig['directory']['columns'] as $c) {
                $handle = $c['handle'] ?? $c['key'] ?? 'id';
                $label = $c['label'] ?? Str::headline($handle);
                $isHtml = in_array($c['type'] ?? '', ['code_badge', 'composite_user', 'status_pill', 'money_breakdown', 'progress_bar', 'badge', 'text_with_badge', 'date_overdue', 'lines'], true);
                $dtCols[] = "            ['key' => '{$handle}', 'label' => '{$label}', 'sortable' => true".($isHtml ? ", 'html' => true" : '').'],';
            }
            $dataTableColumns = implode('
', $dtCols);
        }

        // Form elements for Blade view (only visible, non-audit fields in responsive grid)
        $formAuditBlacklist = [
            'id', 'company_id', 'added_by', 'last_updated_by', 'created_by', 'updated_by',
            'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
            'calendar_view', 'attendance_reminder', 'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at',
        ];

        $formInputs = [];
        $formInputSections = [];
        $wideFieldKeywords = ['address', 'description', 'notes', 'memo', 'summary', 'about', 'instructions', 'content', 'body', 'remarks'];

        // Audit stamps (D-013): Heart-declared *_by user stamps
        // never become inputs (hygiene above), but the source
        // forms SHOW them — e.g. a CRM Note's "Added By". They
        // are collected here and emitted after the input
        // sections as read-only display rows on EDIT forms
        // only (see the audit-stamp section below): no input,
        // no name attribute, no part in submission or the BO.
        $auditStampFields = ['added_by', 'created_by', 'updated_by', 'modified_by', 'last_updated_by', 'deleted_by', 'approved_by'];
        $auditStampRows = [];

        foreach ($fields as $f) {
            $fName = $f['name'] ?? '';
            // The canonical system set (FieldDefinitionNormalizer)
            // rides along: *_by stamps, approval stamps and
            // server-recorded *_ip values never become inputs,
            // even on pre-hygiene manifests whose field defs
            // carry a stamped show_in_form: true.
            if (in_array($fName, $formAuditBlacklist, true) || FieldDefinitionNormalizer::isSystemField($fName)) {
                if (in_array($fName, $auditStampFields, true) && empty($f['hidden'])) {
                    $safeStampLabel = htmlspecialchars((string) ($f['label'] ?? Str::headline($fName)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $auditStampRows[] = <<<HTML
                <div class="space-y-1" data-ls-audit-stamp="{$fName}">
                    <span class="block text-xs font-medium text-foreground/80">{$safeStampLabel}</span>
                    <p class="flex h-8 items-center text-sm text-foreground">{{ \\LaraSlice\\Support\\RecordWorkplace::userLabel(\$form->{$fName} ?? null) ?? (\$form->{$fName} ?? '—') }}</p>
                </div>
HTML;
                }
                continue;
            }
            if (empty($f['hidden']) && ($f['show_in_form'] ?? true)) {
                // Repeater fields render the child-collection
                // component instead of a scalar input (their
                // normalized 'form' markup is intentionally
                // empty — see FieldDefinitionNormalizer). A
                // declared depends_on wraps the component in
                // the same field wrapper the scalar inputs
                // carry, so the shell's behaviour evaluator
                // shows/hides the whole grid as one field.
                $markup = match ($f['type'] ?? '') {
                    'repeater' => $this->repeaterComponent($f, $fName),
                    'multiselect' => $this->multiselectComponent($f, $fName),
                    default => $f['form'],
                };
                if (in_array($f['type'] ?? '', ['repeater', 'multiselect'], true) && ! empty($f['depends_on']) && is_array($f['depends_on'])) {
                    $markup = '<div class="md:col-span-2 lg:col-span-3" data-ls-field="'.$fName.'" data-ls-depends-on="'
                        .htmlspecialchars(json_encode($f['depends_on']), ENT_QUOTES, 'UTF-8').'">'.$markup.'</div>';
                }
                $isWide = ($f['type'] ?? '') === 'text' || ($f['type'] ?? '') === 'json' || in_array($fName, $wideFieldKeywords, true);
                if ($isWide) {
                    $markup = preg_replace('/<div class="space-y-1"/', '<div class="space-y-1 md:col-span-2 lg:col-span-3"', $markup, 1);
                }
                if (isset($dialogFieldMap[$fName])) {
                    // Dialog-seated field (D-015): rendered
                    // inside its dialog instead of the section
                    // flow; the dialog gates it, so behaviour
                    // clauses come off the dialog copy.
                    $dialogMarkups[$dialogFieldMap[$fName]][] = (string) preg_replace('/\s+data-ls-(?:depends-on|mandatory-on)="[^"]*"/', '', $markup);
                    continue;
                }
                $formInputs[] = $markup;
                $formInputSections[] = $f['section'] ?? null;
            }
        }

        // Declared form sections (Seat 1, Stage D): when any
        // field declares a `section`, fields group under
        // section cards — a field's section runs until the
        // next field declares a different one (the source
        // systems' Section Break semantics). Declared
        // sections replace the count-based split below.
        $hasDeclaredSections = array_filter($formInputSections, static fn ($s) => is_string($s) && $s !== '') !== [];
        if ($hasDeclaredSections) {
            $groups = [];
            $currentSection = 'General Information';
            foreach ($formInputs as $i => $markup) {
                $section = $formInputSections[$i] ?? null;
                if (is_string($section) && $section !== '') {
                    $currentSection = $section;
                }
                $groups[$currentSection][] = $markup;
            }
            $cards = [];
            foreach ($groups as $sectionTitle => $inputs) {
                $inputsStr = implode("\n\n", $inputs);
                $safeTitle = htmlspecialchars($sectionTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                // Section meta (D-066 / D-049): a Heart may declare
                // per-section presentation in ui.form_sections —
                // a description printed under the heading (the
                // source's Section Break description) and a
                // collapsed resting state (the source's collapsible
                // sections start closed behind a chevron header).
                $sectionMeta = is_array($uiConfig['form_sections'][$sectionTitle] ?? null) ? $uiConfig['form_sections'][$sectionTitle] : [];
                $sectionDescHtml = ! empty($sectionMeta['description'])
                    ? "\n            <p class=\"text-xs leading-relaxed text-muted-foreground mt-1 mb-4\">".htmlspecialchars((string) $sectionMeta['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>'
                    : '';
                if (! empty($sectionMeta['collapsed'])) {
                    $cards[] = <<<HTML
        <section class="border-t border-border/60 px-6 py-5" x-data="{ lsSectionOpen: false }">
            <button type="button" @click="lsSectionOpen = !lsSectionOpen" :aria-expanded="lsSectionOpen" class="flex w-full items-center justify-between gap-2 text-left">
                <h3 class="text-sm font-semibold text-foreground">{$safeTitle}</h3>
                <x-lucide-chevron-right class="size-4 shrink-0 text-muted-foreground transition-transform duration-150" x-bind:class="lsSectionOpen ? 'rotate-90' : ''" />
            </button>{$sectionDescHtml}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-4" data-ls-section-grid x-show="lsSectionOpen" x-cloak>
{$inputsStr}
            </div>
        </section>
HTML;
                } else {
                    $cards[] = <<<HTML
        <section class="border-t border-border/60 px-6 py-5">
            <h3 class="text-sm font-semibold text-foreground mb-4">{$safeTitle}</h3>{$sectionDescHtml}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" data-ls-section-grid>
{$inputsStr}
            </div>
        </section>
HTML;
                }
            }
            $cardsByTitle = array_combine(array_keys($groups), $cards);
            $formSectionsHtml = implode("\n\n", $cards);
        } elseif (count($formInputs) <= 6) {
            $inputsStr = implode("\n\n", $formInputs);
            $formSectionsHtml = <<<HTML
        <section class="border-t border-border/60 px-6 py-5">
            <h3 class="text-sm font-semibold text-foreground mb-4">General Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" data-ls-section-grid>
{$inputsStr}
            </div>
        </section>
HTML;
        } else {
            $primaryInputs = array_slice($formInputs, 0, 6);
            $secondaryInputs = array_slice($formInputs, 6);
            $primaryStr = implode("\n\n", $primaryInputs);
            $secondaryStr = implode("\n\n", $secondaryInputs);

            $formSectionsHtml = <<<HTML
        <section class="border-t border-border/60 px-6 py-5">
            <h3 class="text-sm font-semibold text-foreground mb-4">Core Parameters</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" data-ls-section-grid>
{$primaryStr}
            </div>
        </section>

        <section class="border-t border-border/60 px-6 py-5">
            <h3 class="text-sm font-semibold text-foreground mb-4">Additional Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" data-ls-section-grid>
{$secondaryStr}
            </div>
        </section>
HTML;
        }

        if (! empty($uiConfig['forms']['sections']) && is_array($uiConfig['forms']['sections'])) {
            $customCards = [];
            foreach ($uiConfig['forms']['sections'] as $sIdx => $sec) {
                $sTitle = $sec['title'] ?? ('Section '.($sIdx + 1));
                $sDesc = $sec['description'] ?? 'Configure record attributes';
                $sDescHtml = ! empty($sec['description'])
                    ? '<p class="text-xs text-muted-foreground mt-0.5 mb-4">'.htmlspecialchars((string) $sec['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>'
                    : '';
                $sIcon = $sec['icon'] ?? 'layers';
                $sCols = (int) ($sec['columns'] ?? 3);
                $gridClass = match ($sCols) {
                    1 => 'grid grid-cols-1 gap-4',
                    2 => 'grid grid-cols-1 md:grid-cols-2 gap-4',
                    4 => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4',
                    default => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4',
                };

                $cardInputs = [];
                foreach ($sec['fields'] ?? [] as $fld) {
                    $fHandle = is_array($fld) ? ($fld['handle'] ?? '') : (string) $fld;
                    $fSpan = is_array($fld) ? (int) ($fld['span'] ?? 1) : 1;
                    $isSearchable = is_array($fld) && ! empty($fld['searchable']);
                    $fType = is_array($fld) ? ($fld['type'] ?? '') : '';

                    // Repeater field (e.g., invoice line items) — render x-ui.repeater
                    if ($fType === 'repeater') {
                        $cardInputs[] = $this->repeaterComponent(is_array($fld) ? $fld : [], $fHandle);

                        continue;
                    }

                    // Relation-multi field (BelongsToMany picker, e.g. a
                    // task's Assigned To) — a native multi-select fed by
                    // the controller-resolved {handle}Options; the service
                    // syncs the selection to the relation on save.
                    if ($fType === 'relation_multi') {
                        $multiLabel = $fld['label'] ?? Str::headline($fHandle);
                        $optionsVar = Str::camel($fHandle).'Options';
                        $spanClass = ($fSpan >= 3 || $fSpan >= $sCols) ? ' md:col-span-2 lg:col-span-3' : ($fSpan === 2 ? ' md:col-span-2' : '');
                        $cardInputs[] = "<div class=\"space-y-1{$spanClass}\">\n"
                            ."                    <x-ui.label for=\"{$fHandle}\">{$multiLabel}</x-ui.label>\n"
                            ."                    <select id=\"{$fHandle}\" name=\"{$fHandle}[]\" multiple size=\"5\" class=\"flex w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring\">\n"
                            ."                        @foreach((\${$optionsVar} ?? []) as \$optValue => \$optLabel)\n"
                            ."                            <option value=\"{{ \$optValue }}\" @selected(in_array((int) \$optValue, array_map('intval', (array) old('{$fHandle}', \$form->{$fHandle} ?? [])), true))>{{ \$optLabel }}</option>\n"
                            ."                        @endforeach\n"
                            ."                    </select>\n"
                            ."                    @error('{$fHandle}') <p class=\"text-xs text-destructive font-medium\">{{ \$message }}</p> @enderror\n"
                            .'                </div>';

                        continue;
                    }

                    // Multiselect field (Table MultiSelect picker) —
                    // the chip combobox fed by the controller-resolved
                    // {handle}Options; the service diffs the child
                    // rows on save.
                    if ($fType === 'multiselect') {
                        $cardInputs[] = $this->multiselectComponent(is_array($fld) ? $fld : [], $fHandle);

                        continue;
                    }

                    // Find field matching handle
                    $fieldObj = collect($fields)->firstWhere('name', $fHandle);
                    // Form hygiene parity with the default (non-sectioned)
                    // path above: audit/system columns and fields the
                    // Heart keeps off forms never become inputs just
                    // because a ported manifest lists their handle in a
                    // form section (the comparative test found added_by /
                    // last_updated_by / hash rendered as editable number
                    // inputs across the ported ERP's sectioned forms).
                    if ($fieldObj && (in_array($fHandle, $formAuditBlacklist, true)
                        || FieldDefinitionNormalizer::isSystemField($fHandle)
                        || ! empty($fieldObj['hidden'])
                        || ($fieldObj['show_in_form'] ?? true) === false)) {
                        continue;
                    }
                    if ($fieldObj && ! empty($fieldObj['form'])) {
                        $markup = $fieldObj['form'];
                        if ($fSpan >= 3 || $fSpan >= $sCols) {
                            $markup = preg_replace('/<div class="space-y-1"/', '<div class="space-y-1 md:col-span-2 lg:col-span-3"', $markup, 1);
                        } elseif ($fSpan === 2) {
                            $markup = preg_replace('/<div class="space-y-1"/', '<div class="space-y-1 md:col-span-2"', $markup, 1);
                        }
                        if ($isSearchable && str_contains($markup, '<select')) {
                            // Replace <select> with <x-ui.searchable-select>
                            $markup = preg_replace('/<select[^>]*>.*?<\/select>/s', "<x-ui.searchable-select name=\"{$fHandle}\" :value=\"old('{$fHandle}', $form->{$fHandle} ?? '')\" placeholder=\"Select {$fieldObj['label']}...\" />", $markup);
                        }
                        $cardInputs[] = $markup;
                    }
                }

                $inputsCombined = implode('

', $cardInputs);
                $customCards[] = <<<CARD
        <section class="border-t border-border/60 px-6 py-5">
            <h3 class="text-sm font-semibold text-foreground">{$sTitle}</h3>
            {$sDescHtml}
            <div class="{$gridClass}" data-ls-section-grid>
{$inputsCombined}
            </div>
        </section>
CARD;
            }
            if (! empty($customCards)) {
                $formSectionsHtml = implode('

', $customCards);
            }
        }

        // Form tabs (D-046): ui.form_tabs groups the declared
        // section cards under Desk-style tab panels —
        // [{label, sections: [section titles]}]. Sections no
        // tab claims join the first tab, so nothing becomes
        // unreachable; a declaration claiming no known section
        // leaves the flat layout untouched.
        if (is_array($uiConfig['form_tabs'] ?? null) && ($cardsByTitle ?? []) !== []
            && empty($uiConfig['forms']['sections'])) {
            $tabPanels = [];
            $claimedSections = [];
            foreach ($uiConfig['form_tabs'] as $tabDecl) {
                if (! is_array($tabDecl) || ! is_string($tabDecl['label'] ?? null)) {
                    continue;
                }
                $panelCards = [];
                foreach ((array) ($tabDecl['sections'] ?? []) as $tabSection) {
                    if (is_string($tabSection) && isset($cardsByTitle[$tabSection]) && ! isset($claimedSections[$tabSection])) {
                        $panelCards[] = $cardsByTitle[$tabSection];
                        $claimedSections[$tabSection] = true;
                    }
                }
                $tabPanels[] = ['label' => $tabDecl['label'], 'cards' => $panelCards];
            }
            if ($tabPanels !== [] && $claimedSections !== []) {
                foreach ($cardsByTitle as $sectionTitle => $cardHtml) {
                    if (! isset($claimedSections[$sectionTitle])) {
                        $tabPanels[0]['cards'][] = $cardHtml;
                    }
                }
                $tabButtons = '';
                $tabBodies = '';
                foreach ($tabPanels as $tabIndex => $tabPanel) {
                    $safeTabLabel = htmlspecialchars($tabPanel['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $tabButtons .= "\n                <button type=\"button\" role=\"tab\" @click=\"lsFormTab = {$tabIndex}\" :class=\"lsFormTab === {$tabIndex} ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'\" class=\"inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-[13px] font-medium transition-colors\">{$safeTabLabel}</button>";
                    $tabBodies .= "\n        <div x-show=\"lsFormTab === {$tabIndex}\"".($tabIndex > 0 ? ' x-cloak' : '').">\n".implode("\n\n", $tabPanel['cards'])."\n        </div>";
                }
                $formSectionsHtml = <<<HTML
        <div x-data="{ lsFormTab: 0 }">
            <div class="flex flex-wrap items-center gap-1 border-b border-border px-3" role="tablist">{$tabButtons}
            </div>{$tabBodies}
        </div>
HTML;
            }
        }

        // Record dialogs (D-015): modal markup + header
        // buttons for the declared dialogs, and the form's
        // Alpine state. The dialog lives INSIDE the form so
        // its fields submit with the record; confirming
        // submits with the confirm button's _dialog marker,
        // which the web controller turns into the dialog's
        // declared effects (trigger value forced + the
        // declaration's `set` map applied — D-071) and a
        // save-in-place redirect (Desk's Declare Lost
        // persists in place). The header button also ARMS
        // the trigger field (the wiring script), so the
        // user sees the gated state; cancelling reverts the
        // trigger field to its last non-trigger value.
        $dialogsHtml = '';
        $dialogButtonsHtml = '';
        $formXDataAttr = '';
        if ($dialogDecls !== []) {
            $statePairs = [];
            foreach ($dialogDecls as $dialogIndex => $dialogDecl) {
                $statePairs[] = $dialogDecl['state_var'].': false';
                $safeDialogLabel = htmlspecialchars($dialogDecl['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safeConfirmLabel = htmlspecialchars($dialogDecl['confirm_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $dialogButtonsHtml .= "\n                @if(!\$isNew)\n                    <button type=\"button\" data-ls-dialog-arm=\"{$dialogDecl['name']}\" @click=\"{$dialogDecl['state_var']} = true\" class=\"inline-flex h-8 items-center justify-center whitespace-nowrap rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted\">{$safeDialogLabel}</button>\n                @endif";
                $dialogFieldsStr = implode("\n\n", $dialogMarkups[$dialogIndex] ?? []);
                $dialogsHtml .= <<<HTML

        <div x-show="{$dialogDecl['state_var']}" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="{$dialogDecl['state_var']} = false">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-border bg-card p-5 shadow-lg">
                <h3 class="text-base font-semibold text-foreground">{$safeDialogLabel}</h3>
                <div class="mt-4 space-y-4">
{$dialogFieldsStr}
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" @click="{$dialogDecl['state_var']} = false; \$el.closest('form').dispatchEvent(new CustomEvent('ls-dialog-cancel-{$dialogDecl['name']}'))" class="inline-flex h-8 items-center justify-center rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Cancel</button>
                    <button type="submit" name="_dialog" value="{$dialogDecl['name']}" class="inline-flex h-8 items-center justify-center rounded-md bg-primary px-3 text-xs font-semibold text-primary-foreground shadow-xs transition-colors hover:bg-primary/90">{$safeConfirmLabel}</button>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.querySelector('form.ls-form-sheet');
            var trigger = form ? form.querySelector('[name="{$dialogDecl['trigger_field']}"]') : null;
            if (!trigger || !window.Alpine) { return; }
            var prev = trigger.value;
            trigger.addEventListener('change', function () {
                if (trigger.value === '{$dialogDecl['trigger_value']}') {
                    try { Alpine.\$data(form).{$dialogDecl['state_var']} = true; } catch (e) {}
                } else {
                    prev = trigger.value;
                }
            });
            form.addEventListener('ls-dialog-cancel-{$dialogDecl['name']}', function () {
                trigger.value = prev;
                trigger.dispatchEvent(new Event('change', { bubbles: true }));
            });
            var arm = form.querySelector('[data-ls-dialog-arm="{$dialogDecl['name']}"]');
            if (arm) {
                arm.addEventListener('click', function () {
                    if (trigger.value !== '{$dialogDecl['trigger_value']}') {
                        trigger.value = '{$dialogDecl['trigger_value']}';
                        trigger.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            }
        });
        </script>
HTML;
            }
            $formXDataAttr = ' x-data="{ '.implode(', ', $statePairs).' }"';
        }

        // Conversion pre-steps (D-073): a conversion declaring
        // pre_steps renders its Create▾ item as a dialog (the
        // Blade menu branches on the declaration at runtime);
        // confirming navigates to the convert URL carrying the
        // chosen steps — this helper builds that URL from the
        // dialog's checkboxes and name inputs.
        $conversionStepsHtml = '';
        foreach ((array) ($options['conversions'] ?? []) as $conversionDecl) {
            if (is_array($conversionDecl) && ! empty($conversionDecl['pre_steps'])) {
                $conversionStepsHtml = <<<'HTML'

        <script>
        window.lsRunConversion = function (btn) {
            var root = btn.closest('[data-ls-convert-url]');
            if (!root) { return; }
            var url = new URL(root.getAttribute('data-ls-convert-url'), window.location.origin);
            root.querySelectorAll('[data-ls-step-check]').forEach(function (check) {
                var name = check.getAttribute('data-ls-step-check');
                url.searchParams.set('steps[' + name + ']', check.checked ? '1' : '0');
                var input = root.querySelector('[data-ls-step-name="' + name + '"]');
                if (input) { url.searchParams.set('step_names[' + name + ']', input.value); }
            });
            window.location = url.toString();
        };
        </script>
HTML;
                break;
            }
        }

        // Settings anatomy (D-027): ui.single slices render
        // the Desk settings shape — no card chrome around the
        // form, sections flat on the page.
        $formSheetClass = ! empty($uiConfig['single'])
            ? 'ls-form-sheet w-full'
            : 'ls-form-sheet bg-card border border-border rounded-xl shadow-xs overflow-hidden';

        // Companion-account creation (declared account_creation:):
        // the create form opens with a Login Account card whose
        // account[...] inputs the store turns into a real user
        // (BaseSliceWebController::applyCompanionAccount).
        $accountDecl = is_array($options['account_creation'] ?? null) ? $options['account_creation'] : null;
        if ($accountDecl !== null && ! empty($accountDecl['role'])) {
            $accountRole = (string) $accountDecl['role'];
            $accountCard = <<<HTML
        @if(\$isNew)
        <x-ui.card class="bg-card border border-border shadow-xs overflow-hidden">
            <x-ui.card-header class="border-b border-border/60 bg-muted/20 px-6 py-4">
                <div class="flex items-center gap-2">
                    <x-lucide-key-round class="size-4 text-primary" />
                    <x-ui.card-title class="text-[13px] font-semibold text-foreground">Login Account</x-ui.card-title>
                </div>
                <x-ui.card-description class="text-xs">Create the {$accountRole} login account in the same step — or leave blank and pick an existing user below.</x-ui.card-description>
            </x-ui.card-header>
            <x-ui.card-content class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" data-ls-section-grid>
                    <div class="space-y-1">
                        <x-ui.label for="account_name">Full Name</x-ui.label>
                        <x-ui.input id="account_name" type="text" name="account[name]" value="{{ old('account.name') }}" autocomplete="off" />
                        @error('account.name') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                    </div>
                    <div class="space-y-1">
                        <x-ui.label for="account_email">Email</x-ui.label>
                        <x-ui.input id="account_email" type="email" name="account[email]" value="{{ old('account.email') }}" autocomplete="off" />
                        @error('account.email') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                    </div>
                    <div class="space-y-1">
                        <x-ui.label for="account_password">Password</x-ui.label>
                        <x-ui.input id="account_password" type="password" name="account[password]" autocomplete="new-password" />
                        @error('account.password') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                    </div>
                </div>
            </x-ui.card-content>
        </x-ui.card>
        @endif

HTML;
            $formSectionsHtml = $accountCard.$formSectionsHtml;
        }

        // Audit-stamp display (D-013): the collected *_by rows
        // ride as a trailing read-only section, gated at
        // runtime to edit forms (@if !$isNew) — create forms
        // never show a stamp for a record that does not exist
        // yet. One @if/@endif pair wraps the whole section.
        if ($auditStampRows !== []) {
            $stampRowsStr = implode("\n\n", $auditStampRows);
            $auditStampSection = <<<HTML
        @if(!\$isNew)
        <section class="border-t border-border/60 px-6 py-5" data-ls-audit-stamps>
            <h3 class="text-sm font-semibold text-foreground mb-4">Audit</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" data-ls-section-grid>
{$stampRowsStr}
            </div>
        </section>
        @endif
HTML;
            $formSectionsHtml .= "\n\n".$auditStampSection;
        }

        // Record-state banner (D-069): a Heart may declare
        // ui.state_banner {field, equals, message, pill?} — when
        // the edited record's field loosely equals the declared
        // value, a banner prints above the first section (the
        // source's "This Currency is disabled" strip). Emitted
        // as a runtime @if so create forms and non-matching
        // records stay byte-identical.
        $stateBanner = is_array($uiConfig['state_banner'] ?? null) ? $uiConfig['state_banner'] : [];
        if (! empty($stateBanner['field']) && array_key_exists('equals', $stateBanner) && ! empty($stateBanner['message'])) {
            $bannerField = (string) $stateBanner['field'];
            $bannerEquals = var_export($stateBanner['equals'], true);
            $bannerMessage = htmlspecialchars((string) $stateBanner['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $bannerPill = ! empty($stateBanner['pill'])
                ? "\n            <span class=\"inline-flex shrink-0 items-center rounded-full bg-slate-200 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600\">".htmlspecialchars((string) $stateBanner['pill'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>'
                : '';
            $stateBannerHtml = <<<HTML
        @if(!\$isNew && (\$form->{$bannerField} ?? null) !== null && (\$form->{$bannerField} ?? null) == {$bannerEquals})
        <div class="flex items-center justify-between gap-3 border-t border-sky-200 bg-sky-50 px-6 py-3">
            <p class="text-xs font-medium text-sky-900">{$bannerMessage}</p>{$bannerPill}
        </div>
        @endif
HTML;
            $formSectionsHtml = $stateBannerHtml."\n\n".$formSectionsHtml;
        }

        $includeApi = (bool) ($options['api'] ?? true);
        $description = isset($options['description']) && is_string($options['description']) && trim($options['description']) !== ''
            ? mb_substr(trim($options['description']), 0, 1000)
            : "Vertical slice module for {$pluralName}";
        $author = isset($options['author']) && is_string($options['author']) && trim($options['author']) !== ''
            ? mb_substr(trim($options['author']), 0, 120)
            : null;

        if (file_exists($targetDir)) {
            if (! empty($options['force'])) {
                File::deleteDirectory($targetDir);
            } else {
                throw new SliceExistsException("Slice '{$pluralName}' already exists at {$targetDir}; generation stopped to protect its files.");
            }
        }

        if (! is_dir($this->slicesPath) && ! mkdir($this->slicesPath, 0755, true) && ! is_dir($this->slicesPath)) {
            throw new \RuntimeException("Unable to create slices directory: {$this->slicesPath}");
        }

        $stagingDir = $this->slicesPath.DIRECTORY_SEPARATOR.'.laraslice-'.bin2hex(random_bytes(8));
        $sliceDir = $stagingDir;

        try {
            if (! mkdir($sliceDir, 0755, true) && ! is_dir($sliceDir)) {
                throw new \RuntimeException("Unable to create temporary slice directory: {$sliceDir}");
            }

            foreach (['Contracts', 'Controllers', 'Migrations', 'Models', 'Resources/views', 'Routes', 'Schemas', 'Services'] as $sub) {
                $path = $sliceDir.'/'.$sub;
                if (! is_dir($path)) {
                    if (! mkdir($path, 0755, true) && ! is_dir($path)) {
                        throw new \RuntimeException("Unable to create slice directory: {$path}");
                    }
                }
            }

            // 1. slice.yaml Manifest
            $manifestWorkflow = false;
            if ($includeWorkflow) {
                if (! empty($options['workflowConfig']) && is_array($options['workflowConfig'])) {
                    $manifestWorkflow = $options['workflowConfig'];
                } else {
                    $manifestWorkflow = [
                        'enabled' => true,
                        'slug' => $pluralSnake,
                        'name' => "{$studlyName} Workflow",
                        'state_field' => 'status',
                        'initial_state' => 'draft',
                        'states' => [
                            ['slug' => 'draft', 'label' => 'Draft', 'color' => 'slate', 'icon' => 'file-text', 'initial' => true],
                            ['slug' => 'under_review', 'label' => 'Under Review', 'color' => 'amber', 'icon' => 'clock', 'sla_hours' => 24],
                            ['slug' => 'approved', 'label' => 'Approved', 'color' => 'emerald', 'icon' => 'check-circle'],
                            ['slug' => 'rejected', 'label' => 'Rejected', 'color' => 'rose', 'icon' => 'x-circle', 'terminal' => true],
                        ],
                        'transitions' => [
                            [
                                'slug' => 'submit',
                                'name' => 'Submit for Review',
                                'from' => ['draft'],
                                'to' => 'under_review',
                                'color' => 'primary',
                                'routing' => ['type' => 'hierarchy_manager', 'notify' => true],
                            ],
                            [
                                'slug' => 'approve',
                                'name' => 'Approve',
                                'from' => ['under_review'],
                                'to' => 'approved',
                                'permission' => "{$snakeName}.edit",
                                'requires_remarks' => true,
                                'color' => 'success',
                            ],
                            [
                                'slug' => 'reject',
                                'name' => 'Reject back to Draft',
                                'from' => ['under_review'],
                                'to' => 'draft',
                                'permission' => "{$snakeName}.edit",
                                'requires_remarks' => true,
                                'color' => 'danger',
                            ],
                        ],
                    ];
                }
            }
            // Declared relations land on the root model entry below —
            // the same arrays the model-method emission further down
            // consumes from $options['relations'] (F3).
            $manifestRelations = [];
            foreach ((array) ($options['relations'] ?? []) as $declaredRelation) {
                if (is_array($declaredRelation) && ! empty($declaredRelation['name'])) {
                    $manifestRelations[] = $declaredRelation;
                }
            }

            // Directory columns for FK fields carry the field's
            // DECLARED relation (name + target table) into the
            // Heart, so cell renderers resolve the actual target
            // record instead of guessing from the handle. Neither
            // the Studio-built UI payload nor the default
            // directory tags both, and a renderer left to
            // conventions renders the wrong record for
            // non-conventional targets (F10: employee_id ->
            // employee_details, whose id is not a user id).
            if (! empty($uiOption['directory']['columns']) && is_array($uiOption['directory']['columns'])) {
                $declaredByHandle = [];
                foreach ($manifestFields as $manifestField) {
                    if (is_array($manifestField) && ! empty($manifestField['name'])) {
                        $declaredByHandle[(string) $manifestField['name']] = $manifestField;
                    }
                }
                foreach ($uiOption['directory']['columns'] as $columnIndex => $directoryColumn) {
                    if (! is_array($directoryColumn)) {
                        continue;
                    }
                    $columnHandle = (string) ($directoryColumn['handle'] ?? $directoryColumn['key'] ?? '');
                    $declaredField = $declaredByHandle[$columnHandle] ?? null;
                    if ($declaredField === null) {
                        continue;
                    }
                    if (empty($directoryColumn['relation']) && ! empty($declaredField['relation']['name'])) {
                        $uiOption['directory']['columns'][$columnIndex]['relation'] = (string) $declaredField['relation']['name'];
                    }
                    if (empty($directoryColumn['label_table']) && ! empty($declaredField['references'])) {
                        $uiOption['directory']['columns'][$columnIndex]['label_table'] = (string) $declaredField['references'];
                    }
                }
            }

            // OBS-B2: the Heart is born in the one canonical
            // producer form (see HeartUiCanonicalizer) — the form
            // the Manage save path produces — so a first Studio
            // save previews the user's change, not a normalization.
            $uiOption = HeartUiCanonicalizer::canonicalize($uiOption, $manifestFields);

            $manifestData = [
                'name' => $pluralName,
                'title' => Str::title(SliceName::snake($pluralName, ' ')),
                'version' => '1.0.0',
                'description' => $description,
                'author' => $author,
                'active' => true,
                'workflow' => $manifestWorkflow,
                'roles' => ! empty($options['roles']) && is_array($options['roles']) ? $options['roles'] : (! empty($options['workflowConfig']['roles']) ? $options['workflowConfig']['roles'] : []),
                'fields' => ManifestRepository::fieldMap($manifestFields),
                // Every generated manifest declares its root model.
                // Without a models[] root entry, manifest-driven
                // features that resolve the root model from the
                // manifest (dashboard counters, porter collision
                // detection, repair field collection) are blind —
                // a fresh port's slices carried no root model at
                // all until one was hand-added. Callers may pass a
                // full models list via options to override.
                'models' => ! empty($options['models']) && is_array($options['models'])
                    ? $options['models']
                    : [[
                        'handle' => Str::snake(Str::singular($tableName)),
                        'table' => $tableName,
                        'root' => true,
                        'fields' => array_map(fn (array $field) => [
                            'handle' => $field['name'],
                            'label' => $field['label'],
                            'type' => $field['type'],
                            'required' => ! ($field['nullable'] ?? true),
                        ], $manifestFields),
                        'relations' => $manifestRelations,
                    ]],
                'permissions' => ! empty($options['permissions']) && is_array($options['permissions'])
                    ? $options['permissions']
                    : [
                        "{$snakeName}.view",
                        "{$snakeName}.create",
                        "{$snakeName}.edit",
                        "{$snakeName}.delete",
                    ],
                'namespace' => $sliceNamespace,
                'ui' => $uiOption ?? [],
                'navigation' => [
                    'label' => Str::title(SliceName::snake($pluralName, ' ')),
                    'url' => $navUrl,
                    'icon' => 'package',
                    'order' => 50,
                ],
            ];

            if ($superDomainOpt !== null) {
                // 3-tier: super_domain > app(domain) > sub_domain(module)
                $manifestData['app'] = $superDomainOpt;
                $manifestData['super_domain'] = $superDomainOpt;
            } elseif ($app !== null) {
                $manifestData['app'] = $app;
                $manifestData['super_domain'] = $app;
            }
            if ($domain !== null) {
                if ($superDomainOpt !== null && $app !== null) {
                    // 3-tier: domain is the middle tier, module is the inner grouping
                    $manifestData['module'] = $app;
                    $manifestData['domain'] = $app;
                    $manifestData['sub_domain'] = $domain;
                } else {
                    $manifestData['module'] = $domain;
                    $manifestData['domain'] = $domain;
                }
                $manifestData['navigation']['group'] = $domain;
            }
            $manifestData['table_prefix'] = $tablePrefix ?: null;
            $manifestData['multitenant'] = $multitenant;
            $manifestData['tenant_key'] = $tenantKey;

            // Phase 2: YAML only. Do not create a JSON twin.
            ManifestRepository::writeYaml($sliceDir, $manifestData);

            if (! empty($manifestData['roles']) && is_array($manifestData['roles'])) {
                try {
                    $seeder = new SliceSeederService;
                    if (method_exists($seeder, 'seedCustomRolesAndUsers')) {
                        $seeder->seedCustomRolesAndUsers($manifestData['roles']);
                    }
                } catch (\Throwable $e) {
                }
            }

            // Phase 2: runtime manifest already written as slice.yaml above.

            // 2. Model
            $modelContent = <<<PHP
<?php

namespace {$sliceNamespace}\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraSlice\Core\Audit\Traits\AuditableSlice;
PHP;
            if ($softDeletes) {
                $modelContent .= "\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;";
            }
            if ($includeWorkflow) {
                $modelContent .= "\nuse LaraSlice\\Core\\Workflow\\HasWorkflow;\n";
            }

            $modelRelations = '';
            $emittedRelationMethods = [];
            foreach ($repeaterDefinitions as $repeaterHandle => $repeaterDef) {
                $childModel = Str::studly(Str::singular($repeaterDef['model'] ?? $repeaterDef['table']));
                $relMethod = Str::camel($repeaterHandle);
                $fkExport = var_export($repeaterDef['foreign_key'], true);
                $targetExport = var_export($childModel, true);
                $modelRelations .= <<<REL


    public function {$relMethod}(): \\Illuminate\\Database\\Eloquent\\Relations\\HasMany
    {
        // Resolved among installed slices (and App\\Models) when first used
        \$class = app(\\LaraSlice\\Core\\Discovery\\SliceManager::class)->modelClass({$targetExport})
            ?? throw new \\LogicException('No model class named {$childModel} was found for repeater {$repeaterHandle}.');

        return \$this->hasMany(\$class, {$fkExport});
    }
REL;
                $emittedRelationMethods[$relMethod] = true;
            }
            // Declared manifest relations (models[].relations, passed via
            // options by the porter / blueprint callers): without methods
            // for these, detail-page relation tabs and $model->relation
            // access have nothing to call. F3.
            foreach (($options['relations'] ?? []) as $declaredRelation) {
                if (! is_array($declaredRelation) || empty($declaredRelation['name'])) {
                    continue;
                }
                $declMethod = Str::camel((string) $declaredRelation['name']);
                if (isset($emittedRelationMethods[$declMethod])) {
                    continue;
                }
                $declType = (string) ($declaredRelation['type'] ?? 'hasMany');
                if (! in_array($declType, ['hasMany', 'belongsTo', 'belongsToMany', 'hasManyThrough'], true)) {
                    continue;
                }
                $declTarget = Str::studly(Str::singular((string) ($declaredRelation['model'] ?? $declaredRelation['name'])));
                $declTargetExport = var_export($declTarget, true);
                if ($declType === 'belongsToMany') {
                    $pivot = is_array($declaredRelation['pivot'] ?? null) ? $declaredRelation['pivot'] : [];
                    $pivotTable = (string) ($pivot['table'] ?? $declaredRelation['table'] ?? '');
                    if ($pivotTable === '') {
                        continue;
                    }
                    $pivotArgs = var_export($pivotTable, true).', '
                        .var_export((string) ($pivot['foreign_pivot_key'] ?? Str::singular($tableName).'_id'), true).', '
                        .var_export((string) ($pivot['related_pivot_key'] ?? Str::singular(Str::snake($declTarget)).'_id'), true);
                    // Absorb (7 Nov): a pivot declaration may
                    // carry the parent/related keys the call
                    // is keyed on (hub relations keyed on
                    // user_id, not id). Absent = the historical
                    // three-argument emission, unchanged.
                    if (! empty($pivot['parent_key']) || ! empty($pivot['related_key'])) {
                        $pivotArgs .= ', '.var_export((string) ($pivot['parent_key'] ?? 'id'), true);
                        if (! empty($pivot['related_key'])) {
                            $pivotArgs .= ', '.var_export((string) $pivot['related_key'], true);
                        }
                    }
                    $modelRelations .= <<<REL


    public function {$declMethod}(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany
    {
        // Resolved among installed slices (and App\\Models) when first used
        \$class = app(\\LaraSlice\\Core\\Discovery\\SliceManager::class)->modelClass({$declTargetExport})
            ?? throw new \\LogicException('No model class named {$declTarget} was found for relation {$declaredRelation['name']}.');

        return \$this->belongsToMany(\$class, {$pivotArgs});
    }
REL;
                    $emittedRelationMethods[$declMethod] = true;

                    continue;
                }
                if ($declType === 'hasManyThrough') {
                    // Through relation (absorbed vocabulary,
                    // 7 Nov): the declaration names the final
                    // model, the through model, and the key
                    // chain (first_key on the through table,
                    // second_key on the final table, local_key
                    // on this model, second_local_key on the
                    // through model). Undeclared keys fall
                    // back to Eloquent's own conventions; a
                    // declaration carrying NO keys emits the
                    // bare two-class call.
                    $throughDecl = (string) ($declaredRelation['through'] ?? '');
                    if ($throughDecl === '') {
                        continue;
                    }
                    $throughTarget = Str::studly(Str::singular($throughDecl));
                    $throughExport = var_export($throughTarget, true);
                    $firstKey = (string) ($declaredRelation['first_key'] ?? Str::snake(Str::singular($tableName)).'_id');
                    $secondKey = (string) ($declaredRelation['second_key'] ?? Str::snake($throughTarget).'_id');
                    $localKey = (string) ($declaredRelation['local_key'] ?? 'id');
                    $secondLocalKey = (string) ($declaredRelation['second_local_key'] ?? 'id');
                    $throughArgs = '';
                    if (isset($declaredRelation['first_key']) || isset($declaredRelation['second_key'])
                        || isset($declaredRelation['local_key']) || isset($declaredRelation['second_local_key'])) {
                        $throughArgs = ', '.var_export($firstKey, true).', '.var_export($secondKey, true)
                            .', '.var_export($localKey, true).', '.var_export($secondLocalKey, true);
                    }
                    $modelRelations .= <<<REL


    public function {$declMethod}(): \\Illuminate\\Database\\Eloquent\\Relations\\HasManyThrough
    {
        // Resolved among installed slices (and App\\Models) when first used
        \$class = app(\\LaraSlice\\Core\\Discovery\\SliceManager::class)->modelClass({$declTargetExport})
            ?? throw new \\LogicException('No model class named {$declTarget} was found for {$secondKey}.');
        \$throughClass = app(\\LaraSlice\\Core\\Discovery\\SliceManager::class)->modelClass({$throughExport})
            ?? throw new \\LogicException('No model class named {$throughTarget} was found for {$firstKey}.');

        return \$this->hasManyThrough(\$class, \$throughClass{$throughArgs});
    }
REL;
                    $emittedRelationMethods[$declMethod] = true;

                    continue;
                }
                $declFk = (string) ($declaredRelation['foreign_key'] ?? ($declType === 'hasMany'
                    ? Str::singular($tableName).'_id'
                    : Str::snake((string) $declaredRelation['name']).'_id'));
                $declFkExport = var_export($declFk, true);
                // Absorb (7 Nov): a declaration may carry
                // the relation's other-side key (local_key
                // for hasMany, owner_key for belongsTo) —
                // emitted as the call's third argument.
                // Absent = the historical two-argument
                // emission, unchanged.
                $declOtherKey = $declType === 'hasMany'
                    ? ($declaredRelation['local_key'] ?? null)
                    : ($declaredRelation['owner_key'] ?? null);
                if (is_string($declOtherKey) && $declOtherKey !== '') {
                    $declFkExport .= ', '.var_export($declOtherKey, true);
                }
                $declReturn = $declType === 'hasMany' ? 'HasMany' : 'BelongsTo';
                $declCall = $declType === 'hasMany' ? 'hasMany' : 'belongsTo';
                // The generated model already imports
                // Relations\BelongsTo, and the field-level
                // emitter below (and every porter-generated
                // model) uses the short name — the declared
                // emitter must match, or the same relation
                // changes shape depending on which loop emits it
                // first (F10: blueprint applies now always feed
                // declared relations through this loop).
                $declReturnType = $declType === 'belongsTo'
                    ? 'BelongsTo'
                    : '\\Illuminate\\Database\\Eloquent\\Relations\\'.$declReturn;
                $modelRelations .= <<<REL


    public function {$declMethod}(): {$declReturnType}
    {
        // Resolved among installed slices (and App\\Models) when first used
        \$class = app(\\LaraSlice\\Core\\Discovery\\SliceManager::class)->modelClass({$declTargetExport})
            ?? throw new \\LogicException('No model class named {$declTarget} was found for relation {$declaredRelation['name']}.');

        return \$this->{$declCall}(\$class, {$declFkExport});
    }
REL;
                $emittedRelationMethods[$declMethod] = true;
            }
            $fieldNames = [];
            foreach ($fields as $def) {
                if (! empty($def['name'])) {
                    $fieldNames[(string) $def['name']] = true;
                }
            }
            foreach ($fields as $def) {
                $isForeignKey = ($def['type'] ?? '') === 'foreign_id' || str_ends_with($def['name'] ?? '', '_id');
                if (! $isForeignKey) {
                    continue;
                }
                $relation = $def['relation'] ?? [];
                $hasDeclaredRelation = (is_array($relation) && $relation !== [])
                    || (is_string($relation) && $relation !== '');
                // Gate T A2d, applied to models: a string-typed
                // *_id is a human/external code (email_id,
                // tax_id, POS ids), not a foreign key — emit no
                // relation for it unless one is declared. The
                // stem-guessed relation (Email, Tax, CustomerPo)
                // can never resolve and throws the first time
                // any surface touches it (record detail 500s).
                if (! $hasDeclaredRelation && ($def['type'] ?? '') !== 'foreign_id'
                    && in_array($def['type'] ?? '', ['string', 'text', 'textarea'], true)) {
                    continue;
                }
                // A polymorphic link (parent_id beside a
                // parent_type column) names its target per row;
                // a plain BelongsTo to the stem guess ("Parent")
                // can never resolve either.
                if (! $hasDeclaredRelation && ! isset($relation['model'])) {
                    $stem = Str::replaceLast('_id', '', (string) ($def['name'] ?? ''));
                    if ($stem !== '' && isset($fieldNames[$stem.'_type'])
                        && $this->resolveForeignTargetModel((string) $def['name'], (string) ($tableName ?? '')) === null) {
                        continue;
                    }
                }
                $relName = Str::camel($relation['name'] ?? Str::replaceLast('_id', '', $def['name']));
                if (isset($emittedRelationMethods[$relName])) {
                    continue;
                }
                $emittedRelationMethods[$relName] = true;
                $targetModel = isset($relation['model'])
                    ? Str::studly(Str::singular($relation['model']))
                    : ($this->resolveForeignTargetModel((string) $def['name'], (string) ($tableName ?? ''))
                        ?? Str::studly(Str::singular(Str::replaceLast('_id', '', $def['name']))));
                $foreignKey = var_export($def['name'], true);
                $targetExport = var_export($targetModel, true);
                $modelRelations .= <<<REL


    public function {$relName}(): BelongsTo
    {
        // Resolved among installed slices (and App\\Models) when first used
        \$class = app(\\LaraSlice\\Core\\Discovery\\SliceManager::class)->modelClass({$targetExport})
            ?? throw new \\LogicException('No model class named {$targetModel} was found for {$def['name']}.');

        return \$this->belongsTo(\$class, {$foreignKey});
    }
REL;
            }

            $modelContent .= <<<PHP

class {$studlyName} extends Model
{
    use AuditableSlice;
PHP;
            if ($softDeletes) {
                $modelContent .= "\n    use SoftDeletes;\n";
            }
            if ($includeWorkflow) {
                $modelContent .= "\n    use HasWorkflow;\n";
            }
            if ($multitenant) {
                $modelContent .= "\n    use \\LaraSlice\\Core\\Tenancy\\Traits\\HasTenant;\n";
            }
            $modelContent .= <<<PHP

    protected \$table = '{$tableName}';
    protected \$fillable = {$fillable};{$modelCasts}
{$modelRelations}
}
PHP;
            $this->writeFile($sliceDir."/Models/{$studlyName}.php", $modelContent);

            // 3. Contracts (DTOs)
            $formDto = <<<PHP
<?php

namespace {$sliceNamespace}\\Contracts;

use LaraSlice\\Core\\Base\\BaseFormBusinessObject;

class {$studlyName}FormBusinessObject extends BaseFormBusinessObject
{
{$dtoFormBody}
}
PHP;
            $this->writeFile($sliceDir."/Contracts/{$studlyName}FormBusinessObject.php", $formDto);

            $listingDto = <<<PHP
<?php

namespace {$sliceNamespace}\\Contracts;

use LaraSlice\\Core\\Base\\BaseListingBusinessObject;

class {$studlyName}ListingBusinessObject extends BaseListingBusinessObject
{
{$dtoListingBody}
}
PHP;
            $this->writeFile($sliceDir."/Contracts/{$studlyName}ListingBusinessObject.php", $listingDto);

            $filterDto = <<<PHP
<?php

namespace {$sliceNamespace}\\Contracts;

use LaraSlice\\Core\\Base\\BaseFilter;

class {$studlyName}FilterBusinessObject extends BaseFilter
{
    protected function sortableColumns(): array
    {
        return [{$sortableColumns}];
    }
}
PHP;
            $this->writeFile($sliceDir."/Contracts/{$studlyName}FilterBusinessObject.php", $filterDto);

            // 4. Service
            $repeaterServiceMethod = $repeaterDefinitions === []
                ? ''
                : "\n    public function repeaterDefinitions(): array\n    {\n        return ".var_export($repeaterDefinitions, true).";\n    }\n";
            $relationMultiServiceMethod = $relationMultiDefinitions === []
                ? ''
                : "\n    public function relationMultiDefinitions(): array\n    {\n        return ".var_export($relationMultiDefinitions, true).";\n    }\n";
            $multiselectServiceMethod = $multiselectDefinitions === []
                ? ''
                : "\n    public function multiselectDefinitions(): array\n    {\n        return ".var_export($multiselectDefinitions, true).";\n    }\n";
            $mapToFormBody = "        return {$studlyName}FormBusinessObject::fromArray(\$model->toArray());";
            if ($relationMultiDefinitions !== []) {
                $mapToFormBody = "        \$bo = {$studlyName}FormBusinessObject::fromArray(\$model->toArray());";
                foreach ($relationMultiDefinitions as $multiHandle => $multiDef) {
                    $multiRelation = $multiDef['relation'] ?? $multiHandle;
                    $mapToFormBody .= "\n        \$bo->{$multiHandle} = \$model->exists ? \$model->{$multiRelation}()->get()->map(fn (\$rel) => (int) \$rel->getKey())->all() : [];";
                }
                $mapToFormBody .= "\n\n        return \$bo;";
            }
            $service = <<<PHP
<?php

namespace {$sliceNamespace}\\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use LaraSlice\\Core\\Base\\BaseSliceService;
use LaraSlice\\Core\\Contracts\\IBusinessObject;
use Illuminate\\Support\\Facades\\Validator;
use {$sliceNamespace}\\Models\\{$studlyName};
use {$sliceNamespace}\\Contracts\\{$studlyName}FormBusinessObject;
use {$sliceNamespace}\\Contracts\\{$studlyName}ListingBusinessObject;

class {$studlyName}SliceService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return {$studlyName}::class;
    }

{$repeaterServiceMethod}{$relationMultiServiceMethod}{$multiselectServiceMethod}
    protected function mapToForm(Model \$model): IBusinessObject
    {
{$mapToFormBody}
    }

    protected function mapToListing(Model \$model): IBusinessObject
    {
        return {$studlyName}ListingBusinessObject::fromArray(\$model->toArray());
    }

    protected function applySearch(Builder \$query, string \$search): void
    {
        \$query->where('{$searchColumn}', 'LIKE', "%\$search%");
    }

    protected function validate(IBusinessObject \$form): void
    {
        Validator::make(\$form->toArray(), [
{$validationRules}
        ])->validate();
    }
}
PHP;
            $this->writeFile($sliceDir."/Services/{$studlyName}SliceService.php", $service);

            // 5. API Controller
            $apiController = <<<PHP
<?php

namespace {$sliceNamespace}\\Controllers;

use LaraSlice\\Core\\Base\\BaseSliceApiController;
use LaraSlice\\Core\\Contracts\\IFormDataService;
use LaraSlice\\Core\\Contracts\\IListingDataService;
use {$sliceNamespace}\\Services\\{$studlyName}SliceService;
use {$sliceNamespace}\\Contracts\\{$studlyName}FormBusinessObject;
use {$sliceNamespace}\\Contracts\\{$studlyName}FilterBusinessObject;

class {$studlyName}ApiController extends BaseSliceApiController
{
    protected {$studlyName}SliceService \$service;

    public function __construct({$studlyName}SliceService \$service)
    {
        \$this->service = \$service;
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        return \$this->service;
    }

    protected function getFormClass(): string
    {
        return {$studlyName}FormBusinessObject::class;
    }

    protected function getFilterClass(): string
    {
        return {$studlyName}FilterBusinessObject::class;
    }
}
PHP;
            if ($includeApi) {
                $this->writeFile($sliceDir."/Controllers/{$studlyName}ApiController.php", $apiController);
            }

            $pluralSnake = Str::snake($pluralName);

            // 6. Web Controller
            $webController = <<<PHP
<?php

namespace {$sliceNamespace}\\Controllers;

use LaraSlice\\Core\\Base\\BaseSliceWebController;
use LaraSlice\\Core\\Contracts\\IFormDataService;
use LaraSlice\\Core\\Contracts\\IListingDataService;
use {$sliceNamespace}\\Services\\{$studlyName}SliceService;
use {$sliceNamespace}\\Contracts\\{$studlyName}FormBusinessObject;
use {$sliceNamespace}\\Contracts\\{$studlyName}FilterBusinessObject;

class {$studlyName}WebController extends BaseSliceWebController
{
    protected {$studlyName}SliceService \$service;

    public function __construct({$studlyName}SliceService \$service)
    {
        \$this->service = \$service;
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        return \$this->service;
    }

    protected function getFormClass(): string
    {
        return {$studlyName}FormBusinessObject::class;
    }

    protected function getFilterClass(): string
    {
        return {$studlyName}FilterBusinessObject::class;
    }

    protected function getViewPrefix(): string
    {
        return '{$pluralSnake}::';
    }

    protected function getRoutePrefix(): string
    {
        return '{$routeNamePrefix}.';
    }
}
PHP;
            $this->writeFile($sliceDir."/Controllers/{$studlyName}WebController.php", $webController);

            $targetRedirect = $domainSlug ? "{$domainSlug}/{$pluralSnake}" : $pluralSnake;
            $aliasRedirects = [];
            $kebabPlural = Str::kebab($pluralName);
            $lowerPlural = strtolower($pluralName);
            $kebabSingular = Str::kebab($studlyName);
            $lowerSingular = strtolower($studlyName);

            if ($domainSlug) {
                $aliasRedirects[] = "Route::redirect('{$pluralSnake}', '/{$targetRedirect}');";
                // Legacy record URLs only: NEVER a '.*' wildcard here.
                // A wildcard alias swallows the whole bare-prefix
                // namespace — which may be ANOTHER module's live
                // route prefix (gate-caught defect, ERPNext port
                // 13 Nov 2026: the CRM Contacts slice's
                // 'contacts/{any}' alias made the Contacts
                // module's Addresses/Genders/Salutations slices
                // unreachable — lists redirected away, edits 404'd).
                // Record ids in this framework are numeric, so the
                // two legacy shapes below cover every real old URL.
                $aliasRedirects[] = "Route::redirect('{$pluralSnake}/{id}', '/{$targetRedirect}/{id}')->where('id', '[0-9]+');";
                $aliasRedirects[] = "Route::redirect('{$pluralSnake}/{id}/edit', '/{$targetRedirect}/{id}/edit')->where('id', '[0-9]+');";
            }

            foreach (array_unique([$snakeName, $kebabPlural, $lowerPlural, $kebabSingular, $lowerSingular]) as $altSlug) {
                if ($altSlug !== $pluralSnake && $altSlug !== $targetRedirect) {
                    $aliasRedirects[] = "Route::redirect('{$altSlug}', '/{$targetRedirect}');";
                }
            }
            $aliasRedirectCode = implode("\n", $aliasRedirects);

            // One named group only: identical URIs under several names collapse to the last one
            // in a compiled route cache, which broke route('...') calls after `php artisan route:cache`.
            $domainNamedRoutes = '';
            $aliasRoutes = '';

            $webRoutes = <<<PHP
<?php

use Illuminate\Support\Facades\Route;
use {$sliceNamespace}\\Controllers\\{$studlyName}WebController;

// Plural resource routes: {$routeNamePrefix}.index, {$routeNamePrefix}.create, etc.
Route::prefix('{$routePrefix}')->name('{$routeNamePrefix}.')->middleware(config('laraslice.generated_routes.web_middleware', ['web', 'auth']))->group(function () {
    Route::get('/', [{$studlyName}WebController::class, 'index'])->name('index');
    Route::get('/create', [{$studlyName}WebController::class, 'create'])->name('create');
    Route::post('/', [{$studlyName}WebController::class, 'store'])->name('store');
    Route::get('/{id}', [{$studlyName}WebController::class, 'show'])->name('show');
    Route::get('/{id}/edit', [{$studlyName}WebController::class, 'edit'])->name('edit');
    Route::get('/{id}/convert/{target}', [{$studlyName}WebController::class, 'convert'])->name('convert');
    Route::put('/{id}', [{$studlyName}WebController::class, 'update'])->name('update');
    Route::delete('/{id}', [{$studlyName}WebController::class, 'destroy'])->name('destroy');
});
{$domainNamedRoutes}
{$aliasRoutes}

// URL slug alias redirects
{$aliasRedirectCode}
PHP;
            $this->writeFile($sliceDir.'/Routes/web.php', $webRoutes);

            $apiPrefix = $domainSlug ? "{$domainSlug}/{$pluralSnake}" : $pluralSnake;
            $apiFlatAlias = $domainSlug ? <<<PHP

// Flat API alias fallback
Route::prefix('{$pluralSnake}')->middleware(config('laraslice.generated_routes.api_middleware', ['api', 'auth:sanctum']))->group(function () {
    Route::post('/list', [{$studlyName}ApiController::class, 'getList']);
    Route::get('/{id}', [{$studlyName}ApiController::class, 'getItemById']);
    Route::post('/save', [{$studlyName}ApiController::class, 'save']);
    Route::delete('/{id}', [{$studlyName}ApiController::class, 'delete']);
});
PHP : '';

            $apiRoutes = <<<PHP
<?php

use Illuminate\Support\Facades\Route;
use {$sliceNamespace}\\Controllers\\{$studlyName}ApiController;

Route::prefix('{$apiPrefix}')->middleware(config('laraslice.generated_routes.api_middleware', ['api', 'auth:sanctum']))->group(function () {
    Route::post('/list', [{$studlyName}ApiController::class, 'getList']);
    Route::get('/{id}', [{$studlyName}ApiController::class, 'getItemById']);
    Route::post('/save', [{$studlyName}ApiController::class, 'save']);
    Route::delete('/{id}', [{$studlyName}ApiController::class, 'delete']);
});
{$apiFlatAlias}
PHP;
            if ($includeApi) {
                $this->writeFile($sliceDir.'/Routes/api.php', $apiRoutes);
            }

            // 8. Migration
            $timestamp = date('Y_m_d_His');
            $auditCols = '';
            if (! in_array('created_by', $customColumns, true)) {
                $auditCols .= "\n                \$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();";
            }
            if (! in_array('updated_by', $customColumns, true)) {
                $auditCols .= "\n                \$table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();";
            }

            // D-014: declared child tables (repeaters and
            // multiselects) are created by this same migration —
            // before this fix the builder emitted only the parent
            // table and every child table needed a hand-written
            // slice migration. Empty for childless slices, so a
            // slice with no declared children produces a
            // byte-identical migration to before.
            $childMigrationUp = $this->childMigrationUp($repeaterDefinitions, $multiselectDefinitions, $tableName);
            $childMigrationDown = $this->childMigrationDown($repeaterDefinitions, $multiselectDefinitions);

            $migration = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('{$tableName}')) {
            return;
        }

        Schema::create('{$tableName}', function (Blueprint \$table) {
                \$table->id();
{$migrationBody}{$auditCols}
                \$table->timestamps();{$migrationSoftDeletes}
        });{$childMigrationUp}
    }

    public function down(): void
    {
{$childMigrationDown}        Schema::dropIfExists('{$tableName}');
    }
};
PHP;
            $this->writeFile($sliceDir."/Migrations/{$timestamp}_create_{$tableName}_table.php", $migration);

            // 9. Filament-inspired Declarative Schema
            $schemaContent = <<<PHP
<?php

namespace {$sliceNamespace}\\Schemas;

use LaraSlice\Schema\SliceSchema;
use LaraSlice\Schema\Field;
use LaraSlice\Schema\Column;

class {$studlyName}Schema extends SliceSchema
{
    public static function form(): array
    {
        return [
{$schemaFormBody}
        ];
    }

    public static function table(): array
    {
        return [
{$schemaTableBody}
        ];
    }
}
PHP;
            $this->writeFile($sliceDir."/Schemas/{$studlyName}Schema.php", $schemaContent);

            // 10. Pure BlatUI Views with Universal Layout Integration
            $rowActionsDisplay = $options['ui']['directory']['row_actions']['display'] ?? 'dropdown';
            $rowActionsSlot = '';
            if ($rowActionsDisplay === 'hybrid') {
                $rowActionsSlot = <<<ACTIONS
            <x-slot:actions>
                <div class="flex items-center gap-1 justify-end">
                    <a :href="item.r.show_url" @if((\$presentation['show'] ?? 'page') !== 'page') :data-overlay-url="item.r.show_url" data-overlay-mode="{{ \$presentation['show'] }}" data-overlay-title="View" @endif class="size-8 rounded-lg hover:bg-muted/80 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors border border-transparent hover:border-border/60" title="View">
                        <x-lucide-eye class="size-4" />
                    </a>
                    <x-ui.row-actions
                        actions="item.r.actions"
                        edit-url="item.r.edit_url"
                        delete-url="item.r.delete_url"
                        delete-confirm="Are you sure you want to delete this {$studlyName}?"
                        edit-overlay="{{ (\$presentation['edit'] ?? 'page') !== 'page' ? \$presentation['edit'] : '' }}"
                    />
                </div>
            </x-slot:actions>
ACTIONS;
            } elseif ($rowActionsDisplay === 'inline') {
                $rowActionsSlot = <<<ACTIONS
            <x-slot:actions>
                <div class="flex items-center gap-1 justify-end">
                    <a :href="item.r.show_url" @if((\$presentation['show'] ?? 'page') !== 'page') :data-overlay-url="item.r.show_url" data-overlay-mode="{{ \$presentation['show'] }}" data-overlay-title="View" @endif class="size-8 rounded-lg hover:bg-muted/80 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors" title="View">
                        <x-lucide-eye class="size-4" />
                    </a>
                    <a :href="item.r.edit_url" @if((\$presentation['edit'] ?? 'page') !== 'page') :data-overlay-url="item.r.edit_url" data-overlay-mode="{{ \$presentation['edit'] }}" data-overlay-title="Edit" @endif class="size-8 rounded-lg hover:bg-muted/80 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors" title="Edit">
                        <x-lucide-pencil class="size-4" />
                    </a>
                    <form method="POST" :action="item.r.delete_url" onsubmit="return confirm('Are you sure you want to delete this {$studlyName}?')" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="size-8 rounded-lg hover:bg-rose-500/10 flex items-center justify-center text-rose-500 transition-colors" title="Delete">
                            <x-lucide-trash-2 class="size-4" />
                        </button>
                    </form>
                </div>
            </x-slot:actions>
ACTIONS;
            } else {
                // Default 'dropdown' display: compact row-actions dropdown menu.
                $rowActionsSlot = <<<ACTIONS
            <x-slot:actions>
                <div class="flex items-center gap-1 justify-end">
                    <x-ui.row-actions
                        actions="item.r.actions"
                        view-url="item.r.show_url"
                        edit-url="item.r.edit_url"
                        delete-url="item.r.delete_url"
                        delete-confirm="Are you sure you want to delete this {$studlyName}?"
                        view-overlay="{{ (\$presentation['show'] ?? 'page') !== 'page' ? \$presentation['show'] : '' }}"
                        edit-overlay="{{ (\$presentation['edit'] ?? 'page') !== 'page' ? \$presentation['edit'] : '' }}"
                    />
                </div>
            </x-slot:actions>
ACTIONS;
            }
            // Quick-entry dialog seat (D-011): a slice declaring
            // ui.quick_entry.fields gets the framework's
            // quick-entry partial included in its directory —
            // the modal markup itself lives in the framework
            // partial (laraslice::directory.quick-entry), so the
            // fleet receives pattern changes centrally. Handles
            // that are not quick-entry material (repeater /
            // multiselect / relation_multi / computed / unknown)
            // are ignored here exactly as DirectoryUi::
            // quickEntryFields() ignores them at runtime
            // (ignore-with-warning policy). Undeclared slices
            // emit NO quick-entry markup at all.
            $quickEntryInclude = '';
            $declaredQuickEntry = $options['ui']['quick_entry']['fields'] ?? null;
            if (is_array($declaredQuickEntry) && $declaredQuickEntry !== []) {
                $fieldsByHandle = [];
                foreach ($fields as $qf) {
                    if (is_array($qf) && ! empty($qf['name'])) {
                        $fieldsByHandle[(string) $qf['name']] = $qf;
                    }
                }
                $hasQuickEntryField = false;
                foreach ($declaredQuickEntry as $qeEntry) {
                    $qeHandle = is_array($qeEntry) ? (string) ($qeEntry['handle'] ?? '') : (string) $qeEntry;
                    $qeDef = $fieldsByHandle[$qeHandle] ?? null;
                    if ($qeDef !== null
                        && ! in_array((string) ($qeDef['type'] ?? ''), ['repeater', 'multiselect', 'relation_multi'], true)
                        && empty($qeDef['computed'])) {
                        $hasQuickEntryField = true;
                        break;
                    }
                }
                if ($hasQuickEntryField) {
                    $quickEntryInclude = "\n@include('laraslice::directory.quick-entry')\n";
                }
            }
            $sliceJsonAbs = str_replace(["'"], ["\\'"], str_replace('\\', '/', rtrim($sliceDir, '/\\').'/slice.yaml'));
            $sliceYamlAbs = str_replace('slice.yaml', 'slice.yaml', $sliceJsonAbs);

            $bladeIndex = <<<BLADE
@extends('layouts.app')

@section('content')
{{-- The band rhythm applies only when KPI cards render above
     the table; otherwise the spacing margin lands on the
     zero-height chrome (bulk bar, drawer) and reads as dead
     space above the actions row. --}}
@php \$hasKpiCards = !empty(\$summaryCards) || !empty(\$dashWidgets); @endphp
<div class="{{ \$hasKpiCards ? 'space-y-2' : '' }}">
    {{-- Validation errors land here too (D-076): actions posted
         FROM the directory (the quick entry dialog above all)
         redirect back with an error bag the directory must
         render — the record form has its own block; without
         this one a rejected quick entry save was invisible. --}}
    @if(\$errors->any())
        <div class="p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm shadow-xs" role="alert">
            <div class="font-semibold flex items-center gap-2">
                <x-lucide-alert-circle class="size-4 shrink-0" />
                <span>Please fix the following:</span>
            </div>
            <ul class="list-disc pl-9 mt-1 space-y-0.5">
                @foreach (\$errors->all() as \$error)
                    <li>{{ \$error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Bulk workflow forward (shows when table rows checked) --}}
    <x-workflows::bulk-bar slice="{$studlyName}" />

    {{-- Page-top composition (owner rule, 13 Nov 2026): page
         identity — title + breadcrumb — lives ONLY in the shell
         topbar; an in-page title/breadcrumb row duplicates it.
         This row therefore survives solely as the actions host
         when placement is header-right. --}}
    @if((\$placement ?? 'below-facets-left') === 'header-right')
    <div class="flex items-center gap-3 pb-2 border-b border-border/40">
        <h1 class="text-base font-semibold text-foreground">{{ \$directoryTitle ?? '' }}</h1>
        {{-- Page Structure Contract (Body v4): placement header-right
             renders the page actions in this row; the below-toolbar
             band is suppressed by the same declaration (and the
             data-table renders no empty band). Seed Demo stays
             band-only by contract and does not render here. --}}
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if(\$can['create'] ?? true)
            @if((\$presentation['create'] ?? 'page') !== 'page')
            <button type="button" data-overlay-url="{{ route('{$routeNamePrefix}.create') }}" data-overlay-mode="{{ \$presentation['create'] }}" data-overlay-title="{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-xs transition-colors cursor-pointer">
                <x-lucide-plus class="size-3.5" />
                <span>{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}</span>
            </button>
            @else
            <x-ui.button href="{{ route('{$routeNamePrefix}.create') }}" as="a" size="sm" class="gap-1.5 shadow-xs font-semibold">
                <x-lucide-plus class="size-3.5" />
                <span>{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}</span>
            </x-ui.button>
            @endif
            @endif
            @include('laraslice::directory.actions')
            @isset(\$importUrl)
            <x-ui.button href="{{ \$importUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-upload class="size-4" /> <span>Import</span>
            </x-ui.button>
            @endisset
            @isset(\$bulkEntryUrl)
            <x-ui.button href="{{ \$bulkEntryUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-clipboard-check class="size-3.5" />
                <span>{{ \$bulkEntryLabel ?? 'Bulk Entry' }}</span>
            </x-ui.button>
            @endisset
            @isset(\$reportUrl)
            <x-ui.button href="{{ \$reportUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-bar-chart-3 class="size-3.5" />
                <span>Report</span>
            </x-ui.button>
            @endisset
            @isset(\$matrixUrl)
            <x-ui.button href="{{ \$matrixUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-grid-3x3 class="size-3.5" />
                <span>Matrix</span>
            </x-ui.button>
            @endisset
            @if(\$directoryExport ?? true)
            @isset(\$exportUrl)
            <a href="{{ \$exportUrl }}" onclick="this.href = this.href.split('?')[0] + window.location.search" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors">
                <x-lucide-download class="size-3.5 text-muted-foreground" />
                <span>Export</span>
            </a>
            @else
            <button type="button" onclick="window.dispatchEvent(new CustomEvent('export-table'))" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors">
                <x-lucide-download class="size-3.5 text-muted-foreground" />
                <span>Export</span>
            </button>
            @endisset
            @endif
        </div>
    </div>
    @endif

    @if((\$placement ?? 'below-facets-left') === 'command-bar')
    {{-- Page Structure Contract (Body v4): placement command-bar
         renders the page actions as a full-width command strip
         at the page top (sequenced-commands style). --}}
    <div class="flex flex-wrap items-center gap-2 rounded-xl border border-border/60 bg-muted/20 px-3 py-2">
        @if(\$can['create'] ?? true)
        @if((\$presentation['create'] ?? 'page') !== 'page')
        <button type="button" data-overlay-url="{{ route('{$routeNamePrefix}.create') }}" data-overlay-mode="{{ \$presentation['create'] }}" data-overlay-title="{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-xs transition-colors cursor-pointer">
            <x-lucide-plus class="size-3.5" />
            <span>{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}</span>
        </button>
        @else
        <x-ui.button href="{{ route('{$routeNamePrefix}.create') }}" as="a" size="sm" class="gap-1.5 shadow-xs font-semibold">
            <x-lucide-plus class="size-3.5" />
            <span>{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}</span>
        </x-ui.button>
        @endif
        @endif
        @include('laraslice::directory.actions')
        @isset(\$importUrl)
        <x-ui.button href="{{ \$importUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
            <x-lucide-upload class="size-4" /> <span>Import</span>
        </x-ui.button>
        @endisset
        @isset(\$bulkEntryUrl)
        <x-ui.button href="{{ \$bulkEntryUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
            <x-lucide-clipboard-check class="size-3.5" />
            <span>{{ \$bulkEntryLabel ?? 'Bulk Entry' }}</span>
        </x-ui.button>
        @endisset
        @isset(\$reportUrl)
        <x-ui.button href="{{ \$reportUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
            <x-lucide-bar-chart-3 class="size-3.5" />
            <span>Report</span>
        </x-ui.button>
        @endisset
        @isset(\$matrixUrl)
        <x-ui.button href="{{ \$matrixUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
            <x-lucide-grid-3x3 class="size-3.5" />
            <span>Matrix</span>
        </x-ui.button>
        @endisset
        @if(\$directoryExport ?? true)
        <button type="button" onclick="window.dispatchEvent(new CustomEvent('export-table'))" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors">
            <x-lucide-download class="size-3.5 text-muted-foreground" />
            <span>Export</span>
        </button>
        @endif
    </div>
    @endif

    @if (session('success'))
        <div class="p-3.5 rounded-xl border border-success/30 bg-success/10 text-success text-xs font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @php
        \$columns = [
{$dataTableColumns}
            // @laraslice:columns
        ];
        // Runtime: load formatters via registered slice path (vendor + path installs)
        \$columns = \\LaraSlice\\Support\\DirectoryUi::columnsForSlice('{$studlyName}', \$columns);
        \$canEdit = \$can['edit'] ?? true;
        \$canDelete = \$can['delete'] ?? true;
        \$rows = \\LaraSlice\\Support\\DataTableRows::from(\$pagedList->items, \$columns, fn (\$item) => [
            'show_url'   => \Illuminate\Support\Facades\Route::has('{$routeNamePrefix}.show') ? route('{$routeNamePrefix}.show', \$item->id) : null,
            'edit_url'   => \$canEdit && \Illuminate\Support\Facades\Route::has('{$routeNamePrefix}.edit') ? route('{$routeNamePrefix}.edit', \$item->id) : null,
            'delete_url' => \$canDelete && \Illuminate\Support\Facades\Route::has('{$routeNamePrefix}.destroy') ? route('{$routeNamePrefix}.destroy', \$item->id) : null,
            'actions'    => \\LaraSlice\\Support\\RecordActions::urlsFor('{$routeNamePrefix}', \$item->id, 'directory'),
        ], '{$tableName}');
    @endphp

    @if(!empty(\$directoryNotice ?? null))
        <div class="mb-4 flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-[13px] text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200">
            <x-lucide-info class="size-4 mt-0.5 shrink-0" />
            <span>{{ \$directoryNotice }}</span>
        </div>
    @endif

    {{-- The first-run empty panel replaces the directory ONLY
         for slices that opt in via ui.directory.demo_seed, and
         only when nothing is filtered: a list filtered down to
         zero keeps its chrome (filter bar, actions, table shell
         with the in-table empty row) like the source systems —
         otherwise the visitor cannot see or clear the filter
         that emptied it. Ported directories never opt in: an
         empty source table renders its shell, not a panel. --}}
    @if (count(\$rows) === 0 && empty(array_filter(\$activeFilters ?? [])) && (\$defaultView ?? 'table') !== 'calendar' && (\$demoSeed ?? false))
        <div class="flex flex-col items-center justify-center gap-3 py-12 text-center text-muted-foreground rounded-2xl border border-dashed border-border bg-card/50">
            <x-lucide-inbox class="size-8 text-muted-foreground/50" />
            <div>
                <p class="text-sm font-semibold text-foreground">No records found in {$pluralName}</p>
                <p class="text-xs text-muted-foreground mt-0.5">Start by creating your first entry or generate realistic mock data.</p>
            </div>
            <div class="flex items-center gap-2 pt-1">
                @if(\$can['create'] ?? true)
                <x-ui.button href="{{ route('{$routeNamePrefix}.create') }}" as="a" size="sm">
                    Create {$studlyName}
                </x-ui.button>
                @endif
                @include('laraslice::directory.actions')
                @isset(\$importUrl)
                <x-ui.button href="{{ \$importUrl }}" as="a" variant="secondary" size="sm" class="border border-border gap-1.5">
                    <x-lucide-upload class="size-3.5" /> <span>Import</span>
                </x-ui.button>
                @endisset
                @if((\$can['seed'] ?? true) && Route::has('laraslice.wizard.seed_slice'))
                    <form action="{{ route('laraslice.wizard.seed_slice') }}" method="POST" class="inline">
                        @csrf
                        <input type="hidden" name="slice" value="{$pluralName}">
                        <input type="hidden" name="count" value="10">
                        <x-ui.button type="submit" variant="secondary" size="sm" class="gap-1.5 text-amber-600 dark:text-amber-400">
                            <x-lucide-sparkles class="size-3.5" />
                            <span>Seed 10 Demo Records</span>
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    @else
        <!-- KPI strip: ui.directory.summary_cards OR ui.dashboard.widgets -->
        @php
            \$dashWidgets = [];
            try {
                \$_mf = '{$sliceJsonAbs}';
                if (is_file(\$_mf)) {
                    \$_m = json_decode((string) file_get_contents(\$_mf), true) ?: [];
                    \$dashWidgets = \$_m['ui']['dashboard']['widgets'] ?? [];
                    if (! is_array(\$dashWidgets)) { \$dashWidgets = []; }
                }
            } catch (\Throwable \$e) { \$dashWidgets = []; }
            \$kpiCards = !empty(\$summaryCards) ? \$summaryCards : \$dashWidgets;
            if (empty(\$summaryData) || ! is_array(\$summaryData)) {
                \$summaryData = [];
            }
            // Default counts when controller did not pass summaryData
            if (\$kpiCards !== [] && \$summaryData === [] && isset(\$pagedList)) {
                \$summaryData['total'] = is_countable(\$pagedList->items ?? null)
                    ? count(\$pagedList->items)
                    : (isset(\$pagedList->total) ? \$pagedList->total : '—');
            }
        @endphp
        @if(!empty(\$kpiCards))
        <div class="flex flex-wrap gap-3 [&>*]:flex-1 [&>*]:min-w-44 [&>*]:max-w-xs">
            @foreach(\$kpiCards as \$card)
            <div class="rounded-lg border border-border bg-card px-4 py-3 shadow-2xs">
                <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ \$card['label'] ?? (\$card['handle'] ?? 'Stat') }}</p>
                <p class="text-xl font-semibold text-foreground mt-1 tabular-nums">{{ \$summaryData[\$card['key'] ?? \$card['handle'] ?? ''] ?? (\$card['handle'] === 'total' ? (\$summaryData['total'] ?? '—') : '—') }}</p>
            </div>
            @endforeach
        </div>
        @endif

        @include('laraslice::directory.strip-filters')

        @include('laraslice::directory.list-controls')

        @include('laraslice::directory.filters-drawer')

        <!-- High-Density Data Table with Automatic 3-Dots Action Column -->
        @php
            // Studio YAML bulk_actions (ui.directory.bulk_actions) + DB workflow engine
            \$wfTransitions = [];
            \$wfBulkUrl = null;
            \$bulkFromYaml = [];
            try {
                \$sliceJson = '{$sliceJsonAbs}';
                \$sliceYaml = '{$sliceYamlAbs}';
                \$manifest = [];
                if (file_exists(\$sliceJson)) {
                    \$manifest = json_decode(file_get_contents(\$sliceJson), true) ?: [];
                } elseif (file_exists(\$sliceYaml) && class_exists(\\Symfony\\Component\\Yaml\\Yaml::class)) {
                    \$manifest = \\Symfony\\Component\\Yaml\\Yaml::parseFile(\$sliceYaml) ?: [];
                }
                \$bulkFromYaml = \$manifest['ui']['directory']['bulk_actions'] ?? [];
            } catch (\\Throwable) {}
            foreach ((array) \$bulkFromYaml as \$ba) {
                if (! is_array(\$ba)) {
                    continue;
                }
                if ((\$ba['type'] ?? '') === 'workflow') {
                    \$wfTransitions[] = [
                        'slug' => \$ba['transition'] ?? \$ba['handle'] ?? 'transition',
                        'label' => \$ba['label'] ?? \$ba['handle'] ?? 'Transition',
                        'requires_remarks' => (bool) (\$ba['requires_remarks'] ?? false),
                    ];
                }
            }
            if (\\Illuminate\\Support\\Facades\\Route::has('workflows.bulk_transition') && class_exists(\\LaraSlice\\Slices\\Workflows\\Services\\WorkflowEngineService::class)) {
                try {
                    \$wf = app(\\LaraSlice\\Slices\\Workflows\\Services\\WorkflowEngineService::class)->getWorkflowForSlice('{$studlyName}');
                    if (\$wf) {
                        \$wfBulkUrl = route('workflows.bulk_transition', ['slice' => '{$studlyName}'], false);
                        if (empty(\$wfTransitions)) {
                            \$wfTransitions = \$wf->transitions->map(fn (\$t) => [
                                'slug' => \$t->slug,
                                'label' => \$t->label ?? \$t->slug,
                                'requires_remarks' => (bool) \$t->requires_remarks,
                            ])->values()->all();
                        }
                    }
                } catch (\\Throwable) {}
            }
        @endphp
        @php
            \$dirUi = \$manifest['ui']['directory'] ?? [];
            // Controller-passed values (own manifest) win; the
            // file-read \$dirUi is only a fallback — its relative
            // path silently misses in compiled views.
            \$tableViews = \$tableViews ?? \$dirUi['views'] ?? ['table'];
            if (! is_array(\$tableViews) || \$tableViews === []) {
                \$tableViews = ['table'];
            }
            \$defaultView = \$defaultView ?? \$dirUi['default_view'] ?? 'table';
            \$inlineEdit = \$inlineEdit ?? \$dirUi['inline_edit'] ?? [];
            if (! is_array(\$inlineEdit)) {
                \$inlineEdit = [];
            }
            \$statusKey = 'status';
            \$statusFound = false;
            \$dateKey = null;
            foreach (\$columns as \$c) {
                \$ck = \$c['key'] ?? '';
                if (in_array(\$ck, ['status', 'state', 'stage'], true)) {
                    \$statusKey = \$ck;
                    \$statusFound = true;
                }
                if (\$dateKey === null && (str_contains(\$ck, 'date') || str_ends_with(\$ck, '_at'))) {
                    \$dateKey = \$ck;
                }
            }
            if (! \$statusFound) {
                // Board-axis fallback: an FK column the declaration
                // labels "Status"/"Stage" (Tasks: board_column_id)
                // is the board's grouping axis — without it the
                // board groups every row under "Unknown".
                foreach (\$columns as \$c) {
                    \$ck = \$c['key'] ?? '';
                    \$clabel = \$c['label'] ?? '';
                    if (str_ends_with(\$ck, '_id')
                        && (in_array(\$clabel, ['Status', 'Stage'], true)
                            || str_contains(\$ck, 'board')
                            || str_contains(\$ck, 'stage')
                            || str_contains(\$ck, 'status'))) {
                        \$statusKey = \$ck;
                        break;
                    }
                }
            }
            \$boardAxis = [];
            foreach (\$columns as \$c) {
                if ((\$c['key'] ?? '') === \$statusKey && ! empty(\$c['options']) && is_array(\$c['options'])) {
                    \$boardAxis = \$c['options'];
                    break;
                }
            }
            foreach (['due_date', 'start_date', 'issue_date', 'date'] as \$preferDate) {
                foreach (\$columns as \$c) {
                    if ((\$c['key'] ?? '') === \$preferDate) {
                        \$dateKey = \$preferDate;
                        break 2;
                    }
                }
            }
        @endphp
        <x-ui.data-table 
            :columns="\$columns" 
            :rows="\$rows" 
            :page-size="10" 
            search-placeholder="Start typing to search..."
            :workflow-transitions="\$wfTransitions"
            :workflow-bulk-url="\$wfBulkUrl"
            workflow-slice="{$studlyName}"
            :bulk-delete-url="\Illuminate\Support\Facades\Route::has('laraslice.bulk_action') ? route('laraslice.bulk_action', ['{$routeNamePrefix}', 'delete']) : null"
            :views="\$tableViews"
            :default-view="\$defaultView"
            :status-key="\$statusKey"
            :board-axis="\$boardAxis"
            :inline-edit="\$inlineEdit"
            :date-key="\$dateKey"
            :inline-update-url="\$inlineUpdateUrl ?? null"
            :density="\$density ?? 'compact'"
            :columns-menu="\$columnsMenu ?? true"
            :selectable="\$directorySelectable ?? true"
        >
            <x-slot:toolbar-lead>
            @if((\$placement ?? 'below-facets-left') === 'toolbar-left')
            {{-- Page Structure Contract (Body v4): placement
                 toolbar-left renders the page actions at the
                 toolbar's left edge, ahead of facets + search. --}}
            @if(\$can['create'] ?? true)
            @if((\$presentation['create'] ?? 'page') !== 'page')
            <button type="button" data-overlay-url="{{ route('{$routeNamePrefix}.create') }}" data-overlay-mode="{{ \$presentation['create'] }}" data-overlay-title="{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-xs transition-colors cursor-pointer">
                <x-lucide-plus class="size-3.5" />
                <span>{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}</span>
            </button>
            @else
            <x-ui.button href="{{ route('{$routeNamePrefix}.create') }}" as="a" size="sm" class="gap-1.5 shadow-xs font-semibold">
                <x-lucide-plus class="size-3.5" />
                <span>{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}</span>
            </x-ui.button>
            @endif
            @endif
            @include('laraslice::directory.actions')
            @isset(\$importUrl)
            <x-ui.button href="{{ \$importUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-upload class="size-4" /> <span>Import</span>
            </x-ui.button>
            @endisset
            @isset(\$bulkEntryUrl)
            <x-ui.button href="{{ \$bulkEntryUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-clipboard-check class="size-3.5" />
                <span>{{ \$bulkEntryLabel ?? 'Bulk Entry' }}</span>
            </x-ui.button>
            @endisset
            @isset(\$reportUrl)
            <x-ui.button href="{{ \$reportUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-bar-chart-3 class="size-3.5" />
                <span>Report</span>
            </x-ui.button>
            @endisset
            @isset(\$matrixUrl)
            <x-ui.button href="{{ \$matrixUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-grid-3x3 class="size-3.5" />
                <span>Matrix</span>
            </x-ui.button>
            @endisset
            @if(\$directoryExport ?? true)
            @isset(\$exportUrl)
            <a href="{{ \$exportUrl }}" onclick="this.href = this.href.split('?')[0] + window.location.search" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors">
                <x-lucide-download class="size-3.5 text-muted-foreground" />
                <span>Export</span>
            </a>
            @else
            <button type="button" onclick="window.dispatchEvent(new CustomEvent('export-table'))" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors">
                <x-lucide-download class="size-3.5 text-muted-foreground" />
                <span>Export</span>
            </button>
            @endisset
            @endif
            @endif
            </x-slot:toolbar-lead>
        @if(!empty(\$quickFilters) || !empty(\$sidebarFilters ?? []))
            <x-slot:quick-filters>
            @foreach(\$quickFilters as \$qf)
            @if((\$qf['display'] ?? 'tabs') === 'select')
            {{-- Facet anatomy (D-023): one attached grey field —
                 label, operator segment, value control fused —
                 the source's filter-row composition. --}}
            <label class="inline-flex h-8 items-stretch overflow-hidden rounded-lg border border-border bg-muted/60 text-xs text-foreground">
                <span class="flex items-center px-2 font-medium">{{ \$qf['label'] ?? \$qf['handle'] }}</span>
                <span class="flex items-center border-x border-border/70 px-1.5 text-muted-foreground">=</span>
                <select data-ls-qf="{{ \$qf['handle'] }}" onchange="const u = new window.URL(window.location.href); if (this.value) { u.searchParams.set('{{ \$qf['handle'] }}', this.value); } else { u.searchParams.delete('{{ \$qf['handle'] }}'); } window.location.href = u.toString();" class="h-full cursor-pointer bg-transparent px-2 text-xs text-foreground focus:outline-none">
                    <option value="">All</option>
                    @foreach(\$qf['options'] ?? [] as \$opt)
                    <option value="{{ \$opt['value'] }}" @selected((string) (\$activeFilters[\$qf['handle']] ?? '') === (string) \$opt['value'])>{{ \$opt['label'] }}</option>
                    @endforeach
                </select>
            </label>
            @else
            <x-ui.filter-tabs
                :tabs="\$qf['options'] ?? []"
                :param="\$qf['handle']"
                :activeTab="\$activeFilters[\$qf['handle']] ?? null"
                :default="\$filterDefaults[\$qf['handle']] ?? null"
            />
            @endif
            @endforeach
            {{-- The Filter button lives in the list-controls
                 row now (Seat 3): one universal opener beside
                 Sort and List View, on every directory. --}}
            </x-slot:quick-filters>
        @endif
        <x-slot:below-toolbar>
@if((\$placement ?? 'below-facets-left') === 'below-facets-left')
@if(\$can['create'] ?? true)
            @if((\$presentation['create'] ?? 'page') !== 'page')
            <button type="button" data-overlay-url="{{ route('{$routeNamePrefix}.create') }}" data-overlay-mode="{{ \$presentation['create'] }}" data-overlay-title="{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-xs transition-colors cursor-pointer">
                <x-lucide-plus class="size-3.5" />
                <span>{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}</span>
            </button>
            @else
            <x-ui.button href="{{ route('{$routeNamePrefix}.create') }}" as="a" size="sm" class="gap-1.5 shadow-xs font-semibold">
                <x-lucide-plus class="size-3.5" />
                <span>{{ \$directoryCreateLabel ?? 'Add {$studlyName}' }}</span>
            </x-ui.button>
            @endif
            @endif
            @include('laraslice::directory.actions')
@isset(\$importUrl)
            <x-ui.button href="{{ \$importUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-upload class="size-4" /> <span>Import</span>
            </x-ui.button>
            @endisset
@isset(\$bulkEntryUrl)
            <x-ui.button href="{{ \$bulkEntryUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-clipboard-check class="size-3.5" />
                <span>{{ \$bulkEntryLabel ?? 'Bulk Entry' }}</span>
            </x-ui.button>
            @endisset
@isset(\$reportUrl)
            <x-ui.button href="{{ \$reportUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-bar-chart-3 class="size-3.5" />
                <span>Report</span>
            </x-ui.button>
            @endisset
@isset(\$matrixUrl)
            <x-ui.button href="{{ \$matrixUrl }}" as="a" size="sm" variant="outline" class="gap-1.5 font-semibold">
                <x-lucide-grid-3x3 class="size-3.5" />
                <span>Matrix</span>
            </x-ui.button>
            @endisset
@if(\$directoryExport ?? true)
<button type="button" onclick="window.dispatchEvent(new CustomEvent('export-table'))" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors">
                <x-lucide-download class="size-3.5 text-muted-foreground" />
                <span>Export</span>
            </button>
@endif
@if((\$demoSeed ?? false) && (\$can['seed'] ?? true) && Route::has('laraslice.wizard.seed_slice'))
                <form action="{{ route('laraslice.wizard.seed_slice') }}" method="POST" class="inline">
                    @csrf
                    <input type="hidden" name="slice" value="{$pluralName}">
                    <input type="hidden" name="count" value="10">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-amber-500/30 text-amber-500 hover:bg-amber-500/10 text-xs font-semibold shadow-2xs transition-colors cursor-pointer">
                        <x-lucide-sparkles class="size-3.5" />
                        <span>Seed Demo</span>
                    </button>
                </form>
            @endif
@endif
        </x-slot:below-toolbar>
        <x-slot:toolbar-tools>
            @include('laraslice::directory.list-tools')
        </x-slot:toolbar-tools>
{$rowActionsSlot}
        </x-ui.data-table>
    @endif
</div>

{{-- F7: URL-bound overlay shells — drawer/modal presentations load the canonical route URL's fragment (see the loader below) --}}
@if(in_array('drawer', \$presentation ?? [], true))
<x-ui.drawer direction="right">
    <x-ui.drawer-trigger id="ls-overlay-drawer-trigger" class="hidden"><span></span></x-ui.drawer-trigger>
    <x-ui.drawer-content :size="\$drawerSize ?? 'lg'">
        <x-ui.drawer-header><x-ui.drawer-title id="ls-overlay-drawer-title">Details</x-ui.drawer-title></x-ui.drawer-header>
        <div id="ls-overlay-drawer-body" class="overflow-y-auto px-4 pb-6"></div>
    </x-ui.drawer-content>
</x-ui.drawer>
@endif
@if(in_array('modal', \$presentation ?? [], true))
<x-ui.dialog id="ls-overlay-modal">
    <x-ui.dialog-content class="sm:max-w-3xl">
        <x-ui.dialog-header><x-ui.dialog-title id="ls-overlay-modal-title">Details</x-ui.dialog-title></x-ui.dialog-header>
        <div id="ls-overlay-modal-body" class="overflow-y-auto max-h-[75vh]"></div>
    </x-ui.dialog-content>
</x-ui.dialog>
@endif
@if(array_filter(\$presentation ?? [], fn (\$m) => \$m !== 'page'))
<script>
document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-overlay-url]');
    if (!t || !t.getAttribute('data-overlay-url')) return;
    e.preventDefault();
    var mode = t.getAttribute('data-overlay-mode') || 'drawer';
    var url = t.getAttribute('data-overlay-url');
    var title = t.getAttribute('data-overlay-title') || '';
    var body = document.getElementById(mode === 'modal' ? 'ls-overlay-modal-body' : 'ls-overlay-drawer-body');
    var titleEl = document.getElementById(mode === 'modal' ? 'ls-overlay-modal-title' : 'ls-overlay-drawer-title');
    if (titleEl) titleEl.textContent = title;
    if (body) body.innerHTML = '<div class="p-6 text-sm text-muted-foreground">Loading…</div>';
    if (mode === 'modal') {
        window.dispatchEvent(new CustomEvent('open-dialog-ls-overlay-modal'));
    } else {
        var trigger = document.getElementById('ls-overlay-drawer-trigger');
        if (trigger) trigger.click();
    }
    fetch(url, { headers: { 'X-LaraSlice-Overlay': mode, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) { if (body) body.innerHTML = html; })
        .catch(function () { if (body) body.innerHTML = '<div class="p-6 text-sm text-destructive">Could not load this screen.</div>'; });
});
// F7 follow-up: submitting a form INSIDE an overlay posts via fetch
// instead of navigating the whole page to the index. Success closes
// the overlay and refreshes the page behind it (filters live in the
// URL, so a reload keeps the operator's place); a 422 renders the
// standard error banner at the top of the form in place.
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    var drawerBody = document.getElementById('ls-overlay-drawer-body');
    var modalBody = document.getElementById('ls-overlay-modal-body');
    var body = null, mode = null;
    if (drawerBody && drawerBody.contains(form)) { body = drawerBody; mode = 'drawer'; }
    if (modalBody && modalBody.contains(form)) { body = modalBody; mode = 'modal'; }
    if (!body) return;
    e.preventDefault();
    fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), headers: { 'X-LaraSlice-Overlay': mode, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.text().then(function (text) { return { status: r.status, text: text }; }); })
        .then(function (res) {
            var json = null;
            try { json = JSON.parse(res.text); } catch (err) {}
            if (json && json.success) {
                if (mode === 'modal') {
                    window.dispatchEvent(new CustomEvent('close-dialog-ls-overlay-modal'));
                } else {
                    var closer = document.querySelector('[data-slot="drawer-close"]');
                    if (closer) closer.click();
                }
                window.location.reload();
                return;
            }
            if (json && json.errors) {
                var messages = [];
                Object.keys(json.errors).forEach(function (k) { (json.errors[k] || []).forEach(function (m) { messages.push(m); }); });
                var old = form.querySelector('[data-ls-overlay-errors]');
                if (old) old.remove();
                var div = document.createElement('div');
                div.setAttribute('data-ls-overlay-errors', '');
                div.className = 'p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm shadow-xs';
                div.setAttribute('role', 'alert');
                var list = messages.map(function (m) { var li = document.createElement('li'); li.textContent = m; return li.outerHTML; }).join('');
                div.innerHTML = '<div class="font-semibold">Please fix the following:</div><ul class="list-disc pl-9 mt-1 space-y-0.5">' + list + '</ul>';
                form.prepend(div);
                div.scrollIntoView({ block: 'nearest' });
                return;
            }
            body.innerHTML = res.text;
        })
        .catch(function () {});
});
</script>
@endif
{$quickEntryInclude}@endsection
BLADE;
            // Tree directory (D-064): a slice declaring
            // ui.directory.tree lands on the server-rendered
            // tree partial instead of the data-table (which
            // stays one toggle away). Injected around the
            // data-table block so the rest of the directory
            // (header, toolbar, overlays) is untouched.
            if (is_array($options['ui']['directory']['tree'] ?? null)) {
                $treeToggle = <<<'BLADE'
    <div class="mb-3 flex items-center justify-end gap-1">
        <a href="{{ request()->fullUrlWithQuery(['view' => 'tree']) }}" class="inline-flex h-8 items-center rounded-md px-3 text-xs font-semibold {{ ($directoryViewMode ?? 'table') === 'tree' ? 'bg-primary text-primary-foreground' : 'border border-input bg-background hover:bg-muted' }}">Tree</a>
        <a href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}" class="inline-flex h-8 items-center rounded-md px-3 text-xs font-semibold {{ ($directoryViewMode ?? 'table') === 'tree' ? 'border border-input bg-background hover:bg-muted' : 'bg-primary text-primary-foreground' }}">Table</a>
    </div>
    @if(($directoryViewMode ?? 'table') === 'tree')
        @include('laraslice::partials.directory-tree')
    @else
BLADE;
                $tablePos = strpos($bladeIndex, '        <x-ui.data-table');
                if ($tablePos !== false) {
                    $bladeIndex = substr_replace($bladeIndex, $treeToggle."\n", $tablePos, 0);
                    $tableEnd = strpos($bladeIndex, '        </x-ui.data-table>');
                    if ($tableEnd !== false) {
                        $bladeIndex = substr_replace($bladeIndex, "\n    @endif", $tableEnd + strlen('        </x-ui.data-table>'), 0);
                    }
                }
            }
            $this->writeFile($sliceDir.'/Resources/views/index.blade.php', $bladeIndex);

            $bladeForm = <<<BLADE
@extends(\$overlayLayout ?? 'layouts.app')

@section('content')
<div class="w-full max-w-[1400px] space-y-4" data-ls-form-root>
    {{-- Single-breadcrumb rule: the topbar owns the trail.
         The record header inside the form sheet carries
         identity, state and the primary actions (Stage E
         UX pass); Delete lives there too. --}}

    @if (session('success'))
        <div class="p-4 rounded-xl border border-success/30 bg-success/10 text-success text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-alert-circle class="size-4 shrink-0" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @if (\$errors->any())
        <div class="p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm shadow-xs" role="alert">
            <div class="font-semibold flex items-center gap-2">
                <x-lucide-alert-circle class="size-4 shrink-0" />
                <span>Please fix the following:</span>
            </div>
            <ul class="list-disc pl-9 mt-1 space-y-0.5">
                @foreach (\$errors->all() as \$error)
                    <li>{{ \$error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(!\$isNew && (\$can['delete'] ?? false))
    {{-- Standalone delete form: the header's Delete button
         submits it by id (nested forms are invalid HTML). --}}
    <form id="ls-delete-form" action="{{ route('{$routeNamePrefix}.destroy', \$form->id) }}" method="POST" class="hidden" onsubmit="return confirm('Delete this record?')">
        @csrf
        @method('DELETE')
    </form>
    @endif

    @if(!\$isNew && !empty(\$workplace))
        @include('laraslice::partials.record-workplace-open')
    @endif

    <form action="{{ \$isNew ? route('{$routeNamePrefix}.store') : route('{$routeNamePrefix}.update', \$form->id) }}" method="POST" enctype="multipart/form-data" class="{$formSheetClass}{{ !\$isNew && !empty(\$workplace) ? ' min-w-0 flex-1' : '' }}"{$formXDataAttr}>
        @csrf
        @if(!\$isNew) @method('PUT') @endif

        {{-- Record header: whose record this is, what state
             it is in, and the primary actions — the first
             thing the eye meets, as on the source record
             pages. The flat bottom bar below mirrors the
             save actions for long forms. --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-6 py-4">
            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <h1 class="truncate text-lg font-semibold tracking-tight text-foreground">{{ \$isNew ? 'New '.(\$formEntityLabel ?? '{$studlyName}') : (\LaraSlice\Support\DirectoryUi::recordLabel(\$form) ?? (\$formEntityLabel ?? '{$studlyName}')) }}</h1>
                @if(!\$isNew && (\$form->status ?? \$form->state ?? \$form->stage ?? null))
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-muted px-2.5 py-0.5 text-[11px] font-semibold text-muted-foreground"><span class="size-1.5 rounded-full bg-current"></span>{{ ucwords(str_replace('_', ' ', (string) (\$form->status ?? \$form->state ?? \$form->stage))) }}</span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if(!\$isNew && !empty(\$workplace) && (!empty(\$workplace['prev']) || !empty(\$workplace['next'])))
                    <div class="flex items-center gap-1">
                        @if(!empty(\$workplace['prev']))
                            <a href="{{ route('{$routeNamePrefix}.edit', \$workplace['prev']) }}" title="Previous record" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-input bg-background shadow-2xs transition-colors hover:bg-muted"><x-lucide-chevron-left class="size-4" /></a>
                        @endif
                        @if(!empty(\$workplace['next']))
                            <a href="{{ route('{$routeNamePrefix}.edit', \$workplace['next']) }}" title="Next record" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-input bg-background shadow-2xs transition-colors hover:bg-muted"><x-lucide-chevron-right class="size-4" /></a>
                        @endif
                    </div>
                @endif
                @if(!\$isNew)
                    @php(\$lsConversions = \LaraSlice\Support\RecordConverter::forSlice('{$studlyName}'))
                    @if(\$lsConversions !== [])
                        <div class="relative" x-data="{ lsConvOpen: false }">
                            <button type="button" @click="lsConvOpen = !lsConvOpen" class="inline-flex h-8 items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">
                                Create <x-lucide-chevron-down class="size-3.5" />
                            </button>
                            <div x-show="lsConvOpen" @click.outside="lsConvOpen = false" x-cloak class="absolute right-0 z-30 mt-1.5 w-52 rounded-lg border border-border bg-popover p-1 shadow-md">
                                @foreach(\$lsConversions as \$lsConversion)
                                    @if(empty(\$lsConversion['pre_steps']))
                                        <a href="{{ route('{$routeNamePrefix}.convert', [\$form->id, \$lsConversion['target']]) }}" class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted">{{ \$lsConversion['label'] }}</a>
                                    @else
                                        <span x-data="{ lsConvStep{{ \$lsConversion['target'] }}: false }" class="block">
                                            <button type="button" @click="lsConvOpen = false; lsConvStep{{ \$lsConversion['target'] }} = true" class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-xs font-medium text-foreground transition-colors hover:bg-muted">{{ \$lsConversion['label'] }}</button>
                                            <template x-teleport="body">
                                                <div x-show="lsConvStep{{ \$lsConversion['target'] }}" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" data-ls-convert-url="{{ route('{$routeNamePrefix}.convert', [\$form->id, \$lsConversion['target']]) }}">
                                                    <div class="w-full max-w-md rounded-xl border border-border bg-card p-5 shadow-lg">
                                                        <h3 class="text-base font-semibold text-foreground">Create {{ \$lsConversion['label'] }}</h3>
                                                        <div class="mt-4 space-y-3">
                                                            @foreach(\$lsConversion['pre_steps'] as \$lsStep)
                                                                <div>
                                                                    <label class="flex items-center gap-2 text-sm font-medium text-foreground">
                                                                        <input type="checkbox" data-ls-step-check="{{ \$lsStep['name'] }}" @if(\$lsStep['default']) checked @endif class="size-4 shrink-0">
                                                                        {{ \$lsStep['label'] }}
                                                                    </label>
                                                                    @if(\$lsStep['name_field'])
                                                                        <label class="mt-2 block text-xs font-medium text-muted-foreground">{{ \$lsStep['name_label'] }}</label>
                                                                        <input type="text" data-ls-step-name="{{ \$lsStep['name'] }}" value="{{ \$lsStep['name_from'] ? (\$form->{\$lsStep['name_from']} ?? '') : '' }}" class="mt-1 flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-2xs">
                                                                    @endif
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                        <div class="mt-5 flex justify-end gap-2">
                                                            <button type="button" @click="lsConvStep{{ \$lsConversion['target'] }} = false" class="inline-flex h-8 items-center justify-center rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">Cancel</button>
                                                            <button type="button" onclick="window.lsRunConversion && window.lsRunConversion(this)" class="inline-flex h-8 items-center justify-center rounded-md bg-primary px-3 text-xs font-semibold text-primary-foreground shadow-xs transition-colors hover:bg-primary/90">Create</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
                @if(!\$isNew && !empty(\$workplace['connections']))
                    {{-- Links menu (D-057): the record's linked
                         records, grouped by slice, in the header —
                         the source's Links dropdown. --}}
                    <div class="relative" x-data="{ lsLinksOpen: false }">
                        <button type="button" @click="lsLinksOpen = !lsLinksOpen" class="inline-flex h-8 items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">
                            Links <x-lucide-chevron-down class="size-3.5" />
                        </button>
                        <div x-show="lsLinksOpen" @click.outside="lsLinksOpen = false" x-cloak class="absolute right-0 z-30 mt-1.5 w-56 rounded-lg border border-border bg-popover p-1 shadow-md">
                            @foreach(\$workplace['connections'] as \$lsLinkGroup)
                                <p class="px-2.5 pt-1.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ \$lsLinkGroup['title'] }}</p>
                                @foreach(\$lsLinkGroup['records'] as \$lsLinkRecord)
                                    @if(\$lsLinkRecord['url'])
                                        <a href="{{ \$lsLinkRecord['url'] }}" class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted">{{ \$lsLinkRecord['label'] }}</a>
                                    @endif
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                @endif{$dialogButtonsHtml}
                @if(!\$isNew)
                    {{-- Desk header pattern (D-022): secondary record
                         actions live in an Actions menu — Delete plus
                         the slice's declared ui.actions — beside one
                         primary Save. --}}
                    @php(\$lsActions = \LaraSlice\Support\RecordActions::urlsFor('{$routeNamePrefix}', \$form->id, 'form'))
                    @if((\$can['delete'] ?? false) || \$lsActions !== [])
                        <div class="relative" x-data="{ lsActionsOpen: false }">
                            <button type="button" @click="lsActionsOpen = !lsActionsOpen" class="inline-flex h-8 items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-2xs transition-colors hover:bg-muted">
                                Actions <x-lucide-chevron-down class="size-3.5" />
                            </button>
                            <div x-show="lsActionsOpen" @click.outside="lsActionsOpen = false" x-cloak class="absolute right-0 z-30 mt-1.5 w-52 rounded-lg border border-border bg-popover p-1 shadow-md">
                                @foreach(\$lsActions as \$lsAction)
                                    @if((\$lsAction['mode'] ?? 'post') === 'link')
                                        <a href="{{ \$lsAction['url'] }}" @if(!empty(\$lsAction['target'])) target="{{ \$lsAction['target'] }}" @endif class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted">{{ \$lsAction['label'] }}</a>
                                    @else
                                        <form method="POST" action="{{ \$lsAction['url'] }}" @if(!empty(\$lsAction['confirm'])) onsubmit="return confirm('{{ \$lsAction['confirm'] }}')" @endif>
                                            @csrf
                                            <button type="submit" class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-xs font-medium text-foreground transition-colors hover:bg-muted">{{ \$lsAction['label'] }}</button>
                                        </form>
                                    @endif
                                @endforeach
                                @if(\$can['delete'] ?? false)
                                    <button type="submit" form="ls-delete-form" class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-xs font-medium text-destructive transition-colors hover:bg-muted">
                                        <x-lucide-trash-2 class="size-3.5" /><span>Delete</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
                <x-ui.button type="submit" name="action" value="save_continue" size="sm" class="gap-1.5 text-xs font-semibold shadow-xs">
                    <x-lucide-check class="size-3.5" />
                    <span>Save</span>
                </x-ui.button>
            </div>
        </div>

{$formSectionsHtml}
{$dialogsHtml}{$conversionStepsHtml}

        @include('laraslice::partials.custom-fields-form')

        <!-- Sticky Bottom Form Actions Bar (Stage E: a flat
             enterprise action bar, not a floating pill) -->
        <div class="sticky bottom-0 z-10 px-6 py-3 border-t border-border bg-card flex flex-wrap items-center justify-between gap-2">
            <x-ui.button href="{{ route('{$routeNamePrefix}.index') }}" as="a" variant="ghost" class="shrink-0 text-xs font-semibold text-muted-foreground hover:text-foreground">
                Cancel
            </x-ui.button>

            <div class="flex flex-wrap items-center justify-end gap-2 sm:gap-3">
                <x-ui.button type="submit" name="action" value="save_continue" class="gap-1.5 text-xs font-semibold shadow-xs">
                    <x-lucide-check class="size-3.5" />
                    <span>Save</span>
                </x-ui.button>
            </div>
        </div>
    </form>
    @if(!\$isNew && !empty(\$workplace))
        @include('laraslice::partials.record-workplace-close')
    @endif
</div>
@endsection
BLADE;
            $this->writeFile($sliceDir.'/Resources/views/form.blade.php', $bladeForm);
            $this->writeFile($sliceDir.'/Resources/views/create.blade.php', $bladeForm);
            $this->writeFile($sliceDir.'/Resources/views/edit.blade.php', $bladeForm);

            $showView = <<<BLADE
@extends(\$overlayLayout ?? 'layouts.app')

@section('content')
@php
    \$id = \$record->id ?? 1;
    \$label = \LaraSlice\Support\DirectoryUi::recordLabel(\$record) ?? ('{$studlyName} #' . \$id);
    \$recordName = \LaraSlice\Support\DirectoryUi::recordLabel(\$record);
    \$subtitle = \LaraSlice\Support\DirectoryUi::identitySubtitle('{$studlyName}', \$record) ?? \$record->subtitle ?? \$record->email ?? \$record->code ?? ('{$studlyName} Record');
    \$initials = strtoupper(substr(\$label, 0, 2));
    \$workflow = method_exists(\$record, 'getWorkflowDefinition') ? \$record->getWorkflowDefinition() : null;
    \$transitions = method_exists(\$record, 'getAvailableTransitions') ? \$record->getAvailableTransitions() : [];
    \$sla = method_exists(\$record, 'getSlaStatus') ? \$record->getSlaStatus() : null;
    \$currentState = (string) (\$record->status ?? 'active');
    \$currentStateModel = \$workflow ? \$workflow->states->firstWhere('slug', \$currentState) : null;
    \$stateLabel = \$currentStateModel?->label ?? ucwords(str_replace('_', ' ', \$currentState));
    \$badgeColor = \$currentStateModel?->badge_color ?? 'indigo';
    \$actionUrl = \Illuminate\Support\Facades\Route::has('workflows.transition') ? route('workflows.transition', ['slice' => '{$studlyName}', 'id' => \$id], false) : '#';
    \$composition = \$showComposition ?? ['mode' => 'standard', 'fields' => 'end'];
    \$isOverview = (\$composition['mode'] ?? 'standard') === 'overview';
@endphp

<div class="space-y-4" x-data="{ activeTab: new URLSearchParams(window.location.search).get('tab') || '{{ \$showDefaultTab ?? 'overview' }}' }" @ls-show-tab.window="activeTab = \$event.detail">
@if(\$isOverview)
{{-- Layout kit step 3: overview composition — the record title
     lives in the topbar (layout page-title section). --}}
@section('page-title')
<div class="hidden sm:flex items-center gap-3 min-w-0 flex-1">
    <span class="truncate text-[15px] font-bold tracking-tight text-foreground">{{ \$label }}</span>
    <span class="flex items-center gap-1.5 text-xs text-muted-foreground font-medium whitespace-nowrap overflow-hidden">
        <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:text-primary transition-colors">Home</a>
        <span aria-hidden="true">•</span>
        <a href="{{ route('{$routeNamePrefix}.index') }}" class="hover:text-primary transition-colors">{$pluralName}</a>
        <span aria-hidden="true">•</span>
        <span class="text-foreground">{{ Str::limit(\$label, 32) }}</span>
    </span>
</div>
@endsection
@endif
    @unless(\$isOverview)
    <!-- Breadcrumbs -->
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('{$routeNamePrefix}.index') }}" class="hover:text-primary transition-colors flex items-center gap-1">
            <x-lucide-arrow-left class="size-3" />
            <span>{$pluralName}</span>
        </a>
        <span>/</span>
        <span class="text-foreground font-medium">{{ \$label }}</span>
    </div>
    @endunless

    @if (session('success'))
        <div class="p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle class="size-4 shrink-0" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-alert-circle class="size-4 shrink-0" />
            <span>{{ session('error') }}</span>
        </div>
    @endif

    @unless(\$isOverview)
    <!-- Tier 1: Persistent Summary Banner & Identity Header -->
    <x-ui.card class="p-4 bg-card border shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div class="flex items-center gap-4">
                <div class="size-11 rounded-xl bg-primary/10 text-primary font-bold text-base flex items-center justify-center shrink-0 ring-1 ring-border shadow-xs">
                    {{ \$initials }}
                </div>
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-base font-medium text-foreground">{{ \$label }}</h1>
                        <x-ui.badge variant="outline" class="font-mono text-xs">#{{ \$id }}</x-ui.badge>
                        <x-ui.status-pill :status="\$currentState" :label="\$stateLabel" :color="\$badgeColor" />
                    </div>
                    <p class="text-xs text-muted-foreground mt-1 flex items-center gap-2">
                        <span>{{ \$subtitle }}</span>
                        <span>•</span>
                        <span>Created {{ \$record->created_at?->format('M d, Y') ?? '—' }}</span>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                @if(\$can['view'] ?? false)
                <x-ui.button href="{{ route('{$routeNamePrefix}.index') }}" as="a" variant="outline" size="sm" class="gap-1.5">
                    <x-lucide-arrow-left class="size-3.5" />
                    <span>Back</span>
                </x-ui.button>
                @endif
                @isset(\$documentUrl)
                <x-ui.button href="{{ \$documentUrl }}" as="a" variant="outline" size="sm" class="gap-1.5">
                    <x-lucide-file-text class="size-3.5" />
                    Document
                </x-ui.button>
                @endisset
                @if(\$can['edit'] ?? false)
                <x-ui.button href="{{ route('{$routeNamePrefix}.edit', \$id) }}" as="a" variant="outline" size="sm" class="gap-1.5">
                    <x-lucide-pencil class="size-3.5" />
                    <span>Edit</span>
                </x-ui.button>
                @endif
                @if(\$can['delete'] ?? false)
                <form action="{{ route('{$routeNamePrefix}.destroy', \$id) }}" method="POST" class="inline" onsubmit="return confirm('Delete this record?')">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="ghost" size="sm" class="text-destructive hover:text-destructive">
                        <x-lucide-trash-2 class="size-3.5" />
                    </x-ui.button>
                </form>
                @endif
            </div>
        </div>
    </x-ui.card>

    @endunless

    @unless(\$isOverview)
    <!-- Tier 3: Responsive Sub-Tabs Ribbon -->
    <div class="border-b border-border flex items-center gap-1" data-ls-ribbon>
        <button 
            type="button"
            data-ls-tab="overview" @click="activeTab = 'overview'; window.history.replaceState(null, '', '?tab=overview')"
            class="px-4 py-2 text-[13px] font-medium border-b-2 transition-colors flex items-center gap-2 whitespace-nowrap"
            :class="activeTab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
        >
            <x-lucide-file-text class="size-4" />
            <span>{{ \$fieldsTabLabel ?? 'Overview & Details' }}</span>
        </button>

        @if(\$workflow)
        <button 
            type="button"
            data-ls-tab="workflow" @click="activeTab = 'workflow'; window.history.replaceState(null, '', '?tab=workflow')"
            class="px-4 py-2 text-[13px] font-medium border-b-2 transition-colors flex items-center gap-2 whitespace-nowrap"
            :class="activeTab === 'workflow' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
        >
            <x-lucide-git-branch class="size-4" />
            <span>Workflow & Audit Trail</span>
        </button>
        @endif

        {{-- Aggregate related records: tabs resolved from the manifest (ui.show.tabs, root model relations, repeater fields) --}}
        @php \$relatedTabs = \$relatedTabs ?? \LaraSlice\Support\DirectoryUi::relatedTabs('{$studlyName}', \$record ?? null); @endphp
        @foreach(\$relatedTabs as \$rtab)
            @if(!empty(\$rtab['handle']))
            <button
                type="button"
                data-ls-tab="{{ \$rtab['handle'] }}" @click="activeTab = '{{ \$rtab['handle'] }}'; window.history.replaceState(null, '', '?tab={{ \$rtab['handle'] }}')"
                class="px-4 py-2 text-[13px] font-medium border-b-2 transition-colors flex items-center gap-2 whitespace-nowrap"
                :class="activeTab === '{{ \$rtab['handle'] }}' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
            >
                <x-lucide-layers class="size-4" />
                <span>{{ \$rtab['label'] ?? \\Illuminate\\Support\\Str::headline(\$rtab['handle']) }}</span>
                @isset(\$rtab['count'])
                    <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-bold leading-none">{{ \$rtab['count'] }}</span>
                @endisset
            </button>
            @endif
        @endforeach
    </div>
    @include('laraslice::partials.tab-ribbon-script')

    @if(\$workflow)
        <!-- Workflow Stepper Pipeline -->
        <x-workflows::stepper :model="\$record" />
    @endif

    @endunless

    @if(\$isOverview)
    {{-- Layout kit step 3: the tab strip fuses under the topbar
         (filterbar stack); tab clicks drive the in-page panels
         through the ls-show-tab window event. Actions + status
         close the strip at its right edge, source-style. --}}
    @push('page-title'){{ \$recordName ?? \$showIdentity['name'] ?? \$label }}@endpush
    @push('filterbar')
    <div class="flex w-full min-w-0 items-center gap-1 border-b border-border bg-background px-4 lg:px-6" data-ls-ribbon
         x-data="{ tab: new URLSearchParams(window.location.search).get('tab') || '{{ \$showDefaultTab ?? 'overview' }}' }"
         @ls-show-tab.window="tab = \$event.detail">
        <button type="button" data-ls-tab="overview" @click="\$dispatch('ls-show-tab', 'overview'); window.history.replaceState(null, '', '?tab=overview')"
            class="px-4 py-2.5 text-[13px] font-medium border-b-2 transition-colors whitespace-nowrap cursor-pointer"
            :class="tab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'">
            <span>{{ \$fieldsTabLabel ?? 'Overview' }}</span>
        </button>
        @foreach(\$relatedTabs ?? [] as \$rtab)
            @if(!empty(\$rtab['handle']))
            <button type="button" data-ls-tab="{{ \$rtab['handle'] }}" @click="\$dispatch('ls-show-tab', '{{ \$rtab['handle'] }}'); window.history.replaceState(null, '', '?tab={{ \$rtab['handle'] }}')"
                class="px-4 py-2.5 text-[13px] font-medium border-b-2 transition-colors whitespace-nowrap cursor-pointer"
                :class="tab === '{{ \$rtab['handle'] }}' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'">
                <span>{{ \$rtab['label'] ?? \Illuminate\Support\Str::headline(\$rtab['handle']) }}</span>
                @isset(\$rtab['count'])
                    <span class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-bold leading-none">{{ \$rtab['count'] }}</span>
                @endisset
            </button>
            @endif
        @endforeach
        <div class="ml-auto flex items-center gap-2 py-1.5 shrink-0 sticky right-0 bg-background pl-3" data-ls-ribbon-actions>
            <x-ui.status-pill :status="\$currentState" :label="\$stateLabel" :color="\$badgeColor" size="sm" />
            @if(\$can['edit'] ?? false)
            <x-ui.button href="{{ route('{$routeNamePrefix}.edit', \$id) }}" as="a" variant="outline" size="sm" class="gap-1.5">
                <x-lucide-pencil class="size-3.5" /><span>Edit</span>
            </x-ui.button>
            @endif
            @if(\$can['delete'] ?? false)
            <form action="{{ route('{$routeNamePrefix}.destroy', \$id) }}" method="POST" class="inline" onsubmit="return confirm('Delete this record?')">
                @csrf
                @method('DELETE')
                <x-ui.button type="submit" variant="ghost" size="sm" class="text-destructive hover:text-destructive">
                    <x-lucide-trash-2 class="size-3.5" />
                </x-ui.button>
            </form>
            @endif
        </div>
    @include('laraslice::partials.tab-ribbon-script')
    </div>
    @endpush
    @endif

    <!-- Tab 1: Overview & Parameters -->
    <div x-show="activeTab === 'overview'" x-transition class="space-y-6">
    @include('laraslice::partials.timer-card')
    @if(\$isOverview && \LaraSlice\Support\Kit::current() === 'replica' && ((\$showIdentity['placement'] ?? 'main') !== 'main'))
    {{-- Replica hubs whose identity is a related-party card carry the
         record actions + workflow status as a row under the tab strip
         instead — the source project page's Action menu and status
         dropdown. (Person hubs keep their actions on the identity
         card's ••• menu.) --}}
    <div class="flex items-center justify-end gap-2">
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-[13px] font-medium text-foreground shadow-xs transition hover:bg-muted">
                Action <x-lucide-more-vertical class="size-4 text-muted-foreground" />
            </button>
            <div x-show="open" x-cloak @click.outside="open = false" class="absolute right-0 top-full z-50 mt-1 min-w-40 rounded-xl border border-border bg-card p-1 shadow-lg">
                @if(\$can['edit'] ?? false)
                <a href="{{ route('{$routeNamePrefix}.edit', \$id) }}" class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-[13px] text-foreground hover:bg-muted"><x-lucide-pencil class="size-3.5" /><span>Edit</span></a>
                @endif
                @if(\$can['delete'] ?? false)
                <form action="{{ route('{$routeNamePrefix}.destroy', \$id) }}" method="POST" onsubmit="return confirm('Delete this record?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px] text-destructive hover:bg-muted"><x-lucide-trash-2 class="size-3.5" /><span>Delete</span></button>
                </form>
                @endif
            </div>
        </div>
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-1.5 text-[13px] font-medium text-foreground shadow-xs transition hover:bg-muted">
                <span class="size-2 rounded-full {{ \$currentState === 'completed' ? 'bg-emerald-500' : 'bg-sky-500' }}"></span>{{ str_replace('_', ' ', \$currentState) }}<x-lucide-chevron-down class="size-3.5 text-muted-foreground" />
            </button>
            <div x-show="open" x-cloak @click.outside="open = false" class="absolute right-0 top-full z-50 mt-1 min-w-44 rounded-xl border border-border bg-card p-1 shadow-lg">
                @forelse(\$transitions as \$trans)
                @php
                    \$tSlug = is_object(\$trans) ? \$trans->slug : \$trans['slug'];
                    \$tLabel = is_object(\$trans) ? (\$trans->label ?? \$trans->slug) : (\$trans['label'] ?? \$trans['slug']);
                    \$tRemarks = is_object(\$trans) ? (! empty(\$trans->requires_remarks)) : (! empty(\$trans['requires_remarks']));
                    \$tAttach = is_object(\$trans) ? (! empty(\$trans->requires_attachment)) : (! empty(\$trans['requires_attachment']));
                @endphp
                <button type="button" @click="open = false" onclick="window.openWorkflowModal('{{ \$tSlug }}', '{{ addslashes(\$tLabel) }}', '{{ \$actionUrl }}', {{ \$tRemarks ? 'true' : 'false' }}, {{ \$tAttach ? 'true' : 'false' }})" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px] text-foreground hover:bg-muted">{{ \$tLabel }}</button>
                @empty
                <p class="px-2.5 py-1.5 text-[13px] text-muted-foreground">{{ str_replace('_', ' ', \$currentState) }}</p>
                @endforelse
            </div>
        </div>
    </div>
    @endif
    <!-- Tier 2: KPI Metric Strip — declared ui.show.stats (F3c) replace the generic cards -->
    @if((\$showIdentity['kpis'] ?? 'strip') === 'inline' && !empty(\$showStats ?? []))
    {{-- KPIs render inside the identity card instead (ui.show.identity.kpis: inline) --}}
    @elseif(!empty(\$showStats ?? []))
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @foreach(\$showStats as \$stat)
        <x-ui.card class="p-4 bg-card border shadow-xs">
            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block">{{ \$stat['label'] }}</span>
            <span class="text-xl font-semibold text-foreground mt-1 block">{{ \$stat['value'] }}</span>
        </x-ui.card>
        @endforeach
    </div>
    @elseif(empty(\$showWidgets ?? []))
    {{-- The generic record cards are scaffolding for uncomposed
         slices only: a slice that declares overview widgets owns
         its overview composition and gets no fallback strip. --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <x-ui.card class="p-4 bg-card border shadow-xs">
            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block">Record ID</span>
            <span class="text-xl font-bold font-mono text-foreground mt-1 block">#{{ \$id }}</span>
        </x-ui.card>
        <x-ui.card class="p-4 bg-card border shadow-xs">
            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block">Status</span>
            <div class="mt-1">
                <x-ui.status-pill :status="\$currentState" :label="\$stateLabel" :color="\$badgeColor" size="sm" />
            </div>
        </x-ui.card>
        <x-ui.card class="p-4 bg-card border shadow-xs">
            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block">Created</span>
            <span class="text-sm font-semibold text-foreground mt-1 block">{{ \$record->created_at?->format('M d, Y') ?? '—' }}</span>
        </x-ui.card>
        <x-ui.card class="p-4 bg-card border shadow-xs">
            <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block">Last Updated</span>
            <span class="text-sm font-semibold text-foreground mt-1 block">{{ \$record->updated_at?->diffForHumans() ?? '—' }}</span>
        </x-ui.card>
    </div>

    @endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <!-- Left 2/3 Main Fieldset -->
            <div class="lg:col-span-2 space-y-6">
                @if(\$isOverview && !empty(\$showIdentity ?? null) && (\$showIdentity['placement'] ?? 'main') === 'main')
                {{-- Declared hub identity (ui.show.identity): the record's person card + About, source-hub style --}}
                <x-ui.card class="ls-identity-card p-6 bg-card border shadow-xs">
                    @if((\$showIdentity['title'] ?? '') !== '')<p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$showIdentity['title'] }}</p>@endif
                    <div class="flex items-start gap-4">
                        @if(\$showIdentity['avatar'] ?? true)<div class="ls-identity-avatar flex size-14 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-xl font-bold text-primary">{{ \$showIdentity['initial'] }}</div>@endif
                        <div class="min-w-0">
                            <p class="text-base font-semibold text-foreground">{{ \$showIdentity['name'] }}</p>
                            @if(\$showIdentity['subtitle'] !== '')<p class="text-[13px] text-muted-foreground mt-0.5">{{ \$showIdentity['subtitle'] }}</p>@endif
                            @if(\$showIdentity['meta'])<p class="text-xs text-muted-foreground mt-1">{{ \$showIdentity['meta']['label'] }} {{ \$showIdentity['meta']['value'] }}</p>@endif
                        </div>
                        @if(\LaraSlice\Support\Kit::current() === 'replica')
                        {{-- Replica hubs carry the record actions on the identity card (••• menu), not the tab bar --}}
                        <div class="relative ml-auto shrink-0" x-data="{ open: false }">
                            <button type="button" @click="open = !open" class="flex size-8 items-center justify-center rounded-lg text-muted-foreground transition hover:bg-muted hover:text-foreground" title="Actions">
                                <x-lucide-more-horizontal class="size-5" />
                            </button>
                            <div x-show="open" x-cloak @click.outside="open = false" class="absolute right-0 top-full z-50 mt-1 min-w-40 rounded-xl border border-border bg-card p-1 shadow-lg">
                                @if(\$can['edit'] ?? false)
                                <a href="{{ route('{$routeNamePrefix}.edit', \$id) }}" class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-[13px] text-foreground hover:bg-muted"><x-lucide-pencil class="size-3.5" /><span>Edit</span></a>
                                @endif
                                @if(\$can['delete'] ?? false)
                                <form action="{{ route('{$routeNamePrefix}.destroy', \$id) }}" method="POST" onsubmit="return confirm('Delete this record?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px] text-destructive hover:bg-muted"><x-lucide-trash-2 class="size-3.5" /><span>Delete</span></button>
                                </form>
                                @endif
                            </div>
                        </div>
                        @endif
                    </div>
                    @if((\$showIdentity['kpis'] ?? 'strip') === 'inline' && !empty(\$showStats ?? []))
                    <div class="border-t mt-4 pt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                        @foreach(\$showStats as \$stat)
                        <div>
                            <span class="text-[11px] font-medium text-muted-foreground block">{{ \$stat['label'] }}</span>
                            <span class="text-lg font-semibold text-foreground mt-0.5 block">{{ \$stat['value'] }}</span>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </x-ui.card>
                @if(\$showIdentity['about'])
                <x-ui.card class="ls-identity-card p-6 bg-card border shadow-xs">
                    <p class="text-base font-semibold text-foreground mb-2">{{ \$showIdentity['about_title'] }}</p>
                    <p class="text-sm text-muted-foreground whitespace-pre-wrap">{{ \$showIdentity['about'] }}</p>
                </x-ui.card>
                @endif
                @endif
        {{-- Declared overview widgets (ui.show.widgets, S-UI 3): gauges and charts computed server-side --}}
        @if(!empty(\$showWidgets ?? []))
            @foreach(\$showWidgets as \$widget)
            @if((\$widget['placement'] ?? 'main') === 'main')
            @if((\$widget['type'] ?? '') === 'gauge')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                @if((\$widget['variant'] ?? 'ring') === 'semicircle')
                {{-- Semicircle gauge: card title above, gauge left, declared side fields right --}}
                <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
                <div class="flex items-center gap-8">
                    <x-ui.gauge :value="\$widget['value']" :max="\$widget['max']" :label="null" :display="null" :variant="\$widget['variant']" :color="\$widget['color'] ?? null" />
                    @if(!empty(\$widget['fields'] ?? []))
                    <div class="grid grid-cols-2 gap-6 flex-1">
                        @foreach(\$widget['fields'] as \$field)
                        <div>
                            <p class="text-[13px] text-muted-foreground">{{ \$field['label'] }}</p>
                            <p class="font-medium text-foreground mt-1">{{ \$field['value'] }}</p>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @else
                <x-ui.gauge :value="\$widget['value']" :max="\$widget['max']" :label="\$widget['title']" :display="\$widget['display']" :variant="\$widget['variant'] ?? 'ring'" :color="\$widget['color'] ?? null" />
                @endif
            </x-ui.card>
            @elseif((\$widget['type'] ?? '') === 'chart')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
                @if(!empty(\$widget['empty'] ?? null))
            <p class="text-[13px] text-muted-foreground">{{ \$widget['empty'] }}</p>
            @else
            <x-ui.chart :type="\$widget['chart']" :labels="\$widget['labels']" :series="\$widget['series']" :colors="\$widget['colors'] ?? []" :legend="\$widget['legend'] ?? 'values'" :height="200" />
            @endif
            </x-ui.card>
            @elseif((\$widget['type'] ?? '') === 'bars')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
                <x-ui.bars :bars="\$widget['bars']" :style="\$widget['style'] ?? 'rows'" />
            </x-ui.card>
            @elseif((\$widget['type'] ?? '') === 'text')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
                <p class="text-sm text-foreground whitespace-pre-wrap">{{ \$widget['text'] }}</p>
            </x-ui.card>
            @elseif((\$widget['type'] ?? '') === 'stats')
            @if((\$widget['layout'] ?? 'rows') === 'cards')
            {{-- Declared small-multiples (ui.show.widgets layout: cards): one mini card per measure --}}
            @if((\$widget['heading'] ?? '') !== '')<p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['heading'] }}</p>@endif
            <div class="grid {{ (\$widget['columns'] ?? 2) === 3 ? 'grid-cols-3' : 'grid-cols-2' }} gap-4">
                @foreach(\$widget['rows'] as \$row)
                <x-ui.card class="p-4 bg-card border shadow-xs">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-[13px] font-medium text-muted-foreground">{{ \$row['label'] }}</p>
                        @if(!empty(\$row['icon']))<x-dynamic-component :component="'lucide-' . \$row['icon']" class="size-4 shrink-0 text-muted-foreground" />@endif
                    </div>
                    <p class="font-semibold text-primary mt-1 {{ (\$widget['columns'] ?? 2) === 3 ? 'text-[15px]' : 'text-xl' }}">{{ \$row['value'] }}</p>
                </x-ui.card>
                @endforeach
            </div>
            @else
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
                <dl class="space-y-2">
                    @foreach(\$widget['rows'] as \$row)
                    <div class="flex items-center justify-between gap-3 text-[13px]">
                        <dt class="text-muted-foreground">{{ \$row['label'] }}</dt>
                        <dd class="font-semibold text-foreground">{{ \$row['value'] }}</dd>
                    </div>
                    @endforeach
                </dl>
            </x-ui.card>
            @endif
            @elseif((\$widget['type'] ?? '') === 'pairs')
            <x-ui.card class="p-5 bg-card border shadow-xs">
            @if((\$widget['title'] ?? '') !== '')<p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>@endif
            @if((\$widget['layout'] ?? 'rows') === 'columns')
            {{-- Declared columns layout: label-over-value cells in a two-column grid --}}
            <div class="grid grid-cols-2 gap-4">
                @foreach(\$widget['rows'] as \$row)
                <div>
                    <p class="text-[13px] text-muted-foreground">{{ \$row['label'] }}</p>
                    <p class="font-medium text-foreground mt-1">{{ \$row['value'] }}</p>
                </div>
                @endforeach
            </div>
            @else
            <dl class="space-y-2">
                @foreach(\$widget['rows'] as \$row)
                <div class="flex items-center justify-between gap-3 text-[13px]">
                    <dt class="text-muted-foreground">{{ \$row['label'] }}</dt>
                    <dd class="font-semibold text-foreground">{{ \$row['value'] }}</dd>
                </div>
                @endforeach
            </dl>
            @endif
            </x-ui.card>
            @elseif((\$widget['type'] ?? '') === 'list')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
                @if((\$widget['rows'] ?? []) === [])
                @if(!empty(\$widget['empty_icon']))
                {{-- Declared centred empty state (empty_icon): the source cards' icon + centred text --}}
                <div class="flex flex-col items-center gap-2 py-4 text-center">
                    <x-dynamic-component :component="'lucide-' . \$widget['empty_icon']" class="size-8 text-muted-foreground/60" />
                    <p class="text-[13px] text-muted-foreground">{{ \$widget['empty'] ?? 'Nothing to show.' }}</p>
                </div>
                @else
                <p class="text-[13px] text-muted-foreground">{{ \$widget['empty'] ?? 'Nothing to show.' }}</p>
                @endif
                @else
                <ul class="divide-y divide-border/60">
                    @foreach(\$widget['rows'] as \$row)
                    <li class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
                        <span class="text-[13px] font-medium text-foreground">{{ \$row['label'] }}</span>
                        @if((\$row['meta'] ?? '') !== '')<span class="text-xs text-muted-foreground">{{ \$row['meta'] }}</span>@endif
                    </li>
                    @endforeach
                </ul>
                @endif
            </x-ui.card>
            @endif
            @endif
            @endforeach
        @endif
                @if(!\LaraSlice\Support\DirectoryUi::detailsSuppressed('{$studlyName}'))
                @php \$detailRows = \LaraSlice\Support\DirectoryUi::detailRows('{$studlyName}', \$record); \$detailStyle = \LaraSlice\Support\DirectoryUi::detailStyle('{$studlyName}'); \$detailCardTitle = \LaraSlice\Support\DirectoryUi::detailTitle('{$studlyName}'); if (\$isOverview && \$detailCardTitle === 'Attribute Parameters') { \$detailCardTitle = 'Details'; } @endphp
                @if(\$detailStyle === 'list')
                {{-- Declared definition list (ui.show.detail_style: list): the source hubs' profile card --}}
                <x-ui.card class="p-6 bg-card border shadow-xs">
                    <p class="text-base font-semibold text-foreground mb-4">{{ \$detailCardTitle }}</p>
                    <dl>
                        @foreach(\$detailRows as \$detailRow)
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-1 sm:gap-4 py-1.5">
                            <dt class="text-sm text-muted-foreground">{{ \$detailRow['label'] }}</dt>
                            <dd class="text-sm text-foreground whitespace-pre-wrap break-words sm:col-span-2">@if(\$detailRow['html']){!! \$detailRow['value'] !!}@else{{ \$detailRow['value'] }}@endif</dd>
                        </div>
                        @endforeach
                    </dl>
                </x-ui.card>
                @else
                <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <x-ui.card-title class="text-base font-semibold flex items-center gap-2">
                            <x-lucide-layers class="size-4 text-primary" />
                            <span>{{ \$detailCardTitle }}</span>
                        </x-ui.card-title>
                    </x-ui.card-header>

                    <x-ui.card-content class="p-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-5 gap-x-6">
                            @php \$detailRows = \LaraSlice\Support\DirectoryUi::detailRows('{$studlyName}', \$record); \$detailStyle = \LaraSlice\Support\DirectoryUi::detailStyle('{$studlyName}'); \$detailCardTitle = \LaraSlice\Support\DirectoryUi::detailTitle('{$studlyName}'); if (\$isOverview && \$detailCardTitle === 'Attribute Parameters') { \$detailCardTitle = 'Details'; } @endphp
                            @if(! empty(\$detailRows))
                                @foreach(\$detailRows as \$detailRow)
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ \$detailRow['label'] }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-foreground whitespace-pre-wrap break-words">@if(\$detailRow['html']){!! \$detailRow['value'] !!}@else{{ \$detailRow['value'] }}@endif</dd>
                                    </div>
                                @endforeach
                            @else
                            @foreach((\$record->getAttributes() ?? []) as \$k => \$v)
                                @if(! in_array(\$k, ['id', 'created_at', 'updated_at', 'deleted_at', 'workflow_state_entered_at']) && ! is_array(\$v))
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ ucwords(str_replace('_', ' ', \$k)) }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-foreground">{{ is_scalar(\$v) ? (string) \$v : '—' }}</dd>
                                    </div>
                                @endif
                            @endforeach
                            @endif
                        </div>
                    </x-ui.card-content>
                </x-ui.card>
                @endif
                @endif
            </div>

            <!-- Right 1/3 Action & Responsibility Sidebar -->
            <div class="space-y-6">
        @if(\$isOverview && !empty(\$showIdentity ?? null) && (\$showIdentity['placement'] ?? 'main') === 'rail')
        {{-- Declared rail identity (ui.show.identity placement: rail): a related-party card — the project hub's Client card --}}
        <x-ui.card class="p-5 bg-card border shadow-xs">
            @if((\$showIdentity['title'] ?? '') !== '')<p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$showIdentity['title'] }}</p>@endif
            <div class="flex items-start gap-4">
                @if(\$showIdentity['avatar'] ?? true)<div class="ls-identity-avatar flex size-14 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-xl font-bold text-primary">{{ \$showIdentity['initial'] }}</div>@endif
                <div class="min-w-0">
                    <p class="text-base font-semibold text-foreground">{{ \$showIdentity['name'] }}</p>
                    @if(\$showIdentity['subtitle'] !== '')<p class="text-[13px] text-muted-foreground mt-0.5">{{ \$showIdentity['subtitle'] }}</p>@endif
                    @if(\$showIdentity['meta'])<p class="text-xs text-muted-foreground mt-1">{{ \$showIdentity['meta']['label'] }} {{ \$showIdentity['meta']['value'] }}</p>@endif
                </div>
            </div>
        </x-ui.card>
        @endif
        {{-- Declared rail widgets (ui.show.widgets placement: rail) --}}
        @foreach(\$showWidgets ?? [] as \$widget)
        @if((\$widget['placement'] ?? 'main') === 'rail')
        <x-ui.card class="{{ (\$widget['type'] ?? '') === 'stats' && (\$widget['layout'] ?? 'rows') === 'cards' ? 'p-0 border-0 shadow-none bg-transparent' : 'p-5 bg-card border shadow-xs' }}">
            @if((\$widget['type'] ?? '') === 'gauge')
            <x-ui.gauge :value="\$widget['value']" :max="\$widget['max']" :label="\$widget['title']" :display="\$widget['display']" :variant="\$widget['variant'] ?? 'ring'" :color="\$widget['color'] ?? null" />
            @elseif((\$widget['type'] ?? '') === 'chart')
            <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
            @if(!empty(\$widget['empty'] ?? null))
            <p class="text-[13px] text-muted-foreground">{{ \$widget['empty'] }}</p>
            @else
            <x-ui.chart :type="\$widget['chart']" :labels="\$widget['labels']" :series="\$widget['series']" :colors="\$widget['colors'] ?? []" :legend="\$widget['legend'] ?? 'values'" :height="170" />
            @endif
            @elseif((\$widget['type'] ?? '') === 'bars')
            <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
            <x-ui.bars :bars="\$widget['bars']" :style="\$widget['style'] ?? 'rows'" />
            @elseif((\$widget['type'] ?? '') === 'text')
            <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
            <p class="text-sm text-foreground whitespace-pre-wrap">{{ \$widget['text'] }}</p>
            @elseif((\$widget['type'] ?? '') === 'stats')
            @if((\$widget['layout'] ?? 'rows') === 'cards')
            {{-- Declared small-multiples (layout: cards): one mini card per measure, no wrapper card --}}
            @if((\$widget['heading'] ?? '') !== '')<p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['heading'] }}</p>@endif
            <div class="grid {{ (\$widget['columns'] ?? 2) === 3 ? 'grid-cols-3' : 'grid-cols-2' }} gap-4">
                @foreach(\$widget['rows'] as \$row)
                <x-ui.card class="p-4 bg-card border shadow-xs">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-[13px] font-medium text-muted-foreground">{{ \$row['label'] }}</p>
                        @if(!empty(\$row['icon']))<x-dynamic-component :component="'lucide-' . \$row['icon']" class="size-4 shrink-0 text-muted-foreground" />@endif
                    </div>
                    <p class="font-semibold text-primary mt-1 {{ (\$widget['columns'] ?? 2) === 3 ? 'text-[15px]' : 'text-xl' }}">{{ \$row['value'] }}</p>
                </x-ui.card>
                @endforeach
            </div>
            @else
            <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
            <dl class="space-y-2">
                @foreach(\$widget['rows'] as \$row)
                <div class="flex items-center justify-between gap-3 text-[13px]">
                    <dt class="text-muted-foreground">{{ \$row['label'] }}</dt>
                    <dd class="font-semibold text-foreground">{{ \$row['value'] }}</dd>
                </div>
                @endforeach
            </dl>
            @endif
            @elseif((\$widget['type'] ?? '') === 'pairs')
            @if((\$widget['title'] ?? '') !== '')<p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>@endif
            @if((\$widget['layout'] ?? 'rows') === 'columns')
            {{-- Declared columns layout: label-over-value cells in a two-column grid --}}
            <div class="grid grid-cols-2 gap-4">
                @foreach(\$widget['rows'] as \$row)
                <div>
                    <p class="text-[13px] text-muted-foreground">{{ \$row['label'] }}</p>
                    <p class="font-medium text-foreground mt-1">{{ \$row['value'] }}</p>
                </div>
                @endforeach
            </div>
            @else
            <dl class="space-y-2">
                @foreach(\$widget['rows'] as \$row)
                <div class="flex items-center justify-between gap-3 text-[13px]">
                    <dt class="text-muted-foreground">{{ \$row['label'] }}</dt>
                    <dd class="font-semibold text-foreground">{{ \$row['value'] }}</dd>
                </div>
                @endforeach
            </dl>
            @endif
            @elseif((\$widget['type'] ?? '') === 'list')
            <p class="ls-widget-title text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ \$widget['title'] }}</p>
            @if((\$widget['rows'] ?? []) === [])
            @if(!empty(\$widget['empty_icon']))
            {{-- Declared centred empty state (empty_icon): the source cards' icon + centred text --}}
            <div class="flex flex-col items-center gap-2 py-4 text-center">
                <x-dynamic-component :component="'lucide-' . \$widget['empty_icon']" class="size-8 text-muted-foreground/60" />
                <p class="text-[13px] text-muted-foreground">{{ \$widget['empty'] ?? 'Nothing to show.' }}</p>
            </div>
            @else
            <p class="text-[13px] text-muted-foreground">{{ \$widget['empty'] ?? 'Nothing to show.' }}</p>
            @endif
            @else
            <ul class="divide-y divide-border/60">
                @foreach(\$widget['rows'] as \$row)
                <li class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
                    <span class="text-[13px] font-medium text-foreground">{{ \$row['label'] }}</span>
                    @if((\$row['meta'] ?? '') !== '')<span class="text-xs text-muted-foreground">{{ \$row['meta'] }}</span>@endif
                </li>
                @endforeach
            </ul>
            @endif
            @endif
        </x-ui.card>
        @endif
        @endforeach
                @if(\$workflow)
                    <!-- Workflow State & Forward Options Card -->
                    <x-ui.card variant="sectioned" class="border shadow-xs bg-card ring-1 ring-border/60">
                        <x-ui.card-header class="border-b pb-4 px-6 pt-5 bg-muted/20">
                            <div class="flex items-center gap-2 text-foreground font-semibold text-sm">
                                <x-lucide-arrow-right-circle class="size-4 text-primary" />
                                <span>Workflow Forward Actions</span>
                            </div>
                            <x-ui.card-description class="text-xs">Execute authorized state machine transitions</x-ui.card-description>
                        </x-ui.card-header>

                        <x-ui.card-content class="p-6 space-y-5">
                            <div class="p-3.5 rounded-xl bg-muted/40 border border-border/60 space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-muted-foreground font-medium">Current Stage:</span>
                                    <span class="font-bold text-foreground">{{ \$stateLabel }}</span>
                                </div>
                                @if(\$sla && (\$sla['has_sla'] ?? false))
                                    <div class="pt-2 border-t border-border/40 flex items-center justify-between text-xs">
                                        <span class="text-muted-foreground font-medium">SLA Clock:</span>
                                        @if(\$sla['is_breached'] ?? false)
                                            <span class="font-bold text-rose-600 dark:text-rose-400 flex items-center gap-1">
                                                <x-lucide-alert-triangle class="size-3" />
                                                <span>Breached ({{ abs(\$sla['hours_remaining']) }}h overdue)</span>
                                            </span>
                                        @else
                                            <span class="font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                                <x-lucide-clock class="size-3" />
                                                <span>{{ \$sla['hours_remaining'] }}h remaining</span>
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if(! empty(\$transitions))
                                <div class="space-y-2.5">
                                    @foreach(\$transitions as \$trans)
                                        @php
                                            \$tSlug = is_object(\$trans) ? \$trans->slug : \$trans['slug'];
                                            \$tLabel = is_object(\$trans) ? (\$trans->label ?? \$trans->slug) : (\$trans['label'] ?? \$trans['slug']);
                                            \$tColor = is_object(\$trans) ? (\$trans->button_color ?? 'primary') : (\$trans['button_color'] ?? 'primary');
                                            \$tRemarks = is_object(\$trans) ? (! empty(\$trans->requires_remarks)) : (! empty(\$trans['requires_remarks']));
                                            \$tAttach = is_object(\$trans) ? (! empty(\$trans->requires_attachment)) : (! empty(\$trans['requires_attachment']));
                                        @endphp
                                        <button
                                            type="button"
                                            onclick="window.openWorkflowModal('{{ \$tSlug }}', '{{ addslashes(\$tLabel) }}', '{{ \$actionUrl }}', {{ \$tRemarks ? 'true' : 'false' }}, {{ \$tAttach ? 'true' : 'false' }})"
                                            class="w-full flex items-center justify-between px-4 py-2.5 rounded-xl text-xs font-bold transition-all cursor-pointer bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm"
                                        >
                                            <span class="truncate">{{ \$tLabel }}</span>
                                            <x-lucide-arrow-right class="size-3.5 shrink-0 ml-2" />
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </x-ui.card-content>
                    </x-ui.card>
                @endif

                <!-- Responsibility & Ownership Card -->
                @if(!empty(\$record->assigned_to_role) || !empty(\$record->assigned_to_department))
                <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
                    <x-ui.card-header class="border-b pb-3 px-6 pt-5">
                        <x-ui.card-title class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                            <x-lucide-shield-check class="size-3.5 text-primary" />
                            <span>Ownership & Responsibility</span>
                        </x-ui.card-title>
                    </x-ui.card-header>

                    <x-ui.card-content class="p-6 space-y-3.5 text-xs">
                        <div>
                            <span class="text-muted-foreground font-medium block">Assigned Role:</span>
                            <span class="font-bold text-foreground text-sm mt-0.5 block">
                                {{ \$record->assigned_to_role ?: 'General Staff' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">Department:</span>
                            <span class="font-semibold text-foreground mt-0.5 block">
                                {{ \$record->assigned_to_department ?: 'Operations' }}
                            </span>
                        </div>
                    </x-ui.card-content>
                </x-ui.card>
                @endif
            </div>
        </div>
    </div>

    @foreach(\$relatedTabs ?? [] as \$rtab)
        @if(!empty(\$rtab['handle']) && (\$rtab['type'] ?? 'relation') === 'relation')
        <div x-show="activeTab === '{{ \$rtab['handle'] }}'" x-transition class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-bold text-foreground">{{ \$rtab['label'] ?? \\Illuminate\\Support\\Str::headline(\$rtab['handle']) }}</h3>
            </div>
            @if(!empty(\$rtab['lazy']))
                @include('laraslice::partials.related-tab-lazy', ['rtab' => \$rtab])
            @elseif(in_array(\$rtab['render'] ?? 'table', ['board', 'gantt', 'calendar', 'thread', 'stack'], true) && isset(\$rtab['records']))
                @include('laraslice::partials.related-tab-' . (\$rtab['render'] ?? 'table'), ['rtab' => \$rtab])
            @elseif(!empty(\$rtab['columns']))
                @if(count(\$rtab['rows'] ?? []) === 0)
                    <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                        No related records yet.
                    </div>
                @else
                    @include('laraslice::partials.related-tab-table', ['rtab' => \$rtab])
                @endif
            @else
            @php
                \$relName = \$rtab['relation'] ?? \$rtab['handle'];
                \$relRows = [];
                try {
                    if (isset(\$record) && method_exists(\$record, \$relName)) {
                        \$relRows = \$record->{\$relName}()->limit(50)->get();
                    } elseif (isset(\$record) && \$record->relationLoaded(\$relName)) {
                        \$relRows = \$record->getRelation(\$relName);
                    }
                } catch (\\Throwable \$e) {
                    \$relRows = [];
                }
            @endphp
            @if(count(\$relRows) === 0)
                <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                    No related records yet.
                </div>
            @else
                <div class="rounded-xl border border-border overflow-hidden bg-card">
                    <table class="w-full text-xs">
                        <thead class="bg-muted/40 border-b border-border">
                            <tr>
                                <th class="px-3 py-2 text-start font-semibold">ID</th>
                                <th class="px-3 py-2 text-start font-semibold">Summary</th>
                                <th class="px-3 py-2 text-start font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/50">
                            @foreach(\$relRows as \$row)
                            <tr class="hover:bg-muted/30">
                                <td class="px-3 py-2 font-mono">{{ \$row->id ?? '—' }}</td>
                                <td class="px-3 py-2 font-medium">{{ \$row->title ?? \$row->name ?? \$row->subject ?? ('#'.(\$row->id ?? '')) }}</td>
                                <td class="px-3 py-2">{{ \$row->status ?? '—' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @endif
            @if(!empty(\$rtab['post']) && !empty(\$rtab['post_url']))
                @include('laraslice::partials.related-tab-post', ['rtab' => \$rtab, 'record' => \$record])
            @endif
        </div>
        @endif
    @endforeach

    {{-- Declared audit tabs (ui.show.tabs type: audit): the record's
         audit trail via the shared partial — entries or empty state,
         never a silently missing panel. --}}
    @foreach(\$relatedTabs ?? [] as \$rtab)
        @if(!empty(\$rtab['handle']) && (\$rtab['type'] ?? '') === 'audit')
        <div x-show="activeTab === '{{ \$rtab['handle'] }}'" x-transition class="space-y-4">
            @include('laraslice::partials.related-tab-audit', ['rtab' => \$rtab])
        </div>
        @endif
    @endforeach

    {{-- Declared chart tabs (ui.show.tabs type: chart): the
         relation's computed series via the shared partial —
         payload or empty state, never a silently missing panel. --}}
    @foreach(\$relatedTabs ?? [] as \$rtab)
        @if(!empty(\$rtab['handle']) && (\$rtab['type'] ?? '') === 'chart')
        <div x-show="activeTab === '{{ \$rtab['handle'] }}'" x-transition class="space-y-4">
            @include('laraslice::partials.related-tab-chart', ['rtab' => \$rtab])
        </div>
        @endif
    @endforeach

    {{-- Declared permissions tabs (ui.show.tabs type:
         permissions): the record's person's module permission
         matrix via the shared partial. --}}
    @foreach(\$relatedTabs ?? [] as \$rtab)
        @if(!empty(\$rtab['handle']) && (\$rtab['type'] ?? '') === 'permissions')
        <div x-show="activeTab === '{{ \$rtab['handle'] }}'" x-transition class="space-y-4">
            @include('laraslice::partials.related-tab-permissions', ['rtab' => \$rtab])
        </div>
        @endif
    @endforeach

    {{-- Custom fields tab: appended by the controller when the
         record's model carries a field group — values via the
         shared partial. --}}
    @foreach(\$relatedTabs ?? [] as \$rtab)
        @if(!empty(\$rtab['handle']) && (\$rtab['type'] ?? '') === 'custom_fields')
        <div x-show="activeTab === '{{ \$rtab['handle'] }}'" x-transition class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-bold text-foreground">{{ \$rtab['label'] ?? 'Custom Fields' }}</h3>
            </div>
            @include('laraslice::partials.related-tab-custom-fields', ['rtab' => \$rtab])
        </div>
        @endif
    @endforeach

    @if(\$workflow)
    <!-- Tab 2: Workflow History & Decision Timeline -->
    <div x-show="activeTab === 'workflow'" x-transition class="space-y-6">
        <x-workflows::timeline :model="\$record" />
    </div>

    <!-- Forward Action Modal Dialog -->
    <x-workflows::forward-modal />
    @endif
</div>
@endsection
BLADE;
            $this->writeFile($sliceDir.'/Resources/views/show.blade.php', $showView);

            $renamed = false;
            if (! file_exists($targetDir) && ! is_link($targetDir)) {
                $parent = dirname($targetDir);
                if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                    throw new \RuntimeException("Unable to create parent directory for slice: {$parent}");
                }
                gc_collect_cycles();
                for ($attempt = 0; $attempt < 8; $attempt++) {
                    if (@rename($stagingDir, $targetDir)) {
                        $renamed = true;
                        break;
                    }
                    usleep(30000);
                }
                if (! $renamed) {
                    $renamed = Directory::copy($stagingDir, $targetDir);
                    if ($renamed) {
                        Directory::remove($stagingDir);
                    }
                }
            }

            if (! $renamed) {
                throw new \RuntimeException("Unable to publish generated slice to {$targetDir}; no existing files were replaced.");
            }

            try {
                if (function_exists('app') && app()->bound(SliceManager::class)) {
                    // Generation is a mutation flow: invalidate discovery
                    // so the next discover() in this process rescans and
                    // sees the new slice (the contract every toggle /
                    // destroy / sync path already follows). Without this,
                    // same-process consumers (tests, Octane, Studio AJAX)
                    // keep the pre-generation registry and the new slice
                    // is invisible until the process restarts.
                    app(SliceManager::class)->clearCache();
                    app(SliceManager::class)->syncPermissions();
                }
            } catch (\Throwable) {
            }

            return $targetDir;
        } catch (\Throwable $exception) {
            Directory::remove($stagingDir);

            throw $exception;
        }
    }

    /**
     * F2: normalize repeater (line-item) field declarations from a UI
     * config into service-ready definitions keyed by form handle.
     * Metadata (table / foreign_key / model) defaults from the handle
     * and the root table when the manifest does not state them.
     *
     * @return array<string, array{table: string, foreign_key: string, model: string, fields: array<string, array{label: string, type: string, required: bool, options: array}>}>
     */
    /**
     * Build the default ui.directory for a slice whose caller declared
     * no directory columns, mirroring the porter's assembly (same
     * shapes, same caps) from the slice's fields. F5.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function buildDefaultDirectory(array $fields, string $tenantKey = 'company_id'): array
    {
        $columns = [];
        $quickFilters = [];
        $summaryCards = [];
        $searchable = [];
        $hasStatus = false;

        foreach ($fields as $f) {
            $handle = (string) ($f['name'] ?? '');
            if ($handle === '' || in_array($handle, ['id', $tenantKey, 'created_at', 'updated_at', 'deleted_at'], true) || ! empty($f['hidden'])) {
                continue;
            }
            $label = (string) ($f['label'] ?? Str::headline($handle));
            $type = (string) ($f['type'] ?? 'string');
            $signals = FieldInference::analyze($handle, $type);
            $hasStatus = $hasStatus || $signals['is_status'];

            if (count($columns) < 8) {
                $col = [
                    'handle' => $handle,
                    'key' => $handle,
                    'label' => $label,
                    'formatter' => $signals['formatter'],
                    'type' => $signals['formatter'],
                    'sortable' => true,
                ];
                if ($signals['is_assignee']) {
                    $col['relation'] = Str::camel(str_ends_with($handle, '_id') ? substr($handle, 0, -3) : $handle);
                }
                $columns[] = $col;
            }

            if (($signals['is_status'] || $signals['is_priority']) && count($quickFilters) < 5) {
                $quickFilters[] = [
                    'handle' => $handle,
                    'label' => $label,
                    'is_searchable' => false,
                    'options_from' => 'distinct:'.$handle,
                ];
            }

            $lower = strtolower($handle);
            if (in_array($type, ['string', 'text'], true) && ! $signals['is_status'] && count($searchable) < 5
                && ! str_contains($lower, 'password') && ! str_contains($lower, 'token') && strlen($handle) < 30) {
                $searchable[] = $handle;
            }
        }

        // Summary cards are NEVER inferred (fidelity rule, QA6
        // 25 Oct 2026): a card the source list does not show is
        // a delta, however useful it looks. Cards exist only
        // when declared — from the source page's anatomy via
        // the ui-spec, or by hand in the manifest.

        $views = ['table'];
        if ($hasStatus) {
            $views[] = 'board';
        }

        return [
            'columns' => $columns,
            'searchable' => $searchable,
            'quick_filters' => $quickFilters,
            'summary_cards' => $summaryCards,
            'views' => $views,
            'default_view' => 'table',
            'inline_edit' => $hasStatus ? ['status'] : [],
            'row_actions' => [
                'display' => 'dropdown',
                'items' => [
                    ['handle' => 'view', 'label' => 'View', 'icon' => 'eye', 'placement' => 'inline'],
                    ['handle' => 'edit', 'label' => 'Edit', 'icon' => 'pencil', 'placement' => 'dropdown'],
                    ['handle' => 'delete', 'label' => 'Delete', 'icon' => 'trash-2', 'placement' => 'dropdown', 'danger' => true],
                ],
            ],
        ];
    }

    /**
     * BelongsToMany form selections declared as `type: relation_multi`
     * in the manifest's form sections. Shape per handle:
     * ['relation' => string] — defaults to the handle itself.
     *
     * @return array<string, array{relation: string}>
     */
    protected function extractRelationMultiDefinitions(array $uiConfig): array
    {
        $defs = [];
        foreach (($uiConfig['forms']['sections'] ?? []) as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                if (! is_array($field) || ($field['type'] ?? '') !== 'relation_multi') {
                    continue;
                }
                $handle = $field['handle'] ?? '';
                if (! is_string($handle) || $handle === '') {
                    continue;
                }
                $relation = is_string($field['relation'] ?? null) && $field['relation'] !== ''
                    ? $field['relation']
                    : $handle;
                $defs[$handle] = ['relation' => $relation];
            }
        }

        return $defs;
    }

    /**
     * The x-ui.repeater component markup for one declared
     * child collection — shared by the ui.forms.sections
     * form path and the fields-map form path so both
     * dialects render the identical component.
     */
    private function repeaterComponent(array $field, string $handle): string
    {
        $repLabel = $field['label'] ?? Str::headline($handle);
        $addBtn = $field['add_button_text'] ?? ('Add '.Str::singular($repLabel));
        $subFieldDefs = [];
        foreach (($field['repeater_fields'] ?? []) as $rf) {
            if (! is_array($rf)) {
                continue;
            }
            $rfName = $rf['handle'] ?? $rf['name'] ?? '';
            if ($rfName === '') {
                continue;
            }
            $subField = [
                'name' => $rfName,
                'label' => $rf['label'] ?? Str::headline($rfName),
                'type' => $rf['type'] ?? 'string',
                'required' => (bool) ($rf['required'] ?? false),
                'options' => $rf['options'] ?? [],
            ];
            // Link picker seat (D-030): a declared target
            // slice rides into the component per sub-field;
            // undeclared sub-fields keep the exact historical
            // markup (no key emitted at all).
            if (is_string($rf['target'] ?? null) && $rf['target'] !== '') {
                $subField['target'] = $rf['target'];
            }
            $subFieldDefs[] = preg_replace('/\s+/', ' ', var_export($subField, true));
        }
        $subFieldsStr = '['.implode(', ', $subFieldDefs).']';
        $docProp = '';
        if (is_array($field['document'] ?? null) && $field['document'] !== []) {
            $docStr = preg_replace('/\s+/', ' ', var_export($field['document'], true));
            $docProp = " :document=\"{$docStr}\"";
        }
        // Repeater actions (D-020): declared grid buttons ride
        // into the component like the document block.
        $actionsProp = '';
        if (is_array($field['actions'] ?? null) && $field['actions'] !== []) {
            $actionsStr = preg_replace('/\s+/', ' ', var_export($field['actions'], true));
            $actionsProp = " :actions=\"{$actionsStr}\"";
        }

        return "<x-ui.repeater name=\"{$handle}\" label=\"{$repLabel}\" :fields=\"{$subFieldsStr}\" :initial-items=\"old('{$handle}', \$form->{$handle} ?? [])\" addButtonText=\"{$addBtn}\"{$docProp}{$actionsProp} class=\"md:col-span-2 lg:col-span-3\" />";
    }

    /**
     * The Table MultiSelect picker for one multiselect field:
     * the kit's combobox in multiple mode (selected targets
     * render as removable chips and submit as {handle}[]),
     * fed by the controller-resolved {handle}Options for the
     * declaration's target slice. The hidden _multiselects[]
     * marker rides along so a picker whose chips were ALL
     * removed still counts as provided (the service then
     * clears the child rows) — the repeater marker trick.
     */
    private function multiselectComponent(array $field, string $handle): string
    {
        $label = $field['label'] ?? Str::headline($handle);
        $optionsVar = Str::camel($handle).'Options';

        return '<div class="space-y-1">'
            .'<input type="hidden" name="_multiselects[]" value="'.$handle.'">'
            .'<x-ui.label for="'.$handle.'" class="text-xs font-medium text-foreground/80">'.$label.'</x-ui.label>'
            .'<x-ui.combobox name="'.$handle.'" :options="$'.$optionsVar.' ?? []" :value="old(\''.$handle.'\', $form->'.$handle.' ?? [])" multiple width="w-full" placeholder="Select..." />'
            .'@error(\''.$handle.'\') <p class="text-xs text-destructive font-medium">{{ $message }}</p> @enderror'
            .'</div>';
    }

    /**
     * D-014: the up() child-table blocks for declared
     * repeaters and multiselects, in declaration order
     * (repeaters first, then multiselects; a table declared
     * twice is emitted once). Each block is individually
     * guarded with `if (! Schema::hasTable(...))` — the
     * generated parent create uses an early-return guard,
     * which cannot be reused per child without skipping the
     * remaining children, and the hand-written child
     * migrations this matches guard per table the same way.
     * Returns '' when nothing is declared, keeping a
     * childless slice's migration byte-identical.
     *
     * Repeater child shape (matches the hand-written child
     * migrations, e.g. item_defaults / party_accounts):
     * id; foreignId(foreign_key) constrained to the parent
     * table with cascadeOnDelete; one typed column per
     * repeater field; timestamps (no audit columns — the
     * hand-written repeater children carry none).
     *
     * Multiselect child shape (matches e.g.
     * item_allowed_companies): id; unsignedBigInteger
     * foreign_key indexed; string parent_type nullable when
     * declared; unsignedBigInteger target_field; audit
     * columns created_by / updated_by as plain nullable
     * unsignedBigInteger; timestamps.
     */
    protected function childMigrationUp(array $repeaterDefinitions, array $multiselectDefinitions, string $parentTable): string
    {
        $isIdentifier = static fn (string $value): bool => preg_match('/^[a-z][a-z0-9_]{0,62}$/', $value) === 1;
        $blocks = [];
        $seenTables = [];
        foreach ($repeaterDefinitions as $def) {
            $childTable = (string) ($def['table'] ?? '');
            if (! $isIdentifier($childTable) || isset($seenTables[$childTable])) {
                continue;
            }
            $seenTables[$childTable] = true;
            $foreignKey = (string) ($def['foreign_key'] ?? '');
            if (! $isIdentifier($foreignKey)) {
                $foreignKey = Str::singular($parentTable).'_id';
            }
            $cols = [];
            $cols[] = '                $table->id();';
            $cols[] = "                \$table->foreignId('{$foreignKey}')->constrained('{$parentTable}')->cascadeOnDelete();";
            foreach (($def['fields'] ?? []) as $subName => $sub) {
                $subName = (string) $subName;
                if ($subName === $foreignKey || ! $isIdentifier($subName)) {
                    continue;
                }
                $cols[] = '                '.$this->childRepeaterColumn($subName, is_array($sub) ? $sub : []);
            }
            $cols[] = '                $table->timestamps();';
            $blocks[] = "\n\n        if (! Schema::hasTable('{$childTable}')) {\n            Schema::create('{$childTable}', function (Blueprint \$table) {\n".implode("\n", $cols)."\n            });\n        }";
        }
        foreach ($multiselectDefinitions as $def) {
            $childTable = (string) ($def['table'] ?? '');
            if (! $isIdentifier($childTable) || isset($seenTables[$childTable])) {
                continue;
            }
            $seenTables[$childTable] = true;
            $foreignKey = (string) ($def['foreign_key'] ?? '');
            if (! $isIdentifier($foreignKey)) {
                continue;
            }
            $targetField = (string) ($def['target_field'] ?? '');
            if (! $isIdentifier($targetField)) {
                continue;
            }
            $cols = [];
            $cols[] = '                $table->id();';
            $cols[] = "                \$table->unsignedBigInteger('{$foreignKey}')->index();";
            if (! empty($def['parent_type'])) {
                $cols[] = "                \$table->string('parent_type')->nullable();";
            }
            $cols[] = "                \$table->unsignedBigInteger('{$targetField}');";
            $cols[] = "                \$table->unsignedBigInteger('created_by')->nullable();";
            $cols[] = "                \$table->unsignedBigInteger('updated_by')->nullable();";
            $cols[] = '                $table->timestamps();';
            $blocks[] = "\n\n        if (! Schema::hasTable('{$childTable}')) {\n            Schema::create('{$childTable}', function (Blueprint \$table) {\n".implode("\n", $cols)."\n            });\n        }";
        }

        return implode('', $blocks);
    }

    /**
     * D-014: the down() drops for the child tables emitted
     * by childMigrationUp(), in reverse declaration order
     * (children before the parent drop the template adds).
     * Returns '' when nothing is declared.
     */
    protected function childMigrationDown(array $repeaterDefinitions, array $multiselectDefinitions): string
    {
        $tables = [];
        foreach ([$repeaterDefinitions, $multiselectDefinitions] as $definitions) {
            foreach ($definitions as $def) {
                $childTable = (string) ($def['table'] ?? '');
                if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $childTable) === 1 && ! in_array($childTable, $tables, true)) {
                    $tables[] = $childTable;
                }
            }
        }
        $down = '';
        foreach (array_reverse($tables) as $childTable) {
            $down .= "        Schema::dropIfExists('{$childTable}');\n";
        }

        return $down;
    }

    /**
     * One repeater sub-field column, typed from the
     * FieldDefinitionNormalizer type-to-migration map the
     * parent columns use (string->string, text->text,
     * decimal->decimal(12, 2), boolean->boolean, date->date,
     * datetime->dateTime, select/email/url->string,
     * json->json, float->float, bigInteger->bigInteger,
     * timestamp->timestamp), with one documented deviation:
     * integer (and foreign_id) sub-fields are Link-shaped
     * row references in the source systems, so they emit
     * unsignedBigInteger — the shape the hand-written
     * repeater child migrations (item_defaults,
     * brand_defaults, item_taxes) and the porter's own
     * scaffoldChildTable() use for integer columns.
     * A required sub-field is not nullable; an optional
     * one is.
     */
    protected function childRepeaterColumn(string $name, array $sub): string
    {
        $type = (string) ($sub['type'] ?? 'string');
        $nullable = ! empty($sub['required']) ? '' : '->nullable()';
        if ($type === 'decimal') {
            return "\$table->decimal('{$name}', 12, 2){$nullable};";
        }
        $method = match ($type) {
            'integer', 'foreign_id' => 'unsignedBigInteger',
            'text' => 'text',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime' => 'dateTime',
            'json' => 'json',
            'float' => 'float',
            'bigInteger' => 'bigInteger',
            'timestamp' => 'timestamp',
            default => 'string',
        };

        return "\$table->{$method}('{$name}'){$nullable};";
    }

    /**
     * Multiselect (Table MultiSelect) definitions from the
     * fields map: a field of type multiselect declares its
     * child table, the child table's parent key, the child
     * row's single link column, the target slice the picker
     * resolves options from, and — for polymorphic child
     * tables — the parent type written onto new rows.
     * Shape per handle: ['table', 'foreign_key',
     * 'target_field', 'target', 'parent_type' => ?string].
     */
    protected function extractMultiselectDefinitions(array $fields = []): array
    {
        $defs = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ($field['type'] ?? '') !== 'multiselect') {
                continue;
            }
            $handle = (string) ($field['name'] ?? '');
            if ($handle === '') {
                continue;
            }
            $defs[$handle] = [
                'table' => (string) ($field['table'] ?? ''),
                'foreign_key' => (string) ($field['foreign_key'] ?? ''),
                'target_field' => (string) ($field['target_field'] ?? ''),
                'target' => (string) ($field['target'] ?? ''),
                'parent_type' => is_string($field['parent_type'] ?? null) && $field['parent_type'] !== ''
                    ? $field['parent_type']
                    : null,
            ];
        }

        return $defs;
    }

    protected function extractRepeaterDefinitions(array $uiConfig, string $rootTable, array $fields = []): array
    {
        $defs = [];
        foreach (($uiConfig['forms']['sections'] ?? []) as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                if (! is_array($field) || ($field['type'] ?? '') !== 'repeater') {
                    continue;
                }
                $handle = $field['handle'] ?? '';
                if (! is_string($handle) || $handle === '') {
                    continue;
                }
                $table = is_string($field['table'] ?? null) && $field['table'] !== ''
                    ? $field['table']
                    : Str::snake($handle);
                $fields = [];
                foreach (($field['repeater_fields'] ?? []) as $rf) {
                    if (! is_array($rf)) {
                        continue;
                    }
                    $rfName = $rf['handle'] ?? $rf['name'] ?? '';
                    if (! is_string($rfName) || $rfName === '' || $rfName === 'id') {
                        continue;
                    }
                    $fields[$rfName] = [
                        'label' => $rf['label'] ?? Str::headline($rfName),
                        'type' => $rf['type'] ?? 'string',
                        'required' => (bool) ($rf['required'] ?? false),
                        'options' => is_array($rf['options'] ?? null) ? $rf['options'] : [],
                    ];
                    if (is_string($rf['target'] ?? null) && $rf['target'] !== '') {
                        $fields[$rfName]['target'] = $rf['target'];
                    }
                }
                $defs[$handle] = [
                    'table' => $table,
                    'foreign_key' => is_string($field['foreign_key'] ?? null) && $field['foreign_key'] !== ''
                        ? $field['foreign_key']
                        : Str::singular($rootTable).'_id',
                    'model' => is_string($field['model'] ?? null) && $field['model'] !== ''
                        ? $field['model']
                        : Str::snake(Str::singular($table)),
                    'fields' => $fields,
                ];
                // Document arithmetic (D3): a declared document
                // block rides into the service definition; the
                // base service recomputes amounts/totals on save.
                if (is_array($field['document'] ?? null) && $field['document'] !== []) {
                    $defs[$handle]['document'] = $field['document'];
                }
                // Shared child tables (D-036): see the field-map
                // dialect below — parent_type scopes the rows.
                if (is_string($field['parent_type'] ?? null) && $field['parent_type'] !== '') {
                    $defs[$handle]['parent_type'] = $field['parent_type'];
                }
            }
        }

        // Field-map dialect (Seat: child Tables carried as
        // fields, e.g. a settings page's slot grid): a field
        // of type repeater in the top-level fields map is the
        // same declaration seated on the field instead of in
        // ui.forms.sections. Both dialects feed the one
        // machinery; a ui.forms declaration wins on collision.
        foreach ($fields as $field) {
            if (! is_array($field) || ($field['type'] ?? '') !== 'repeater') {
                continue;
            }
            $handle = (string) ($field['name'] ?? '');
            if ($handle === '' || isset($defs[$handle])) {
                continue;
            }
            $table = is_string($field['table'] ?? null) && $field['table'] !== ''
                ? $field['table']
                : Str::snake($handle);
            $subFields = [];
            foreach (($field['repeater_fields'] ?? []) as $rf) {
                if (! is_array($rf)) {
                    continue;
                }
                $rfName = $rf['handle'] ?? $rf['name'] ?? '';
                if (! is_string($rfName) || $rfName === '' || $rfName === 'id') {
                    continue;
                }
                $subFields[$rfName] = [
                    'label' => $rf['label'] ?? Str::headline($rfName),
                    'type' => $rf['type'] ?? 'string',
                    'required' => (bool) ($rf['required'] ?? false),
                    'options' => is_array($rf['options'] ?? null) ? $rf['options'] : [],
                ];
                if (is_string($rf['target'] ?? null) && $rf['target'] !== '') {
                    $subFields[$rfName]['target'] = $rf['target'];
                }
            }
            if ($subFields === []) {
                continue;
            }
            $defs[$handle] = [
                'table' => $table,
                'foreign_key' => is_string($field['foreign_key'] ?? null) && $field['foreign_key'] !== ''
                    ? $field['foreign_key']
                    : Str::singular($rootTable).'_id',
                'model' => is_string($field['model'] ?? null) && $field['model'] !== ''
                    ? $field['model']
                    : Str::snake(Str::singular($table)),
                'fields' => $subFields,
            ];
            // Shared child tables (D-036): a repeater may declare
            // parent_type — the service then scopes load / sync /
            // delete by it, so one child table can serve several
            // parents without rows bleeding across.
            if (is_string($field['parent_type'] ?? null) && $field['parent_type'] !== '') {
                $defs[$handle]['parent_type'] = $field['parent_type'];
            }
        }

        return $defs;
    }

    private function writeFile(string $path, string $contents): void
    {
        // Generated PHP must parse; files are staged, so a failure leaves nothing behind
        if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
            try {
                token_get_all($contents, TOKEN_PARSE);
            } catch (\ParseError $e) {
                throw new \RuntimeException('Generated '.basename($path)." is not valid PHP (line {$e->getLine()}): {$e->getMessage()}", 0, $e);
            }
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write generated file: {$path}");
        }
    }
}
