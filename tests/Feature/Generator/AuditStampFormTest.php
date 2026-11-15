<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;

/**
 * Audit-stamp display (D-013, owner-ordered source matching):
 * Heart-declared *_by user stamps (added_by / created_by / …)
 * never become form inputs, but the source forms SHOW them —
 * a CRM Note's form carries its "Added By". Generated forms
 * therefore carry the stamps as read-only display rows in a
 * trailing Audit section, gated to edit forms only
 * (@if !$isNew): no input, no name attribute, no part in
 * submission; the displayed value resolves to the user's
 * name through RecordWorkplace::userLabel with a raw-value
 * fallback.
 */
final class AuditStampFormTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-auditstamp-'.uniqid();
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

    public function test_edit_form_carries_read_only_stamp_rows_and_no_stamp_input(): void
    {
        $sliceDir = (new SliceGenerator($this->root, 'App\\Slices'))->generate('StampNote', [
            ['name' => 'note', 'type' => 'text', 'label' => 'Note', 'nullable' => true],
            ['name' => 'added_by', 'type' => 'integer', 'label' => 'Added By', 'nullable' => true],
            ['name' => 'created_by', 'type' => 'integer', 'label' => 'Created By', 'nullable' => true],
        ], false, ['table' => 'stamp_notes']);

        $form = (string) file_get_contents($sliceDir.'/Resources/views/form.blade.php');

        // The stamp section exists, is edit-only, and carries
        // one read-only row per declared stamp with its Heart
        // label and the user-name resolver.
        $this->assertStringContainsString('data-ls-audit-stamps', $form);
        $this->assertStringContainsString('@if(!$isNew)', $form);
        $stampPos = strpos($form, 'data-ls-audit-stamps');
        $guardPos = strrpos(substr($form, 0, (int) $stampPos), '@if(!$isNew)');
        $this->assertNotFalse($guardPos, 'Stamp section must sit inside an edit-only @if(!$isNew) guard.');
        $this->assertStringContainsString('data-ls-audit-stamp="added_by"', $form);
        $this->assertStringContainsString('data-ls-audit-stamp="created_by"', $form);
        $this->assertStringContainsString('>Added By</span>', $form);
        $this->assertStringContainsString('>Created By</span>', $form);
        $this->assertStringContainsString('RecordWorkplace::userLabel($form->added_by ?? null)', $form);
        $this->assertStringContainsString('RecordWorkplace::userLabel($form->created_by ?? null)', $form);

        // Never an input: no element may carry the stamp
        // field's name (submission / BO stay stamp-free).
        $this->assertStringNotContainsString('name="added_by"', $form);
        $this->assertStringNotContainsString('name="created_by"', $form);
        $this->assertStringNotContainsString('name=\'added_by\'', $form);

        // The ordinary field still renders as an input.
        $this->assertStringContainsString('name="note"', $form);
    }

    public function test_sectioned_form_also_carries_the_stamp_rows(): void
    {
        $sliceDir = (new SliceGenerator($this->root, 'App\\Slices'))->generate('SectionStamp', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'added_by', 'type' => 'integer', 'label' => 'Added By', 'nullable' => true],
        ], false, [
            'table' => 'section_stamps',
            'ui' => [
                'forms' => [
                    'sections' => [
                        [
                            'key' => 'general',
                            'title' => 'General Information',
                            'fields' => [
                                ['handle' => 'title', 'span' => 1],
                                ['handle' => 'added_by', 'span' => 1],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $form = (string) file_get_contents($sliceDir.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('name="title"', $form);
        $this->assertStringContainsString('data-ls-audit-stamp="added_by"', $form);
        $this->assertStringContainsString('>Added By</span>', $form);
        $this->assertStringNotContainsString('name="added_by"', $form);
    }

    public function test_slice_without_declared_stamps_has_no_audit_section(): void
    {
        $sliceDir = (new SliceGenerator($this->root, 'App\\Slices'))->generate('PlainNote', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
        ], false, ['table' => 'plain_notes']);

        $form = (string) file_get_contents($sliceDir.'/Resources/views/form.blade.php');
        $this->assertStringNotContainsString('data-ls-audit-stamps', $form);
        $this->assertStringNotContainsString('data-ls-audit-stamp=', $form);
    }
}
