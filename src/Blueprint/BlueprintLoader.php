<?php

namespace LaraSlice\Blueprint;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class BlueprintLoader
{
    /** @return array<string, mixed> */
    public function load(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Blueprint file does not exist or is not readable: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read blueprint file: {$path}");
        }

        return $this->parse($contents, $extension);
    }

    /** @return array<string, mixed> */
    public function parse(string $contents, string $format): array
    {
        try {
            $data = match (strtolower($format)) {
                'json' => json_decode($contents, true, 512, JSON_THROW_ON_ERROR),
                'yaml', 'yml' => Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE),
                default => throw new RuntimeException('Blueprint format must be json, yaml, or yml.'),
            };
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'Blueprint format')) {
                throw $exception;
            }

            throw new RuntimeException('Unable to parse blueprint: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Blueprint root must be an object/map.');
        }

        return $data;
    }
}
