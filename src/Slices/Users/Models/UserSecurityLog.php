<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSecurityLog extends Model
{
    public $timestamps = false;

    protected $table = 'user_security_logs';

    protected $fillable = [
        'user_id',
        'identifier_attempted',
        'event_type',
        'ip_address',
        'user_agent',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function log(int|string|null $userId, string $eventType, string $severity = 'info', ?string $description = null, ?array $payload = null): self
    {
        $identifier = 'system';
        if ($userId) {
            $u = User::find($userId);
            $identifier = $u?->email ?? "user-{$userId}";
        }

        return static::create([
            'user_id' => $userId,
            'identifier_attempted' => $identifier,
            'event_type' => $eventType,
            'ip_address' => request()?->ip() ?? '127.0.0.1',
            'user_agent' => substr(request()?->userAgent() ?? 'System', 0, 255),
            'payload' => array_merge(['severity' => $severity, 'description' => $description], $payload ?? []),
            'created_at' => now(),
        ]);
    }

    public function getSeverityAttribute(): string
    {
        return $this->payload['severity'] ?? 'info';
    }

    public function getDescriptionAttribute(): string
    {
        return $this->payload['description'] ?? ($this->event_type ?? 'Security event');
    }

    public function getEventAttribute(): string
    {
        return $this->event_type;
    }
}
