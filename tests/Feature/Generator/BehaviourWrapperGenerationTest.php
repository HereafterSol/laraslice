<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Seat 1 wrappers: a declared depends_on must land on the
 * field's wrapper element as data-ls-* attributes for EVERY
 * field shape — including booleans, whose flex-row container
 * is not the space-y-1 stack the scalar inputs use. A
 * boolean's behaviour used to vanish silently (found by the
 * Contacts benchmark gate: pulled_from_google_contacts).
 */
class BehaviourWrapperGenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-bw-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->slicesPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->slicesPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->slicesPath);
        }
        parent::tearDown();
    }

    public function test_boolean_and_scalar_fields_both_carry_the_behaviour_wrapper(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Toggle', [
            ['name' => 'enabled', 'type' => 'boolean', 'label' => 'Enabled'],
            ['name' => 'extra_notes', 'type' => 'string', 'label' => 'Extra Notes', 'depends_on' => ['field' => 'enabled', 'not_empty' => true]],
            ['name' => 'verified', 'type' => 'boolean', 'label' => 'Verified', 'depends_on' => ['field' => 'enabled', 'not_empty' => true]],
        ], false, ['table' => 'toggles']);

        $form = file_get_contents($path.'/Resources/views/form.blade.php');
        $this->assertStringContainsString('data-ls-field="extra_notes"', $form);
        $this->assertStringContainsString('data-ls-field="verified"', $form);
        $this->assertSame(2, substr_count($form, 'data-ls-depends-on='));
    }
}
