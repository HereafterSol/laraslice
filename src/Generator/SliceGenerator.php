<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Support\Directory;
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

    public function generate(string $name, array $fields = [], bool $includeWorkflow = false, array $options = []): string
    {
        $studlyName = SliceName::canonical($name);
        $pluralName = Str::plural($studlyName);
        $snakeName = Str::snake($studlyName);
        $pluralSnake = Str::snake($pluralName);

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

        $fields = $this->fieldNormalizer->normalize($fields);
        $fieldsByName = [];
        foreach ($fields as $field) {
            $fieldsByName[$field['name']] = $field;
        }

        $softDeletes = (bool) ($options['soft_deletes'] ?? false);
        $migrationSoftDeletes = $softDeletes ? "\n                \$table->softDeletes();" : '';
        $encryptedColumns = array_column(array_filter($fields, fn (array $f) => ! empty($f['encrypted'])), 'name');
        $modelCasts = $encryptedColumns === []
            ? ''
            : "\n    protected \$casts = ".var_export(array_fill_keys($encryptedColumns, 'encrypted'), true).';';

        $titleField = $fieldsByName['title'] ?? null;
        $descriptionField = $fieldsByName['description'] ?? null;
        $statusField = $fieldsByName['status'] ?? null;

        $extraFields = array_values(array_filter($fields, fn (array $f) => ! in_array($f['name'], ['title', 'description', 'status'], true)));
        $customColumns = array_column($fields, 'name');

        $manifestFields = array_map(fn (array $field) => [
            'name' => $field['name'],
            'label' => $field['label'],
            'type' => $field['type'],
            'nullable' => $field['nullable'],
            'default' => $field['default'],
            'options' => $field['options'] ?? [],
            'hidden' => ! empty($field['hidden']),
            'show_in_form' => isset($field['show_in_form']) ? (bool) $field['show_in_form'] : empty($field['hidden']),
            'show_in_list' => isset($field['show_in_list']) ? (bool) $field['show_in_list'] : empty($field['hidden']),
        ], $fields);

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

        // DTO properties strictly for defined fields
        $dtoFormList = [];
        $dtoListingList = [];
        foreach ($fields as $f) {
            $dtoFormList[] = '    '.$this->fieldNormalizer->dtoProperty($f['name'], $f);
            if (empty($f['hidden']) && ($f['show_in_list'] ?? true)) {
                $dtoListingList[] = '    '.$this->fieldNormalizer->dtoProperty($f['name'], $f);
            }
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
            $schemaTableList[] = "            Column::make('{$searchColumn}')->label(".var_export($fieldsByName[$searchColumn]['label'], true).")->sortable()->searchable()";
        }
        if ($statusField && empty($statusField['hidden']) && ($statusField['show_in_list'] ?? true)) {
            $schemaTableList[] = "            Column::make('status')->label(".var_export($statusField['label'], true).")->badge()";
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
        $dataTableColumns = implode("
", $dtCols);

        // Check if declarative UI configuration is passed
        $uiConfig = $options['ui'] ?? [];
        if (!empty($uiConfig['directory']['columns']) && is_array($uiConfig['directory']['columns'])) {
            $dtCols = [];
            foreach ($uiConfig['directory']['columns'] as $c) {
                $handle = $c['handle'] ?? $c['key'] ?? 'id';
                $label = $c['label'] ?? \Illuminate\Support\Str::headline($handle);
                $isHtml = in_array($c['type'] ?? '', ['code_badge', 'composite_user', 'status_pill', 'money_breakdown', 'progress_bar', 'badge', 'text_with_badge', 'date_overdue'], true);
                $dtCols[] = "            ['key' => '{$handle}', 'label' => '{$label}', 'sortable' => true" . ($isHtml ? ", 'html' => true" : '') . "],";
            }
            $dataTableColumns = implode("
", $dtCols);
        }

        // Form elements for Blade view (only visible, non-audit fields in responsive grid)
        $formAuditBlacklist = [
            'id', 'company_id', 'added_by', 'last_updated_by', 'created_by', 'updated_by',
            'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
            'calendar_view', 'attendance_reminder', 'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at',
        ];

        $formInputs = [];
        $wideFieldKeywords = ['address', 'description', 'notes', 'memo', 'summary', 'about', 'instructions', 'content', 'body', 'remarks'];

        foreach ($fields as $f) {
            $fName = $f['name'] ?? '';
            if (in_array($fName, $formAuditBlacklist, true)) {
                continue;
            }
            if (empty($f['hidden']) && ($f['show_in_form'] ?? true)) {
                $markup = $f['form'];
                $isWide = ($f['type'] ?? '') === 'text' || ($f['type'] ?? '') === 'json' || in_array($fName, $wideFieldKeywords, true);
                if ($isWide) {
                    $markup = preg_replace('/<div class="space-y-1\.5"/', '<div class="space-y-1.5 md:col-span-2 lg:col-span-3"', $markup, 1);
                }
                $formInputs[] = $markup;
            }
        }

        if (count($formInputs) <= 6) {
            $inputsStr = implode("\n\n", $formInputs);
            $formSectionsHtml = <<<HTML
        <x-ui.card class="bg-card border border-border shadow-xs overflow-hidden">
            <x-ui.card-header class="border-b border-border/60 bg-muted/20 px-6 py-4">
                <div class="flex items-center gap-2">
                    <x-lucide-info class="size-4 text-primary" />
                    <x-ui.card-title class="text-base font-bold text-foreground">General Information</x-ui.card-title>
                </div>
                <x-ui.card-description class="text-xs">Configure {$studlyName} operational parameters</x-ui.card-description>
            </x-ui.card-header>
            <x-ui.card-content class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
{$inputsStr}
                </div>
            </x-ui.card-content>
        </x-ui.card>
HTML;
        } else {
            $primaryInputs = array_slice($formInputs, 0, 6);
            $secondaryInputs = array_slice($formInputs, 6);
            $primaryStr = implode("\n\n", $primaryInputs);
            $secondaryStr = implode("\n\n", $secondaryInputs);

            $formSectionsHtml = <<<HTML
        <x-ui.card class="bg-card border border-border shadow-xs overflow-hidden">
            <x-ui.card-header class="border-b border-border/60 bg-muted/20 px-6 py-4">
                <div class="flex items-center gap-2">
                    <x-lucide-layers class="size-4 text-primary" />
                    <x-ui.card-title class="text-base font-bold text-foreground">Core Parameters</x-ui.card-title>
                </div>
                <x-ui.card-description class="text-xs">Primary identification and record attributes</x-ui.card-description>
            </x-ui.card-header>
            <x-ui.card-content class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
{$primaryStr}
                </div>
            </x-ui.card-content>
        </x-ui.card>

        <x-ui.card class="bg-card border border-border shadow-xs overflow-hidden">
            <x-ui.card-header class="border-b border-border/60 bg-muted/20 px-6 py-4">
                <div class="flex items-center gap-2">
                    <x-lucide-sliders class="size-4 text-primary" />
                    <x-ui.card-title class="text-base font-bold text-foreground">Additional Details</x-ui.card-title>
                </div>
                <x-ui.card-description class="text-xs">Extended parameters and operational terms</x-ui.card-description>
            </x-ui.card-header>
            <x-ui.card-content class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
{$secondaryStr}
                </div>
            </x-ui.card-content>
        </x-ui.card>
HTML;
        }

        if (!empty($uiConfig['forms']['sections']) && is_array($uiConfig['forms']['sections'])) {
            $customCards = [];
            foreach ($uiConfig['forms']['sections'] as $sIdx => $sec) {
                $sTitle = $sec['title'] ?? ('Section ' . ($sIdx + 1));
                $sDesc = $sec['description'] ?? 'Configure record attributes';
                $sIcon = $sec['icon'] ?? 'layers';
                $sCols = (int) ($sec['columns'] ?? 3);
                $gridClass = match ($sCols) {
                    1 => 'grid grid-cols-1 gap-5',
                    2 => 'grid grid-cols-1 md:grid-cols-2 gap-5',
                    4 => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5',
                    default => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5',
                };

                $cardInputs = [];
                foreach ($sec['fields'] ?? [] as $fld) {
                    $fHandle = is_array($fld) ? ($fld['handle'] ?? '') : (string) $fld;
                    $fSpan = is_array($fld) ? (int) ($fld['span'] ?? 1) : 1;
                    $isSearchable = is_array($fld) && !empty($fld['searchable']);
                    $fType = is_array($fld) ? ($fld['type'] ?? '') : '';

                    // Repeater field (e.g., invoice line items) — render x-ui.repeater
                    if ($fType === 'repeater') {
                        $repLabel = $fld['label'] ?? \Illuminate\Support\Str::headline($fHandle);
                        $addBtn = $fld['add_button_text'] ?? ('Add ' . \Illuminate\Support\Str::singular($repLabel));
                        $repFields = $fld['repeater_fields'] ?? [];
                        // Build sub-field definitions for the repeater
                        $subFieldDefs = [];
                        foreach ($repFields as $rf) {
                            $rfName = $rf['handle'] ?? $rf['name'] ?? '';
                            $rfLabel = $rf['label'] ?? \Illuminate\Support\Str::headline($rfName);
                            $rfType = $rf['type'] ?? 'text';
                            $subFieldDefs[] = "['name' => '{$rfName}', 'label' => '{$rfLabel}', 'type' => '{$rfType}']";
                        }
                        $subFieldsStr = '[' . implode(', ', $subFieldDefs) . ']';
                        $cardInputs[] = "<x-ui.repeater name=\"{$fHandle}\" label=\"{$repLabel}\" :fields=\"{$subFieldsStr}\" addButtonText=\"{$addBtn}\" class=\"md:col-span-2 lg:col-span-3\" />";
                        continue;
                    }

                    // Find field matching handle
                    $fieldObj = collect($fields)->firstWhere('name', $fHandle);
                    if ($fieldObj && !empty($fieldObj['form'])) {
                        $markup = $fieldObj['form'];
                        if ($fSpan >= 3 || $fSpan >= $sCols) {
                            $markup = preg_replace('/<div class="space-y-1\.5"/', '<div class="space-y-1.5 md:col-span-2 lg:col-span-3"', $markup, 1);
                        } elseif ($fSpan === 2) {
                            $markup = preg_replace('/<div class="space-y-1\.5"/', '<div class="space-y-1.5 md:col-span-2"', $markup, 1);
                        }
                        if ($isSearchable && str_contains($markup, '<select')) {
                            // Replace <select> with <x-ui.searchable-select>
                            $markup = preg_replace('/<select[^>]*>.*?<\/select>/s', "<x-ui.searchable-select name=\"{$fHandle}\" :value=\"old('{$fHandle}', $form->{$fHandle} ?? '')\" placeholder=\"Select {$fieldObj['label']}...\" />", $markup);
                        }
                        $cardInputs[] = $markup;
                    }
                }

                $inputsCombined = implode("

", $cardInputs);
                $customCards[] = <<<CARD
        <x-ui.card class="bg-card border border-border shadow-xs overflow-hidden">
            <x-ui.card-header class="border-b border-border/60 bg-muted/20 px-6 py-4">
                <div class="flex items-center gap-2">
                    <x-lucide-{$sIcon} class="size-4 text-primary" />
                    <x-ui.card-title class="text-base font-bold text-foreground">{$sTitle}</x-ui.card-title>
                </div>
                <x-ui.card-description class="text-xs">{$sDesc}</x-ui.card-description>
            </x-ui.card-header>
            <x-ui.card-content class="p-6">
                <div class="{$gridClass}">
{$inputsCombined}
                </div>
            </x-ui.card-content>
        </x-ui.card>
CARD;
            }
            if (!empty($customCards)) {
                $formSectionsHtml = implode("

", $customCards);
            }
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
                \Illuminate\Support\Facades\File::deleteDirectory($targetDir);
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

            // 1. slice.json Manifest
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
            $manifestData = [
                'name' => $pluralName,
                'title' => Str::title(Str::snake($pluralName, ' ')),
                'version' => '1.0.0',
                'description' => $description,
                'author' => $author,
                'active' => true,
                'workflow' => $manifestWorkflow,
                'roles' => ! empty($options['roles']) && is_array($options['roles']) ? $options['roles'] : (! empty($options['workflowConfig']['roles']) ? $options['workflowConfig']['roles'] : []),
                'fields' => ManifestRepository::fieldMap($manifestFields),
                'permissions' => ! empty($options['permissions']) && is_array($options['permissions'])
                    ? $options['permissions']
                    : [
                        "{$snakeName}.view",
                        "{$snakeName}.create",
                        "{$snakeName}.edit",
                        "{$snakeName}.delete",
                    ],
                'namespace' => $sliceNamespace,
                'navigation' => [
                    'label' => Str::title(Str::snake($pluralName, ' ')),
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

            // Phase 2: YAML only. Do not create slice.json.
            \LaraSlice\Core\Discovery\ManifestRepository::writeYaml($sliceDir, $manifestData);

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
            foreach ($fields as $def) {
                $isForeignKey = ($def['type'] ?? '') === 'foreign_id' || str_ends_with($def['name'] ?? '', '_id');
                if (! $isForeignKey) {
                    continue;
                }
                $relation = $def['relation'] ?? [];
                $relName = Str::camel($relation['name'] ?? Str::replaceLast('_id', '', $def['name']));
                $targetModel = Str::studly(Str::singular($relation['model'] ?? Str::replaceLast('_id', '', $def['name'])));
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

    protected function mapToForm(Model \$model): IBusinessObject
    {
        return {$studlyName}FormBusinessObject::fromArray(\$model->toArray());
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
        return '{$pluralSnake}.';
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
                $aliasRedirects[] = "Route::redirect('{$pluralSnake}/{any}', '/{$targetRedirect}/{any}')->where('any', '.*');";
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

// Plural resource routes: {$pluralSnake}.index, {$pluralSnake}.create, etc.
Route::prefix('{$routePrefix}')->name('{$pluralSnake}.')->middleware(config('laraslice.generated_routes.web_middleware', ['web', 'auth']))->group(function () {
    Route::get('/', [{$studlyName}WebController::class, 'index'])->name('index');
    Route::get('/create', [{$studlyName}WebController::class, 'create'])->name('create');
    Route::post('/', [{$studlyName}WebController::class, 'store'])->name('store');
    Route::get('/{id}', [{$studlyName}WebController::class, 'show'])->name('show');
    Route::get('/{id}/edit', [{$studlyName}WebController::class, 'edit'])->name('edit');
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
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
                    <a :href="item.r.show_url" class="size-8 rounded-lg hover:bg-muted/80 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors border border-transparent hover:border-border/60" title="View">
                        <x-lucide-eye class="size-4" />
                    </a>
                    <x-ui.row-actions 
                        edit-url="item.r.edit_url" 
                        delete-url="item.r.delete_url"
                        delete-confirm="Are you sure you want to delete this {$studlyName}?"
                    />
                </div>
            </x-slot:actions>
ACTIONS;
            } elseif ($rowActionsDisplay === 'inline') {
                $rowActionsSlot = <<<ACTIONS
            <x-slot:actions>
                <div class="flex items-center gap-1 justify-end">
                    <a :href="item.r.show_url" class="size-8 rounded-lg hover:bg-muted/80 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors" title="View">
                        <x-lucide-eye class="size-4" />
                    </a>
                    <a :href="item.r.edit_url" class="size-8 rounded-lg hover:bg-muted/80 flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors" title="Edit">
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
                        view-url="item.r.show_url"
                        edit-url="item.r.edit_url"
                        delete-url="item.r.delete_url"
                        delete-confirm="Are you sure you want to delete this {$studlyName}?"
                    />
                </div>
            </x-slot:actions>
ACTIONS;
            }
                        $sliceJsonAbs = str_replace(["'"], ["\\'"], str_replace('\\', '/', rtrim($sliceDir, '/\\') . '/slice.json'));
            $sliceYamlAbs = str_replace('slice.json', 'slice.yaml', $sliceJsonAbs);

$bladeIndex = <<<BLADE
@extends('layouts.app')

@section('content')
<div class="space-y-4">
    {{-- Bulk workflow forward (shows when table rows checked) --}}
    <x-workflows::bulk-bar slice="{$studlyName}" />

    <!-- Compact Top Bar (Matches Enterprise ERP Standard) -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-2 border-b border-border/40">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold tracking-tight text-foreground">{$pluralName}</h1>
            <div class="flex items-center gap-1.5 text-xs text-muted-foreground font-medium">
                <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:text-primary transition-colors">Home</a>
                <span>•</span>
                <span class="text-foreground">{$pluralName}</span>
            </div>
            <x-ui.badge variant="secondary" class="font-mono text-[10px] px-1.5 py-0">v1.0.0</x-ui.badge>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            @if((\$can['seed'] ?? true) && Route::has('laraslice.wizard.seed_slice'))
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

            <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors">
                <x-lucide-download class="size-3.5 text-muted-foreground" />
                <span>Export</span>
            </button>

            @if(\$can['create'] ?? true)
            @if((\$uiPresentationCreate ?? 'page') === 'drawer')
            <x-ui.drawer>
                <x-ui.drawer-trigger as-child>
                    <x-ui.button size="sm" class="gap-1.5 shadow-xs font-semibold">
                        <x-lucide-plus class="size-3.5" />
                        <span>Add {$studlyName}</span>
                    </x-ui.button>
                </x-ui.drawer-trigger>
                <x-ui.drawer-content>
                    <x-ui.drawer-header><x-ui.drawer-title>Add {$studlyName}</x-ui.drawer-title></x-ui.drawer-header>
                    <div id="{$pluralSnake}-drawer-form"></div>
                </x-ui.drawer-content>
            </x-ui.drawer>
            @else
            <x-ui.button href="{{ route('{$pluralSnake}.create') }}" as="a" size="sm" class="gap-1.5 shadow-xs font-semibold">
                <x-lucide-plus class="size-3.5" />
                <span>Add {$studlyName}</span>
            </x-ui.button>
            @endif
            @endif
        </div>
    </div>

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
        \$rows = \\LaraSlice\\Support\\DataTableRows::from(\$pagedList->items, \$columns, fn (\$item) => [
            'show_url'   => \Illuminate\Support\Facades\Route::has('{$pluralSnake}.show') ? route('{$pluralSnake}.show', \$item->id) : null,
            'edit_url'   => \Illuminate\Support\Facades\Route::has('{$pluralSnake}.edit') ? route('{$pluralSnake}.edit', \$item->id) : null,
            'delete_url' => \Illuminate\Support\Facades\Route::has('{$pluralSnake}.destroy') ? route('{$pluralSnake}.destroy', \$item->id) : null,
        ]);
    @endphp

    @if (count(\$rows) === 0)
        <div class="flex flex-col items-center justify-center gap-3 py-12 text-center text-muted-foreground rounded-2xl border border-dashed border-border bg-card/50">
            <x-lucide-inbox class="size-8 text-muted-foreground/50" />
            <div>
                <p class="text-sm font-semibold text-foreground">No records found in {$pluralName}</p>
                <p class="text-xs text-muted-foreground mt-0.5">Start by creating your first entry or generate realistic mock data.</p>
            </div>
            <div class="flex items-center gap-2 pt-1">
                @if(\$can['create'] ?? true)
                <x-ui.button href="{{ route('{$pluralSnake}.create') }}" as="a" size="sm">
                    Create {$studlyName}
                </x-ui.button>
                @endif
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
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            @foreach(\$kpiCards as \$card)
            <div class="rounded-2xl border border-border bg-card p-4 shadow-2xs">
                <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">{{ \$card['label'] ?? (\$card['handle'] ?? 'Stat') }}</p>
                <p class="text-2xl font-bold text-foreground mt-1 tabular-nums">{{ \$summaryData[\$card['handle'] ?? ''] ?? (\$card['handle'] === 'total' ? (\$summaryData['total'] ?? '—') : '—') }}</p>
            </div>
            @endforeach
        </div>
        @endif

        <!-- Quick Filter Tabs (from ui.directory.quick_filters) -->
        @if(!empty(\$quickFilters))
        <div class="mb-4">
            @foreach(\$quickFilters as \$qf)
            <x-ui.filter-tabs
                :tabs="\$qf['options'] ?? []"
                :param="\$qf['handle']"
            />
            @endforeach
        </div>
        @endif

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
            \$tableViews = \$dirUi['views'] ?? ['table'];
            if (! is_array(\$tableViews) || \$tableViews === []) {
                \$tableViews = ['table'];
            }
            \$defaultView = \$dirUi['default_view'] ?? 'table';
            \$inlineEdit = \$dirUi['inline_edit'] ?? [];
            if (! is_array(\$inlineEdit)) {
                \$inlineEdit = [];
            }
            \$statusKey = 'status';
            \$dateKey = null;
            foreach (\$columns as \$c) {
                \$ck = \$c['key'] ?? '';
                if (in_array(\$ck, ['status', 'state', 'stage'], true)) {
                    \$statusKey = \$ck;
                }
                if (\$dateKey === null && (str_contains(\$ck, 'date') || str_ends_with(\$ck, '_at'))) {
                    \$dateKey = \$ck;
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
            :views="\$tableViews"
            :default-view="\$defaultView"
            :status-key="\$statusKey"
            :inline-edit="\$inlineEdit"
            :date-key="\$dateKey"
        >
{$rowActionsSlot}
        </x-ui.data-table>
    @endif
</div>
@endsection
BLADE;
            $this->writeFile($sliceDir.'/Resources/views/index.blade.php', $bladeIndex);

            $bladeForm = <<<BLADE
@extends('layouts.app')

@section('content')
<div class="w-full max-w-5xl mx-auto space-y-6">
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('{$pluralSnake}.index') }}" class="hover:text-primary transition-colors">{$pluralName}</a>
        <span>/</span>
        <span class="text-foreground font-medium">{{ \$isNew ? 'Create {$studlyName}' : 'Edit {$studlyName} #' . \$form->id }}</span>
    </div>

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

    <form action="{{ \$isNew ? route('{$pluralSnake}.store') : route('{$pluralSnake}.update', \$form->id) }}" method="POST" class="space-y-6">
        @csrf
        @if(!\$isNew) @method('PUT') @endif

{$formSectionsHtml}

        <!-- Sticky Bottom Form Actions Bar -->
        <div class="sticky bottom-4 z-10 p-4 rounded-2xl border border-border bg-card/95 backdrop-blur-md shadow-lg flex items-center justify-between">
            <x-ui.button href="{{ route('{$pluralSnake}.index') }}" as="a" variant="ghost" class="text-xs font-semibold text-muted-foreground hover:text-foreground">
                Cancel
            </x-ui.button>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" name="action" value="save_continue" variant="outline" class="gap-1.5 text-xs font-semibold shadow-2xs">
                    <x-lucide-save class="size-3.5" />
                    <span>Save & Continue</span>
                </x-ui.button>
                <x-ui.button type="submit" name="action" value="save_close" class="gap-1.5 text-xs font-bold shadow-xs">
                    <x-lucide-check class="size-3.5" />
                    <span>{{ \$isNew ? 'Create {$studlyName}' : 'Save Changes' }}</span>
                </x-ui.button>
            </div>
        </div>
    </form>
</div>
@endsection
BLADE;
            $this->writeFile($sliceDir.'/Resources/views/form.blade.php', $bladeForm);
            $this->writeFile($sliceDir.'/Resources/views/create.blade.php', $bladeForm);
            $this->writeFile($sliceDir.'/Resources/views/edit.blade.php', $bladeForm);

            $showView = <<<BLADE
@extends('layouts.app')

@section('content')
@php
    \$id = \$record->id ?? 1;
    \$label = \$record->title ?? \$record->name ?? ('{$studlyName} #' . \$id);
    \$subtitle = \$record->subtitle ?? \$record->email ?? \$record->code ?? ('{$studlyName} Record');
    \$initials = strtoupper(substr(\$label, 0, 2));
    \$workflow = method_exists(\$record, 'getWorkflowDefinition') ? \$record->getWorkflowDefinition() : null;
    \$transitions = method_exists(\$record, 'getAvailableTransitions') ? \$record->getAvailableTransitions() : [];
    \$sla = method_exists(\$record, 'getSlaStatus') ? \$record->getSlaStatus() : null;
    \$currentState = (string) (\$record->status ?? 'active');
    \$currentStateModel = \$workflow ? \$workflow->states->firstWhere('slug', \$currentState) : null;
    \$stateLabel = \$currentStateModel?->label ?? ucwords(str_replace('_', ' ', \$currentState));
    \$badgeColor = \$currentStateModel?->badge_color ?? 'indigo';
    \$actionUrl = \Illuminate\Support\Facades\Route::has('workflows.transition') ? route('workflows.transition', ['slice' => '{$studlyName}', 'id' => \$id], false) : '#';
@endphp

<div class="space-y-6" x-data="{ activeTab: new URLSearchParams(window.location.search).get('tab') || 'overview' }">
    <!-- Breadcrumbs -->
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <a href="{{ route('{$pluralSnake}.index') }}" class="hover:text-primary transition-colors flex items-center gap-1">
            <x-lucide-arrow-left class="size-3" />
            <span>{$pluralName}</span>
        </a>
        <span>/</span>
        <span class="text-foreground font-medium">{{ \$label }}</span>
    </div>

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

    <!-- Tier 1: Persistent Summary Banner & Identity Header -->
    <x-ui.card class="p-6 bg-card border shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div class="flex items-center gap-4">
                <div class="size-14 rounded-2xl bg-primary/10 text-primary font-bold text-lg flex items-center justify-center shrink-0 ring-1 ring-border shadow-xs">
                    {{ \$initials }}
                </div>
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ \$label }}</h1>
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
                <x-ui.button href="{{ route('{$pluralSnake}.index') }}" as="a" variant="outline" size="sm" class="gap-1.5">
                    <x-lucide-arrow-left class="size-3.5" />
                    <span>Back</span>
                </x-ui.button>
                <x-ui.button href="{{ route('{$pluralSnake}.edit', \$id) }}" as="a" variant="outline" size="sm" class="gap-1.5">
                    <x-lucide-pencil class="size-3.5" />
                    <span>Edit</span>
                </x-ui.button>
                <form action="{{ route('{$pluralSnake}.destroy', \$id) }}" method="POST" class="inline" onsubmit="return confirm('Delete this record?')">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="ghost" size="sm" class="text-destructive hover:text-destructive">
                        <x-lucide-trash-2 class="size-3.5" />
                    </x-ui.button>
                </form>
            </div>
        </div>
    </x-ui.card>

    <!-- Tier 2: 4-KPI Metric Strip -->
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

    @if(\$workflow)
        <!-- Workflow Stepper Pipeline -->
        <x-workflows::stepper :model="\$record" />
    @endif

    <!-- Tier 3: Responsive Sub-Tabs Ribbon -->
    <div class="border-b border-border flex items-center gap-1 overflow-x-auto">
        <button 
            type="button"
            @click="activeTab = 'overview'; window.history.replaceState(null, '', '?tab=overview')"
            class="px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors flex items-center gap-2 whitespace-nowrap"
            :class="activeTab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
        >
            <x-lucide-file-text class="size-4" />
            <span>Overview & Details</span>
        </button>

        @if(\$workflow)
        <button 
            type="button"
            @click="activeTab = 'workflow'; window.history.replaceState(null, '', '?tab=workflow')"
            class="px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors flex items-center gap-2 whitespace-nowrap"
            :class="activeTab === 'workflow' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
        >
            <x-lucide-git-branch class="size-4" />
            <span>Workflow & Audit Trail</span>
        </button>
        @endif

        {{-- Aggregate related records (ERP Project hub parity) --}}
        @php
            \$relatedTabs = \$relatedTabs ?? [];
            if (\$relatedTabs === [] && isset(\$record) && is_object(\$record)) {
                try {
                    \$_mf = '{$sliceJsonAbs}';
                    if (is_file(\$_mf)) {
                        \$_m = json_decode((string) file_get_contents(\$_mf), true) ?: [];
                        foreach ((\$_m['ui']['show']['tabs'] ?? []) as \$_tab) {
                            if ((\$_tab['type'] ?? '') === 'relation') {
                                \$relatedTabs[] = \$_tab;
                            }
                        }
                        if (\$relatedTabs === []) {
                            foreach ((\$_m['models'][0]['relations'] ?? []) as \$_rel) {
                                if ((\$_rel['type'] ?? '') === 'hasMany') {
                                    \$relatedTabs[] = [
                                        'handle' => \$_rel['name'] ?? '',
                                        'label' => \\Illuminate\\Support\\Str::headline(\$_rel['name'] ?? 'Related'),
                                        'type' => 'relation',
                                        'relation' => \$_rel['name'] ?? '',
                                    ];
                                }
                            }
                        }
                    }
                } catch (\\Throwable \$e) {
                    \$relatedTabs = [];
                }
            }
        @endphp
        @foreach(\$relatedTabs as \$rtab)
            @if(!empty(\$rtab['handle']))
            <button
                type="button"
                @click="activeTab = '{{ \$rtab['handle'] }}'; window.history.replaceState(null, '', '?tab={{ \$rtab['handle'] }}')"
                class="px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors flex items-center gap-2 whitespace-nowrap"
                :class="activeTab === '{{ \$rtab['handle'] }}' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'"
            >
                <x-lucide-layers class="size-4" />
                <span>{{ \$rtab['label'] ?? \\Illuminate\\Support\\Str::headline(\$rtab['handle']) }}</span>
            </button>
            @endif
        @endforeach
    </div>

    <!-- Tab 1: Overview & Parameters -->
    <div x-show="activeTab === 'overview'" x-transition class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <!-- Left 2/3 Main Fieldset -->
            <div class="lg:col-span-2 space-y-6">
                <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
                    <x-ui.card-header class="border-b pb-4 px-6 pt-6">
                        <x-ui.card-title class="text-base font-semibold flex items-center gap-2">
                            <x-lucide-layers class="size-4 text-primary" />
                            <span>Attribute Parameters</span>
                        </x-ui.card-title>
                        <x-ui.card-description>Domain attributes and specifications</x-ui.card-description>
                    </x-ui.card-header>

                    <x-ui.card-content class="p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-5 gap-x-6">
                            @foreach((\$record->getAttributes() ?? []) as \$k => \$v)
                                @if(! in_array(\$k, ['id', 'created_at', 'updated_at', 'deleted_at', 'workflow_state_entered_at']) && ! is_array(\$v))
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ ucwords(str_replace('_', ' ', \$k)) }}</dt>
                                        <dd class="mt-1 text-sm font-medium text-foreground">{{ is_scalar(\$v) ? (string) \$v : '—' }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </x-ui.card-content>
                </x-ui.card>
            </div>

            <!-- Right 1/3 Action & Responsibility Sidebar -->
            <div class="space-y-6">
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
            </div>
        </div>
    </div>

    @foreach(\$relatedTabs ?? [] as \$rtab)
        @if(!empty(\$rtab['handle']) && (\$rtab['type'] ?? 'relation') === 'relation')
        <div x-show="activeTab === '{{ \$rtab['handle'] }}'" x-transition class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-bold text-foreground">{{ \$rtab['label'] ?? \\Illuminate\\Support\\Str::headline(\$rtab['handle']) }}</h3>
            </div>
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
