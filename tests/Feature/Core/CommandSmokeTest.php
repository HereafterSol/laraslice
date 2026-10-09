<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Foundation\Auth\User as HostUser;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Slices\Users\Services\SecurityPolicyService;
use LaraSlice\Tests\TestCase;

/**
 * Every read-only or reversible artisan command runs end to end without errors.
 */
class CommandSmokeTest extends TestCase
{
    protected function tearDown(): void
    {
        app('files')->deleteDirectory(config('laraslice.slices_path'));
        @unlink(app()->bootstrapPath('cache/laraslice-slices.php'));
        parent::tearDown();
    }

    public function test_slice_list_shows_core_slices_and_filters_by_domain(): void
    {
        $this->artisan('slice:list')->expectsOutputToContain('Users')->assertSuccessful();
        $this->artisan('slice:list', ['--domain' => 'nothing-here'])->assertSuccessful();
    }

    public function test_slice_cache_and_clear(): void
    {
        $this->artisan('slice:cache')->assertSuccessful();
        $this->artisan('slice:clear')->assertSuccessful();
    }

    public function test_mcp_self_test(): void
    {
        $this->artisan('laraslice:mcp', ['--test' => true])->assertSuccessful();
    }

    public function test_blueprint_validate_and_plan_bundled_examples(): void
    {
        foreach (glob(dirname(__DIR__, 3).'/blueprints/examples/*.yaml') as $path) {
            $this->artisan('slice:blueprint:validate', ['path' => $path])->assertSuccessful();
            $this->artisan('slice:blueprint:plan', ['path' => $path, '--format' => 'json'])->assertSuccessful();
        }
    }

    public function test_audit_prune(): void
    {
        $this->artisan('laraslice:audit:prune', ['--days' => 30, '--force' => true])->assertSuccessful();
    }

    public function test_slice_make_then_toggle_a_whole_domain(): void
    {
        $this->artisan('slice:make', ['name' => ['Ticket', 'Queue'], '--domain' => 'Helpdesk'])->assertSuccessful();
        $manager = app(SliceManager::class);
        $manager->discover();
        $this->assertCount(2, $manager->getDomainSlices('helpdesk'));

        $this->artisan('slice:toggle', ['--domain' => 'Helpdesk', '--disable' => true])
            ->expectsOutputToContain('2 slices updated')
            ->assertSuccessful();

        $manager->discover();
        foreach ($manager->getDomainSlices('Helpdesk') as $slice) {
            $this->assertFalse($slice->active);
        }
    }

    public function test_mfa_policy_accepts_host_user_models_without_mfa_methods(): void
    {
        $user = new HostUser;
        $user->id = 999;

        $this->assertFalse(SecurityPolicyService::isPrivilegedUser($user));
        $this->assertSame('totp', SecurityPolicyService::preferredMethodFor($user));
    }

    public function test_destroying_the_last_slice_of_a_domain_removes_the_domain_folder(): void
    {
        $this->artisan('slice:make', ['name' => ['Ticket'], '--domain' => 'Helpdesk'])->assertSuccessful();
        $domainDir = config('laraslice.slices_path').'/Helpdesk';
        $this->assertDirectoryExists($domainDir);

        $this->artisan('slice:destroy', ['slice' => 'Tickets', '--mode' => 'complete', '--force' => true])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($domainDir);
        $this->assertDirectoryExists(config('laraslice.slices_path'));
    }
}
