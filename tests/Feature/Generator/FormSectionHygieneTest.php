<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;

/**
 * Form-section hygiene parity (Comparative Fix Programme
 * Phase A): the sectioned form path applies the same
 * audit/system exclusion as the default path. Ported
 * manifests list every handle in ui.forms.sections —
 * including added_by / last_updated_by / hash — and the
 * section renderer used to emit them all as editable
 * inputs while the default path blacklisted them.
 */
final class FormSectionHygieneTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-sechyg-'.uniqid();
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_sectioned_forms_exclude_audit_and_hidden_fields(): void
    {
        $sliceDir = (new SliceGenerator($this->root, 'App\\Slices'))->generate('SectionWidget', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'added_by', 'type' => 'integer', 'nullable' => true],
            ['name' => 'internal_note', 'type' => 'string', 'nullable' => true, 'show_in_form' => false],
        ], false, [
            'ui' => [
                'forms' => [
                    'sections' => [
                        [
                            'key' => 'general',
                            'title' => 'General Information',
                            'grid' => 2,
                            'fields' => [
                                ['handle' => 'title', 'span' => 1],
                                ['handle' => 'added_by', 'span' => 1],
                                ['handle' => 'internal_note', 'span' => 1],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $form = (string) file_get_contents($sliceDir.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('name="title"', $form);
        $this->assertStringNotContainsString('name="added_by"', $form);
        $this->assertStringNotContainsString('name="internal_note"', $form);
    }
}
