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

    /** Row payload of the rendered directory page (rows ship as JSON for the client-side table). */
    private function directoryRows(string $html): array
    {
        $this->assertMatchesRegularExpression("/rows: JSON\\.parse\\('(.*?)'\\)/s", $html);
        preg_match("/rows: JSON\\.parse\\('(.*?)'\\)/s", $html, $m);

        // The payload is a JS single-quoted string holding JSON whose
        // quotes are \\u0022 escapes: unescape for JS first, then decode.
        $raw = str_replace('\\\\', "\x00", $m[1]);
        $raw = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn ($mm) => mb_chr(hexdec($mm[1])), $raw);
        $raw = str_replace("\\'", "'", $raw);
        $raw = str_replace("\x00", '\\\\', $raw);

        return json_decode($raw, true) ?: [];
    }

    public function test_a_view_and_create_user_sees_no_edit_or_delete(): void
    {
        $this->makeTicketSlice();
        $agent = $this->makeUserWithPermissions(['ticket.view', 'ticket.create']);

        $response = $this->actingAs($agent)->get('/tickets')->assertOk()
            ->assertSee('Printer offline')
            ->assertSee('Add Ticket')
            ->assertDontSee('Seed Demo');

        // The controls are client-rendered from the row payload: a user
        // without edit/delete permission must receive no edit/delete URL.
        $rows = $this->directoryRows($response->getContent());
        $this->assertNotEmpty($rows);
        $this->assertNotNull($rows[0]['show_url']);
        $this->assertNull($rows[0]['edit_url']);
        $this->assertNull($rows[0]['delete_url']);
    }

    public function test_a_super_admin_sees_every_control(): void
    {
        $this->makeTicketSlice();

        $response = $this->actingAs($this->makeSuperAdmin())->get('/tickets')->assertOk()
            ->assertSee('Add Ticket')
            ->assertSee('Are you sure you want to delete this Ticket?');

        $rows = $this->directoryRows($response->getContent());
        $this->assertNotEmpty($rows);
        $this->assertNotNull($rows[0]['edit_url']);
        $this->assertNotNull($rows[0]['delete_url']);
    }
}
