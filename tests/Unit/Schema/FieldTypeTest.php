<?php

namespace LaraSlice\Tests\Unit\Schema;

use LaraSlice\Schema\FieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FieldTypeTest extends TestCase
{
    public function test_types_and_aliases_resolve_case_insensitively(): void
    {
        $this->assertSame('dateTime', FieldType::columnMethod('DateTime'));
        $this->assertSame('integer', FieldType::columnMethod('int'));
        $this->assertSame('string', FieldType::columnMethod('email'));
        $this->assertSame('unsignedBigInteger', FieldType::columnMethod('foreign_id'));
        $this->assertNull(FieldType::columnMethod('geometry'));
    }

    /** @return array<string, array{string, mixed, bool}> */
    public static function defaults(): array
    {
        return [
            'string accepts text' => ['string', 'draft', true],
            'string rejects int' => ['string', 5, false],
            'integer accepts int' => ['bigInteger', 5, true],
            'integer rejects numeric string' => ['integer', '5', false],
            'decimal accepts numeric string' => ['decimal', '9.99', true],
            'decimal rejects text' => ['decimal', 'cheap', false],
            'boolean accepts bool' => ['bool', false, true],
            'boolean rejects int' => ['boolean', 1, false],
            'null is always fine' => ['date', null, true],
            'unknown type rejects values' => ['geometry', 'x', false],
        ];
    }

    #[DataProvider('defaults')]
    public function test_default_matches_type(string $type, mixed $default, bool $expected): void
    {
        $this->assertSame($expected, FieldType::defaultMatches($type, $default));
    }

    public function test_only_text_like_types_are_encryptable(): void
    {
        $this->assertTrue(FieldType::isEncryptable('json'));
        $this->assertFalse(FieldType::isEncryptable('integer'));
    }

    public function test_form_string_defaults_are_coerced_only_when_unambiguous(): void
    {
        $this->assertSame(0, FieldType::coerceDefault('integer', '0'));
        $this->assertSame(-5, FieldType::coerceDefault('bigInteger', ' -5 '));
        $this->assertFalse(FieldType::coerceDefault('boolean', '0'));
        $this->assertTrue(FieldType::coerceDefault('bool', 'true'));
        $this->assertNull(FieldType::coerceDefault('string', ''));
        $this->assertSame('9.99', FieldType::coerceDefault('decimal', '9.99'));
        $this->assertSame('maybe', FieldType::coerceDefault('boolean', 'maybe'));
        $this->assertSame('1.5', FieldType::coerceDefault('integer', '1.5'));
        $this->assertSame(3, FieldType::coerceDefault('integer', 3));
    }
}
