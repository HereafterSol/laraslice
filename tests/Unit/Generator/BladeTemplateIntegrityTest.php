<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;

/**
 * Template integrity for the generator's heredoc blade
 * emissions (foundation backlog A1, 24 Oct 2026): an
 * attribute value opened as `attr="{{ ...` MUST close with
 * `}}"` on the same emitted line. The dropdown/hybrid
 * row-actions emissions shipped `edit-overlay="{{ ... }}`
 * with no closing quote; the browser's error recovery then
 * swallowed the rest of the component tag, adopted the
 * data-table's empty-state cell into every row, and left
 * the affected slices (any with a presentation map — in
 * the demo: Leaves, Holidays) with no row menu at all.
 * This scans the generator source for the defect CLASS,
 * not just the two repaired lines.
 */
class BladeTemplateIntegrityTest extends TestCase
{
    public function test_generator_attribute_emissions_close_their_quotes(): void
    {
        $file = dirname(__DIR__, 3).'/src/Generator/SliceGenerator.php';
        $lines = file($file);
        $this->assertNotFalse($lines, 'SliceGenerator.php must be readable');

        $offenders = [];
        foreach ($lines as $i => $line) {
            // An emitted attribute whose value opens with ="
            // and a blade echo {{ must terminate with }}" on
            // the same source line.
            if (preg_match('/[a-z-]+="\{\{/', $line) && ! str_contains($line, '}}"')) {
                $offenders[] = ($i + 1).': '.trim($line);
            }
            if (preg_match('/[a-z-]+="\{\{/', $line) && str_contains($line, '{{') && ! str_contains($line, '}}')) {
                $offenders[] = ($i + 1).' (unclosed echo): '.trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Unterminated attribute emissions in SliceGenerator (each swallows following markup in the browser):'
        );
    }

    public function test_row_actions_overlay_attributes_are_terminated(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Generator/SliceGenerator.php');

        foreach (['view-overlay', 'edit-overlay'] as $attr) {
            $count = preg_match_all('/'.preg_quote($attr, '/').'="\{\{/', $source, $_);
            $closed = preg_match_all('/'.preg_quote($attr, '/').'="\{\{.*\}\}"/', $source, $_);
            $this->assertSame($count, $closed, $attr.' must always be emitted with a closing quote');
            $this->assertGreaterThan(0, $count, $attr.' emissions must exist (row-actions slot)');
        }
    }
}
