<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Core\Base\BaseSliceWebController;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 (D-070): the declared-abilities guard. A Heart may
 * narrow the verbs a slice offers (ui.abilities {create:
 * false, delete: false} — system-seeded slices like
 * Languages); declaredAbilityDenied() is the route-level
 * twin of the button-hiding narrowing, and it must deny ONLY
 * an explicit false — an absent or true declaration leaves
 * the verb available.
 */
class Phase6AbilitiesGuardTest extends TestCase
{
    private function controller(array $ui): BaseSliceWebController
    {
        return new class($ui) extends BaseSliceWebController
        {
            public function __construct(private array $uiConfig) {}

            protected function getService(): IFormDataService&IListingDataService
            {
                throw new \LogicException('not used by the abilities guard');
            }

            protected function getFormClass(): string
            {
                return '';
            }

            protected function getFilterClass(): string
            {
                return '';
            }

            protected function getViewPrefix(): string
            {
                return '';
            }

            protected function getRoutePrefix(): string
            {
                return '';
            }

            protected function getSliceUiConfig(): array
            {
                return $this->uiConfig;
            }

            public function denied(string $verb): bool
            {
                return $this->declaredAbilityDenied($verb);
            }
        };
    }

    public function test_explicit_false_denies_the_verb(): void
    {
        $c = $this->controller(['abilities' => ['create' => false, 'delete' => false]]);
        $this->assertTrue($c->denied('create'));
        $this->assertTrue($c->denied('delete'));
        $this->assertFalse($c->denied('update'));
    }

    public function test_absent_declaration_denies_nothing(): void
    {
        $c = $this->controller([]);
        $this->assertFalse($c->denied('create'));
        $this->assertFalse($c->denied('delete'));
    }

    public function test_true_declaration_denies_nothing(): void
    {
        $c = $this->controller(['abilities' => ['create' => true]]);
        $this->assertFalse($c->denied('create'));
    }
}
