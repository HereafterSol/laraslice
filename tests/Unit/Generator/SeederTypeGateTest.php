<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\SliceSeederService;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * F4 (Stage A, Stage C-6 fix): the seeder's name-based
 * semantics invented strings for any column whose NAME
 * matched (industry, status, title...) regardless of the
 * column's type. An integer `industry` was seeded with
 * "Technology"; SQLite stored the text and the strictly
 * typed listing BO TypeError'd on read — the seeded
 * directory 500'd. The type gate now answers non-text
 * columns from their type (money names keep realistic
 * values, cast for integer columns).
 */
class SeederTypeGateTest extends TestCase
{
    private function value(string $column, string $type, int $index = 0): mixed
    {
        $service = app(SliceSeederService::class);
        $method = new ReflectionMethod($service, 'generateFieldValue');
        $method->setAccessible(true);

        return $method->invoke($service, $column, $type, 'leads', $index);
    }

    public function test_integer_industry_gets_a_number_not_a_category_name(): void
    {
        $this->assertIsInt($this->value('industry', 'integer'));
    }

    public function test_string_industry_keeps_the_semantic_name(): void
    {
        $this->assertSame('Technology', $this->value('industry', 'string'));
    }

    public function test_boolean_status_gets_a_flag_not_a_word(): void
    {
        $this->assertSame(1, $this->value('status', 'boolean'));
    }

    public function test_money_names_stay_realistic_and_type_true(): void
    {
        $this->assertIsFloat($this->value('amount', 'decimal'));
        $this->assertIsInt($this->value('amount', 'integer'));
        $this->assertIsInt($this->value('annual_revenue', 'integer'));
        $this->assertIsFloat($this->value('annual_revenue', 'decimal'));
    }

    public function test_integer_title_and_email_columns_get_numbers(): void
    {
        $this->assertIsInt($this->value('title', 'integer'));
        $this->assertIsInt($this->value('email', 'integer'));
    }
}
