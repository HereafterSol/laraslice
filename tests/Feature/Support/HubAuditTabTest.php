<?php

namespace LaraSlice\Tests\Feature\Support;

use Illuminate\Database\Eloquent\Model;
use LaraSlice\Core\Audit\AuditLogger;
use LaraSlice\Support\DirectoryUi;
use LaraSlice\Tests\TestCase;

class HubAuditTabRecord extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

/**
 * Hub tab curation (27 Oct 2026): a declared `type: audit` tab
 * renders the record's audit trail — entries sourced from the
 * framework's audit infrastructure (AuditLogger, entity_type =
 * model class, entity_id = record key), never a silently
 * missing tab.
 */
class HubAuditTabTest extends TestCase
{
    public function test_audit_tab_carries_the_records_audit_entries(): void
    {
        AuditLogger::record([
            'slice' => 'Projects',
            'action' => 'created',
            'actor_email' => 'pm@example.com',
            'entity_type' => HubAuditTabRecord::class,
            'entity_id' => 5,
            'new_values' => ['name' => 'Apollo'],
        ]);
        AuditLogger::record([
            'slice' => 'Projects',
            'action' => 'updated',
            'actor_email' => 'pm@example.com',
            'entity_type' => HubAuditTabRecord::class,
            'entity_id' => 5,
            'old_values' => ['status' => 'open'],
            'new_values' => ['status' => 'in_progress'],
        ]);
        AuditLogger::record([
            'slice' => 'Projects',
            'action' => 'updated',
            'actor_email' => 'other@example.com',
            'entity_type' => HubAuditTabRecord::class,
            'entity_id' => 9,
        ]);

        $record = new HubAuditTabRecord;
        $record->forceFill(['id' => 5]);

        $manifest = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'activity', 'label' => 'Activity', 'type' => 'audit'],
        ]]]];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);

        $this->assertCount(1, $tabs);
        $this->assertSame('audit', $tabs[0]['type']);
        $this->assertSame(2, $tabs[0]['count']);
        $this->assertCount(2, $tabs[0]['entries']);
        // Newest first (AuditLogger orders by id desc).
        $this->assertSame('updated', $tabs[0]['entries'][0]['action']);
        $this->assertSame('pm@example.com', $tabs[0]['entries'][0]['actor']);
        $this->assertSame(['status' => 'in_progress'], $tabs[0]['entries'][0]['new_values']);
        $this->assertSame('created', $tabs[0]['entries'][1]['action']);
    }

    public function test_audit_tab_for_an_unrecorded_record_is_an_empty_trail_not_a_missing_tab(): void
    {
        $record = new HubAuditTabRecord;
        $record->forceFill(['id' => 42]);

        $manifest = ['ui' => ['show' => ['tabs' => [
            ['handle' => 'activity', 'label' => 'Activity', 'type' => 'audit'],
        ]]]];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);

        $this->assertCount(1, $tabs);
        $this->assertSame([], $tabs[0]['entries']);
        $this->assertSame(0, $tabs[0]['count']);
    }
}
