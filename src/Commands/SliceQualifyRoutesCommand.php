<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\SliceManifest;

/**
 * G6 step 2: repair pass for existing slices. Slices generated before
 * route-name qualification carry FLAT route names (tasks.*) even when
 * nested (ERP/ERP/Work/Tasks), so same-named slices share route names
 * and route() resolves to one winner. This command rewrites — inside
 * each nested slice's OWN directory only — its routes file name
 * prefix, its controller's getRoutePrefix(), and its blades' route()
 * references to the path-qualified prefix the current generator emits
 * (erp_erp_work_tasks.*). Root slices and already-qualified slices
 * are untouched. Use --dry-run to preview.
 */
final class SliceQualifyRoutesCommand extends Command
{
    protected $signature = 'slice:qualify-routes {slice? : Only qualify the slice with this name} {--dry-run : Report what would change without writing}';

    protected $description = 'Qualify nested slices\' route names with their path prefix so same-named slices stop sharing route names.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $skipped = 0;

        $only = $this->argument('slice');
        foreach ($this->discoverManifests() as $slice) {
            if ($only && strcasecmp((string) $only, (string) $slice->name) !== 0) {
                continue;
            }
            $routesFile = $slice->path.'/Routes/web.php';
            if (! is_file($routesFile)) {
                $skipped++;

                continue;
            }
            $routes = (string) file_get_contents($routesFile);
            if (! preg_match("/Route::prefix\\('([^']+)'\\)->name\\('([^']+)\\.'\\)/", $routes, $m)) {
                $skipped++;

                continue;
            }
            [, $uriPrefix, $oldName] = $m;
            $qualified = str_replace('/', '_', str_replace('-', '_', $uriPrefix));
            if (! str_contains($uriPrefix, '/') || $qualified === $oldName) {
                $skipped++; // root slice or already qualified

                continue;
            }

            $files = $this->sliceFiles($slice->path);
            $touched = 0;
            foreach ($files as $file) {
                $content = (string) file_get_contents($file);
                $updated = str_replace(
                    ["'{$oldName}.", '"'.$oldName.'.'],
                    ["'{$qualified}.", '"'.$qualified.'.'],
                    $content,
                    $count
                );
                if ($count > 0 && $updated !== $content) {
                    $touched++;
                    if (! $dry) {
                        file_put_contents($file, $updated);
                    }
                }
            }
            if ($touched > 0) {
                $changed++;
                $this->line(sprintf(
                    '%s %s: %s.* -> %s.* (%d file%s)',
                    $dry ? '[dry-run] would qualify' : 'qualified',
                    $slice->name,
                    $oldName,
                    $qualified,
                    $touched,
                    $touched === 1 ? '' : 's'
                ));
            } else {
                $skipped++;
            }
        }

        $this->info($dry
            ? "Dry run: {$changed} slice(s) would be qualified, {$skipped} skipped."
            : "Done: {$changed} slice(s) qualified, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * Enumerate slice manifests from the filesystem. SliceManager's
     * registry is keyed by slice NAME, so same-named slices overwrite
     * each other there — exactly the population this command repairs —
     * hence the direct scan (same rules as SliceManager::discover).
     *
     * @return array<int, SliceManifest>
     */
    private function discoverManifests(): array
    {
        $base = (string) config('laraslice.slices_path', app_path('Slices'));
        $found = [];
        $scan = function (string $dir, int $depth) use (&$scan, &$found): void {
            if (! is_dir($dir) || $depth > 6) {
                return;
            }
            foreach (['slice.yaml', 'slice.yml'] as $candidate) {
                if (is_file($dir.'/'.$candidate)) {
                    $found[] = new SliceManifest($dir.'/'.$candidate);

                    return;
                }
            }
            foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $sub) {
                if (in_array(strtolower(basename($sub)), ['.git', 'node_modules', 'vendor', 'storage', 'public', 'tests', 'cache', '.system_generated'], true)) {
                    continue;
                }
                $scan($sub, $depth + 1);
            }
        };
        $scan($base, 0);

        return $found;
    }

    /**
     * @return array<int, string> php + blade files inside the slice
     */
    private function sliceFiles(string $sliceDir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sliceDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['php'], true)) {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
