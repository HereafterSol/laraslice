<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class SliceName
{
    /** PHP keywords and reserved words that cannot be used as class names (lowercase). */
    private const PHP_RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false',
        'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match',
        'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private',
        'protected', 'public', 'readonly', 'require', 'resource', 'return', 'self', 'static', 'string',
        'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    /** Class names imported by generated slice files; a slice with the same name would not compile. */
    private const GENERATOR_IMPORTS = [
        'auditableslice', 'belongsto', 'blueprint', 'builder', 'column', 'field', 'migration', 'model',
        'request', 'route', 'schema', 'slice', 'str', 'validator',
    ];

    /** Return the canonical class name for a user-supplied slice name. */
    public static function canonical(string $name): string
    {
        $name = trim($name);

        if ($name === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9 _-]*$/', $name)) {
            throw new InvalidArgumentException('Use a slice name that starts with a letter and contains only letters, numbers, spaces, hyphens, or underscores.');
        }

        $canonical = Str::studly(Str::singular($name));

        if ($canonical === '' || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $canonical)) {
            throw new InvalidArgumentException('The supplied slice name does not produce a valid PHP class name.');
        }

        $lower = strtolower($canonical);
        if (in_array($lower, self::PHP_RESERVED, true) || in_array($lower, self::GENERATOR_IMPORTS, true)) {
            throw new InvalidArgumentException("\"{$canonical}\" is a reserved PHP or framework name; choose a different slice name.");
        }

        return $canonical;
    }

    /**
     * Return the namespace segment for a domain name (e.g. "Human Resources" -> "HumanResources").
     */
    public static function domainSegment(string $domain): string
    {
        $segment = Str::studly(Str::slug(trim($domain)));

        if ($segment === '' || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $segment) || in_array(strtolower($segment), self::PHP_RESERVED, true)) {
            throw new InvalidArgumentException('Use a domain name that starts with a letter, so it can be used as a PHP namespace segment.');
        }

        return $segment;
    }
}
