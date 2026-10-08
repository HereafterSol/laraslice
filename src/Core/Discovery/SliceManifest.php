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
    public ?string $domain;
    public ?string $namespace;
    public array $raw;
    public string $path;

    public function __construct(string $manifestPath)
    {
        $this->path = dirname($manifestPath);
        $content = file_exists($manifestPath) ? file_get_contents($manifestPath) : '';
        $data = [];

        if (str_ends_with($manifestPath, '.yaml') || str_ends_with($manifestPath, '.yml')) {
            $data = class_exists(Yaml::class) ? (Yaml::parse($content) ?: []) : [];
            $jsonFile = $this->path . '/slice.json';
            if (file_exists($jsonFile)) {
                $jsonData = json_decode(file_get_contents($jsonFile), true) ?: [];
                $data = array_merge($jsonData, $data);
                if (!empty($jsonData['tables'])) {
                    $data['tables'] = array_values(array_unique(array_merge($data['tables'] ?? [], $jsonData['tables'])));
                }
                if (!empty($jsonData['relations'])) {
                    $data['relations'] = array_values(array_unique(array_merge($data['relations'] ?? [], $jsonData['relations']), SORT_REGULAR));
                }
            }
            if (empty($data['tables']) && !empty($data['models']) && is_array($data['models'])) {
                foreach ($data['models'] as $m) {
                    if (!empty($m['table'])) {
                        $data['tables'][] = $m['table'];
                    }
                }
            }
        } else {
            $data = json_decode($content, true) ?: [];
        }

        $this->name         = $data['name'] ?? basename($this->path);
        $this->title        = $data['title'] ?? ucwords(str_replace(['_', '-'], ' ', $this->name));
        $this->version      = $data['version'] ?? '1.0.0';
        $this->description  = $data['description'] ?? '';
        $this->author       = $data['author'] ?? 'LaraSlice';
        $this->icon         = $data['icon'] ?? 'cube';
        // Manifests may say "false" or "no"; unparsable values keep the slice active
        $this->active       = filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $this->dependencies = $data['dependencies'] ?? [];
        $this->permissions  = $data['permissions'] ?? [];
        $this->navigation   = $data['navigation'] ?? [];
        $this->tables       = $data['tables'] ?? [];
        $this->relations    = $data['relations'] ?? [];
        $parentFolder = basename(dirname($this->path));
        $inferredDomain = (!in_array(strtolower($parentFolder), ['slices', 'src', 'app', 'packages', 'vendor'])) ? ucwords(str_replace(['_', '-'], ' ', $parentFolder)) : null;
        if ($inferredDomain === 'Ecommerce') {
            $inferredDomain = 'E-Commerce';
        }
        $this->domain       = $data['domain'] ?? $data['navigation']['group'] ?? $inferredDomain;
        $this->namespace    = $data['namespace'] ?? null;
        $this->raw          = $data;
    }

    public function getRoutesPath(): ?string
    {
        $path = $this->path . '/Routes';
        return is_dir($path) ? $path : null;
    }

    public function getMigrationsPath(): ?string
    {
        $path = $this->path . '/Migrations';
        return is_dir($path) ? $path : null;
    }

    public function getViewsPath(): ?string
    {
        $path = $this->path . '/Resources/views';
        return is_dir($path) ? $path : null;
    }

    public function getServicesPath(): ?string
    {
        $path = $this->path . '/Services';
        return is_dir($path) ? $path : null;
    }

    public function getControllersPath(): ?string
    {
        $path = $this->path . '/Controllers';
        return is_dir($path) ? $path : null;
    }

    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'title'        => $this->title,
            'version'      => $this->version,
            'description'  => $this->description,
            'author'       => $this->author,
            'icon'         => $this->icon,
            'active'       => $this->active,
            'dependencies' => $this->dependencies,
            'permissions'  => $this->permissions,
            'path'         => $this->path,
        ];
    }
}
