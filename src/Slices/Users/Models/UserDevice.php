<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class UserDevice extends Model
{
    protected $table = 'user_devices';

    protected $fillable = [
        'user_id',
        'session_id',
        'device_token',
        'device_label',
        'platform',
        'device_name',
        'browser',
        'os',
        'ip_address',
        'location_label',
        'is_current',
        'is_trusted',
        'last_active_at',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'is_trusted' => 'boolean',
        'last_active_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Resolve browser and OS details from request.
     */
    public static function parseClientDetails(Request $request): array
    {
        $userAgent = $request->userAgent() ?: 'Mozilla/5.0';
        $ip = $request->ip() ?: '127.0.0.1';
        if ($ip === '::1') {
            $ip = '127.0.0.1';
        }

        $os = 'Windows';
        if (stripos($userAgent, 'Macintosh') !== false || stripos($userAgent, 'Mac OS') !== false) {
            $os = 'macOS';
        } elseif (stripos($userAgent, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
            $os = 'iOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $os = 'Linux';
        }

        $browser = 'Chrome';
        if (stripos($userAgent, 'Edg') !== false || stripos($userAgent, 'Edge') !== false) {
            $browser = 'Edge';
        } elseif (stripos($userAgent, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($userAgent, 'Safari') !== false && stripos($userAgent, 'Chrome') === false) {
            $browser = 'Safari';
        } elseif (stripos($userAgent, 'Opera') !== false || stripos($userAgent, 'OPR') !== false) {
            $browser = 'Opera';
        }

        $platform = in_array($os, ['Android', 'iOS']) ? 'Mobile' : 'Web';
        $deviceName = "{$browser} on {$os}";

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        $location = ($lat && $lng) ? "GPS: {$lat}, {$lng}" : (($ip === '127.0.0.1' || str_starts_with($ip, '192.168.')) ? 'Local Workstation' : 'Islamabad, PK');

        return compact('os', 'browser', 'platform', 'deviceName', 'ip', 'location');
    }

    /**
     * Check if the incoming request comes from a trusted/enrolled device.
     */
    public static function isDeviceTrusted(User $user, Request $request): bool
    {
        $token = $request->cookie('laraslice_device_token');
        if (empty($token)) {
            return false;
        }

        return self::where('user_id', $user->id)
            ->where('device_token', $token)
            ->where('is_trusted', true)
            ->exists();
    }

    /**
     * Record and authorize a device for the user, returning the persistent device token.
     */
    public static function recordDevice(User $user, Request $request, bool $isTrusted = true): array
    {
        $details = self::parseClientDetails($request);
        $token = $request->cookie('laraslice_device_token');
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
        }

        self::where('user_id', $user->id)->update(['is_current' => false]);

        $device = self::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_token' => $token,
            ],
            [
                'session_id' => session()->getId(),
                'device_label' => $details['deviceName'],
                'device_name' => $details['deviceName'],
                'browser' => $details['browser'],
                'os' => $details['os'],
                'platform' => $details['platform'],
                'ip_address' => $details['ip'],
                'location_label' => $details['location'],
                'is_current' => true,
                'is_trusted' => $isTrusted,
                'last_active_at' => now(),
            ]
        );

        return [$device, $token];
    }
}
