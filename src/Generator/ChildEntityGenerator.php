<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;

class ChildEntityGenerator
{
    public function generate(string $sliceDir, string $sliceNamespace, string $pluralSlice, string $childTable, string $parentTable, string $foreignKey, array $fields = []): void
    {
        $childStudly = Str::studly(Str::singular($childTable));
        $childPlural = Str::plural($childStudly);
        $childSingularLabel = Str::headline(Str::singular($childTable));
        $childPluralLabel = Str::plural($childSingularLabel);
        $childSnake = Str::snake($childStudly);
        $childPluralSnake = Str::snake($childPlural);

        $parentStudly = Str::studly(Str::singular($parentTable));
        $parentSnake = Str::snake($parentStudly);
        $parentRouteName = Str::snake($parentTable);
        $parentModelClass = "{$sliceNamespace}\\{$pluralSlice}\\Models\\{$parentStudly}";

        $manifestFile = "{$sliceDir}/slice.json";
        $manifest = file_exists($manifestFile) ? ManifestRepository::read($manifestFile) : [];
        $domain = $manifest['domain'] ?? $manifest['navigation']['group'] ?? null;
        $domainSlug = $domain ? Str::slug($domain) : null;

        $webRoutesFile = "{$sliceDir}/Routes/web.php";
        if (! $domainSlug && file_exists($webRoutesFile)) {
            $webContent = file_get_contents($webRoutesFile);
            if (preg_match("/Route::prefix\(['\"]([^'\"]+)\/{$parentRouteName}['\"]\)/", $webContent, $m)) {
                $domainSlug = trim($m[1], '/');
            }
        }

        $parentUrlPrefix = $domainSlug ? "{$domainSlug}/{$parentRouteName}" : $parentRouteName;

        $namespace = "{$sliceNamespace}\\{$pluralSlice}";

        // 1. Generate DTOs
        $formProps = [];
        $listingProps = [];
        foreach ($fields as $f) {
            $fName = $f['name'];
            $fType = strtolower($f['type']);
            $phpType = match ($fType) {
                'integer', 'biginteger', 'unsignedbiginteger', 'smallinteger', 'tinyinteger', 'foreign_id' => '?int',
                'boolean' => '?bool',
                'decimal', 'float', 'double' => '?float',
                default => '?string',
            };
            // Cast the default to the property type, e.g. a decimal default "1.5" must be 1.5
            $default = var_export($f['default'] === null ? null : match ($phpType) {
                '?int' => (int) $f['default'],
                '?bool' => (bool) $f['default'],
                '?float' => (float) $f['default'],
                default => (string) $f['default'],
            }, true);
            $formProps[] = "    public {$phpType} \${$fName} = {$default};";
            $listingProps[] = "    public {$phpType} \${$fName} = {$default};";
        }
        $formProps[] = "    public ?int \${$foreignKey} = null;";

        $formPropsStr = implode("\n", $formProps);
        $listingPropsStr = implode("\n", $listingProps);
        $sortableColumns = implode(', ', array_map(
            fn (string $column) => var_export($column, true),
            array_merge(['id', 'created_at', 'updated_at'], array_column($fields, 'name'))
        ));
        $rules = [];
        foreach ($fields as $field) {
            $inputType = $field['input_type'] ?? strtolower($field['type']);
            $rules[$field['name']] = array_values(array_filter([
                $field['required'] ? 'required' : 'nullable',
                match ($inputType) {
                    'string', 'text', 'mediumtext', 'longtext', 'uuid' => 'string',
                    'integer', 'biginteger', 'smallinteger', 'tinyinteger' => 'integer',
                    'boolean' => 'boolean',
                    'decimal', 'float', 'double' => 'numeric',
                    'date', 'datetime', 'timestamp', 'time' => 'date',
                    'email' => 'email',
                    'url' => 'url',
                    'enum' => 'string',
                    'json' => null,
                    default => null,
                },
                in_array($inputType, ['string', 'uuid', 'enum'], true) ? 'max:255' : null,
                $inputType === 'enum' ? 'in:'.implode(',', array_keys($field['options'] ?? [])) : null,
            ]));
        }
        $rules[$foreignKey] = ['required', 'integer', "exists:{$parentTable},id"];
        $validationRules = implode("\n", array_map(
            fn (string $field, array $fieldRules) => '            '.var_export($field, true).' => '.var_export($fieldRules, true).',',
            array_keys($rules),
            array_values($rules)
        ));

        $formDto = <<<PHP
<?php

namespace {$namespace}\Contracts;

use LaraSlice\Core\Base\BaseFormBusinessObject;

class {$childStudly}FormBusinessObject extends BaseFormBusinessObject
{
{$formPropsStr}
}
PHP;
        file_put_contents("{$sliceDir}/Contracts/{$childStudly}FormBusinessObject.php", $formDto);

        $listingDto = <<<PHP
<?php

namespace {$namespace}\Contracts;

use LaraSlice\Core\Base\BaseListingBusinessObject;

class {$childStudly}ListingBusinessObject extends BaseListingBusinessObject
{
{$listingPropsStr}
}
PHP;
        file_put_contents("{$sliceDir}/Contracts/{$childStudly}ListingBusinessObject.php", $listingDto);

        $filterDto = <<<PHP
<?php

namespace {$namespace}\Contracts;

use LaraSlice\Core\Base\BaseFilter;

class {$childStudly}FilterBusinessObject extends BaseFilter
{
    protected function sortableColumns(): array
    {
        return [{$sortableColumns}];
    }
}
PHP;
        file_put_contents("{$sliceDir}/Contracts/{$childStudly}FilterBusinessObject.php", $filterDto);

        // 2. Generate Service
        $service = <<<PHP
<?php

namespace {$namespace}\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use {$namespace}\Models\\{$childStudly};
use {$parentModelClass};
use {$namespace}\Contracts\\{$childStudly}FormBusinessObject;
use {$namespace}\Contracts\\{$childStudly}ListingBusinessObject;

class {$childStudly}SliceService extends BaseSliceService
{
    private string|int|null \$parentId = null;

    public function __construct(Request \$request)
    {
        \$parentId = \$request->route('parentId') ?? \$request->query('parentId');
        if (\$parentId !== null) {
            \$this->setParentId(\$parentId);
        }
    }

    public function setParentId(string|int \$parentId): void
    {
        \$parent = {$parentStudly}::query()->findOrFail(\$parentId);
        \$this->parentId = \$parent->getKey();
    }

    protected function newQuery(): Builder
    {
        \$query = parent::newQuery();
        if (\$this->parentId !== null) {
            \$query->where('{$foreignKey}', \$this->parentId);
        }

        return \$query;
    }

    protected function prepareModelForSave(IBusinessObject \$form, Model \$model, bool \$isNew): void
    {
        if (\$this->parentId !== null) {
            \$model->setAttribute('{$foreignKey}', \$this->parentId);
        } elseif (isset(\$form->{$foreignKey}) && \$form->{$foreignKey}) {
            \$model->setAttribute('{$foreignKey}', \$form->{$foreignKey});
        }
    }

    protected function getModelClass(): string
    {
        return {$childStudly}::class;
    }

    protected function mapToForm(Model \$model): IBusinessObject
    {
        return {$childStudly}FormBusinessObject::fromArray(\$model->toArray());
    }

    protected function mapToListing(Model \$model): IBusinessObject
    {
        return {$childStudly}ListingBusinessObject::fromArray(\$model->toArray());
    }

    protected function applySearch(Builder \$query, string \$search): void
    {
        \$query->where('id', 'LIKE', "%\$search%");
    }

    protected function validate(IBusinessObject \$form): void
    {
        \$data = \$form->toArray();
        if (\$this->parentId !== null) {
            \$data['{$foreignKey}'] = \$this->parentId;
        }

        Validator::make(\$data, [
{$validationRules}
        ])->validate();
    }
}
PHP;
        file_put_contents("{$sliceDir}/Services/{$childStudly}SliceService.php", $service);

        // 3. Web Controller
        $webController = <<<PHP
<?php

namespace {$namespace}\Controllers;

use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use {$namespace}\Services\\{$childStudly}SliceService;
use {$namespace}\Contracts\\{$childStudly}FormBusinessObject;
use {$namespace}\Contracts\\{$childStudly}FilterBusinessObject;

class {$childStudly}WebController extends BaseSliceWebController
{
    protected {$childStudly}SliceService \$service;

    public function __construct({$childStudly}SliceService \$service)
    {
        \$this->service = \$service;
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        return \$this->service;
    }

    protected function routeParameters(array \$extra = []): array
    {
        return array_filter(array_merge(['parentId' => request()->route('parentId')], \$extra), fn(\$val) => \$val !== null);
    }

    protected function getFormClass(): string
    {
        return {$childStudly}FormBusinessObject::class;
    }

    protected function getFilterClass(): string
    {
        return {$childStudly}FilterBusinessObject::class;
    }

    protected function getViewPrefix(): string
    {
        return '{$parentSnake}::{$childPluralSnake}.';
    }

    protected function getRoutePrefix(): string
    {
        if (request()->route('parentId') === null) {
            return '{$childPluralSnake}.';
        }
        return '{$parentRouteName}.{$childPluralSnake}.';
    }
}
PHP;
        file_put_contents("{$sliceDir}/Controllers/{$childStudly}WebController.php", $webController);

        // 4. API Controller
        $apiController = <<<PHP
<?php

namespace {$namespace}\Controllers;

use LaraSlice\Core\Base\BaseSliceApiController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use {$namespace}\Services\\{$childStudly}SliceService;
use {$namespace}\Contracts\\{$childStudly}FormBusinessObject;
use {$namespace}\Contracts\\{$childStudly}FilterBusinessObject;

class {$childStudly}ApiController extends BaseSliceApiController
{
    protected {$childStudly}SliceService \$service;

    public function __construct({$childStudly}SliceService \$service)
    {
        \$this->service = \$service;
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        return \$this->service;
    }

    protected function getFormClass(): string
    {
        return {$childStudly}FormBusinessObject::class;
    }

    protected function getFilterClass(): string
    {
        return {$childStudly}FilterBusinessObject::class;
    }
}
PHP;
        file_put_contents("{$sliceDir}/Controllers/{$childStudly}ApiController.php", $apiController);

        // 5. Views
        $viewsDir = "{$sliceDir}/Resources/views/{$childPluralSnake}";
        if (! is_dir($viewsDir)) {
            mkdir($viewsDir, 0755, true);
        }

        // Column definitions for the index data-table
        $dataTableColumn = fn (string $key, string $label): string => "            ['key' => ".var_export($key, true).", 'label' => ".var_export($label, true).'],';
        $columnLines = [$dataTableColumn('id', 'ID')];
        if (! empty($fields)) {
            foreach ($fields as $f) {
                $columnLines[] = $dataTableColumn($f['name'], Str::headline($f['name']));
            }
        } else {
            $columnLines[] = $dataTableColumn('name', 'Name');
        }
        $dataTableColumns = implode("\n", $columnLines);

        $editLinkExpr = "\$parentId ? (\\Illuminate\\Support\\Facades\\Route::has('{$childPluralSnake}.edit') ? route('{$childPluralSnake}.edit', ['id' => \$item->id, 'parentId' => \$parentId]) : route('{$parentRouteName}.{$childPluralSnake}.edit', ['parentId' => \$parentId, 'id' => \$item->id])) : (\\Illuminate\\Support\\Facades\\Route::has('{$childPluralSnake}.edit') ? route('{$childPluralSnake}.edit', \$item->id) : route('{$parentRouteName}.{$childPluralSnake}.edit', ['parentId' => \$item->{$foreignKey} ?? 0, 'id' => \$item->id]))";

        $indexBlade = <<<BLADE
@extends('layouts.app')
@section('title', '{$childPluralLabel}')
@section('content')
<div class="space-y-6">
    <div class="text-xs text-muted-foreground">
        @if (\$parentId)
            <a href="{{ \Illuminate\Support\Facades\Route::has('{$parentRouteName}.index') ? route('{$parentRouteName}.index') : (\Illuminate\Support\Facades\Route::has('{$parentSnake}.index') ? route('{$parentSnake}.index') : url('/{$parentRouteName}')) }}" class="hover:underline">{$parentStudly}</a> / {$childPluralLabel}
        @else
            <a href="{{ \Illuminate\Support\Facades\Route::has('dashboard') ? route('dashboard') : url('/') }}" class="hover:underline">Dashboard</a> / {$childPluralLabel}
        @endif
    </div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">{$childPluralLabel}</h1>
            <p class="text-sm text-muted-foreground">{{ \$parentId ? 'Manage {$childPluralLabel} linked with {$parentStudly} #' . \$parentId : 'Manage and organize all {$childPluralLabel}' }}</p>
        </div>
        <x-ui.button href="{{ \$parentId ? route('{$parentRouteName}.{$childPluralSnake}.create', ['parentId' => \$parentId]) : (\Illuminate\Support\Facades\Route::has('{$childPluralSnake}.create') ? route('{$childPluralSnake}.create') : '#') }}" as="a" class="bg-primary text-primary-foreground font-semibold shadow-sm">
            <x-lucide-plus class="mr-2 h-4 w-4" /> Create {$childSingularLabel}
        </x-ui.button>
    </div>
    @php
        \$columns = [
{$dataTableColumns}
            // @laraslice:columns
        ];
        \$rows = \LaraSlice\Support\DataTableRows::from(\$pagedList->items, \$columns, fn (\$item) => [
            'edit_url' => {$editLinkExpr},
        ]);
    @endphp
    <x-ui.card class="p-6">
        @if (count(\$rows) === 0)
            <div class="flex flex-col items-center justify-center gap-2 py-10 text-center text-muted-foreground">
                <x-lucide-inbox class="size-8 text-muted-foreground/40" />
                <p class="text-sm font-medium">No {$childPluralLabel} found</p>
                <x-ui.button href="{{ \$parentId ? route('{$parentRouteName}.{$childPluralSnake}.create', ['parentId' => \$parentId]) : (\Illuminate\Support\Facades\Route::has('{$childPluralSnake}.create') ? route('{$childPluralSnake}.create') : '#') }}" as="a" variant="outline" size="sm">
                    Create your first {$childSingularLabel}
                </x-ui.button>
            </div>
        @else
            @if (\$pagedList->totalCount > count(\$rows))
                <p class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                    Showing the latest {{ count(\$rows) }} of {{ \$pagedList->totalCount }} records. Raise <code>LARASLICE_DATA_TABLE_MAX_ROWS</code> to load more.
                </p>
            @endif
            <x-ui.data-table :columns="\$columns" :rows="\$rows" :page-size="10" search-placeholder="Filter {$childPluralLabel}...">
                <x-slot:actions>
                    <x-ui.button as="a" ::href="item.r.edit_url" variant="ghost" size="sm">
                        <x-lucide-pencil class="size-4" /> Edit
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.data-table>
        @endif
    </x-ui.card>
</div>
@endsection
BLADE;
        file_put_contents("{$viewsDir}/index.blade.php", $indexBlade);

        // Build Dynamic Form Inputs
        $formInputs = [];
        if (! empty($fields)) {
            foreach ($fields as $f) {
                $fName = $f['name'];
                $fLabel = htmlspecialchars($f['label'] ?? Str::headline($fName), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $fType = strtolower($f['input_type'] ?? $f['type'] ?? 'string');
                $required = $f['required'] ? 'required' : '';
                $reqStar = $f['required'] ? ' *' : '';

                if ($fType === 'enum') {
                    $optionsHtml = '';
                    foreach ($f['options'] ?? [] as $value => $optionLabel) {
                        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $safeLabel = htmlspecialchars($optionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $expression = var_export((string) $value, true);
                        $optionsHtml .= "                    <option value=\"{$safeValue}\" @selected(old('{$fName}', \$form->{$fName} ?? ".var_export($f['default'] ?? '', true).") === {$expression})>{$safeLabel}</option>\n";
                    }
                    $formInputs[] = <<<HTML
                <div class="space-y-1.5">
                    <x-ui.label for="{$fName}">{$fLabel}{$reqStar}</x-ui.label>
                    <select id="{$fName}" name="{$fName}" {$required} class="flex h-9 w-full rounded-xl border border-border bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/20">
{$optionsHtml}                    </select>
                    @error('{$fName}')
                        <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                    @enderror
                </div>
HTML;
                } elseif (in_array($fType, ['text', 'mediumtext', 'longtext'], true)) {
                    $formInputs[] = <<<HTML
                <div class="space-y-1.5">
                    <x-ui.label for="{$fName}">{$fLabel}{$reqStar}</x-ui.label>
                    <x-ui.textarea id="{$fName}" name="{$fName}" rows="3" placeholder="Enter {$fLabel}..." {$required}>{{ old('{$fName}', \$form->{$fName} ?? '') }}</x-ui.textarea>
                    @error('{$fName}')
                        <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                    @enderror
                </div>
HTML;
                } elseif ($fType === 'boolean' || $fType === 'bool') {
                    $formInputs[] = <<<HTML
                <div class="flex items-center justify-between p-3.5 rounded-xl border border-border bg-card shadow-xs">
                    <div>
                        <x-ui.label for="{$fName}" class="font-medium">{$fLabel}</x-ui.label>
                        <p class="text-xs text-muted-foreground">Toggle status for {$fLabel}</p>
                    </div>
                    <input type="hidden" name="{$fName}" value="0">
                    <input type="checkbox" id="{$fName}" name="{$fName}" value="1" {{ old('{$fName}', \$form->{$fName} ?? false) ? 'checked' : '' }} class="h-4 w-4 rounded border-input text-primary focus:ring-primary">
                </div>
HTML;
                } elseif ($fType === 'foreign_id' || str_ends_with($fName, '_id')) {
                    $relModel = Str::camel(Str::plural(preg_replace('/_id$/', '', $fName)));
                    $optVar = '$'.$relModel.'Options';
                    $storeVar = '$'.$relModel.'QuickStoreUrl';
                    $reqBool = $required ? 'true' : 'false';
                    $formInputs[] = <<<HTML
                <div class="space-y-1.5">
                    <x-ui.combobox-relationship
                        name="{$fName}"
                        label="{$fLabel}"
                        :options="{$optVar} ?? []"
                        :selected="old('{$fName}', \$form->{$fName} ?? '')"
                        placeholder="Select {$fLabel}..."
                        :quickAddUrl="{$storeVar} ?? null"
                        quickAddTitle="{$fLabel}"
                        :required="{$reqBool}"
                    />
                    @error('{$fName}')
                        <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                    @enderror
                </div>
HTML;
                } else {
                    $htmlInputType = match ($fType) {
                        'integer', 'biginteger', 'smallinteger', 'tinyinteger' => 'number',
                        'decimal', 'float', 'double' => 'number',
                        'date' => 'date',
                        'datetime', 'timestamp' => 'datetime-local',
                        'time' => 'time',
                        'email' => 'email',
                        default => 'text',
                    };
                    $stepAttribute = in_array($fType, ['decimal', 'float', 'double'], true) ? ' step="any"' : '';

                    $formInputs[] = <<<HTML
                <div class="space-y-1.5">
                    <x-ui.label for="{$fName}">{$fLabel}{$reqStar}</x-ui.label>
                    <x-ui.input id="{$fName}" type="{$htmlInputType}" name="{$fName}" value="{{ old('{$fName}', \$form->{$fName} ?? '') }}" placeholder="Enter {$fLabel}..."{$stepAttribute} {$required} />
                    @error('{$fName}')
                        <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                    @enderror
                </div>
HTML;
                }
            }
        } else {
            $formInputs[] = <<<'HTML'
                <div class="space-y-1.5">
                    <x-ui.label for="name">Name *</x-ui.label>
                    <x-ui.input id="name" name="name" value="{{ old('name', $form->name ?? '') }}" required autofocus />
                </div>
HTML;
        }

        $formInputsStr = implode("\n\n", $formInputs);

        $optParentVar = Str::camel(Str::plural($parentTable)).'Options';

        $formBlade = <<<BLADE
@extends('layouts.app')
@section('title', \$isNew ? 'Create {$childSingularLabel}' : 'Edit {$childSingularLabel}')
@section('content')
<div class="w-full max-w-5xl mx-auto space-y-6">
    <div class="flex items-center gap-3 text-sm text-muted-foreground">
        @if(\$parentId)
            <a href="{{ route('{$parentRouteName}.{$childPluralSnake}.index', ['parentId' => \$parentId]) }}" class="hover:text-foreground transition-colors">{$childPluralLabel}</a>
        @else
            <a href="{{ \Illuminate\Support\Facades\Route::has('{$childPluralSnake}.index') ? route('{$childPluralSnake}.index') : url('/{$childPluralSnake}') }}" class="hover:text-foreground transition-colors">{$childPluralLabel}</a>
        @endif
        <span>/</span>
        <span class="text-foreground font-medium">{{ \$isNew ? 'Create {$childSingularLabel}' : 'Edit {$childSingularLabel} #' . \$form->id }}</span>
    </div>
    <x-ui.card>
        <x-ui.card-header class="border-b pb-4 px-6 pt-6">
            <x-ui.card-title class="text-lg font-bold text-foreground">{{ \$isNew ? 'Create New {$childSingularLabel}' : 'Edit {$childSingularLabel}' }}</x-ui.card-title>
            <x-ui.card-description>Configure {$childSingularLabel} details and link with {$parentStudly}</x-ui.card-description>
        </x-ui.card-header>
        <x-ui.card-content class="p-6">
            <form action="{{ \$isNew ? (\$parentId ? route('{$parentRouteName}.{$childPluralSnake}.store', ['parentId' => \$parentId]) : (\Illuminate\Support\Facades\Route::has('{$childPluralSnake}.store') ? route('{$childPluralSnake}.store') : route('{$parentRouteName}.{$childPluralSnake}.store', ['parentId' => old('{$foreignKey}', 0)]))) : (\$parentId ? route('{$parentRouteName}.{$childPluralSnake}.update', ['parentId' => \$parentId, 'id' => \$form->id]) : (\Illuminate\Support\Facades\Route::has('{$childPluralSnake}.update') ? route('{$childPluralSnake}.update', \$form->id) : route('{$parentRouteName}.{$childPluralSnake}.update', ['parentId' => \$form->{$foreignKey} ?? 0, 'id' => \$form->id]))) }}" method="POST" class="space-y-6">
                @csrf
                @if(!\$isNew) @method('PUT') @endif
                
                @if(\$parentId)
                    <input type="hidden" name="{$foreignKey}" value="{{ \$parentId }}">
                    <div class="p-3.5 rounded-xl border border-primary/20 bg-primary/5 text-xs text-foreground flex items-center gap-2.5">
                        <x-lucide-link class="size-4 text-primary shrink-0" />
                        <span>This {$childSingularLabel} belongs to <strong>{$parentStudly} #{{ \$parentId }}</strong>.</span>
                    </div>
                @else
                    @php \$parentOptions = \${$optParentVar} ?? []; @endphp
                    <div class="space-y-1.5">
                        <x-ui.combobox-relationship
                            name="{$foreignKey}"
                            label="{$parentStudly} *"
                            :options="\$parentOptions"
                            :selected="old('{$foreignKey}', \$form->{$foreignKey} ?? '')"
                            placeholder="Select {$parentStudly}..."
                            :required="true"
                        />
                        @error('{$foreignKey}')
                            <p class="text-xs text-destructive font-medium">{{ \$message }}</p>
                        @enderror
                    </div>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
{$formInputsStr}
                </div>
                
                <div class="flex justify-end gap-3 pt-4 border-t border-border">
                    <x-ui.button href="{{ \$parentId ? route('{$parentRouteName}.{$childPluralSnake}.index', ['parentId' => \$parentId]) : (\Illuminate\Support\Facades\Route::has('{$childPluralSnake}.index') ? route('{$childPluralSnake}.index') : url('/{$childPluralSnake}')) }}" as="a" variant="outline">Cancel</x-ui.button>
                    <x-ui.button type="submit" class="bg-primary text-primary-foreground font-semibold shadow-xs">Save</x-ui.button>
                </div>
            </form>
        </x-ui.card-content>
    </x-ui.card>
</div>
@endsection
BLADE;
        file_put_contents("{$viewsDir}/form.blade.php", $formBlade);
        file_put_contents("{$viewsDir}/create.blade.php", $formBlade);
        file_put_contents("{$viewsDir}/edit.blade.php", $formBlade);

        // 6. Update Web Routes
        $webRoutesFile = "{$sliceDir}/Routes/web.php";
        if (file_exists($webRoutesFile)) {
            $webRoutes = file_get_contents($webRoutesFile);

            // Auto-heal existing legacy routes if missing domain prefix
            if ($domainSlug && str_contains($webRoutes, "Route::prefix('{$parentRouteName}/{parentId}')")) {
                $webRoutes = str_replace(
                    "Route::prefix('{$parentRouteName}/{parentId}')",
                    "Route::prefix('{$parentUrlPrefix}/{parentId}')",
                    $webRoutes
                );
                file_put_contents($webRoutesFile, $webRoutes);
            }

            if (! str_contains($webRoutes, "Route::resource('{$childPluralSnake}'")) {
                $useStatement = "use {$namespace}\Controllers\\{$childStudly}WebController;\n";
                if (! str_contains($webRoutes, $useStatement)) {
                    $webRoutes = preg_replace('/(use Illuminate\\\\Support\\\\Facades\\\\Route;)/', "$1\n".$useStatement, $webRoutes);
                }

                $childUrlPrefix = $domainSlug ? "{$domainSlug}/{$childPluralSnake}" : $childPluralSnake;

                $routeDef = "Route::prefix('{$parentUrlPrefix}/{parentId}')->name('{$parentRouteName}.')->middleware(config('laraslice.generated_routes.web_middleware', ['web', 'auth']))->group(function () {\n    Route::resource('{$childPluralSnake}', {$childStudly}WebController::class)->parameters(['{$childPluralSnake}' => 'id'])->except(['show']);\n});\n";

                // Global top-level routes: /crm/contacts or /contacts
                $routeDef .= "\nRoute::prefix('{$childUrlPrefix}')->name('{$childPluralSnake}.')->middleware(config('laraslice.generated_routes.web_middleware', ['web', 'auth']))->group(function () {\n"
                    ."    Route::get('/', [{$childStudly}WebController::class, 'index'])->name('index');\n"
                    ."    Route::get('/create', [{$childStudly}WebController::class, 'create'])->name('create');\n"
                    ."    Route::post('/', [{$childStudly}WebController::class, 'store'])->name('store');\n"
                    ."    Route::get('/{id}/edit', [{$childStudly}WebController::class, 'edit'])->name('edit');\n"
                    ."    Route::put('/{id}', [{$childStudly}WebController::class, 'update'])->name('update');\n"
                    ."    Route::delete('/{id}', [{$childStudly}WebController::class, 'destroy'])->name('destroy');\n"
                    ."});\n";

                $webRoutes .= "\n".$routeDef;
                file_put_contents($webRoutesFile, $webRoutes);
            }
        }

        // 7. Update Service Provider views namespace
        $providerFile = "{$sliceDir}/Providers/{$pluralSlice}ServiceProvider.php";
        if (file_exists($providerFile)) {
            $provider = file_get_contents($providerFile);
            if (! str_contains($provider, "\$this->loadViewsFrom(__DIR__.'/../Resources/views/{$childPluralSnake}'")) {
                $loadViews = "\$this->loadViewsFrom(__DIR__.'/../Resources/views/{$childPluralSnake}', '{$childPluralSnake}');";
                $provider = preg_replace('/(\$this->loadViewsFrom\([^;]+;)/', "$1\n        ".$loadViews, $provider);
                file_put_contents($providerFile, $provider);
            }
        }
    }
}
