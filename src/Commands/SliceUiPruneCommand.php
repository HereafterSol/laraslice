<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SliceUiPruneCommand extends Command
{
    protected $signature = 'slice:ui:prune 
                            {--force : Automatically remove unused BlatUI components without confirmation}
                            {--dry-run : Only list unused components without deleting}';

    protected $description = 'Scan all slices and views, identifying and pruning unused BlatUI components to keep the codebase lean';

    public function handle(): int
    {
        $uiPath = resource_path('views/components/ui');
        if (! is_dir($uiPath)) {
            $this->warn("No BlatUI components directory found at: {$uiPath}");

            return Command::SUCCESS;
        }

        $this->info('🔍 Scanning project for BlatUI component usage...');

        // 1. Gather all installed UI component files (top-level components)
        $installedFiles = File::files($uiPath);
        $installedComponents = [];
        foreach ($installedFiles as $file) {
            $name = str_replace('.blade.php', '', $file->getFilename());
            $installedComponents[$name] = $file->getPathname();
        }

        // 2. Scan all blade templates in resources/views and app/Slices
        $scanPaths = [
            resource_path('views'),
            app_path('Slices'),
            config('laraslice.slices_path', app_path('Slices')),
        ];

        $usedComponents = [];
        foreach ($scanPaths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $allFiles = File::allFiles($path);
            foreach ($allFiles as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                // Skip the components/ui folder itself
                if (str_starts_with($file->getPathname(), $uiPath)) {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                foreach (array_keys($installedComponents) as $comp) {
                    // Check for <x-ui.{comp}, <x-ui.{comp}-*, or x-ui::{comp}
                    if (str_contains($content, "<x-ui.{$comp}") || str_contains($content, "<x-ui::{$comp}")) {
                        $usedComponents[$comp] = true;
                    }
                }
            }
        }

        // Always preserve core primitives
        $protected = ['button', 'card', 'input', 'label', 'badge', 'table'];
        foreach ($protected as $p) {
            $usedComponents[$p] = true;
        }

        $unused = [];
        foreach ($installedComponents as $comp => $filepath) {
            if (! isset($usedComponents[$comp])) {
                $unused[$comp] = $filepath;
            }
        }

        $this->newLine();
        $this->line('Total installed UI components: <comment>'.count($installedComponents).'</comment>');
        $this->line('Actively used in slices:        <fg=green>'.count($usedComponents).'</>');
        $this->line('Unused candidate components:    <fg=yellow>'.count($unused).'</>');
        $this->newLine();

        if (empty($unused)) {
            $this->info('✨ Clean tree! All installed BlatUI components are actively utilized.');

            return Command::SUCCESS;
        }

        $this->table(['Unused Component', 'File Path'], array_map(fn ($k, $v) => [$k, basename($v)], array_keys($unused), $unused));

        if ($this->option('dry-run')) {
            $this->comment('Dry-run mode active. No files were removed.');

            return Command::SUCCESS;
        }

        if ($this->option('force') || $this->confirm('Would you like to prune these '.count($unused).' unused components?', false)) {
            $deleted = 0;
            foreach ($unused as $file) {
                if (File::delete($file)) {
                    $deleted++;
                }
            }
            $this->info("🗑️ Pruned {$deleted} unused component(s) successfully. Project remains lean!");
        }

        return Command::SUCCESS;
    }
}
