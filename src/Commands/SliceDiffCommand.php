<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Item 5: CLI parity with Studio YAML preview.
 * Prints a unified diff and writes nothing.
 */
final class SliceDiffCommand extends Command
{
    protected $signature = 'slice:diff
                            {slice : Slice name}
                            {--against= : Optional YAML/JSON file to diff against the current manifest}';

    protected $description = 'Print a unified YAML diff for a slice. Writes nothing.';

    public function handle(SliceManager $manager): int
    {
        $manager->discover();
        $slice = $manager->getSlice($this->argument('slice'));
        if (! $slice) {
            $this->error('Slice not found: '.$this->argument('slice'));
            return self::FAILURE;
        }

        $current = ManifestRepository::readFromSliceDir($slice->path);
        $currentYaml = Yaml::dump(ManifestRepository::normalize($current), 6, 2);

        $against = $this->option('against');
        if (is_string($against) && $against !== '' && is_file($against)) {
            $raw = file_get_contents($against);
            $other = str_ends_with(strtolower($against), '.json')
                ? json_decode($raw, true)
                : Yaml::parse($raw);
            $otherYaml = Yaml::dump(ManifestRepository::normalize(is_array($other) ? $other : []), 6, 2);
            $label = basename($against);
        } else {
            $json = $slice->path.'/slice.json';
            if (! is_file($json)) {
                $this->info('(no changes) — YAML only, nothing to diff against.');
                return self::SUCCESS;
            }
            $other = ManifestRepository::read($json);
            $otherYaml = Yaml::dump(ManifestRepository::normalize($other), 6, 2);
            $label = 'slice.json';
        }

        $diff = ManifestRepository::unifiedDiff($currentYaml, $otherYaml, 'slice.yaml', $label);
        $this->line($diff);
        return self::SUCCESS;
    }
}
