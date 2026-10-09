<?php

namespace LaraSlice\Tests\Feature\Generator;

use Illuminate\Support\Facades\DB;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Tests\TestCase;

/**
 * Generated listings show only the controls the signed-in user may use.
 */
class GeneratedViewPermissionsTest extends TestCase
{
    protected function tearDown(): void
    {
        app('files')->deleteDirectory(config('laraslice.slices_path'));
        parent::tearDown();
    }

    private function makeTicketSlice(): void
    {
        $this->artisan('slice:make', ['name' => ['Ticket']])->assertSuccessful();
        // Composer autoloads App\Slices in a real app; map it to the test's slices folder
        $root = config('laraslice.slices_path');
        spl_autoload_register(function (string $class) use ($root) {
            if (str_starts_with($class, 'App\\Slices\\')) {
                $file = $root.'/'.str_replace('\\', '/', substr($class, strlen('App\\Slices\\'))).'.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
        // Register the new slice's routes and views as a fresh request would
        $manager = app(SliceManager::class);
        $manager->discover();
        (new \ReflectionMethod($manager, 'bootSlice'))->invoke($manager, $manager->getSlice('Ticket'));
        app('router')->getRoutes()->refreshNameLookups();
        foreach (glob(config('laraslice.slices_path').'/*/Migrations', GLOB_ONLYDIR) as $dir) {
            $this->artisan('migrate', ['--path' => $dir, '--realpath' => true])->assertSuccessful();
        }
        DB::table('tickets')->insert(['title' => 'Printer offline', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_view_and_create_user_sees_no_edit_or_delete(): void
    {
        $this->makeTicketSlice();
        $agent = $this->makeUserWithPermissions(['ticket.view', 'ticket.create']);

        $this->actingAs($agent)->get('/tickets')->assertOk()
            ->assertSee('Printer offline')
            ->assertSee('Create Ticket')
            ->assertDontSee('Seed Demo Data')
            ->assertDontSee('lucide-pencil', false)
            ->assertDontSee('Delete this record?');
    }

    public function test_a_super_admin_sees_every_control(): void
    {
        $this->makeTicketSlice();

        $this->actingAs($this->makeSuperAdmin())->get('/tickets')->assertOk()
            ->assertSee('Create Ticket')
            ->assertSee('Seed Demo Data')
            ->assertSee('Delete this record?');
    }
}
