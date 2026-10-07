<?php

namespace LaraSlice\Generator;

/**
 * Guards free text (labels, option labels, string defaults) that the generators
 * write into Blade templates. HTML escaping does not stop Blade from compiling
 * echo tags, directives or PHP tags, so such text is rejected outright.
 */
final class BladeSafeText
{
    public static function isSafe(string $text): bool
    {
        return ! preg_match('/\{\{|\}\}|\{!!|!!\}|<\?|\?>|\B@[A-Za-z_]/', $text);
    }

    public static function problem(string $text, string $what): ?string
    {
        return self::isSafe($text)
            ? null
            : "{$what} cannot contain Blade or PHP syntax such as {{ }}, {!! !!}, <?php or @directives.";
    }
}
