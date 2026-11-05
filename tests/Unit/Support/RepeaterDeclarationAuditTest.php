<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\RepeaterDeclarationAudit;
use PHPUnit\Framework\TestCase;

class RepeaterDeclarationAuditTest extends TestCase
{
    public function test_passes_a_repeater_whose_table_carries_the_declared_foreign_key(): void
    {
        $findings = RepeaterDeclarationAudit::audit(
            [['slice' => 'Orders', 'repeaters' => [
                ['handle' => 'items', 'table' => 'order_items', 'foreign_key' => 'order_id'],
            ]]],
            fn (string $t, string $c): string => 'ok',
        );

        $this->assertSame([], $findings);
    }

    public function test_errors_on_a_belongs_to_lookup_modelled_as_a_child_repeater(): void
    {
        $findings = RepeaterDeclarationAudit::audit(
            [['slice' => 'Tickets', 'repeaters' => [
                ['handle' => 'ticket_channels', 'table' => 'ticket_channels', 'foreign_key' => 'ticket_id'],
            ]]],
            fn (string $t, string $c): string => 'missing_column',
        );

        $this->assertCount(1, $findings);
        $this->assertSame('error', $findings[0]['severity']);
        $this->assertSame('repeater-foreign-key', $findings[0]['kind']);
        $this->assertStringContainsString('ticket_channels', $findings[0]['message']);
        $this->assertStringContainsString('ticket_id', $findings[0]['message']);
    }

    public function test_warns_when_the_repeater_table_does_not_exist(): void
    {
        $findings = RepeaterDeclarationAudit::audit(
            [['slice' => 'Ghost', 'repeaters' => [
                ['handle' => 'ghosts', 'table' => 'ghost_rows', 'foreign_key' => 'ghost_id'],
            ]]],
            fn (string $t, string $c): string => 'missing_table',
        );

        $this->assertCount(1, $findings);
        $this->assertSame('warning', $findings[0]['severity']);
    }

    public function test_extracts_repeaters_from_relations_and_form_sections_deduped(): void
    {
        $manifest = [
            'models' => [
                ['name' => 'order', 'relations' => [
                    ['name' => 'items', 'table' => 'order_items', 'foreign_key' => 'order_id'],
                ]],
            ],
            'ui' => ['forms' => ['sections' => [
                ['key' => 'main', 'fields' => [
                    ['handle' => 'title', 'type' => 'string'],
                ]],
                ['key' => 'items_items', 'fields' => [
                    ['handle' => 'items', 'type' => 'repeater', 'table' => 'order_items', 'foreign_key' => 'order_id'],
                ]],
            ]]],
        ];

        $repeaters = RepeaterDeclarationAudit::repeatersFromManifest($manifest);
        $this->assertCount(2, $repeaters);

        $findings = RepeaterDeclarationAudit::audit(
            [['slice' => 'Orders', 'repeaters' => $repeaters]],
            fn (string $t, string $c): string => 'missing_column',
        );

        $this->assertCount(1, $findings);
    }
}
