<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Machine checks from MULTI-RUNTIME-PORTABILITY-CONTRACT.md §1–2.
 *
 * @phpstan-type Finding array{layer: string, file: string, line: int|null, rule: string, message: string}
 */
final class TrilogyPortabilityLint
{
    /** @var list<array{needle: string, rule: string, message: string}> */
    private const HEART_STRING_RULES = [
        ['needle' => 'App\\', 'rule' => 'heart.app_namespace', 'message' => 'Heart must not reference App\\ namespaces in YAML values.'],
        ['needle' => 'Illuminate\\', 'rule' => 'heart.framework_namespace', 'message' => 'Heart must not reference Illuminate\\ in YAML values.'],
        ['needle' => '.blade.php', 'rule' => 'heart.blade_path', 'message' => 'Heart must not embed Blade view paths (.blade.php).'],
        ['needle' => '.php', 'rule' => 'heart.php_path', 'message' => 'Heart must not embed PHP file paths (.php).'],
    ];

    /** @var list<array{pattern: string, rule: string, message: string}> */
    private const MIND_LINE_RULES = [
        ['pattern' => '/<\?php/i', 'rule' => 'mind.php_tag', 'message' => 'Mind must not contain PHP opening tags.'],
        ['pattern' => '/@php\b/', 'rule' => 'mind.blade_php', 'message' => 'Mind must not contain Blade @php directives.'],
        ['pattern' => '/\.blade\.php/i', 'rule' => 'mind.blade_path', 'message' => 'Mind must not reference Blade view files.'],
        ['pattern' => '/App\\\\/', 'rule' => 'mind.app_namespace', 'message' => 'Mind must not reference App\\ namespaces.'],
        ['pattern' => '/Illuminate\\\\/', 'rule' => 'mind.framework_namespace', 'message' => 'Mind must not reference Illuminate\\ namespaces.'],
        ['pattern' => '/<x-[\w.-]+/i', 'rule' => 'mind.blade_component', 'message' => 'Mind must not embed Blade component tags.'],
        ['pattern' => '/@livewire\b/i', 'rule' => 'mind.livewire', 'message' => 'Mind must not embed Livewire directives.'],
        ['pattern' => '/<script[\s>]/i', 'rule' => 'mind.script', 'message' => 'Mind must not embed executable script blocks.'],
    ];

    /** @var list<array{pattern: string, rule: string, message: string}> */
    private const BODY_LINE_RULES = [
        ['pattern' => '/<x-[\w.-]+/i', 'rule' => 'body.blade_component', 'message' => 'Body UI spec must not name Blade components (<x-…>).'],
        ['pattern' => '/Livewire\\\\/', 'rule' => 'body.livewire_class', 'message' => 'Body UI spec must not reference Livewire component classes.'],
        ['pattern' => '/Illuminate\\\\/', 'rule' => 'body.framework_class', 'message' => 'Body UI spec must not reference Illuminate classes.'],
    ];

    /**
     * @return list<Finding>
     */
    public function scan(?string $projectRoot = null): array
    {
        $root = $projectRoot ?? (function_exists('base_path') ? base_path() : (getcwd() ?: '.'));
        $findings = [];

        foreach ($this->discoverHeartFiles($root) as $file) {
            $findings = array_merge($findings, $this->scanHeartFile($file));
        }

        $mind = $root.DIRECTORY_SEPARATOR.'porting'.DIRECTORY_SEPARATOR.'system.yaml';
        if (is_file($mind)) {
            $findings = array_merge($findings, $this->scanLineRules($mind, 'mind', self::MIND_LINE_RULES));
        }

        $bodyDir = $root.DIRECTORY_SEPARATOR.'porting'.DIRECTORY_SEPARATOR.'ui-spec';
        if (is_dir($bodyDir)) {
            foreach (glob($bodyDir.DIRECTORY_SEPARATOR.'*.ui.yaml') ?: [] as $bodyFile) {
                $findings = array_merge($findings, $this->scanLineRules($bodyFile, 'body', self::BODY_LINE_RULES));
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    public function discoverHeartFiles(string $root): array
    {
        $files = [];
        $roots = [];

        $coreSlices = dirname(__DIR__).DIRECTORY_SEPARATOR.'Slices';
        if (is_dir($coreSlices)) {
            $roots[] = $coreSlices;
        }

        $appSlices = $this->resolveAppSlicesPath($root);

        if ($appSlices !== '' && is_dir($appSlices)) {
            $coreReal = is_dir($coreSlices) ? realpath($coreSlices) : false;
            $appReal = realpath($appSlices);
            if ($appReal !== false && ($coreReal === false || $appReal !== $coreReal)) {
                $roots[] = $appSlices;
            }
        }

        foreach ($roots as $scanRoot) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scanRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === 'slice.yaml') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    private function resolveAppSlicesPath(string $root): string
    {
        $default = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Slices';

        if (! function_exists('app')) {
            return $default;
        }

        try {
            $configured = app('config')->get('laraslice.slices_path');
        } catch (\Throwable) {
            return $default;
        }

        return is_string($configured) && $configured !== '' ? $configured : $default;
    }

    /**
     * @return list<Finding>
     */
    private function scanHeartFile(string $file): array
    {
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            return [[
                'layer' => 'heart',
                'file' => $file,
                'line' => $e->getParsedLine(),
                'rule' => 'heart.parse',
                'message' => 'Unable to parse slice.yaml: '.$e->getMessage(),
            ]];
        }

        if (! is_array($parsed)) {
            return [];
        }

        $findings = [];
        $this->walkScalars($parsed, '', function (string $value, string $path) use ($file, &$findings): void {
            foreach (self::HEART_STRING_RULES as $rule) {
                if ($rule['rule'] === 'heart.php_path' && self::isAllowedHeartPhpReference($path)) {
                    continue;
                }
                if (str_contains($value, $rule['needle'])) {
                    $findings[] = [
                        'layer' => 'heart',
                        'file' => $file,
                        'line' => null,
                        'rule' => $rule['rule'],
                        'message' => $rule['message'].' (at '.$path.')',
                    ];
                }
            }
        });

        return $findings;
    }

    private static function isAllowedHeartPhpReference(string $yamlPath): bool
    {
        return str_ends_with($yamlPath, '.migration')
            || str_contains($yamlPath, '.migrations.');
    }

    /**
     * @param  list<array{pattern: string, rule: string, message: string}>  $rules
     * @return list<Finding>
     */
    private function scanLineRules(string $file, string $layer, array $rules): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $findings = [];
        foreach ($lines as $index => $line) {
            foreach ($rules as $rule) {
                if (preg_match($rule['pattern'], $line) === 1) {
                    $findings[] = [
                        'layer' => $layer,
                        'file' => $file,
                        'line' => $index + 1,
                        'rule' => $rule['rule'],
                        'message' => $rule['message'],
                    ];
                }
            }
        }

        return $findings;
    }

    private function walkScalars(mixed $node, string $path, callable $onString): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $segment = is_int($key) ? (string) $key : (string) $key;
                $next = $path === '' ? $segment : $path.'.'.$segment;
                $this->walkScalars($child, $next, $onString);
            }

            return;
        }

        if (is_string($node)) {
            $onString($node, $path);
        }
    }
}
