<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IFormDataService;
use LaraSlice\Core\Contracts\IListingDataService;
use PHPUnit\Framework\TestCase;

/**
 * Board-axis default on create (Phase 4, gap G9): a record
 * created on a slice whose directory declares a board view
 * enters the board at the axis' first value when the request
 * left the axis empty — the source systems file a new task
 * under the first board column, never under no column at all.
 * An explicitly chosen value always wins.
 */
class BoardAxisDefaultTest extends TestCase
{
    public function test_empty_axis_defaults_to_the_first_vocabulary_value(): void
    {
        $form = new BoardAxisProbeForm;
        (new BoardAxisProbeController)->apply($form);

        $this->assertSame(1, $form->board_column_id);
    }

    public function test_an_explicit_axis_value_is_respected(): void
    {
        $form = new BoardAxisProbeForm;
        $form->board_column_id = 3;
        (new BoardAxisProbeController)->apply($form);

        $this->assertSame(3, $form->board_column_id);
    }

    public function test_a_slice_without_a_board_view_is_untouched(): void
    {
        $form = new BoardAxisProbeForm;
        (new BoardAxisProbeController(false))->apply($form);

        $this->assertNull($form->board_column_id);
    }

    public function test_store_action_wires_the_default(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Base/BaseSliceWebController.php');
        $this->assertStringContainsString('applyBoardAxisDefault($form)', $controller);
    }
}

class BoardAxisProbeForm
{
    public ?int $board_column_id = null;
}

class BoardAxisProbeController extends BaseSliceWebController
{
    public function __construct(private bool $withBoard = true)
    {
    }

    public function apply(object $form): void
    {
        $this->applyBoardAxisDefault($form);
    }

    protected function ownManifest(): array
    {
        return [
            'ui' => [
                'directory' => [
                    'views' => $this->withBoard ? ['table', 'board'] : ['table'],
                    'columns' => [
                        ['key' => 'heading', 'label' => 'Heading'],
                        [
                            'key' => 'board_column_id',
                            'label' => 'Status',
                            'options' => [
                                ['value' => 1, 'label' => 'Incomplete'],
                                ['value' => 2, 'label' => 'To Do'],
                                ['value' => 3, 'label' => 'Doing'],
                                ['value' => 4, 'label' => 'Completed'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function getService(): IFormDataService&IListingDataService
    {
        throw new \LogicException('not used');
    }

    protected function getFormClass(): string
    {
        return \stdClass::class;
    }

    protected function getFilterClass(): string
    {
        return \stdClass::class;
    }

    protected function getViewPrefix(): string
    {
        return 'probe::';
    }

    protected function getRoutePrefix(): string
    {
        return 'probe';
    }
}
