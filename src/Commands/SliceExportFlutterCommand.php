<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\FlutterSliceGenerator;

class SliceExportFlutterCommand extends Command
{
    protected $signature = 'slice:export-flutter
                            {name : Name of the slice to export to Flutter}
                            {--force : Overwrite Flutter files that already exist}';

    protected $description = 'Generate matching Flutter client model, typed HTTP client, and UI views for a slice';

    public function handle(SliceManager $manager): int
    {
        $name = $this->argument('name');
        $this->info("📱 Exporting Flutter Client Slice for [{$name}]...");

        // Use the slice's own fields and API prefix when it is installed
        $options = ['force' => (bool) $this->option('force')];
        if ($slice = $manager->getSlice($name)) {
            $fields = $slice->raw['fields'] ?? [];
            $options['fields'] = array_is_list($fields)
                ? $fields
                : array_map(fn ($key, $field) => ['name' => $key] + (array) $field, array_keys($fields), $fields);
            $plural = Str::plural(Str::snake(Str::singular($slice->name)));
            $options['api_path'] = ! empty($slice->domain) ? Str::slug($slice->domain) . '/' . $plural : $plural;
        }

        try {
            $targetDir = (new FlutterSliceGenerator())->generate($name, $options);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->line("<fg=green>✓</> Flutter Model: <comment>{$targetDir}/models/</comment>");
        $this->line("<fg=green>✓</> Flutter API Service: <comment>{$targetDir}/services/</comment>");
        $this->line("<fg=green>✓</> Flutter Listing & Form Views: <comment>{$targetDir}/views/</comment>");

        $this->newLine();
        $this->info("✨ Flutter slice exported successfully to [{$targetDir}]!");

        return Command::SUCCESS;
    }
}
