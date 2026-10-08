<?php

namespace LaraSlice\Slices\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCheck extends Model
{
    protected $table = 'user_checks';

    protected $fillable = ['user_id', 'purpose', 'payload', 'token_hash', 'expires_at', 'consumed_at', 'ip', 'remember'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'remember' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
