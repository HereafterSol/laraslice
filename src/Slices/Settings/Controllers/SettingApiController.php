<?php

namespace LaraSlice\Slices\Settings\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraSlice\Core\Security\Access;
use LaraSlice\Slices\Settings\Services\SettingSliceService;

class SettingApiController extends Controller
{
    protected SettingSliceService $settingService;

    public function __construct(SettingSliceService $service)
    {
        $this->settingService = $service;
    }

    /**
     * GET /api/settings/smtp
     */
    public function getSmtp(): JsonResponse
    {
        $this->authorizeSettings(['settings.smtp.view', 'settings.smtp.edit', 'settings.view']);

        $settings = $this->settingService->getSmtpSettings();
        // Mask password before returning via API
        $data = (array) $settings;
        $data['mail_password'] = ! empty($data['mail_password']) ? '********' : '';

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * POST /api/settings/smtp
     */
    public function updateSmtp(Request $request): JsonResponse
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

        return response()->json([
            'success' => true,
            'message' => 'SMTP settings updated successfully.',
        ]);
    }

    /**
     * POST /api/settings/smtp/test
     */
    public function testSmtp(Request $request): JsonResponse
    {
        $this->authorizeSettings(['settings.smtp.test', 'settings.smtp.edit']);

        $request->validate([
            'email' => 'required|email',
        ]);

        $result = $this->settingService->testSmtpConnection($request->input('email'));

        return response()->json($result, $result['success'] ? 200 : 422);
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
