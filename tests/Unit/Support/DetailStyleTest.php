<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Replica P2 pins (1 Nov 2026): the declared detail-card
 * presentation — ui.show.detail_style ('list' = the source
 * hubs' definition list, 'grid' = the house default) — and
 * declared value maps on ui.show.detail_fields entries
 * (stored code → presented label: male → Male, en → English).
 */
class DetailStyleTest extends TestCase
{
    protected function setUp(): void
    {
        $manifest = [
            'ui' => ['show' => [
                'detail_style' => 'list',
                'detail_title' => 'Profile Info',
                'detail_fields' => [
                    ['label' => 'Employee ID', 'from' => 'employee_id'],
                    ['label' => 'Gender', 'from' => 'user.gender', 'map' => ['male' => 'Male', 'female' => 'Female']],
                    ['label' => 'Language', 'from' => 'user.locale', 'map' => ['en' => 'English']],
                    ['label' => 'Missing', 'from' => 'user.not_a_field'],
                    ['label' => 'Hourly Rate', 'from' => 'hourly_rate', 'type' => 'money'],
                    ['label' => 'Work Anniversary', 'from' => 'joining_date', 'type' => 'anniversary'],
                ],
            ]],
        ];
        $prop = new ReflectionProperty(DirectoryUi::class, 'manifestDataBySlice');
        $prop->setAccessible(true);
        $data = $prop->getValue();
        $data['ProfilePin'] = $manifest;
        $data['PlainPin'] = ['ui' => ['show' => []]];
        $prop->setValue(null, $data);
    }

    public function test_detail_style_reads_the_declaration(): void
    {
        $this->assertSame('list', DirectoryUi::detailStyle('ProfilePin'));
        $this->assertSame('grid', DirectoryUi::detailStyle('PlainPin'), 'undeclared style falls back to the house grid');
    }

    public function test_detail_rows_apply_declared_maps_and_dash_unresolvable(): void
    {
        $record = (object) [
            'employee_id' => 'EMP-12',
            'hourly_rate' => '97.00',
            'joining_date' => '2023-05-13',
            'user' => (object) ['gender' => 'male', 'locale' => 'en'],
        ];

        $rows = DirectoryUi::detailRows('ProfilePin', $record);

        $this->assertSame(['Employee ID', 'Gender', 'Language', 'Missing', 'Hourly Rate', 'Work Anniversary'], array_column($rows, 'label'));
        $this->assertSame('EMP-12', $rows[0]['value']);
        $this->assertSame('Male', $rows[1]['value'], 'map translates the stored code');
        $this->assertSame('English', $rows[2]['value']);
        $this->assertSame('—', $rows[3]['value'], 'an unresolvable path renders a dash, never an error');
        $this->assertSame('PKR97', $rows[4]['value'], 'money type: Mind currency code + trimmed decimals (source format)');
        $this->assertStringContainsString('from now', $rows[5]['value'], 'anniversary type: next occurrence, humanized');
    }
}
