<?php

namespace LaraSlice\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * Studio Polish (DEV_STUDIO_STANDARD follow-up program): the
 * four panel blades keep the structural contracts the polish
 * pass established — balanced Alpine roots (a stray close once
 * pushed the whole Port body outside its component scope and a
 * duplicated wrapper once nested the Create forms column inside
 * its own sidebar), the YAML validation chip in Design, the
 * grouped Danger menu in Manage, and collapsible sections.
 */
class StudioPolishTest extends TestCase
{
    private function blade(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/partials/'.$name);
    }

    private function divDepth(string $html): int
    {
        return substr_count($html, '<div') - substr_count($html, '</div>');
    }

    public function test_panel_blades_have_balanced_divs(): void
    {
        foreach (['wizard-panel.blade.php', 'design-panel.blade.php', 'port-panel.blade.php'] as $panel) {
            $this->assertSame(0, $this->divDepth($this->blade($panel)), $panel.' has unbalanced <div> tags');
        }
    }

    public function test_create_wizard_has_no_duplicated_sidebar_wrapper(): void
    {
        $wizard = $this->blade('wizard-panel.blade.php');
        $this->assertSame(1, substr_count($wizard, 'hidden md:block col-span-12 md:col-span-5 lg:col-span-4 space-y-6'));
        $this->assertStringNotContainsString(
            "hidden md:block col-span-12 md:col-span-5 lg:col-span-4 space-y-6\">\n            <div class=\"col-span-12 md:col-span-5 lg:col-span-4 space-y-6\">",
            $wizard
        );
    }

    public function test_design_panel_has_yaml_validation_chip(): void
    {
        $design = $this->blade('design-panel.blade.php');
        $this->assertStringContainsString('get yamlStatus()', $design);
        $this->assertStringContainsString('YAML valid', $design);
        $this->assertStringContainsString('sticky top-0', $design);
    }

    public function test_manage_header_groups_destructive_actions_behind_danger_menu(): void
    {
        $wizard = $this->blade('wizard-panel.blade.php');
        $this->assertStringContainsString('dangerOpen', $wizard);
        $this->assertStringContainsString('Wipe data…', $wizard);
        $this->assertStringContainsString('Remove slice…', $wizard);
    }

    public function test_port_panel_header_zone_is_sticky(): void
    {
        $port = $this->blade('port-panel.blade.php');
        $this->assertStringContainsString('sticky top-0 z-30 bg-background/95 backdrop-blur', $port);
    }
}
