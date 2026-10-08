<?php

namespace LaraSlice\Tests\Feature\Security;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\SliceSeederService;
use LaraSlice\Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class DestructiveOperationsTest extends TestCase
{
    public function test_core_slices_cannot_be_destroyed_or_wiped(): void
    {
        $manager = app(SliceManager::class);
        $user = $this->makeUser();

        foreach (['Users', 'Roles', 'Settings', 'Auth'] as $slice) {
            try {
                $manager->destroySlice($slice);
                $this->fail("Destroying the core {$slice} slice must be refused.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('core', $e->getMessage());
            }
        }

        $this->expectException(\RuntimeException::class);
        try {
            (new SliceSeederService($manager))->wipeSlice('Users');
        } finally {
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertNotNull($user->fresh());
            $this->assertDirectoryExists(dirname(__DIR__, 3).'/src/Slices/Users');
        }
    }

    public function test_destroy_command_refuses_core_slices(): void
    {
        $this->artisan('slice:destroy', ['slice' => 'Users', '--force' => true])
            ->expectsOutputToContain('cannot be destroyed')
            ->assertFailed();

        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_studio_writes_are_refused_in_production(): void
    {
        try {
            $this->app['env'] = 'production';
            // Laravel only skips CSRF checks in the "testing" environment
            $this->withoutMiddleware([
                PreventRequestForgery::class,
                ValidateCsrfToken::class,
                VerifyCsrfToken::class,
            ]);
            $admin = $this->makeSuperAdmin();

            $this->actingAs($admin)->postJson('/laraslice/wizard/generate', ['projectName' => 'Tickets'])->assertForbidden();
            $this->actingAs($admin)->postJson('/laraslice/wizard/migrate')->assertForbidden();
            $this->actingAs($admin)->postJson('/laraslice/wizard/destroy-slice', ['slice' => 'Tickets'])->assertForbidden();

            config(['laraslice.wizard.allow_in_production' => true]);
            $this->actingAs($admin)->postJson('/laraslice/wizard/destroy-slice', ['slice' => 'Users'])
                ->assertJson(['success' => false]);
            $this->assertTrue(Schema::hasTable('users'));
        } finally {
            // Teardown rolls back migrations, which prompts for confirmation in production
            $this->app['env'] = 'testing';
        }
    }

    public function test_studio_access_does_not_unlock_code_generation_or_migrations(): void
    {
        $viewer = $this->makeUserWithPermissions(['studio.access']);

        $this->actingAs($viewer)->postJson('/laraslice/wizard/generate', ['projectName' => 'Tickets'])->assertForbidden();
        $this->actingAs($viewer)->postJson('/laraslice/wizard/migrate')->assertForbidden();
        $this->actingAs($viewer)->postJson('/laraslice/wizard/blueprint/apply', [])->assertForbidden();
    }

    public function test_wizard_and_mcp_endpoint_are_disabled_by_default(): void
    {
        $config = require dirname(__DIR__, 3).'/config/laraslice.php';

        $this->assertFalse($config['wizard']['enabled']);
        $this->assertFalse($config['wizard']['allow_in_production']);
        $this->assertFalse($config['ai']['mcp_server']['enabled']);
        $this->assertFalse($config['auth']['api_registration']);
    }

    public function test_mcp_requires_json_rpc_and_confirmation_for_destructive_tools(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->post('/.well-known/mcp', ['method' => 'tools/list'])->assertStatus(400);

        $response = $this->postJson('/.well-known/mcp', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'destroy_slice', 'arguments' => ['slice' => 'Tickets']],
        ])->assertOk();

        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('confirm', $response->json('result.content.0.text'));
    }

    public function test_mcp_write_tools_check_permissions_over_http(): void
    {
        Sanctum::actingAs($this->makeUserWithPermissions(['studio.access']));

        $response = $this->postJson('/.well-known/mcp', [
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => ['name' => 'wipe_slice_data', 'arguments' => ['slice' => 'Users', 'confirm' => 'Users']],
        ])->assertOk();

        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('permission', $response->json('result.content.0.text'));
    }
}
