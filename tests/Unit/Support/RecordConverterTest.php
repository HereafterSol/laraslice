<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\RecordConverter;
use PHPUnit\Framework\TestCase;

/**
 * Record conversions: same-name copy, field_map
 * overrides, literal + conditional set rules — the
 * mapped-doc semantics of the source controllers,
 * declared as data.
 */
class RecordConverterTest extends TestCase
{
    private function conversion(): array
    {
        return [
            'target' => 'Opportunities',
            'label' => 'Opportunity',
            'field_map' => ['name' => 'party_name', 'email_id' => 'contact_email'],
            'field_no_map' => ['disabled'],
            'set' => [
                'opportunity_from' => 'Leads',
                'customer_type' => ['when' => ['field' => 'company_name', 'not_empty' => true], 'then' => 'Company', 'else' => 'Individual'],
            ],
        ];
    }

    private function targetFields(): array
    {
        return [
            'party_name' => [], 'contact_email' => [], 'opportunity_from' => [],
            'customer_type' => [], 'status' => [], 'disabled' => [], 'id' => [],
            'company_name' => [],
        ];
    }

    public function test_same_name_copy_skips_system_and_no_map(): void
    {
        $payload = RecordConverter::payload($this->conversion(), [
            'id' => 42, 'status' => 'open', 'disabled' => 1, 'company_name' => 'Acme',
        ], $this->targetFields());

        $this->assertSame('open', $payload['status']);
        $this->assertArrayNotHasKey('id', $payload);
        $this->assertArrayNotHasKey('disabled', $payload);
    }

    public function test_field_map_and_literal_set(): void
    {
        $payload = RecordConverter::payload($this->conversion(), [
            'name' => 'CRM-LEAD-2026-00001', 'email_id' => 'a@b.c', 'company_name' => 'Acme',
        ], $this->targetFields());

        $this->assertSame('CRM-LEAD-2026-00001', $payload['party_name']);
        $this->assertSame('a@b.c', $payload['contact_email']);
        $this->assertSame('Leads', $payload['opportunity_from']);
    }

    public function test_conditional_set_uses_source_values(): void
    {
        $withCompany = RecordConverter::payload($this->conversion(), ['company_name' => 'Acme'], $this->targetFields());
        $without = RecordConverter::payload($this->conversion(), ['company_name' => null], $this->targetFields());

        $this->assertSame('Company', $withCompany['customer_type']);
        $this->assertSame('Individual', $without['customer_type']);
    }

    public function test_empty_source_values_never_copy(): void
    {
        $payload = RecordConverter::payload($this->conversion(), [
            'name' => '', 'email_id' => null, 'status' => '',
        ], $this->targetFields());

        $this->assertArrayNotHasKey('party_name', $payload);
        $this->assertArrayNotHasKey('contact_email', $payload);
        $this->assertArrayNotHasKey('status', $payload);
    }

    public function test_unrepresentable_option_values_never_copy(): void
    {
        $fields = $this->targetFields();
        $fields['status'] = ['options' => ['open' => 'Open', 'lost' => 'Lost']];

        $foreign = RecordConverter::payload($this->conversion(), ['status' => 'lead'], $fields);
        $native = RecordConverter::payload($this->conversion(), ['status' => 'open'], $fields);

        $this->assertArrayNotHasKey('status', $foreign);
        $this->assertSame('open', $native['status']);
    }

    public function test_today_token_stamps_the_conversion_date(): void
    {
        $conversion = $this->conversion();
        $conversion['set'] = ['transaction_date' => ['today' => true]];
        $fields = $this->targetFields() + ['transaction_date' => []];

        $payload = RecordConverter::payload($conversion, [], $fields);

        $this->assertSame(date('Y-m-d'), $payload['transaction_date']);
    }

    public function test_conversions_of_normalizes_manifest_entries(): void
    {
        $list = RecordConverter::conversionsOf(['conversions' => [
            ['target' => 'Customers'],
            ['label' => 'No target'],
            'garbage',
        ]]);

        $this->assertCount(1, $list);
        $this->assertSame('Customer', $list[0]['label']);
    }

    public function test_children_rows_are_built_from_source_values_and_empty_rows_dropped(): void
    {
        // D-073: a conversion's declared child rows are built
        // from the source record's flat values with the same
        // scalar machinery; a row with no source-derived value
        // is dropped even when its set map would fill flags,
        // and an integer id landing in a string child field is
        // stringified (link names are strings).
        $conversion = $this->conversion();
        $conversion['children'] = [
            'links' => ['rows' => [
                ['field_map' => ['id' => 'link_name'], 'set' => ['link_doctype' => 'Leads']],
            ]],
            'phone_nos' => ['rows' => [
                ['field_map' => ['phone' => 'phone'], 'set' => ['is_primary_phone' => 1]],
            ]],
        ];
        $fields = $this->targetFields();
        $fields['links'] = ['type' => 'repeater', 'repeater_fields' => [
            ['handle' => 'link_name', 'type' => 'string'],
            ['handle' => 'link_doctype', 'type' => 'string'],
        ]];
        $fields['phone_nos'] = ['type' => 'repeater', 'repeater_fields' => [
            ['handle' => 'phone', 'type' => 'string'],
            ['handle' => 'is_primary_phone', 'type' => 'integer'],
        ]];

        $payload = RecordConverter::payload($conversion, [
            'id' => 26, 'status' => 'open', 'company_name' => 'Acme',
        ], $fields);

        $this->assertSame([['link_name' => '26', 'link_doctype' => 'Leads']], $payload['links']);
        $this->assertArrayNotHasKey('phone_nos', $payload);
    }

    public function test_conversions_of_carries_children_steps_and_source_status(): void
    {
        $list = RecordConverter::conversionsOf(['conversions' => [[
            'target' => 'Opportunities',
            'source_status' => 'opportunity',
            'pre_steps' => [[
                'name' => 'create_prospect', 'slice' => 'Prospects', 'default' => true,
                'field_map' => ['lead_owner' => 'prospect_owner'],
            ]],
            'children' => ['links' => ['field_map' => ['id' => 'link_name']]],
        ]]]);

        $this->assertSame('opportunity', $list[0]['source_status']);
        $this->assertSame('create_prospect', $list[0]['pre_steps'][0]['name']);
        $this->assertTrue($list[0]['pre_steps'][0]['default']);
        $this->assertSame(['id' => 'link_name'], $list[0]['children']['links']['rows'][0]['field_map']);
    }
}
