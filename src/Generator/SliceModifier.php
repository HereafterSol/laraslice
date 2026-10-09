<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Schema\FieldType;

class SliceModifier
{
    protected string $slicesPath;

    protected string $namespace;

    public function __construct(?string $slicesPath = null, ?string $namespace = null)
    {
        $this->slicesPath = $slicesPath ?: config('laraslice.slices_path', app_path('Slices'));
        $this->namespace = $namespace ?: config('laraslice.slices_namespace', 'App\\Slices');
    }

    /**
     * Add multiple fields to an existing slice in a SINGLE consolidated migration (October CMS Builder style).
     *
     * @param  array  $fields  Array of field definitions: [['name' => 'sku', 'type' => 'string', 'length' => 100, 'nullable' => true, 'default' => null, 'unsigned' => false]]
     */
    /** "1.2.3" becomes "1.2.4"; a missing version starts from 1.0.0. */
    private static function nextPatchVersion(?string $version): string
    {
        $parts = explode('.', $version ?: '1.0.0');
        $parts[count($parts) - 1] = ((int) end($parts)) + 1;

        return implode('.', $parts);
    }

    public function addFieldsBatch(string $sliceName, array $fields, string $author = 'Developer', ?string $note = null, ?string $targetTable = null): array
    {
        $studlyName = SliceName::canonical($sliceName);
        $fields = $this->normalizeMigrationFields($fields);
        $pluralName = Str::plural($studlyName);
        $tableName = $targetTable ? Str::snake($targetTable) : Str::plural(Str::snake($studlyName));
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $tableName)) {
            throw new \InvalidArgumentException('Target table must be a lowercase snake_case identifier.');
        }
        $sliceDir = $this->resolveSliceDir($pluralName);

        if (! is_dir($sliceDir)) {
            throw new \RuntimeException("Slice directory for [{$sliceName}] not found at {$sliceDir}");
        }

        $manifestFile = $sliceDir.'/slice.json';
        $manifest = file_exists($manifestFile) ? ManifestRepository::read($manifestFile) : [];

        // Build column definitions and down statements
        $upStatements = [];
        $downColumns = [];
        $fieldSummaries = [];

        foreach ($fields as $field) {
            $snakeField = $field['name'];
            $type = $field['type'];
            $nullable = ! empty($field['nullable']);
            $length = $field['length'] ?? null;
            $default = $field['default'] ?? null;
            $unsigned = ! empty($field['unsigned']);

            $definition = $this->buildColumnDefinition($snakeField, $type, $length, $nullable, $default, $unsigned);
            $upStatements[] = "            {$definition};";
            $downColumns[] = "'{$snakeField}'";
            $fieldSummaries[] = "{$snakeField} ({$type})";
        }

        // 1. Generate ONE single consolidated migration
        $timestamp = date('Y_m_d_His');
        $migrationSlug = count($fields) === 1
            ? 'add_'.Str::snake($fields[0]['name'])."_to_{$tableName}_table"
            : "update_{$tableName}_table_add_".count($fields).'_columns';
        $migrationFile = $sliceDir."/Migrations/{$timestamp}_{$migrationSlug}.php";
        if (file_exists($migrationFile)) {
            throw new \RuntimeException("Migration already exists at {$migrationFile}; retry after the current second.");
        }

        $upBody = implode("\n", $upStatements);
        $downList = implode(', ', $downColumns);

        $migrationContent = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
{$upBody}
        });
    }

    public function down(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
            \$table->dropColumn([{$downList}]);
        });
    }
};
PHP;
        if (file_put_contents($migrationFile, $migrationContent, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write generated migration: {$migrationFile}");
        }

        // 2. Update Form & Listing DTOs or Child Models
        $isPrimary = ! $targetTable || ($targetTable === Str::plural(Str::snake($studlyName)));
        if ($isPrimary) {
            $formDtoCandidates = [
                $sliceDir."/Contracts/{$studlyName}FormBusinessObject.php",
                $sliceDir.'/Contracts/'.Str::singular($studlyName).'FormBusinessObject.php',
            ];
            $listingDtoCandidates = [
                $sliceDir."/Contracts/{$studlyName}ListingBusinessObject.php",
                $sliceDir.'/Contracts/'.Str::singular($studlyName).'ListingBusinessObject.php',
            ];

            foreach ($fields as $field) {
                $snakeField = $field['name'];
                $type = $field['type'];
                $nullable = ! empty($field['nullable']);

                foreach ($formDtoCandidates as $fFile) {
                    if (file_exists($fFile)) {
                        $this->injectDtoProperty($fFile, $snakeField, $type, $nullable);
                        break;
                    }
                }

                foreach ($listingDtoCandidates as $lFile) {
                    if (file_exists($lFile)) {
                        $this->injectDtoProperty($lFile, $snakeField, $type, $nullable);
                        break;
                    }
                }
            }

            // 3. Model mass-assignment and service validation, so the new values are saved
            $this->addToFillable($sliceDir."/Models/{$studlyName}.php", array_column($fields, 'name'));
            $this->addValidationRules($sliceDir."/Services/{$studlyName}SliceService.php", $fields);

            // 4. Update BlatUI Blade Views
            (new BladeFieldInjector)->injectFormFields($sliceDir, $studlyName, $fields);
            (new BladeFieldInjector)->injectTableColumns($sliceDir, $studlyName, $fields);
        } else {
            // Child table: If child model exists, update fillable
            $childModelName = Str::studly(Str::singular($tableName));
            $this->addToFillable($sliceDir."/Models/{$childModelName}.php", array_column($fields, 'name'));
        }

        // 4. Update slice.json Manifest Version & Changelog
        $newVersion = self::nextPatchVersion($manifest['version'] ?? null);

        $manifest['version'] = $newVersion;
        if (! isset($manifest['fields'])) {
            $manifest['fields'] = [];
        }

        foreach ($fields as $field) {
            $snakeField = Str::snake($field['name']);
            $entry = [
                'type' => $field['type'] ?? 'string',
                'nullable' => ! empty($field['nullable']),
                'default' => $field['default'] ?? null,
                'table' => $tableName,
                'added_in' => $newVersion,
            ];
            if ($isPrimary) {
                $manifest['fields'][$snakeField] = $entry;
            } else {
                if (! isset($manifest['child_fields'])) {
                    $manifest['child_fields'] = [];
                }
                $manifest['child_fields'][$tableName][$snakeField] = $entry;
            }
        }

        if (! isset($manifest['version_history'])) {
            $manifest['version_history'] = [];
        }

        $tableLabel = $isPrimary ? '' : " ({$tableName})";
        $desc = $note ?: ('Added '.count($fields)." column(s) to {$tableName}: ".implode(', ', $fieldSummaries));
        $manifest['version_history'][] = [
            'version' => $newVersion,
            'migration' => "{$timestamp}_{$migrationSlug}.php",
            'description' => $desc,
            'author' => $author,
            'date' => date('Y-m-d H:i:s'),
        ];

        ManifestRepository::write($manifestFile, $manifest);

        return [
            'success' => true,
            'slice' => $sliceName,
            'table' => $tableName,
            'fields' => $fields,
            'version' => $newVersion,
            'migration' => $migrationFile,
            'description' => $desc,
        ];
    }

    /**
     * Add a single field (backward-compatible wrapper around addFieldsBatch)
     */
    public function addField(string $sliceName, string $fieldName, string $fieldType = 'string', bool $nullable = true, ?string $length = null, mixed $default = null, ?string $targetTable = null): array
    {
        return $this->addFieldsBatch($sliceName, [
            [
                'name' => $fieldName,
                'type' => $fieldType,
                'nullable' => $nullable,
                'length' => $length,
                'default' => $default,
            ],
        ], 'Developer', null, $targetTable);
    }

    /**
     * Construct Laravel Blueprint column definition for all supported types
     */
    protected function buildColumnDefinition(string $column, string $type, ?string $length = null, bool $nullable = true, mixed $default = null, bool $unsigned = false): string
    {
        $type = strtolower($type);

        $def = match ($type) {
            'string' => $length ? "\$table->string('{$column}', {$length})" : "\$table->string('{$column}')",
            'text' => "\$table->text('{$column}')",
            'mediumtext' => "\$table->mediumText('{$column}')",
            'longtext' => "\$table->longText('{$column}')",
            'integer', 'int' => "\$table->integer('{$column}')",
            'biginteger' => "\$table->bigInteger('{$column}')",
            'smallinteger' => "\$table->smallInteger('{$column}')",
            'tinyinteger' => "\$table->tinyInteger('{$column}')",
            'unsignedinteger' => "\$table->unsignedInteger('{$column}')",
            'unsignedbiginteger' => "\$table->unsignedBigInteger('{$column}')",
            'boolean', 'bool' => "\$table->boolean('{$column}')",
            'decimal' => $length ? "\$table->decimal('{$column}', {$length})" : "\$table->decimal('{$column}', 10, 2)",
            'float' => "\$table->float('{$column}')",
            'double' => "\$table->double('{$column}')",
            'date' => "\$table->date('{$column}')",
            'datetime' => "\$table->dateTime('{$column}')",
            'timestamp' => "\$table->timestamp('{$column}')",
            'time' => "\$table->time('{$column}')",
            'json' => "\$table->json('{$column}')",
            'uuid' => "\$table->uuid('{$column}')",
            'binary' => "\$table->binary('{$column}')",
            default => "\$table->string('{$column}')",
        };

        if ($unsigned && in_array($type, ['integer', 'biginteger', 'smallinteger', 'tinyinteger', 'decimal', 'float'])) {
            $def .= '->unsigned()';
        }

        if ($nullable) {
            $def .= '->nullable()';
        }

        if ($default !== null && $default !== '') {
            $def .= '->default('.var_export($default, true).')';
        }

        return $def;
    }

    private function normalizeMigrationFields(array $fields): array
    {
        if ($fields === [] || count($fields) > 50) {
            throw new \InvalidArgumentException('Provide between 1 and 50 fields per migration.');
        }

        $types = FieldType::COLUMN_METHODS;
        $reserved = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $normalized = [];

        foreach ($fields as $index => $field) {
            if (! is_array($field) || ! isset($field['name']) || ! is_string($field['name'])) {
                throw new \InvalidArgumentException("Field at index {$index} must include a string name.");
            }

            $name = Str::snake(trim($field['name']));
            if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) || in_array($name, $reserved, true) || isset($normalized[$name])) {
                throw new \InvalidArgumentException("Invalid, reserved, or duplicate field name '{$name}'.");
            }

            $rawType = $field['type'] ?? 'string';
            if (! is_string($rawType) || ! isset($types[strtolower($rawType)])) {
                throw new \InvalidArgumentException("Unsupported database type for field '{$name}'.");
            }
            $type = $types[strtolower($rawType)];

            $nullable = $field['nullable'] ?? true;
            if (! is_bool($nullable) && ! in_array($nullable, [0, 1, '0', '1'], true)) {
                throw new \InvalidArgumentException("Field '{$name}' nullable must be true or false.");
            }
            $unsigned = $field['unsigned'] ?? false;
            if (! is_bool($unsigned) && ! in_array($unsigned, [0, 1, '0', '1'], true)) {
                throw new \InvalidArgumentException("Field '{$name}' unsigned must be true or false.");
            }

            $length = $field['length'] ?? null;
            if ($length === '') {
                $length = null;
            }
            if ($length !== null) {
                if ($type === 'string' && preg_match('/^\d{1,5}$/', (string) $length) && (int) $length >= 1 && (int) $length <= 65535) {
                    $length = (int) $length;
                } elseif ($type === 'decimal' && preg_match('/^\d{1,2},\s*\d{1,2}$/', (string) $length)) {
                    $length = preg_replace('/\s+/', '', (string) $length);
                } else {
                    throw new \InvalidArgumentException("Field '{$name}' has an invalid length or precision.");
                }
            }

            $default = $field['default'] ?? null;
            if ($default === '') {
                $default = null;
            }
            if ($default !== null && ! is_scalar($default)) {
                throw new \InvalidArgumentException("Field '{$name}' default must be scalar or null.");
            }
            if (! FieldType::defaultMatches($type, $default)) {
                throw new \InvalidArgumentException("Field '{$name}' default does not match its database type.");
            }

            $normalized[$name] = [
                'name' => $name,
                'type' => $type,
                'length' => $length,
                'nullable' => (bool) $nullable,
                'default' => $default,
                'unsigned' => (bool) $unsigned,
            ];
        }

        return array_values($normalized);
    }

    /**
     * Add column names to a model's $fillable, whether it is written as [...] or array (...).
     */
    protected function addToFillable(string $modelFile, array $columns): void
    {
        if (! file_exists($modelFile)) {
            return;
        }

        $content = file_get_contents($modelFile);
        $pattern = '/protected\s+\$fillable\s*=\s*(?:\[(?<short>.*?)\]|array\s*\((?<long>.*?)\))\s*;/s';
        if (! preg_match($pattern, $content, $match)) {
            return;
        }

        preg_match_all("/'([A-Za-z0-9_]+)'/", ($match['short'] ?? '').($match['long'] ?? ''), $existing);
        $merged = array_values(array_unique(array_merge($existing[1], $columns)));
        if ($merged === $existing[1]) {
            return;
        }

        $list = implode('', array_map(fn ($column) => "\n        '{$column}',", $merged));
        $replacement = "protected \$fillable = [{$list}\n    ];";
        file_put_contents($modelFile, preg_replace($pattern, addcslashes($replacement, '\\$'), $content, 1), LOCK_EX);
    }

    /**
     * Add validation rules for new columns to a generated service's Validator::make([...]) call.
     */
    protected function addValidationRules(string $serviceFile, array $fields): void
    {
        if (! file_exists($serviceFile)) {
            return;
        }

        $content = file_get_contents($serviceFile);
        $anchor = '        ])->validate();';
        if (! str_contains($content, $anchor)) {
            return;
        }

        $lines = '';
        foreach ($fields as $field) {
            if (preg_match("/'".preg_quote($field['name'], '/')."'\s*=>/", $content)) {
                continue;
            }
            $lines .= "            '{$field['name']}' => ".var_export($this->validationRulesFor($field), true).",\n";
        }

        if ($lines !== '') {
            file_put_contents($serviceFile, str_replace($anchor, rtrim($lines, "\n")."\n".$anchor, $content), LOCK_EX);
        }
    }

    /**
     * Validation rules for a normalized migration field.
     *
     * @return array<int, string>
     */
    protected function validationRulesFor(array $field): array
    {
        $type = strtolower($field['type']);
        $rules = [$field['nullable'] ? 'nullable' : 'required'];

        $rules[] = match (true) {
            in_array($type, ['integer', 'biginteger', 'smallinteger', 'tinyinteger', 'unsignedinteger', 'unsignedbiginteger'], true) => 'integer',
            in_array($type, ['decimal', 'float', 'double'], true) => 'numeric',
            $type === 'boolean' => 'boolean',
            in_array($type, ['date', 'datetime', 'timestamp'], true) => 'date',
            $type === 'json' => 'array',
            $type === 'uuid' => 'uuid',
            default => 'string',
        };

        if ($type === 'string') {
            $rules[] = 'max:'.($field['length'] ?? 255);
        }

        return $rules;
    }

    /**
     * Remove column names from a model's $fillable.
     */
    protected function removeFromFillable(string $modelFile, array $columns): void
    {
        if ($columns === [] || ! file_exists($modelFile)) {
            return;
        }

        $content = file_get_contents($modelFile);
        $pattern = '/protected\s+\$fillable\s*=\s*(?:\[(?<short>.*?)\]|array\s*\((?<long>.*?)\))\s*;/s';
        if (! preg_match($pattern, $content, $match)) {
            return;
        }

        preg_match_all("/'([A-Za-z0-9_]+)'/", ($match['short'] ?? '').($match['long'] ?? ''), $existing);
        $kept = array_values(array_diff($existing[1], $columns));
        $list = implode('', array_map(fn ($column) => "\n        '{$column}',", $kept));
        file_put_contents($modelFile, preg_replace($pattern, addcslashes("protected \$fillable = [{$list}\n    ];", '\\$'), $content, 1), LOCK_EX);
    }

    /**
     * Remove validation rules for dropped columns from a generated service.
     */
    protected function removeValidationRules(string $serviceFile, array $columns): void
    {
        if ($columns === [] || ! file_exists($serviceFile)) {
            return;
        }

        $content = file_get_contents($serviceFile);
        foreach ($columns as $column) {
            // 'column' => array ( ... ), or 'column' => [...],
            $content = preg_replace("/\R[ \t]*'".preg_quote($column, '/')."'\s*=>\s*(?:array\s*\((?:[^()]|\([^()]*\))*\)|\[[^\]]*\]),/", '', $content);
        }
        file_put_contents($serviceFile, $content, LOCK_EX);
    }

    /**
     * Blueprint statement that recreates a dropped column (as nullable) in a migration's down().
     */
    protected function restoreColumnStatement(string $table, string $column, array $manifest): string
    {
        $known = $manifest['fields'][$column] ?? $manifest['child_fields'][$table][$column] ?? null;
        if ($known === null && array_is_list($manifest['fields'] ?? [])) {
            // Generated manifests list fields as [{name, type, ...}]
            foreach ($manifest['fields'] ?? [] as $entry) {
                if (($entry['name'] ?? null) === $column) {
                    $known = $entry;
                }
            }
        }
        if (is_array($known) && ! empty($known['type'])) {
            try {
                $field = $this->normalizeMigrationFields([[
                    'name' => $column,
                    'type' => $known['type'] === 'select' ? 'string' : $known['type'],
                    'length' => $known['length'] ?? null,
                    'nullable' => true,
                ]])[0];

                return $this->buildColumnDefinition($column, $field['type'], $field['length'], true, null);
            } catch (\InvalidArgumentException) {
                // fall through to the live schema
            }
        }

        try {
            foreach (Schema::getColumns($table) as $info) {
                if ($info['name'] === $column) {
                    $type = match (true) {
                        str_contains($info['type_name'], 'int') && str_contains($info['type'], '(1)') => 'boolean',
                        in_array($info['type_name'], ['bigint', 'int8'], true) => 'bigInteger',
                        str_contains($info['type_name'], 'int') => 'integer',
                        in_array($info['type_name'], ['decimal', 'numeric'], true) => 'decimal',
                        in_array($info['type_name'], ['float', 'double', 'real'], true) => 'float',
                        in_array($info['type_name'], ['bool', 'boolean'], true) => 'boolean',
                        $info['type_name'] === 'date' => 'date',
                        in_array($info['type_name'], ['datetime', 'timestamp', 'timestamptz'], true) => 'dateTime',
                        in_array($info['type_name'], ['json', 'jsonb'], true) => 'json',
                        str_contains($info['type_name'], 'text') => 'text',
                        default => 'string',
                    };

                    return $this->buildColumnDefinition($column, $type, null, true, null);
                }
            }
        } catch (\Throwable) {
            // no database connection while generating
        }

        return "\$table->text('{$column}')->nullable() /* original definition unknown */";
    }

    protected function injectDtoProperty(string $dtoFile, string $field, string $type, bool $nullable): void
    {
        if (! file_exists($dtoFile)) {
            return;
        }

        $content = file_get_contents($dtoFile);
        if (preg_match('/\$'.preg_quote($field, '/').'\b/', $content)) {
            return; // Already present ($desc must not match $description)
        }

        $phpType = $this->mapPhpType($type);
        $typePrefix = $nullable ? "?{$phpType}" : $phpType;
        $defaultVal = $nullable ? 'null' : ($phpType === 'int' ? '0' : ($phpType === 'float' ? '0.0' : ($phpType === 'bool' ? 'false' : ($phpType === 'array' ? '[]' : "''"))));
        $property = "    public {$typePrefix} \${$field} = {$defaultVal};\n";

        $pos = strrpos($content, '}');
        if ($pos !== false) {
            $newContent = substr($content, 0, $pos).$property."}\n";
            file_put_contents($dtoFile, $newContent);
        }
    }

    /**
     * Whether a route prefix is a plain URL path that is safe to write into a routes file.
     */
    public static function isSafeRoutePrefix(string $prefix): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]{0,120}$#', $prefix) && ! str_contains($prefix, '//');
    }

    /**
     * Update navigation settings in slice.json (October CMS Builder equivalent)
     */
    public function updateNavigation(string $sliceName, array $navConfig): array
    {
        $studlyName = SliceName::canonical($sliceName);
        $pluralName = Str::plural($studlyName);
        $sliceDir = $this->resolveSliceDir($pluralName);

        $manifestFile = $sliceDir.'/slice.json';
        if (! file_exists($manifestFile)) {
            throw new \RuntimeException("Slice manifest not found for [{$sliceName}]");
        }

        $manifest = ManifestRepository::read($manifestFile);
        $current = $manifest['navigation'] ?? [];
        $newUrl = ! empty($navConfig['url']) ? '/'.ltrim($navConfig['url'], '/') : ('/'.Str::snake($pluralName));
        $cleanPrefix = ltrim($newUrl, '/');

        // The prefix is written into Routes/web.php, so only plain URL path characters are allowed
        if (! self::isSafeRoutePrefix($cleanPrefix)) {
            throw new \InvalidArgumentException('The navigation URL may only contain letters, numbers, "/", "_" and "-".');
        }

        $oldUrl = $current['url'] ?? ('/'.Str::snake($pluralName));
        $oldPrefix = trim($oldUrl, '/');

        // 1. Routes first, so a failure leaves the manifest untouched
        $webRouteFile = $sliceDir.'/Routes/web.php';
        $routeContent = null;
        if ($oldPrefix !== $cleanPrefix && file_exists($webRouteFile)) {
            $routeContent = $this->moveRoutePrefix(file_get_contents($webRouteFile), $oldPrefix, $cleanPrefix, $navConfig['redirect_old'] ?? true);
        }

        // 2. Change log, compared against the values before this update
        $changes = [];
        if ($oldUrl !== $newUrl) {
            $changes[] = "Route URL changed from '{$oldUrl}' to '{$newUrl}'";
        }
        $oldTitle = $current['title'] ?? $current['label'] ?? $manifest['title'] ?? $sliceName;
        if (! empty($navConfig['title']) && $navConfig['title'] !== $oldTitle) {
            $changes[] = "Menu title changed to '{$navConfig['title']}'";
        }
        if (! empty($navConfig['icon']) && $navConfig['icon'] !== ($current['icon'] ?? '')) {
            $changes[] = "Icon changed to '{$navConfig['icon']}'";
        }
        if (isset($navConfig['order']) && (int) $navConfig['order'] !== (int) ($current['order'] ?? 10)) {
            $changes[] = "Menu order set to {$navConfig['order']}";
        }

        $defaultTitle = Str::title(Str::snake($pluralName, ' '));
        $manifest['navigation'] = array_filter([
            'label' => $navConfig['label'] ?? $navConfig['title'] ?? $current['label'] ?? $defaultTitle,
            'title' => $navConfig['title'] ?? $navConfig['label'] ?? $current['title'] ?? $defaultTitle,
            'icon' => $navConfig['icon'] ?? $current['icon'] ?? 'cube',
            'order' => (int) ($navConfig['order'] ?? $current['order'] ?? 10),
            'parent' => $navConfig['parent'] ?? $current['parent'] ?? null,
            'permission' => $navConfig['permission'] ?? $current['permission'] ?? null,
            'url' => $newUrl,
            'group' => $navConfig['group'] ?? $current['group'] ?? $manifest['domain'] ?? null,
            // Sub-menu entries are kept unless new ones are supplied
            'children' => $navConfig['children'] ?? $current['children'] ?? null,
        ], fn ($value) => $value !== null);

        if (isset($navConfig['permissions']) && is_array($navConfig['permissions'])) {
            $manifest['permissions'] = array_values(array_unique(array_filter($navConfig['permissions'])));
        }

        if (! empty($changes)) {
            $newVersion = self::nextPatchVersion($manifest['version'] ?? null);
            $manifest['version'] = $newVersion;

            $manifest['version_history'][] = [
                'version' => $newVersion,
                'type' => 'navigation_update',
                'description' => implode('; ', $changes),
                'author' => $navConfig['author'] ?? 'Developer via Navigation Studio',
                'date' => date('Y-m-d H:i:s'),
            ];
        }

        // 3. Write routes, then the manifest, then sync permissions from the saved manifest
        if ($routeContent !== null) {
            file_put_contents($webRouteFile, $routeContent, LOCK_EX);
        }
        ManifestRepository::write($manifestFile, $manifest);

        if (isset($navConfig['permissions']) && is_array($navConfig['permissions'])) {
            try {
                app(SliceManager::class)->syncPermissions();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $manifest['navigation'];
    }

    /**
     * Move a slice's routes from one URL prefix to another inside Routes/web.php.
     *
     * Every route group keeps its name, so route() calls and route:cache keep working; the
     * slug redirects follow the new URL, and a single marked 301 sends the old URL to the new one.
     */
    protected function moveRoutePrefix(string $content, string $oldPrefix, string $newPrefix, bool $redirectOld): string
    {
        $groupPattern = '/Route::prefix\(\s*([\'"])'.preg_quote($oldPrefix, '/').'\1\s*\)/';
        if (! preg_match($groupPattern, $content)) {
            throw new \RuntimeException("Routes/web.php has no route group for '/{$oldPrefix}'; update the prefix there by hand, then save the navigation again.");
        }

        $content = preg_replace($groupPattern, 'Route::prefix('.addcslashes(var_export($newPrefix, true), '\\$').')', $content);

        // Existing slug redirects point at the old URL; send them to the new one
        $content = preg_replace(
            '#(Route::redirect\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"])/'.preg_quote($oldPrefix, '#').'(/\{any\})?([\'"])#',
            '${1}/'.addcslashes($newPrefix, '\\$').'${2}${3}',
            $content
        );

        // One marked redirect from the previous URL; drop any that would now loop
        $content = preg_replace('#\R?// laraslice:navigation-redirect\R[^\r\n]*#', '', $content);
        if ($redirectOld) {
            $content = rtrim($content)."\n\n// laraslice:navigation-redirect\n"
                .'Route::redirect('.var_export($oldPrefix, true).', '.var_export('/'.$newPrefix, true).", 301);\n";
        }

        return $content;
    }

    protected function resolveSliceDir(string $pluralName): string
    {
        // 1. Direct flat path
        $appPath = rtrim($this->slicesPath, '/\\').DIRECTORY_SEPARATOR.$pluralName;
        if (is_dir($appPath)) {
            return $appPath;
        }

        // 2. Check if nested under a domain directory (e.g. Slices/Ecommerce/ShopProducts)
        $domainDirs = glob(rtrim($this->slicesPath, '/\\').'/*', GLOB_ONLYDIR) ?: [];
        foreach ($domainDirs as $domainDir) {
            $candidate = $domainDir.DIRECTORY_SEPARATOR.$pluralName;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        // 3. Core slices ship inside the package; changes there would be lost on composer update
        if (is_dir(dirname(__DIR__).'/Slices/'.$pluralName)) {
            throw new \RuntimeException("[{$pluralName}] is a core LaraSlice slice inside the package and cannot be modified from the studio.");
        }

        return $appPath;
    }

    protected function mapPhpType(string $fieldType): string
    {
        return match (strtolower($fieldType)) {
            'integer', 'int', 'biginteger', 'smallinteger', 'tinyinteger', 'unsignedinteger', 'unsignedbiginteger' => 'int',
            'decimal', 'float', 'double' => 'float',
            'boolean', 'bool' => 'bool',
            'array', 'json' => 'array',
            default => 'string',
        };
    }

    /**
     * Add a child table / entity to an existing slice with foreign key relationship.
     *
     * @param  string  $sliceName  e.g. "Products"
     * @param  string  $tableName  e.g. "product_images" or "variants"
     * @param  string  $relationType  e.g. "hasMany", "belongsTo", "belongsToMany"
     * @param  array  $fields  Array of column definitions
     * @param  string|null  $foreignKey  e.g. "product_id"
     */
    /**
     * Add a child table to a slice. All-or-nothing: if any step fails, every file in the
     * slice folder is restored to its previous state and new files are removed.
     */
    public function addChildTable(string $sliceName, string $tableName, string $relationType = 'hasMany', array $fields = [], ?string $foreignKey = null): array
    {
        $definition = ChildEntityDefinition::normalize($sliceName, $tableName, $relationType, $foreignKey, $fields);
        $sliceDir = $this->resolveSliceDir($definition['plural_slice']);

        return $this->withSliceRollback($sliceDir, fn () => $this->performAddChildTable($sliceName, $tableName, $relationType, $fields, $foreignKey));
    }

    /**
     * Run $change and restore the slice folder exactly as it was if it throws.
     */
    protected function withSliceRollback(string $sliceDir, callable $change): mixed
    {
        $snapshot = [];
        if (is_dir($sliceDir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sliceDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $snapshot[$file->getPathname()] = file_get_contents($file->getPathname());
                }
            }
        }

        try {
            return $change();
        } catch (\Throwable $e) {
            if (is_dir($sliceDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($sliceDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $entry) {
                    $path = $entry->getPathname();
                    if ($entry->isDir()) {
                        @rmdir($path); // only succeeds for folders left empty
                    } elseif (! array_key_exists($path, $snapshot)) {
                        @unlink($path);
                    }
                }
            }
            foreach ($snapshot as $path => $contents) {
                if (! is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0755, true);
                }
                if (! file_exists($path) || file_get_contents($path) !== $contents) {
                    file_put_contents($path, $contents, LOCK_EX);
                }
            }

            throw $e;
        }
    }

    protected function performAddChildTable(string $sliceName, string $tableName, string $relationType = 'hasMany', array $fields = [], ?string $foreignKey = null): array
    {
        $definition = ChildEntityDefinition::normalize($sliceName, $tableName, $relationType, $foreignKey, $fields);
        $studlyName = $definition['slice'];
        $pluralSlice = $definition['plural_slice'];
        $sliceDir = $this->resolveSliceDir($pluralSlice);
        $parentTable = Str::plural(Str::snake($studlyName));
        $parentModelName = Str::studly(Str::singular($parentTable));
        $childTable = $definition['child_table'];
        $childModelName = Str::studly(Str::singular($childTable));
        $foreignKey = $definition['foreign_key'];
        $fields = $definition['fields'];
        $relationMethod = Str::camel(Str::plural($childModelName));

        if (! is_dir($sliceDir)) {
            throw new \RuntimeException("Slice directory for [{$sliceName}] not found at {$sliceDir}");
        }

        $manifestFile = "{$sliceDir}/slice.json";
        $manifest = file_exists($manifestFile)
            ? ManifestRepository::read($manifestFile)
            : [];
        if (in_array($childTable, $manifest['tables'] ?? [], true)) {
            throw new \InvalidArgumentException("Child table '{$childTable}' is already declared in this slice.");
        }

        $routeName = Str::snake(Str::plural($childModelName));
        $parentRouteName = Str::snake($parentTable);
        $webRoutesFile = "{$sliceDir}/Routes/web.php";
        $apiRoutesFile = "{$sliceDir}/Routes/api.php";

        $domain = $manifest['domain'] ?? $manifest['navigation']['group'] ?? null;
        $domainSlug = $domain ? Str::slug($domain) : null;
        if (! $domainSlug && file_exists($webRoutesFile)) {
            $webContent = file_get_contents($webRoutesFile);
            if (preg_match("/Route::prefix\(['\"]([^'\"]+)\/{$parentRouteName}['\"]\)/", $webContent, $m)) {
                $domainSlug = trim($m[1], '/');
            }
        }
        $parentUrlPrefix = $domainSlug ? "{$domainSlug}/{$parentRouteName}" : $parentRouteName;

        $outputFiles = [
            "{$sliceDir}/Models/{$childModelName}.php",
            "{$sliceDir}/Contracts/{$childModelName}FormBusinessObject.php",
            "{$sliceDir}/Contracts/{$childModelName}ListingBusinessObject.php",
            "{$sliceDir}/Contracts/{$childModelName}FilterBusinessObject.php",
            "{$sliceDir}/Services/{$childModelName}SliceService.php",
            "{$sliceDir}/Controllers/{$childModelName}WebController.php",
            "{$sliceDir}/Controllers/{$childModelName}ApiController.php",
            "{$sliceDir}/Resources/views/{$routeName}",
        ];
        foreach ($outputFiles as $path) {
            if (file_exists($path)) {
                throw new \InvalidArgumentException("Child entity output already exists at {$path}; no files were changed.");
            }
        }

        if (glob("{$sliceDir}/Migrations/*_create_{$childTable}_table.php")) {
            throw new \InvalidArgumentException("A migration for '{$childTable}' already exists.");
        }

        if (file_exists($webRoutesFile) && (
            str_contains(file_get_contents($webRoutesFile), "Route::resource('{$routeName}'")
            || str_contains(file_get_contents($webRoutesFile), "Route::resource('{$childTable}'")
        )) {
            throw new \InvalidArgumentException("Web routes for '{$routeName}' already exist.");
        }
        if (file_exists($apiRoutesFile) && (
            str_contains(file_get_contents($apiRoutesFile), "prefix('{$parentRouteName}/{parentId}/{$routeName}')")
            || str_contains(file_get_contents($apiRoutesFile), "prefix('{$parentUrlPrefix}/{parentId}/{$routeName}')")
        )) {
            throw new \InvalidArgumentException("API routes for '{$routeName}' already exist.");
        }

        $parentModelFile = "{$sliceDir}/Models/{$parentModelName}.php";
        if (! file_exists($parentModelFile)) {
            throw new \RuntimeException("Parent model [{$parentModelName}] is required before adding child entities.");
        }
        if (file_exists($parentModelFile) && str_contains(file_get_contents($parentModelFile), "function {$relationMethod}")) {
            throw new \InvalidArgumentException("Parent relationship method '{$relationMethod}' already exists.");
        }
        foreach ([$webRoutesFile, $apiRoutesFile] as $routesFile) {
            if (! file_exists($routesFile)) {
                throw new \RuntimeException("Slice route file is missing: {$routesFile}");
            }
        }

        // 1. Generate Migration for Child Table
        $timestamp = $this->nextMigrationTimestamp($sliceDir);
        $migrationFile = "{$sliceDir}/Migrations/{$timestamp}_create_{$childTable}_table.php";

        $colDefs = [];
        foreach ($fields as $f) {
            $name = $f['name'];
            $type = $f['type'];
            $nullable = $f['nullable'] ? '->nullable()' : '';
            $default = $f['default'] !== null ? '->default('.var_export($f['default'], true).')' : '';
            $colDefs[] = "                \$table->{$type}('{$name}'){$nullable}{$default};";
        }
        $colsString = count($colDefs) > 0 ? implode("\n", $colDefs)."\n" : '';

        $migrationCode = <<<MIG
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$childTable}', function (Blueprint \$table) {
                \$table->id();
                \$table->foreignId('{$foreignKey}')->constrained('{$parentTable}')->cascadeOnDelete();
{$colsString}                \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$childTable}');
    }
};
MIG;
        file_put_contents($migrationFile, $migrationCode);

        $baseSliceNamespace = $this->namespace;
        if (! empty($manifest['namespace'])) {
            $nsParts = explode('\\'.$pluralSlice, $manifest['namespace']);
            if (isset($nsParts[0]) && $nsParts[0] !== '') {
                $baseSliceNamespace = $nsParts[0];
            }
        } elseif (file_exists($parentModelFile)) {
            $parentModelContent = file_get_contents($parentModelFile);
            if (preg_match('/namespace\s+([^;]+)\\\\Models;/', $parentModelContent, $m)) {
                $nsParts = explode('\\'.$pluralSlice, $m[1]);
                if (isset($nsParts[0]) && $nsParts[0] !== '') {
                    $baseSliceNamespace = $nsParts[0];
                }
            }
        }

        // 2. Generate Child Eloquent Model
        $childModelFile = "{$sliceDir}/Models/{$childModelName}.php";
        $parentRelation = Str::camel(Str::singular($parentTable));
        $fillable = var_export(array_merge([$foreignKey], array_column($fields, 'name')), true);
        $modelCode = <<<MOD
<?php

namespace {$baseSliceNamespace}\\{$pluralSlice}\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class {$childModelName} extends Model
{
    protected \$table = '{$childTable}';
    protected \$fillable = {$fillable};

    public function {$parentRelation}(): BelongsTo
    {
        return \$this->belongsTo({$parentModelName}::class, '{$foreignKey}');
    }
}
MOD;
        file_put_contents($childModelFile, $modelCode, LOCK_EX);

        // 3. Inject relationship into Parent Model if not exists
        if (file_exists($parentModelFile)) {
            $parentModelContent = file_get_contents($parentModelFile);
            if (! str_contains($parentModelContent, "function {$relationMethod}")) {
                $hasManySnippet = <<<REL

    public function {$relationMethod}(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return \$this->hasMany({$childModelName}::class, '{$foreignKey}');
    }
REL;
                $parentModelContent = preg_replace('/\}\s*$/', $hasManySnippet."\n}\n", $parentModelContent);
                file_put_contents($parentModelFile, $parentModelContent);
            }
        }

        // 4. Update manifest slice.json
        if (! isset($manifest['tables'])) {
            $manifest['tables'] = [$parentTable];
        }
        if (! in_array($childTable, $manifest['tables'])) {
            $manifest['tables'][] = $childTable;
        }
        if (! isset($manifest['relations'])) {
            $manifest['relations'] = [];
        }
        $manifest['relations'] = array_values(array_filter($manifest['relations'], fn ($relation) => ($relation['table'] ?? null) !== $childTable));
        $manifest['relations'][] = [
            'type' => $relationType,
            'table' => $childTable,
            'model' => $childModelName,
            'foreign_key' => $foreignKey,
            'method' => $relationMethod,
        ];
        ManifestRepository::write($manifestFile, $manifest);

        $childGenerator = new ChildEntityGenerator;
        $childGenerator->generate($sliceDir, $baseSliceNamespace, $pluralSlice, $childTable, $parentTable, $foreignKey, $fields);
        $childModelClass = "{$baseSliceNamespace}\\{$pluralSlice}\\Models\\{$childModelName}";
        $this->injectChildRelationLink($sliceDir, $parentRouteName, $routeName, $childModelName, $parentTable, $childModelClass, $foreignKey);

        $apiRoutes = file_get_contents($apiRoutesFile);
        $apiUse = "use {$baseSliceNamespace}\\{$pluralSlice}\\Controllers\\{$childModelName}ApiController;";
        if (! str_contains($apiRoutes, $apiUse)) {
            $apiRoutes = preg_replace('/(use Illuminate\\\\Support\\\\Facades\\\\Route;)/', "$1\n{$apiUse}", $apiRoutes, 1);
        }
        $apiRoutes .= "\nRoute::prefix('{$parentUrlPrefix}/{parentId}/{$routeName}')->name('{$parentRouteName}.{$routeName}.')->middleware(config('laraslice.generated_routes.api_middleware', ['api', 'auth:sanctum']))->group(function () {\n"
            ."    Route::post('/list', [{$childModelName}ApiController::class, 'getList'])->name('list');\n"
            ."    Route::get('/{id}', [{$childModelName}ApiController::class, 'getItemById'])->name('show');\n"
            ."    Route::post('/save', [{$childModelName}ApiController::class, 'save'])->name('save');\n"
            ."    Route::delete('/{id}', [{$childModelName}ApiController::class, 'delete'])->name('delete');\n"
            ."});\n";
        file_put_contents($apiRoutesFile, $apiRoutes, LOCK_EX);

        return [
            'child_table' => $childTable,
            'child_model' => $childModelName,
            'migration_file' => $migrationFile,
            'foreign_key' => $foreignKey,
            'parent_table' => $parentTable,
        ];
    }

    private function injectChildRelationLink(string $sliceDir, string $parentRouteName, string $childRouteName, string $childName, string $parentTable, ?string $childModelClass = null, ?string $foreignKey = null): void
    {
        $route = "{$parentRouteName}.{$childRouteName}.index";
        $parentLabel = Str::headline(Str::singular($parentTable));
        $childSingularLabel = Str::singular(Str::headline(Str::singular($childName)));
        $childLabel = Str::plural(Str::headline(Str::singular($childName)));
        $fk = $foreignKey ?? Str::snake(Str::singular($parentTable)).'_id';
        $cls = $childModelClass ? '\\'.ltrim($childModelClass, '\\') : "App\\Models\\{$childName}";

        $link = "\n@if (!\$isNew && \\Illuminate\\Support\\Facades\\Route::has('{$route}'))\n"
            ."    @php\n"
            ."        \$childRecords = null;\n"
            ."        try {\n"
            ."            if (class_exists('{$cls}')) {\n"
            ."                \$childRecords = {$cls}::where('{$fk}', \$form->id)->latest()->take(10)->get();\n"
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
            ."                        Associated {$childLabel}\n"
            ."                        <x-ui.badge variant=\"secondary\" class=\"text-xs font-mono\">{{ \$childCount }}</x-ui.badge>\n"
            ."                    </h3>\n"
            ."                    <p class=\"text-xs text-muted-foreground\">Manage records directly linked to this {$parentLabel}</p>\n"
            ."                </div>\n"
            ."            </div>\n"
            ."            <div class=\"flex items-center gap-2\">\n"
            ."                @if (\\Illuminate\\Support\\Facades\\Route::has('{$parentRouteName}.{$childRouteName}.create'))\n"
            ."                    <x-ui.button href=\"{{ route('{$parentRouteName}.{$childRouteName}.create', ['parentId' => \$form->id]) }}\" as=\"a\" size=\"sm\" class=\"bg-primary text-primary-foreground font-semibold shadow-xs\">\n"
            ."                        <x-lucide-plus class=\"size-3.5 mr-1\" /> Add {$childSingularLabel}\n"
            ."                    </x-ui.button>\n"
            ."                @endif\n"
            ."                <x-ui.button href=\"{{ route('{$route}', ['parentId' => \$form->id]) }}\" as=\"a\" variant=\"outline\" size=\"sm\" class=\"text-xs\">\n"
            ."                    View All <x-lucide-arrow-up-right class=\"size-3.5 ml-1\" />\n"
            ."                </x-ui.button>\n"
            ."            </div>\n"
            ."        </div>\n\n"
            ."        @if (\$childRecords && count(\$childRecords) > 0)\n"
            ."            @php\n"
            ."                \$childColumns = [['key' => 'name', 'label' => 'Name'], ['key' => 'details', 'label' => 'Details']];\n"
            ."                \$childEditRoute = '{$parentRouteName}.{$childRouteName}.edit';\n"
            ."                \$childRows = collect(\$childRecords)->map(fn (\$item) => [\n"
            ."                    'id' => \$item->id,\n"
            ."                    'name' => (string) (\$item->name ?? (\$item->first_name ? \$item->first_name . ' ' . (\$item->last_name ?? '') : (\$item->title ?? '#' . \$item->id))),\n"
            ."                    'details' => (string) (\$item->email ?? \$item->job_title ?? \$item->phone ?? \$item->status ?? '—'),\n"
            ."                    'edit_url' => \\Illuminate\\Support\\Facades\\Route::has(\$childEditRoute) ? route(\$childEditRoute, ['parentId' => \$form->id, 'id' => \$item->id]) : null,\n"
            ."                ])->values()->all();\n"
            ."            @endphp\n"
            ."            <x-ui.data-table :columns=\"\$childColumns\" :rows=\"\$childRows\" :page-size=\"5\" :selectable=\"false\" search-placeholder=\"Filter {$childLabel}...\">\n"
            ."                <x-slot:actions>\n"
            ."                    <x-ui.button as=\"a\" ::href=\"item.r.edit_url\" x-show=\"item.r.edit_url\" variant=\"ghost\" size=\"sm\">\n"
            ."                        <x-lucide-pencil class=\"size-3.5\" /> Edit\n"
            ."                    </x-ui.button>\n"
            ."                </x-slot:actions>\n"
            ."            </x-ui.data-table>\n"
            ."        @else\n"
            ."            <div class=\"rounded-xl border border-dashed border-border/80 p-6 text-center bg-muted/10\">\n"
            ."                <div class=\"flex flex-col items-center justify-center gap-1.5\">\n"
            ."                    <x-lucide-layers class=\"size-6 text-muted-foreground/40\" />\n"
            ."                    <p class=\"text-xs font-medium text-foreground\">No {$childLabel} linked yet</p>\n"
            ."                    <p class=\"text-[11px] text-muted-foreground\">Add records associated with this {$parentLabel}</p>\n"
            ."                    @if (\\Illuminate\\Support\\Facades\\Route::has('{$parentRouteName}.{$childRouteName}.create'))\n"
            ."                        <x-ui.button href=\"{{ route('{$parentRouteName}.{$childRouteName}.create', ['parentId' => \$form->id]) }}\" as=\"a\" size=\"sm\" variant=\"outline\" class=\"mt-2 text-xs\">\n"
            ."                            <x-lucide-plus class=\"size-3 mr-1\" /> Add First {$childSingularLabel}\n"
            ."                        </x-ui.button>\n"
            ."                    @endif\n"
            ."                </div>\n"
            ."            </div>\n"
            ."        @endif\n"
            ."    </div>\n"
            ."@endif\n";

        foreach (['form.blade.php', 'create.blade.php', 'edit.blade.php'] as $viewName) {
            $viewFile = "{$sliceDir}/Resources/views/{$viewName}";
            if (! is_file($viewFile)) {
                continue;
            }

            $content = file_get_contents($viewFile);
            $legacyPattern = '/@if \(!\$isNew && \\\\Illuminate\\\\Support\\\\Facades\\\\Route::has\(\''.preg_quote($route, '/').'\'\)\)[\s\S]*?@endif/m';
            if (preg_match($legacyPattern, $content)) {
                $content = preg_replace($legacyPattern, trim($link), $content, 1);
                file_put_contents($viewFile, $content, LOCK_EX);
            } elseif (! str_contains($content, $route) && str_contains($content, '</x-ui.card-content>')) {
                $content = preg_replace('/<\\/x-ui\\.card-content>/', $link."\n            </x-ui.card-content>", $content, 1);
                file_put_contents($viewFile, $content, LOCK_EX);
            }
        }
    }

    private function nextMigrationTimestamp(string $sliceDir): string
    {
        $timestamp = date('Y_m_d_His');
        $latest = null;
        foreach (glob($sliceDir.'/Migrations/*.php') ?: [] as $migration) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($migration), $matches) === 1) {
                $latest = $latest === null || $matches[1] > $latest ? $matches[1] : $latest;
            }
        }

        if ($latest !== null && $timestamp <= $latest) {
            $latestDate = \DateTimeImmutable::createFromFormat('!Y_m_d_His', $latest);
            if ($latestDate !== false) {
                $timestamp = $latestDate->modify('+1 second')->format('Y_m_d_His');
            }
        }

        return $timestamp;
    }

    /**
     * Rollback a version or restore to a specific target version in slice history.
     */
    public function rollbackVersion(string $sliceName, string $targetVersion): array
    {
        $studlyName = SliceName::canonical($sliceName);
        $pluralName = Str::plural($studlyName);
        $sliceDir = $this->resolveSliceDir($pluralName);

        if (! is_dir($sliceDir)) {
            throw new \RuntimeException("Slice directory for [{$sliceName}] not found at {$sliceDir}");
        }

        $manifestFile = $sliceDir.'/slice.json';
        if (! file_exists($manifestFile)) {
            throw new \RuntimeException("Slice manifest not found for [{$sliceName}]");
        }

        $manifest = ManifestRepository::read($manifestFile);
        $history = $manifest['version_history'] ?? [];

        if (empty($history)) {
            throw new \RuntimeException("No version history available to rollback for [{$sliceName}].");
        }

        // If target version matches current version, target becomes the entry right before it
        $currentVersion = $manifest['version'] ?? '1.0.0';
        if ($targetVersion === $currentVersion && count($history) > 1) {
            $targetVersion = $history[count($history) - 2]['version'];
        }

        // Find items to undo (any items strictly after the target version)
        $targetFound = false;
        $undoneItems = [];
        $keptHistory = [];

        foreach ($history as $h) {
            if (! $targetFound) {
                $keptHistory[] = $h;
                if ($h['version'] === $targetVersion) {
                    $targetFound = true;
                }
            } else {
                $undoneItems[] = $h;
            }
        }

        // If target not found in linear order, undo the latest item
        if (! $targetFound) {
            $undoneItems = [array_pop($history)];
            $keptHistory = $history;
            $targetVersion = ! empty($keptHistory) ? end($keptHistory)['version'] : '1.0.0';
        }

        // Execute rollback for undone items (in reverse)
        $revertedMigrations = [];
        foreach (array_reverse($undoneItems) as $item) {
            // 1. Revert migration if exists
            if (! empty($item['migration'])) {
                // The name comes from slice.json; only plain migration file names inside Migrations/
                if (! is_string($item['migration']) || ! preg_match('/^[A-Za-z0-9_]+\.php$/', $item['migration'])) {
                    throw new \RuntimeException('Version history names an invalid migration file; nothing was rolled back.');
                }
                $migFile = $sliceDir.'/Migrations/'.$item['migration'];
                if (file_exists($migFile)) {
                    try {
                        $migrationInstance = require $migFile;
                        if (is_object($migrationInstance) && method_exists($migrationInstance, 'down')) {
                            $migrationInstance->down();
                            $revertedMigrations[] = $item['migration'];
                        }
                        $migrationName = pathinfo($item['migration'], PATHINFO_FILENAME);
                        if (Schema::hasTable('migrations')) {
                            DB::table('migrations')->where('migration', $migrationName)->delete();
                        }
                    } catch (\Throwable $e) {
                        // Stop here: the manifest must not claim a version the database is not at
                        $done = $revertedMigrations === [] ? 'nothing was reverted' : 'already reverted: '.implode(', ', $revertedMigrations);
                        throw new \RuntimeException("Rolling back {$item['migration']} failed ({$done}): ".$e->getMessage(), 0, $e);
                    }
                }

                // Clean up fields added in this version
                if (! empty($manifest['fields'])) {
                    foreach ($manifest['fields'] as $fName => $fData) {
                        if (($fData['added_in'] ?? null) === $item['version']) {
                            unset($manifest['fields'][$fName]);
                        }
                    }
                }
                if (! empty($manifest['child_fields'])) {
                    foreach ($manifest['child_fields'] as $tbl => $tblFields) {
                        foreach ($tblFields as $fName => $fData) {
                            if (($fData['added_in'] ?? null) === $item['version']) {
                                unset($manifest['child_fields'][$tbl][$fName]);
                            }
                        }
                    }
                }
            }

            // 2. Revert navigation / route if it was a navigation update
            if (($item['type'] ?? '') === 'navigation_update' || str_contains($item['description'] ?? '', 'Route URL changed')) {
                if (! empty($item['previous_config'])) {
                    $manifest['navigation'] = array_merge($manifest['navigation'] ?? [], $item['previous_config']);
                } elseif (preg_match("/Route URL changed from '([^']+)' to '([^']+)'/", $item['description'] ?? '', $rMatches)) {
                    $manifest['navigation']['url'] = $rMatches[1];
                }
            }
        }

        // Add rollback entry
        $keptHistory[] = [
            'version' => $targetVersion,
            'type' => 'rollback',
            'description' => "Restored slice to v{$targetVersion} (undid ".count($undoneItems).' modification(s))',
            'author' => 'Developer via Version History',
            'date' => date('Y-m-d H:i:s'),
        ];

        $manifest['version'] = $targetVersion;
        $manifest['version_history'] = $keptHistory;

        ManifestRepository::write($manifestFile, $manifest);

        return [
            'success' => true,
            'version' => $targetVersion,
            'reverted_migrations' => $revertedMigrations,
            'message' => "Successfully restored [{$sliceName}] to v{$targetVersion}!",
        ];
    }

    /**
     * Synchronize schema fields for a slice table (add new fields, drop deleted fields, and update manifest metadata).
     */
    public function syncFields(string $sliceName, string $targetTable, array $newFields = [], array $deletedFields = [], array $allFields = [], string $author = 'Developer'): array
    {
        $studlyName = SliceName::canonical($sliceName);
        $pluralName = Str::plural($studlyName);
        $tableName = Str::snake($targetTable);
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $tableName)) {
            throw new \InvalidArgumentException('Target table must be a lowercase snake_case identifier.');
        }
        $sliceDir = $this->resolveSliceDir($pluralName);

        if (! is_dir($sliceDir)) {
            throw new \RuntimeException("Slice directory for [{$sliceName}] not found at {$sliceDir}");
        }

        $manifestFile = $sliceDir.'/slice.json';
        $manifest = file_exists($manifestFile) ? ManifestRepository::read($manifestFile) : [];

        $isPrimary = (! $targetTable || $targetTable === Str::plural(Str::snake($studlyName)));
        $upStatements = [];
        $downStatements = [];
        $actionsSummary = [];

        // 1. Process New Fields
        $normalizedNewFields = [];
        if (! empty($newFields)) {
            $normalizedNewFields = $this->normalizeMigrationFields($newFields);
            foreach ($normalizedNewFields as $field) {
                $snakeField = $field['name'];
                $type = $field['type'];
                $nullable = ! empty($field['nullable']);
                $length = $field['length'] ?? null;
                $default = $field['default'] ?? null;
                $unsigned = ! empty($field['unsigned']);

                $definition = $this->buildColumnDefinition($snakeField, $type, $length, $nullable, $default, $unsigned);
                $upStatements[] = "            {$definition};";
                $downStatements[] = "            \$table->dropColumn('{$snakeField}');";
                $actionsSummary[] = "Added {$snakeField} ({$type})";
            }
        }

        // 2. Process Deleted Fields
        $validatedDeletedFields = [];
        $protectedColumns = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'];
        if ($isPrimary) {
            // The generated model, service and views depend on these
            $protectedColumns = array_merge($protectedColumns, ['title', 'description', 'status']);
        }
        if (! empty($deletedFields)) {
            foreach ($deletedFields as $delCol) {
                $delCol = Str::snake(trim((string) $delCol));
                if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $delCol)) {
                    throw new \InvalidArgumentException("Invalid column name '{$delCol}'.");
                }
                if (in_array($delCol, $protectedColumns, true) || str_ends_with($delCol, '_id') && $delCol === Str::singular(Str::snake($studlyName)).'_id') {
                    throw new \InvalidArgumentException("Column '{$delCol}' is required by the generated slice and cannot be dropped.");
                }
                $validatedDeletedFields[] = $delCol;
                $upStatements[] = "            \$table->dropColumn('{$delCol}');";
                // Data cannot be restored, but rolling back recreates the column (nullable)
                $downStatements[] = '            '.$this->restoreColumnStatement($tableName, $delCol, $manifest).';';
                $actionsSummary[] = "Dropped {$delCol}";
            }
        }

        // 3. Generate Consolidated Migration if any schema changes
        $migrationFile = null;
        $timestamp = date('Y_m_d_His');
        if (! empty($upStatements)) {
            $migrationSlug = "update_{$tableName}_table_sync_schema";
            $migrationFile = $sliceDir."/Migrations/{$timestamp}_{$migrationSlug}.php";
            if (file_exists($migrationFile)) {
                throw new \RuntimeException("Migration already exists at {$migrationFile}; retry after the current second.");
            }

            $upBody = implode("\n", $upStatements);
            $downBody = ! empty($downStatements) ? implode("\n", $downStatements) : '            // Revert changes';

            $migrationContent = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
{$upBody}
        });
    }

    public function down(): void
    {
        Schema::table('{$tableName}', function (Blueprint \$table) {
{$downBody}
        });
    }
};
PHP;
            if (file_put_contents($migrationFile, $migrationContent, LOCK_EX) === false) {
                throw new \RuntimeException("Unable to write generated migration: {$migrationFile}");
            }

            // Update DTOs and BlatUI views for new fields if primary
            if ($isPrimary && ! empty($normalizedNewFields)) {
                $formDtoCandidates = [
                    $sliceDir."/Contracts/{$studlyName}FormBusinessObject.php",
                    $sliceDir.'/Contracts/'.Str::singular($studlyName).'FormBusinessObject.php',
                ];
                $listingDtoCandidates = [
                    $sliceDir."/Contracts/{$studlyName}ListingBusinessObject.php",
                    $sliceDir.'/Contracts/'.Str::singular($studlyName).'ListingBusinessObject.php',
                ];

                foreach ($normalizedNewFields as $field) {
                    $snakeField = $field['name'];
                    $type = $field['type'];
                    $nullable = ! empty($field['nullable']);

                    foreach ($formDtoCandidates as $fFile) {
                        if (file_exists($fFile)) {
                            $this->injectDtoProperty($fFile, $snakeField, $type, $nullable);
                            break;
                        }
                    }

                    foreach ($listingDtoCandidates as $lFile) {
                        if (file_exists($lFile)) {
                            $this->injectDtoProperty($lFile, $snakeField, $type, $nullable);
                            break;
                        }
                    }
                }

                (new BladeFieldInjector)->injectFormFields($sliceDir, $studlyName, $normalizedNewFields);
                (new BladeFieldInjector)->injectTableColumns($sliceDir, $studlyName, $normalizedNewFields);
            }
        }

        // Model mass assignment and validation follow the schema change
        $modelFile = $sliceDir.'/Models/'.($isPrimary ? $studlyName : Str::studly(Str::singular($tableName))).'.php';
        $this->addToFillable($modelFile, array_column($normalizedNewFields, 'name'));
        $this->removeFromFillable($modelFile, $validatedDeletedFields);
        if ($isPrimary) {
            $serviceFile = $sliceDir."/Services/{$studlyName}SliceService.php";
            $this->addValidationRules($serviceFile, $normalizedNewFields);
            $this->removeValidationRules($serviceFile, $validatedDeletedFields);
        }

        // 4. Update slice.json Metadata
        $newVersion = self::nextPatchVersion($manifest['version'] ?? null);
        $manifest['version'] = $newVersion;

        if ($isPrimary) {
            if (! isset($manifest['fields'])) {
                $manifest['fields'] = [];
            }
            foreach ($validatedDeletedFields as $delCol) {
                unset($manifest['fields'][$delCol]);
            }
            foreach ($allFields as $f) {
                $handle = Str::snake($f['handle'] ?? $f['name'] ?? '');
                if ($handle && ! in_array($handle, $validatedDeletedFields, true)) {
                    $existingAddedIn = $manifest['fields'][$handle]['added_in'] ?? null;
                    $manifest['fields'][$handle] = [
                        'label' => $f['label'] ?? Str::title(str_replace('_', ' ', $handle)),
                        'type' => $f['type'] ?? 'string',
                        'width' => (int) ($f['width'] ?? 50),
                        'required' => ! empty($f['required']),
                        'nullable' => ! empty($f['nullable']),
                        'hidden' => ! empty($f['hidden']),
                        'length' => $f['length'] ?? null,
                        'default' => $f['default'] ?? null,
                        'added_in' => $existingAddedIn ?? $newVersion,
                    ];
                }
            }
            foreach ($normalizedNewFields as $nf) {
                $handle = Str::snake($nf['name'] ?? '');
                if ($handle && ! isset($manifest['fields'][$handle])) {
                    $manifest['fields'][$handle] = [
                        'label' => Str::title(str_replace('_', ' ', $handle)),
                        'type' => $nf['type'] ?? 'string',
                        'width' => (int) ($nf['width'] ?? 50),
                        'required' => empty($nf['nullable']),
                        'nullable' => ! empty($nf['nullable']),
                        'length' => $nf['length'] ?? null,
                        'default' => $nf['default'] ?? null,
                        'added_in' => $newVersion,
                    ];
                } elseif ($handle && isset($manifest['fields'][$handle])) {
                    if (empty($manifest['fields'][$handle]['added_in'])) {
                        $manifest['fields'][$handle]['added_in'] = $newVersion;
                    }
                }
            }
        } else {
            if (! isset($manifest['child_fields'])) {
                $manifest['child_fields'] = [];
            }
            if (! isset($manifest['child_fields'][$tableName])) {
                $manifest['child_fields'][$tableName] = [];
            }
            foreach ($validatedDeletedFields as $delCol) {
                unset($manifest['child_fields'][$tableName][$delCol]);
            }
            foreach ($allFields as $f) {
                $handle = Str::snake($f['handle'] ?? $f['name'] ?? '');
                if ($handle && ! in_array($handle, $validatedDeletedFields, true)) {
                    $existingAddedIn = $manifest['child_fields'][$tableName][$handle]['added_in'] ?? null;
                    $manifest['child_fields'][$tableName][$handle] = [
                        'label' => $f['label'] ?? Str::title(str_replace('_', ' ', $handle)),
                        'type' => $f['type'] ?? 'string',
                        'width' => (int) ($f['width'] ?? 50),
                        'required' => ! empty($f['required']),
                        'nullable' => ! empty($f['nullable']),
                        'length' => $f['length'] ?? null,
                        'default' => $f['default'] ?? null,
                        'added_in' => $existingAddedIn ?? $newVersion,
                    ];
                }
            }
            foreach ($normalizedNewFields as $nf) {
                $handle = Str::snake($nf['name'] ?? '');
                if ($handle && ! isset($manifest['child_fields'][$tableName][$handle])) {
                    $manifest['child_fields'][$tableName][$handle] = [
                        'label' => Str::title(str_replace('_', ' ', $handle)),
                        'type' => $nf['type'] ?? 'string',
                        'width' => (int) ($nf['width'] ?? 50),
                        'required' => empty($nf['nullable']),
                        'nullable' => ! empty($nf['nullable']),
                        'length' => $nf['length'] ?? null,
                        'default' => $nf['default'] ?? null,
                        'added_in' => $newVersion,
                    ];
                } elseif ($handle && isset($manifest['child_fields'][$tableName][$handle])) {
                    if (empty($manifest['child_fields'][$tableName][$handle]['added_in'])) {
                        $manifest['child_fields'][$tableName][$handle]['added_in'] = $newVersion;
                    }
                }
            }
        }

        // Version History
        if (! isset($manifest['version_history'])) {
            $manifest['version_history'] = [];
        }
        $desc = ! empty($actionsSummary)
            ? "Schema synchronized on {$tableName}: ".implode('; ', $actionsSummary)
            : "Field layout and display options updated for {$tableName}";

        $manifest['version_history'][] = [
            'version' => $newVersion,
            'type' => 'schema_sync',
            'migration' => $migrationFile ? basename($migrationFile) : null,
            'description' => $desc,
            'author' => $author,
            'date' => date('Y-m-d H:i:s'),
        ];

        ManifestRepository::write($manifestFile, $manifest);

        return [
            'success' => true,
            'slice' => $sliceName,
            'table' => $tableName,
            'version' => $newVersion,
            'migration' => $migrationFile,
            'description' => $desc,
        ];
    }

    /**
     * Save relationships to slice.json and inject/update Eloquent relation methods on models.
     */
    public function saveRelationships(string $sliceName, array $relations, string $author = 'Developer'): array
    {
        $studlyName = SliceName::canonical($sliceName);
        $pluralName = Str::plural($studlyName);
        $sliceDir = $this->resolveSliceDir($pluralName);

        if (! is_dir($sliceDir)) {
            throw new \RuntimeException("Slice directory for [{$sliceName}] not found at {$sliceDir}");
        }

        $manifestFile = $sliceDir.'/slice.json';
        $manifest = file_exists($manifestFile) ? ManifestRepository::read($manifestFile) : [];

        $cleanedRelations = [];
        foreach ($relations as $rel) {
            $src = Str::snake((string) ($rel['source_model'] ?? ''));
            $type = (string) ($rel['type'] ?? 'belongsTo');
            $target = (string) ($rel['model'] ?? '');
            $fk = (string) ($rel['foreign_key'] ?? '');
            $method = Str::camel((string) ($rel['method'] ?? $rel['name'] ?? $target));

            if (! $src || ! $target) {
                continue;
            }

            // Every value below is written into PHP source, so accept identifiers only
            if (! in_array($type, ['belongsTo', 'hasMany', 'hasOne', 'belongsToMany'], true)
                || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $src)
                || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $target)
                || ! preg_match('/^[a-z][A-Za-z0-9_]{0,62}$/', $method)
                || ($fk !== '' && ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $fk))) {
                throw new \InvalidArgumentException('Relationships must use plain identifiers (letters, numbers and underscores) and a supported type.');
            }

            $cleanedRelations[] = [
                'source_model' => $src,
                'type' => $type,
                'model' => $target,
                'foreign_key' => $fk,
                'method' => $method,
            ];

            // Inject method into source model file
            $sourceModelClass = Str::studly(Str::singular($src));
            $sourceModelFile = $sliceDir."/Models/{$sourceModelClass}.php";
            if (! file_exists($sourceModelFile)) {
                $sourceModelFile = $sliceDir.'/Models/'.Str::studly($src).'.php';
            }

            $modelsDir = realpath($sliceDir.'/Models');
            if (file_exists($sourceModelFile) && $modelsDir !== false && dirname((string) realpath($sourceModelFile)) === $modelsDir) {
                $content = file_get_contents($sourceModelFile);

                $targetStudly = Str::studly(Str::singular($target));
                $targetFile = $sliceDir."/Models/{$targetStudly}.php";
                if (file_exists($targetFile)) {
                    $targetClass = "{$targetStudly}::class";
                } elseif (class_exists("\\App\\Models\\{$targetStudly}")) {
                    $targetClass = "\\App\\Models\\{$targetStudly}::class";
                } else {
                    $targetClass = '\\App\\Models\\'.Str::studly($target).'::class';
                }

                $returnType = match ($type) {
                    'hasMany' => '\Illuminate\Database\Eloquent\Relations\HasMany',
                    'hasOne' => '\Illuminate\Database\Eloquent\Relations\HasOne',
                    'belongsToMany' => '\Illuminate\Database\Eloquent\Relations\BelongsToMany',
                    default => '\Illuminate\Database\Eloquent\Relations\BelongsTo',
                };

                $fkArg = $fk !== '' ? ', '.var_export($fk, true) : '';
                $relationCall = match ($type) {
                    'hasMany' => "\$this->hasMany({$targetClass}{$fkArg});",
                    'hasOne' => "\$this->hasOne({$targetClass}{$fkArg});",
                    'belongsToMany' => "\$this->belongsToMany({$targetClass});",
                    default => "\$this->belongsTo({$targetClass}{$fkArg});",
                };

                $snippet = <<<PHP

    public function {$method}(): {$returnType}
    {
        return {$relationCall}
    }
PHP;

                $pattern = '/public\s+function\s+'.preg_quote($method, '/').'\s*\([^\)]*\)\s*(?::\s*[^{]+)?\s*\{[^}]+\}/s';
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, trim($snippet), $content);
                } else {
                    $content = preg_replace('/\s*\}\s*$/', "\n".$snippet."\n}\n", $content);
                }

                file_put_contents($sourceModelFile, $content);
            }
        }

        $manifest['relations'] = $cleanedRelations;

        $newVersion = self::nextPatchVersion($manifest['version'] ?? null);
        $manifest['version'] = $newVersion;

        if (! isset($manifest['version_history'])) {
            $manifest['version_history'] = [];
        }
        $manifest['version_history'][] = [
            'version' => $newVersion,
            'type' => 'relationships_update',
            'description' => 'Updated '.count($cleanedRelations).' Eloquent relationship(s)',
            'author' => $author,
            'date' => date('Y-m-d H:i:s'),
        ];

        ManifestRepository::write($manifestFile, $manifest);

        return [
            'success' => true,
            'relations' => $cleanedRelations,
            'version' => $newVersion,
            'message' => 'Successfully saved '.count($cleanedRelations)." relationship(s) for [{$sliceName}]!",
        ];
    }
}
