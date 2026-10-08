<?php

namespace LaraSlice\Core\Discovery;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Security\Access;
use LaraSlice\Support\SchemaCache;
use Symfony\Component\Yaml\Yaml;

class SliceManager
{
    protected Application $app;

    /** @var array<string, SliceManifest> */
    protected array $slices = [];

    /** @var array<string, string|null> Model classes already resolved by modelClass(). */
    protected array $resolvedModels = [];

    /** @var array<string, array> navigation per user, built by getNavigableSlices() */
    protected array $navigationCache = [];

    /** @var array<int, string> files changed by the current repairSlice() call */
    protected array $repairedFiles = [];

    protected bool $repairDryRun = false;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function discover(): void
    {
        $this->slices = [];
        $this->navigationCache = [];
        $this->resolvedModels = [];
        if ($this->isCached()) {
            $cached = include $this->getCachedSlicesPath();
            if (is_array($cached)) {
                foreach ($cached as $name => $manifestPath) {
                    if (file_exists($manifestPath)) {
                        $manifest = new SliceManifest($manifestPath);
                        $this->slices[$manifest->name] = $manifest;
                        if ($manifest->active) {
                            $this->bootSlice($manifest);
                        }
                    }
                }

                return;
            }
        }

        $scanPaths = [];

        // 1. Built-in Core Framework Slices
        $coreSlicesPath = dirname(__DIR__, 2).'/Slices';
        if (is_dir($coreSlicesPath)) {
            $scanPaths[] = $coreSlicesPath;
        }

        // 2. Application-level Slices (e.g. app/Slices)
        $appSlicesPath = config('laraslice.slices_path', app_path('Slices'));
        if ($appSlicesPath && is_dir($appSlicesPath) && realpath($appSlicesPath) !== realpath($coreSlicesPath)) {
            $scanPaths[] = $appSlicesPath;
        }

        foreach ($scanPaths as $path) {
            $directories = glob($path.'/*', GLOB_ONLYDIR) ?: [];

            foreach ($directories as $dir) {
                $manifestFile = $this->findManifest($dir);
                if ($manifestFile) {
                    $manifest = new SliceManifest($manifestFile);
                    $this->slices[$manifest->name] = $manifest;

                    if ($manifest->active) {
                        $this->bootSlice($manifest);
                    }
                } else {
                    // Check if $dir is a domain folder containing nested slices (e.g. Slices/Ecommerce/ShopProducts)
                    $subDirs = glob($dir.'/*', GLOB_ONLYDIR) ?: [];
                    foreach ($subDirs as $subDir) {
                        $subManifestFile = $this->findManifest($subDir);
                        if ($subManifestFile) {
                            $manifest = new SliceManifest($subManifestFile);
                            $this->slices[$manifest->name] = $manifest;

                            if ($manifest->active) {
                                $this->bootSlice($manifest);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Find manifest file prioritizing slice.yaml, then slice.yml, then slice.json.
     */
    public function findManifest(string $dir): ?string
    {
        foreach (['slice.yaml', 'slice.yml', 'slice.json'] as $file) {
            $path = $dir.'/'.$file;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get path to cached slices manifest map.
     */
    public function getCachedSlicesPath(): string
    {
        if (function_exists('app') && method_exists($this->app, 'bootstrapPath')) {
            return $this->app->bootstrapPath('cache/laraslice_slices.php');
        }

        return sys_get_temp_dir().'/laraslice_slices.php';
    }

    /**
     * Check if slice discovery is cached.
     */
    public function isCached(): bool
    {
        return file_exists($this->getCachedSlicesPath());
    }

    /**
     * Cache discovered slices for high-performance production execution.
     */
    public function cacheSlices(): int
    {
        $map = [];
        foreach ($this->slices as $name => $manifest) {
            $manifestFile = $this->findManifest($manifest->path);
            if ($manifestFile) {
                $map[$name] = $manifestFile;
            }
        }

        $cachePath = $this->getCachedSlicesPath();
        $cacheDir = dirname($cachePath);
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $export = var_export($map, true);
        file_put_contents($cachePath, "<?php\n\nreturn {$export};\n");

        return count($map);
    }

    /**
     * Clear cached slice discovery file.
     */
    public function clearCache(): bool
    {
        $cachePath = $this->getCachedSlicesPath();
        if (file_exists($cachePath)) {
            return @unlink($cachePath);
        }

        return true;
    }

    protected function bootSlice(SliceManifest $slice): void
    {
        // 1. Register Migrations
        if ($migrationsPath = $slice->getMigrationsPath()) {
            if ($this->app->resolved('migrator')) {
                $this->app['migrator']->path($migrationsPath);
            }
            $this->app->afterResolving('migrator', function ($migrator) use ($migrationsPath) {
                $migrator->path($migrationsPath);
            });
        }

        // 2. Register Views (e.g. view('product::index') or view('products::index'))
        if ($viewsPath = $slice->getViewsPath()) {
            $snake = Str::snake($slice->name);
            $plural = Str::plural($snake);
            $singular = Str::singular($snake);

            $namespaces = array_unique([$snake, $plural, $singular, str_replace('_', '', $snake)]);
            foreach ($namespaces as $ns) {
                if ($ns) {
                    $this->app['view']->addNamespace($ns, $viewsPath);
                }
            }

        }

        // 3. Register Routes (web.php and api.php)
        if ($routesPath = $slice->getRoutesPath()) {
            $webRoute = $routesPath.'/web.php';
            if (file_exists($webRoute)) {
                Route::middleware(['web'])->group($webRoute);
            }

            $apiRoute = $routesPath.'/api.php';
            if (file_exists($apiRoute)) {
                Route::prefix('api')->middleware(['api'])->group($apiRoute);
            }
        }

        // 4. Register custom SliceServiceProvider if exists
        $sliceNamespace = $slice->namespace ?? (
            isset($slice->domain)
                ? (config('laraslice.slices_namespace', 'App\\Slices').'\\'.Str::studly(Str::slug($slice->domain))."\\{$slice->name}")
                : (config('laraslice.slices_namespace', 'App\\Slices')."\\{$slice->name}")
        );
        $providerClass = "{$sliceNamespace}\\{$slice->name}SliceServiceProvider";
        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }
    }

    /**
     * Rewrite files of slices generated by older LaraSlice versions to the current layout
     * (view widths, embedded child panels, child services/controllers, domain-prefixed routes).
     *
     * Runs only from `php artisan slice:repair`; booting a slice never writes files.
     *
     * @return array<int, string> files that were (or, in a dry run, would be) changed
     */
    public function repairSlice(SliceManifest $slice, bool $dryRun = false): array
    {
        $this->repairedFiles = [];
        $this->repairDryRun = $dryRun;

        // Files inside the package (vendor/) are never rewritten
        $packageSlices = realpath(dirname(__DIR__, 2).'/Slices');
        if ($packageSlices !== false && str_starts_with((string) realpath($slice->path), $packageSlices)) {
            return [];
        }

        $viewsPath = $slice->getViewsPath();
        if ($viewsPath) {
            // Auto-heal view layout widths
            try {
                $viewFiles = glob($viewsPath.'/*.blade.php') ?: [];
                $subDirs = glob($viewsPath.'/*', GLOB_ONLYDIR) ?: [];
                foreach ($subDirs as $sub) {
                    $viewFiles = array_merge($viewFiles, glob($sub.'/*.blade.php') ?: []);
                }
                foreach ($viewFiles as $vf) {
                    $vContent = file_get_contents($vf);
                    $vChanged = false;
                    if (str_contains($vContent, 'max-w-2xl mx-auto space-y-6') || str_contains($vContent, 'max-w-3xl mx-auto space-y-6')) {
                        $vContent = str_replace(
                            ['max-w-2xl mx-auto space-y-6', 'max-w-3xl mx-auto space-y-6'],
                            'w-full max-w-5xl mx-auto space-y-6',
                            $vContent
                        );
                        $vChanged = true;
                    }

                    // Upgrade legacy single child button to Embedded Related Records Panel
                    if (str_contains($vContent, '>Manage ') && preg_match('/@if \(!\$isNew && \\\\Illuminate\\\\Support\\\\Facades\\\\Route::has\(\'([a-zA-Z0-9_\.]+)\'\)\)[\s\S]*?<x-ui\.button[^>]*>Manage ([^<]+)<\/x-ui\.button>[\s\S]*?@endif/m', $vContent, $btnMatch)) {
                        $cRoute = $btnMatch[1];
                        $cLabel = trim($btnMatch[2]);
                        $cSingular = Str::singular($cLabel);
                        $createRoute = preg_replace('/\.index$/', '.create', $cRoute);
                        $editRoute = preg_replace('/\.index$/', '.edit', $cRoute);
                        $pLabel = Str::headline(Str::singular($slice->name));
                        $fk = Str::snake(Str::singular($slice->name)).'_id';
                        $childModel = $slice->namespace.'\\Models\\'.$cSingular;

                        $embeddedCard = "@if (!\$isNew && \\Illuminate\\Support\\Facades\\Route::has('{$cRoute}'))\n"
                            ."    @php\n"
                            ."        \$childRecords = null;\n"
                            ."        try {\n"
                            ."            if (class_exists('{$childModel}')) {\n"
                            ."                \$childRecords = \\{$childModel}::where('{$fk}', \$form->id)->latest()->take(10)->get();\n"
                            ."            }\n"
                            ."        } catch (\\Throwable \$e) {}\n"
                            ."        \$childCount = \$childRecords ? count(\$childRecords) : 0;\n"
                            ."    @endphp\n"
                            ."    <div class=\"mt-8 pt-6 border-t border-border space-y-4\">\n"
                            ."        <div class=\"flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3\">\n"
                            ."            <div class=\"flex items-center gap-2.5\">\n"
                            ."                <div class=\"size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold\">\n"
                            ."                    <x-lucide-users class=\"size-4 text-primary\" />\n"
                            ."                </div>\n"
                            ."                <div>\n"
                            ."                    <h3 class=\"text-base font-semibold text-foreground flex items-center gap-2\">\n"
                            ."                        Associated {$cLabel}\n"
                            ."                        <x-ui.badge variant=\"secondary\" class=\"text-xs font-mono\">{{ \$childCount }}</x-ui.badge>\n"
                            ."                    </h3>\n"
                            ."                    <p class=\"text-xs text-muted-foreground\">Manage records directly linked to this {$pLabel}</p>\n"
                            ."                </div>\n"
                            ."            </div>\n"
                            ."            <div class=\"flex items-center gap-2\">\n"
                            ."                @if (\\Illuminate\\Support\\Facades\\Route::has('{$createRoute}'))\n"
                            ."                    <x-ui.button href=\"{{ route('{$createRoute}', ['parentId' => \$form->id]) }}\" as=\"a\" size=\"sm\" class=\"bg-primary text-primary-foreground font-semibold shadow-xs\">\n"
                            ."                        <x-lucide-plus class=\"size-3.5 mr-1\" /> Add {$cSingular}\n"
                            ."                    </x-ui.button>\n"
                            ."                @endif\n"
                            ."                <x-ui.button href=\"{{ route('{$cRoute}', ['parentId' => \$form->id]) }}\" as=\"a\" variant=\"outline\" size=\"sm\" class=\"text-xs\">\n"
                            ."                    View All <x-lucide-arrow-up-right class=\"size-3.5 ml-1\" />\n"
                            ."                </x-ui.button>\n"
                            ."            </div>\n"
                            ."        </div>\n\n"
                            ."        @if (\$childRecords && count(\$childRecords) > 0)\n"
                            ."            <div class=\"rounded-xl border border-border overflow-hidden bg-card/40\">\n"
                            ."                <table class=\"w-full text-sm text-left\">\n"
                            ."                    <thead class=\"text-xs uppercase bg-muted/50 text-muted-foreground border-b border-border\">\n"
                            ."                        <tr>\n"
                            ."                            <th class=\"px-4 py-2.5 font-medium\">Name</th>\n"
                            ."                            <th class=\"px-4 py-2.5 font-medium\">Details</th>\n"
                            ."                            <th class=\"px-4 py-2.5 font-medium text-right\">Actions</th>\n"
                            ."                        </tr>\n"
                            ."                    </thead>\n"
                            ."                    <tbody class=\"divide-y divide-border\">\n"
                            ."                        @foreach (\$childRecords as \$item)\n"
                            ."                            <tr class=\"hover:bg-muted/20 transition-colors\">\n"
                            ."                                <td class=\"px-4 py-2.5 font-medium text-foreground\">\n"
                            ."                                    {{ \$item->name ?? (\$item->first_name ? \$item->first_name . ' ' . (\$item->last_name ?? '') : (\$item->title ?? '#' . \$item->id)) }}\n"
                            ."                                </td>\n"
                            ."                                <td class=\"px-4 py-2.5 text-muted-foreground text-xs\">\n"
                            ."                                    {{ \$item->email ?? \$item->job_title ?? \$item->phone ?? \$item->status ?? '—' }}\n"
                            ."                                </td>\n"
                            ."                                <td class=\"px-4 py-2.5 text-right\">\n"
                            ."                                    @if (\\Illuminate\\Support\\Facades\\Route::has('{$editRoute}'))\n"
                            ."                                        <x-ui.button href=\"{{ route('{$editRoute}', ['parentId' => \$form->id, 'id' => \$item->id]) }}\" as=\"a\" variant=\"ghost\" size=\"sm\" class=\"size-7 p-0\">\n"
                            ."                                            <x-lucide-pencil class=\"size-3.5 text-muted-foreground\" />\n"
                            ."                                        </x-ui.button>\n"
                            ."                                    @endif\n"
                            ."                                </td>\n"
                            ."                            </tr>\n"
                            ."                        @endforeach\n"
                            ."                    </tbody>\n"
                            ."                </table>\n"
                            ."            </div>\n"
                            ."        @else\n"
                            ."            <div class=\"rounded-xl border border-dashed border-border/80 p-6 text-center bg-muted/10\">\n"
                            ."                <div class=\"flex flex-col items-center justify-center gap-1.5\">\n"
                            ."                    <x-lucide-layers class=\"size-6 text-muted-foreground/40\" />\n"
                            ."                    <p class=\"text-xs font-medium text-foreground\">No {$cLabel} linked yet</p>\n"
                            ."                    <p class=\"text-[11px] text-muted-foreground\">Add records associated with this {$pLabel}</p>\n"
                            ."                    @if (\\Illuminate\\Support\\Facades\\Route::has('{$createRoute}'))\n"
                            ."                        <x-ui.button href=\"{{ route('{$createRoute}', ['parentId' => \$form->id]) }}\" as=\"a\" size=\"sm\" variant=\"outline\" class=\"mt-2 text-xs\">\n"
                            ."                            <x-lucide-plus class=\"size-3 mr-1\" /> Add First {$cSingular}\n"
                            ."                        </x-ui.button>\n"
                            ."                    @endif\n"
                            ."                </div>\n"
                            ."            </div>\n"
                            ."        @endif\n"
                            ."    </div>\n"
                            .'@endif';

                        $vContent = str_replace($btnMatch[0], $embeddedCard, $vContent);
                        $vChanged = true;
                    }

                    if ($vChanged) {
                        $this->writeRepair($vf, $vContent);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        // Auto-heal legacy child services, controllers & views for global top-level access
        try {
            $parentPlural = Str::plural(Str::snake($slice->name));
            $parentSingular = Str::singular(Str::snake($slice->name));
            $parentStudly = Str::studly($slice->name);
            $fkDefault = $parentSingular.'_id';

            $servicesPath = $slice->getServicesPath();
            if ($servicesPath && is_dir($servicesPath)) {
                foreach (glob($servicesPath.'/*SliceService.php') ?: [] as $sFile) {
                    $sContent = file_get_contents($sFile);
                    if (str_contains($sContent, 'A parent record is required to access')) {
                        $fk = $fkDefault;
                        if (preg_match('/->where\([\'"]([a-zA-Z0-9_]+)[\'"],\s*\$this->parentId\)/', $sContent, $fkMatch)) {
                            $fk = $fkMatch[1];
                        }

                        // 1. Heal newQuery
                        $newQueryPattern = '/protected function newQuery\(\): Builder\s*\{[\s\S]*?return parent::newQuery\(\)->where\([\'"][a-zA-Z0-9_]+[\'"],\s*\$this->parentId\);\s*\}/';
                        $newQueryReplacement = "protected function newQuery(): Builder\n    {\n        \$query = parent::newQuery();\n        if (\$this->parentId !== null) {\n            \$query->where('{$fk}', \$this->parentId);\n        }\n\n        return \$query;\n    }";
                        $sContent = preg_replace($newQueryPattern, $newQueryReplacement, $sContent);

                        // 2. Heal prepareModelForSave
                        $prepPattern = '/protected function prepareModelForSave\(IBusinessObject \$form, Model \$model, bool \$isNew\): void\s*\{[\s\S]*?\$model->setAttribute\([\'"][a-zA-Z0-9_]+[\'"],\s*\$this->parentId\);\s*\}/';
                        $prepReplacement = "protected function prepareModelForSave(IBusinessObject \$form, Model \$model, bool \$isNew): void\n    {\n        if (\$this->parentId !== null) {\n            \$model->setAttribute('{$fk}', \$this->parentId);\n        } elseif (isset(\$form->{$fk}) && \$form->{$fk}) {\n            \$model->setAttribute('{$fk}', \$form->{$fk});\n        }\n    }";
                        $sContent = preg_replace($prepPattern, $prepReplacement, $sContent);

                        // 3. Heal validate
                        $valPattern = '/if \(\$this->parentId === null\) \{\s*throw new \\\?LogicException\([^\)]*\);\s*\}\s*\$data = \$form->toArray\(\);\s*\$data\[[\'"][a-zA-Z0-9_]+[\'"]\] = \$this->parentId;/';
                        $valReplacement = "\$data = \$form->toArray();\n        if (\$this->parentId !== null) {\n            \$data['{$fk}'] = \$this->parentId;\n        }";
                        $sContent = preg_replace($valPattern, $valReplacement, $sContent);

                        $this->writeRepair($sFile, $sContent);
                    }
                }
            }

            $controllersPath = $slice->getControllersPath();
            if ($controllersPath && is_dir($controllersPath)) {
                foreach (glob($controllersPath.'/*WebController.php') ?: [] as $cFile) {
                    $cContent = file_get_contents($cFile);
                    $cChanged = false;

                    if (! str_contains($cContent, 'extends BaseSliceWebController') || ! str_contains($cContent, 'routeParameters')) {
                        continue;
                    }

                    $baseName = basename($cFile, 'WebController.php');
                    $childPlural = Str::plural(Str::snake($baseName));

                    // Route parameters: check for null parentId cleanly without recursion
                    if (str_contains($cContent, "request()->route('parentId')")) {
                        $cleanedController = preg_replace(
                            '/protected function routeParameters\(array \$extra = \[\]\): array\s*\{[\s\S]*?return array_merge\([\s\S]*?request\(\)->route\([\'"]parentId[\'"]\)[\s\S]*?\$extra\);\s*\}/',
                            "protected function routeParameters(array \$extra = []): array\n    {\n        \$parentId = request()->route('parentId');\n        if (\$parentId === null) {\n            return \$extra;\n        }\n        return array_merge(['parentId' => \$parentId], \$extra);\n    }",
                            $cContent
                        );
                        if ($cleanedController && $cleanedController !== $cContent) {
                            $cContent = $cleanedController;
                            $cChanged = true;
                        }
                    }

                    if ($childPlural !== $parentPlural && ! str_contains($cContent, 'function getRoutePrefix()')) {
                        $methodStr = "\n    protected function getRoutePrefix(): string\n    {\n        if (request()->route('parentId') === null) {\n            return '{$childPlural}.';\n        }\n        return '{$parentPlural}.{$childPlural}.';\n    }\n";
                        $pos = strrpos($cContent, '}');
                        if ($pos !== false) {
                            $cContent = substr($cContent, 0, $pos).$methodStr."\n}\n";
                            $cChanged = true;
                        }
                    }

                    if ($cChanged) {
                        $this->writeRepair($cFile, $cContent);
                    }
                }
            }

            // Child views auto-healing
            if ($viewsPath && is_dir($viewsPath)) {
                $subDirs = glob($viewsPath.'/*', GLOB_ONLYDIR) ?: [];
                foreach ($subDirs as $subDir) {
                    $childName = basename($subDir);
                    $indexFile = $subDir.'/index.blade.php';
                    $formFile = $subDir.'/form.blade.php';

                    if (file_exists($indexFile)) {
                        $idx = file_get_contents($indexFile);
                        $idxChanged = false;
                        if (! str_contains($idx, '$parentId ?') && str_contains($idx, "route('{$parentPlural}.{$childName}.create', ['parentId' => \$parentId])")) {
                            $idx = str_replace(
                                "route('{$parentPlural}.{$childName}.create', ['parentId' => \$parentId])",
                                "(\$parentId ? route('{$parentPlural}.{$childName}.create', ['parentId' => \$parentId]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.create') ? route('{$childName}.create') : url('/crm/{$childName}/create')))",
                                $idx
                            );
                            $idxChanged = true;
                        }
                        if (! str_contains($idx, '$parentId ?') && preg_match("/route\('{$parentPlural}\.{$childName}\.edit',\s*\[[\'\"]parentId[\'\"]\s*=>\s*\\\$parentId,\s*[\'\"](?:contact|id)[\'\"]\s*=>\s*\\\$item->id\]\)/", $idx, $mEdit)) {
                            $idx = str_replace(
                                $mEdit[0],
                                "(\$parentId ? route('{$parentPlural}.{$childName}.edit', ['parentId' => \$parentId, 'id' => \$item->id]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.edit') ? route('{$childName}.edit', \$item->id) : route('{$parentPlural}.{$childName}.edit', ['parentId' => \$item->{$fkDefault} ?? 0, 'id' => \$item->id])))",
                                $idx
                            );
                            $idxChanged = true;
                        }
                        if ($idxChanged) {
                            $this->writeRepair($indexFile, $idx);
                        }
                    }

                    if (file_exists($formFile)) {
                        $ff = file_get_contents($formFile);
                        $ffChanged = false;

                        if (! str_contains($ff, '$parentId ?') && str_contains($ff, "href=\"{{ route('{$parentPlural}.{$childName}.index', ['parentId' => \$parentId]) }}\"")) {
                            $ff = str_replace(
                                "href=\"{{ route('{$parentPlural}.{$childName}.index', ['parentId' => \$parentId]) }}\"",
                                "href=\"{{ \$parentId ? route('{$parentPlural}.{$childName}.index', ['parentId' => \$parentId]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.index') ? route('{$childName}.index') : url('/crm/{$childName}')) }}\"",
                                $ff
                            );
                            $ffChanged = true;
                        }

                        if (! str_contains($ff, '$parentId ?') && str_contains($ff, "\$isNew ? route('{$parentPlural}.{$childName}.store', ['parentId' => \$parentId])")) {
                            $oldAction = "\$isNew ? route('{$parentPlural}.{$childName}.store', ['parentId' => \$parentId]) : route('{$parentPlural}.{$childName}.update', ['parentId' => \$parentId, 'id' => \$form->id])";
                            $newAction = "\$isNew ? (\$parentId ? route('{$parentPlural}.{$childName}.store', ['parentId' => \$parentId]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.store') ? route('{$childName}.store') : route('{$parentPlural}.{$childName}.store', ['parentId' => old('{$fkDefault}', 0)]))) : (\$parentId ? route('{$parentPlural}.{$childName}.update', ['parentId' => \$parentId, 'id' => \$form->id]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.update') ? route('{$childName}.update', \$form->id) : route('{$parentPlural}.{$childName}.update', ['parentId' => \$form->{$fkDefault} ?? 0, 'id' => \$form->id])))";
                            $ff = str_replace($oldAction, $newAction, $ff);
                            $ffChanged = true;
                        }

                        $hiddenInput = '<input type="hidden" name="'.$fkDefault.'" value="{{ $parentId }}">';
                        if (str_contains($ff, $hiddenInput) && ! str_contains($ff, '@if($parentId)')) {
                            $parentLabel = Str::headline($parentSingular);
                            $comboboxSnippet = "@if(\$parentId)\n"
                                ."                <input type=\"hidden\" name=\"{$fkDefault}\" value=\"{{ \$parentId }}\">\n"
                                ."                @else\n"
                                ."                <div class=\"space-y-1.5\">\n"
                                ."                    <x-ui.combobox-relationship\n"
                                ."                        name=\"{$fkDefault}\"\n"
                                ."                        label=\"{$parentLabel}\"\n"
                                ."                        :options=\"\${$parentPlural}Options ?? \${$parentSingular}Options ?? []\"\n"
                                ."                        :selected=\"old('{$fkDefault}', \$form->{$fkDefault} ?? '')\"\n"
                                ."                        placeholder=\"Select {$parentLabel}...\"\n"
                                ."                        required=\"true\"\n"
                                ."                    />\n"
                                ."                    @error('{$fkDefault}')\n"
                                ."                        <p class=\"text-xs text-destructive font-medium\">{{ \$message }}</p>\n"
                                ."                    @enderror\n"
                                ."                </div>\n"
                                .'                @endif';
                            $ff = str_replace($hiddenInput, $comboboxSnippet, $ff);
                            $ffChanged = true;
                        }

                        if ($ffChanged) {
                            $this->writeRepair($formFile, $ff);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        if ($routesPath = $slice->getRoutesPath()) {
            $webRoute = $routesPath.'/web.php';
            if (file_exists($webRoute)) {
                $domain = $slice->domain ?? $slice->raw['domain'] ?? null;
                $webContent = file_get_contents($webRoute);
                $parentTable = Str::plural(Str::snake($slice->name));

                if (! $domain) {
                    if (preg_match("/Route::prefix\(['\"]([^'\"]+)\/{$parentTable}['\"]\)/", $webContent, $m)) {
                        $domain = trim($m[1], '/');
                    }
                }

                if ($domain) {
                    $domainSlug = Str::slug($domain);
                    $parentSingular = Str::singular($parentTable);

                    // Auto-heal legacy or child routes missing domain prefix (e.g. companies/{parentId} -> crm/companies/{parentId})
                    $pattern = "/Route::prefix\(['\"](?!".preg_quote($domainSlug, '/')."\/)(".preg_quote($parentTable, '/').'|'.preg_quote($parentSingular, '/').")\/\{parentId\}['\"]\)/";
                    if (preg_match($pattern, $webContent)) {
                        $updatedContent = preg_replace($pattern, "Route::prefix('{$domainSlug}/$1/{parentId}')", $webContent);
                        if ($updatedContent && $updatedContent !== $webContent) {
                            $this->writeRepair($webRoute, $updatedContent);
                            $webContent = $updatedContent;
                        }
                    }

                    // Auto-heal missing or nested top-level routes for child tables (e.g. /crm/contacts)
                    if (! empty($slice->tables) && is_array($slice->tables)) {
                        $topRoutesNeeded = '';
                        $contentChanged = false;
                        foreach ($slice->tables as $tbl) {
                            if ($tbl !== $parentTable && $tbl !== $parentSingular) {
                                $childPluralSnake = $tbl;
                                $childStudly = Str::studly(Str::singular($tbl));
                                $childUrlPrefix = "{$domainSlug}/{$childPluralSnake}";

                                // A single named group: identical URIs under two names break route:cache
                                $explicitRoutes = "\nRoute::prefix('{$childUrlPrefix}')->name('{$childPluralSnake}.')->middleware(config('laraslice.generated_routes.web_middleware', ['web', 'auth']))->group(function () {\n"
                                    ."    Route::get('/', [{$childStudly}WebController::class, 'index'])->name('index');\n"
                                    ."    Route::get('/create', [{$childStudly}WebController::class, 'create'])->name('create');\n"
                                    ."    Route::post('/', [{$childStudly}WebController::class, 'store'])->name('store');\n"
                                    ."    Route::get('/{id}/edit', [{$childStudly}WebController::class, 'edit'])->name('edit');\n"
                                    ."    Route::put('/{id}', [{$childStudly}WebController::class, 'update'])->name('update');\n"
                                    ."    Route::delete('/{id}', [{$childStudly}WebController::class, 'destroy'])->name('destroy');\n"
                                    ."});\n";

                                if (str_contains($webContent, "Route::prefix('{$childUrlPrefix}')") && str_contains($webContent, "Route::resource('{$childPluralSnake}'")) {
                                    $quotedPrefix = preg_quote($childUrlPrefix, '#');
                                    $quotedChild = preg_quote($childPluralSnake, '#');
                                    $legacyPattern1 = "#Route::prefix\('{$quotedPrefix}'\)[\s\S]*?Route::resource\('{$quotedChild}'[\s\S]*?\}\);\s*Route::prefix\('{$quotedPrefix}'\)[\s\S]*?Route::resource\('{$quotedChild}'[\s\S]*?\}\);#m";
                                    if (preg_match($legacyPattern1, $webContent)) {
                                        $webContent = preg_replace($legacyPattern1, trim($explicitRoutes), $webContent);
                                        $contentChanged = true;
                                    }
                                } elseif (! str_contains($webContent, "Route::prefix('{$childUrlPrefix}')")) {
                                    $topRoutesNeeded .= $explicitRoutes;
                                }
                            }
                        }
                        if ($topRoutesNeeded !== '' || $contentChanged) {
                            $webContent .= $topRoutesNeeded;
                            $this->writeRepair($webRoute, $webContent);
                        }
                    }
                }

            }

            $apiRoute = $routesPath.'/api.php';
            if (file_exists($apiRoute)) {
                $domain = $slice->domain ?? $slice->raw['domain'] ?? null;
                $apiContent = file_get_contents($apiRoute);
                $parentTable = Str::plural(Str::snake($slice->name));

                if (! $domain && isset($webContent)) {
                    if (preg_match("/Route::prefix\(['\"]([^'\"]+)\/{$parentTable}['\"]\)/", $webContent, $m)) {
                        $domain = trim($m[1], '/');
                    }
                }

                if ($domain) {
                    $domainSlug = Str::slug($domain);
                    $parentSingular = Str::singular($parentTable);

                    $patternApi = "/Route::prefix\(['\"](?!".preg_quote($domainSlug, '/')."\/)(".preg_quote($parentTable, '/').'|'.preg_quote($parentSingular, '/').")\/\{parentId\}\/([a-zA-Z0-9_]+)['\"]\)/";
                    if (preg_match($patternApi, $apiContent)) {
                        $updatedApi = preg_replace($patternApi, "Route::prefix('{$domainSlug}/$1/{parentId}/$2')", $apiContent);
                        if ($updatedApi && $updatedApi !== $apiContent) {
                            $this->writeRepair($apiRoute, $updatedApi);
                        }
                    }
                }

            }
        }

        return $this->repairedFiles;
    }

    /** Record (and unless dry-running, write) one repaired file. */
    protected function writeRepair(string $path, string $contents): void
    {
        if (! in_array($path, $this->repairedFiles, true)) {
            $this->repairedFiles[] = $path;
        }
        if (! $this->repairDryRun && file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write {$path}");
        }
    }

    /**
     * Find the Eloquent class for a model name (e.g. "ShopCategory") among discovered slices,
     * then App\Models. Generated belongsTo relations use this instead of guessing namespaces.
     */
    public function modelClass(string $studlyName): ?string
    {
        if (array_key_exists($studlyName, $this->resolvedModels)) {
            return $this->resolvedModels[$studlyName];
        }

        $packageSlices = realpath(dirname(__DIR__, 2).'/Slices');
        $candidates = [];
        foreach ($this->slices as $slice) {
            // Core slices and older manifests have no namespace; derive it from the location
            $namespace = $slice->namespace;
            if (empty($namespace)) {
                $insidePackage = $packageSlices !== false && str_starts_with((string) realpath($slice->path), $packageSlices);
                $namespace = $insidePackage
                    ? 'LaraSlice\\Slices\\'.basename($slice->path)
                    : rtrim((string) config('laraslice.slices_namespace', 'App\\Slices'), '\\')
                        .(! empty($slice->domain) ? '\\'.Str::studly(Str::slug($slice->domain)) : '')
                        .'\\'.basename($slice->path);
            }
            $candidates[] = rtrim($namespace, '\\').'\\Models\\'.$studlyName;
        }
        $candidates[] = 'App\\Models\\'.$studlyName;

        foreach ($candidates as $class) {
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                return $this->resolvedModels[$studlyName] = $class;
            }
        }

        return $this->resolvedModels[$studlyName] = null;
    }

    public function getAllSlices(): array
    {
        return $this->slices;
    }

    public function getSlices(): array
    {
        return $this->slices;
    }

    public function getActiveSlices(): array
    {
        return array_filter($this->slices, fn (SliceManifest $s) => $s->active);
    }

    public function getSlice(string $name): ?SliceManifest
    {
        if (isset($this->slices[$name])) {
            return $this->slices[$name];
        }

        $norm = strtolower(str_replace([' ', '_', '-'], '', $name));

        foreach ($this->slices as $slice) {
            $sliceNorm = strtolower(str_replace([' ', '_', '-'], '', $slice->name));
            if ($sliceNorm === $norm) {
                return $slice;
            }

            if (strtolower(basename($slice->path)) === strtolower($name) || strtolower(str_replace([' ', '_', '-'], '', basename($slice->path))) === $norm) {
                return $slice;
            }

            if (isset($slice->title) && strtolower(str_replace([' ', '_', '-'], '', $slice->title)) === $norm) {
                return $slice;
            }

            if (isset($slice->raw['handle']) && strtolower(str_replace([' ', '_', '-'], '', $slice->raw['handle'])) === $norm) {
                return $slice;
            }
        }

        return null;
    }

    /**
     * Get list of active slices formatted for navigation bars / sidebars
     */
    public function getNavigableSlices(): array
    {
        $currentUser = auth()->check() ? auth()->user() : null;

        // Built once per user per request: every view and component asks for it
        $cacheKey = $currentUser ? 'user:'.$currentUser->getAuthIdentifier() : 'guest';
        if (isset($this->navigationCache[$cacheKey])) {
            return $this->navigationCache[$cacheKey];
        }

        $nav = [];

        $isSuperAdmin = $currentUser && Access::isSuperAdmin($currentUser);

        foreach ($this->getActiveSlices() as $slice) {
            if (isset($slice->navigation['visible']) && $slice->navigation['visible'] === false) {
                continue;
            }

            $studly = Str::studly($slice->name);
            $snake = Str::snake($slice->name);
            $kebab = Str::kebab($slice->name);
            $slug = strtolower($slice->name);

            $singularSnake = Str::snake(Str::singular($slice->name));
            $singularKebab = Str::kebab(Str::singular($slice->name));
            $singular = Str::singular($slug);

            // Determine required RBAC permission for this slice
            $requiredPermission = $slice->navigation['permission'] ?? null;
            if (empty($requiredPermission)) {
                $candidate = $singularSnake.'.view';
                if (! empty($slice->permissions) && in_array($candidate, (array) $slice->permissions, true)) {
                    $requiredPermission = $candidate;
                } elseif (! empty($slice->permissions[0])) {
                    $first = $slice->permissions[0];
                    $requiredPermission = is_array($first) ? ($first['slug'] ?? $first['key'] ?? null) : (string) $first;
                }
            }

            // If user is authenticated and slice requires permission, verify authorization (Super Admin bypasses checks)
            if ($requiredPermission && $currentUser && ! $isSuperAdmin) {
                if (method_exists($currentUser, 'hasPermission')) {
                    if (! $currentUser->hasPermission($requiredPermission)) {
                        continue;
                    }
                } elseif (method_exists($currentUser, 'can')) {
                    if (! $currentUser->can($requiredPermission)) {
                        continue;
                    }
                }
            }

            $group = $slice->navigation['group'] ?? $slice->raw['domain'] ?? $slice->domain ?? null;
            $groupSlug = $group ? Str::slug($group) : null;
            $groupDot = $groupSlug ? str_replace('-', '_', $groupSlug).'.' : '';

            // Determine route: explicit in slice.json, or fallback to registered candidate route names
            $routeCandidates = array_values(array_filter([
                $slice->navigation['route'] ?? null,
                $groupDot ? $groupDot.$snake.'.index' : null,
                $groupDot ? $groupDot.$singularSnake.'.index' : null,
                $snake.'.index',
                $singularSnake.'.index',
                $kebab.'.index',
                $singularKebab.'.index',
                $slug.'.index',
                $singular.'.index',
            ]));

            $route = null;
            foreach ($routeCandidates as $candidate) {
                if (Route::has($candidate)) {
                    $route = $candidate;
                    break;
                }
            }

            $defaultPath = '/'.($groupSlug ? "{$groupSlug}/{$snake}" : $snake);
            $url = $slice->navigation['url'] ?? ($route ? route($route) : url($defaultPath));
            $icon = $slice->navigation['icon'] ?? $slice->icon ?? 'package';
            $label = $slice->navigation['label'] ?? $slice->title ?: Str::headline($slice->name);
            $order = (int) ($slice->navigation['order'] ?? 50);

            $slugsToCheck = array_unique([$slug, $singular, $snake, $singularSnake, $kebab, $singularKebab]);
            $isActive = ($route && request()->routeIs($route));
            if (! $isActive) {
                foreach ($slugsToCheck as $s) {
                    if (request()->is($s) || request()->is($s.'/*') || request()->routeIs($s.'.*')) {
                        $isActive = true;
                        break;
                    }
                    if ($groupSlug && (request()->is($groupSlug.'/'.$s) || request()->is($groupSlug.'/'.$s.'/*') || request()->routeIs($groupDot.$s.'.*'))) {
                        $isActive = true;
                        break;
                    }
                }
            }

            $children = [];
            if (! empty($slice->navigation['children']) && is_array($slice->navigation['children'])) {
                foreach ($slice->navigation['children'] as $child) {
                    $childPerm = $child['permission'] ?? null;
                    if ($childPerm && $currentUser && ! $isSuperAdmin) {
                        if (method_exists($currentUser, 'hasPermission') && ! $currentUser->hasPermission($childPerm)) {
                            continue;
                        } elseif (method_exists($currentUser, 'can') && ! $currentUser->can($childPerm)) {
                            continue;
                        }
                    }

                    $childUrl = $child['url'] ?? (isset($child['route']) && Route::has($child['route']) ? route($child['route']) : '#');
                    $isChildActive = (! empty($child['url']) && (request()->is(ltrim($child['url'], '/')) || request()->is(ltrim($child['url'], '/').'/*')))
                                     || (! empty($child['route']) && request()->routeIs($child['route']));
                    if ($isChildActive) {
                        $isActive = true;
                    }
                    $children[] = [
                        'label' => $child['label'] ?? $child['title'] ?? 'Sub Item',
                        'url' => $childUrl,
                        'route' => $child['route'] ?? null,
                        'icon' => $child['icon'] ?? 'circle',
                        'active' => $isChildActive,
                        'permission' => $childPerm,
                    ];
                }
            } elseif (! empty($slice->tables) && is_array($slice->tables)) {
                // Auto-discover child tables from manifest
                $primaryTable = Str::snake(Str::plural($slice->name));
                foreach ($slice->tables as $tbl) {
                    if ($tbl !== $primaryTable) {
                        $groupSlug = ! empty($slice->navigation['group']) ? strtolower(Str::slug($slice->navigation['group'])) : '';
                        $groupDot = $groupSlug ? $groupSlug.'.' : '';
                        $candidateRoutes = [
                            $tbl.'.index',
                            $groupDot.$tbl.'.index',
                            $slice->name.'.'.$tbl.'.index',
                            $primaryTable.'.'.$tbl.'.index',
                            $groupDot.$primaryTable.'.'.$tbl.'.index',
                        ];
                        $childRoute = null;
                        foreach ($candidateRoutes as $cand) {
                            if (Route::has($cand)) {
                                $childRoute = $cand;
                                break;
                            }
                        }
                        if ($childRoute) {
                            try {
                                $childUrl = route($childRoute);
                            } catch (\Throwable $e) {
                                $childUrl = url(($groupSlug ? $groupSlug.'/' : '').$tbl);
                            }
                            $isChildActive = request()->routeIs($tbl.'.*') || request()->is($tbl) || request()->is($tbl.'/*')
                                || request()->is('*'.$tbl) || request()->is('*'.$tbl.'/*');
                            if ($isChildActive) {
                                $isActive = true;
                            }
                            $children[] = [
                                'label' => Str::headline($tbl),
                                'url' => $childUrl,
                                'route' => $childRoute,
                                'icon' => in_array($tbl, ['contacts', 'users', 'members', 'employees']) ? 'users' : 'layers',
                                'active' => $isChildActive,
                            ];
                        }
                    }
                }
            }

            $nav[] = [
                'name' => $slice->name,
                'label' => $label,
                'route' => $route,
                'url' => $url,
                'icon' => $icon,
                'order' => $order,
                'active' => $isActive,
                'badge' => $slice->navigation['badge'] ?? null,
                'version' => $slice->version,
                'group' => $slice->navigation['group'] ?? $slice->raw['domain'] ?? null,
                'core' => (bool) ($slice->raw['core'] ?? false),
                'permission' => $requiredPermission,
                'children' => $children,
            ];
        }

        usort($nav, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $this->navigationCache[$cacheKey] = $nav;
    }

    /**
     * Automatically sync declared slice permissions into the database permissions table
     */
    /**
     * Sync permissions only when the slices' declared permissions changed since the last sync.
     * Cheap enough for page loads: normally a single cache lookup.
     */
    public function syncPermissionsIfChanged(): int
    {
        $fingerprint = md5(serialize(array_map(
            fn (SliceManifest $slice) => [$slice->name, $slice->title, $slice->domain, $slice->permissions, $slice->navigation['group'] ?? null],
            $this->slices
        )));

        if (Cache::get('laraslice:permissions-fingerprint') === $fingerprint) {
            return 0;
        }

        $count = $this->syncPermissions();
        Cache::forever('laraslice:permissions-fingerprint', $fingerprint);

        return $count;
    }

    /**
     * Create or update the permissions declared by discovered slices and the studio.
     *
     * With $prune, permissions this method created earlier (source "laraslice") that no slice
     * declares any more are deleted. Permissions created by the application are never pruned,
     * and nothing is pruned when no slices were discovered.
     */
    public function syncPermissions(bool $prune = false): int
    {
        if (! SchemaCache::hasTable('permissions')) {
            return 0;
        }

        $hasDomainColumn = SchemaCache::hasColumn('permissions', 'domain');
        $hasSourceColumn = SchemaCache::hasColumn('permissions', 'source');

        $count = 0;
        $validSlugs = [];
        foreach ($this->slices as $slice) {
            $sliceTitle = ! empty($slice->title) ? $slice->title : ucwords(str_replace(['_', '-'], ' ', $slice->name));
            $domain = ! empty($slice->domain) ? $slice->domain : ($slice->navigation['group'] ?? 'Vertical Slices');
            $group = $sliceTitle;
            $perms = $slice->permissions ?? [];

            foreach ($perms as $perm) {
                $slug = is_array($perm) ? ($perm['key'] ?? $perm['slug'] ?? '') : $perm;
                $name = is_array($perm)
                    ? ($perm['name'] ?? $perm['label'] ?? Str::title(str_replace(['.', '_', '-'], ' ', $slug)))
                    : Str::title(str_replace(['.', '_', '-'], ' ', $slug));

                if (! empty($slug)) {
                    $payload = [
                        'name' => $name,
                        'group' => $group,
                        'description' => "Permission to {$name}",
                        'updated_at' => now(),
                        'created_at' => now(),
                    ];

                    if ($hasDomainColumn) {
                        $payload['domain'] = $domain;
                    }
                    if ($hasSourceColumn) {
                        $payload['source'] = 'laraslice';
                    }

                    DB::table('permissions')->updateOrInsert(
                        ['slug' => $slug],
                        $payload
                    );
                    $validSlugs[] = $slug;
                    $count++;
                }
            }
        }

        // 2. Register System-Level Core Slice Permissions
        $coreSystemPerms = [
            ['slug' => 'studio.access', 'name' => 'Access Slice Studio & Wizard', 'desc' => 'Allows opening and accessing Slice Studio and Blueprint Studio interfaces.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.create', 'name' => 'Scaffold & Generate Feature Slices', 'desc' => 'Allows generating new vertical slices, child tables, and fields via the wizard.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.blueprint', 'name' => 'Blueprint Studio Schema Design', 'desc' => 'Allows designing, introspecting, and applying declarative schemas in Blueprint Studio.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.migrate', 'name' => 'Execute Migrations from Studio', 'desc' => 'Allows running database migrations directly from Slice Studio.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.wipe', 'name' => 'Wipe / Truncate Slice Records', 'desc' => 'Allows wiping demo or production records from slice tables.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'system.slices.view', 'name' => 'View Slice Studio & Slices', 'desc' => 'Allows viewing of all installed vertical slices in studio and directory.'],
            ['slug' => 'system.slices.toggle', 'name' => 'Toggle Slices (Enable/Disable)', 'desc' => 'Allows enabling or disabling slices and domains from navigation.'],
            ['slug' => 'system.slices.seed', 'name' => 'Seed Demo Data', 'desc' => 'Allows generating realistic demo data at slice and domain levels.'],
            ['slug' => 'system.slices.wipe', 'name' => 'Wipe Slice Data', 'desc' => 'Allows truncating data across slice and domain tables.'],
            ['slug' => 'system.slices.delete', 'name' => 'Delete Slices & Domains', 'desc' => 'Allows complete or selective destruction of slice code and databases.'],
            ['slug' => 'slice.disable', 'name' => 'Disable Slices', 'desc' => 'Allows deactivating slices from navigation and routing.'],
            ['slug' => 'slice.seed', 'name' => 'Seed Slices', 'desc' => 'Allows generating mock seed data for individual slices.'],
            ['slug' => 'slice.wipe', 'name' => 'Wipe Slice Records', 'desc' => 'Allows truncating records in slice tables.'],
            ['slug' => 'slice.delete', 'name' => 'Delete Slices', 'desc' => 'Allows removal and destruction of slices.'],
            ['slug' => 'domain.manage', 'name' => 'Manage Domains', 'desc' => 'Allows domain-level configuration, toggle, and lifecycle control.'],
            ['slug' => 'domain.seed', 'name' => 'Seed Domains', 'desc' => 'Allows domain-wide demo record generation.'],
            ['slug' => 'domain.wipe', 'name' => 'Wipe Domains', 'desc' => 'Allows truncating all tables across a domain.'],
            ['slug' => 'domain.delete', 'name' => 'Delete Domains', 'desc' => 'Allows complete or granular removal of domains.'],
        ];

        // Also add domain-specific management permissions
        $domainsSeen = [];
        foreach ($this->slices as $slice) {
            $d = ! empty($slice->domain) ? $slice->domain : ($slice->navigation['group'] ?? null);
            if ($d && ! in_array($d, $domainsSeen, true)) {
                $domainsSeen[] = $d;
                $dSlug = strtolower(Str::slug($d));
                $coreSystemPerms[] = [
                    'slug' => "{$dSlug}.manage",
                    'name' => "Manage {$d} Domain",
                    'desc' => "Complete administrative management of all slices in {$d} domain.",
                ];
                $coreSystemPerms[] = [
                    'slug' => "{$dSlug}.seed",
                    'name' => "Seed {$d} Data",
                    'desc' => "Generate demo seed records for all slices in {$d} domain.",
                ];
            }
        }

        foreach ($coreSystemPerms as $sp) {
            $payload = [
                'name' => $sp['name'],
                'group' => $sp['group'] ?? 'System Administration',
                'description' => $sp['desc'],
                'updated_at' => now(),
                'created_at' => now(),
            ];
            if ($hasDomainColumn) {
                $payload['domain'] = $sp['domain'] ?? 'System';
            }
            if ($hasSourceColumn) {
                $payload['source'] = 'laraslice';
            }
            DB::table('permissions')->updateOrInsert(
                ['slug' => $sp['slug']],
                $payload
            );
            $validSlugs[] = $sp['slug'];
            $count++;
        }

        // 3. Prune permissions of removed slices: only on request, only rows this method created
        if ($prune && $this->slices !== [] && $hasSourceColumn) {
            $orphanIds = DB::table('permissions')
                ->where('source', 'laraslice')
                ->whereNotIn('slug', $validSlugs)
                ->pluck('id')
                ->all();

            if ($orphanIds !== []) {
                DB::table('permission_role')->whereIn('permission_id', $orphanIds)->delete();
                DB::table('permissions')->whereIn('id', $orphanIds)->delete();
            }
        }

        // Ensure super-admin role automatically receives all synced permissions by default
        try {
            $superAdminRole = Schema::hasTable('roles')
                ? DB::table('roles')->where('slug', 'super-admin')->first()
                : null;

            if ($superAdminRole && Schema::hasTable('permission_role')) {
                $allPermIds = DB::table('permissions')->pluck('id');
                $existing = DB::table('permission_role')
                    ->where('role_id', $superAdminRole->id)
                    ->pluck('permission_id')
                    ->toArray();

                $missing = $allPermIds->diff($existing);
                if ($missing->isNotEmpty()) {
                    $newRows = [];
                    foreach ($missing as $mId) {
                        $newRows[] = [
                            'role_id' => $superAdminRole->id,
                            'permission_id' => $mId,
                        ];
                    }
                    DB::table('permission_role')->insert($newRows);
                }
            }
        } catch (\Throwable $e) {
            // Fail-safe if DB schema not ready
        }

        return $count;
    }

    /**
     * Enable or disable a single slice.
     */
    public function toggleSlice(string $sliceName, ?bool $active = null): bool
    {
        $this->discover();
        $slice = $this->getSlice($sliceName);
        if (! $slice) {
            foreach ($this->getAllSlices() as $s) {
                if (strtolower($s->name) === strtolower($sliceName)) {
                    $slice = $s;
                    break;
                }
            }
        }

        if (! $slice) {
            throw new \InvalidArgumentException("Slice [{$sliceName}] not found.");
        }

        $newActive = ($active !== null) ? (bool) $active : ! $slice->active;

        // Update slice.json
        $jsonPath = $slice->path.'/slice.json';
        if (file_exists($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true) ?: [];
            $data['active'] = $newActive;
            file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // Update slice.yaml
        foreach (['slice.yaml', 'slice.yml'] as $yf) {
            $yamlPath = $slice->path.'/'.$yf;
            if (file_exists($yamlPath) && class_exists(Yaml::class)) {
                $yData = Yaml::parse(file_get_contents($yamlPath)) ?: [];
                $yData['active'] = $newActive;
                file_put_contents($yamlPath, Yaml::dump($yData, 10, 2));
            }
        }

        $slice->active = $newActive;
        $this->clearCache();

        return $newActive;
    }

    /**
     * Enable or disable all slices in a domain.
     */
    /**
     * Slices whose domain (or navigation group) matches $domainName, case-insensitively.
     *
     * @return array<string, SliceManifest>
     */
    public function getDomainSlices(string $domainName): array
    {
        $wanted = strtolower(trim($domainName));

        return array_filter($this->getAllSlices(), function (SliceManifest $slice) use ($wanted) {
            $sliceDomain = $slice->domain ?? $slice->navigation['group'] ?? $slice->raw['domain'] ?? null;

            return strtolower(trim((string) $sliceDomain)) === $wanted;
        });
    }

    public function toggleDomain(string $domainName, ?bool $active = null): array
    {
        $this->discover();
        $updated = [];

        foreach ($this->getDomainSlices($domainName) as $slice) {
            $updated[$slice->name] = $this->toggleSlice($slice->name, $active);
        }

        $this->clearCache();

        return $updated;
    }

    /**
     * Discover all database tables associated with a slice.
     */
    /**
     * Core slices (Users, Roles, Settings, Auth, or any manifest with "core": true)
     * and slices inside the package itself are never destroyed or wiped.
     */
    public function isProtectedSlice(SliceManifest $slice): bool
    {
        if (filter_var($slice->raw['core'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $packageSlices = realpath(dirname(__DIR__, 2).'/Slices');
        $slicePath = realpath($slice->path);

        return $packageSlices !== false && $slicePath !== false
            && str_starts_with($slicePath.DIRECTORY_SEPARATOR, $packageSlices.DIRECTORY_SEPARATOR);
    }

    /**
     * Tables owned by protected slices; these are never dropped or truncated.
     *
     * @return array<int, string>
     */
    public function protectedTables(): array
    {
        $tables = [];
        foreach ($this->getAllSlices() as $slice) {
            if ($this->isProtectedSlice($slice)) {
                $tables = array_merge($tables, $this->getSliceTables($slice), $slice->tables ?? []);
            }
        }

        return array_values(array_unique(array_filter($tables)));
    }

    public function getSliceTables(SliceManifest $slice): array
    {
        $primary = $slice->name ? strtolower(Str::plural(Str::snake($slice->name))) : '';
        $tables = array_filter([$primary]);

        if (! empty($slice->tables)) {
            foreach ($slice->tables as $t) {
                if (! in_array($t, $tables, true)) {
                    $tables[] = $t;
                }
            }
        }

        $modelFiles = glob($slice->path.'/Models/*.php') ?: [];
        foreach ($modelFiles as $mf) {
            $c = @file_get_contents($mf);
            if ($c && preg_match('/protected\\s+\\$table\\s*=\\s*[\\\'\"]([^\\\'\"]+)[\\\'\"]/', $c, $m)) {
                if (! in_array($m[1], $tables, true)) {
                    $tables[] = $m[1];
                }
            } else {
                $t = strtolower(Str::plural(Str::snake(basename($mf, '.php'))));
                if (! in_array($t, $tables, true)) {
                    $tables[] = $t;
                }
            }
        }

        $migrationFiles = array_merge(
            glob($slice->path.'/Database/Migrations/*.php') ?: [],
            glob($slice->path.'/Migrations/*.php') ?: []
        );
        foreach ($migrationFiles as $mf) {
            $c = @file_get_contents($mf);
            if ($c && preg_match_all('/Schema::(?:create|table)\\([\\\'\"]([^\\\'\"]+)[\\\'\"]/', $c, $matches)) {
                foreach ($matches[1] as $t) {
                    if (! in_array($t, $tables, true)) {
                        $tables[] = $t;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($tables)));
    }

    /**
     * Destroy a slice with granular removal modes.
     * Modes: 'complete' (code + db), 'code_only', 'db_only', 'wipe_data'
     */
    public function destroySlice(string $sliceName, string $mode = 'complete'): array
    {
        $this->discover();
        $slice = $this->getSlice($sliceName);
        if (! $slice) {
            foreach ($this->getAllSlices() as $s) {
                if (strtolower($s->name) === strtolower($sliceName)) {
                    $slice = $s;
                    break;
                }
            }
        }

        if (! $slice) {
            throw new \InvalidArgumentException("Slice [{$sliceName}] not found.");
        }

        if ($this->isProtectedSlice($slice)) {
            throw new \RuntimeException("Slice [{$slice->name}] is a core LaraSlice slice and cannot be destroyed.");
        }

        $tables = array_values(array_diff($this->getSliceTables($slice), $this->protectedTables()));

        $droppedTables = [];
        $wipedTables = [];
        $codeRemoved = false;

        // 1. Database operations
        if ($mode === 'complete' || $mode === 'db_only') {
            Schema::disableForeignKeyConstraints();
            foreach ($tables as $t) {
                if (! empty($t) && Schema::hasTable($t)) {
                    Schema::dropIfExists($t);
                    $droppedTables[] = $t;
                }
            }
            try {
                if (Schema::hasTable('migrations')) {
                    $migrationFiles = array_merge(
                        glob($slice->path.'/Database/Migrations/*.php') ?: [],
                        glob($slice->path.'/Migrations/*.php') ?: []
                    );
                    foreach ($migrationFiles as $mf) {
                        $migBase = basename($mf, '.php');
                        DB::table('migrations')->where('migration', $migBase)->delete();
                    }
                }
            } catch (\Throwable) {
            }
            Schema::enableForeignKeyConstraints();
        } elseif ($mode === 'wipe_data') {
            Schema::disableForeignKeyConstraints();
            foreach ($tables as $t) {
                if (! empty($t) && Schema::hasTable($t)) {
                    DB::table($t)->truncate();
                    $wipedTables[] = $t;
                }
            }
            Schema::enableForeignKeyConstraints();
        }

        // 2. Code deletion
        if ($mode === 'complete' || $mode === 'code_only') {
            $slicePath = $slice->path;
            if (is_dir($slicePath)) {
                $this->recursiveDeleteDir($slicePath);
                $codeRemoved = true;
            }
            unset($this->slices[$slice->name]);
        }

        $this->clearCache();
        try {
            $this->syncPermissions(prune: true);
        } catch (\Throwable) {
        }

        return [
            'success' => true,
            'slice' => $sliceName,
            'mode' => $mode,
            'code_removed' => $codeRemoved,
            'tables_dropped' => $droppedTables,
            'tables_wiped' => $wipedTables,
            'message' => "Slice [{$sliceName}] successfully processed with mode [{$mode}].",
        ];
    }

    /**
     * Destroy an entire domain with granular options.
     */
    public function destroyDomain(string $domainName, string $mode = 'complete'): array
    {
        $this->discover();
        $slices = [];
        foreach ($this->getAllSlices() as $s) {
            $sliceDomain = $s->domain ?? $s->navigation['group'] ?? $s->raw['domain'] ?? null;
            if (strtolower(trim((string) $sliceDomain)) === strtolower(trim($domainName)) && ! $this->isProtectedSlice($s)) {
                $slices[] = $s;
            }
        }

        $results = [];
        $domainDirs = [];

        foreach ($slices as $slice) {
            $domainDirs[] = dirname($slice->path);
            $results[$slice->name] = $this->destroySlice($slice->name, $mode);
        }

        if ($mode === 'complete' || $mode === 'code_only') {
            foreach (array_unique($domainDirs) as $dDir) {
                if (is_dir($dDir)) {
                    $remaining = glob($dDir.'/*');
                    if (empty($remaining)) {
                        @rmdir($dDir);
                    }
                }
            }
        }

        $this->clearCache();
        try {
            $this->syncPermissions(prune: true);
        } catch (\Throwable) {
        }

        return [
            'success' => true,
            'domain' => $domainName,
            'mode' => $mode,
            'slices' => $results,
            'message' => "Domain [{$domainName}] processed with mode [{$mode}].",
        ];
    }

    protected function recursiveDeleteDir(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $p = $dir.DIRECTORY_SEPARATOR.$file;
            is_dir($p) ? $this->recursiveDeleteDir($p) : @unlink($p);
        }

        return @rmdir($dir);
    }
}
