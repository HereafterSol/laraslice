<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Bidirectional Schema Drift & Sync Command.
 *
 * Compares active database columns with app/Slices/{Slice}/slice.yaml,
 * detects schema drift, and non-destructively merges new fields.
 */
class SliceSyncCommand extends Command
{
    protected $signature = 'slice:sync 
                            {slice : The name of the vertical slice (e.g. HumanResources)}
                            {--dry-run : Display detected schema drift without modifying slice.yaml}';

    protected $description = 'Detect database schema drift for a slice and sync new fields into slice.yaml';

    public function handle(): int
    {
        $sliceName = (string) $this->argument('slice');
        $dryRun = (bool) $this->option('dry-run');

        $slicesPath = config('laraslice.slices_path', app_path('Slices'));
        $sliceDir = $slicesPath.'/'.$sliceName;
        $yamlPath = $sliceDir.'/slice.yaml';

        if (! File::isDirectory($sliceDir)) {
            $this->error("Slice '{$sliceName}' not found at: {$sliceDir}");

            return self::FAILURE;
        }

        if (! File::exists($yamlPath)) {
            $this->error("No slice.yaml found in {$sliceDir}. Run blueprint generation first.");

            return self::FAILURE;
        }

        $blueprint = Yaml::parse(File::get($yamlPath));
        if (! is_array($blueprint) || empty($blueprint['models'])) {
            $this->error("Invalid or empty slice.yaml format in {$yamlPath}.");

            return self::FAILURE;
        }

        $this->info("Analyzing schema drift for slice: {$sliceName}...");
        $driftDetected = false;
        $totalAdded = 0;

        foreach ($blueprint['models'] as &$model) {
            $table = $model['table'] ?? ($model['handle'].'s');

            if (! Schema::hasTable($table)) {
                $this->warn("Table '{$table}' for model '{$model['handle']}' does not exist in database. Skipping.");

                continue;
            }

            $existingHandles = array_column($model['fields'] ?? [], 'handle');
            $dbColumns = Schema::getColumns($table);
            $newFields = [];

            foreach ($dbColumns as $col) {
                $colName = $col['name'];
                if (in_array($colName, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                if (! in_array($colName, $existingHandles, true)) {
                    $typeName = strtolower($col['type_name']);
                    $type = match (true) {
                        str_contains($typeName, 'int') && ($col['type'] === 'tinyint(1)' || $typeName === 'bool') => 'boolean',
                        str_contains($typeName, 'int') => 'integer',
                        str_contains($typeName, 'text') => 'text',
                        str_contains($typeName, 'decimal') || str_contains($typeName, 'float') => 'decimal',
                        str_contains($typeName, 'date') && ! str_contains($typeName, 'time') => 'date',
                        str_contains($typeName, 'time') || str_contains($typeName, 'timestamp') => 'datetime',
                        str_contains($typeName, 'json') => 'json',
                        default => 'string',
                    };

                    $newFields[] = [
                        'handle' => $colName,
                        'label' => Str::headline($colName),
                        'type' => $type,
                        'required' => ! $col['nullable'] && ($col['default'] === null),
                        'default' => $col['default'] ?? null,
                    ];
                }
            }

            if (! empty($newFields)) {
                $driftDetected = true;
                $this->warn("Model '{$model['handle']}' has ".count($newFields).' new column(s) in database:');
                foreach ($newFields as $f) {
                    $this->line("  + {$f['handle']} ({$f['type']})".($f['required'] ? ' [required]' : ''));
                    $model['fields'][] = $f;
                    $totalAdded++;
                }
            }
        }
        unset($model);

        if (! $driftDetected) {
            $this->info('✅ In sync! No schema drift detected between database and slice.yaml.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("🔍 Dry run complete. {$totalAdded} drifted column(s) found. Run without --dry-run to sync slice.yaml.");

            return self::SUCCESS;
        }

        File::put($yamlPath, Yaml::dump($blueprint, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $this->info("🎉 Successfully synced {$totalAdded} drifted field(s) into {$yamlPath}!");
        $this->info('Existing custom PHP code was preserved untouched.');

        return self::SUCCESS;
    }
}
