<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use LaraSlice\Slices\Users\Support\UserAgent;

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

        ['os' => $os, 'browser' => $browser, 'deviceType' => $deviceType] = UserAgent::parse($ua);

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
