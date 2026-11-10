<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

/**
 * MySQL/MariaDB row-size preflight (Cursor EVAL-B, 10 Nov 2026).
 *
 * A cold port's migrate can die mid-run with error 1118
 * ("Row size too large … maximum row size … is 65535") when one
 * CREATE TABLE's worst-case row — every column at its maximum
 * length, charset-weighted — exceeds the InnoDB limit. The run
 * stops at that table, leaving a half-migrated schema with the
 * real cause buried in migrator output.
 *
 * This guard estimates each Schema::create block in the
 * migration files about to run and reports every over-limit
 * table BEFORE anything migrates, with the remedy. Estimates
 * are deliberately conservative (utf8mb4 = 4 bytes/char, the
 * Laravel default; TEXT/BLOB columns count at their in-row
 * pointer size, exactly as the 65535 rule counts them).
 */
final class RowSizeGuard
{
    /** InnoDB hard limit for a row's maximum size, bytes. */
    public const LIMIT = 65535;

    /**
     * Scan migration files and return every CREATE whose
     * estimated worst-case row exceeds the limit.
     *
     * @param  array<int, string>  $files  absolute paths
     * @return array<int, array{table: string, bytes: int, file: string}>
     */
    public static function violations(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            foreach (self::scanContent($content) as $table => $bytes) {
                if ($bytes > self::LIMIT) {
                    $out[] = ['table' => $table, 'bytes' => $bytes, 'file' => $file];
                }
            }
        }

        return $out;
    }

    /**
     * Estimate every Schema::create in one migration file's
     * content.
     *
     * @return array<string, int> table => estimated row bytes
     */
    public static function scanContent(string $content): array
    {
        $tables = [];
        if (! preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m, PREG_OFFSET_CAPTURE)) {
            return $tables;
        }
        foreach ($m[1] as $i => [$table, $pos]) {
            $block = self::createBlock($content, (int) $m[0][$i][1]);
            if ($block !== null) {
                $tables[$table] = self::estimateBlock($block);
            }
        }

        return $tables;
    }

    /**
     * Worst-case row bytes for one Schema::create closure body.
     */
    public static function estimateBlock(string $block): int
    {
        $bytes = 0;
        // Typed columns: $table->string('name', 100), ->integer('x'), …
        if (preg_match_all('/\$table->(\w+)\(\s*[\'"]([^\'"]*)[\'"](?:\s*,\s*(\d+))?/', $block, $cols, PREG_SET_ORDER)) {
            foreach ($cols as $col) {
                $bytes += self::columnBytes(strtolower($col[1]), isset($col[3]) && $col[3] !== '' ? (int) $col[3] : null) + 2;
            }
        }
        // Unnamed helpers: id() 8, timestamps() 2×8, softDeletes() 8,
        // rememberToken() varchar(100).
        if (preg_match_all('/\$table->(\w+)\(\s*\)/', $block, $helpers, PREG_SET_ORDER)) {
            foreach ($helpers as $helper) {
                $bytes += match (strtolower($helper[1])) {
                    'id' => 8 + 2,
                    'timestamps', 'timestampstz' => 16 + 4,
                    'softdeletes', 'softdeletestz' => 8 + 2,
                    'remembertoken' => 400 + 2,
                    default => 0,
                };
            }
        }

        return $bytes;
    }

    private static function columnBytes(string $type, ?int $length): int
    {
        return match ($type) {
            'string', 'varchar', 'char' => ($length ?? 255) * 4,
            'text', 'mediumtext', 'longtext', 'blob', 'mediumblob', 'longblob', 'json', 'jsonb' => 12,
            'binary', 'varbinary' => $length ?? 255,
            'integer', 'int', 'foreignid', 'foreignuuid' => 4,
            'bigint', 'biginteger', 'unsignedbigint' => 8,
            'smallint', 'smallinteger', 'year' => 2,
            'tinyint', 'tinyinteger', 'boolean' => 1,
            'decimal', 'double', 'float', 'unsigneddecimal' => 8,
            'date' => 3,
            'datetime', 'datetimetz', 'timestamp', 'timestamptz' => 8,
            'time', 'timetz' => 3,
            'uuid' => 144,
            'ulid' => 104,
            'ipaddress' => 180,
            'macaddress' => 68,
            'enum', 'set' => 2,
            default => 1020, // unknown: assume a default varchar — flag rather than miss
        };
    }

    /**
     * The bounded body of one Schema::create call: from the call
     * to the matching close of its closure, brace-matched with
     * single-quoted strings skipped. (The unbounded version of
     * this scan is the phantom-column bug class documented in
     * SlicePortService::extractTablesFromMigrations.)
     */
    private static function createBlock(string $content, int $start): ?string
    {
        $open = strpos($content, '{', $start);
        if ($open === false) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $len = strlen($content);
        for ($i = $open; $i < $len; $i++) {
            $c = $content[$i];
            if ($inString) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === "'") {
                    $inString = false;
                }
                continue;
            }
            if ($c === "'") {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $open, $i - $open + 1);
                }
            }
        }

        return null;
    }
}
