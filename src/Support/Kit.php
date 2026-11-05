<?php

namespace LaraSlice\Support;

/**
 * Active UI kit profile (default vs ERP replica copy/strings).
 */
final class Kit
{
    public static function current(): string
    {
        $kit = config('laraslice.kit');

        if (is_string($kit) && $kit !== '') {
            return $kit;
        }

        return (string) env('LARASLICE_KIT', 'default');
    }

    public static function isReplica(): bool
    {
        return self::current() === 'replica';
    }
}
