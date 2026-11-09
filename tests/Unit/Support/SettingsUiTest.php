<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\SettingsUi;
use PHPUnit\Framework\TestCase;

/**
 * S-UI 5: declared settings sections aggregate across slices
 * into one hub — ids are slice-qualified, storage keys default
 * to {section}.{field} with declared overrides, values resolve
 * through the store with manifest defaults as fallback.
 */
class SettingsUiTest extends TestCase
{
    public function test_sections_aggregate_with_keys_and_values(): void
    {
        $stored = ['leaves.max_days_per_year' => '25'];
        $resolver = fn (string $key, mixed $default) => $stored[$key] ?? $default;

        $sections = SettingsUi::collectSections([
            ['slice' => 'Leaves', 'manifest' => ['settings' => [[
                'handle' => 'leaves', 'title' => 'Leaves',
                'fields' => [
                    ['handle' => 'approval_required', 'label' => 'Approval Required', 'type' => 'toggle', 'default' => '1'],
                    ['handle' => 'max_days_per_year', 'label' => 'Max Days', 'type' => 'number', 'default' => '20'],
                    ['handle' => 'api_token', 'label' => 'API Token', 'type' => 'secret', 'key' => 'integrations.leave_api_token'],
                ],
            ]]]],
            ['slice' => 'Tasks', 'manifest' => ['settings' => [[
                'handle' => 'tasks', 'title' => 'Tasks',
                'fields' => [
                    ['handle' => 'default_priority', 'label' => 'Default Priority', 'type' => 'select', 'default' => 'medium',
                        'options' => ['low' => 'Low', 'medium' => 'Medium']],
                ],
            ]]]],
            ['slice' => 'Empty', 'manifest' => []],
        ], $resolver);

        $this->assertSame(['Leaves:leaves', 'Tasks:tasks'], array_column($sections, 'id'));
        $fields = $sections[0]['fields'];
        $this->assertSame('leaves.approval_required', $fields[0]['key']);
        $this->assertSame('1', $fields[0]['value']);
        $this->assertSame('25', $fields[1]['value'], 'stored value wins over the default');
        $this->assertSame('integrations.leave_api_token', $fields[2]['key']);
        $this->assertTrue($fields[2]['secret']);
        $this->assertSame(['low' => 'Low', 'medium' => 'Medium'], $sections[1]['fields'][0]['options']);
    }

    public function test_keyed_rows_bind_carries_through(): void
    {
        $entries = [[
            'slice' => 'Settings',
            'manifest' => ['settings' => [[
                'handle' => 'notifications',
                'title' => 'Notifications',
                'bind' => ['table' => 'email_notification_settings', 'rows_key' => 'slug', 'rows_label' => 'setting_name', 'toggles' => ['send_email', 'send_slack']],
                'fields' => [],
            ]]],
        ]];
        $sections = SettingsUi::collectSections($entries, fn ($k, $d) => $d);
        $this->assertSame('email_notification_settings', $sections[0]['bind']['table']);
        $this->assertSame('slug', $sections[0]['bind']['rows_key']);
        $this->assertSame(['send_email', 'send_slack'], $sections[0]['bind']['toggles']);
    }

    public function test_bound_section_carries_its_table(): void
    {
        $resolver = fn (string $key, mixed $default) => $default;

        $sections = SettingsUi::collectSections([
            ['slice' => 'Settings', 'manifest' => ['settings' => [
                [
                    'handle' => 'smtp', 'title' => 'Email (SMTP)',
                    'bind' => ['table' => 'smtp_settings'],
                    'fields' => [
                        ['handle' => 'mail_host', 'label' => 'Mail Host', 'type' => 'text'],
                    ],
                ],
                [
                    'handle' => 'plain', 'title' => 'Plain',
                    'fields' => [
                        ['handle' => 'note', 'label' => 'Note', 'type' => 'text'],
                    ],
                ],
            ]]],
        ], $resolver);

        $this->assertSame(['table' => 'smtp_settings'], $sections[0]['bind']);
        $this->assertNull($sections[1]['bind'], 'unbound sections stay on the key-value store');
    }

    public function test_time_fields_survive_collection_and_normalize_on_write(): void
    {
        $sections = SettingsUi::collectSections([
            ['slice' => 'Backup', 'manifest' => ['settings' => [[
                'handle' => 'backup', 'title' => 'Backup',
                'fields' => [
                    ['handle' => 'hour_of_day', 'label' => 'Hour Of Day', 'type' => 'time'],
                ],
            ]]]],
        ], fn ($k, $d) => $d);

        $this->assertSame('time', $sections[0]['fields'][0]['type'], 'time is a first-class settings field type, not a text fallback');

        // A time input submits HH:MM; stored times are HH:MM:SS.
        $this->assertSame('00:00:00', SettingsUi::normalizeTimeValue('00:00'));
        $this->assertSame('05:30:00', SettingsUi::normalizeTimeValue('05:30'));
        $this->assertSame('23:59:58', SettingsUi::normalizeTimeValue('23:59:58'), 'already-complete times pass through');
        $this->assertSame('', SettingsUi::normalizeTimeValue(''));
        $this->assertNull(SettingsUi::normalizeTimeValue(null));
    }

    public function test_enum_toggle_pairs_come_from_the_column_not_the_current_value(): void
    {
        $this->assertSame(['active', 'inactive'], SettingsUi::enumValues("enum('active','inactive')"));
        $this->assertSame(['enable', 'disable'], SettingsUi::enumValues("enum('enable','disable')"));
        $this->assertSame([], SettingsUi::enumValues('varchar(255)'));

        // 'active' pairs with 'inactive' here, 'deactive' there —
        // only the enum itself can say which.
        $this->assertSame(['active', 'inactive'], SettingsUi::togglePairFor(['active', 'inactive']));
        $this->assertSame(['active', 'deactive'], SettingsUi::togglePairFor(['active', 'deactive']));
        $this->assertSame(['yes', 'no'], SettingsUi::togglePairFor(['yes', 'no']));
        $this->assertSame(['enable', 'disable'], SettingsUi::togglePairFor(['enable', 'disable']));
        $this->assertNull(SettingsUi::togglePairFor(['draft', 'sent']), 'no known toggle vocabulary — caller falls back');
        $this->assertNull(SettingsUi::togglePairFor([]));
    }

    public function test_zero_values_match_column_families(): void
    {
        $this->assertSame('', SettingsUi::zeroValueFor(['type_name' => 'varchar']));
        $this->assertSame('', SettingsUi::zeroValueFor(['type_name' => 'enum']));
        $this->assertSame('', SettingsUi::zeroValueFor(['type_name' => 'text']));
        $this->assertSame(0, SettingsUi::zeroValueFor(['type_name' => 'int']));
        $this->assertSame(0, SettingsUi::zeroValueFor(['type_name' => 'decimal']));
        $this->assertSame('00:00:00', SettingsUi::zeroValueFor(['type_name' => 'time']));
        $this->assertNull(SettingsUi::zeroValueFor(['type_name' => 'date']), 'no invented dates');
        $this->assertNull(SettingsUi::zeroValueFor(['type_name' => 'datetime']));
    }

    public function test_toggle_conventions_cover_the_domains_settings_tables_use(): void
    {
        // Read side: every stored "on" spelling counts.
        foreach ([1, '1', 'yes', 'true', true, 'active', 'enabled'] as $on) {
            $this->assertTrue(SettingsUi::toggleIsOn($on), 'on: '.var_export($on, true));
        }
        foreach ([0, '0', 'no', 'false', false, 'deactive', 'disabled', null] as $off) {
            $this->assertFalse(SettingsUi::toggleIsOn($off), 'off: '.var_export($off, true));
        }

        // Write side: the record's own convention is kept —
        // a module_settings row must stay active/deactive,
        // never collapse into 1/0.
        $this->assertSame('yes', SettingsUi::toggleValueFor('no', true));
        $this->assertSame('no', SettingsUi::toggleValueFor('yes', false));
        $this->assertSame('active', SettingsUi::toggleValueFor('deactive', true));
        $this->assertSame('deactive', SettingsUi::toggleValueFor('active', false));
        $this->assertSame('enabled', SettingsUi::toggleValueFor('disabled', true));
        $this->assertSame('disabled', SettingsUi::toggleValueFor('enabled', false));
        $this->assertSame(1, SettingsUi::toggleValueFor(0, true));
        $this->assertSame(0, SettingsUi::toggleValueFor(1, false));
        $this->assertSame('1', SettingsUi::toggleValueFor('0', true));
    }
}
