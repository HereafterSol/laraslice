<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Tests\TestCase;

/**
 * Regression pin (2 Nov 2026): removing the wizard's
 * auto-applied demo preset closed init() early and
 * orphaned the rest of its body outside the method —
 * a JavaScript syntax error that silently killed the
 * whole Studio wizard script (the HTML still rendered;
 * only a browser behaviour check caught it). The init()
 * body must stay intact: urlParams handling and the
 * loadSlices() call live INSIDE the method.
 */
class WizardPanelScriptTest extends TestCase
{
    public function test_wizard_init_body_is_not_orphaned(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/partials/wizard-panel.blade.php');

        $initPos = strpos($blade, 'init() {');
        $this->assertNotFalse($initPos, 'wizard init() not found');
        $segment = substr($blade, (int) $initPos, 800);

        $this->assertStringContainsString('const urlParams', $segment);
        $this->assertStringContainsString('this.loadSlices()', substr($blade, (int) $initPos, 1200));
        // The broken shape: a method-level closing brace on
        // its own line, a blank line, then the orphaned body.
        $this->assertDoesNotMatchRegularExpression('/\n        \}\n\n            const urlParams/', $segment);
    }
}
