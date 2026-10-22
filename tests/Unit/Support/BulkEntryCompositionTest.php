<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * G8: ui.directory.bulk_entry resolves into a normalized
 * declaration (row key, date field, typed fields with labels)
 * — the standard pattern behind Mark Attendance-style screens.
 */
class BulkEntryCompositionTest extends TestCase
{
    private function manifest(array $bulk): array
    {
        return [
            'name' => 'Attendances',
            'ui' => ['directory' => [
                'matrix' => ['row_key' => 'user_id', 'date_field' => 'clock_in_time'],
                'columns' => [
                    ['key' => 'clock_in_time', 'label' => 'Clock In Time'],
                    ['key' => 'working_from', 'label' => 'Working From'],
                ],
                'bulk_entry' => $bulk,
            ]],
        ];
    }

    public function test_bulk_entry_inherits_row_key_and_date_field_from_matrix(): void
    {
        $decl = DirectoryUi::bulkEntryFromManifest($this->manifest([
            'title' => 'Mark Attendance',
            'fields' => [
                ['handle' => 'clock_in_time', 'input' => 'time'],
                ['handle' => 'late', 'input' => 'select', 'options' => ['yes', 'no']],
            ],
        ]));
        $this->assertNotNull($decl);
        $this->assertSame('Mark Attendance', $decl['title']);
        $this->assertSame('user_id', $decl['row_key']);
        $this->assertSame('clock_in_time', $decl['date_field']);
        // Label falls back to the directory column label.
        $this->assertSame('Clock In Time', $decl['fields'][0]['label']);
        $this->assertSame(['yes', 'no'], $decl['fields'][1]['options']);
    }

    public function test_bulk_entry_accepts_string_fields_and_headline_labels(): void
    {
        $decl = DirectoryUi::bulkEntryFromManifest($this->manifest([
            'fields' => ['working_from'],
        ]));
        $this->assertNotNull($decl);
        $this->assertSame('Working From', $decl['fields'][0]['label']);
        $this->assertSame('text', $decl['fields'][0]['input']);
        $this->assertSame('Bulk Entry', $decl['title']);
    }

    public function test_bulk_entry_requires_fields_and_keys(): void
    {
        $this->assertNull(DirectoryUi::bulkEntryFromManifest($this->manifest(['fields' => []])));
        $this->assertNull(DirectoryUi::bulkEntryFromManifest(['name' => 'X', 'ui' => ['directory' => []]]));
    }

    public function test_bulk_entry_range_and_holidays_parse(): void
    {
        $decl = DirectoryUi::bulkEntryFromManifest([
            'ui' => ['directory' => [
                'bulk_entry' => [
                    'row_key' => 'user_id',
                    'date_field' => 'clock_in_time',
                    'range' => true,
                    'skip_weekends' => true,
                    'holidays' => ['slice' => 'Holiday', 'date_field' => 'date'],
                    'fields' => [['handle' => 'clock_in_time', 'input' => 'time']],
                ],
            ]],
        ]);
        $this->assertNotNull($decl);
        $this->assertTrue($decl['range']);
        $this->assertTrue($decl['skip_weekends']);
        $this->assertSame(['slice' => 'Holiday', 'date_field' => 'date'], $decl['holidays']);
    }

    public function test_bulk_entry_defaults_are_single_date_without_holidays(): void
    {
        $decl = DirectoryUi::bulkEntryFromManifest([
            'ui' => ['directory' => [
                'bulk_entry' => [
                    'row_key' => 'user_id',
                    'date_field' => 'clock_in_time',
                    'fields' => [['handle' => 'clock_in_time', 'input' => 'time']],
                ],
            ]],
        ]);
        $this->assertNotNull($decl);
        $this->assertFalse($decl['range']);
        $this->assertFalse($decl['skip_weekends']);
        $this->assertNull($decl['holidays']);
    }
}
