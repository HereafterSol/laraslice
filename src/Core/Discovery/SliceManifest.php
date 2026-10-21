<?php

namespace LaraSlice\Core\Discovery;

use Symfony\Component\Yaml\Yaml;

class SliceManifest
{
    public string $name;

    public string $title;

    public string $version;

    public string $description;

    public string $author;

    public string $icon;

    public bool $active;

    public array $dependencies;

    public array $permissions;

    public array $navigation;

    public array $tables;

    public array $relations;

    public ?string $super_domain = null;

    public ?string $domain = null;

    public ?string $sub_domain = null;

    public ?string $app = null;

    public ?string $module = null;

    public ?string $canonical_id = null;

    public ?string $table_prefix = null;

    public bool $multitenant = false;

    public string $tenant_key = 'company_id';

    public ?string $namespace = null;

    public array $raw = [];

    public string $path;

    public function __construct(string $manifestPath)
    {
        $this->path = dirname($manifestPath);
        $content = file_exists($manifestPath) ? file_get_contents($manifestPath) : '';
        $data = [];

        if (str_ends_with($manifestPath, '.yaml') || str_ends_with($manifestPath, '.yml')) {
            $data = class_exists(Yaml::class) ? (Yaml::parse($content) ?: []) : [];
            $jsonFile = $this->path.'/slice.yaml';
            if (file_exists($jsonFile)) {
                $jsonData = json_decode(file_get_contents($jsonFile), true) ?: [];
                $data = array_merge($jsonData, $data);
                if (! empty($jsonData['tables'])) {
                    $data['tables'] = array_values(array_unique(array_merge($data['tables'] ?? [], $jsonData['tables'])));
                }
                if (! empty($jsonData['relations'])) {
                    $data['relations'] = array_values(array_unique(array_merge($data['relations'] ?? [], $jsonData['relations']), SORT_REGULAR));
                }
            }
            if (empty($data['tables']) && ! empty($data['models']) && is_array($data['models'])) {
                foreach ($data['models'] as $m) {
                    if (! empty($m['table'])) {
                        $data['tables'][] = $m['table'];
                    }
                }
            }
        } else {
            $data = json_decode($content, true) ?: [];
        }

        $this->name = $data['name'] ?? basename($this->path);
        $this->title = $data['title'] ?? ucwords(str_replace(['_', '-'], ' ', $this->name));
        $this->version = $data['version'] ?? '1.0.0';
        $this->description = $data['description'] ?? '';
        $this->author = $data['author'] ?? 'LaraSlice';
        $this->icon = $data['icon'] ?? 'cube';
        // Manifests may say "false" or "no"; unparsable values keep the slice active
        $this->active = filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $this->dependencies = $data['dependencies'] ?? [];
        $this->permissions = $data['permissions'] ?? [];
        $this->navigation = $data['navigation'] ?? [];
        $this->tables = $data['tables'] ?? [];
        $this->relations = $data['relations'] ?? [];

        // Automatic folder-depth taxonomy inference
        $inferredSuperDomain = null;
        $inferredDomain = null;
        $inferredSubDomain = null;

        $appSlicesPath = config('laraslice.slices_path', function_exists('app_path') ? app_path('Slices') : null);
        $realApp = $appSlicesPath ? realpath($appSlicesPath) : false;
        $realPath = realpath($this->path);

        if ($realApp && $realPath && str_starts_with($realPath, $realApp)) {
            $relativeFolder = trim(substr($realPath, strlen($realApp)), DIRECTORY_SEPARATOR);
            $folderParts = array_filter(explode(DIRECTORY_SEPARATOR, $relativeFolder));
            // e.g. ['ERP', 'Finance', 'Billing', 'Invoices']
            $count = count($folderParts);
            if ($count >= 4) {
                $inferredSuperDomain = $folderParts[0];
                $inferredDomain = $folderParts[1];
                $inferredSubDomain = $folderParts[2];
            } elseif ($count === 3) {
                $inferredSuperDomain = $folderParts[0];
                $inferredDomain = $folderParts[1];
            } elseif ($count === 2) {
                $inferredDomain = $folderParts[0];
            }
        } else {
            $parentFolder = basename(dirname($this->path));
            $grandParentFolder = basename(dirname(dirname($this->path)));
            if (! in_array(strtolower($parentFolder), ['slices', 'src', 'app', 'packages', 'vendor'])) {
                $inferredDomain = ucwords(str_replace(['_', '-'], ' ', $parentFolder));
                if (! in_array(strtolower($grandParentFolder), ['slices', 'src', 'app', 'packages', 'vendor'])) {
                    $inferredSuperDomain = ucwords(str_replace(['_', '-'], ' ', $grandParentFolder));
                }
            }
        }

        if ($inferredDomain === 'Ecommerce') {
            $inferredDomain = 'E-Commerce';
        }

        // Harmonize App / Module with SuperDomain / Domain
        $this->app = $data['app'] ?? $data['super_domain'] ?? $inferredSuperDomain;
        $this->module = $data['module'] ?? $data['domain'] ?? $data['navigation']['group'] ?? $inferredDomain;
        $this->super_domain = $this->app;
        $this->domain = $this->module;
        $this->sub_domain = $data['sub_domain'] ?? $inferredSubDomain;

        // Canonical ID: e.g. erp.finance.ledger or adjudication.complaints
        $idParts = array_filter([$this->app, $this->module, $this->name]);
        $slugParts = array_map(fn ($p) => \Illuminate\Support\Str::slug($p), $idParts);
        $this->canonical_id = $data['canonical_id'] ?? implode('.', $slugParts);

        $this->table_prefix = $data['table_prefix'] ?? null;
        $this->multitenant = filter_var($data['multitenant'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->tenant_key = $data['tenant_key'] ?? 'company_id';
        $this->namespace = $data['namespace'] ?? null;
        $this->raw = $data;
    }

    public function getNamespace(): string
    {
        if (! empty($this->namespace)) {
            return $this->namespace;
        }

        $appSlicesPath = config('laraslice.slices_path', function_exists('app_path') ? app_path('Slices') : null);
        $coreSlicesPath = dirname(__DIR__, 2).'/Slices';

        $realApp = $appSlicesPath ? realpath($appSlicesPath) : false;
        $realCore = realpath($coreSlicesPath);
        $realPath = realpath($this->path);

        if ($realApp && $realPath && str_starts_with($realPath, $realApp)) {
            $relative = trim(substr($realPath, strlen($realApp)), DIRECTORY_SEPARATOR);
            $parts = array_filter(explode(DIRECTORY_SEPARATOR, $relative));
            $studlyParts = array_map(fn ($p) => \Illuminate\Support\Str::studly(\Illuminate\Support\Str::slug($p)), $parts);

            return config('laraslice.slices_namespace', 'App\\Slices').'\\'.implode('\\', $studlyParts);
        }

        if ($realCore && $realPath && str_starts_with($realPath, $realCore)) {
            $relative = trim(substr($realPath, strlen($realCore)), DIRECTORY_SEPARATOR);
            $parts = array_filter(explode(DIRECTORY_SEPARATOR, $relative));
            $studlyParts = array_map(fn ($p) => \Illuminate\Support\Str::studly(\Illuminate\Support\Str::slug($p)), $parts);

            return 'LaraSlice\\Slices\\'.implode('\\', $studlyParts);
        }

        if ($this->domain) {
            $domainPart = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::slug($this->domain));

            return config('laraslice.slices_namespace', 'App\\Slices')."\\{$domainPart}\\{$this->name}";
        }

        return config('laraslice.slices_namespace', 'App\\Slices')."\\{$this->name}";
    }

    public function getRoutesPath(): ?string
    {
        $path = $this->path.'/Routes';

        return is_dir($path) ? $path : null;
    }

    public function getMigrationsPath(): ?string
    {
        if (is_dir($this->path.'/Migrations')) {
            return $this->path.'/Migrations';
        }

        if (is_dir($this->path.'/Database/Migrations')) {
            return $this->path.'/Database/Migrations';
        }

        return null;
    }

    public function getViewsPath(): ?string
    {
        $path = $this->path.'/Resources/views';

        return is_dir($path) ? $path : null;
    }

    public function getServicesPath(): ?string
    {
        $path = $this->path.'/Services';

        return is_dir($path) ? $path : null;
    }

    /**
     * The slice's primary (root) table, resolved across manifest shapes:
     * the declared tables list, the root model's manifest entry, and
     * finally the model class itself. Older manifests declare none of
     * these keys, which left ->tables empty and blinded porter collision
     * detection and link audits for exactly the slices that needed them.
     */
    public function primaryTable(): ?string
    {
        if (! empty($this->tables)) {
            return (string) $this->tables[0];
        }

        foreach (($this->raw['models'] ?? []) as $model) {
            if (is_array($model) && ! empty($model['table']) && ($model['root'] ?? true)) {
                return (string) $model['table'];
            }
        }

        $class = $this->getModelClass();
        if ($class && class_exists($class)) {
            try {
                $table = (new $class)->getTable();
                if (is_string($table) && $table !== '') {
                    return $table;
                }
            } catch (\Throwable $e) {
                // fall through to null
            }
        }

        return null;
    }

    public function getModelClass(): ?string
    {
        $modelsDir = $this->path.'/Models';
        if (is_dir($modelsDir)) {
            $files = glob($modelsDir.'/*.php');
            if (! empty($files)) {
                $base = basename($files[0], '.php');
                $cleanDomain = $this->domain ? str_replace([' ', '-'], '', $this->domain) : null;
                $candidates = [
                    $this->namespace ? $this->namespace.'\\Models\\'.$base : null,
                    $cleanDomain ? 'App\\Slices\\'.$cleanDomain.'\\'.$this->name.'\\Models\\'.$base : null,
                    'App\\Slices\\'.$this->name.'\\Models\\'.$base,
                    'App\\Models\\'.$base,
                ];
                foreach (array_filter($candidates) as $c) {
                    if (class_exists($c)) {
                        return $c;
                    }
                }
            }
        }

        return null;
    }

    public function getControllersPath(): ?string
    {
        $path = $this->path.'/Controllers';

        return is_dir($path) ? $path : null;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'icon' => $this->icon,
            'active' => $this->active,
            'dependencies' => $this->dependencies,
            'permissions' => $this->permissions,
            'path' => $this->path,
            'app' => $this->app,
            'module' => $this->module,
            'canonical_id' => $this->canonical_id,
            'super_domain' => $this->super_domain,
            'domain' => $this->domain,
            'sub_domain' => $this->sub_domain,
            'table_prefix' => $this->table_prefix,
            'multitenant' => $this->multitenant,
            'tenant_key' => $this->tenant_key,
            'namespace' => $this->getNamespace(),
        ];
    }
}
