<?php

namespace LaraSlice\Tests\Unit\Blueprint;

use LaraSlice\Blueprint\BlueprintValidationException;
use LaraSlice\Blueprint\BlueprintValidator;
use LaraSlice\Support\DataTableRows;
use LaraSlice\Tests\TestCase;

/**
 * The formatter vocabulary has ONE source: the runtime
 * renderer (DataTableRows::FORMATTERS). Before the
 * registry, the validator kept its own list and falsely
 * rejected the plain 'date' formatter that working
 * slices declare — Design called the port's own Invoices
 * blueprint "invalid" while the app rendered it fine.
 */
class FormatterVocabularyTest extends TestCase
{
    public function test_validator_reads_the_runtime_registry(): void
    {
        $this->assertSame(DataTableRows::FORMATTERS, BlueprintValidator::ALLOWED_FORMATTERS);
        $this->assertContains('date', DataTableRows::FORMATTERS);
        $this->assertSame(
            array_values(array_unique(DataTableRows::FORMATTERS)),
            DataTableRows::FORMATTERS,
            'the vocabulary must not contain duplicates'
        );
    }

    public function test_date_formatter_validates(): void
    {
        $validated = (new BlueprintValidator)->validate($this->blueprintWithFormatter('date'));
        $this->assertSame('date', $validated['ui']['directory']['columns'][0]['formatter']);
    }

    public function test_unknown_formatter_is_rejected(): void
    {
        try {
            (new BlueprintValidator)->validate($this->blueprintWithFormatter('bogus_fmt'));
            $this->fail('An unknown formatter must not validate.');
        } catch (BlueprintValidationException $e) {
            $this->assertStringContainsString("formatter 'bogus_fmt' is invalid", $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function blueprintWithFormatter(string $formatter): array
    {
        return [
            'schema_version' => 1,
            'name' => 'Invoices',
            'handle' => 'invoices',
            'domain' => 'Finance',
            'models' => [[
                'name' => 'Invoice',
                'handle' => 'invoice',
                'table' => 'invoices',
                'root' => true,
                'fields' => [
                    ['handle' => 'invoice_date', 'label' => 'Invoice Date', 'type' => 'date'],
                ],
            ]],
            'ui' => ['directory' => ['columns' => [
                ['handle' => 'invoice_date', 'label' => 'Invoice Date', 'formatter' => $formatter],
            ]]],
        ];
    }
}
