<?php

namespace LaraSlice\Core\Discovery;

/**
 * Reads and writes slice manifests in one canonical shape.
 *
 * Phase 1 (YAML elimination): dual-read prefers slice.yaml, falls back to slice.json,
 * and auto-migrates JSON-only slices by writing YAML (JSON is kept).
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

        if (self::isYamlPath($file)) {
            return self::parseYaml($contents, $file);
        }

        return self::normalize(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Phase 1 dual-read for a slice directory.
     *
     * Priority:
     *  1. Valid slice.yaml / slice.yml → use it (YAML wins)
     *  2. Malformed YAML → warn and fall back to slice.json (never fatal)
     *  3. JSON only → read JSON, write equivalent YAML (keep JSON), return JSON
     *  4. Neither → throw
     *
     * Does NOT delete JSON. Write-YAML-only is Phase 2.
     *
     * @return array<string, mixed>
     */
    public static function readFromSliceDir(string $dir): array
    {
        $dir = rtrim($dir, '/\\');
        $yaml = null;
        foreach (['slice.yaml', 'slice.yml'] as $name) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$name;
            if (is_file($candidate)) {
                $yaml = $candidate;
                break;
            }
        }
        $json = $dir.DIRECTORY_SEPARATOR.'slice.json';
        $hasJson = is_file($json);

        if ($yaml !== null) {
            try {
                $manifest = self::read($yaml);
                // Round-trip check: re-dump and parse must match
                self::assertRoundTrip($manifest, $yaml);
                return $manifest;
            } catch (\Throwable $e) {
                self::warn("Malformed YAML at {$yaml}: ".$e->getMessage().' — falling back to slice.json');
                if ($hasJson) {
                    return self::read($json);
                }
                throw $e;
            }
        }

        if ($hasJson) {
            $manifest = self::read($json);
            // Auto-migrate-on-read: write YAML twin, keep JSON
            self::writeYamlTwin($json, $manifest);
            return $manifest;
        }

        throw new \RuntimeException("No slice.yaml or slice.json in {$dir}");
    }

    private static function isYamlPath(string $file): bool
    {
        $lower = strtolower($file);
        return str_ends_with($lower, '.yaml') || str_ends_with($lower, '.yml');
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseYaml(string $contents, string $file): array
    {
        if (! class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            throw new \RuntimeException("symfony/yaml is required to read {$file}");
        }
        $data = \Symfony\Component\Yaml\Yaml::parse($contents);
        if (! is_array($data)) {
            throw new \RuntimeException("YAML manifest must be a map: {$file}");
        }
        return self::normalize($data);
    }

    /**
     * Migrated / existing YAML must parse back to the same normalized manifest.
     */
    private static function assertRoundTrip(array $manifest, string $yamlFile): void
    {
        if (! class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return;
        }
        $dumped = \Symfony\Component\Yaml\Yaml::dump(
            self::normalize($manifest),
            8,
            2,
            \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
        );
        $reparsed = \Symfony\Component\Yaml\Yaml::parse($dumped);
        if (! is_array($reparsed)) {
            throw new \RuntimeException("YAML round-trip failed for {$yamlFile}");
        }
        $a = self::normalize($manifest);
        $b = self::normalize($reparsed);
        if ($a != $b) {
            throw new \RuntimeException("YAML round-trip mismatch for {$yamlFile}");
        }
    }

    private static function warn(string $message): void
    {
        if (class_exists(\Illuminate\Support\Facades\Log::class)) {
            try {
                \Illuminate\Support\Facades\Log::warning('[LaraSlice] '.$message);
                return;
            } catch (\Throwable $e) {
                // fall through
            }
        }
        error_log('[LaraSlice] '.$message);
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

    /**
     * Write GitOps twin slice.yaml next to slice.json (same payload, YAML form).
     * Keeps file + Studio edits in sync for developers who prefer YAML reviews/PRs.
     */
    public static function writeYamlTwin(string $jsonFile, array $manifest): void
    {
        $dir = dirname($jsonFile);
        $yamlPath = $dir.DIRECTORY_SEPARATOR.'slice.yaml';
        // Prefer symfony/yaml if available via Laravel
        try {
            if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                $yaml = \Symfony\Component\Yaml\Yaml::dump(
                    self::normalize($manifest),
                    8,
                    2,
                    \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
                );
            } elseif (class_exists(\Illuminate\Support\Facades\Yaml::class)) {
                $yaml = \Illuminate\Support\Facades\Yaml::dump(self::normalize($manifest));
            } else {
                // Minimal fallback: JSON-compatible structure as YAML-ish
                $yaml = "# LaraSlice GitOps twin of slice.json — edit either; slice:sync reconciles\n"
                    .json_encode(self::normalize($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            file_put_contents($yamlPath, $yaml, LOCK_EX);
        } catch (\Throwable $e) {
            // Non-fatal: JSON remains source of truth at runtime
        }
    }

    /**
     * Persist both slice.json and slice.yaml.
     */
    public static function writeBoth(string $jsonFile, array $manifest): void
    {
        self::write($jsonFile, $manifest);
        self::writeYamlTwin($jsonFile, $manifest);
    }
}
