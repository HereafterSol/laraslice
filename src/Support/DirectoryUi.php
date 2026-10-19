<?php

namespace LaraSlice\Support;

use LaraSlice\Core\Discovery\SliceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolve directory column config (formatters) at request time from the
 * slice's registered path — works for path-repo, vendor, and published installs.
 */
final class DirectoryUi
{
    /** @var list<string> */
    public const HTML_FORMATTERS = [
        'code_badge', 'composite_user', 'status_pill', 'money_breakdown',
        'progress_bar', 'badge', 'text_with_badge', 'date_overdue',
    ];

    /**
     * Merge ui.directory.columns from the live slice manifest over $fallback.
     *
     * @param  list<array<string, mixed>>  $fallback
     * @return list<array<string, mixed>>
     */
    public static function columnsForSlice(string $sliceName, array $fallback = []): array
    {
        $manifest = self::readManifest($sliceName);
        $dirCols = $manifest['ui']['directory']['columns'] ?? null;
        if (! is_array($dirCols) || $dirCols === []) {
            return $fallback;
        }

        $columns = [];
        foreach ($dirCols as $c) {
            if (! is_array($c)) {
                continue;
            }
            $key = $c['key'] ?? $c['handle'] ?? null;
            if (! $key) {
                continue;
            }
            $fmt = $c['formatter'] ?? $c['type'] ?? 'text';
            $columns[] = array_filter([
                'key' => $key,
                'label' => $c['label'] ?? \Illuminate\Support\Str::headline((string) $key),
                'sortable' => $c['sortable'] ?? true,
                'formatter' => $fmt,
                'html' => in_array($fmt, self::HTML_FORMATTERS, true),
                'badge_from' => $c['badge_from'] ?? null,
                'breakdown' => $c['breakdown'] ?? null,
            ], fn ($v) => $v !== null);
        }

        return $columns !== [] ? $columns : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readManifest(string $sliceName): array
    {
        $path = self::manifestPath($sliceName);
        if ($path === null || ! is_file($path)) {
            return [];
        }

        try {
            if (str_ends_with(strtolower($path), '.json')) {
                return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) ?: [];
            }
            if (class_exists(Yaml::class)) {
                $data = Yaml::parseFile($path);

                return is_array($data) ? $data : [];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    public static function manifestPath(string $sliceName): ?string
    {
        try {
            if (function_exists('app') && app()->bound(SliceManager::class)) {
                /** @var SliceManager $manager */
                $manager = app(SliceManager::class);
                $manager->discover();
                $slice = $manager->getSlice($sliceName);
                if ($slice) {
                    foreach (['slice.json', 'slice.yaml', 'slice.yml'] as $file) {
                        $p = rtrim($slice->path, '/\\').DIRECTORY_SEPARATOR.$file;
                        if (is_file($p)) {
                            return $p;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to path heuristics
        }

        // Heuristics when container not ready
        $candidates = [];
        if (function_exists('app_path')) {
            $candidates[] = app_path('Slices');
            $candidates[] = base_path('app/Slices');
        }
        $candidates[] = dirname(__DIR__, 2).'/Slices';

        foreach ($candidates as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $found = self::findManifestUnder($root, $sliceName, 0, 6);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    protected static function findManifestUnder(string $dir, string $sliceName, int $depth, int $max): ?string
    {
        if ($depth > $max) {
            return null;
        }
        $base = basename($dir);
        if (strcasecmp($base, $sliceName) === 0 || strcasecmp($base, str_replace(' ', '', $sliceName)) === 0) {
            foreach (['slice.json', 'slice.yaml', 'slice.yml'] as $file) {
                $p = $dir.DIRECTORY_SEPARATOR.$file;
                if (is_file($p)) {
                    return $p;
                }
            }
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $sub) {
            $hit = self::findManifestUnder($sub, $sliceName, $depth + 1, $max);
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }
}
