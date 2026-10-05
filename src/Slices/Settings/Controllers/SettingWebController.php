<?php

namespace LaraSlice\Slices\Settings\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Routing\Controller;
use LaraSlice\Slices\Settings\Services\SettingSliceService;

class SettingWebController extends Controller
{
    protected SettingSliceService $settingService;

    public function __construct(SettingSliceService $service)
    {
        $this->settingService = $service;
    }

    /**
     * Display SMTP configuration page.
     */
    public function smtp(): View
    {
        $settings = $this->settingService->getSmtpSettings();
        return view('settings::smtp', compact('settings'));
    }

    /**
     * Save SMTP settings.
     */
    public function saveSmtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mail_host' => 'required|string',
            'mail_port' => 'required|integer',
            'mail_username' => 'nullable|string',
            'mail_password' => 'nullable|string',
            'mail_encryption' => 'required|string',
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
    public function ai(): \Illuminate\View\View
    {
        return app(\LaraSlice\Core\Ai\AiChatController::class)->settings();
    }

    /**
     * Save AI Copilot configuration.
     */
    public function saveAi(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        return app(\LaraSlice\Core\Ai\AiChatController::class)->updateSettings($request);
    }
}