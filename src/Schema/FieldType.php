<?php

namespace LaraSlice\Schema;

/**
 * The column types fields can use, shared by the slice modifier, child entities and the generator.
 *
 * Types are matched case-insensitively; aliases such as "int", "bool", "email" and "enum" resolve
 * to the Laravel schema builder method that creates the column.
 */
final class FieldType
{
    /** Schema builder method for each accepted type, keyed by the lower-cased type */
    public const COLUMN_METHODS = [
        'string' => 'string',
        'email' => 'string',
        'url' => 'string',
        'enum' => 'string',
        'select' => 'string',
        'text' => 'text',
        'mediumtext' => 'mediumText',
        'longtext' => 'longText',
        'integer' => 'integer',
        'int' => 'integer',
        'biginteger' => 'bigInteger',
        'smallinteger' => 'smallInteger',
        'tinyinteger' => 'tinyInteger',
        'unsignedinteger' => 'unsignedInteger',
        'unsignedbiginteger' => 'unsignedBigInteger',
        'foreign_id' => 'unsignedBigInteger',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'decimal' => 'decimal',
        'float' => 'float',
        'double' => 'double',
        'date' => 'date',
        'datetime' => 'dateTime',
        'timestamp' => 'timestamp',
        'time' => 'time',
        'json' => 'json',
        'uuid' => 'uuid',
        'binary' => 'binary',
    ];

    /** Types whose values can be stored with Laravel's encrypted casts */
    public const ENCRYPTABLE = ['string', 'text', 'email', 'url', 'json'];

    public static function exists(string $type): bool
    {
        return isset(self::COLUMN_METHODS[strtolower($type)]);
    }

    /** The schema builder method for $type, or null when the type is unknown. */
    public static function columnMethod(string $type): ?string
    {
        return self::COLUMN_METHODS[strtolower($type)] ?? null;
    }

    public static function isEncryptable(string $type): bool
    {
        return in_array(strtolower($type), self::ENCRYPTABLE, true);
    }

    /**
     * Whether $default is a valid default for a column of $type: strings for text-like and date
     * columns, integers for integer columns, numbers for decimals and booleans for booleans.
     */
    public static function defaultMatches(string $type, mixed $default): bool
    {
        if ($default === null) {
            return true;
        }

        return match (self::columnMethod($type)) {
            'string', 'text', 'mediumText', 'longText', 'json', 'uuid', 'binary',
            'date', 'dateTime', 'timestamp', 'time' => is_string($default),
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'unsignedInteger', 'unsignedBigInteger' => is_int($default),
            'decimal', 'float', 'double' => is_numeric($default),
            'boolean' => is_bool($default),
            default => false,
        };
    }
}
