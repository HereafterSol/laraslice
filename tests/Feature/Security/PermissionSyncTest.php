<?php

namespace LaraSlice\Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Tests\TestCase;

class PermissionSyncTest extends TestCase
{
    public function test_application_permissions_are_never_pruned(): void
    {
        DB::table('permissions')->insert(['name' => 'Export reports', 'slug' => 'reports.export', 'group' => 'App', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['name' => 'Old', 'slug' => 'removed_slice.view', 'group' => 'Old', 'source' => 'laraslice', 'created_at' => now(), 'updated_at' => now()]);

        app(SliceManager::class)->syncPermissions(prune: true);

        $this->assertDatabaseHas('permissions', ['slug' => 'reports.export']);
        $this->assertDatabaseMissing('permissions', ['slug' => 'removed_slice.view']);
        $this->assertDatabaseHas('permissions', ['slug' => 'users.view', 'source' => 'laraslice']);
    }

    public function test_sync_without_prune_keeps_everything(): void
    {
        DB::table('permissions')->insert(['name' => 'Old', 'slug' => 'removed_slice.view', 'group' => 'Old', 'source' => 'laraslice', 'created_at' => now(), 'updated_at' => now()]);

        app(SliceManager::class)->syncPermissions();

        $this->assertDatabaseHas('permissions', ['slug' => 'removed_slice.view']);
    }

    public function test_opening_the_role_pages_does_not_resync_every_time(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->get('/admin/roles/create')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(0, app(SliceManager::class)->syncPermissionsIfChanged(), 'nothing changed since the last sync');
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_a_slice_destroyed_and_generated_again_gets_its_permissions_back(): void
    {
        $manager = app(SliceManager::class);
        $manager->syncPermissionsIfChanged();
        $this->artisan('slice:make', ['name' => ['Ticket']])->assertSuccessful();
        $manager->discover();
        $manager->syncPermissionsIfChanged();
        $this->assertDatabaseHas('permissions', ['slug' => 'ticket.view']);

        $this->artisan('slice:destroy', ['slice' => 'Tickets', '--mode' => 'complete', '--force' => true])->assertSuccessful();
        $this->assertDatabaseMissing('permissions', ['slug' => 'ticket.view']);

        $this->artisan('slice:make', ['name' => ['Ticket']])->assertSuccessful();
        $manager->discover();
        $manager->syncPermissionsIfChanged();
        $this->assertDatabaseHas('permissions', ['slug' => 'ticket.view']);

        app('files')->deleteDirectory(config('laraslice.slices_path'));
    }
}
