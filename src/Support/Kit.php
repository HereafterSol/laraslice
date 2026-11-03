<?php

declare(strict_types=1);

namespace LaraSlice\Support;

/**
 * The active rendering KIT — the seam between declarations
 * and appearance. Declarations (Mind/Body/Heart) never change
 * per kit; a kit is a token + component-style layer rendered
 * over the standard markup (see docs/KIT-CONTRACT.md and the
 * replica kit's contract). The house kit is the default Soul;
 * `replica` renders a ported application in its source's own
 * identity (the 100%-clone programme).
 *
 * Resolution, highest first:
 *   1. `?kit=` request parameter — the developer escape hatch
 *      (`replica` forces the replica kit, `standard` forces
 *      the house kit).
 *   2. The Mind's declaration: `design.kit` in system.yaml.
 *   3. `config('laraslice.kit')` — the app-level default.
 * Platform surfaces (the Studio's own routes) always keep the
 * house identity: a kit skins the APPLICATION, never the
 * platform that builds it.
 */
final class Kit
{
    /** @var list<string> */
    public const KITS = ['replica'];

    /** Route prefixes that always render the house kit. */
    private const PLATFORM_PREFIXES = ['laraslice/', 'slice-studio', 'studio/'];

    public static function current(): ?string
    {
        $path = '';
        $param = null;
        try {
            $request = request();
            $path = $request->path();
            $param = $request->query('kit');
        } catch (\Throwable) {
            // No request bound (console/tests): declarations only.
        }

        foreach (self::PLATFORM_PREFIXES as $prefix) {
            if ($path !== '' && str_starts_with($path, $prefix)) {
                return null;
            }
        }

        $mind = null;
        try {
            $mind = SystemMind::load();
        } catch (\Throwable) {
            $mind = null;
        }

        return self::resolve(
            is_string($param) ? $param : null,
            is_array($mind) ? ($mind['design']['kit'] ?? null) : null,
            config('laraslice.kit'),
        );
    }

    /**
     * Pure resolution, kept separate for testing.
     * Returns the kit name, or null for the house kit.
     */
    public static function resolve(?string $param, mixed $mindKit, mixed $configKit): ?string
    {
        if ($param === 'standard') {
            return null;
        }
        if (is_string($param) && in_array($param, self::KITS, true)) {
            return $param;
        }
        foreach ([$mindKit, $configKit] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::KITS, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The published stylesheet path for a kit, if it has one. */
    public static function stylesheet(?string $kit): ?string
    {
        return $kit === 'replica' ? 'css/replica-kit.css' : null;
    }
}
