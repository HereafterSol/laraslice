<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * G2: ui.show.document resolves a record into a printable document
 * structure — header fields, line items from the declared repeater,
 * and a totals block with money formatting.
 */
class DocumentCompositionTest extends TestCase
{
    private function manifest(): array
    {
        return [
            'name' => 'Invoice',
            'models' => [['fields' => [
                ['handle' => 'invoice_number', 'type' => 'string', 'label' => 'Invoice Number'],
                ['handle' => 'amount', 'type' => 'decimal', 'label' => 'Amount'],
                ['handle' => 'invoiced_at', 'type' => 'date', 'label' => 'Invoiced At'],
                ['handle' => 'items', 'type' => 'repeater', 'label' => 'Items', 'repeater_fields' => [
                    ['handle' => 'name', 'label' => 'Name'],
                    ['handle' => 'quantity', 'label' => 'Qty'],
                    ['handle' => 'amount', 'label' => 'Amount'],
                ]],
            ]]],
            'ui' => ['show' => ['document' => ['title' => 'Tax Invoice', 'totals' => ['amount']]]],
        ];
    }

    public function test_document_resolves_header_items_and_totals(): void
    {
        $item1 = new FakeDocumentRecord(['name' => 'Hosting', 'quantity' => 2, 'amount' => 500]);
        $item2 = new FakeDocumentRecord(['name' => 'Support', 'quantity' => 1, 'amount' => 500]);
        $invoice = new FakeDocumentRecord(
            ['invoice_number' => 'INV-001', 'amount' => 1000, 'invoiced_at' => '2026-10-01 00:00:00'],
            ['items' => [$item1, $item2]]
        );

        $doc = DirectoryUi::documentFromManifest($this->manifest(), $invoice);

        $this->assertNotNull($doc);
        $this->assertSame('Tax Invoice', $doc['title']);
        $labels = array_column($doc['header'], 'label');
        $this->assertContains('Invoice Number', $labels);
        $this->assertSame([['label' => 'Amount', 'value' => '1,000.00']], $doc['totals']);
        $this->assertSame(['Name', 'Qty', 'Amount'], array_column($doc['items']['columns'], 'label'));
        $this->assertCount(2, $doc['items']['rows']);
        $this->assertSame('Hosting', $doc['items']['rows'][0][0]);
    }

    public function test_no_declaration_no_document(): void
    {
        $record = new FakeDocumentRecord(['invoice_number' => 'INV-001']);
        $this->assertNull(DirectoryUi::documentFromManifest(['name' => 'Invoice', 'models' => []], $record));
    }
}

class FakeDocumentRecord
{
    public function __construct(private array $attrs, private array $relations = []) {}

    public function getAttributes(): array
    {
        return $this->attrs;
    }

    public function items(): array
    {
        return $this->relations['items'] ?? [];
    }

    public function __get(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }
}
