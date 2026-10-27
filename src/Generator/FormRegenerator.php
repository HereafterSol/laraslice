<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManifest;

/**
 * Form-view regeneration for EXISTING slices (backlog E1).
 *
 * SliceGenerator only emits whole slices, so an installed
 * slice whose form has drifted from its Heart (or predates
 * the current templates) historically had to be rebuilt by
 * hand — the Tier-0.3 leaves form was. This service rebuilds
 * the field definitions from the slice's own manifest,
 * generates the slice into a throwaway staging root (the
 * BlueprintApplier pattern), and transplants ONLY the form
 * views (form/create/edit) back. Callers decide whether the
 * transplant is a dry-run report or a write with backups;
 * hand edits in the current form are visible in the diff
 * before anything is overwritten.
 */
class FormRegenerator
{
    /** @var array<int, string> */
    public const FORM_VIEWS = ['form.blade.php', 'create.blade.php', 'edit.blade.php'];

    /**
     * Generate the slice into a staging root and return the
     * staged form views keyed by file name.
     *
     * @return array{staging: string, sliceDir: string, files: array<string, string>}
     */
    public function stage(SliceManifest $manifest): array
    {
        $raw = $manifest->raw;
        $fields = [];
        foreach ((array) ($raw['fields'] ?? []) as $def) {
            if (! is_array($def) || empty($def['name'])) {
                continue;
            }
            $field = [
                'name' => (string) $def['name'],
                'label' => (string) ($def['label'] ?? Str::headline((string) $def['name'])),
                'type' => (string) ($def['type'] ?? 'string'),
                'required' => (bool) ($def['required'] ?? ! ($def['nullable'] ?? true)),
                'nullable' => (bool) ($def['nullable'] ?? true),
                'default' => $def['default'] ?? null,
                'options' => $def['options'] ?? [],
                'length' => $def['length'] ?? null,
            ];
            foreach (['relation', 'references', 'hidden', 'show_in_form', 'show_in_list', 'encrypted'] as $extra) {
                if (array_key_exists($extra, $def)) {
                    $field[$extra] = $def[$extra];
                }
            }
            $fields[] = $field;
        }

        $workflow = $raw['workflow'] ?? false;
        $includeWorkflow = is_array($workflow) ? $workflow !== [] : (bool) $workflow;

        $options = [
            'description' => (string) ($raw['description'] ?? ''),
            'domain' => $manifest->domain ?? ($raw['domain'] ?? null),
            'super_domain' => $manifest->super_domain ?? ($raw['super_domain'] ?? null),
            'app' => $manifest->app ?? ($raw['app'] ?? null),
            'ui' => is_array($raw['ui'] ?? null) ? $raw['ui'] : [],
            'table' => is_array($raw['tables'] ?? null) ? ($raw['tables'][0] ?? null) : null,
            'table_prefix' => $manifest->table_prefix ?? '',
            'tenant_key' => $manifest->tenant_key ?? 'company_id',
            'api' => true,
        ];
        if (is_array($workflow)) {
            $options['workflowConfig'] = $workflow;
        }

        $staging = sys_get_temp_dir().'/laraslice-regen-'.bin2hex(random_bytes(6));
        $generator = new SliceGenerator($staging, $manifest->getNamespace());
        $sliceDir = $generator->generate($manifest->name, $fields, $includeWorkflow, $options);

        $files = [];
        foreach (self::FORM_VIEWS as $view) {
            $path = $sliceDir.'/Resources/views/'.$view;
            if (file_exists($path)) {
                $files[$view] = $path;
            }
        }

        $this->remapRoutePrefixes($manifest, $files);

        return ['staging' => $staging, 'sliceDir' => $sliceDir, 'files' => $files];
    }

    /**
     * Route-name remap (regression guard): staging re-derives
     * route names from the manifest's tier fields, which for
     * ported trees does NOT reproduce the slice's registered
     * names (a regen once emitted erp_erp_erp_<slice>.* into
     * forms whose routes are plain <slice>.* — every form
     * 500'd on its back link). The slice's CURRENT blades are
     * the lived truth: if their dominant route() prefix
     * differs from the staged one, rewrite the staged prefix
     * to the current one before any transplant.
     *
     * @param  array<string, string>  $stagedFiles
     */
    private function remapRoutePrefixes(SliceManifest $manifest, array $stagedFiles): void
    {
        $currentDir = $manifest->path.'/Resources/views';
        $currentContents = [];
        foreach (array_merge(self::FORM_VIEWS, ['index.blade.php', 'show.blade.php']) as $view) {
            $p = $currentDir.'/'.$view;
            if (file_exists($p)) {
                $currentContents[] = (string) file_get_contents($p);
            }
        }
        $currentPrefix = self::dominantRoutePrefix($currentContents);
        if ($currentPrefix === null) {
            return; // a brand-new slice: staged names stand
        }
        foreach ($stagedFiles as $path) {
            $content = (string) file_get_contents($path);
            $stagedPrefix = self::dominantRoutePrefix([$content]);
            if ($stagedPrefix !== null && $stagedPrefix !== $currentPrefix) {
                $content = str_replace("route('{$stagedPrefix}.", "route('{$currentPrefix}.", $content);
                file_put_contents($path, $content);
            }
        }
    }

    /**
     * The most common route() name prefix across blade
     * contents (the slice's own CRUD names dominate its form
     * and index views), or null when no route() call exists.
     *
     * @param  array<int, string>  $contents
     */
    public static function dominantRoutePrefix(array $contents): ?string
    {
        $counts = [];
        foreach ($contents as $content) {
            if (preg_match_all("/route\\('([a-z0-9_.]+)'/", $content, $m)) {
                foreach ($m[1] as $name) {
                    if (str_contains($name, '.')) {
                        $prefix = substr($name, 0, strrpos($name, '.'));
                        $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
                    }
                }
            }
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Compare staged form views against the slice's current
     * ones, line counts only — the caller shows the report
     * and the staging dir can be diffed in full.
     *
     * @param  array<string, string>  $stagedFiles
     * @return array<int, array{view: string, status: string, current_lines: ?int, staged_lines: ?int}>
     */
    public function diff(SliceManifest $manifest, array $stagedFiles): array
    {
        $report = [];
        foreach (self::FORM_VIEWS as $view) {
            $current = $manifest->path.'/Resources/views/'.$view;
            $staged = $stagedFiles[$view] ?? null;
            if ($staged === null) {
                $report[] = ['view' => $view, 'status' => 'not-generated', 'current_lines' => file_exists($current) ? count(file($current) ?: []) : null, 'staged_lines' => null];
                continue;
            }
            if (! file_exists($current)) {
                $report[] = ['view' => $view, 'status' => 'new', 'current_lines' => null, 'staged_lines' => count(file($staged) ?: [])];
                continue;
            }
            $currentContent = (string) file_get_contents($current);
            $stagedContent = (string) file_get_contents($staged);
            $report[] = [
                'view' => $view,
                'status' => $currentContent === $stagedContent ? 'identical' : 'changed',
                'current_lines' => count(file($current) ?: []),
                'staged_lines' => count(file($staged) ?: []),
                // Surfaces the Heart cannot re-derive (pivot
                // multi-selects, hand-added inputs): named
                // inputs and $…Options variables present in
                // the current form but absent from the staged
                // one would be LOST by a transplant.
                'lost' => $this->surfaceTokens($currentContent) !== [] && $currentContent !== $stagedContent
                    ? array_values(array_diff($this->surfaceTokens($currentContent), $this->surfaceTokens($stagedContent)))
                    : [],
            ];
        }

        return $report;
    }

    /**
     * Named form inputs and options variables a blade
     * renders — the surface tokens used for loss detection.
     *
     * @return array<int, string>
     */
    private function surfaceTokens(string $blade): array
    {
        $tokens = [];
        if (preg_match_all('/name="([a-zA-Z0-9_\[\]]+)"/', $blade, $m)) {
            foreach ($m[1] as $name) {
                $tokens[] = 'input:'.$name;
            }
        }
        if (preg_match_all('/\$([a-zA-Z]+Options)\b/', $blade, $m)) {
            foreach ($m[1] as $var) {
                $tokens[] = 'options:$'.$var;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Copy staged form views over the slice's, backing the
     * originals up first. Returns the backup directory.
     *
     * @param  array<string, string>  $stagedFiles
     */
    public function apply(SliceManifest $manifest, array $stagedFiles, string $backupRoot): string
    {
        $backupDir = rtrim($backupRoot, '/').'/'.$manifest->name.'-'.date('YmdHis');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $targetDir = $manifest->path.'/Resources/views';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        foreach ($stagedFiles as $view => $stagedPath) {
            $target = $targetDir.'/'.$view;
            if (file_exists($target)) {
                copy($target, $backupDir.'/'.$view);
            }
            copy($stagedPath, $target);
        }

        return $backupDir;
    }

    public function discardStaging(string $staging): void
    {
        if (! is_dir($staging)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($staging);
    }
}
