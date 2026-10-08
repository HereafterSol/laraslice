<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SlicePublishCommand extends Command
{
    protected $signature = 'slice:publish 
                            {slice? : The name of the core slice to publish (e.g. Users, Roles, Settings, Auth, Products)}
                            {--all : Publish all core slices into the application}
                            {--force : Overwrite existing slices in app/Slices}';

    protected $description = 'Publish core LaraSlice slices into app/Slices for deep customization and project-level ownership';

    public function handle(): int
    {
        $coreSlicesPath = dirname(__DIR__).'/Slices';
        $appSlicesPath = config('laraslice.slices_path', app_path('Slices'));

        if (! File::isDirectory($coreSlicesPath)) {
            $this->error('Core slices directory not found at: '.$coreSlicesPath);

            return self::FAILURE;
        }

        $availableSlices = collect(File::directories($coreSlicesPath))->map(fn ($d) => basename($d))->values();

        if ($this->option('all')) {
            $slicesToPublish = $availableSlices;
        } else {
            $sliceName = $this->argument('slice');
            if (! $sliceName) {
                $choice = $this->choice(
                    'Which core slice would you like to publish to app/Slices?',
                    array_merge(['All Slices'], $availableSlices->toArray()),
                    0
                );
                if ($choice === 'All Slices') {
                    $slicesToPublish = $availableSlices;
                } else {
                    $slicesToPublish = collect([$choice]);
                }
            } else {
                $matched = $availableSlices->first(fn ($s) => strcasecmp($s, $sliceName) === 0);
                if (! $matched) {
                    $this->error("Core slice '{$sliceName}' not found. Available core slices: ".$availableSlices->implode(', '));

                    return self::FAILURE;
                }
                $slicesToPublish = collect([$matched]);
            }
        }

        File::ensureDirectoryExists($appSlicesPath);

        foreach ($slicesToPublish as $slice) {
            $src = $coreSlicesPath.'/'.$slice;
            $dst = $appSlicesPath.'/'.$slice;

            if (File::isDirectory($dst) && ! $this->option('force')) {
                $this->components->warn("Slice [{$slice}] already exists in app/Slices. Use --force to overwrite.");

                continue;
            }

            $this->components->task("Publishing [{$slice}] slice to app/Slices/{$slice}", function () use ($src, $dst) {
                File::ensureDirectoryExists($dst);
                File::copyDirectory($src, $dst);

                // Update namespace references from package to application
                $files = File::allFiles($dst);
                foreach ($files as $file) {
                    if ($file->getExtension() === 'php' || $file->getExtension() === 'json') {
                        $content = File::get($file->getRealPath());
                        $updated = str_replace(
                            ['LaraSlice\\Slices\\', 'LaraSlice\\\\Slices\\\\'],
                            ['App\\Slices\\', 'App\\\\Slices\\\\'],
                            $content
                        );
                        if ($updated !== $content) {
                            File::put($file->getRealPath(), $updated);
                        }
                    }
                }

                return true;
            });
        }

        $this->newLine();
        $this->components->info('🎉 Core slices published successfully! They are now editable first-class code in your app.');
        $this->line('<fg=gray>You can commit published slices to your git repository and customize models, views, and controllers freely.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
