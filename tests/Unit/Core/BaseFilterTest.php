<?php

namespace LaraSlice\Tests\Unit\Core;

use LaraSlice\Core\Base\BaseFilter;
use PHPUnit\Framework\TestCase;

class BaseFilterTest extends TestCase
{
    public function test_it_bounds_pagination_and_rejects_unknown_sort_columns(): void
    {
        $filter = new BaseFilter([
            'page' => 0,
            'limit' => 5000,
            'sortBy' => 'created_at desc; drop table users',
            'search' => str_repeat('x', 300),
        ]);

        $this->assertSame(1, $filter->getPage());
        $this->assertSame(100, $filter->getLimit());
        $this->assertSame('created_at', $filter->getSortBy());
        $this->assertSame(200, mb_strlen($filter->getSearch()));
    }

    public function test_it_uses_valid_custom_sort_columns_from_a_generated_filter(): void
    {
        $filter = new class(['sortBy' => 'unit_price']) extends BaseFilter
        {
            protected function sortableColumns(): array
            {
                return ['id', 'unit_price', 'created_at'];
            }
        };

        $this->assertSame('unit_price', $filter->getSortBy());
    }

    public function test_malformed_query_parameter_shapes_fall_back_to_safe_defaults(): void
    {
        $filter = new BaseFilter([
            'page' => ['1'],
            'limit' => ['20'],
            'search' => ['unexpected'],
            'sortBy' => ['email'],
        ]);

        $this->assertSame(1, $filter->getPage());
        $this->assertSame(20, $filter->getLimit());
        $this->assertNull($filter->getSearch());
        $this->assertSame('created_at', $filter->getSortBy());
    }
}
