<?php

namespace LaraSlice\Slices\Settings\Services;

use Illuminate\Support\Facades\Mail;
use LaraSlice\Slices\Settings\Contracts\SmtpSettingsFormBusinessObject;
use LaraSlice\Slices\Settings\Models\Setting;

class SettingSliceService
{
    /**
     * Retrieve the current SMTP settings mapped into the DTO.
     */
    public function getSmtpSettings(): SmtpSettingsFormBusinessObject
    {
        $dto = new SmtpSettingsFormBusinessObject;
        $dto->mail_host = Setting::get('mail_host', 'smtp.mailtrap.io');
        $dto->mail_port = (int) Setting::get('mail_port', 587);
        $dto->mail_username = Setting::get('mail_username', '');
        $dto->mail_password = Setting::get('mail_password', '');
        $dto->mail_encryption = Setting::get('mail_encryption', 'tls');
        $dto->mail_from_address = Setting::get('mail_from_address', 'noreply@laraslice.com');
        $dto->mail_from_name = Setting::get('mail_from_name', 'LaraSlice');

        return $dto;
    }

    /**
     * Save SMTP settings to database and immediately apply to runtime config.
     */
    public function saveSmtpSettings(array $data): bool
    {
        Setting::set('mail_host', $data['mail_host'] ?? '', 'mail', false, 'SMTP Server Host');
        Setting::set('mail_port', (string) ($data['mail_port'] ?? 587), 'mail', false, 'SMTP Server Port');
        Setting::set('mail_username', $data['mail_username'] ?? '', 'mail', false, 'SMTP Auth Username');

        // Only update password if a new one is supplied
        if (! empty($data['mail_password'])) {
            Setting::set('mail_password', $data['mail_password'], 'mail', true, 'SMTP Auth Password');
        }

        Setting::set('mail_encryption', $data['mail_encryption'] ?? 'tls', 'mail', false, 'SMTP Encryption Protocol');
        Setting::set('mail_from_address', $data['mail_from_address'] ?? 'noreply@laraslice.com', 'mail', false, 'System Sender Address');
        Setting::set('mail_from_name', $data['mail_from_name'] ?? 'LaraSlice', 'mail', false, 'System Sender Name');

        // Immediately refresh runtime config
        Setting::applySmtpConfig();

        return true;
    }

    /**
     * Test SMTP configuration by sending an actual diagnostic email.
     */
    public function testSmtpConnection(string $recipientEmail): array
    {
        try {
            Setting::applySmtpConfig();

            Mail::raw("Hello,\n\nThis is a verification email sent from your LaraSlice Core SMTP service.\nConnection established successfully at ".now()->toIso8601String(), function ($message) use ($recipientEmail) {
                $message->to($recipientEmail)
                    ->subject('LaraSlice SMTP Diagnostic Test: Successful');
            });

            return [
                'success' => true,
                'message' => "Diagnostic test email sent successfully to {$recipientEmail}!",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'SMTP Connection Failed: '.$e->getMessage(),
            ];
        }
    }
}
