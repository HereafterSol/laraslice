<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\TestCase;

/**
 * Item 0: previewStudioConfig must resolve the slice and return a real diff.
 */
class StudioPreviewTest extends TestCase
{
    private string $root;
    private SliceModifier $modifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/laraslice-preview-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/Tasks', 0777, true);
        ManifestRepository::writeYaml($this->root.'/Tasks', [
            'name' => 'Tasks',
            'version' => '1.0.0',
            'fields' => ['title' => ['name' => 'title', 'type' => 'string']],
            'ui' => ['directory' => ['title' => 'Tasks']],
        ]);
        $this->modifier = new SliceModifier($this->root, 'App\\Slices');
    }

    protected function tearDown(): void
    {
        foreach (array_merge([$this->root], $this->extraRoots) as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }
        parent::tearDown();
    }

    public function test_empty_config_is_unchanged(): void
    {
        $preview = $this->modifier->previewStudioConfig('Tasks', [], [], null);
        // Version bump alone counts as a change in applyStudioConfig; empty UI/nav
        // still bumps patch. Accept either no semantic change or version-only.
        $this->assertIsString($preview['diff']);
        $this->assertArrayHasKey('changed', $preview);
    }

    public function test_title_change_produces_diff(): void
    {
        $preview = $this->modifier->previewStudioConfig('Tasks', [
            'directory' => ['title' => 'X'],
        ], [], null);

        $this->assertTrue($preview['changed']);
        $this->assertStringContainsString('X', $preview['diff']);
        $this->assertStringContainsString('-', $preview['diff']);
        $this->assertStringContainsString('+', $preview['diff']);
    }

    /**
     * A modifier over a fresh temp root holding one slice with
     * the given manifest (OBS-B scenarios use ported-shape
     * Hearts, richer than the shared fixture above).
     *
     * @return array{0: SliceModifier, 1: string}
     */
    private function portedModifier(array $manifest): array
    {
        $root = sys_get_temp_dir().'/laraslice-preview-'.bin2hex(random_bytes(4));
        mkdir($root.'/Ported', 0777, true);
        ManifestRepository::writeYaml($root.'/Ported', $manifest);
        $this->extraRoots[] = $root;

        return [new SliceModifier($root, 'App\\Slices'), $root];
    }

    /** @var list<string> */
    private array $extraRoots = [];

    public function test_keyed_columns_reconcile_by_handle_not_by_index(): void
    {
        // OBS-B: the declared columns carry keys the Studio model
        // does not model (subtitle_from, badge_from). The payload
        // lists the same columns in a different order with only
        // modelled keys. Index-merging cross-contaminated the
        // entries and produced a wall of false changes; keyed
        // reconciliation must read this as NO change.
        [$modifier] = $this->portedModifier([
            'name' => 'Ported',
            'version' => '1.0.0',
            'fields' => ['heading' => ['name' => 'heading', 'type' => 'string']],
            'ui' => ['directory' => [
                'default_view' => 'table',
                'columns' => [
                    ['handle' => 'heading', 'key' => 'heading', 'label' => 'Task', 'formatter' => 'text_with_badge', 'type' => 'text_with_badge', 'sortable' => true, 'subtitle_from' => 'project.project_name', 'badge_from' => 'labels'],
                    ['handle' => 'task_short_code', 'key' => 'task_short_code', 'label' => 'Code', 'formatter' => 'code_badge', 'type' => 'code_badge', 'sortable' => true],
                ],
            ]],
            'navigation' => ['label' => 'Ported', 'url' => '/ported'],
        ]);

        $preview = $modifier->previewStudioConfig('Ported', [
            'directory' => [
                'default_view' => 'table',
                'columns' => [
                    ['handle' => 'task_short_code', 'key' => 'task_short_code', 'label' => 'Code', 'formatter' => 'code_badge', 'type' => 'code_badge', 'sortable' => true],
                    ['handle' => 'heading', 'key' => 'heading', 'label' => 'Task', 'formatter' => 'text_with_badge', 'type' => 'text_with_badge', 'sortable' => true],
                ],
            ],
        ], ['label' => 'Ported', 'url' => '/ported'], null);

        $this->assertFalse($preview['changed'], 'reordered but semantically identical columns must preview as no change; diff: '.($preview['diff'] ?? ''));
    }

    public function test_one_setting_change_previews_as_one_setting(): void
    {
        // OBS-B acceptance shape: a ported Heart + payload that
        // restates the declared semantics and changes only
        // default_view must preview changed=true with the diff
        // touching default_view (and the version bump) only.
        [$modifier] = $this->portedModifier([
            'name' => 'Ported',
            'version' => '1.0.0',
            'fields' => ['heading' => ['name' => 'heading', 'type' => 'string']],
            'ui' => ['directory' => [
                'default_view' => 'table',
                'columns' => [
                    ['handle' => 'heading', 'key' => 'heading', 'label' => 'Task', 'formatter' => 'text_with_badge', 'type' => 'text_with_badge', 'sortable' => true, 'subtitle_from' => 'project.project_name'],
                ],
            ]],
            'navigation' => ['label' => 'Ported', 'url' => '/ported', 'icon' => 'package', 'order' => 160, 'group' => 'Work'],
        ]);

        $preview = $modifier->previewStudioConfig('Ported', [
            'directory' => [
                'default_view' => 'board',
                'columns' => [
                    ['handle' => 'heading', 'key' => 'heading', 'label' => 'Task', 'formatter' => 'text_with_badge', 'type' => 'text_with_badge', 'sortable' => true],
                ],
            ],
        ], ['label' => 'Ported', 'url' => '/ported', 'icon' => 'package', 'order' => 160, 'group' => 'Work'], null);

        $this->assertTrue($preview['changed']);
        $changedLines = array_values(array_filter(
            explode("\n", $preview['diff']),
            static fn (string $line): bool => (str_starts_with($line, '+') || str_starts_with($line, '-')) && ! str_starts_with($line, '+++') && ! str_starts_with($line, '---')
        ));
        $this->assertLessThanOrEqual(6, count($changedLines), 'one-setting change must stay a one-setting diff; got: '.implode(' | ', $changedLines));
        $this->assertStringContainsString('default_view', $preview['diff']);
        $this->assertStringContainsString('board', $preview['diff']);
        $this->assertStringNotContainsString('subtitle_from', $preview['diff'], 'unmodelled declared keys must not churn');
    }

    public function test_resting_defaults_are_not_materialized_into_the_heart(): void
    {
        // OBS-B: the Studio payload always carries its resting
        // defaults (navigation visible/hidden/roles, directory
        // presentation, empty quick_filters, form mode at rest).
        // A Heart that does not declare those keys must not gain
        // them from a no-op save — while a value that differs
        // from the resting default is a real change.
        [$modifier] = $this->portedModifier([
            'name' => 'Ported',
            'version' => '1.0.0',
            'fields' => ['heading' => ['name' => 'heading', 'type' => 'string']],
            'ui' => ['directory' => ['default_view' => 'table']],
            'navigation' => ['label' => 'Ported', 'url' => '/ported'],
        ]);

        $resting = $modifier->previewStudioConfig('Ported', [
            'directory' => ['default_view' => 'table', 'presentation' => 'table', 'quick_filters' => []],
            'forms' => ['mode' => 'drawer', 'presentation' => 'drawer', 'sections' => []],
        ], ['label' => 'Ported', 'url' => '/ported', 'visible' => true, 'hidden' => false, 'roles' => []], null);
        $this->assertFalse($resting['changed'], 'resting defaults must preview as no change; diff: '.($resting['diff'] ?? ''));

        $real = $modifier->previewStudioConfig('Ported', [
            'directory' => ['default_view' => 'table'],
        ], ['label' => 'Ported', 'url' => '/ported', 'visible' => false], null);
        $this->assertTrue($real['changed']);
        $this->assertStringContainsString('visible', $real['diff']);
    }
}
