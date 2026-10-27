<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * Option-level filter criteria (fidelity vocabulary): a chosen
 * option's declared `criteria` replace the plain handle
 * equality — computed facets like the source's Overdue status
 * and its progress buckets.
 */
final class FilterOptionCriteriaTest extends TestCase
{
    private function statusFilter(): array
    {
        return [
            'handle' => 'status',
            'options' => [
                ['value' => 'overdue', 'label' => 'Overdue', 'criteria' => [
                    'deadline' => ['op' => 'lt', 'value' => '$today'],
                    'status' => ['op' => 'not_in', 'value' => ['finished', 'canceled']],
                ]],
                ['value' => 'in progress', 'label' => 'In Progress'],
            ],
        ];
    }

    public function testOptionWithCriteriaTranslatesWithTokensResolved(): void
    {
        $conditions = DirectoryUi::optionConditions($this->statusFilter(), 'overdue', 7);

        self::assertSame([
            ['field' => 'deadline', 'op' => 'lt', 'value' => date('Y-m-d')],
            ['field' => 'status', 'op' => 'not_in', 'value' => ['finished', 'canceled']],
        ], $conditions);
    }

    public function testOptionWithoutCriteriaFallsBackToPlainEquality(): void
    {
        self::assertNull(DirectoryUi::optionConditions($this->statusFilter(), 'in progress', 7));
        self::assertNull(DirectoryUi::optionConditions($this->statusFilter(), 'bogus', 7));
        self::assertNull(DirectoryUi::optionConditions(['handle' => 'x'], 'overdue', 7));
    }

    public function testBucketCriteriaAndUserToken(): void
    {
        $filter = [
            'handle' => 'progress',
            'options' => [
                ['value' => '41-60', 'label' => '41% - 60%', 'criteria' => [
                    'completion_percent' => ['op' => 'between', 'value' => [41, 60]],
                ]],
                ['value' => 'mine', 'label' => 'Mine', 'criteria' => [
                    'added_by' => ['op' => 'eq', 'value' => '$user'],
                ]],
            ],
        ];

        self::assertSame(
            [['field' => 'completion_percent', 'op' => 'between', 'value' => [41, 60]]],
            DirectoryUi::optionConditions($filter, '41-60', 7)
        );
        self::assertSame(
            [['field' => 'added_by', 'op' => 'eq', 'value' => 7]],
            DirectoryUi::optionConditions($filter, 'mine', 7)
        );
    }
}
