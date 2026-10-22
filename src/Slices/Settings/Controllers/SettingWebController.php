<?php

namespace LaraSlice\Slices\Settings\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LaraSlice\Core\Ai\AiChatController;
use LaraSlice\Core\Security\Access;
use LaraSlice\Slices\Settings\Services\SettingSliceService;

class SettingWebController extends Controller
{
    protected SettingSliceService $settingService;

    public function __construct(SettingSliceService $service)
    {
        $this->settingService = $service;
    }

    /**
     * The settings hub (S-UI 5, pattern P9): every slice's
     * declared `settings:` sections in one rail + search shell,
     * alongside the built-in bespoke pages (SMTP, Theme, AI).
     */
    public function hub(Request $request): View
    {
        $this->authorizeSettings(['settings.view', 'settings.smtp.view']);

        $sections = $this->hubSections();
        $activeId = (string) $request->query('section', $sections[0]['id'] ?? '');
        $active = collect($sections)->firstWhere('id', $activeId) ?? ($sections[0] ?? null);

        return view('laraslice::settings-hub', [
            'sections' => $sections,
            'active' => $active,
            'builtin' => [
                ['title' => 'SMTP', 'url' => route('settings.smtp'), 'icon' => 'mail'],
                ['title' => 'Theme', 'url' => route('settings.theme'), 'icon' => 'palette'],
                ['title' => 'AI', 'url' => route('settings.ai'), 'icon' => 'sparkles'],
            ],
        ]);
    }

    /**
     * Save one declared section. Only the section's declared
     * fields are written (the declaration is the allowlist);
     * blank secret fields keep their current value.
     */
    public function saveHubSection(Request $request, string $section): RedirectResponse
    {
        $this->authorizeSettings(['settings.view', 'settings.smtp.edit']);

        $target = collect($this->hubSections())->firstWhere('id', $section);
        if ($target === null) {
            abort(404);
        }
        foreach ($target['fields'] as $field) {
            $value = $request->input($field['handle']);
            if ($field['type'] === 'toggle') {
                $value = $request->boolean($field['handle']) ? '1' : '0';
            }
            if ($field['secret'] && ($value === null || $value === '')) {
                continue;
            }
            \LaraSlice\Slices\Settings\Models\Setting::set(
                $field['key'],
                $value === null ? '' : (string) $value,
                $target['slice'],
                (bool) $field['secret'],
                $target['title'].' — '.$field['label']
            );
        }

        return redirect()
            ->route('settings.hub', ['section' => $section])
            ->with('success', $target['title'].' settings saved.');
    }

    /**
     * Aggregate declared sections from every active slice.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function hubSections(): array
    {
        $manager = app(\LaraSlice\Core\Discovery\SliceManager::class);
        $entries = [];
        foreach ($manager->getActiveSlices() as $slice) {
            if (! empty($slice->raw['settings'] ?? [])) {
                $entries[] = ['slice' => (string) $slice->name, 'manifest' => $slice->raw];
            }
        }

        return \LaraSlice\Support\SettingsUi::collectSections(
            $entries,
            fn (string $key, mixed $default) => \LaraSlice\Slices\Settings\Models\Setting::get($key, $default)
        );
    }

    /**
     * Display SMTP configuration page.
     */
    public function smtp(): View
    {
        $this->authorizeSettings(['settings.smtp.view', 'settings.smtp.edit', 'settings.view']);

        $settings = $this->settingService->getSmtpSettings();

        return view('settings::smtp', compact('settings'));
    }

    /**
     * Save SMTP settings.
     */
    public function saveSmtp(Request $request): RedirectResponse
    {
        $this->authorizeSettings(['settings.smtp.edit', 'settings.edit']);

        $validated = $request->validate([
            'mail_host' => 'required|string',
            'mail_port' => 'required|integer',
            'mail_username' => 'nullable|string',
            'mail_password' => 'nullable|string',
            'mail_encryption' => 'required|string|in:tls,ssl,none',
            'mail_from_address' => 'required|email',
            'mail_from_name' => 'required|string',
        ]);

        $this->settingService->saveSmtpSettings($validated);

        return redirect()->route('settings.smtp')->with('success', 'SMTP settings saved successfully!');
    }

    /**
     * Send test email.
     */
    public function testSmtp(Request $request): RedirectResponse
    {
        $this->authorizeSettings(['settings.smtp.test', 'settings.smtp.edit']);

        $request->validate(['recipient' => 'required|email']);

        $res = $this->settingService->testSmtpConnection($request->input('recipient'));

        if ($res['success']) {
            return back()->with('success', $res['message']);
        }

        return back()->with('error', $res['message']);
    }

    /**
     * Display AI Copilot & Model configuration.
     */
    public function ai(): View
    {
        return app(AiChatController::class)->settings();
    }

    /**
     * Save AI Copilot configuration.
     */
    public function saveAi(Request $request): RedirectResponse
    {
        return app(AiChatController::class)->updateSettings($request);
    }

    /**
     * Abort unless the signed-in user is a super-admin or holds one of the abilities.
     *
     * @param  array<int, string>  $abilities
     */
    private function authorizeSettings(array $abilities): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if (! Access::allows($user, $abilities)) {
            abort(403, 'Access Denied: you do not have permission to manage system settings.');
        }
    }
}
