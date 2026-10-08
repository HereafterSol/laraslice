<?php

namespace LaraSlice\Tests\Feature\Ai;

use LaraSlice\Slices\Settings\Models\Setting;
use LaraSlice\Tests\TestCase;

class CopilotSettingsTest extends TestCase
{
    private const BUBBLE = 'id="laraslice-copilot-container"';

    public function test_bubble_follows_the_floating_bubble_setting(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->get('/laraslice/wizard/schema-studio')->assertOk()->assertSee(self::BUBBLE, false);

        Setting::set('ai.floating_bubble', 'false', 'ai');
        $this->actingAs($admin)->get('/laraslice/wizard/schema-studio')->assertOk()->assertDontSee(self::BUBBLE, false);
    }

    public function test_bubble_is_hidden_from_users_without_copilot_access(): void
    {
        $studioOnly = $this->makeUserWithPermissions(['studio.access']);

        $this->actingAs($studioOnly)->get('/laraslice/wizard/schema-studio')->assertOk()->assertDontSee(self::BUBBLE, false);
    }

    public function test_disabling_telemetry_stops_database_answers(): void
    {
        $admin = $this->makeSuperAdmin();

        $on = (string) $this->actingAs($admin)->postJson('/laraslice/ai/chat', ['message' => 'how many users'])->json('reply');
        $this->assertStringContainsString('`users` table', $on);

        Setting::set('ai.allow_telemetry', 'false', 'ai');
        $off = (string) $this->actingAs($admin)->postJson('/laraslice/ai/chat', ['message' => 'how many users'])->json('reply');
        $this->assertStringNotContainsString('`users` table', $off);
    }

    public function test_clients_cannot_choose_the_provider(): void
    {
        Setting::set('ai.default_provider', 'opencode', 'ai');

        $reply = $this->actingAs($this->makeSuperAdmin())
            ->postJson('/laraslice/ai/chat', ['message' => 'hello there', 'provider' => 'anthropic'])
            ->assertOk();

        $this->assertNotSame('anthropic', $reply->json('provider'));
    }
}
