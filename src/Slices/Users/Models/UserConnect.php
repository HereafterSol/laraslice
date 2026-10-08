<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserConnect extends Model
{
    protected $table = 'user_connect';

    protected $fillable = ['user_id', 'created_by', 'code_hash', 'expires_at', 'used_at', 'revoked_at', 'used_ip', 'used_ua'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
