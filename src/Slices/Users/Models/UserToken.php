<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserToken extends Model
{
    protected $table = 'user_tokens';

    protected $fillable = ['user_id', 'selector', 'verifier_hash', 'expires_at', 'revoked_at', 'user_agent', 'ip'];

    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
