<?php

namespace LaraSlice\Slices\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'key',
        'value',
        'group',
        'is_secret',
        'description',
    ];

    protected $casts = [
        'is_secret' => 'boolean',
    ];

    /**
     * Get a setting by key with optional fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if (! $setting) {
            return $default;
        }

        if ($setting->is_secret && ! empty($setting->value)) {
            try {
                return Crypt::decryptString($setting->value);
            } catch (\Exception $e) {
                return $setting->value;
            }
        }

        return $setting->value;
    }

    /**
     * Store or update a setting value.
     */
    public static function set(string $key, mixed $value, string $group = 'general', bool $isSecret = false, ?string $description = null): self
    {
        $storedValue = $value;
        if ($isSecret && ! empty($value)) {
            $storedValue = Crypt::encryptString((string) $value);
        }

        return static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $storedValue,
                'group' => $group,
                'is_secret' => $isSecret,
                'description' => $description,
            ]
        );
    }

    /**
     * Dynamically apply stored SMTP configuration to Laravel's runtime mailer.
     */
    public static function applySmtpConfig(): void
    {
        $host = self::get('mail_host');
        if (! $host) {
            return; // No custom database SMTP configured
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', (int) self::get('mail_port', 587));
        Config::set('mail.mailers.smtp.encryption', self::get('mail_encryption', 'tls'));
        Config::set('mail.mailers.smtp.username', self::get('mail_username'));
        Config::set('mail.mailers.smtp.password', self::get('mail_password'));
        Config::set('mail.from.address', self::get('mail_from_address', 'noreply@laraslice.com'));
        Config::set('mail.from.name', self::get('mail_from_name', config('app.name', 'LaraSlice')));
    }
}
