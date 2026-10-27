<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

class PageStructureContractTest extends TestCase
{
    public function test_undeclared_placement_falls_back_to_the_actions_band(): void
    {
        $this->assertSame('below-facets-left', DirectoryUi::listPlacement([]));
        $this->assertSame('below-facets-left', DirectoryUi::listPlacement(['directory' => []]));
    }

    public function test_pages_list_placement_wins_over_legacy_directory_key(): void
    {
        $ui = [
            'pages' => ['list' => ['placement' => 'header-right']],
            'directory' => ['placement' => 'toolbar-left'],
        ];
        $this->assertSame('header-right', DirectoryUi::listPlacement($ui));
    }

    public function test_legacy_directory_placement_is_honoured(): void
    {
        $this->assertSame('command-bar', DirectoryUi::listPlacement(['directory' => ['placement' => 'command-bar']]));
    }

    public function test_values_outside_the_vocabulary_fall_back(): void
    {
        $this->assertSame('below-facets-left', DirectoryUi::listPlacement(['pages' => ['list' => ['placement' => 'floating-action-button']]]));
        $this->assertSame('below-facets-left', DirectoryUi::listPlacement(['pages' => ['list' => ['placement' => 42]]]));
    }

    public function test_generator_emits_the_placement_contract(): void
    {
        $generator = file_get_contents(__DIR__.'/../../../src/Generator/SliceGenerator.php');
        // In the generator's PHP source the blade variable is escaped (\$placement).
        $this->assertStringContainsString('(\\$placement ?? \'below-facets-left\') === \'header-right\'', $generator);
        $this->assertStringContainsString('(\\$placement ?? \'below-facets-left\') === \'below-facets-left\'', $generator);
        $this->assertStringContainsString('(\\$placement ?? \'below-facets-left\') === \'command-bar\'', $generator);
        $this->assertStringContainsString('(\\$placement ?? \'below-facets-left\') === \'toolbar-left\'', $generator);
        $this->assertStringContainsString('<x-slot:toolbar-lead>', $generator);
        // The controller supplies the resolved placement to every index view.
        $controller = file_get_contents(__DIR__.'/../../../src/Core/Base/BaseSliceWebController.php');
        $this->assertStringContainsString('placement\' => \LaraSlice\Support\DirectoryUi::listPlacement($uiConfig)', $controller);
        // The data-table renders no empty actions band.
        $stub = file_get_contents(__DIR__.'/../../../resources/stubs/starter/views/components/ui/data-table.blade.php');
        $this->assertStringContainsString('$belowToolbar->isNotEmpty()', $stub);
    }
}
