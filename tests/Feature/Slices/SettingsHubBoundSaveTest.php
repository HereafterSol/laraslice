<?php

namespace LaraSlice\Tests\Feature\Slices;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Tests\TestCase;

/**
 * Bound settings saves (S-UI 5): a blank field must never
 * write null into a NOT NULL column — under a strict SQL
 * mode that 500s the whole save (the ported
 * payroll_settings.tds_salary shape: NOT NULL varchar
 * holding ''). And a declared time field must round-trip
 * the stored HH:MM:SS instead of wiping it.
 */
class SettingsHubBoundSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widget_settings', function (Blueprint $table) {
            $table->id();
            $table->string('threshold'); // NOT NULL, no default
            $table->string('label')->nullable();
            $table->string('hour_of_day')->nullable();
            $table->timestamps();
        });
        DB::table('widget_settings')->insert([
            'threshold' => '',
            'label' => 'before',
            'hour_of_day' => '00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sliceDir = config('laraslice.slices_path').'/WidgetSettings';
        if (! is_dir($sliceDir)) {
            mkdir($sliceDir, 0777, true);
        }
        file_put_contents($sliceDir.'/slice.yaml', <<<'YAML'
name: WidgetSettings
version: 1.0.0
active: true
settings:
- handle: widget
  title: Widget Settings
  bind:
    table: widget_settings
  fields:
  - handle: threshold
    label: Threshold
    type: number
  - handle: label
    label: Label
    type: text
  - handle: hour_of_day
    label: Hour Of Day
    type: time
YAML);
        app(SliceManager::class)->discover(true);
    }

    public function test_blank_field_never_nulls_a_not_null_column(): void
    {
        $this->actingAs($this->makeSuperAdmin())
            ->post('/admin/settings/WidgetSettings:widget', [
                'threshold' => '', // blank, as the form submits it
                'label' => 'after',
                'hour_of_day' => '00:00',
            ])
            ->assertRedirect();

        $row = DB::table('widget_settings')->first();
        $this->assertSame('', $row->threshold, 'NOT NULL column keeps its stored value instead of 500ing the save');
        $this->assertSame('after', $row->label);
        $this->assertSame('00:00:00', $row->hour_of_day, 'the submitted HH:MM is padded back to the stored HH:MM:SS');
    }

    public function test_time_field_pads_seconds_on_write(): void
    {
        $this->actingAs($this->makeSuperAdmin())
            ->post('/admin/settings/WidgetSettings:widget', [
                'threshold' => '500',
                'label' => 'before',
                'hour_of_day' => '05:30',
            ])
            ->assertRedirect();

        $row = DB::table('widget_settings')->first();
        $this->assertSame('500', (string) $row->threshold);
        $this->assertSame('05:30:00', $row->hour_of_day);
    }

    public function test_insert_into_an_empty_table_uses_the_zero_value(): void
    {
        DB::table('widget_settings')->delete();

        $this->actingAs($this->makeSuperAdmin())
            ->post('/admin/settings/WidgetSettings:widget', [
                'threshold' => '',
                'label' => 'fresh',
                'hour_of_day' => '01:15',
            ])
            ->assertRedirect();

        $row = DB::table('widget_settings')->first();
        $this->assertNotNull($row);
        $this->assertSame('', $row->threshold, 'insert falls back to the column zero value, not null');
        $this->assertSame('01:15:00', $row->hour_of_day);
    }
}
