<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class UserAttempt extends Model
{
    public $timestamps = false;

    protected $table = 'user_attempts';

    protected $fillable = [
        'user_id',
        'identifier_attempted',
        'channel',
        'ip_address',
        'user_agent',
        'browser',
        'os',
        'device_type',
        'location_label',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an authentication / lockscreen / MFA attempt.
     */
    public static function record(
        ?string $identifier = null,
        mixed $request = null,
        string $reason = 'failed_attempt',
        ?int $userId = null,
        string $channel = 'Web'
    ): static {
        $req = ($request instanceof Request) ? $request : request();
        $ua = $req ? ($req->userAgent() ?? '') : '';
        $ip = $req ? ($req->ip() ?: '127.0.0.1') : '127.0.0.1';
        if ($ip === '::1') {
            $ip = '127.0.0.1';
        }

        // Parse OS
        $os = 'Unknown';
        if (stripos($ua, 'Windows NT 10') !== false || stripos($ua, 'Windows NT 11') !== false) {
            $os = 'Windows 10/11';
        } elseif (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $os = 'iOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        }

        // Parse Browser
        $browser = 'Unknown';
        if (stripos($ua, 'Edg') !== false) {
            $browser = 'Edge';
        } elseif (stripos($ua, 'Chrome') !== false) {
            $browser = 'Chrome';
        } elseif (stripos($ua, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (stripos($ua, 'Safari') !== false) {
            $browser = 'Safari';
        } elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) {
            $browser = 'Opera';
        }

        // Parse Device Type
        $deviceType = 'Desktop';
        if (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false) {
            $deviceType = 'Mobile';
        } elseif (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) {
            $deviceType = 'Tablet';
        }

        $locationLabel = ($ip === '127.0.0.1' || $ip === 'localhost') ? 'Local Workstation' : 'Remote Client';

        return static::create([
            'user_id' => $userId,
            'identifier_attempted' => $identifier ?: 'unknown',
            'channel' => $channel,
            'ip_address' => $ip,
            'user_agent' => substr($ua, 0, 500),
            'browser' => $browser,
            'os' => $os,
            'device_type' => $deviceType,
            'location_label' => $locationLabel,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    public static function isLocked(string $identifier, int $max = 5, int $windowMinutes = 15): bool
    {
        $failures = static::where('identifier_attempted', $identifier)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        return $failures >= $max;
    }
}
