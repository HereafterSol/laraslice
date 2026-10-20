<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Validation\SliceYamlValidator;

class SliceValidateCommand extends Command
{
    protected $signature = 'slice:validate
                            {path? : Path to a slice.yaml / slice.json (omit with --all)}
                            {--all : Validate every discovered slice manifest}
                            {--schema= : Override path to slice-v0.1.json}';

    protected $description = 'Validate slice YAML/JSON against Slice YAML v0.1 (closed formatters & field types)';

    public function handle(SliceYamlValidator $validator, SliceManager $manager): int
    {
        $path = $this->argument('path');
        $all = (bool) $this->option('all');

        if (! $all && ($path === null || $path === '')) {
            $this->error('Provide a path or use --all.');
            $this->line('  php artisan slice:validate path/to/slice.yaml');
            $this->line('  php artisan slice:validate --all');

            return self::FAILURE;
        }

        $schema = $validator->schemaPath();
        if (is_file($schema)) {
            $this->line('<fg=gray>Schema:</> '.$schema);
        }

        $targets = [];
        if ($all) {
            $manager->discover();
            foreach ($manager->getAllSlices() as $slice) {
                $yaml = $slice->path.DIRECTORY_SEPARATOR.'slice.yaml';
                $json = $slice->path.DIRECTORY_SEPARATOR.'slice.json';
                if (is_file($yaml)) {
                    $targets[$slice->name] = $yaml;
                } elseif (is_file($json)) {
                    $targets[$slice->name] = $json;
                }
            }
            if ($targets === []) {
                $this->warn('No slice manifests found.');

                return self::SUCCESS;
            }
        } else {
            $targets[basename(dirname((string) $path))] = (string) $path;
        }

        $failed = 0;
        $passed = 0;
        foreach ($targets as $label => $file) {
            $errors = $validator->validateFile($file);
            if ($errors === []) {
                $this->line("<fg=green>PASS</> {$label}  <fg=gray>{$file}</>");
                $passed++;
            } else {
                $this->line("<fg=red>FAIL</> {$label}  <fg=gray>{$file}</>");
                foreach ($errors as $err) {
                    $p = $err['path'] !== '' ? $err['path'].': ' : '';
                    $this->line("  <fg=red>•</> {$p}{$err['message']}");
                }
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Validated {$passed} passed, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
