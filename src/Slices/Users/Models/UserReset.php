<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReset extends Model
{
    protected $table = 'user_resets';

    protected $fillable = ['user_id', 'email', 'token_hash', 'channel', 'ip_address', 'user_agent', 'requested_at', 'expired_at', 'used_at', 'status'];

    protected $casts = ['requested_at' => 'datetime', 'expired_at' => 'datetime', 'used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
