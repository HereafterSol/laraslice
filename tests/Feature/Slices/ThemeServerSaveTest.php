<?php

namespace LaraSlice\Tests\Feature\Slices;

use LaraSlice\Slices\Settings\Models\Setting;
use LaraSlice\Support\SettingsUi;
use LaraSlice\Tests\TestCase;

/**
 * Stage C-3: the Theme Studio's brand dimensions persist
 * server-side. Before this, the customizer wrote localStorage
 * only — the choice lived in one browser and an admin could
 * not set a company-wide theme. Now Settings → Theme saves
 * to the Setting store, and the shell seeds the saved values
 * into every page (window.__lsTheme), where the pre-paint
 * restorer and the theme store prefer them over localStorage.
 * Mode (light/dark) deliberately stays personal.
 */
class ThemeServerSaveTest extends TestCase
{
    public function test_admin_save_persists_brand_dimensions(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->postJson('/admin/settings/theme', ['preset' => 'emerald', 'base' => 'slate', 'radius' => '0.5'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('emerald', Setting::get('theme.preset'));
        $this->assertSame('slate', Setting::get('theme.base'));
        $this->assertSame(
            ['base' => 'slate', 'preset' => 'emerald', 'radius' => '0.5'],
            SettingsUi::savedTheme()
        );
    }

    public function test_saved_theme_is_seeded_into_pages(): void
    {
        $admin = $this->makeSuperAdmin();
        Setting::set('theme.preset', 'emerald', 'theme');

        $this->actingAs($admin)
            ->get('/laraslice/studio?mode=design&view=schema')
            ->assertOk()
            ->assertSee('window.__lsTheme', false)
            ->assertSee('emerald', false);
    }

    public function test_mode_is_not_a_saved_dimension(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->postJson('/admin/settings/theme', ['mode' => 'dark', 'preset' => 'blue'])
            ->assertOk();

        $this->assertNull(Setting::get('theme.mode'));
        $this->assertSame('blue', Setting::get('theme.preset'));
    }

    public function test_save_requires_settings_permission(): void
    {
        $plain = $this->makeUserWithPermissions([]);

        $this->actingAs($plain)
            ->postJson('/admin/settings/theme', ['preset' => 'emerald'])
            ->assertForbidden();

        $this->assertNull(Setting::get('theme.preset'));
    }

    public function test_reset_clears_the_saved_theme(): void
    {
        $admin = $this->makeSuperAdmin();
        Setting::set('theme.preset', 'emerald', 'theme');

        $this->actingAs($admin)
            ->postJson('/admin/settings/theme', ['reset' => true])
            ->assertOk()
            ->assertJsonPath('reset', true);

        $this->assertSame([], SettingsUi::savedTheme());
    }
}
