<?php

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\SlicePortService;
use PHPUnit\Framework\TestCase;

final class PorterDeclarationTest extends TestCase
{
    public function test_document_declaration_for_money_shaped_children(): void
    {
        $doc = SlicePortService::documentDeclarationFor(
            ['item', 'quantity', 'price', 'tax', 'total'],
            ['amount', 'status'],
        );
        $this->assertSame('quantity', $doc['quantity']);
        $this->assertSame('price', $doc['unit_price']);
        $this->assertSame('total', $doc['amount']);
        $this->assertSame('tax', $doc['tax_amount']);
        $this->assertSame('amount', $doc['total_field']);
        $this->assertArrayNotHasKey('type_field', $doc);

        $typed = SlicePortService::documentDeclarationFor(['quantity', 'unit_price', 'amount', 'type'], []);
        $this->assertSame('type', $typed['type_field']);
        $this->assertArrayNotHasKey('total_field', $typed);
    }

    public function test_document_declaration_rejects_non_money_children(): void
    {
        $this->assertNull(SlicePortService::documentDeclarationFor(['name', 'email'], []));
        $this->assertNull(SlicePortService::documentDeclarationFor(['quantity', 'price'], []));
    }

    public function test_settings_section_for_single_row_tables(): void
    {
        $section = SlicePortService::settingsSectionFor('smtp_settings', ['id', 'company_id', 'mail_host', 'mail_port', 'mail_password', 'created_at']);
        $this->assertSame('smtp', $section['handle']);
        $this->assertSame(['table' => 'smtp_settings'], $section['bind']);
        $handles = array_column($section['fields'], 'handle');
        $this->assertSame(['company_id', 'mail_host', 'mail_port', 'mail_password'], $handles);
        $types = array_column($section['fields'], 'type', 'handle');
        $this->assertSame('number', $types['mail_port']);
        $this->assertSame('secret', $types['mail_password']);
        $this->assertSame('text', $types['mail_host']);

        $plain = SlicePortService::settingsSectionFor('global_settings', ['id', 'name', 'currency']);
        $this->assertSame('global', $plain['handle']);
    }

    public function test_settings_section_rejects_directory_shaped_tables(): void
    {
        $this->assertNull(SlicePortService::settingsSectionFor('team_settings', ['id', 'team_id', 'flag']));
        $this->assertNull(SlicePortService::settingsSectionFor('empty_settings', ['id', 'created_at']));
    }

    public function test_port_names_free_and_report_names_are_kept(): void
    {
        $existing = [
            ['name' => 'Invoices', 'primary_table' => 'invoices', 'app' => 'ERP', 'path' => '/slices/ERP/Invoices'],
        ];

        $free = SlicePortService::resolvePortSliceNames(
            ['Leads' => ['primary_table' => 'leads']],
            $existing,
            'ERP'
        );
        $this->assertSame(['Leads'], array_keys($free['mapping']));
        $this->assertSame([], $free['renames']);

        // A re-port of the same slice (same name, table, app)
        // updates in place — never renamed.
        $report = SlicePortService::resolvePortSliceNames(
            ['Invoices' => ['primary_table' => 'invoices']],
            $existing,
            'ERP'
        );
        $this->assertSame(['Invoices'], array_keys($report['mapping']));
        $this->assertSame([], $report['renames']);
    }

    public function test_port_names_twin_is_app_qualified(): void
    {
        $existing = [
            ['name' => 'Invoices', 'primary_table' => 'invoices', 'app' => 'ERP', 'path' => '/slices/ERP/Invoices'],
        ];

        $out = SlicePortService::resolvePortSliceNames(
            ['Invoices' => ['primary_table' => 'acct_invoices']],
            $existing,
            'Accounting'
        );
        $this->assertSame(['AccountingInvoices'], array_keys($out['mapping']));
        $this->assertSame('AccountingInvoices', $out['renames']['Invoices']['to']);
        $this->assertSame('/slices/ERP/Invoices', $out['renames']['Invoices']['incumbent']);
    }

    public function test_port_names_normalized_twin_and_suffix_chain(): void
    {
        $existing = [
            ['name' => 'Item Taxes', 'primary_table' => 'item_taxes', 'app' => 'ERP', 'path' => '/a'],
            ['name' => 'AccountingItemTaxes', 'primary_table' => 'other_taxes', 'app' => 'ERP', 'path' => '/b'],
        ];

        // 'ItemTaxes' collides with 'Item Taxes' once
        // normalized; the app-qualified candidate is also
        // claimed, so the numeric suffix follows.
        $out = SlicePortService::resolvePortSliceNames(
            ['ItemTaxes' => ['primary_table' => 'acct_item_taxes']],
            $existing,
            'Accounting'
        );
        $this->assertSame(['AccountingItemTaxes2'], array_keys($out['mapping']));
    }
}
