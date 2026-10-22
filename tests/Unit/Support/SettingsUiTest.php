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
}
