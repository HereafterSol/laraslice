<?php

namespace LaraSlice\Generator;

use InvalidArgumentException;

final class SliceNamespace
{
    public static function validate(string $namespace): string
    {
        $namespace = trim($namespace, " \t\n\r\0\x0B\\");

        if ($namespace === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace)) {
            throw new InvalidArgumentException('The slice namespace must contain valid PHP namespace segments separated by backslashes.');
        }

        return $namespace;
    }
}
