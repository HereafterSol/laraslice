<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\ManifestRepository;

/**
 * Phase 2: bulk-convert existing slice.json files to slice.yaml.
 *
 * JSON is deleted only when --delete is passed (explicit user choice).
 * The framework never deletes JSON on its own.
 */
final class SliceMigrateJsonToYamlCommand extends Command
{
    protected $signature = 'slice:migrate-json-to-yaml
                            {--path= : Slice root (default: app/Slices)}
                            {--dry-run : List conversions without writing}
                            {--delete : Delete slice.json after a successful YAML write}';

    protected $description = 'Convert existing slice.json manifests to slice.yaml (Phase 2). JSON kept unless --delete.';

    public function handle(): int
    {
        $path = $this->option('path') ?: config('laraslice.slices_path', app_path('Slices'));
        $dry = (bool) $this->option('dry-run');
        $delete = (bool) $this->option('delete');

        if ($delete && $dry) {
            $this->warn('--delete is ignored with --dry-run.');
            $delete = false;
        }

        $this->info($dry ? 'Dry-run: listing JSON manifests that would be converted.' : 'Converting slice.json → slice.yaml…');
        $this->line("Root: {$path}");

        $result = ManifestRepository::migrateJsonToYaml($path, $dry, $delete);

        $this->table(
            ['Converted', 'Skipped', 'JSON deleted'],
            [[$result['converted'], $result['skipped'], $result['deleted']]]
        );

        foreach (array_slice($result['paths'], 0, 30) as $dir) {
            $this->line('  '.$dir);
        }
        if (count($result['paths']) > 30) {
            $this->line('  … '.(count($result['paths']) - 30).' more');
        }

        if ($dry) {
            $this->comment('No files were written. Re-run without --dry-run to convert.');
        } elseif ($result['converted'] > 0 && ! $delete) {
            $this->comment('JSON files were kept. Re-run with --delete to remove them after you have verified YAML.');
        }

        return self::SUCCESS;
    }
}
