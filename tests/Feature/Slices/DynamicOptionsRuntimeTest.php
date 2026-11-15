<?php

namespace LaraSlice\Tests\Feature\Slices;

use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Support\SliceRecordOptions;
use LaraSlice\Tests\TestCase;

/**
 * Stage C-5 runtime side: the slice-registry options and
 * the records endpoint that dynamic link fields refill
 * from. Slices here are the package's own (Users), which
 * the test app discovers like any installed app would.
 */
class DynamicOptionsRuntimeTest extends TestCase
{
    public function test_registry_options_list_active_slices_by_name(): void
    {
        app(SliceManager::class)->discover(true);

        $options = SliceRecordOptions::registryOptions();

        $this->assertArrayHasKey('Users', $options);
        $this->assertNotEmpty($options['Users']);
    }

    public function test_slice_records_resolve_id_label_pairs(): void
    {
        app(SliceManager::class)->discover(true);
        $user = $this->makeUser(['name' => 'Zara Dynamic']);

        $options = SliceRecordOptions::forSlice('Users');

        $this->assertSame('Zara Dynamic', $options[$user->id] ?? null);
        $this->assertSame([], SliceRecordOptions::forSlice('NoSuchSlice'));
    }

    public function test_slice_records_for_unknown_slice_is_an_empty_list(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->getJson('/laraslice/options/slice-records?slice=NoSuchSlice')
            ->assertOk()
            ->assertExactJson(['options' => []]);
    }
}
