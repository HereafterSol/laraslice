<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Tests\TestCase;

/**
 * Slice Studio posts form values as strings; the generator must accept what the wizard sends.
 */
class WizardGenerateTest extends TestCase
{
    protected function tearDown(): void
    {
        app('files')->deleteDirectory(config('laraslice.slices_path'));
        parent::tearDown();
    }

    public function test_the_helpdesk_preset_generates_as_the_browser_sends_it(): void
    {
        // Captured from the "Helpdesk Ticket" preset in Chrome (trimmed)
        $payload = [
            'projectName' => 'Ticket', 'namespace' => 'App\Slices', 'domain' => 'Helpdesk',
            'description' => 'Customer support ticketing', 'author' => '', 'uiFramework' => 'blatui',
            'includeAuth' => true, 'includeDatabase' => true, 'includeApi' => true, 'includeWorkflow' => true,
            'fields' => [
                ['name' => 'ticket_number', 'label' => 'Ticket #', 'type' => 'string', 'required' => true, 'nullable' => false, 'default' => null, 'length' => '50'],
                ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'nullable' => false, 'default' => 'medium', 'options' => ['low' => 'low', 'medium' => 'medium', 'high' => 'high']],
                ['name' => 'category', 'label' => 'Category', 'type' => 'string', 'required' => false, 'nullable' => true, 'default' => 'General', 'length' => ''],
                ['name' => 'due_date', 'label' => 'SLA Due Date', 'type' => 'datetime', 'required' => false, 'nullable' => true, 'default' => null],
                ['name' => 'reopen_count', 'label' => 'Reopened', 'type' => 'integer', 'required' => true, 'nullable' => false, 'default' => '0'],
            ],
            'childTables' => [
                ['name' => 'ticket_replies', 'label' => 'Ticket Responses', 'relation' => 'hasMany', 'fields' => [
                    ['name' => 'reply_body', 'label' => 'Reply Message', 'type' => 'text', 'required' => true, 'nullable' => false, 'default' => null],
                    ['name' => 'is_internal_note', 'label' => 'Internal Staff Note', 'type' => 'boolean', 'required' => false, 'nullable' => true, 'default' => '0'],
                ]],
            ],
        ];

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/laraslice/wizard/generate', $payload);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonMissingPath('childErrors.ticket_replies');
        $migration = glob(config('laraslice.slices_path').'/**/Ticket/Migrations/*_create_tickets_table.php')
            ?: glob(config('laraslice.slices_path').'/*/*/Migrations/*_create_tickets_table.php')
            ?: glob(config('laraslice.slices_path').'/*/Migrations/*_create_tickets_table.php');
        $this->assertNotEmpty($migration);
        $this->assertStringContainsString("\$table->string('ticket_number', 50)", file_get_contents($migration[0]));
    }
}
