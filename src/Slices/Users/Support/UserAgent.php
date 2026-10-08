<?php

namespace LaraSlice\Slices\Users\Support;

/**
 * Coarse browser, operating system and device-type detection for security logs and device lists.
 *
 * Order matters: iOS user agents also contain "Mac OS X", Android ones contain "Linux",
 * and Edge and Opera ones contain "Chrome" and "Safari".
 */
final class UserAgent
{
    /**
     * @return array{os: string, browser: string, deviceType: string}
     */
    public static function parse(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        return [
            'os' => self::os($ua),
            'browser' => self::browser($ua),
            'deviceType' => self::deviceType($ua),
        ];
    }

    public static function os(string $ua): string
    {
        return match (true) {
            self::has($ua, 'iPhone', 'iPad', 'iPod') => 'iOS',
            self::has($ua, 'Android') => 'Android',
            self::has($ua, 'Windows') => 'Windows',
            self::has($ua, 'Macintosh', 'Mac OS') => 'macOS',
            self::has($ua, 'CrOS') => 'ChromeOS',
            self::has($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            self::has($ua, 'Edg') => 'Edge',
            self::has($ua, 'OPR', 'Opera') => 'Opera',
            self::has($ua, 'Firefox', 'FxiOS') => 'Firefox',
            self::has($ua, 'Chrome', 'CriOS', 'Chromium') => 'Chrome',
            self::has($ua, 'Safari') => 'Safari',
            default => 'Unknown',
        };
    }

    public static function deviceType(string $ua): string
    {
        return match (true) {
            self::has($ua, 'iPad', 'Tablet') => 'Tablet',
            self::has($ua, 'Mobile', 'Android', 'iPhone', 'iPod') => 'Mobile',
            default => 'Desktop',
        };
    }

    private static function has(string $haystack, string ...$needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
