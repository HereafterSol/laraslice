<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The team blueprint library: named blueprints kept as
 * plain files under porting/blueprints/, one
 * <slug>.blueprint.yaml per entry.
 *
 * Files on purpose — no table, no model: the library
 * travels with the project in Git, so a design one person
 * saves is on every teammate's disk after a pull, and
 * Declaration History tracks the files like every other
 * declaration. Saving over an existing slug updates it;
 * the library is a shelf, not a version store (Git and
 * the history ledger already do versions).
 */
class BlueprintLibrary
{
    public function __construct(private readonly ?string $appRoot = null) {}

    private function root(): string
    {
        return $this->appRoot ?? base_path();
    }

    public function dir(): string
    {
        return $this->root().'/porting/blueprints';
    }

    private function pathFor(string $slug): ?string
    {
        // A slug is a file name, never a path: lowercase
        // words, digits, dashes and underscores only. This
        // is the whole traversal defence — anything with a
        // separator, a dot or a leading dash simply has no
        // file here.
        if (preg_match('/^[a-z0-9][a-z0-9\-_]*$/', $slug) !== 1) {
            return null;
        }

        return $this->dir().'/'.$slug.'.blueprint.yaml';
    }

    /**
     * Every library entry, lightest facts first: the file
     * is parsed best-effort for its display name and how
     * much it declares (slices in a suite, models in a
     * single blueprint); an unparseable file still lists,
     * under its slug, with zero.
     *
     * @return list<array{slug: string, name: string, slices: int, size: int, updated_at: string}>
     */
    public function all(): array
    {
        $entries = [];
        if (is_dir($this->dir())) {
            foreach (glob($this->dir().'/*.blueprint.yaml') ?: [] as $file) {
                $slug = basename($file, '.blueprint.yaml');
                $entries[] = $this->summarize($slug, $file);
            }
        }
        usort($entries, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * One entry with its raw YAML — what the Design panel
     * loads when a teammate opens a shared blueprint.
     *
     * @return array{slug: string, name: string, yaml: string}|null
     */
    public function get(string $slug): ?array
    {
        $path = $this->pathFor($slug);
        if ($path === null || ! is_file($path)) {
            return null;
        }
        $yaml = (string) file_get_contents($path);

        return [
            'slug' => $slug,
            'name' => $this->summarize($slug, $path, $yaml)['name'],
            'yaml' => $yaml,
        ];
    }

    /**
     * Save (or overwrite) a library blueprint. The YAML
     * must parse and declare something — a name, models,
     * or a slices suite. The full BlueprintValidator is
     * deliberately NOT the gate here: it validates a
     * single blueprint for generation, while the library
     * also shelves suites and works-in-progress verbatim;
     * the stored text is the author's own, unrewritten.
     *
     * @return array{slug: string, name: string, slices: int, size: int, updated_at: string}
     *
     * @throws InvalidArgumentException on any validation failure
     */
    public function save(string $name, string $yaml): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Give the blueprint a name before saving it to the library.');
        }
        if (trim($yaml) === '') {
            throw new InvalidArgumentException('There is no blueprint YAML to save yet.');
        }
        try {
            $doc = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new InvalidArgumentException('The blueprint YAML does not parse: '.$e->getMessage());
        }
        if (! is_array($doc) || (! array_key_exists('name', $doc) && ! array_key_exists('models', $doc) && ! array_key_exists('slices', $doc))) {
            throw new InvalidArgumentException('The blueprint must declare a name, models, or a slices suite to belong in the library.');
        }

        $slug = Str::slug($name);
        if ($slug === '') {
            throw new InvalidArgumentException('The name "'. $name.'" does not produce a usable file name — rename it with letters or numbers.');
        }
        if (! is_dir($this->dir())) {
            mkdir($this->dir(), 0775, true);
        }
        $path = $this->dir().'/'.$slug.'.blueprint.yaml';
        file_put_contents($path, $yaml);

        return $this->summarize($slug, $path, $yaml);
    }

    public function delete(string $slug): bool
    {
        $path = $this->pathFor($slug);
        if ($path === null || ! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * The all() facts for one file. $yaml may be passed
     * when the caller already holds the contents.
     *
     * @return array{slug: string, name: string, slices: int, size: int, updated_at: string}
     */
    private function summarize(string $slug, string $path, ?string $yaml = null): array
    {
        $yaml ??= (string) @file_get_contents($path);
        $name = $slug;
        $count = 0;
        try {
            $doc = Yaml::parse($yaml);
        } catch (ParseException) {
            $doc = null;
        }
        if (is_array($doc)) {
            if (isset($doc['name']) && is_string($doc['name']) && trim($doc['name']) !== '') {
                $name = trim($doc['name']);
            }
            if (isset($doc['slices']) && is_array($doc['slices'])) {
                $count = count($doc['slices']);
            } elseif (isset($doc['models']) && is_array($doc['models'])) {
                $count = count($doc['models']);
            }
        }

        return [
            'slug' => $slug,
            'name' => $name,
            'slices' => $count,
            'size' => (int) @filesize($path),
            'updated_at' => date('c', (int) @filemtime($path)),
        ];
    }
}
