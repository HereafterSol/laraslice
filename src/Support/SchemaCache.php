<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers table and column existence per database connection, so hot paths
 * (audit logging, userstamps) do not query the schema on every save.
 *
 * The cache is cleared whenever migrations finish (see the service provider).
 */
final class SchemaCache
{
    /** @var array<string, array<int, string>|false> table => column names, or false when missing */
    private static array $tables = [];

    public static function hasTable(string $table): bool
    {
        return self::columns($table) !== false;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $columns = self::columns($table);

        return $columns !== false && in_array(strtolower($column), $columns, true);
    }

    public static function flush(): void
    {
        self::$tables = [];
    }

    /** @return array<int, string>|false */
    private static function columns(string $table): array|false
    {
        $key = DB::getDefaultConnection().':'.$table;
        if (! array_key_exists($key, self::$tables)) {
            // Cache POSITIVES only. A missing table is a transient
            // state (install, migration, a database rebuilt under a
            // running server): caching the negative would blind
            // every consumer — audit logging, schema guards — for
            // the whole life of a long-running process.
            if (! Schema::hasTable($table)) {
                return false;
            }
            self::$tables[$key] = array_map('strtolower', Schema::getColumnListing($table));
        }

        return self::$tables[$key];
    }
}
