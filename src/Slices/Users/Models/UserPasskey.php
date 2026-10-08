<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPasskey extends Model
{
    protected $table = 'user_passkeys';

    protected $fillable = [
        'user_id',
        'credential_id',
        'public_key',
        'sign_count',
        'aaguid',
        'transports',
        'label',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'sign_count' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return is_null($this->revoked_at);
    }
}
