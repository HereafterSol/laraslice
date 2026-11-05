<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Route;
use LaraSlice\Core\Discovery\SliceManager;
use Throwable;

/**
 * ERP shell chrome helpers — document titles and nav-derived labels (enterprise UX; no generic framework suffix).
 */
final class EnterpriseShell
{
    /**
     * Browser tab title: explicit Blade @section('title') wins, then active nav label, then app name only.
     */
    public static function documentTitle(?string $sectionTitle = null): string
    {
        $appName = (string) config('app.name', 'LaraSlice');
        $sectionTitle = trim((string) $sectionTitle);

        if ($sectionTitle !== '') {
            return self::join($sectionTitle, $appName);
        }

        $fromNav = self::activeNavLabel();
        if ($fromNav !== null) {
            return self::join($fromNav, $appName);
        }

        if (Route::has('dashboard') && request()->routeIs('dashboard')) {
            return self::join('Dashboard', $appName);
        }

        if (Route::has('laraslice.wizard') && request()->routeIs('laraslice.wizard*')) {
            return self::join('Dev Studio', $appName);
        }

        return $appName;
    }

    private static function join(string $primary, string $appName): string
    {
        if (strcasecmp($primary, $appName) === 0) {
            return $appName;
        }

        return "{$primary} · {$appName}";
    }

    private static function activeNavLabel(): ?string
    {
        try {
            $manager = app(SliceManager::class);
            $slices = $manager->getNavigableSlices();
        } catch (Throwable) {
            return null;
        }

        foreach ($slices as $slice) {
            $parentLabel = (string) ($slice['label'] ?? $slice['title'] ?? $slice['name'] ?? '');

            if (! empty($slice['children']) && is_array($slice['children'])) {
                foreach ($slice['children'] as $child) {
                    if (! empty($child['active'])) {
                        $childLabel = (string) ($child['label'] ?? '');

                        return $childLabel !== '' && $parentLabel !== ''
                            ? "{$parentLabel} — {$childLabel}"
                            : ($childLabel !== '' ? $childLabel : $parentLabel);
                    }
                }
            }

            if (! empty($slice['active'])) {
                return $parentLabel !== '' ? $parentLabel : null;
            }
        }

        return null;
    }
}
