<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\RecordWorkplace;
use PHPUnit\Framework\TestCase;

/**
 * Seat 2: the workplace's inbound-link scan finds both
 * link shapes (declared relations and dynamic
 * slice_records links) and ignores everything else.
 */
class RecordWorkplaceTest extends TestCase
{
    public function test_inbound_fields_finds_relations_and_dynamic_links(): void
    {
        $manifests = [
            'Leads' => ['fields' => ['title' => ['name' => 'title']]],
            'Customers' => ['fields' => [
                'lead_name' => ['name' => 'lead_name', 'relation' => 'lead'],
                'customer_name' => ['name' => 'customer_name'],
            ]],
            'Opportunities' => ['fields' => [
                'opportunity_from' => ['name' => 'opportunity_from', 'options_from' => 'slices'],
                'party_name' => ['name' => 'party_name', 'options_from' => 'slice_records:opportunity_from'],
                'company' => ['name' => 'company', 'relation' => 'company'],
            ]],
            'Contacts' => ['fields' => [
                'lead' => ['name' => 'lead', 'relation' => 'lead'],
            ]],
        ];

        $inbound = RecordWorkplace::inboundFields($manifests, 'Leads');

        $this->assertCount(3, $inbound);
        $bySlice = [];
        foreach ($inbound as $link) {
            $bySlice[$link['slice']] = $link;
        }
        $this->assertSame('lead_name', $bySlice['Customers']['field']);
        $this->assertNull($bySlice['Customers']['via']);
        $this->assertSame('party_name', $bySlice['Opportunities']['field']);
        $this->assertSame('opportunity_from', $bySlice['Opportunities']['via']);
        $this->assertSame('lead', $bySlice['Contacts']['field']);
    }

    public function test_inbound_fields_skips_self_and_unrelated(): void
    {
        $manifests = [
            'Leads' => ['fields' => ['amended_from' => ['name' => 'amended_from', 'relation' => 'lead']]],
            'Territories' => ['fields' => ['name' => ['name' => 'name']]],
        ];

        $this->assertSame([], RecordWorkplace::inboundFields($manifests, 'Leads'));
    }

    public function test_assignment_field_resolution(): void
    {
        // Owner-shaped field is the default seat.
        $this->assertSame('lead_owner', RecordWorkplace::assignmentField([
            'fields' => ['lead_owner' => ['name' => 'lead_owner'], 'title' => ['name' => 'title']],
        ]));
        // A declared workplace seat wins over the owner shape
        // (Customer's account_manager is not owner-shaped).
        $this->assertSame('account_manager', RecordWorkplace::assignmentField([
            'fields' => ['account_manager' => ['name' => 'account_manager'], 'owner' => ['name' => 'owner']],
            'ui' => ['workplace' => ['assignment_field' => 'account_manager']],
        ]));
        // A declared seat naming a missing field falls back
        // to the owner shape rather than seating nothing.
        $this->assertSame('owner', RecordWorkplace::assignmentField([
            'fields' => ['owner' => ['name' => 'owner']],
            'ui' => ['workplace' => ['assignment_field' => 'ghost']],
        ]));
        // No seat at all.
        $this->assertNull(RecordWorkplace::assignmentField([
            'fields' => ['title' => ['name' => 'title']],
        ]));
    }
}
