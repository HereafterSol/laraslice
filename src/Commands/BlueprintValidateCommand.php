<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintValidator;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class BlueprintValidateCommand extends Command
{
    protected $signature = 'slice:blueprint:validate 
                            {target : Slice name (e.g. Employees) or path to a .json, .yaml, or .yml slice blueprint}
                            {--part= : Validate only an isolated sub-part (ui, directory, forms)}
                            {--fix : Automatically generate and inject missing ui: configurations}';

    protected $aliases = ['slice:validate'];

    protected $description = 'Validate a LaraSlice blueprint manifest (whole or sub-parts) with optional auto-fix';

    public function handle(BlueprintLoader $loader, BlueprintValidator $validator): int
    {
        $target = (string) $this->argument('target');
        $part = $this->option('part');
        $autoFix = (bool) $this->option('fix');

        // 1. Resolve blueprint path
        $filePath = $this->resolvePath($target);
        if (! $filePath || ! File::exists($filePath)) {
            $this->components->error("Blueprint or slice file not found for '{$target}'.");

            return self::FAILURE;
        }

        // 2. Load blueprint data
        try {
            $blueprint = $loader->load($filePath);
        } catch (Throwable $e) {
            $this->components->error("Failed to parse blueprint file: {$e->getMessage()}");

            return self::FAILURE;
        }

        // 3. Auto-fix missing or invalid UI block if requested
        if ($autoFix) {
            $blueprint['ui'] = $this->generateDefaultUi($blueprint);
            File::put($filePath, Yaml::dump($blueprint, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            $this->components->info("Injected clean declarative UI block into '{$filePath}'.");
        }

        // 4. Validate isolated part or entire blueprint
        try {
            if ($part) {
                $subData = match ($part) {
                    'ui' => $blueprint['ui'] ?? null,
                    'directory' => $blueprint['ui']['directory'] ?? null,
                    'forms' => $blueprint['ui']['forms'] ?? null,
                    default => throw new \InvalidArgumentException("Unsupported part '{$part}'. Allowed: ui, directory, forms."),
                };

                if ($subData === null) {
                    $this->components->warn("Target blueprint does not contain a '{$part}' block.");

                    return self::FAILURE;
                }

                $validator->validatePart($part, $subData);
                $this->components->info("Blueprint sub-part [{$part}] in [{$blueprint['handle']}] is VALID.");

                return self::SUCCESS;
            }

            $validated = $validator->validate($blueprint);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Blueprint [{$validated['handle']}] is VALID (schema v{$validated['schema_version']}).");
        $uiStatus = isset($validated['ui']) ? 'with declarative UI' : 'no UI block';
        $this->line(count($validated['models']).' model(s), '.$this->fieldCount($validated).' field(s), '.$this->relationCount($validated)." relation(s) ({$uiStatus}).");

        return self::SUCCESS;
    }

    private function resolvePath(string $target): ?string
    {
        if (File::exists($target)) {
            return $target;
        }

        // Try relative to base_path
        if (File::exists(base_path($target))) {
            return base_path($target);
        }

        // Try installed slices in app/Slices/
        $slicesPath = config('laraslice.slices_path', app_path('Slices'));
        $direct = $slicesPath.'/'.$target.'/slice.yaml';
        if (File::exists($direct)) {
            return $direct;
        }

        $globMatches = glob($slicesPath.'/*/'.$target.'/slice.yaml') ?: glob($slicesPath.'/*/*/'.$target.'/slice.yaml');
        if (! empty($globMatches)) {
            return $globMatches[0];
        }

        return null;
    }

    private function generateDefaultUi(array $blueprint): array
    {
        $rootModel = collect($blueprint['models'] ?? [])->firstWhere('root', true) ?? ($blueprint['models'][0] ?? null);
        $fields = $rootModel['fields'] ?? [];

        $columns = [];
        $formFields = [];

        foreach ($fields as $field) {
            $handle = $field['handle'] ?? '';
            $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $handle));
            $type = $field['type'] ?? 'string';

            if (in_array($handle, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            // Semantic formatter heuristic
            $formatter = 'text';
            if ($handle === 'code' || str_ends_with($handle, '_code') || $handle === 'sku') {
                $formatter = 'code_badge';
            } elseif (str_contains($handle, 'user') || str_contains($handle, 'employee') || str_contains($handle, 'customer')) {
                $formatter = 'composite_user';
            } elseif ($handle === 'status' || $type === 'enum') {
                $formatter = 'status_pill';
            } elseif ($type === 'decimal' || str_contains($handle, 'price') || str_contains($handle, 'rate') || str_contains($handle, 'total') || str_contains($handle, 'budget')) {
                $formatter = 'money_breakdown';
            } elseif ($type === 'date' || $type === 'datetime') {
                $formatter = 'date_time';
            }

            if (count($columns) < 6) {
                $columns[] = [
                    'handle' => $handle,
                    'label' => $label,
                    'formatter' => $formatter,
                    'sortable' => true,
                ];
            }

            $formFields[] = [
                'handle' => $handle,
                'span' => in_array($type, ['text', 'textarea'], true) ? 3 : 1,
                'is_searchable' => str_ends_with($handle, '_id') || $type === 'enum',
            ];
        }

        return [
            'presentation' => [
                'create' => 'drawer',
                'edit' => 'drawer',
            ],
            'directory' => [
                'columns' => $columns,
                'quick_filters' => array_values(array_map(fn ($c) => [
                    'handle' => $c['handle'],
                    'label' => $c['label'],
                    'is_searchable' => true,
                ], array_slice(array_filter($columns, fn ($c) => in_array($c['formatter'], ['status_pill', 'composite_user'], true)), 0, 2))),
                'sidebar_filters' => [],
            ],
            'forms' => [
                'sections' => [
                    [
                        'key' => 'general_details',
                        'title' => ($blueprint['name'] ?? 'Record').' Information',
                        'grid' => 3,
                        'fields' => $formFields,
                    ],
                ],
            ],
        ];
    }

    private function fieldCount(array $blueprint): int
    {
        return array_sum(array_map(static fn (array $model): int => count($model['fields'] ?? []), $blueprint['models'] ?? []));
    }

    private function relationCount(array $blueprint): int
    {
        return array_sum(array_map(static fn (array $model): int => count($model['relations'] ?? []), $blueprint['models'] ?? []));
    }
}
