<?php

namespace LaraSlice\Core\Discovery;

/**
 * Reads and writes slice.json manifests in one canonical shape.
 *
 * "fields" (and each "child_fields" table) is a map keyed by column name, e.g.
 * {"sku": {"name": "sku", "type": "string", ...}}. Manifests written as a list of
 * {name: ...} entries by earlier versions are converted when read.
 */
final class ManifestRepository
{
    public static function read(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException("Slice manifest not found: {$file}");
        }

        return self::normalize(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
    }

    public static function write(string $file, array $manifest): void
    {
        $json = json_encode(self::normalize($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write slice manifest: {$file}");
        }
    }

    public static function normalize(array $manifest): array
    {
        if (isset($manifest['fields']) && is_array($manifest['fields'])) {
            $manifest['fields'] = self::fieldMap($manifest['fields']);
        }
        foreach ($manifest['child_fields'] ?? [] as $table => $fields) {
            if (is_array($fields)) {
                $manifest['child_fields'][$table] = self::fieldMap($fields);
            }
        }

        return $manifest;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fieldMap(array $fields): array
    {
        $map = [];
        foreach ($fields as $key => $field) {
            if (! is_array($field)) {
                continue;
            }
            $name = is_string($key) ? $key : ($field['name'] ?? $field['handle'] ?? null);
            if (! is_string($name) || $name === '') {
                continue;
            }
            $map[$name] = ['name' => $name] + $field;
        }

        return $map;
    }
}
