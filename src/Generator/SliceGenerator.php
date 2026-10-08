<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;

class SliceGenerator
{
    protected string $slicesPath;
    protected string $namespace;

    public function __construct(?string $slicesPath = null, ?string $namespace = null)
    {
        $this->slicesPath = $slicesPath ?: config('laraslice.slices_path', app_path('Slices'));
        $this->namespace = SliceNamespace::validate($namespace ?: config('laraslice.slices_namespace', 'App\\Slices'));
    }

    public function generate(string $name, array $fields = [], bool $includeWorkflow = false, array $options = []): string
    {
        $studlyName = SliceName::canonical($name);
        $pluralName = Str::plural($studlyName);
        $snakeName  = Str::snake($studlyName);
        $pluralSnake = Str::snake($pluralName);
        $tableName  = $pluralSnake;

        $domain = isset($options['domain']) && is_string($options['domain']) && trim($options['domain']) !== ''
            ? trim($options['domain'])
            : null;
        $domainFolder = $domain ? SliceName::domainSegment($domain) : null;
        $domainSlug = $domain ? Str::slug($domain) : null;
        $domainDot = $domainSlug ? str_replace('-', '_', $domainSlug) . '.' : '';
        $routePrefix = $domainSlug ? "{$domainSlug}/{$pluralSnake}" : $pluralSnake;
        $navUrl = '/' . $routePrefix;

        $targetDir = $domainFolder
            ? ($this->slicesPath . DIRECTORY_SEPARATOR . $domainFolder . DIRECTORY_SEPARATOR . $pluralName)
            : ($this->slicesPath . DIRECTORY_SEPARATOR . $pluralName);

        $sliceNamespace = $domainFolder
            ? "{$this->namespace}\\{$domainFolder}\\{$pluralName}"
            : "{$this->namespace}\\{$pluralName}";

        $fields = $this->normalizeFields($fields);
        $fieldsByName = [];
        foreach ($fields as $field) {
            $fieldsByName[$field['name']] = $field;
        }

        $softDeletes = (bool) ($options['soft_deletes'] ?? false);
        $migrationSoftDeletes = $softDeletes ? "\n                \$table->softDeletes();" : '';
        $encryptedColumns = array_column(array_filter($fields, fn (array $f) => $f['encrypted']), 'name');
        $modelCasts = $encryptedColumns === []
            ? ''
            : "\n    protected \$casts = " . var_export(array_fill_keys($encryptedColumns, 'encrypted'), true) . ';';

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
        ], $fields);

        $fillable = var_export(array_values(array_unique(array_merge(['title', 'description', 'status'], $customColumns))), true);
        $sortableColumns = implode(', ', array_map(fn (string $column) => var_export($column, true), array_values(array_unique(array_merge(['id', 'title', 'status', 'created_at', 'updated_at'], $customColumns)))));

        $dtoTitle = $this->dtoProperty('title', $titleField, 'string', '');
        $dtoDesc = $this->dtoProperty('description', $descriptionField, '?string', null);
        $dtoStatus = $this->dtoProperty('status', $statusField, 'string', 'draft');

        $dtoProperties = implode("\n", array_map(fn (array $field) => '    ' . $this->dtoProperty($field['name'], $field), $extraFields));
        if ($dtoProperties !== '') {
            $dtoProperties = "\n" . $dtoProperties;
        }

        $migrationTitle = $titleField ? $titleField['migration'] : "\$table->string('title');";
        $migrationDesc = $descriptionField ? $descriptionField['migration'] : "\$table->text('description')->nullable();";
        $migrationStatus = $statusField ? $statusField['migration'] : "\$table->string('status')->default('draft');";
        $migrationFields = implode("\n", array_map(fn (array $field) => '                ' . $field['migration'], $extraFields));
        if ($migrationFields !== '') {
            $migrationFields = "\n" . $migrationFields;
        }

        $titleRulesExport = var_export($titleField['rules'] ?? ['required', 'string', 'max:255'], true);
        $descRulesExport = var_export($descriptionField['rules'] ?? ['nullable', 'string'], true);
        $statusRulesExport = var_export($statusField['rules'] ?? ['required', 'string', 'in:draft,active,archived'], true);
        $validationRules = implode("\n", array_map(fn (array $field) => "            '{$field['name']}' => " . var_export($field['rules'], true) . ',', $extraFields));
        if ($validationRules !== '') {
            $validationRules = "\n" . $validationRules;
        }

        $schemaTitle = $titleField ? $titleField['schema'] : "Field::make('title')->label('Title')->required()->autofocus()";
        $schemaDesc = $descriptionField ? $descriptionField['schema'] : "Field::make('description', 'textarea')->rows(3)";
        if ($statusField) {
            $schemaStatus = $statusField['schema'];
        } else {
            $schemaStatus = "Field::make('status', 'select')->options([\n                'draft' => 'Draft',\n                'active' => 'Active',\n                'archived' => 'Archived',\n            ])->default('draft')";
        }
        $schemaFields = implode("\n", array_map(fn (array $field) => '            ' . $field['schema'] . ',', $extraFields));
        if ($schemaFields !== '') {
            $schemaFields = "\n" . $schemaFields;
        }

        $schemaTitleLabel = var_export($titleField['label'] ?? 'Title', true);
        $schemaStatusLabel = var_export($statusField['label'] ?? 'Status', true);
        $schemaTableColumns = implode("\n", array_map(fn (array $field) => "            Column::make('{$field['name']}')->label(" . var_export($field['label'], true) . '),', $extraFields));
        if ($schemaTableColumns !== '') {
            $schemaTableColumns = "\n" . $schemaTableColumns;
        }

        // Column definitions for the index data-table; SliceModifier appends new fields before the marker
        $dataTableColumn = fn (string $key, string $label): string => "            ['key' => " . var_export($key, true) . ", 'label' => " . var_export($label, true) . '],';
        $dataTableColumns = implode("\n", array_merge(
            [
                $dataTableColumn('id', 'ID'),
                $dataTableColumn('title', $titleField['label'] ?? 'Title'),
                $dataTableColumn('status', $statusField['label'] ?? 'Status'),
            ],
            array_map(fn (array $field) => $dataTableColumn($field['name'], $field['label']), $extraFields)
        ));

        if ($titleField) {
            $formTitle = $titleField['form'];
        } else {
            $formTitle = <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="title">Title *</x-ui.label>
                    <x-ui.input id="title" name="title" value="{{ old('title', \$form->title ?? '') }}" placeholder="Enter title..." required autofocus />
                    @error('title')
                        <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                    @enderror
                </div>
BLADE;
        }

        if ($descriptionField) {
            $formDescription = $descriptionField['form'];
        } else {
            $formDescription = <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="description">Description</x-ui.label>
                    <x-ui.textarea id="description" name="description" rows="3" placeholder="Enter description...">{{ old('description', \$form->description ?? '') }}</x-ui.textarea>
                    @error('description')
                        <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                    @enderror
                </div>
BLADE;
        }

        if ($statusField) {
            $formStatus = $statusField['form'];
        } else {
            $formStatus = <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="status">Publication Status</x-ui.label>
                    <select id="status" name="status" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                        <option value="draft" {{ old('status', \$form->status ?? '') === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="active" {{ old('status', \$form->status ?? '') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="archived" {{ old('status', \$form->status ?? '') === 'archived' ? 'selected' : '' }}>Archived</option>
                    </select>
                </div>
BLADE;
        }

        $formFields = implode("\n\n", array_column($extraFields, 'form'));
        if ($formFields !== '') {
            $formFields = "\n\n" . $formFields;
        }
        $includeApi = (bool) ($options['api'] ?? true);
        $description = isset($options['description']) && is_string($options['description']) && trim($options['description']) !== ''
            ? mb_substr(trim($options['description']), 0, 1000)
            : "Vertical slice module for {$pluralName}";
        $author = isset($options['author']) && is_string($options['author']) && trim($options['author']) !== ''
            ? mb_substr(trim($options['author']), 0, 120)
            : null;

        if (file_exists($targetDir)) {
            throw new SliceExistsException("Slice '{$pluralName}' already exists at {$targetDir}; generation stopped to protect its files.");
        }

        if (! is_dir($this->slicesPath) && ! mkdir($this->slicesPath, 0755, true) && ! is_dir($this->slicesPath)) {
            throw new \RuntimeException("Unable to create slices directory: {$this->slicesPath}");
        }

        $stagingDir = $this->slicesPath . DIRECTORY_SEPARATOR . '.laraslice-' . bin2hex(random_bytes(8));
        $sliceDir = $stagingDir;

        try {
        if (! mkdir($sliceDir, 0755, true) && ! is_dir($sliceDir)) {
            throw new \RuntimeException("Unable to create temporary slice directory: {$sliceDir}");
        }

        foreach (['Contracts', 'Controllers', 'Migrations', 'Models', 'Resources/views', 'Routes', 'Schemas', 'Services'] as $sub) {
            $path = $sliceDir . '/' . $sub;
            if (!is_dir($path)) {
                if (! mkdir($path, 0755, true) && ! is_dir($path)) {
                    throw new \RuntimeException("Unable to create slice directory: {$path}");
                }
            }
        }

        // 1. slice.json Manifest
        $manifestData = [
            'name'        => $pluralName,
            'title'       => Str::title(Str::snake($pluralName, ' ')),
            'version'     => '1.0.0',
            'description' => $description,
            'author'      => $author,
            'active'      => true,
            'workflow'    => $includeWorkflow,
            'fields'      => $manifestFields,
            'permissions' => !empty($options['permissions']) && is_array($options['permissions'])
                ? $options['permissions']
                : [
                    "{$snakeName}.view",
                    "{$snakeName}.create",
                    "{$snakeName}.edit",
                    "{$snakeName}.delete",
                ],
            'namespace'   => $sliceNamespace,
            'navigation'  => [
                'label' => Str::title(Str::snake($pluralName, ' ')),
                'url'   => $navUrl,
                'icon'  => 'package',
                'order' => 50,
            ],
        ];

        if ($domain !== null) {
            $manifestData['domain'] = $domain;
            $manifestData['navigation']['group'] = $domain;
        }

        $this->writeFile($sliceDir . '/slice.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        // 1b. Canonical slice.yaml Blueprint
        $yamlData = [
            'schema_version' => 1,
            'name'           => Str::title(Str::snake($pluralName, ' ')),
            'handle'         => Str::snake(Str::singular($pluralName)),
            'description'    => $description,
        ];

        if ($domain !== null) {
            $yamlData['domain'] = $domain;
            $yamlData['navigation'] = [
                'group' => $domain,
                'label' => Str::title(Str::snake($pluralName, ' ')),
                'url'   => $navUrl,
            ];
        }

        $yamlData['models'] = [
            [
                'handle' => Str::snake(Str::singular($pluralName)),
                'table'  => $tableName,
                'root'   => true,
                'fields' => array_map(static fn (array $f): array => [
                    'handle'   => $f['name'],
                    'label'    => $f['label'] ?? Str::headline($f['name']),
                    'type'     => $f['type'],
                    'required' => empty($f['nullable']),
                ], $manifestFields),
            ],
        ];
        $this->writeFile($sliceDir . '/slice.yaml', \Symfony\Component\Yaml\Yaml::dump($yamlData, 10, 2));

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
        foreach ($manifestFields as $def) {
            if (($def['type'] ?? '') === 'foreign_id' || str_ends_with($def['name'] ?? '', '_id')) {
                $relName = Str::camel(Str::replaceLast('_id', '', $def['name']));
                $targetClass = Str::studly(Str::replaceLast('_id', '', $def['name']));
                $modelRelations .= <<<REL


    public function {$relName}(): BelongsTo
    {
        \$candidateClasses = [
            "\\\\App\\\\Slices\\\\ECommerce\\\\ShopCategories\\\\Models\\\\{$targetClass}",
            "\\\\App\\\\Slices\\\\ECommerce\\\\ShopProducts\\\\Models\\\\{$targetClass}",
            "\\\\App\\\\Slices\\\\{$targetClass}s\\\\Models\\\\{$targetClass}",
            "\\\\App\\\\Models\\\\{$targetClass}",
        ];
        foreach (\$candidateClasses as \$cls) {
            if (class_exists(\$cls)) {
                return \$this->belongsTo(\$cls, '{$def['name']}');
            }
        }
        return \$this->belongsTo(Model::class, '{$def['name']}');
    }
REL;
            }
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
        $modelContent .= <<<PHP

    protected \$table = '{$tableName}';
    protected \$fillable = {$fillable};{$modelCasts}
{$modelRelations}
}
PHP;
        $this->writeFile($sliceDir . "/Models/{$studlyName}.php", $modelContent);

        // 3. Contracts (DTOs)
        $formDto = <<<PHP
<?php

namespace {$sliceNamespace}\\Contracts;

use LaraSlice\\Core\\Base\\BaseFormBusinessObject;

class {$studlyName}FormBusinessObject extends BaseFormBusinessObject
{
    {$dtoTitle}
    {$dtoDesc}
    {$dtoStatus}{$dtoProperties}
}
PHP;
        $this->writeFile($sliceDir . "/Contracts/{$studlyName}FormBusinessObject.php", $formDto);

        $listingDto = <<<PHP
<?php

namespace {$sliceNamespace}\\Contracts;

use LaraSlice\\Core\\Base\\BaseListingBusinessObject;

class {$studlyName}ListingBusinessObject extends BaseListingBusinessObject
{
    {$dtoTitle}
    {$dtoStatus}{$dtoProperties}
}
PHP;
        $this->writeFile($sliceDir . "/Contracts/{$studlyName}ListingBusinessObject.php", $listingDto);

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
        $this->writeFile($sliceDir . "/Contracts/{$studlyName}FilterBusinessObject.php", $filterDto);

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
        \$query->where('title', 'LIKE', "%\$search%");
    }

    protected function validate(IBusinessObject \$form): void
    {
        Validator::make(\$form->toArray(), [
            'title' => {$titleRulesExport},
            'description' => {$descRulesExport},
            'status' => {$statusRulesExport},{$validationRules}
        ])->validate();
    }
}
PHP;
        $this->writeFile($sliceDir . "/Services/{$studlyName}SliceService.php", $service);

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
            $this->writeFile($sliceDir . "/Controllers/{$studlyName}ApiController.php", $apiController);
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
        $this->writeFile($sliceDir . "/Controllers/{$studlyName}WebController.php", $webController);

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
    Route::get('/{id}/edit', [{$studlyName}WebController::class, 'edit'])->name('edit');
    Route::put('/{id}', [{$studlyName}WebController::class, 'update'])->name('update');
    Route::delete('/{id}', [{$studlyName}WebController::class, 'destroy'])->name('destroy');
});
{$domainNamedRoutes}
{$aliasRoutes}

// URL slug alias redirects
{$aliasRedirectCode}
PHP;
        $this->writeFile($sliceDir . "/Routes/web.php", $webRoutes);

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
            $this->writeFile($sliceDir . "/Routes/api.php", $apiRoutes);
        }

        // 8. Migration
        $timestamp = date('Y_m_d_His');
        $migration = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
                \$table->id();
                {$migrationTitle}
                {$migrationDesc}
                {$migrationStatus}{$migrationFields}
                \$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                \$table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                \$table->timestamps();{$migrationSoftDeletes}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;
        $this->writeFile($sliceDir . "/Migrations/{$timestamp}_create_{$tableName}_table.php", $migration);

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
            {$schemaTitle},
            {$schemaDesc},
            {$schemaStatus},{$schemaFields}
        ];
    }

    public static function table(): array
    {
        return [
            Column::make('id')->label('ID')->sortable(),
            Column::make('title')->label({$schemaTitleLabel})->sortable()->searchable(),
            Column::make('status')->label({$schemaStatusLabel})->badge(),
            Column::make('created_at')->label('Created')->datetime(),{$schemaTableColumns}
        ];
    }
}
PHP;
        $this->writeFile($sliceDir . "/Schemas/{$studlyName}Schema.php", $schemaContent);

        // 10. Pure BlatUI Views with Universal Layout Integration
        $bladeIndex = <<<BLADE
@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <!-- Header with Breadcrumbs & Action -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2 border-b border-border/40">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:text-primary transition-colors">Dashboard</a>
                <span>/</span>
                <span class="text-foreground font-medium">{$pluralName}</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-3">
                {$pluralName}
                <x-ui.badge variant="secondary" class="font-mono text-xs">v1.0.0</x-ui.badge>
            </h1>
            <p class="text-sm text-muted-foreground mt-0.5">Manage and organize {$pluralName} records with LaraSlice & BlatUI</p>
        </div>
        <div class="flex items-center gap-3">
            @if(Route::has('laraslice.wizard.seed_slice'))
                <form action="{{ route('laraslice.wizard.seed_slice') }}" method="POST" class="inline">
                    @csrf
                    <input type="hidden" name="slice" value="{$studlyName}">
                    <input type="hidden" name="count" value="10">
                    <x-ui.button type="submit" variant="outline" class="gap-1.5 shadow-2xs text-amber-500 border-amber-500/30 hover:bg-amber-500/10">
                        <x-lucide-sparkles class="size-3.5" />
                        <span>Seed Demo Data</span>
                    </x-ui.button>
                </form>
            @endif
            <x-ui.button href="{{ route('{$pluralSnake}.create') }}" as="a" class="gap-1.5 shadow-sm">
                <x-lucide-plus class="size-4" />
                <span>Create {$studlyName}</span>
            </x-ui.button>
        </div>
    </div>

    @if (session('success'))
        <div class="p-4 rounded-xl border border-success/30 bg-success/10 text-success text-sm font-medium flex items-center gap-2 shadow-xs">
            <x-lucide-check-circle class="size-4" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @php
        \$columns = [
{$dataTableColumns}
            // @laraslice:columns
        ];
        \$rows = \LaraSlice\Support\DataTableRows::from(\$pagedList->items, \$columns, fn (\$item) => [
            'edit_url'   => route('{$pluralSnake}.edit', \$item->id),
            'delete_url' => route('{$pluralSnake}.destroy', \$item->id),
        ]);
    @endphp

    <!-- Card Container with BlatUI Data Table -->
    <x-ui.card variant="sectioned" class="border shadow-xs bg-card">
        <x-ui.card-header class="border-b pb-4 px-6 pt-6">
            <div class="flex items-center justify-between">
                <div>
                    <x-ui.card-title class="text-base font-semibold">{$pluralName} Catalog</x-ui.card-title>
                    <x-ui.card-description>All synchronized vertical slice records</x-ui.card-description>
                </div>
                <div class="text-xs text-muted-foreground font-medium">
                    Total records: {{ \$pagedList->totalCount }}
                </div>
            </div>
        </x-ui.card-header>

        <x-ui.card-content class="p-6">
            @if (\$pagedList->totalCount > count(\$rows))
                <p class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                    Showing the latest {{ count(\$rows) }} of {{ \$pagedList->totalCount }} records. Raise <code>LARASLICE_DATA_TABLE_MAX_ROWS</code> to load more.
                </p>
            @endif

            @if (count(\$rows) === 0)
                <div class="flex flex-col items-center justify-center gap-3 py-10 text-center text-muted-foreground">
                    <x-lucide-inbox class="size-8 text-muted-foreground/50" />
                    <div>
                        <p class="text-sm font-semibold text-foreground">No records found in {$pluralName}</p>
                        <p class="text-xs text-muted-foreground mt-0.5">Start by creating your first entry or generate realistic mock data.</p>
                    </div>
                    <div class="flex items-center gap-2 pt-1">
                        <x-ui.button href="{{ route('{$pluralSnake}.create') }}" as="a" variant="outline" size="sm">
                            Create {$studlyName}
                        </x-ui.button>
                        @if(Route::has('laraslice.wizard.seed_slice'))
                            <form action="{{ route('laraslice.wizard.seed_slice') }}" method="POST" class="inline">
                                @csrf
                                <input type="hidden" name="slice" value="{$studlyName}">
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
                <x-ui.data-table :columns="\$columns" :rows="\$rows" :page-size="10" search-placeholder="Filter {$pluralName}...">
                    <x-slot:actions>
                        <x-ui.button as="a" ::href="item.r.edit_url" variant="ghost" size="sm">
                            <x-lucide-pencil class="size-4" /> Edit
                        </x-ui.button>
                        <form method="POST" :action="item.r.delete_url" class="inline" onsubmit="return confirm('Delete this record?')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm" class="text-destructive hover:text-destructive">
                                <x-lucide-trash-2 class="size-4" /> Delete
                            </x-ui.button>
                        </form>
                    </x-slot:actions>
                </x-ui.data-table>
            @endif
        </x-ui.card-content>
    </x-ui.card>
</div>
@endsection
BLADE;
        $this->writeFile($sliceDir . "/Resources/views/index.blade.php", $bladeIndex);

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

    <x-ui.card variant="sectioned" class="shadow-sm bg-card border border-border">
        <x-ui.card-header class="border-b pb-4 px-6 pt-6">
            <x-ui.card-title class="text-lg font-bold text-foreground">{{ \$isNew ? 'Create New {$studlyName}' : 'Edit {$studlyName}' }}</x-ui.card-title>
            <x-ui.card-description>Configure {$studlyName} attributes using BlatUI & LaraSlice</x-ui.card-description>
        </x-ui.card-header>

        <x-ui.card-content class="p-6">
            <form action="{{ \$isNew ? route('{$pluralSnake}.store') : route('{$pluralSnake}.update', \$form->id) }}" method="POST" class="space-y-5">
                @csrf
                @if(!\$isNew) @method('PUT') @endif

{$formTitle}

{$formDescription}

{$formStatus}{$formFields}

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border/50">
                    <x-ui.button href="{{ route('{$pluralSnake}.index') }}" as="a" variant="outline">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="submit" name="action" value="save_continue" variant="secondary">
                        Save & Continue
                    </x-ui.button>
                    <x-ui.button type="submit" name="action" value="save_close">
                        {{ \$isNew ? 'Create {$studlyName}' : 'Save & Close' }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card-content>
    </x-ui.card>
</div>
@endsection
BLADE;
        $this->writeFile($sliceDir . "/Resources/views/form.blade.php", $bladeForm);
        $this->writeFile($sliceDir . "/Resources/views/create.blade.php", $bladeForm);
        $this->writeFile($sliceDir . "/Resources/views/edit.blade.php", $bladeForm);

        $renamed = false;
        if (! file_exists($targetDir)) {
            $parent = dirname($targetDir);
            if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                throw new \RuntimeException("Unable to create parent directory for slice: {$parent}");
            }
            for ($attempt = 0; $attempt < 5; $attempt++) {
                if (@rename($stagingDir, $targetDir)) {
                    $renamed = true;
                    break;
                }
                usleep(25000);
            }
        }

        if (! $renamed) {
            throw new \RuntimeException("Unable to publish generated slice to {$targetDir}; no existing files were replaced.");
        }

        try {
            if (function_exists('app') && app()->bound(\LaraSlice\Core\Discovery\SliceManager::class)) {
                app(\LaraSlice\Core\Discovery\SliceManager::class)->syncPermissions();
            }
        } catch (\Throwable) {}

        return $targetDir;
        } catch (\Throwable $exception) {
            $this->removeDirectory($stagingDir);

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
                throw new \RuntimeException('Generated ' . basename($path) . " is not valid PHP (line {$e->getLine()}): {$e->getMessage()}", 0, $e);
            }
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write generated file: {$path}");
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }

        rmdir($path);
    }

    /** Normalize user input before the generator creates any files. */
    private function normalizeFields(array $fields): array
    {
        if (count($fields) > 50) {
            throw new SliceFieldDefinitionException('A slice can define at most 50 custom fields per generation.');
        }

        $definitions = [];
        $reserved = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $types = [
            'string' => ['migration' => 'string', 'validation' => 'string', 'input' => 'text'],
            'text' => ['migration' => 'text', 'validation' => 'string', 'input' => 'textarea'],
            'integer' => ['migration' => 'integer', 'validation' => 'integer', 'input' => 'number'],
            'decimal' => ['migration' => 'decimal', 'validation' => 'numeric', 'input' => 'decimal'],
            'boolean' => ['migration' => 'boolean', 'validation' => 'boolean', 'input' => 'boolean'],
            'date' => ['migration' => 'date', 'validation' => 'date', 'input' => 'date'],
            'datetime' => ['migration' => 'dateTime', 'validation' => 'date', 'input' => 'datetime-local'],
            'email' => ['migration' => 'string', 'validation' => 'email', 'input' => 'email'],
            'select' => ['migration' => 'string', 'validation' => 'string', 'input' => 'select'],
            'foreign_id' => ['migration' => 'unsignedBigInteger', 'validation' => 'integer', 'input' => 'select'],
            'url' => ['migration' => 'string', 'validation' => 'url', 'input' => 'url'],
            'json' => ['migration' => 'json', 'validation' => 'json', 'input' => 'textarea'],
            'float' => ['migration' => 'float', 'validation' => 'numeric', 'input' => 'decimal'],
            'bigInteger' => ['migration' => 'bigInteger', 'validation' => 'integer', 'input' => 'number'],
            'timestamp' => ['migration' => 'timestamp', 'validation' => 'date', 'input' => 'datetime-local'],
        ];

        foreach ($fields as $index => $definition) {
            if (! is_array($definition) || ! isset($definition['name']) || ! is_string($definition['name'])) {
                throw new SliceFieldDefinitionException("Custom field at index {$index} must include a string name.");
            }

            $name = $definition['name'];
            $type = $definition['type'] ?? 'string';
            if (! is_string($type)) {
                throw new SliceFieldDefinitionException("Field '{$name}' must use a supported text type name.");
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                throw new SliceFieldDefinitionException("Invalid field name '{$name}'. Use lowercase snake_case identifiers.");
            }
            if (in_array($name, $reserved, true) || isset($definitions[$name])) {
                throw new SliceFieldDefinitionException("Field '{$name}' is reserved or duplicated.");
            }
            if (! isset($types[$type])) {
                throw new SliceFieldDefinitionException("Unsupported type '{$type}' for field '{$name}'.");
            }

            $label = $definition['label'] ?? Str::headline($name);
            if (! is_string($label) || mb_strlen($label) > 120) {
                throw new SliceFieldDefinitionException("Field '{$name}' must have a text label up to 120 characters.");
            }
            if ($problem = BladeSafeText::problem($label, "Field '{$name}' label")) {
                throw new SliceFieldDefinitionException($problem);
            }

            $options = [];
            if ($type === 'select' && ! empty($definition['options'])) {
                $options = $definition['options'] ?? [];
                if (! is_array($options) || count($options) > 50) {
                    throw new SliceFieldDefinitionException("Select field '{$name}' requires between 1 and 50 options.");
                }
                foreach ($options as $value => $optionLabel) {
                    if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $value) || ! is_string($optionLabel) || ! BladeSafeText::isSafe($optionLabel)) {
                        throw new SliceFieldDefinitionException("Select field '{$name}' has an invalid option.");
                    }
                }
            }

            $requiredInput = $definition['required'] ?? null;
            if ($requiredInput !== null && ! is_bool($requiredInput) && ! in_array($requiredInput, [0, 1, '0', '1'], true)) {
                throw new SliceFieldDefinitionException("Field '{$name}' required must be true or false.");
            }

            $nullableInput = $definition['nullable'] ?? ! (bool) $requiredInput;
            if (! is_bool($nullableInput) && ! in_array($nullableInput, [0, 1, '0', '1'], true)) {
                throw new SliceFieldDefinitionException("Field '{$name}' nullable must be true or false.");
            }
            if ($requiredInput !== null && (bool) $requiredInput === (bool) $nullableInput) {
                throw new SliceFieldDefinitionException("Field '{$name}' required and nullable settings conflict.");
            }
            $nullable = (bool) $nullableInput;
            $required = ! $nullable;
            $default = $definition['default'] ?? ($type === 'boolean' ? false : null);
            if (! is_null($default) && ! is_scalar($default)) {
                throw new SliceFieldDefinitionException("Field '{$name}' default must be a scalar value or null.");
            }
            if (is_string($default) && ($problem = BladeSafeText::problem($default, "Field '{$name}' default"))) {
                throw new SliceFieldDefinitionException($problem);
            }
            if ($default !== null && match ($type) {
                'string', 'text', 'email', 'select', 'date', 'datetime', 'url', 'json', 'timestamp' => ! is_string($default),
                'integer', 'foreign_id', 'bigInteger' => ! is_int($default),
                'decimal', 'float' => ! is_numeric($default),
                'boolean' => ! is_bool($default),
                default => true,
            }) {
                throw new SliceFieldDefinitionException("Field '{$name}' has a default value that does not match its {$type} type.");
            }
            if ($type === 'select' && $default !== null && ! empty($options) && ! array_key_exists($default, $options)) {
                throw new SliceFieldDefinitionException("Field '{$name}' default must match one of its select option values.");
            }

            $encrypted = (bool) ($definition['encrypted'] ?? false);
            if ($encrypted && ! in_array($type, ['string', 'text', 'email', 'url', 'json'], true)) {
                throw new SliceFieldDefinitionException("Field '{$name}' cannot be encrypted; only text-like fields support encryption.");
            }

            $length = $definition['length'] ?? null;
            $maxLength = 255;
            $column = $types[$type]['migration'];
            if ($encrypted) {
                // Ciphertext is much longer than the value, so encrypted columns are always text
                $columnExpression = "\$table->text('{$name}')";
            } elseif (in_array($type, ['decimal', 'float'], true)) {
                [$precision, $scale] = [12, 2];
                if ($length !== null) {
                    if (! is_string($length) || ! preg_match('/^(\d{1,2})\s*,\s*(\d{1,2})$/', $length, $m) || (int) $m[2] > (int) $m[1]) {
                        throw new SliceFieldDefinitionException("Field '{$name}' length must be \"precision,scale\", e.g. \"12,2\".");
                    }
                    [$precision, $scale] = [(int) $m[1], (int) $m[2]];
                }
                $columnExpression = "\$table->decimal('{$name}', {$precision}, {$scale})";
            } elseif (in_array($column, ['string'], true) && $length !== null) {
                if (! is_int($length) || $length < 1 || $length > 65535) {
                    throw new SliceFieldDefinitionException("Field '{$name}' length must be between 1 and 65535.");
                }
                $maxLength = $length;
                $columnExpression = "\$table->string('{$name}', {$length})";
            } elseif ($length !== null) {
                throw new SliceFieldDefinitionException("Field '{$name}' does not support a length.");
            } else {
                $columnExpression = "\$table->{$column}('{$name}')";
            }
            if ($nullable) {
                $columnExpression .= '->nullable()';
            }
            if ($default !== null) {
                $columnExpression .= '->default(' . var_export($default, true) . ')';
            }

            $rules = array_values(array_filter([
                $nullable ? 'nullable' : 'required',
                $types[$type]['validation'],
                in_array($type, ['string', 'email', 'select', 'url'], true) ? 'max:' . $maxLength : null,
                $type === 'select' && ! empty($options) ? 'in:' . implode(',', array_keys($options)) : null,
            ]));

            $schema = "Field::make('{$name}', '{$types[$type]['input']}')->label(" . var_export($label, true) . ')';
            if ($required) {
                $schema .= '->required()';
            }
            if ($default !== null) {
                $schema .= '->default(' . var_export($default, true) . ')';
            }
            if ($options) {
                $schema .= '->options(' . var_export($options, true) . ')';
            }

            $definitions[$name] = [
                'name' => $name,
                'label' => $label,
                'label_html' => htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'rules' => $rules,
                'type' => $type,
                'schema' => $schema,
                'form' => $this->renderFormField($name, $label, $types[$type]['input'], $required, $default, $options),
                'migration' => $columnExpression . ';',
                'default' => $default,
                'nullable' => $nullable,
                'options' => $options,
                'encrypted' => $encrypted,
            ];
        }

        return array_values($definitions);
    }

    /**
     * A typed DTO property declaration whose default always matches its type.
     *
     * Built-in columns without a custom definition use $fallbackType / $fallbackDefault.
     */
    private function dtoProperty(string $name, ?array $field, string $fallbackType = '?string', mixed $fallbackDefault = null): string
    {
        if ($field === null) {
            return "public {$fallbackType} \${$name} = " . var_export($fallbackDefault, true) . ';';
        }

        $default = $field['default'] ?? null;
        [$type, $default] = match ($field['type']) {
            'boolean' => ['bool', (bool) $default],
            'integer', 'foreign_id', 'bigInteger' => ['?int', $default === null ? null : (int) $default],
            'decimal', 'float' => ['?float', $default === null ? null : (float) $default],
            default => [! $field['nullable'] && $default !== null ? 'string' : '?string', $default === null ? null : (string) $default],
        };

        if ($type === 'string' && $default === null) {
            $default = '';
        }

        return "public {$type} \${$name} = " . var_export($default, true) . ';';
    }

    private function renderFormField(string $name, string $label, string $type, bool $required, mixed $default, array $options): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $requiredHtml = $required ? ' required' : '';
        $requiredMark = $required ? ' *' : '';
        $defaultExpression = var_export($default, true);
        $valueExpression = "old('{$name}', \$form->{$name} ?? {$defaultExpression})";

        if ($type === 'select' || $type === 'foreign_id' || str_ends_with($name, '_id')) {
            $baseRel = Str::replaceLast('_id', '', $name);
            $optionsVar = Str::camel(Str::plural($baseRel)) . 'Options';
            $quickStoreUrlVar = Str::camel(Str::plural($baseRel)) . 'QuickStoreUrl';
            $requiredBool = $required ? 'true' : 'false';
            $cleanTitle = str_ends_with($name, '_id') ? Str::headline($baseRel) : $safeLabel;

            if (empty($options) || str_ends_with($name, '_id') || $type === 'foreign_id') {
                return <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.combobox-relationship
                        name="{$name}"
                        label="{$cleanTitle}"
                        :options="\${$optionsVar} ?? []"
                        :selected="{$valueExpression}"
                        placeholder="Select {$cleanTitle}..."
                        :quickAddUrl="\${$quickStoreUrlVar} ?? null"
                        quickAddTitle="{$cleanTitle}"
                        :required="{$requiredBool}"
                    />
                    @error('{$name}') <p class="text-xs text-destructive font-medium">{{ \$message }}</p> @enderror
                </div>
BLADE;
            }

            $optionHtml = '<option value="">Select ' . $safeLabel . '...</option>';
            foreach ($options as $value => $optionLabel) {
                $displayLabel = ($optionLabel === $value) ? Str::headline($optionLabel) : $optionLabel;
                $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safeOptionLabel = htmlspecialchars($displayLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $optionHtml .= "\n                    <option value=\"{$safeValue}\" @selected({$valueExpression} === '{$safeValue}')>{$safeOptionLabel}</option>";
            }

            return <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="{$name}">{$safeLabel}{$requiredMark}</x-ui.label>
                    <select id="{$name}" name="{$name}" class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"{$requiredHtml}>
{$optionHtml}
                    </select>
                    @error('{$name}') <p class="text-xs text-destructive font-medium">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        if ($type === 'textarea') {
            return <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="{$name}">{$safeLabel}{$requiredMark}</x-ui.label>
                    <x-ui.textarea id="{$name}" name="{$name}" rows="3"{$requiredHtml}>{{ old('{$name}', \$form->{$name} ?? '') }}</x-ui.textarea>
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        if ($type === 'boolean') {
            return <<<BLADE
                <div class="flex items-center justify-between p-3 rounded-xl border bg-card/60">
                    <x-ui.label for="{$name}">{$safeLabel}</x-ui.label>
                    <input type="hidden" name="{$name}" value="0">
                    <input type="checkbox" id="{$name}" name="{$name}" value="1" @checked(old('{$name}', \$form->{$name} ?? false)) class="h-4 w-4 rounded border-gray-300 text-primary" />
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        $inputType = $type === 'decimal' ? 'number' : $type;
        $step = $type === 'decimal' ? ' step="0.01"' : '';

        return <<<BLADE
                <div class="space-y-1.5">
                    <x-ui.label for="{$name}">{$safeLabel}{$requiredMark}</x-ui.label>
                    <x-ui.input id="{$name}" type="{$inputType}" name="{$name}" value="{{ {$valueExpression} }}"{$step}{$requiredHtml} />
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
    }
}
