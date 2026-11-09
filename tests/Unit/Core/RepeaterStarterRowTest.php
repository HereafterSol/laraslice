<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Core;

use Illuminate\Database\Eloquent\Model;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use PHPUnit\Framework\TestCase;

/**
 * Repeater starter-row emptiness (Comparative Fix Programme
 * Phase A, Gate T A4b): the repeater component always submits
 * one blank starter row, and a boolean field's hidden companion
 * submits '0' with it. That companion alone is not content —
 * if the row were kept, every required rule on the repeater
 * (e.g. a NOT NULL issue_date) would block a create that never
 * touched the repeater.
 */
class RepeaterStarterRowTest extends TestCase
{
    public function test_starter_row_with_only_a_boolean_companion_is_dropped(): void
    {
        $form = new RepeaterProbeForm;
        $form->expenses_recurring = [['unlimited_recurring' => '0']];
        (new RepeaterProbeService)->normalize($form);

        $this->assertSame([], $form->expenses_recurring);
    }

    public function test_row_with_real_content_is_kept(): void
    {
        $form = new RepeaterProbeForm;
        $form->expenses_recurring = [['unlimited_recurring' => '0', 'item_name' => 'Hosting']];
        (new RepeaterProbeService)->normalize($form);

        $this->assertCount(1, $form->expenses_recurring);
        $this->assertSame('Hosting', $form->expenses_recurring[0]['item_name']);
    }

    public function test_checked_boolean_alone_is_content(): void
    {
        $form = new RepeaterProbeForm;
        $form->expenses_recurring = [['unlimited_recurring' => '1']];
        (new RepeaterProbeService)->normalize($form);

        $this->assertCount(1, $form->expenses_recurring);
    }
}

class RepeaterProbeForm extends BaseFormBusinessObject
{
    /** @var array<int, array<string, mixed>> */
    public array $expenses_recurring = [];
}

class RepeaterProbeModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

class RepeaterProbeService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return RepeaterProbeModel::class;
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        return new RepeaterProbeForm;
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        return new RepeaterProbeForm;
    }

    public function repeaterDefinitions(): array
    {
        return [
            'expenses_recurring' => [
                'table' => 'expenses_recurring',
                'foreign_key' => 'project_id',
                'model' => 'expenses_recurring',
                'fields' => [
                    'item_name' => ['label' => 'Item Name', 'type' => 'string', 'required' => false, 'options' => []],
                    'unlimited_recurring' => ['label' => 'Unlimited Recurring', 'type' => 'boolean', 'required' => false, 'options' => []],
                    'issue_date' => ['label' => 'Issue Date', 'type' => 'date', 'required' => true, 'options' => []],
                ],
            ],
        ];
    }

    public function normalize(IBusinessObject $form): void
    {
        $this->normalizeRepeaterPayloads($form);
    }
}
