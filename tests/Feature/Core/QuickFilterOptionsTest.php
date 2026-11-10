<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use LaraSlice\Tests\TestCase;

/**
 * Quick-filter field-vocabulary fallback (10 Nov 2026):
 * a select filter that declares neither options nor an
 * options_from source resolves its options from the
 * FIELD's declared vocabulary in the Heart — the shape
 * ported slices actually declare (handle + display only).
 * Before this, the flagship's Projects status filter
 * rendered only "All" while its Heart declared all five
 * statuses on the field.
 */
final class QuickFilterOptionsTest extends TestCase
{
    public function test_field_declared_options_resolve_in_value_label_shape(): void
    {
        $controller = new class extends BaseSliceWebController
        {
            protected function getService(): IFormDataService&IListingDataService
            {
                throw new \LogicException('not used');
            }

            protected function getFormClass(): string
            {
                return 'stdClass';
            }

            protected function getFilterClass(): string
            {
                return 'stdClass';
            }

            protected function getViewPrefix(): string
            {
                return 'test::';
            }

            protected function getRoutePrefix(): string
            {
                return 'test.';
            }

            protected function ownManifest(): array
            {
                return ['fields' => [
                    'status' => ['options' => ['in progress' => 'In Progress', 'finished' => 'Finished']],
                    'title' => ['options' => []],
                ]];
            }

            /** @return array<int, array{value: mixed, label: string}> */
            public function exposedFieldOptions(string $handle): array
            {
                return $this->fieldDeclaredOptions($handle);
            }
        };

        $this->assertSame([
            ['value' => 'in progress', 'label' => 'In Progress'],
            ['value' => 'finished', 'label' => 'Finished'],
        ], $controller->exposedFieldOptions('status'));
        $this->assertSame([], $controller->exposedFieldOptions('title'));
        $this->assertSame([], $controller->exposedFieldOptions('missing'));
    }
}
