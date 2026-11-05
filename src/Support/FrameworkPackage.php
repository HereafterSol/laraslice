<?php

namespace LaraSlice\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use LaraSlice\Core\Discovery\SliceManager;
use Throwable;

/**
 * Package metadata for Studio, Copilot, and operator surfaces — never hard-code release labels in Blade.
 */
final class FrameworkPackage
{
    private static ?string $cachedVersion = null;

    public static function version(): string
    {
        if (self::$cachedVersion !== null) {
            return self::$cachedVersion;
        }

        if (class_exists(InstalledVersions::class)) {
            try {
                if (InstalledVersions::isInstalled('hereafter/laraslice')) {
                    $pretty = InstalledVersions::getPrettyVersion('hereafter/laraslice');
                    if (is_string($pretty) && $pretty !== '') {
                        return self::$cachedVersion = $pretty;
                    }
                }
            } catch (Throwable) {
                // fall through
            }
        }

        $fromChangelog = self::versionFromChangelog();
        if ($fromChangelog !== null) {
            return self::$cachedVersion = $fromChangelog;
        }

        return self::$cachedVersion = 'dev';
    }

    /**
     * Count slices under the host app's configured slices path (honest Studio header; excludes vendor-only manifests when paths differ).
     */
    public static function hostSliceCount(): int
    {
        try {
            $manager = app(SliceManager::class);
            $slices = $manager->getAllSlices();
            $base = realpath((string) config('laraslice.slices_path', app_path('Slices'))) ?: '';

            if ($base === '') {
                return count($slices);
            }

            $hostOnly = 0;
            foreach ($slices as $manifest) {
                $path = realpath($manifest->path ?? '') ?: '';
                if ($path !== '' && str_starts_with($path, $base)) {
                    $hostOnly++;
                }
            }

            return $hostOnly > 0 ? $hostOnly : count($slices);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Plain-language schema telemetry label for Copilot copy (SQLite demo must not say "MySQL").
     */
    public static function schemaTelemetryLabel(): string
    {
        try {
            $driver = DB::connection()->getDriverName();
        } catch (Throwable) {
            return 'active database schemas';
        }

        return match ($driver) {
            'sqlite' => 'SQLite schemas',
            'pgsql' => 'PostgreSQL schemas',
            'mysql', 'mariadb' => 'MySQL schemas',
            default => 'active database schemas',
        };
    }

    private static function versionFromChangelog(): ?string
    {
        $changelog = dirname(__DIR__, 2).'/CHANGELOG.md';
        if (! is_readable($changelog)) {
            return null;
        }

        $contents = file_get_contents($changelog);
        if ($contents === false) {
            return null;
        }

        if (! preg_match_all('/^## \[(\d+\.\d+\.\d+)\]/m', $contents, $matches)) {
            return null;
        }

        $versions = $matches[1];
        usort($versions, version_compare(...));

        return $versions !== [] ? end($versions) : null;
    }
}
