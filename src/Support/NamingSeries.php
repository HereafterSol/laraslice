<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Naming-series generation (Seat 1, Stage D).
 *
 * A slice declares `autoname: {field, pattern}` in its
 * Heart; the pattern is declarative data any runtime
 * can implement identically:
 *
 *   CRM-LEAD-.YYYY.-#####   tokens .YYYY. .YY. .MM. .DD.
 *                           '#' run = zero-padded counter
 *                           (no run: unpadded counter at
 *                           the end)
 *
 * The counter lives in the framework's counters table,
 * scoped by the rendered prefix (so a yearly series
 * restarts each year), and increments inside a
 * transaction with a row lock — two simultaneous
 * creates can never receive the same number.
 */
class NamingSeries
{
    public static function next(string $sliceName, array $autoname): ?string
    {
        $pattern = $autoname['pattern'] ?? null;
        if (! is_string($pattern) || $pattern === '' || ! Schema::hasTable('laraslice_counters')) {
            return null;
        }

        $rendered = self::renderDateTokens($pattern);
        $hashRun = null;
        if (preg_match('/#+/', $rendered, $m, PREG_OFFSET_CAPTURE)) {
            $hashRun = $m[0][0];
            $prefix = substr($rendered, 0, $m[0][1]);
        } else {
            $prefix = $rendered;
        }
        $key = $sliceName.'|'.$prefix;

        $value = DB::transaction(function () use ($key) {
            DB::table('laraslice_counters')->insertOrIgnore([
                'key' => $key, 'value' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('laraslice_counters')->where('key', $key)->lockForUpdate()->first();
            $next = (int) $row->value + 1;
            DB::table('laraslice_counters')->where('key', $key)->update([
                'value' => $next, 'updated_at' => now(),
            ]);

            return $next;
        });

        if ($hashRun !== null) {
            return preg_replace('/#+/', str_pad((string) $value, strlen($hashRun), '0', STR_PAD_LEFT), $rendered, 1);
        }

        return $rendered.$value;
    }

    private static function renderDateTokens(string $pattern): string
    {
        return strtr($pattern, [
            '.YYYY.' => now()->format('Y'),
            '.YY.' => now()->format('y'),
            '.MM.' => now()->format('m'),
            '.DD.' => now()->format('d'),
        ]);
    }
}
