<?php

namespace LaraSlice\Tests\Feature\Slices;

use LaraSlice\Slices\Users\Models\UserDevice;
use LaraSlice\Tests\TestCase;

/**
 * S1 (security pass, 6 Nov 2026): revoking a device from the admin
 * surface must terminate the underlying stored session — deleting the
 * registry row alone left the session fully usable until expiry.
 */
class DeviceSessionRevocationTest extends TestCase
{
    private string $sessionDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionDir = sys_get_temp_dir().'/laraslice-revoke-'.uniqid();
        mkdir($this->sessionDir, 0777, true);
        config(['session.driver' => 'file', 'session.files' => $this->sessionDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sessionDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->sessionDir);

        parent::tearDown();
    }

    private function makeDevice(int $userId, string $sessionId): UserDevice
    {
        return UserDevice::create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'device_name' => 'Firefox on Linux',
            'device_token' => 'tok-'.$sessionId,
            'ip_address' => '127.0.0.1',
            'last_active_at' => now(),
        ]);
    }

    public function test_admin_revoke_device_destroys_the_underlying_session(): void
    {
        $victim = $this->makeUser();
        $victimSession = 'victim'.str_repeat('a', 34);
        $bystanderSession = 'bystander'.str_repeat('b', 31);
        file_put_contents($this->sessionDir.'/'.$victimSession, 'payload');
        file_put_contents($this->sessionDir.'/'.$bystanderSession, 'payload');

        $device = $this->makeDevice($victim->id, $victimSession);

        $this->actingAs($this->makeSuperAdmin())
            ->delete("/admin/users/devices/{$device->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('user_devices', ['id' => $device->id]);
        $this->assertFileDoesNotExist($this->sessionDir.'/'.$victimSession);
        $this->assertFileExists($this->sessionDir.'/'.$bystanderSession);
    }

    public function test_revoke_all_devices_destroys_each_underlying_session(): void
    {
        $victim = $this->makeUser();
        $one = 'one'.str_repeat('c', 37);
        $two = 'two'.str_repeat('d', 37);
        file_put_contents($this->sessionDir.'/'.$one, 'payload');
        file_put_contents($this->sessionDir.'/'.$two, 'payload');
        $this->makeDevice($victim->id, $one);
        $this->makeDevice($victim->id, $two);

        $this->actingAs($this->makeSuperAdmin())
            ->post("/admin/users/mfa/{$victim->id}/revoke-devices")
            ->assertRedirect();

        $this->assertSame(0, UserDevice::where('user_id', $victim->id)->count());
        $this->assertFileDoesNotExist($this->sessionDir.'/'.$one);
        $this->assertFileDoesNotExist($this->sessionDir.'/'.$two);
    }

    public function test_actor_without_devices_permission_cannot_revoke(): void
    {
        $victim = $this->makeUser();
        $sessionId = 'guarded'.str_repeat('e', 33);
        file_put_contents($this->sessionDir.'/'.$sessionId, 'payload');
        $device = $this->makeDevice($victim->id, $sessionId);

        $response = $this->actingAs($this->makeUser())
            ->delete("/admin/users/devices/{$device->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('user_devices', ['id' => $device->id]);
        $this->assertFileExists($this->sessionDir.'/'.$sessionId);
    }
}
